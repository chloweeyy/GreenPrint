import { databasePool } from "./admin-common.mjs";

// Netlify invokes this once a minute (UTC); schedule rows use GREENPRINT_TIMEZONE.
export const config = { schedule: "* * * * *" };

function localClock(date, timeZone) {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hourCycle: "h23"
  }).formatToParts(date);
  const value = (type) => parts.find((part) => part.type === type)?.value || "00";
  const year = value("year");
  const month = value("month");
  const day = value("day");
  const hour = Number(value("hour"));
  const minute = Number(value("minute"));
  return { date: `${year}-${month}-${day}`, minuteOfDay: hour * 60 + minute };
}

function getTimeZone() {
  const requested = process.env.GREENPRINT_TIMEZONE || "Asia/Manila";
  try {
    new Intl.DateTimeFormat("en", { timeZone: requested });
    return requested;
  } catch {
    return "UTC";
  }
}

async function ensureSchedulerSchema(client) {
  const column = await client.query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'watering_schedules' AND column_name = 'duration_minutes') AS exists");
  if (!column.rows[0].exists) await client.query("ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS duration_minutes INTEGER NOT NULL DEFAULT 1");
  await client.query("ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS duration_seconds INTEGER NOT NULL DEFAULT 60");
  await client.query("ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS auto_stop_seconds INTEGER");
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

async function queueSharedCommand(client, command, autoStopSeconds = null) {
  await client.query(
    "INSERT INTO device_commands (zone_id, zone_name, command, status, auto_stop_seconds) VALUES ('zone1', 'Shared irrigation — both areas', $1, 'PENDING', $2)",
    [command, autoStopSeconds]
  );
}

async function logScheduledCommand(client, command) {
  await client.query(
    "INSERT INTO watering_logs (zone_id, zone_name, status) VALUES ('zone1', 'Shared daily watering — both areas', $1)",
    [`AUTO_${command}`]
  );
}

export default async function wateringScheduler() {
  if (!process.env.GREENPRINT_DB_HOST || !process.env.GREENPRINT_DB_USER || !process.env.GREENPRINT_DB_PASSWORD) {
    console.error("Watering scheduler skipped: database access is not configured.");
    return;
  }

  const client = await databasePool().connect().catch((error) => {
    console.error("Watering scheduler database connection failed:", error.code || error.name);
    return null;
  });
  if (!client) return;

  try {
    await ensureSchedulerSchema(client);
    await client.query("BEGIN");
    await client.query("SELECT pg_advisory_xact_lock(hashtext('greenprint-shared-watering-scheduler'))");

    await client.query("UPDATE watering_schedules SET enabled = FALSE WHERE zone_id = 'zone2'");
    const legacyRuns = await client.query(
      "UPDATE watering_schedule_runs SET status = 'CANCELLED', finished_at = NOW() WHERE schedule_slot = 'zone2' AND status = 'RUNNING' RETURNING schedule_slot"
    );
    if (legacyRuns.rows.length) {
      await queueSharedCommand(client, "OFF");
      for (const _row of legacyRuns.rows) await logScheduledCommand(client, "OFF");
    }

    const now = new Date();
    const local = localClock(now, getTimeZone());
    const expired = await client.query(
      "SELECT schedule_slot, run_date FROM watering_schedule_runs WHERE status = 'RUNNING' AND stops_at <= $1::timestamptz FOR UPDATE",
      [now.toISOString()]
    );
    if (expired.rows.length) {
      await queueSharedCommand(client, "OFF");
      for (const row of expired.rows) {
        await client.query(
          "UPDATE watering_schedule_runs SET status = 'DONE', finished_at = $1::timestamptz WHERE schedule_slot = $2 AND run_date = $3::date AND status = 'RUNNING'",
          [now.toISOString(), row.schedule_slot, row.run_date]
        );
        await logScheduledCommand(client, "OFF");
      }
    }

    const active = await client.query("SELECT 1 FROM watering_schedule_runs WHERE schedule_slot = 'zone1' AND status = 'RUNNING' LIMIT 1");
    if (!active.rows.length) {
      const schedules = await client.query(
        "SELECT zone_id, schedule_time, duration_seconds FROM watering_schedules WHERE enabled IS TRUE AND zone_id = 'zone1' ORDER BY schedule_time FOR UPDATE"
      );
      for (const schedule of schedules.rows) {
        const match = String(schedule.schedule_time || "").match(/^(\d{1,2}):(\d{2})/);
        if (!match) continue;
        const scheduledMinute = Number(match[1]) * 60 + Number(match[2]);
        const minutesLate = local.minuteOfDay - scheduledMinute;
        if (minutesLate < 0 || minutesLate > 1) continue;

        const durationSeconds = Number(schedule.duration_seconds) === 30 ? 30 : 60;
        const started = await client.query(
          `INSERT INTO watering_schedule_runs (schedule_slot, run_date, started_at, stops_at, status)
           VALUES ($1, $2::date, $3::timestamptz, $3::timestamptz + ($4::integer * INTERVAL '1 second'), 'RUNNING')
           ON CONFLICT (schedule_slot, run_date) DO NOTHING
           RETURNING schedule_slot`,
          [schedule.zone_id, local.date, now.toISOString(), durationSeconds]
        );
        if (!started.rows.length) continue;

        await queueSharedCommand(client, "ON", durationSeconds);
        await logScheduledCommand(client, "ON");
        break;
      }
    }

    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK").catch(() => {});
    console.error("Watering scheduler failed:", error.code || error.name);
  } finally {
    client.release();
  }
}
