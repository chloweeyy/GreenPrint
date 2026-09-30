export default async function publicConfig(request) {
  if (request.method !== "GET") {
    return new Response(JSON.stringify({ message: "Method not allowed." }), {
      status: 405,
      headers: { "Content-Type": "application/json; charset=utf-8", Allow: "GET" }
    });
  }

  const supabaseUrl = (process.env.SUPABASE_URL || "").trim().replace(/\/$/, "");
  const supabaseAnonKey = (process.env.SUPABASE_ANON_KEY || process.env.SUPABASE_PUBLISHABLE_KEY || "").trim();

  if (!supabaseUrl || !supabaseAnonKey) {
    return new Response(JSON.stringify({
      message: "Set SUPABASE_URL and SUPABASE_ANON_KEY in the Netlify Functions environment, then redeploy."
    }), {
      status: 503,
      headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" }
    });
  }

  try {
    const parsedUrl = new URL(supabaseUrl);
    if (parsedUrl.protocol !== "https:" || !parsedUrl.hostname.endsWith("supabase.co")) {
      throw new Error("Invalid Supabase project URL.");
    }
  } catch {
    return new Response(JSON.stringify({ message: "SUPABASE_URL must be a valid HTTPS Supabase project URL." }), {
      status: 503,
      headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store" }
    });
  }

  return new Response(JSON.stringify({ supabaseUrl, supabaseAnonKey }), {
    status: 200,
    headers: {
      "Content-Type": "application/json; charset=utf-8",
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff"
    }
  });
}
