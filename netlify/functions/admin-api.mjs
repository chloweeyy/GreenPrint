import {
  checkCsrf, databasePool, firstColumn, getSchema, hasColumn, json, quoteIdentifier,
  requireAdmin, requestBody, writeAudit
} from "./admin-common.mjs";
import { configuredTimeZone, dueScheduleDate } from "./watering-time.mjs";

const TABLES = ["admin_users", "products", "transactions", "inventory_logs", "watering_schedules", "system_alerts", "sensor_readings", "watering_logs", "device_commands", "admin_logs"];
const categoryPrefix = { indoor: "IN", outdoor: "OUT", pots: "POT", pebbles: "PBL", supplies: "SUP" };

async function ensureWateringSchema(client) {
  const column = await client.query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'watering_schedules' AND column_name = 'duration_minutes') AS exists");
  if (!column.rows[0].exists) await client.query("ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS duration_minutes INTEGER NOT NULL DEFAULT 1");
  await client.query("ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS duration_seconds INTEGER NOT NULL DEFAULT 60");
  await client.query("ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS auto_stop_seconds INTEGER");
  await client.query("UPDATE watering_schedules SET enabled = FALSE WHERE zone_id = 'zone2'");
  const table = await client.query("SELECT to_regclass('public.watering_schedule_runs') IS NOT NULL AS exists");
  if (!table.rows[0].exists) {
    await client.query(`CREATE TABLE IF NOT EXISTS watering_schedule_runs (
      schedule_slot TEXT NOT NULL CHECK (schedule_slot IN ('zone1', 'zone2')),
      run_date DATE NOT NULL,
      started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      stops_at TIMESTAMPTZ NOT NULL,
      status TEXT NOT NULL DEFAULT 'RUNNING' CHECK (status IN ('RUNNING', 'DONE', 'CANCELLED')),
      finished_at TIMESTAMPTZ,
      PRIMARY KEY (schedule_slot, run_date)
    )`);
  }
}

function validId(value) {
  const id = Number(value);
  return Number.isSafeInteger(id) && id > 0 ? id : null;
}

function validDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const date = new Date(`${value}T00:00:00.000Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value;
}

async function insertRow(client, table, values, returning = null) {
  const columns = Object.keys(values);
  const names = columns.map(quoteIdentifier).join(", ");
  const params = columns.map((_, index) => `$${index + 1}`).join(", ");
  const returnSql = returning ? ` RETURNING ${quoteIdentifier(returning)}` : "";
  return client.query(`INSERT INTO ${quoteIdentifier(table)} (${names}) VALUES (${params})${returnSql}`, columns.map((name) => values[name]));
}

async function readAction(client, schema, action, query = new URLSearchParams()) {
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
      result = await client.query("SELECT zone_id, schedule_time, duration_seconds FROM watering_schedules WHERE zone_id = 'zone1' LIMIT 1");
      break;
    case "watering_state":
      result = await client.query("SELECT command, created_at, auto_stop_seconds FROM device_commands WHERE zone_id = 'zone1' ORDER BY id DESC LIMIT 1");
      break;
    case "transactions": {
      // Prefer the sale's recorded date; created_at can be later for imported/backfilled transactions.
      const timeColumn = firstColumn(schema, "transactions", ["transaction_date", "created_at", "timestamp"]);
      const from = query.get("from") || "";
      const to = query.get("to") || "";
      if ((from && !validDate(from)) || (to && !validDate(to))) {
        return { error: json({ status: "error", message: "Choose valid report dates." }, 422) };
      }
      if (from && to && from > to) {
        return { error: json({ status: "error", message: "The from date must be on or before the to date." }, 422) };
      }
      if ((from || to) && !timeColumn) {
        return { error: json({ status: "error", message: "Date filtering is unavailable because the transactions table has no date column." }, 503) };
      }
      const timeSql = timeColumn ? `${quoteIdentifier(timeColumn)} AS created_at` : "NULL::timestamptz AS created_at";
      const conditions = [];
      const values = [];
      if (from || to) {
        let timeZone = process.env.GREENPRINT_TIMEZONE || "Asia/Manila";
        try { new Intl.DateTimeFormat("en", { timeZone }); }
        catch { timeZone = "UTC"; }
        if (from) {
          values.push(from, timeZone);
          conditions.push(`${quoteIdentifier(timeColumn)} >= ($${values.length - 1}::date::timestamp AT TIME ZONE $${values.length}::text)`);
        }
        if (to) {
          values.push(to, timeZone);
          conditions.push(`${quoteIdentifier(timeColumn)} < (($${values.length - 1}::date + INTERVAL '1 day')::timestamp AT TIME ZONE $${values.length}::text)`);
        }
      }
      const whereSql = conditions.length ? ` WHERE ${conditions.join(" AND ")}` : "";
      result = await client.query(`SELECT transaction_ref, total_amount, subtotal, ${timeSql} FROM transactions${whereSql} ORDER BY ${timeColumn ? `${quoteIdentifier(timeColumn)} DESC` : "transaction_ref DESC"}`, values);
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
    const stock = input.stock == null || input.stock === "" ? null : Number(input.stock);
    const validStock = Number.isSafeInteger(stock) && stock >= 0 && stock <= 2_147_483_647;
    if ((input.id != null && input.id !== "" && !id) || !name || name.length > 160 || !categoryPrefix[category]
      || !Number.isFinite(price) || price < 0 || price > 9_999_999_999.99
      || (!id && !validStock)) {
      return json({ status: "error", message: "Check the product name, category, price, and initial stock." }, 422);
    }
    await client.query("BEGIN");
    try {
      let productId;
      let currentStock = 0;
      if (id) {
        const before = await client.query("SELECT stock FROM products WHERE id = $1 FOR UPDATE", [id]);
        if (!before.rows.length) throw Object.assign(new Error("Product was not found."), { status: 404 });
        currentStock = Number(before.rows[0].stock);
        await client.query("UPDATE products SET name = $1, category = $2, price = $3, updated_at = NOW() WHERE id = $4", [name, category, price, id]);
        productId = id;
      } else {
        const sku = `KGG-${categoryPrefix[category]}-${new Date().toISOString().slice(2, 10).replaceAll("-", "")}-${Math.random().toString(16).slice(2, 6).toUpperCase()}`;
        const inserted = await client.query("INSERT INTO products (sku, name, category, price, stock, is_active) VALUES ($1, $2, $3, $4, $5, TRUE) RETURNING id", [sku, name, category, price, stock]);
        productId = inserted.rows[0].id;
        currentStock = stock;
      }
      if (!id && stock > 0) {
        await client.query("INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES ($1, $2, $3)", [productId, stock, "Initial stock"]);
      }
      await writeAudit(client, admin.admin_id, "save_product", { product_id: String(productId), name, stock: currentStock });
      await client.query("COMMIT");
      return json({ status: "success", id: productId });
    } catch (error) { await client.query("ROLLBACK"); throw error; }
  }

  if (action === "adjust_stock") {
    const id = validId(input.product_id);
    const quantity = Number(input.quantity);
    const direction = String(input.direction || "");
    const reason = String(input.reason || "").trim();
    if (!id || !Number.isSafeInteger(quantity) || quantity < 1 || quantity > 2_147_483_647
      || !["in", "out"].includes(direction) || !reason || reason.length > 160) {
      return json({ status: "error", message: "Choose a product, a positive whole-number quantity, and a reason." }, 422);
    }
    await client.query("BEGIN");
    try {
      const productResult = await client.query("SELECT id, name, stock FROM products WHERE id = $1 FOR UPDATE", [id]);
      if (!productResult.rows.length) throw Object.assign(new Error("Product was not found."), { status: 404 });
      const product = productResult.rows[0];
      const change = direction === "in" ? quantity : -quantity;
      const newStock = Number(product.stock) + change;
      if (newStock < 0) throw Object.assign(new Error(`Cannot remove ${quantity}; only ${product.stock} are in stock.`), { status: 422 });
      if (newStock > 2_147_483_647) throw Object.assign(new Error("The adjusted stock exceeds the database limit."), { status: 422 });
      await client.query("UPDATE products SET stock = $1, updated_at = NOW() WHERE id = $2", [newStock, id]);
      await client.query("INSERT INTO inventory_logs (product_id, change_amount, reason) VALUES ($1, $2, $3)", [id, change, reason]);
      await writeAudit(client, admin.admin_id, direction === "in" ? "stock_in" : "stock_out", {
        product_id: String(id), name: product.name, quantity, change, from_stock: Number(product.stock), to_stock: newStock, reason
      });
      await client.query("COMMIT");
      return json({ status: "success", stock: newStock });
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
    await ensureWateringSchema(client);
    const zoneId = String(input.zone_id || "");
    const time = String(input.schedule_time || "").trim();
    const durationSeconds = Number(input.duration_seconds || 60);
    if (zoneId !== "zone1" || !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(time)
      || ![30, 60].includes(durationSeconds)) {
      return json({ status: "error", message: "Choose a valid shared daily time and a watering duration of 30 or 60 seconds." }, 422);
    }
    await client.query("BEGIN");
    try {
      await client.query("SELECT pg_advisory_xact_lock(hashtext('greenprint-shared-watering-scheduler'))");
      await client.query("UPDATE watering_schedules SET enabled = FALSE WHERE zone_id = 'zone2'");
      await client.query("INSERT INTO watering_schedules (zone_id, schedule_time, duration_seconds, enabled, updated_at) VALUES ($1, $2, $3, TRUE, NOW()) ON CONFLICT (zone_id) DO UPDATE SET schedule_time = EXCLUDED.schedule_time, duration_seconds = EXCLUDED.duration_seconds, enabled = TRUE, updated_at = NOW()", [zoneId, time, durationSeconds]);

      // If the user saves a time that is already due, start now. This shares
      // the scheduler's daily run lock, so the minute job cannot double-start.
      let startedNow = false;
      let alreadyWatering = false;
      let alreadyRanToday = false;
      const now = new Date();
      const runDate = dueScheduleDate(time, now, configuredTimeZone());
      if (runDate) {
        const active = await client.query(
          "SELECT 1 FROM watering_schedule_runs WHERE schedule_slot = 'zone1' AND status = 'RUNNING' AND stops_at > $1::timestamptz LIMIT 1",
          [now.toISOString()]
        );
        if (active.rows.length) {
          alreadyWatering = true;
        } else {
          const current = await client.query(
            "SELECT command, auto_stop_seconds FROM device_commands WHERE zone_id = 'zone1' ORDER BY id DESC LIMIT 1"
          );
          alreadyWatering = String(current.rows[0]?.command || "").toUpperCase() === "ON"
            && current.rows[0]?.auto_stop_seconds == null;
          if (!alreadyWatering) {
            const previousRun = await client.query(
              "SELECT 1 FROM watering_schedule_runs WHERE schedule_slot = 'zone1' AND run_date = $1::date LIMIT 1",
              [runDate]
            );
            alreadyRanToday = previousRun.rows.length > 0;
            if (!alreadyRanToday) {
              const run = await client.query(
                `INSERT INTO watering_schedule_runs (schedule_slot, run_date, started_at, stops_at, status)
                 VALUES ('zone1', $1::date, $2::timestamptz, $2::timestamptz + ($3::integer * INTERVAL '1 second'), 'RUNNING')
                 ON CONFLICT (schedule_slot, run_date) DO NOTHING
                 RETURNING schedule_slot`,
                [runDate, now.toISOString(), durationSeconds]
              );
              if (run.rows.length) {
                await client.query(
                  "INSERT INTO device_commands (zone_id, zone_name, command, status, auto_stop_seconds) VALUES ('zone1', 'Shared irrigation — both areas', 'ON', 'PENDING', $1)",
                  [durationSeconds]
                );
                await client.query(
                  "INSERT INTO watering_logs (zone_id, zone_name, status) VALUES ('zone1', 'Shared daily watering — both areas', 'AUTO_ON')"
                );
                startedNow = true;
              }
            }
          }
        }
      }

      await writeAudit(client, admin.admin_id, "save_schedule", { schedule_slot: "shared", time, duration_seconds: durationSeconds, started_now: startedNow, already_ran_today: alreadyRanToday });
      await client.query("COMMIT");
      return json({ status: "success", started_now: startedNow, already_watering: alreadyWatering, already_ran_today: alreadyRanToday });
    } catch (error) {
      await client.query("ROLLBACK").catch(() => {});
      throw error;
    }
  }

  if (action === "watering") {
    await ensureWateringSchema(client);
    const requestedZoneId = String(input.zone_id || "all");
    const command = String(input.command || "").toUpperCase();
    if (!["all", "zone1", "zone2"].includes(requestedZoneId) || !["ON", "OFF"].includes(command)) return json({ status: "error", message: "Choose a valid watering command." }, 422);
    const zoneId = "zone1";
    const zoneName = "Shared irrigation — both areas";
    await client.query("BEGIN");
    try {
      await client.query("UPDATE watering_schedule_runs SET status = 'CANCELLED', finished_at = NOW() WHERE status = 'RUNNING'");
      await client.query("INSERT INTO device_commands (zone_id, zone_name, command, status, issued_by) VALUES ($1, $2, $3, 'PENDING', $4)", [zoneId, zoneName, command, admin.admin_id]);
      await client.query("INSERT INTO watering_logs (zone_id, zone_name, status, admin_user_id) VALUES ($1, $2, $3, $4)", [zoneId, zoneName, `MANUAL_${command}`, admin.admin_id]);
      await writeAudit(client, admin.admin_id, "watering_command", { zone_id: "all", zone_name: zoneName, command });
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
      if (action === "schedules") {
        stage = "prepare watering scheduler";
        await ensureWateringSchema(client);
      }
      stage = `load ${action}`;
      const schema = await getSchema(client, TABLES);
      const result = await readAction(client, schema, action, url.searchParams);
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
