import { createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import pg from "pg";

const { Pool } = pg;
const JSON_HEADERS = {
  "Content-Type": "application/json; charset=utf-8",
  "Cache-Control": "no-store",
  "X-Content-Type-Options": "nosniff"
};
const ATTEMPT_LIMIT = 5;
const LOCKOUT_MS = 60_000;
const attemptsByClient = new Map();
const usedAuthorizationTokens = new Map();
let pool;

function json(body, status = 200) {
  return new Response(JSON.stringify(body), { status, headers: JSON_HEADERS });
}

function databasePool() {
  if (pool) return pool;
  const sslMode = (process.env.GREENPRINT_DB_SSLMODE || "require").trim().toLowerCase();
  const databaseCa = process.env.GREENPRINT_DB_CA_CERT || "";
  pool = new Pool({
    host: process.env.GREENPRINT_DB_HOST,
    port: Number(process.env.GREENPRINT_DB_PORT || 5432),
    database: process.env.GREENPRINT_DB_NAME || "postgres",
    user: process.env.GREENPRINT_DB_USER,
    password: process.env.GREENPRINT_DB_PASSWORD,
    // Match the configured PostgreSQL sslmode. `require` still encrypts the
    // connection, but Supabase's pooler certificate chain may not be in the
    // Netlify runtime trust store. For certificate verification, provide the
    // project CA and use verify-ca or verify-full.
    ssl: ["verify-ca", "verify-full"].includes(sslMode)
      ? { ca: databaseCa, rejectUnauthorized: true }
      : { rejectUnauthorized: false },
    max: 2,
    connectionTimeoutMillis: 10_000,
    idleTimeoutMillis: 10_000
  });
  return pool;
}

function clientKey(request) {
  return request.headers.get("x-nf-client-connection-ip")
    || request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
    || "unknown";
}

function checkPin(request, pin) {
  const key = clientKey(request);
  const now = Date.now();
  const state = attemptsByClient.get(key);
  if (state?.lockedUntil > now) return { ok: false, locked: true };
  const expected = process.env.GREENPRINT_CHECKOUT_PIN || "";
  const actualBytes = Buffer.from(String(pin));
  const expectedBytes = Buffer.from(expected);
  const matches = /^\d{4,8}$/.test(expected)
    && /^\d{4,8}$/.test(String(pin))
    && actualBytes.length === expectedBytes.length
    && timingSafeEqual(actualBytes, expectedBytes);
  if (matches) {
    attemptsByClient.delete(key);
    return { ok: true, key };
  }
  const failures = (state?.failures || 0) + 1;
  attemptsByClient.set(key, {
    failures: failures >= ATTEMPT_LIMIT ? 0 : failures,
    lockedUntil: failures >= ATTEMPT_LIMIT ? now + LOCKOUT_MS : 0
  });
  return { ok: false, locked: failures >= ATTEMPT_LIMIT };
}

function quoteIdentifier(name) {
  if (!/^[a-z_][a-z0-9_]*$/i.test(name)) throw new Error("Unexpected database column.");
  return `"${name}"`;
}

function tableColumns(rows) {
  const tables = new Map();
  for (const row of rows) {
    if (!tables.has(row.table_name)) tables.set(row.table_name, new Map());
    tables.get(row.table_name).set(row.column_name, row);
  }
  return tables;
}

function has(tables, table, column) {
  return Boolean(tables.get(table)?.has(column));
}

function firstColumn(tables, table, candidates) {
  return candidates.find((candidate) => has(tables, table, candidate)) || null;
}

function amountToCents(value) {
  const amount = Number(value);
  if (!Number.isFinite(amount) || amount < 0) return null;
  return Math.round(amount * 100);
}

function transactionReference() {
  const configuredTimeZone = process.env.GREENPRINT_TIMEZONE || "Asia/Manila";
  const formatOptions = {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hourCycle: "h23"
  };
  let formatter;
  try {
    formatOptions.timeZone = configuredTimeZone;
    formatter = new Intl.DateTimeFormat("en-CA", formatOptions);
  } catch {
    formatOptions.timeZone = "Asia/Manila";
    formatter = new Intl.DateTimeFormat("en-CA", formatOptions);
    console.warn("GreenPrint checkout received an invalid GREENPRINT_TIMEZONE; using Asia/Manila.");
  }
  const parts = formatter.formatToParts(new Date()).reduce((result, part) => {
    result[part.type] = part.value;
    return result;
  }, {});
  return `KGG-${parts.year}${parts.month}${parts.day}-${parts.hour}${parts.minute}${parts.second}-${randomBytes(3).toString("hex").toUpperCase()}`;
}

async function inspectSchema(client) {
  const result = await client.query(
    `SELECT table_name, column_name, is_nullable, column_default
       FROM information_schema.columns
      WHERE table_schema = 'public'
        AND table_name = ANY($1::text[])`,
    [["products", "transactions", "transaction_items", "admin_users", "inventory_logs", "admin_logs"]]
  );
  return tableColumns(result.rows);
}

async function insertRow(client, table, values, returning = null) {
  const names = Object.keys(values);
  const columns = names.map(quoteIdentifier).join(", ");
  const params = names.map((_, index) => `$${index + 1}`).join(", ");
  const returnSql = returning ? ` RETURNING ${quoteIdentifier(returning)}` : "";
  return client.query(
    `INSERT INTO ${quoteIdentifier(table)} (${columns}) VALUES (${params})${returnSql}`,
    names.map((name) => values[name])
  );
}

function checkoutError(message, status = 422) {
  const error = new Error(message);
  error.status = status;
  return error;
}

function cartFingerprint(cart) {
  const normalized = cart.map((line) => [Number(line.product_id), Number(line.quantity)])
    .sort((a, b) => a[0] - b[0]);
  return createHmac("sha256", process.env.GREENPRINT_DB_PASSWORD || process.env.GREENPRINT_CHECKOUT_PIN)
    .update(JSON.stringify(normalized)).digest("hex");
}

function issueAuthorizationToken(request, cart) {
  const expires = Date.now() + 5 * 60_000;
  const nonce = randomBytes(18).toString("hex");
  const payload = Buffer.from(JSON.stringify({
    expires,
    nonce,
    client: clientKey(request),
    cart: cartFingerprint(cart)
  })).toString("base64url");
  const signature = createHmac("sha256", process.env.GREENPRINT_DB_PASSWORD || process.env.GREENPRINT_CHECKOUT_PIN)
    .update(payload).digest("base64url");
  return `${payload}.${signature}`;
}

function consumeAuthorizationToken(request, token, cart) {
  if (typeof token !== "string" || token.length > 2048) return false;
  const [payload, signature, extra] = token.split(".");
  if (!payload || !signature || extra) return false;
  const secret = process.env.GREENPRINT_DB_PASSWORD || process.env.GREENPRINT_CHECKOUT_PIN;
  const expected = createHmac("sha256", secret).update(payload).digest();
  let actual;
  try { actual = Buffer.from(signature, "base64url"); } catch { return false; }
  if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) return false;
  let claims;
  try { claims = JSON.parse(Buffer.from(payload, "base64url").toString("utf8")); } catch { return false; }
  const now = Date.now();
  for (const [nonce, expiry] of usedAuthorizationTokens) if (expiry <= now) usedAuthorizationTokens.delete(nonce);
  if (!claims.nonce || claims.expires <= now || claims.expires > now + 5 * 60_000 + 5_000
      || claims.client !== clientKey(request) || claims.cart !== cartFingerprint(cart)
      || usedAuthorizationTokens.has(claims.nonce)) return false;
  usedAuthorizationTokens.set(claims.nonce, claims.expires);
  return true;
}

function validatePayment(payment, totalCents) {
  const method = String(payment?.method || "");
  if (!["cash", "gcash_qr", "bank_transfer"].includes(method)) {
    throw checkoutError("Choose a valid payment method.");
  }
  const receivedCents = amountToCents(payment?.amount_received);
  if (method === "cash" && (receivedCents === null || receivedCents < totalCents)) {
    throw checkoutError("The amount received must cover the total.");
  }
  const customerName = String(payment?.customer_name || "").trim().slice(0, 120);
  return {
    method,
    customerName,
    received: method === "cash" ? receivedCents : totalCents,
    change: method === "cash" ? receivedCents - totalCents : 0
  };
}

export default async function processCheckout(request) {
  if (request.method !== "POST") {
    return json({ status: "error", message: "Use POST." }, 405);
  }

  let payload;
  try {
    const raw = await request.text();
    if (raw.length > 20_000) return json({ status: "error", message: "The cart is too large." }, 413);
    payload = JSON.parse(raw);
  } catch {
    return json({ status: "error", message: "Request body must be valid JSON." }, 400);
  }

  const cart = payload?.cart;
  if (!Array.isArray(cart) || cart.length < 1 || cart.length > 100) {
    return json({ status: "error", message: "The cart is empty or invalid." }, 400);
  }

  const quantities = new Map();
  for (const line of cart) {
    const id = Number(line?.product_id);
    const quantity = Number(line?.quantity);
    if (!Number.isSafeInteger(id) || id < 1 || !Number.isSafeInteger(quantity) || quantity < 1 || quantity > 999) {
      return json({ status: "error", message: "The cart contains an invalid product or quantity." }, 400);
    }
    const combined = (quantities.get(id) || 0) + quantity;
    if (combined > 999) return json({ status: "error", message: "The cart contains an invalid quantity." }, 400);
    quantities.set(id, combined);
  }

  if (!process.env.GREENPRINT_CHECKOUT_PIN) {
    return json({ status: "error", message: "Checkout authorization is not configured. Please ask staff for help." }, 503);
  }
  if (payload?.action === "authorize") {
    const pinResult = checkPin(request, String(payload?.pin ?? ""));
    if (!pinResult.ok) {
      return json({ status: "error", message: pinResult.locked ? "Too many PIN attempts. Wait one minute and try again." : "Incorrect checkout PIN." }, pinResult.locked ? 429 : 401);
    }
    return json({ status: "success", authorization_token: issueAuthorizationToken(request, cart) });
  }
  if (!consumeAuthorizationToken(request, payload?.authorization_token, cart)) {
    return json({ status: "error", message: "Staff authorization expired. Please enter the PIN again." }, 401);
  }

  const missingConfig = ["GREENPRINT_DB_HOST", "GREENPRINT_DB_USER", "GREENPRINT_DB_PASSWORD"]
    .filter((name) => !process.env[name]);
  if (missingConfig.length) {
    return json({ status: "error", message: "Checkout is not configured on the server. Please ask staff for help." }, 503);
  }
  const sslMode = (process.env.GREENPRINT_DB_SSLMODE || "require").trim().toLowerCase();
  if (!["require", "verify-ca", "verify-full"].includes(sslMode)) {
    return json({ status: "error", message: "Checkout has an invalid database SSL mode. Please ask an administrator to check Netlify settings." }, 503);
  }
  if (["verify-ca", "verify-full"].includes(sslMode) && !process.env.GREENPRINT_DB_CA_CERT) {
    return json({ status: "error", message: "The database CA certificate is missing from Netlify settings. Please ask an administrator to check the SSL configuration." }, 503);
  }

  const db = databasePool();
  const client = await db.connect().catch((error) => {
    console.error("GreenPrint checkout database connection failed.", error.code || "database error");
    return null;
  });
  if (!client) return json({ status: "error", message: "Checkout could not connect to the store database. No stock was changed." }, 503);

  let inTransaction = false;
  let operationStage = "inspect database schema";
  try {
    const schema = await inspectSchema(client);
    const transactionIdColumn = firstColumn(schema, "transactions", ["id", "transaction_id"]);
    const adminIdColumn = firstColumn(schema, "admin_users", ["id", "admin_id"]);
    const transactionTimeColumn = firstColumn(schema, "transactions", ["created_at", "transaction_date", "timestamp"]);
    const required = [
      ["products", "id"], ["products", "name"], ["products", "price"], ["products", "stock"], ["products", "is_active"],
      ["transactions", "transaction_ref"], ["transactions", "total_amount"], ["transactions", "subtotal"],
      ["transaction_items", "transaction_id"], ["transaction_items", "product_id"], ["transaction_items", "product_name"],
      ["transaction_items", "quantity"], ["transaction_items", "unit_price"], ["transaction_items", "line_total"]
    ];
    const missing = required.filter(([table, column]) => !has(schema, table, column));
    if (!transactionIdColumn) missing.push(["transactions", "id or transaction_id"]);
    if (missing.length) throw checkoutError("Checkout database tables are missing required columns. Please ask an administrator to check the database setup.", 503);

    operationStage = "begin sale transaction";
    await client.query("BEGIN");
    inTransaction = true;

    let adminId = null;
    if (adminIdColumn && has(schema, "admin_users", "is_active")) {
      operationStage = "find active staff account";
      const adminResult = await client.query(
        `SELECT ${quoteIdentifier(adminIdColumn)} AS admin_id FROM admin_users WHERE is_active IS TRUE ORDER BY ${quoteIdentifier(adminIdColumn)} LIMIT 1`
      );
      adminId = adminResult.rows[0]?.admin_id ?? null;
    }

    const lockedProducts = [];
    let subtotalCents = 0;
    for (const [productId, quantity] of quantities) {
      operationStage = "lock and validate product stock";
      const productResult = await client.query(
        "SELECT id, name, price, stock, is_active FROM products WHERE id = $1 FOR UPDATE",
        [productId]
      );
      const product = productResult.rows[0];
      if (!product || product.is_active !== true) throw checkoutError("A cart item is no longer available. Refresh the products and try again.");
      if (Number(product.stock) < quantity) throw checkoutError(`${product.name} does not have enough stock for this order.`);
      const unitPriceCents = amountToCents(product.price);
      if (unitPriceCents === null) throw checkoutError("A cart item has an invalid price. Please ask staff for help.", 503);
      const lineTotalCents = unitPriceCents * quantity;
      subtotalCents += lineTotalCents;
      lockedProducts.push({ ...product, quantity, unitPriceCents, lineTotalCents });
    }

    const subtotal = (subtotalCents / 100).toFixed(2);
    const payment = validatePayment(payload?.payment || { method: "cash", amount_received: subtotal }, subtotalCents);
    operationStage = "create transaction reference";
    const reference = transactionReference();
    const transactionValues = {
      transaction_ref: reference,
      total_amount: subtotal,
      subtotal
    };
    if (transactionTimeColumn) transactionValues[transactionTimeColumn] = new Date();
    if (has(schema, "transactions", "discount_amount")) transactionValues.discount_amount = "0.00";
    if (has(schema, "transactions", "admin_id") && adminId !== null) transactionValues.admin_id = adminId;
    if (has(schema, "transactions", "payment_method")) transactionValues.payment_method = payment.method;
    if (has(schema, "transactions", "customer_name")) transactionValues.customer_name = payment.customerName || null;
    if (has(schema, "transactions", "amount_received")) transactionValues.amount_received = (payment.received / 100).toFixed(2);
    if (has(schema, "transactions", "change_amount")) transactionValues.change_amount = (payment.change / 100).toFixed(2);

    operationStage = "insert transaction record";
    const transactionResult = await insertRow(client, "transactions", transactionValues, transactionIdColumn);
    const transactionId = transactionResult.rows[0]?.[transactionIdColumn];
    const timestamp = transactionTimeColumn
      ? (await client.query(`SELECT ${quoteIdentifier(transactionTimeColumn)} AS timestamp FROM transactions WHERE ${quoteIdentifier(transactionIdColumn)} = $1`, [transactionId])).rows[0]?.timestamp
      : new Date().toISOString();

    for (const product of lockedProducts) {
      operationStage = "insert transaction items";
      await insertRow(client, "transaction_items", {
        transaction_id: transactionId,
        product_id: product.id,
        product_name: product.name,
        quantity: product.quantity,
        unit_price: (product.unitPriceCents / 100).toFixed(2),
        line_total: (product.lineTotalCents / 100).toFixed(2)
      });

      operationStage = "deduct product stock";
      const updatedAtSql = has(schema, "products", "updated_at") ? ", updated_at = NOW()" : "";
      const stockUpdate = await client.query(
        `UPDATE products SET stock = stock - $1${updatedAtSql} WHERE id = $2 AND stock >= $1`,
        [product.quantity, product.id]
      );
      if (stockUpdate.rowCount !== 1) throw checkoutError(`${product.name} no longer has enough stock. Please try again.`);

      if (["product_id", "change_amount", "reason"].every((column) => has(schema, "inventory_logs", column))) {
        operationStage = "write inventory log";
        await client.query("SAVEPOINT greenprint_inventory_log");
        try {
          const inventoryLog = { product_id: product.id, change_amount: -product.quantity, reason: `Sale ${reference}` };
          if (has(schema, "inventory_logs", "created_at")) inventoryLog.created_at = new Date();
          await insertRow(client, "inventory_logs", inventoryLog);
          await client.query("RELEASE SAVEPOINT greenprint_inventory_log");
        } catch (error) {
          await client.query("ROLLBACK TO SAVEPOINT greenprint_inventory_log");
          await client.query("RELEASE SAVEPOINT greenprint_inventory_log");
          console.error("GreenPrint checkout inventory history write failed: " + JSON.stringify({
            code: error?.code || error?.name || "unknown",
            message: String(error?.message || "Unknown error").replace(/[\\r\\n\\t]+/g, " ").slice(0, 300)
          }));
        }
      }
    }

    if (adminId !== null && ["admin_id", "action", "details"].every((column) => has(schema, "admin_logs", column))) {
      operationStage = "write admin audit log";
      await client.query("SAVEPOINT greenprint_admin_audit");
      try {
        await insertRow(client, "admin_logs", {
          admin_id: adminId,
          action: "checkout",
          details: JSON.stringify({ transaction_ref: reference, transaction_id: String(transactionId), item_count: lockedProducts.length, total: subtotal, payment_method: payment.method, customer_name: payment.customerName, amount_received: payment.received / 100, change_amount: payment.change / 100 })
        });
        await client.query("RELEASE SAVEPOINT greenprint_admin_audit");
      } catch (error) {
        await client.query("ROLLBACK TO SAVEPOINT greenprint_admin_audit");
        await client.query("RELEASE SAVEPOINT greenprint_admin_audit");
        console.error("GreenPrint checkout audit history write failed: " + JSON.stringify({
          code: error?.code || error?.name || "unknown",
          message: String(error?.message || "Unknown error").replace(/[\\r\\n\\t]+/g, " ").slice(0, 300)
        }));
      }
    }

    operationStage = "commit sale transaction";
    await client.query("COMMIT");
    inTransaction = false;
    return json({
      status: "success",
      transaction_ref: reference,
      transaction_id: transactionId,
      timestamp: timestamp || new Date().toISOString(),
      subtotal: Number(subtotal),
      total_amount: Number(subtotal),
      payment_method: payment.method,
      customer_name: payment.customerName,
      amount_received: payment.received / 100,
      change_amount: payment.change / 100,
      items: lockedProducts.map((product) => ({
        product_name: product.name,
        quantity: product.quantity,
        unit_price: product.unitPriceCents / 100,
        line_total: product.lineTotalCents / 100
      }))
    });
  } catch (error) {
    if (inTransaction) await client.query("ROLLBACK").catch(() => {});
    if (error.status) return json({ status: "error", message: error.message }, error.status);
    const diagnostic = String(error?.message || "Unknown error")
      .replace(/postgres(?:ql)?:\/\/[^\s]+/gi, "[redacted connection string]")
      .replace(/[\r\n\t]+/g, " ")
      .slice(0, 400);
    console.error("GreenPrint checkout database operation failed: " + JSON.stringify({
      stage: operationStage,
      code: error?.code || error?.name || "unknown",
      message: diagnostic
    }));
    return json({ status: "error", message: "Checkout could not be completed. No stock was changed." }, 500);
  } finally {
    client.release();
  }
}
