import {
  checkCsrf, databasePool, firstColumn, getSchema, hasColumn, json, quoteIdentifier,
  requireAdmin, requestBody, writeAudit
} from "./admin-common.mjs";

const TABLES = ["admin_users", "products", "transactions", "inventory_logs", "watering_schedules", "system_alerts", "sensor_readings", "watering_logs", "device_commands", "admin_logs"];
const categoryPrefix = { indoor: "IN", outdoor: "OUT", pots: "POT", pebbles: "PBL", supplies: "SUP" };

function validId(value) {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

async function insertRow(client, table, values, returning = null) {
  const columns = Object.keys(values);
  const names = columns.map(quoteIdentifier).join(", ");
  const params = columns.map((_, index) => `$${index + 1}`).join(", ");
  const returnSql = returning ? ` RETURNING ${quoteIdentifier(returning)}` : "";
  return client.query(`INSERT INTO ${quoteIdentifier(table)} (${names}) VALUES (${params})${returnSql}`, columns.map((name) => values[name]));
}

async function readAction(client, schema, action) {
  let result;
  switch (action) {
    case "products":
      result = await client.query("SELECT id, sku, name, category, price, stock, is_active, notes, image_url FROM products ORDER BY id");
      break;
    case "low_stock":
      result = await client.query("SELECT id, name, stock, category FROM products WHERE stock <= 5 AND is_active IS TRUE ORDER BY stock, name");
      break;
    case "inventory_logs":
      result = await client.query("SELECT il.created_at, p.name AS product_name, il.change_amount, il.reason FROM inventory_logs il LEFT JOIN products p ON p.id = il.product_id ORDER BY il.created_at DESC LIMIT 100");
      break;
    case "schedules":
      result = await client.query("SELECT zone_id, schedule_time FROM watering_schedules ORDER BY zone_id");
      break;
    case "transactions": {
      const timeColumn = firstColumn(schema, "transactions", ["created_at", "transaction_date", "timestamp"]);
      const timeSql = timeColumn ? `${quoteIdentifier(timeColumn)} AS created_at` : "NULL::timestamptz AS created_at";
      result = await client.query(`SELECT transaction_ref, total_amount, subtotal, ${timeSql} FROM transactions ORDER BY ${timeColumn ? `${quoteIdentifier(timeColumn)} DESC` : "transaction_ref DESC"} LIMIT 500`);
      break;
    }
    case "alerts":
      result = await client.query("SELECT id, alert_type, message, created_at FROM system_alerts WHERE is_resolved IS FALSE ORDER BY created_at DESC LIMIT 100");
      break;
    case "sensor_history":
    case "latest_sensor": {
      const timeColumn = firstColumn(schema, "sensor_readings", ["created_at", "reading_time"]);
      const timeSql = timeColumn ? `${quoteIdentifier(timeColumn)} AS created_at` : "NULL::timestamptz AS created_at";
      const orderSql = timeColumn ? `${quoteIdentifier(timeColumn)} DESC` : "id DESC";
      const limit = action === "latest_sensor" ? 1 : 24;
      result = await client.query(`SELECT temperature, humidity, water_level, ${timeSql} FROM sensor_readings ORDER BY ${orderSql} LIMIT ${limit}`);
      break;
    }
    case "watering_history":
      result = await client.query("SELECT zone_name, status, created_at FROM watering_logs ORDER BY created_at DESC LIMIT 8");
      break;
    default:
      return { error: json({ status: "error", message: "Unknown admin data request." }, 404) };
  }
  return { response: json({ status: "success", data: result.rows }) };
}

async function mutateAction(client, schema, admin, action, input) {
  if (action === "save_product") {
    const id = input.id == null || input.id === "" ? null : validId(input.id);
    const name = String(input.name || "").trim();
    const category = String(input.category || "").toLowerCase().trim();
    const price = Number(input.price);
    const stock = Number(input.stock);
    if ((input.id != null && input.id !== "" && !id) || !name || name.length > 160 || !categoryPrefix[category]
      || !Number.isFinite(price) || price < 0 || price > 9_999_999_999.99
      || !Number.isSafeInteger(stock) || stock < 0 || stock > 2_147_483_647) {
      return json({ status: "error", message: "Check the product name, category, price, and stock." }, 422);
    }
    await client.query("BEGIN");
    try {
      let productId;
      let oldStock = 0;
      if (id) {
        const before = await client.query("SELECT stock FROM products WHERE id = $1 FOR UPDATE", [id]);
        if (!before.rows.length) throw Object.assign(new Error("Product was not found."), { status: 404 });
        oldStock = Number(before.rows[0].stock);
        await client.query("UPDATE products SET name = $1, category = $2, price = $3, stock = $4, updated_at = NOW() WHERE id = $5", [name, category, price, stock, id]);
        productId = id;
      } else {
        const sku = `KGG-${categoryPrefix[category]}-${new Date().toISOString().slice(2, 10).replaceAll("-", "")}-${Math.random().toString(16).slice(2, 6).toUpperCase()}`;
        const inserted = await client.query("INSERT INTO products (sku, name, category, price, stock, is_active) VALUES ($1, $2, $3, $4, $5, TRUE) RETURNING id", [sku, name, category, price, stock]);
        productId = inserted.rows[0].id;
      }
      if (oldStock !== stock) {
        await client.query("INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES ($1, $2, $3)", [productId, stock - oldStock, id ? "Admin stock adjustment" : "Initial stock"]);
      }
      await writeAudit(client, admin.admin_id, "save_product", { product_id: String(productId), name, stock });
      await client.query("COMMIT");
      return json({ status: "success", id: productId });
    } catch (error) { await client.query("ROLLBACK"); throw error; }
  }

  if (action === "delete_product" || action === "toggle_product") {
    const id = validId(input.id);
    if (!id) return json({ status: "error", message: "Product was not found." }, 422);
    const active = action === "toggle_product" && input.is_active === true;
    const result = await client.query("UPDATE products SET is_active = $1, updated_at = NOW() WHERE id = $2 RETURNING name", [active, id]);
    if (!result.rows.length) return json({ status: "error", message: "Product was not found." }, 404);
    await writeAudit(client, admin.admin_id, active ? "activate_product" : "deactivate_product", { product_id: String(id), name: result.rows[0].name });
    return json({ status: "success" });
  }

  if (action === "save_schedule") {
    const zoneId = String(input.zone_id || "");
    const time = String(input.schedule_time || "").trim();
    if (!["zone1", "zone2"].includes(zoneId) || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time)) return json({ status: "error", message: "Choose a valid watering zone and time." }, 422);
    await client.query("INSERT INTO watering_schedules (zone_id, schedule_time) VALUES ($1, $2) ON CONFLICT (zone_id) DO UPDATE SET schedule_time = EXCLUDED.schedule_time", [zoneId, time]);
    await writeAudit(client, admin.admin_id, "save_schedule", { zone_id: zoneId, time });
    return json({ status: "success" });
  }

  if (action === "watering") {
    const zone = String(input.zone_name || "");
    const command = String(input.command || "").toUpperCase();
    const zoneIds = { "Zone 01 — Indoor": "zone1", "Zone 02 — Outdoor": "zone2" };
    if (!zoneIds[zone] || !["ON", "OFF"].includes(command)) return json({ status: "error", message: "Choose a valid zone and watering command." }, 422);
    await client.query("BEGIN");
    try {
      await client.query("INSERT INTO device_commands (zone_id, zone_name, command, status, issued_by) VALUES ($1, $2, $3, 'PENDING', $4)", [zoneIds[zone], zone, command, admin.admin_id]);
      await client.query("INSERT INTO watering_logs (zone_id, zone_name, status, admin_user_id) VALUES ($1, $2, $3, $4)", [zoneIds[zone], zone, `MANUAL_${command}`, admin.admin_id]);
      await writeAudit(client, admin.admin_id, "watering_command", { zone_id: zoneIds[zone], zone_name: zone, command });
      await client.query("COMMIT");
      return json({ status: "success" });
    } catch (error) { await client.query("ROLLBACK"); throw error; }
  }
  return json({ status: "error", message: "Unknown admin action." }, 404);
}

export default async function adminApi(request) {
  if (!process.env.GREENPRINT_DB_HOST || !process.env.GREENPRINT_DB_USER || !process.env.GREENPRINT_DB_PASSWORD) {
    return json({ status: "error", message: "Admin database access is not configured on the server." }, 503);
  }
  const client = await databasePool().connect().catch((error) => {
    console.error("GreenPrint admin database connection failed:", error.code || error.name);
    return null;
  });
  if (!client) return json({ status: "error", message: "Admin database connection is unavailable." }, 503);
  let stage = "authenticate admin";
  try {
    const auth = await requireAdmin(request, client);
    if (auth.error) return auth.error;
    const { admin, session } = auth;
    const url = new URL(request.url);
    const action = url.searchParams.get("action") || "";
    if (request.method === "GET") {
      stage = `load ${action}`;
      const schema = await getSchema(client, TABLES);
      const result = await readAction(client, schema, action);
      return result.error || result.response;
    }
    if (request.method !== "POST") return json({ status: "error", message: "Use GET or POST." }, 405);
    if (!checkCsrf(request, session)) return json({ status: "error", message: "Request token expired. Reload the page and try again." }, 403);
    let input;
    try { input = await requestBody(request); }
    catch (error) { return json({ status: "error", message: error.message }, error.status || 400); }
    stage = `save ${action}`;
    return await mutateAction(client, auth.schema, admin, action, input);
  } catch (error) {
    console.error("GreenPrint admin API action failed:", { stage, code: error.code || error.name, message: String(error.message || "").slice(0, 300) });
    return json({ status: "error", message: error.status === 404 ? error.message : "The admin action could not be completed. Check the database schema and function logs." }, error.status || 500);
  } finally { client.release(); }
}
