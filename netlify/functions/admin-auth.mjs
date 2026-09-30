import bcrypt from "bcryptjs";
import {
  checkCsrf, clientKey, createSession, databasePool, firstColumn, getSchema,
  json, readSession, requestBody, requireAdmin, setSessionCookie, writeAudit
} from "./admin-common.mjs";

const ATTEMPT_LIMIT = 5;
const LOCKOUT_MS = 60_000;
const attempts = new Map();

function locked(key) {
  const state = attempts.get(key);
  if (state?.until > Date.now()) return true;
  if (state?.until) attempts.delete(key);
  return false;
}

function recordFailure(key) {
  const state = attempts.get(key) || { count: 0, until: 0 };
  state.count += 1;
  if (state.count >= ATTEMPT_LIMIT) {
    state.count = 0;
    state.until = Date.now() + LOCKOUT_MS;
  }
  attempts.set(key, state);
  return state.until > Date.now();
}

export default async function adminAuth(request) {
  const action = new URL(request.url).searchParams.get("action") || "";
  if (!["login", "session", "logout"].includes(action)) return json({ status: "error", message: "Unknown admin authentication request." }, 404);

  if (action === "session") {
    if (request.method !== "GET") return json({ status: "error", message: "Use GET." }, 405);
    const client = await databasePool().connect().catch((error) => {
      console.error("GreenPrint admin session database connection failed:", error.code || error.name);
      return null;
    });
    if (!client) return json({ status: "error", message: "Admin database connection is unavailable." }, 503);
    try {
      const result = await requireAdmin(request, client);
      if (result.error) return result.error;
      const session = readSession(request);
      return json({ status: "success", csrf_token: session.csrf });
    } catch (error) {
      console.error("GreenPrint admin session check failed:", error.code || error.name);
      return json({ status: "error", message: "Could not verify the admin session." }, 503);
    } finally { client.release(); }
  }

  if (request.method !== "POST") return json({ status: "error", message: "Use POST." }, 405);

  if (action === "logout") {
    const session = readSession(request);
    if (!session || !checkCsrf(request, session)) {
      return json({ status: "error", message: "Admin session expired. Sign in again." }, 401,
        { "Set-Cookie": setSessionCookie("", 0) });
    }
    return json({ status: "success" }, 200, { "Set-Cookie": setSessionCookie("", 0) });
  }

  const key = clientKey(request);
  if (locked(key)) return json({ status: "error", message: "Too many attempts. Wait one minute and try again." }, 429);
  let body;
  try { body = await requestBody(request, 10_000); }
  catch (error) { return json({ status: "error", message: error.message }, error.status || 400); }
  const passcode = String(body?.passcode || "");
  if (!passcode || passcode.length > 128) return json({ status: "error", message: "Enter your admin passcode." }, 400);
  if (!process.env.GREENPRINT_DB_HOST || !process.env.GREENPRINT_DB_USER || !process.env.GREENPRINT_DB_PASSWORD) {
    return json({ status: "error", message: "Admin sign-in is not configured on the server." }, 503);
  }

  const client = await databasePool().connect().catch((error) => {
    console.error("GreenPrint admin login database connection failed:", error.code || error.name);
    return null;
  });
  if (!client) return json({ status: "error", message: "Could not connect to the admin database." }, 503);
  try {
    const schema = await getSchema(client, ["admin_users"]);
    const idColumn = firstColumn(schema, "admin_users", ["id", "admin_id"]);
    if (!idColumn || !schema.get("admin_users")?.has("passcode_hash") || !schema.get("admin_users")?.has("is_active")) {
      return json({ status: "error", message: "Admin account columns are missing. Check the admin_users table schema." }, 503);
    }
    const roleColumn = schema.get("admin_users").has("role");
    const userQuery = `SELECT ${`"${idColumn}"`} AS admin_id, passcode_hash${roleColumn ? ", role" : ", 'admin' AS role"}
      FROM admin_users WHERE is_active IS TRUE AND passcode_hash IS NOT NULL ORDER BY ${`"${idColumn}"`} LIMIT 100`;
    const users = await client.query(userQuery);
    let match = null;
    for (const user of users.rows) {
      const hash = String(user.passcode_hash || "");
      const compatibleHash = hash.startsWith("$2y$") ? `$2b$${hash.slice(4)}` : hash;
      if (/^\$2[aby]\$\d\d\$/.test(compatibleHash) && await bcrypt.compare(passcode, compatibleHash)) {
        match = user;
        break;
      }
    }
    if (!match || !["owner", "admin", "manager"].includes(String(match.role || "").toLowerCase())) {
      const isLocked = recordFailure(key);
      return json({ status: "error", message: isLocked ? "Too many attempts. Wait one minute and try again." : "Incorrect passcode." }, isLocked ? 429 : 401);
    }
    attempts.delete(key);
    const { session, token } = createSession(match.admin_id);
    try { await writeAudit(client, match.admin_id, "login", { message: "Netlify admin session opened" }); }
    catch (error) { console.error("GreenPrint admin login audit write failed:", error.code || error.name); }
    return json({ status: "success", csrf_token: session.csrf }, 200, { "Set-Cookie": setSessionCookie(token) });
  } catch (error) {
    console.error("GreenPrint admin login failed:", { code: error.code || error.name, message: String(error.message || "").slice(0, 300) });
    return json({ status: "error", message: "Could not verify the admin passcode. Check the admin database setup." }, 503);
  } finally { client.release(); }
}
