-- SUPER_ADMIN, catálogo global de productos/ambientes y visibilidad por clínica.

CREATE TABLE user_clinics (
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (user_id, clinic_id)
);

CREATE INDEX user_clinics_clinic_idx ON user_clinics (clinic_id);

CREATE TABLE clinic_products (
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    visible BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (clinic_id, product_id)
);

CREATE INDEX clinic_products_product_idx ON clinic_products (product_id);

CREATE TABLE clinic_ambientes (
    clinic_id UUID NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    ambiente_id UUID NOT NULL REFERENCES ambientes(id) ON DELETE CASCADE,
    visible BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    PRIMARY KEY (clinic_id, ambiente_id)
);

CREATE INDEX clinic_ambientes_ambiente_idx ON clinic_ambientes (ambiente_id);

INSERT INTO clinic_products (clinic_id, product_id, visible)
SELECT clinic_id, id, is_active
FROM products;

INSERT INTO clinic_ambientes (clinic_id, ambiente_id, visible)
SELECT clinic_id, id, is_active
FROM ambientes;

INSERT INTO user_clinics (user_id, clinic_id)
SELECT id, clinic_id
FROM users
WHERE role = 'ADMIN' AND clinic_id IS NOT NULL;

ALTER TABLE users ALTER COLUMN clinic_id DROP NOT NULL;

ALTER TABLE products DROP CONSTRAINT products_clinic_id_fkey;
ALTER TABLE products DROP CONSTRAINT products_clinic_id_sku_key;
DROP INDEX IF EXISTS products_clinic_active_idx;
ALTER TABLE products DROP COLUMN clinic_id;
ALTER TABLE products ADD CONSTRAINT products_sku_unique UNIQUE (sku);
CREATE INDEX products_active_idx ON products (is_active);

ALTER TABLE ambientes DROP CONSTRAINT ambientes_clinic_id_fkey;
ALTER TABLE ambientes DROP COLUMN clinic_id;

ALTER TABLE zones DROP CONSTRAINT zones_clinic_id_fkey;
DROP INDEX IF EXISTS zones_clinic_ambiente_idx;
ALTER TABLE zones DROP COLUMN clinic_id;
CREATE INDEX zones_ambiente_idx ON zones (ambiente_id);

INSERT INTO users (id, clinic_id, name, email, password_hash, role, is_active, created_at, updated_at)
VALUES (
    '88888888-8888-8888-8888-888888888888',
    NULL,
    'Super Admin',
    'super@clinic-erp.com',
    '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy',
    'SUPER_ADMIN',
    TRUE,
    NOW(),
    NOW()
)
ON CONFLICT (email) DO UPDATE
SET role = EXCLUDED.role,
    clinic_id = EXCLUDED.clinic_id,
    password_hash = EXCLUDED.password_hash,
    is_active = EXCLUDED.is_active,
    updated_at = NOW();
