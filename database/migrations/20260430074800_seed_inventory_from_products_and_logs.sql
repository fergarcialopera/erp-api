-- Baseline unico del proyecto:
-- 1) Esquema completo actual
-- 2) Datos iniciales funcionales para arrancar el sistema
-- 3) Idempotente para entornos nuevos y reutilizables

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

CREATE TABLE IF NOT EXISTS clinics (
    id UUID PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY,
    public_id VARCHAR(26) NOT NULL UNIQUE,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    email VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS inventory_items (
    id BIGSERIAL PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    sku VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    UNIQUE (clinic_id, sku)
);

-- Compatibilidad con instalaciones antiguas:
-- tabla legacy ya no usada por el codigo actual.
DROP TABLE IF EXISTS inventory CASCADE;

-- Asegura forma canonical de inventory_items incluso si ya existia con tipos legacy.
ALTER TABLE inventory_items
    ALTER COLUMN clinic_id TYPE UUID USING clinic_id::uuid;

ALTER TABLE inventory_items
    ALTER COLUMN updated_at TYPE TIMESTAMP WITH TIME ZONE USING updated_at AT TIME ZONE 'UTC';

ALTER TABLE inventory_items
    DROP CONSTRAINT IF EXISTS inventory_items_clinic_id_fkey;

ALTER TABLE inventory_items
    ADD CONSTRAINT inventory_items_clinic_id_fkey
        FOREIGN KEY (clinic_id)
        REFERENCES clinics(id)
        ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS entry_logs (
    id BIGSERIAL PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    sku VARCHAR(100) NOT NULL,
    quantity INTEGER NOT NULL,
    note TEXT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS exit_logs (
    id BIGSERIAL PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    sku VARCHAR(100) NOT NULL,
    quantity INTEGER NOT NULL,
    note TEXT NULL,
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    compartment_public_id VARCHAR(26) NULL
);

CREATE TABLE IF NOT EXISTS incidents (
    id BIGSERIAL PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    severity VARCHAR(32) NOT NULL,
    source VARCHAR(32) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'OPEN',
    created_by VARCHAR(64) NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS settings (
    id BIGSERIAL PRIMARY KEY,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    key VARCHAR(100) NOT NULL,
    value JSONB NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    UNIQUE (clinic_id, key)
);

CREATE TABLE IF NOT EXISTS products (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    public_id VARCHAR(26) NOT NULL UNIQUE,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    sku VARCHAR(255) NULL,
    name VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS lockers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    public_id VARCHAR(26) NOT NULL UNIQUE,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    name VARCHAR(255) NOT NULL,
    location VARCHAR(255) NULL,
    device_id VARCHAR(128) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS lockers_device_id_unique
    ON lockers (device_id)
    WHERE device_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS compartments (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    public_id VARCHAR(26) NOT NULL UNIQUE,
    clinic_id UUID NOT NULL REFERENCES clinics(id),
    locker_public_id VARCHAR(26) NOT NULL,
    code VARCHAR(128) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

ALTER TABLE exit_logs
    DROP CONSTRAINT IF EXISTS fk_exit_logs_compartment;

ALTER TABLE exit_logs
    ADD CONSTRAINT fk_exit_logs_compartment
        FOREIGN KEY (compartment_public_id)
        REFERENCES compartments (public_id)
        ON DELETE SET NULL;

CREATE INDEX IF NOT EXISTS exit_logs_compartment_public_id_idx
    ON exit_logs (compartment_public_id)
    WHERE compartment_public_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS exit_log_lock_commands (
    id BIGSERIAL PRIMARY KEY,
    exit_log_id BIGINT NOT NULL REFERENCES exit_logs (id),
    clinic_id UUID NOT NULL,
    device_id VARCHAR(128) NOT NULL,
    topic VARCHAR(512) NOT NULL,
    payload VARCHAR(64) NOT NULL,
    requested_by VARCHAR(64) NOT NULL,
    success BOOLEAN NOT NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS exit_log_lock_commands_exit_log_id_idx
    ON exit_log_lock_commands (exit_log_id);

TRUNCATE TABLE
    exit_log_lock_commands,
    exit_logs,
    entry_logs,
    inventory_items,
    incidents,
    settings,
    compartments,
    lockers,
    products,
    users,
    clinics
RESTART IDENTITY CASCADE;

INSERT INTO clinics (id, name, created_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'Clinic A', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'Clinic B', NOW());

INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
VALUES
    ('22222222-2222-2222-2222-222222222222', '01J0000000000000000000001', '11111111-1111-1111-1111-111111111111', 'admin@clinic.local', 'Admin Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'ADMIN', TRUE, NOW(), NOW()),
    ('33333333-3333-3333-3333-333333333333', '01J0000000000000000000002', '11111111-1111-1111-1111-111111111111', 'tech@clinic.local', 'Tech Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'TECHNICIAN', TRUE, NOW(), NOW()),
    ('44444444-4444-4444-4444-444444444444', '01J0000000000000000000003', '11111111-1111-1111-1111-111111111111', 'staff@clinic.local', 'Staff Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'STAFF', TRUE, NOW(), NOW()),
    ('55555555-5555-5555-5555-555555555555', '01J0000000000000000000004', '99999999-9999-9999-9999-999999999999', 'admin2@clinic.local', 'Admin Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'ADMIN', TRUE, NOW(), NOW()),
    ('66666666-6666-6666-6666-666666666666', '01J0000000000000000000005', '99999999-9999-9999-9999-999999999999', 'tech2@clinic.local', 'Tech Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'TECHNICIAN', TRUE, NOW(), NOW()),
    ('77777777-7777-7777-7777-777777777777', '01J0000000000000000000006', '99999999-9999-9999-9999-999999999999', 'staff2@clinic.local', 'Staff Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'STAFF', TRUE, NOW(), NOW());

INSERT INTO products (id, public_id, clinic_id, sku, name, is_active, created_at, updated_at)
VALUES
    ('a0a00000-0000-4000-8000-000000000001', '01KBASELINEPRODA0000000001', '11111111-1111-1111-1111-111111111111', 'SKU-A-001', 'Guantes nitrilo', TRUE, NOW(), NOW()),
    ('a0a00000-0000-4000-8000-000000000002', '01KBASELINEPRODA0000000002', '11111111-1111-1111-1111-111111111111', 'SKU-A-002', 'Mascarillas FFP2', TRUE, NOW(), NOW()),
    ('a0a00000-0000-4000-8000-000000000003', '01KBASELINEPRODA0000000003', '11111111-1111-1111-1111-111111111111', 'SKU-A-003', 'Jeringas 5ml', TRUE, NOW(), NOW()),
    ('a0a00000-0000-4000-8000-000000000004', '01KBASELINEPRODA0000000004', '11111111-1111-1111-1111-111111111111', 'SKU-A-004', 'Gasas esteriles', TRUE, NOW(), NOW()),
    ('b0b00000-0000-4000-8000-000000000001', '01KBASELINEPRODB0000000001', '99999999-9999-9999-9999-999999999999', 'SKU-B-001', 'Guantes nitrilo', TRUE, NOW(), NOW()),
    ('b0b00000-0000-4000-8000-000000000002', '01KBASELINEPRODB0000000002', '99999999-9999-9999-9999-999999999999', 'SKU-B-002', 'Mascarillas FFP2', TRUE, NOW(), NOW()),
    ('b0b00000-0000-4000-8000-000000000003', '01KBASELINEPRODB0000000003', '99999999-9999-9999-9999-999999999999', 'SKU-B-003', 'Jeringas 5ml', TRUE, NOW(), NOW()),
    ('b0b00000-0000-4000-8000-000000000004', '01KBASELINEPRODB0000000004', '99999999-9999-9999-9999-999999999999', 'SKU-B-004', 'Gasas esteriles', TRUE, NOW(), NOW());

INSERT INTO lockers (id, public_id, clinic_id, name, location, device_id, is_active, created_at, updated_at)
VALUES
    ('c0c00000-0000-4000-8000-000000000001', '01KBASELOCKERA00000000001', '11111111-1111-1111-1111-111111111111', 'Locker A1', 'Planta 1', 'DEVICE-A1', TRUE, NOW(), NOW()),
    ('c0c00000-0000-4000-8000-000000000002', '01KBASELOCKERA00000000002', '11111111-1111-1111-1111-111111111111', 'Locker A2', 'Planta 2', 'DEVICE-A2', TRUE, NOW(), NOW()),
    ('d0d00000-0000-4000-8000-000000000001', '01KBASELOCKERB00000000001', '99999999-9999-9999-9999-999999999999', 'Locker B1', 'Planta 1', 'DEVICE-B1', TRUE, NOW(), NOW()),
    ('d0d00000-0000-4000-8000-000000000002', '01KBASELOCKERB00000000002', '99999999-9999-9999-9999-999999999999', 'Locker B2', 'Planta 2', 'DEVICE-B2', TRUE, NOW(), NOW());

INSERT INTO compartments (id, public_id, clinic_id, locker_public_id, code, is_active, created_at, updated_at)
VALUES
    ('e0e00000-0000-4000-8000-000000000001', '01KBASECOMPA0000000000001', '11111111-1111-1111-1111-111111111111', '01KBASELOCKERA00000000001', 'A1-C1', TRUE, NOW(), NOW()),
    ('e0e00000-0000-4000-8000-000000000002', '01KBASECOMPA0000000000002', '11111111-1111-1111-1111-111111111111', '01KBASELOCKERA00000000001', 'A1-C2', TRUE, NOW(), NOW()),
    ('e0e00000-0000-4000-8000-000000000003', '01KBASECOMPA0000000000003', '11111111-1111-1111-1111-111111111111', '01KBASELOCKERA00000000002', 'A2-C1', TRUE, NOW(), NOW()),
    ('f0f00000-0000-4000-8000-000000000001', '01KBASECOMPB0000000000001', '99999999-9999-9999-9999-999999999999', '01KBASELOCKERB00000000001', 'B1-C1', TRUE, NOW(), NOW()),
    ('f0f00000-0000-4000-8000-000000000002', '01KBASECOMPB0000000000002', '99999999-9999-9999-9999-999999999999', '01KBASELOCKERB00000000002', 'B2-C1', TRUE, NOW(), NOW());

INSERT INTO entry_logs (clinic_id, sku, quantity, note, created_by, created_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-001', 50, 'Carga inicial', '22222222-2222-2222-2222-222222222222', NOW()),
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-002', 35, 'Carga inicial', '22222222-2222-2222-2222-222222222222', NOW()),
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-003', 60, 'Carga inicial', '22222222-2222-2222-2222-222222222222', NOW()),
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-004', 40, 'Carga inicial', '22222222-2222-2222-2222-222222222222', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'SKU-B-001', 55, 'Carga inicial', '55555555-5555-5555-5555-555555555555', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'SKU-B-002', 30, 'Carga inicial', '55555555-5555-5555-5555-555555555555', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'SKU-B-003', 45, 'Carga inicial', '55555555-5555-5555-5555-555555555555', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'SKU-B-004', 70, 'Carga inicial', '55555555-5555-5555-5555-555555555555', NOW());

INSERT INTO exit_logs (clinic_id, sku, quantity, note, created_by, compartment_public_id, created_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-001', 5, 'Consumo semanal', '33333333-3333-3333-3333-333333333333', '01KBASECOMPA0000000000001', NOW()),
    ('11111111-1111-1111-1111-111111111111', 'SKU-A-002', 3, 'Consumo semanal', '33333333-3333-3333-3333-333333333333', '01KBASECOMPA0000000000002', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'SKU-B-003', 7, 'Consumo semanal', '66666666-6666-6666-6666-666666666666', '01KBASECOMPB0000000000001', NOW());

INSERT INTO inventory_items (clinic_id, sku, name, quantity, updated_at)
SELECT
    p.clinic_id,
    p.sku,
    p.name,
    GREATEST(COALESCE(e.qty, 0) - COALESCE(x.qty, 0), 0) AS quantity,
    NOW()
FROM products p
LEFT JOIN (
    SELECT clinic_id, sku, SUM(quantity)::int AS qty
    FROM entry_logs
    GROUP BY clinic_id, sku
) e ON e.clinic_id = p.clinic_id AND e.sku = p.sku
LEFT JOIN (
    SELECT clinic_id, sku, SUM(quantity)::int AS qty
    FROM exit_logs
    GROUP BY clinic_id, sku
) x ON x.clinic_id = p.clinic_id AND x.sku = p.sku
ON CONFLICT (clinic_id, sku)
DO UPDATE SET
    name = EXCLUDED.name,
    quantity = EXCLUDED.quantity,
    updated_at = NOW();

INSERT INTO settings (clinic_id, key, value, updated_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'timezone', '"Europe/Madrid"'::jsonb, NOW()),
    ('11111111-1111-1111-1111-111111111111', 'currency', '"EUR"'::jsonb, NOW()),
    ('99999999-9999-9999-9999-999999999999', 'timezone', '"Europe/Madrid"'::jsonb, NOW()),
    ('99999999-9999-9999-9999-999999999999', 'currency', '"EUR"'::jsonb, NOW())
ON CONFLICT (clinic_id, key)
DO UPDATE SET
    value = EXCLUDED.value,
    updated_at = NOW();

INSERT INTO incidents (clinic_id, title, description, severity, source, status, created_by, created_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'Rotura de stock puntual', 'Reposicion planificada para el proximo turno', 'LOW', 'ERP', 'OPEN', '33333333-3333-3333-3333-333333333333', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'Lectura RFID inconsistente', 'Revisar dispositivo del locker B1', 'MEDIUM', 'IOT', 'OPEN', '66666666-6666-6666-6666-666666666666', NOW());
