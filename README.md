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

Netlify's build command runs `build-netlify.mjs` to publish the kiosk's static HTML, JavaScript, styles, and `IMAGE/` assets under `dist/`. Netlify does not run PHP files, so the POS, admin sign-in, and admin dashboard use Node functions under `netlify/functions/` when deployed. The catalog uses `public-config.mjs` for the browser-safe Supabase URL and anon key. The POS uses `process-checkout.mjs` to validate the server-side checkout PIN and atomically record sales and stock changes. Admin sign-in and dashboard requests use `admin-auth.mjs` and `admin-api.mjs`; admin passcodes are verified against PHP-compatible bcrypt hashes in `admin_users.passcode_hash`, and the function issues a signed, HttpOnly session cookie with CSRF protection.

Configure `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `GREENPRINT_DB_HOST`, `GREENPRINT_DB_PORT`, `GREENPRINT_DB_NAME`, `GREENPRINT_DB_USER`, `GREENPRINT_DB_PASSWORD`, `GREENPRINT_DB_SSLMODE`, `GREENPRINT_CHECKOUT_PIN`, and `GREENPRINT_TIMEZONE` in Netlify's environment variables, then redeploy. Set `GREENPRINT_ADMIN_SESSION_SECRET` to a long random value for a dedicated admin-cookie signing key; if it is omitted, the functions derive the signing key from the database password. Use `GREENPRINT_DB_SSLMODE=require` for encrypted connections without certificate validation. For certificate validation, set `GREENPRINT_DB_SSLMODE=verify-full` and provide Supabase's project CA certificate as the private `GREENPRINT_DB_CA_CERT` variable. These values must stay server-side; never add the database password, session secret, service-role key, Gemini key, admin passcode, or checkout PIN to browser code or Git. Local XAMPP continues to use the PHP endpoints.

### Switching Netlify accounts or sites

GitHub contains the application code, not Netlify environment variables. Every new Netlify site must be configured again: add the browser-safe `SUPABASE_URL` and `SUPABASE_ANON_KEY` (or `SUPABASE_PUBLISHABLE_KEY`) for the catalog, then add the server-only `GREENPRINT_DB_HOST`, `GREENPRINT_DB_PORT`, `GREENPRINT_DB_NAME`, `GREENPRINT_DB_USER`, `GREENPRINT_DB_PASSWORD`, and `GREENPRINT_CHECKOUT_PIN` values for the Netlify Functions. Apply them to the deploy contexts used by the site and redeploy after saving. Keep the database password and checkout PIN private.

The admin passcode is a bcrypt hash in `admin_users.passcode_hash`; it is not carried by a GitHub deploy and is not the checkout PIN. To keep the existing admin login, point the new site at the same GreenPrint database with the same database connection values. To keep checkout authorization, configure the same `GREENPRINT_CHECKOUT_PIN` value. If the new site points at a different database, it has different admin accounts and stored inventory/sales. Do not run the seed script just to troubleshoot a password: it creates an owner account only when there is no active hashed admin account. A missing database configuration reports that admin sign-in is not configured; a missing checkout PIN reports that checkout authorization is not configured. If those settings are present and the response still says the passcode or PIN is incorrect, verify the database/site and configured values rather than changing the client-side form.

The public products query relies on the read-only `greenprint_public_catalog_read` row-level security policy from `database.sql`. If the POS or visualizer still says inventory is unavailable after redeploying, confirm both Supabase browser config values are present in the Netlify Functions scope and that the Supabase catalog policy and grants have been applied. XAMPP continues to load its config through `app_config.php` and use `get_products.php` as the local fallback. Direct `.php` requests remain unavailable on Netlify.

## Shared watering and schedules

The owner dashboard has one shared **Water both areas now** control and one shared **Stop watering** control because the installed pump and valve are common to both growing areas. The ESP32 listens to the `zone1` command channel for this shared output; older `zone2` commands are ignored. There is one daily shared watering schedule, with a 30-second or 60-second watering duration. The ESP32 enforces the selected duration locally; the scheduled function also queues an OFF command as a backup. The scheduler checks once per minute using `GREENPRINT_TIMEZONE` (defaults to `Asia/Manila`). Netlify runs it only on a published production deploy; XAMPP's PHP endpoints can save schedules but do not run the scheduled watering job.

The current sketch preserves the supplied tank calibration: 39 cm empty and 24.5 cm full. The AJ-SR04M is read in UART mode at 9600 baud on ESP32 RX GPIO16 and TX GPIO17; set the sensor to UART output mode. If the sensor's TX output is 5 V, add a level shifter before GPIO16. Pump and valve relays use GPIO32 and GPIO33 and turn on together for shared watering. Do not connect Relay IN1 or IN3 directly to GND at the same time as GPIO32 or GPIO33; connect each used input to its GPIO signal and share ground between the ESP32 and relay logic. The solenoid valve is an on/off device and stays energized for the whole watering period; persistent low flow at one nozzle needs a tubing, blockage, pressure, or pump-capacity check.

To build the firmware from a fresh checkout, copy `firmware/secrets.example.h` to `firmware/secrets.h`, fill in the local Wi-Fi and Supabase values, then open `firmware/GreenPrint_ESP32.ino` in Arduino IDE. `secrets.h` is ignored by Git and must remain private.

## Application flows

- POS and visualizer load active, in-stock catalog rows through `@supabase/supabase-js@2` under the read-only RLS policy, with `get_products.php` as a fallback. Product notes and all transaction/admin data remain server-side.
- Checkout sends product IDs and quantities to the local PHP endpoint on XAMPP or the Netlify Function on the deployed site. The server checks the PIN, locks stock rows, calculates prices from the database, records the transaction and line items, deducts stock, and writes available inventory and admin audit logs in one database transaction.
- Admin pages use a signed HttpOnly cookie and CSRF-protected Netlify function actions for inventory, sales, watering, and schedule updates; local XAMPP continues to use PHP sessions and `admin_api.php`.
- The Garden Planner uses locally stored, polished top-down scene plans with lawns, buildings, patios, paths, and beds. It includes a one-metre guide and an angled perspective view. Inventory items can be placed separately, dragged around the plan, and dropped onto a pot to compose a planter. Select a composed pot to replace, separate, or remove its plant, pot, and optional pebbles. The hosted planner suggests in-stock plants for the selected indoor or outdoor scene and pairs each plant with the nearest available pot size; XAMPP may also use the optional PHP/Gemini planner. Plant care and actual dimensions are not part of the public catalog, so compatibility is an estimate based on product names and size labels.
- The plant scanner uses the kiosk camera only. Visitors can follow the on-screen steps to identify a plant, read care guidance, and browse matching products. The camera requires browser permission; localhost is allowed by modern browsers.
- The POS shows a short customer checkout guide; a staff member still authorizes the sale with the checkout PIN before the receipt prints.
- Thermal receipts use the browser's 80 mm print stylesheet. Configure the kiosk browser and printer driver for the intended paper width and kiosk printing behavior.
- The customer kiosk pages and owner dashboard include touch-sized controls and responsive layouts for tablet portrait and landscape use. The target Galaxy Tab A11 Wi-Fi display is 8.7 inches at 1340 × 800; landscape keeps the main workflows compact while portrait uses stacked, scrollable panels.
- The shared ESP32 controller polls the `zone1` command channel. Scheduled commands carry a 30- or 60-second local auto-stop value; the controller keeps the pump and common solenoid on together until that timer expires.

## Security and configuration notes

The SQL script enables row-level security and removes pre-existing policies on GreenPrint's application tables before adding read-only catalog policies. Review those policy changes before applying the script to a Supabase project that contains policies for other clients. PHP uses the configured database account for server-side access; do not publish its password.

Credentials that were present in the original source should be considered exposed. Rotate the Supabase database password and Gemini API key in their provider consoles, then update the local configuration before using the system.

Gemini's model is configurable with `GEMINI_MODEL`; the sample defaults to `gemini-3.8-flash`. Use a currently supported model listed in Google's [Gemini API model guide](https://ai.google.dev/gemini-api/docs/models).
