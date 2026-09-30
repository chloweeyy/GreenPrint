import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import pg from "pg";

const { Pool } = pg;
export const JSON_HEADERS = {
  "Content-Type": "application/json; charset=utf-8",
  "Cache-Control": "no-store",
  "X-Content-Type-Options": "nosniff"
};

let pool;
export function json(body, status = 200, extraHeaders = {}) {
  return new Response(JSON.stringify(body), { status, headers: { ...JSON_HEADERS, ...extraHeaders } });
}

export function databasePool() {
  if (pool) return pool;
  const sslMode = (process.env.GREENPRINT_DB_SSLMODE || "require").trim().toLowerCase();
  const databaseCa = process.env.GREENPRINT_DB_CA_CERT || "";
  pool = new Pool({
    host: process.env.GREENPRINT_DB_HOST,
    port: Number(process.env.GREENPRINT_DB_PORT || 5432),
    database: process.env.GREENPRINT_DB_NAME || "postgres",
    user: process.env.GREENPRINT_DB_USER,
    password: process.env.GREENPRINT_DB_PASSWORD,
    ssl: ["verify-ca", "verify-full"].includes(sslMode)
      ? { ca: databaseCa, rejectUnauthorized: true }
      : { rejectUnauthorized: false },
    max: 2,
    connectionTimeoutMillis: 10_000,
    idleTimeoutMillis: 10_000
  });
  return pool;
}

export function quoteIdentifier(name) {
  if (!/^[a-z_][a-z0-9_]*$/i.test(name)) throw new Error("Unexpected database identifier.");
  return `"${name}"`;
}

export async function getSchema(client, names) {
  const result = await client.query(
    `SELECT table_name, column_name FROM information_schema.columns
      WHERE table_schema = 'public' AND table_name = ANY($1::text[])`, [names]
  );
  const schema = new Map();
  for (const row of result.rows) {
    if (!schema.has(row.table_name)) schema.set(row.table_name, new Set());
    schema.get(row.table_name).add(row.column_name);
  }
  return schema;
}

export function hasColumn(schema, table, column) {
  return Boolean(schema.get(table)?.has(column));
}

export function firstColumn(schema, table, columns) {
  return columns.find((column) => hasColumn(schema, table, column)) || null;
}

const cookieName = "kgg_admin_session";
const sessionSecret = () => process.env.GREENPRINT_ADMIN_SESSION_SECRET || process.env.GREENPRINT_DB_PASSWORD || "";
const encode = (value) => Buffer.from(value).toString("base64url");
const sign = (value) => createHmac("sha256", sessionSecret()).update(value).digest("base64url");

export function createSession(adminId) {
  const session = {
    admin_id: String(adminId),
    csrf: randomBytes(32).toString("hex"),
    exp: Math.floor(Date.now() / 1000) + 21_600
  };
  const payload = encode(JSON.stringify(session));
  return { session, token: `${payload}.${sign(payload)}` };
}

function requestCookie(request) {
  const cookies = request.headers.get("cookie") || "";
  const entry = cookies.split(";").map((part) => part.trim()).find((part) => part.startsWith(`${cookieName}=`));
  return entry ? entry.slice(cookieName.length + 1) : "";
}

export function readSession(request) {
  const token = requestCookie(request);
  const [payload, signature, extra] = token.split(".");
  const secret = sessionSecret();
  if (!payload || !signature || extra || !secret) return null;
  const expected = Buffer.from(sign(payload));
  const actual = Buffer.from(signature);
  if (expected.length !== actual.length || !timingSafeEqual(expected, actual)) return null;
  try {
    const session = JSON.parse(Buffer.from(payload, "base64url").toString("utf8"));
    if (!session.admin_id || !session.csrf || Number(session.exp) <= Math.floor(Date.now() / 1000)) return null;
    return session;
  } catch {
    return null;
  }
}

export function setSessionCookie(token, maxAge = 21_600) {
  return `${cookieName}=${token}; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=${maxAge}`;
}

export async function requireAdmin(request, client) {
  const session = readSession(request);
  if (!session) return { error: json({ status: "error", message: "Admin session expired. Sign in again." }, 401) };
  const schema = await getSchema(client, ["admin_users"]);
  const idColumn = firstColumn(schema, "admin_users", ["id", "admin_id"]);
  if (!idColumn || !hasColumn(schema, "admin_users", "is_active")) {
    return { error: json({ status: "error", message: "Admin accounts are not configured in the database." }, 503) };
  }
  const result = await client.query(
    `SELECT ${quoteIdentifier(idColumn)} AS admin_id, ${hasColumn(schema, "admin_users", "role") ? "role" : "'admin' AS role"}
       FROM admin_users
      WHERE ${quoteIdentifier(idColumn)} = $1 AND is_active IS TRUE`, [session.admin_id]
  );
  const admin = result.rows[0];
  if (!admin || !["owner", "admin", "manager"].includes(String(admin.role || "").toLowerCase())) {
    return { error: json({ status: "error", message: "This active account cannot manage the store." }, 403) };
  }
  return { admin, session, schema };
}

export function checkCsrf(request, session) {
  const provided = request.headers.get("x-csrf-token") || "";
  return Boolean(provided && session?.csrf && provided.length === session.csrf.length
    && timingSafeEqual(Buffer.from(provided), Buffer.from(session.csrf)));
}

export function clientKey(request) {
  return request.headers.get("x-nf-client-connection-ip")
    || request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
    || "unknown";
}

export function requestBody(request, limit = 20_000) {
  return request.text().then((raw) => {
    if (raw.length > limit) throw Object.assign(new Error("Request is too large."), { status: 413 });
    try { return JSON.parse(raw || "{}"); }
    catch { throw Object.assign(new Error("Request body must be valid JSON."), { status: 400 }); }
  });
}

export async function writeAudit(client, adminId, action, details) {
  await client.query(
    `INSERT INTO admin_logs (admin_id, action, details) VALUES ($1, $2, $3)`,
    [adminId, action, JSON.stringify(details || {})]
  );
}
