-- Baseline inicial unica del proyecto.
-- Esta migracion limpia totalmente la base (tablas del esquema public) y la recrea.
-- Se conserva schema_migrations para permitir trazabilidad del migrador.

DO $$
DECLARE
    r record;
BEGIN
    FOR r IN
        SELECT tablename
        FROM pg_tables
        WHERE schemaname = 'public'
          AND tablename <> 'schema_migrations'
    LOOP
        EXECUTE format('DROP TABLE IF EXISTS public.%I CASCADE', r.tablename);
    END LOOP;
END $$;

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE clinics (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE users (
    id UUID PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    name VARCHAR(255) NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE products (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    UNIQUE (clinic_id, sku)
);

CREATE TABLE ambientes (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NULL,
    device_id VARCHAR(128) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX ambientes_device_id_unique
    ON ambientes (device_id)
    WHERE device_id IS NOT NULL;

CREATE TABLE compartments (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    ambiente_id UUID NOT NULL REFERENCES ambientes(id) ON DELETE CASCADE,
    code VARCHAR(128) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    UNIQUE (ambiente_id, code)
);

CREATE TABLE inventory_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    compartment_id UUID NULL REFERENCES compartments(id) ON DELETE SET NULL,
    quantity INTEGER NOT NULL DEFAULT 0 CHECK (quantity >= 0),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE entry_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    quantity INTEGER NOT NULL CHECK (quantity > 0),
    note TEXT NULL,
    created_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE exit_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    status VARCHAR(32) NOT NULL DEFAULT 'DRAFT',
    note TEXT NULL,
    created_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    confirmed_at TIMESTAMP WITH TIME ZONE NULL,
    cancelled_at TIMESTAMP WITH TIME ZONE NULL,
    metadata JSONB NULL,
    compartment_id UUID NULL REFERENCES compartments(id) ON DELETE SET NULL
);

CREATE TABLE exit_log_items (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    exit_log_id UUID NOT NULL REFERENCES exit_logs(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
    compartment_id UUID NULL REFERENCES compartments(id) ON DELETE SET NULL,
    requested_quantity INTEGER NOT NULL CHECK (requested_quantity >= 0),
    confirmed_quantity INTEGER NULL CHECK (confirmed_quantity IS NULL OR confirmed_quantity >= 0),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE incidents (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    severity VARCHAR(32) NOT NULL,
    source VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'OPEN',
    created_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE settings (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    key VARCHAR(100) NOT NULL,
    value JSONB NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    UNIQUE (clinic_id, key)
);

CREATE TABLE exit_log_lock_commands (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    exit_log_id UUID NOT NULL REFERENCES exit_logs(id) ON DELETE CASCADE,
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    device_id VARCHAR(128) NOT NULL,
    topic VARCHAR(512) NOT NULL,
    payload VARCHAR(64) NOT NULL,
    requested_by_user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    success BOOLEAN NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX users_clinic_role_active_idx ON users (clinic_id, role, is_active);
CREATE INDEX products_clinic_active_idx ON products (clinic_id, is_active);
CREATE INDEX inventory_items_clinic_idx ON inventory_items (clinic_id);
CREATE INDEX inventory_items_product_idx ON inventory_items (product_id);
CREATE INDEX inventory_items_compartment_idx ON inventory_items (compartment_id);
CREATE UNIQUE INDEX inventory_items_unassigned_unique_idx
    ON inventory_items (clinic_id, product_id)
    WHERE compartment_id IS NULL;
CREATE UNIQUE INDEX inventory_items_compartment_unique_idx
    ON inventory_items (clinic_id, product_id, compartment_id)
    WHERE compartment_id IS NOT NULL;
CREATE INDEX entry_logs_clinic_created_at_idx ON entry_logs (clinic_id, created_at DESC);
CREATE INDEX entry_logs_product_created_at_idx ON entry_logs (product_id, created_at DESC);
CREATE INDEX exit_logs_clinic_status_created_at_idx ON exit_logs (clinic_id, status, created_at DESC);
CREATE INDEX exit_logs_compartment_idx ON exit_logs (compartment_id);
CREATE INDEX exit_log_items_exit_log_idx ON exit_log_items (exit_log_id);
CREATE INDEX exit_log_items_product_idx ON exit_log_items (product_id);
CREATE INDEX compartments_clinic_ambiente_idx ON compartments (clinic_id, ambiente_id);
CREATE INDEX settings_clinic_idx ON settings (clinic_id);
CREATE INDEX incidents_clinic_created_at_idx ON incidents (clinic_id, created_at DESC);
CREATE INDEX exit_log_lock_commands_exit_log_idx ON exit_log_lock_commands (exit_log_id);
