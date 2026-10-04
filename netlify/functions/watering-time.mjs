const DEFAULT_TIME_ZONE = "Asia/Manila";

export function configuredTimeZone() {
  const requested = process.env.GREENPRINT_TIMEZONE || DEFAULT_TIME_ZONE;
  try {
    new Intl.DateTimeFormat("en", { timeZone: requested });
    return requested;
  } catch {
    return "UTC";
  }
}

export function localClock(date, timeZone) {
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

function previousDate(date) {
  const value = new Date(`${date}T00:00:00.000Z`);
  value.setUTCDate(value.getUTCDate() - 1);
  return value.toISOString().slice(0, 10);
}

// Netlify's scheduled invocations run once a minute. A short catch-up window
// prevents a late invocation from silently skipping that day's watering.
export function dueScheduleDate(scheduleTime, now, timeZone, graceMinutes = 5) {
  const match = String(scheduleTime || "").match(/^(\d{1,2}):(\d{2})/);
  if (!match) return null;

  const scheduledMinute = Number(match[1]) * 60 + Number(match[2]);
  if (scheduledMinute < 0 || scheduledMinute >= 24 * 60) return null;

  const current = localClock(now, timeZone);
  let minutesLate = current.minuteOfDay - scheduledMinute;
  let runDate = current.date;
  if (minutesLate < 0) {
    minutesLate += 24 * 60;
    runDate = previousDate(current.date);
  }
  return minutesLate <= graceMinutes ? runDate : null;
}
