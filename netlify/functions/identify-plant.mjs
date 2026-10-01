import pg from "pg";

const { Pool } = pg;
const HEADERS = {
  "Content-Type": "application/json; charset=utf-8",
  "Cache-Control": "no-store",
  "X-Content-Type-Options": "nosniff"
};
let pool;

function json(data, status = 200) {
  return new Response(JSON.stringify(data), { status, headers: HEADERS });
}

function databasePool() {
  if (pool) return pool;
  const sslMode = (process.env.GREENPRINT_DB_SSLMODE || "require").trim().toLowerCase();
  const ca = process.env.GREENPRINT_DB_CA_CERT || "";
  pool = new Pool({
    host: process.env.GREENPRINT_DB_HOST,
    port: Number(process.env.GREENPRINT_DB_PORT || 5432),
    database: process.env.GREENPRINT_DB_NAME || "postgres",
    user: process.env.GREENPRINT_DB_USER,
    password: process.env.GREENPRINT_DB_PASSWORD,
    ssl: ["verify-ca", "verify-full"].includes(sslMode)
      ? { ca, rejectUnauthorized: true }
      : { rejectUnauthorized: false },
    max: 2,
    connectionTimeoutMillis: 8_000,
    idleTimeoutMillis: 10_000
  });
  return pool;
}

function clean(value, limit = 500) {
  return String(value ?? "").replace(/<[^>]*>/g, "").replace(/[\u0000-\u001f\u007f]/g, " ").trim().slice(0, limit);
}

function normalized(value) {
  return String(value || "").replace(/\([^)]*\)/g, "").normalize("NFKD").replace(/[^\p{L}\p{N}]+/gu, " ").trim().toLowerCase();
}

function extractJson(text) {
  const source = String(text || "").trim().replace(/^```(?:json)?\s*/i, "").replace(/\s*```$/, "");
  const start = source.indexOf("{");
  const end = source.lastIndexOf("}");
  if (start < 0 || end <= start) throw new Error("Plant identification returned invalid data.");
  return JSON.parse(source.slice(start, end + 1));
}

export default async function identifyPlant(request) {
  if (request.method !== "POST") return json({ status: "error", message: "Use POST." }, 405);
  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) return json({ status: "error", message: "The plant scanner AI is not configured. Ask an administrator to add its API key." }, 503);

  let form;
  try { form = await request.formData(); }
  catch { return json({ status: "error", message: "Send a camera image to identify." }, 400); }
  const image = form.get("plant_image");
  if (!(image instanceof File) || image.size < 1 || image.size > 8 * 1024 * 1024) {
    return json({ status: "error", message: "Capture a valid image under 8 MB." }, 413);
  }
  const mimeType = image.type.toLowerCase();
  if (!["image/jpeg", "image/png", "image/webp"].includes(mimeType)) {
    return json({ status: "error", message: "Use a JPEG, PNG, or WebP camera image." }, 415);
  }

  const missing = ["GREENPRINT_DB_HOST", "GREENPRINT_DB_USER", "GREENPRINT_DB_PASSWORD"]
    .filter((name) => !process.env[name]);
  if (missing.length) return json({ status: "error", message: "The plant scanner cannot connect to store care data yet." }, 503);
  const sslMode = (process.env.GREENPRINT_DB_SSLMODE || "require").trim().toLowerCase();
  if (!["require", "verify-ca", "verify-full"].includes(sslMode)
      || (["verify-ca", "verify-full"].includes(sslMode) && !process.env.GREENPRINT_DB_CA_CERT)) {
    return json({ status: "error", message: "The plant scanner database connection is not configured correctly." }, 503);
  }

  try {
    const client = await databasePool().connect();
    let inventory, care;
    try {
      const [items, records] = await Promise.all([
        client.query("SELECT id, sku, name, category, price, stock FROM products WHERE is_active IS TRUE AND stock > 0 AND lower(category) IN ('indoor','outdoor') ORDER BY category,name"),
        client.query("SELECT common_name, scientific_name, care_instructions, sunlight, watering, soil_type, ideal_temperature, is_toxic FROM plant_care_info ORDER BY common_name")
      ]);
      inventory = items.rows;
      care = records.rows;
    } finally { client.release(); }

    const model = clean(process.env.GEMINI_MODEL || "gemini-1.5-flash", 80);
    const prompt = `Identify the plant in this camera image for a garden-store kiosk. Use the inventory and verified care records only as comparison data. State uncertainty clearly. Return a JSON object with keys name, scientific_name, care_instructions, sunlight, watering, soil_type, ideal_temperature, is_toxic. is_toxic must be true, false, or null. Do not claim a match unless the identified common or scientific name matches an inventory item. Inventory: ${JSON.stringify(inventory.map(({ name, category }) => ({ name, category })))}. Verified care records: ${JSON.stringify(care)}.`;
    const imageBytes = Buffer.from(await image.arrayBuffer()).toString("base64");
    const response = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent?key=${encodeURIComponent(apiKey)}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ contents: [{ parts: [{ text: prompt }, { inline_data: { mime_type: mimeType, data: imageBytes } }] }], generationConfig: { temperature: 0.2, responseMimeType: "application/json" } }),
      signal: AbortSignal.timeout(45_000)
    });
    const aiResult = await response.json().catch(() => ({}));
    if (!response.ok) {
      console.error("GreenPrint plant AI request failed", response.status, clean(aiResult?.error?.status || "AI error", 80));
      return json({ status: "error", message: "Plant identification is temporarily unavailable. Please try again." }, 502);
    }
    const plant = extractJson(aiResult?.candidates?.[0]?.content?.parts?.map((part) => part.text || "").join("\n"));
    const data = {
      name: clean(plant.name || "Unknown plant", 160),
      scientific_name: clean(plant.scientific_name, 160),
      care_instructions: clean(plant.care_instructions),
      sunlight: clean(plant.sunlight),
      watering: clean(plant.watering),
      soil_type: clean(plant.soil_type),
      ideal_temperature: clean(plant.ideal_temperature),
      is_toxic: typeof plant.is_toxic === "boolean" ? plant.is_toxic : null
    };
    const careMatch = care.find((row) => normalized(row.common_name) === normalized(data.name)
      || (data.scientific_name && normalized(row.scientific_name) === normalized(data.scientific_name)));
    if (careMatch) {
      for (const key of ["care_instructions", "sunlight", "watering", "soil_type", "ideal_temperature"]) {
        if (clean(careMatch[key])) data[key] = clean(careMatch[key]);
      }
      if (careMatch.is_toxic !== null && careMatch.is_toxic !== undefined) data.is_toxic = careMatch.is_toxic === true || careMatch.is_toxic === "true";
      if (!data.name) data.name = clean(careMatch.common_name, 160);
    }
    const matchNames = new Set([data.name, data.scientific_name, careMatch?.common_name, careMatch?.scientific_name].map(normalized).filter(Boolean));
    const products = inventory.filter((item) => {
      const productName = normalized(item.name);
      const alias = normalized(String(item.name).match(/\(([^)]*)\)/)?.[1] || "");
      return matchNames.has(productName) || (alias && matchNames.has(alias));
    }).map((item) => ({ id: String(item.id), sku: item.sku, name: item.name, category: item.category, price: Number(item.price), stock: Number(item.stock) }));
    return json({ status: "success", data, products });
  } catch (error) {
    console.error("GreenPrint plant scanner failed", error?.name || "Error", clean(error?.message || "unknown", 180));
    return json({ status: "error", message: "Plant identification is temporarily unavailable. Please try again or ask a garden specialist." }, 502);
  }
}
