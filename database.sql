-- GreenPrint / KGG Garden Store schema.
-- Back up the Supabase project before applying this to an existing database.

CREATE TABLE IF NOT EXISTS categories (
    category_id BIGSERIAL PRIMARY KEY,
    category_name TEXT NOT NULL UNIQUE,
    description TEXT,
    slug TEXT UNIQUE
);
-- Existing GreenPrint databases may already have category_id/category_name/description.
-- Add and backfill slug without replacing or deleting their existing categories.
ALTER TABLE categories ADD COLUMN IF NOT EXISTS slug TEXT;
UPDATE categories
SET slug = trim(both '-' FROM regexp_replace(lower(category_name), '[^a-z0-9]+', '-', 'g'))
WHERE slug IS NULL OR slug = '';
CREATE UNIQUE INDEX IF NOT EXISTS categories_slug_unique_idx ON categories (slug);
INSERT INTO categories (category_name, description, slug) VALUES
    ('Indoor Plants', 'Plants suited for indoor spaces.', 'indoor'),
    ('Outdoor Plants', 'Plants suited for outdoor spaces.', 'outdoor'),
    ('Pots', 'Plant pots and containers.', 'pots'),
    ('Pebbles', 'Decorative pebbles and stones.', 'pebbles'),
    ('Supplies', 'Garden care and maintenance supplies.', 'supplies')
ON CONFLICT (category_name) DO NOTHING;

CREATE TABLE IF NOT EXISTS products (
    id BIGSERIAL PRIMARY KEY,
    sku TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    category TEXT NOT NULL DEFAULT 'indoor',
    price NUMERIC(12,2) NOT NULL DEFAULT 0 CHECK (price >= 0),
    stock INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT,
    image_url TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE products ADD COLUMN IF NOT EXISTS sku TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS name TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS category TEXT NOT NULL DEFAULT 'indoor';
ALTER TABLE products ADD COLUMN IF NOT EXISTS price NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS stock INTEGER NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE products ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS image_url TEXT;
ALTER TABLE products ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE products ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE UNIQUE INDEX IF NOT EXISTS products_sku_unique_idx ON products (sku);
CREATE INDEX IF NOT EXISTS products_live_catalog_idx ON products (is_active, stock, category);

CREATE TABLE IF NOT EXISTS transactions (
    id BIGSERIAL PRIMARY KEY,
    transaction_ref TEXT NOT NULL UNIQUE,
    total_amount NUMERIC(12,2) NOT NULL DEFAULT 0,
    subtotal NUMERIC(12,2) NOT NULL DEFAULT 0,
    "timestamp" TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS transaction_ref TEXT;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS total_amount NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS subtotal NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS "timestamp" TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS payment_method TEXT NOT NULL DEFAULT 'cash';
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS customer_name TEXT;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS amount_received NUMERIC(12,2);
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS change_amount NUMERIC(12,2);
CREATE UNIQUE INDEX IF NOT EXISTS transactions_ref_idx ON transactions (transaction_ref) WHERE transaction_ref IS NOT NULL;

CREATE TABLE IF NOT EXISTS transaction_items (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES transactions(id) ON DELETE CASCADE,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    product_name TEXT NOT NULL,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    unit_price NUMERIC(12,2) NOT NULL DEFAULT 0,
    line_total NUMERIC(12,2) NOT NULL DEFAULT 0
);
ALTER TABLE transaction_items ADD COLUMN IF NOT EXISTS product_id BIGINT;
ALTER TABLE transaction_items ADD COLUMN IF NOT EXISTS product_name TEXT;
ALTER TABLE transaction_items ADD COLUMN IF NOT EXISTS quantity INTEGER NOT NULL DEFAULT 1;
ALTER TABLE transaction_items ADD COLUMN IF NOT EXISTS unit_price NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE transaction_items ADD COLUMN IF NOT EXISTS line_total NUMERIC(12,2) NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS transaction_items_transaction_idx ON transaction_items (transaction_id);

CREATE TABLE IF NOT EXISTS admin_users (
    id BIGSERIAL PRIMARY KEY,
    username TEXT NOT NULL UNIQUE,
    passcode_hash TEXT NOT NULL,
    checkout_pin_hash TEXT,
    role TEXT NOT NULL DEFAULT 'admin',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS username TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS passcode_hash TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS checkout_pin_hash TEXT;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS role TEXT NOT NULL DEFAULT 'admin';
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE admin_users ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE admin_users DROP CONSTRAINT IF EXISTS admin_users_role_check;
CREATE UNIQUE INDEX IF NOT EXISTS admin_users_username_idx ON admin_users (username) WHERE username IS NOT NULL;

CREATE TABLE IF NOT EXISTS admin_logs (
    id BIGSERIAL PRIMARY KEY,
    admin_id BIGINT REFERENCES admin_users(id) ON DELETE SET NULL,
    action TEXT NOT NULL,
    details TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE admin_logs ADD COLUMN IF NOT EXISTS admin_id BIGINT;
ALTER TABLE admin_logs ADD COLUMN IF NOT EXISTS action TEXT;
ALTER TABLE admin_logs ADD COLUMN IF NOT EXISTS details TEXT;
ALTER TABLE admin_logs ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS inventory_logs (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    change_amount INTEGER NOT NULL,
    reason TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS product_id BIGINT;
ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS change_amount INTEGER NOT NULL DEFAULT 0;
ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS reason TEXT NOT NULL DEFAULT 'Adjustment';
ALTER TABLE inventory_logs ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS inventory_logs_product_idx ON inventory_logs (product_id, created_at DESC);

CREATE TABLE IF NOT EXISTS system_alerts (
    id BIGSERIAL PRIMARY KEY,
    alert_type TEXT NOT NULL,
    message TEXT NOT NULL,
    is_resolved BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ
);
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS alert_type TEXT;
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS message TEXT;
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS is_resolved BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE system_alerts ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS alerts (
    id BIGSERIAL PRIMARY KEY,
    alert_type TEXT NOT NULL,
    message TEXT NOT NULL,
    is_resolved BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ
);
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS alert_type TEXT;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS message TEXT;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS is_resolved BOOLEAN NOT NULL DEFAULT FALSE;
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE alerts ADD COLUMN IF NOT EXISTS resolved_at TIMESTAMPTZ;

CREATE TABLE IF NOT EXISTS plant_care_info (
    id BIGSERIAL PRIMARY KEY,
    product_id BIGINT REFERENCES products(id) ON DELETE SET NULL,
    common_name TEXT NOT NULL,
    scientific_name TEXT,
    care_instructions TEXT,
    sunlight TEXT,
    watering TEXT,
    soil_type TEXT,
    ideal_temperature TEXT,
    is_toxic BOOLEAN,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (common_name)
);
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS product_id BIGINT REFERENCES products(id) ON DELETE SET NULL;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS common_name TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS scientific_name TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS care_instructions TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS sunlight TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS watering TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS soil_type TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS ideal_temperature TEXT;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS is_toxic BOOLEAN;
ALTER TABLE plant_care_info ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();

CREATE TABLE IF NOT EXISTS sensor_readings (
    id BIGSERIAL PRIMARY KEY,
    zone_id TEXT NOT NULL DEFAULT 'zone1',
    temperature NUMERIC(6,2),
    humidity NUMERIC(6,2),
    soil_moisture NUMERIC(6,2),
    water_level NUMERIC(6,2),
    reading_time TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS zone_id TEXT NOT NULL DEFAULT 'zone1';
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS temperature NUMERIC(6,2);
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS humidity NUMERIC(6,2);
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS soil_moisture NUMERIC(6,2);
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS water_level NUMERIC(6,2);
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS reading_time TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE sensor_readings ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS sensor_readings_created_idx ON sensor_readings (created_at DESC);

CREATE TABLE IF NOT EXISTS device_commands (
    id BIGSERIAL PRIMARY KEY,
    zone_id TEXT NOT NULL DEFAULT 'zone1',
    zone_name TEXT NOT NULL,
    command TEXT NOT NULL CHECK (command IN ('ON', 'OFF')),
    status TEXT NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    acknowledged_at TIMESTAMPTZ,
    processed_at TIMESTAMPTZ,
    issued_by BIGINT REFERENCES admin_users(id) ON DELETE SET NULL
);
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS zone_id TEXT NOT NULL DEFAULT 'zone1';
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS zone_name TEXT NOT NULL DEFAULT 'Zone 01 — Indoor';
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS command TEXT;
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'PENDING';
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS acknowledged_at TIMESTAMPTZ;
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS processed_at TIMESTAMPTZ;
ALTER TABLE device_commands ADD COLUMN IF NOT EXISTS issued_by BIGINT REFERENCES admin_users(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS device_commands_pending_idx ON device_commands (zone_id, id) WHERE status = 'PENDING';

CREATE TABLE IF NOT EXISTS watering_logs (
    id BIGSERIAL PRIMARY KEY,
    zone_id TEXT NOT NULL DEFAULT 'zone1',
    zone_name TEXT NOT NULL,
    status TEXT NOT NULL,
    admin_user_id BIGINT REFERENCES admin_users(id) ON DELETE SET NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE watering_logs ADD COLUMN IF NOT EXISTS zone_id TEXT NOT NULL DEFAULT 'zone1';
ALTER TABLE watering_logs ADD COLUMN IF NOT EXISTS zone_name TEXT;
ALTER TABLE watering_logs ADD COLUMN IF NOT EXISTS status TEXT;
ALTER TABLE watering_logs ADD COLUMN IF NOT EXISTS admin_user_id BIGINT REFERENCES admin_users(id) ON DELETE SET NULL;
ALTER TABLE watering_logs ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE INDEX IF NOT EXISTS watering_logs_created_idx ON watering_logs (created_at DESC);

CREATE TABLE IF NOT EXISTS watering_schedules (
    id BIGSERIAL PRIMARY KEY,
    zone_id TEXT NOT NULL UNIQUE,
    schedule_time TEXT NOT NULL,
    duration_minutes INTEGER NOT NULL DEFAULT 1,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS zone_id TEXT NOT NULL DEFAULT 'zone1';
ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS schedule_time TEXT NOT NULL DEFAULT '06:00';
ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS duration_minutes INTEGER NOT NULL DEFAULT 1;
ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS enabled BOOLEAN NOT NULL DEFAULT TRUE;
ALTER TABLE watering_schedules ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW();
CREATE UNIQUE INDEX IF NOT EXISTS watering_schedules_zone_unique_idx ON watering_schedules (zone_id);

CREATE TABLE IF NOT EXISTS watering_schedule_runs (
    schedule_slot TEXT NOT NULL CHECK (schedule_slot IN ('zone1', 'zone2')),
    run_date DATE NOT NULL,
    started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    stops_at TIMESTAMPTZ NOT NULL,
    status TEXT NOT NULL DEFAULT 'RUNNING' CHECK (status IN ('RUNNING', 'DONE', 'CANCELLED')),
    finished_at TIMESTAMPTZ,
    PRIMARY KEY (schedule_slot, run_date)
);

INSERT INTO watering_schedules (zone_id, schedule_time) VALUES
    ('zone1', '06:00'), ('zone2', '07:00')
ON CONFLICT (zone_id) DO NOTHING;

-- The browser reaches inventory through the PHP API. Restrict PostgREST access
-- to public, active, in-stock catalog reads and block direct table access elsewhere.
DO $$
DECLARE
    app_table TEXT;
    existing_policy RECORD;
BEGIN
    FOREACH app_table IN ARRAY ARRAY[
        'categories', 'products', 'transactions', 'transaction_items', 'admin_users',
        'admin_logs', 'inventory_logs', 'system_alerts', 'alerts', 'plant_care_info',
        'sensor_readings', 'device_commands', 'watering_logs', 'watering_schedules'
    ] LOOP
        EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', app_table);
        FOR existing_policy IN SELECT policyname FROM pg_policies WHERE schemaname = current_schema() AND tablename = app_table LOOP
            EXECUTE format('DROP POLICY %I ON %I', existing_policy.policyname, app_table);
        END LOOP;
    END LOOP;
END $$;
CREATE POLICY greenprint_public_catalog_read ON products
    FOR SELECT TO anon, authenticated
    USING (is_active IS TRUE AND stock > 0);
GRANT USAGE ON SCHEMA public TO anon, authenticated;
REVOKE ALL PRIVILEGES ON TABLE products FROM anon, authenticated, PUBLIC;
GRANT SELECT (id, sku, name, category, price, stock, is_active, image_url) ON TABLE products TO anon, authenticated;
