# GreenPrint — KGG Garden Store

Touch-friendly garden store kiosk with POS, plant identification, a garden visualizer, an admin dashboard, and PHP endpoints for sensor and watering-device integration.

## Setup on XAMPP

1. Use PHP 8.1 or newer. Enable `pdo_pgsql`, `curl`, and `fileinfo` in XAMPP's `php.ini`, then restart Apache.
2. Configure the app using either a root `.env` copied from `.env.example`, or `greenprint.local.php` copied from `greenprint.local.example.php`. Fill in Supabase's PostgreSQL pooler host, user, and a newly rotated database password. Add a Gemini API key, device key, admin username/passcode, and a unique checkout PIN. Use an admin passcode of at least 10 characters and a 4–8 digit checkout PIN. Keep both config files private.
3. In the Supabase SQL editor, review `database.sql` and run it against the GreenPrint project. Back up an existing database first. The script creates the catalog, sales, staff, audit, plant care, alert, sensor, and watering tables. It enables row-level security, removes existing policies on those GreenPrint tables, and allows public reads only for active, in-stock products. Check the policy changes if this Supabase project already serves other clients.
4. From the GreenPrint directory, run `php seed.php` in the XAMPP Shell. This imports the 93 sample products and creates the first owner account when no active hashed admin account exists.
5. Run `php update_images.php` from the same directory to map matching product names to local files under `IMAGE/`.
6. Open `http://localhost/GreenPrint/` in the kiosk browser. Sign in to the admin panel through `Admin_login.html`.

`.env` and `greenprint.local.php` are ignored by Git and blocked from direct HTTP access by `.htaccess`. Keep Apache `AllowOverride` enabled so those access rules take effect. The PHP database connection is server-side; the browser never receives the PostgreSQL password or Gemini key.

## Deploying the catalog to Netlify

Netlify's build command runs `build-netlify.mjs` to publish only the kiosk's static HTML, JavaScript, styles, and `IMAGE/` assets under `dist/`. Netlify does not run the PHP files in this project. The catalog uses `netlify/functions/public-config.mjs` to read the browser-safe Supabase project URL and anon/publishable key from Netlify Functions environment variables. The POS uses `netlify/functions/process-checkout.mjs` to validate the server-side checkout PIN and atomically record sales and stock changes through PostgreSQL. Configure `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `GREENPRINT_DB_HOST`, `GREENPRINT_DB_PORT`, `GREENPRINT_DB_NAME`, `GREENPRINT_DB_USER`, `GREENPRINT_DB_PASSWORD`, `GREENPRINT_DB_SSLMODE`, `GREENPRINT_CHECKOUT_PIN`, and `GREENPRINT_TIMEZONE` in Netlify's environment variables, then redeploy. Use `GREENPRINT_DB_SSLMODE=require` for encrypted connections without certificate validation. For certificate validation, set `GREENPRINT_DB_SSLMODE=verify-full` and provide Supabase's project CA certificate as the private `GREENPRINT_DB_CA_CERT` variable. These values must stay server-side; never add the database password, service-role key, Gemini key, admin passcode, or checkout PIN to browser code or Git. Local XAMPP POS continues to use `process_checkout.php`.

The public products query relies on the read-only `greenprint_public_catalog_read` row-level security policy from `database.sql`. If the POS or visualizer still says inventory is unavailable after redeploying, confirm both Supabase browser config values are present in the Netlify Functions scope and that the Supabase catalog policy and grants have been applied. XAMPP continues to load its config through `app_config.php` and use `get_products.php` as the local fallback. The PHP-based admin, scanner, and device endpoints still need migration to Netlify Functions before those server-side features can run on a Netlify-only deployment; Netlify returns a GreenPrint unavailable page for direct `.php` requests.

## Application flows

- POS and visualizer load active, in-stock catalog rows through `@supabase/supabase-js@2` under the read-only RLS policy, with `get_products.php` as a fallback. Product notes and all transaction/admin data remain server-side.
- Checkout sends product IDs and quantities to the local PHP endpoint on XAMPP or the Netlify Function on the deployed site. The server checks the PIN, locks stock rows, calculates prices from the database, records the transaction and line items, deducts stock, and writes available inventory and admin audit logs in one database transaction.
- Admin pages use the PHP session and CSRF-protected `admin_api.php` actions for inventory, sales, watering, and schedule updates.
- The Garden Planner uses locally stored, illustrated top-down garden plans (pool courtyard, cottage patio, and kitchen garden) with lawns, buildings, patios, paths, beds, and trees. It includes a one-metre guide and an angled perspective view. Inventory items can be placed separately, dragged around the plan, and dropped onto a pot to compose a planter. Select a composed pot to replace, separate, or remove its plant, pot, and optional pebbles. AI proposes plant-and-pot placements without adding pebbles automatically; a local layout is used if Gemini is unavailable.
- The plant scanner uses the kiosk camera only. Visitors can follow the on-screen steps to identify a plant, read care guidance, and browse matching products. The camera requires browser permission; localhost is allowed by modern browsers.
- The POS shows a short customer checkout guide; a staff member still authorizes the sale with the checkout PIN before the receipt prints.
- Thermal receipts use the browser's 80 mm print stylesheet. Configure the kiosk browser and printer driver for the intended paper width and kiosk printing behavior.
- The customer kiosk pages and owner dashboard include touch-sized controls and responsive layouts for tablet portrait and landscape use. The target Galaxy Tab A11 Wi-Fi display is 8.7 inches at 1340 × 800; landscape keeps the main workflows compact while portrait uses stacked, scrollable panels.
- Sensor ingestion and pump polling expect the `X-GreenPrint-Device-Key` header. Pump polling accepts `zone_id=zone1` or `zone2`.

## Security and configuration notes

The SQL script enables row-level security and removes pre-existing policies on GreenPrint's application tables before adding read-only catalog policies. Review those policy changes before applying the script to a Supabase project that contains policies for other clients. PHP uses the configured database account for server-side access; do not publish its password.

Credentials that were present in the original source should be considered exposed. Rotate the Supabase database password and Gemini API key in their provider consoles, then update the local configuration before using the system.

Gemini's model is configurable with `GEMINI_MODEL`; the sample defaults to `gemini-3.8-flash`. Use a currently supported model listed in Google's [Gemini API model guide](https://ai.google.dev/gemini-api/docs/models).
