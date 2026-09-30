(function () {
  "use strict";

  const localHosts = new Set(["localhost", "127.0.0.1", "::1"]);
  const isLocalXampp = localHosts.has(window.location.hostname) || window.location.hostname.endsWith(".localhost");
  const hasSupabaseConfig = (config) => Boolean(config && config.supabaseUrl && config.supabaseAnonKey);

  window.GREENPRINT_CONFIG_READY = (async function loadGreenPrintConfig() {
    if (hasSupabaseConfig(window.GREENPRINT_CONFIG)) return window.GREENPRINT_CONFIG;

    if (!isLocalXampp) {
      try {
        const response = await fetch("/.netlify/functions/public-config", {
          headers: { Accept: "application/json" },
          cache: "no-store"
        });
        const result = await response.json();
        if (!response.ok || !hasSupabaseConfig(result)) {
          throw new Error(result.message || "Netlify Supabase configuration is missing.");
        }
        window.GREENPRINT_CONFIG = result;
        return result;
      } catch (error) {
        window.GREENPRINT_CONFIG_ERROR = error;
        console.error("GreenPrint could not load its Netlify Supabase configuration.", error);
        return {};
      }
    }

    try {
      const response = await fetch("app_config.php", { cache: "no-store" });
      if (!response.ok) throw new Error("Local PHP configuration endpoint is unavailable.");
      const source = await response.text();
      const match = source.match(/window\.GREENPRINT_CONFIG\s*=\s*(\{[\s\S]*?\})\s*;/);
      if (!match) throw new Error("Local PHP configuration returned an unexpected response.");
      const config = JSON.parse(match[1]);
      if (!hasSupabaseConfig(config)) throw new Error("Local Supabase configuration is incomplete.");
      window.GREENPRINT_CONFIG = config;
      return config;
    } catch (error) {
      window.GREENPRINT_CONFIG_ERROR = error;
      console.error("GreenPrint could not load its local Supabase configuration.", error);
      return {};
    }
  })();
})();
