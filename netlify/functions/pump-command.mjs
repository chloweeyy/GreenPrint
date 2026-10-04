import { timingSafeEqual } from "node:crypto";
import { databasePool, json } from "./admin-common.mjs";

function authorized(request) {
  const expected = process.env.GREENPRINT_DEVICE_KEY || "";
  const provided = request.headers.get("x-greenprint-device-key") || "";
  if (!expected || !provided) return false;
  const left = Buffer.from(expected);
  const right = Buffer.from(provided);
  return left.length === right.length && timingSafeEqual(left, right);
}

export default async function pumpCommand(request) {
  if (request.method !== "GET") return json({ status: "error", message: "Method not allowed." }, 405, { Allow: "GET" });
  if (!authorized(request)) return json({ status: "error", message: "Unauthorized device." }, 401);

  const zoneId = new URL(request.url).searchParams.get("zone_id")?.trim().toLowerCase() || "zone1";
  if (!["zone1", "zone2"].includes(zoneId)) return json({ status: "error", message: "Unknown zone." }, 400);
  if (!process.env.GREENPRINT_DB_HOST || !process.env.GREENPRINT_DB_USER || !process.env.GREENPRINT_DB_PASSWORD) {
    return json({ status: "error", message: "Device database access is not configured." }, 503);
  }

  const client = await databasePool().connect().catch(() => null);
  if (!client) return json({ status: "error", message: "Command queue is unavailable." }, 503);
  try {
    await client.query("BEGIN");
    await client.query(
      `UPDATE device_commands
          SET status = 'SUPERSEDED'
        WHERE zone_id = $1 AND status = 'PENDING'
          AND id < (SELECT MAX(id) FROM device_commands WHERE zone_id = $1 AND status = 'PENDING')`, [zoneId]
    );
    const pending = await client.query(
      "SELECT id, command FROM device_commands WHERE zone_id = $1 AND status = 'PENDING' ORDER BY id DESC LIMIT 1 FOR UPDATE SKIP LOCKED", [zoneId]
    );
    let command = pending.rows[0]?.command;
    if (pending.rows[0]) {
      await client.query("UPDATE device_commands SET status = 'SENT', processed_at = NOW() WHERE id = $1", [pending.rows[0].id]);
    } else {
      const current = await client.query(
        "SELECT command FROM device_commands WHERE zone_id = $1 AND status = 'SENT' ORDER BY id DESC LIMIT 1", [zoneId]
      );
      command = current.rows[0]?.command || "OFF";
    }
    await client.query("COMMIT");
    return json({ status: "success", zone_id: zoneId, command });
  } catch (error) {
    await client.query("ROLLBACK").catch(() => {});
    console.error("GreenPrint pump command polling failed:", error.code || error.name);
    return json({ status: "error", message: "Command queue is unavailable." }, 500);
  } finally {
    client.release();
  }
}
