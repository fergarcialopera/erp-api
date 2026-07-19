-- Catálogo relacional de productos: categorías, marcas, proveedores,
-- tipos de dispensación, roles operativos y vínculos con products/users.

-- ---------------------------------------------------------------------------
-- Lookup tables
-- ---------------------------------------------------------------------------

CREATE TABLE categories (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT categories_slug_unique UNIQUE (slug)
);

CREATE TABLE subcategories (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    category_id UUID NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT subcategories_category_slug_unique UNIQUE (category_id, slug)
);

CREATE INDEX subcategories_category_id_idx ON subcategories (category_id);

CREATE TABLE brands (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT brands_slug_unique UNIQUE (slug)
);

CREATE TABLE suppliers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255) NULL,
    tax_id VARCHAR(64) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(64) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT suppliers_slug_unique UNIQUE (slug)
);

CREATE TABLE brand_supplier (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    brand_id UUID NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
    supplier_id UUID NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT brand_supplier_brand_supplier_unique UNIQUE (brand_id, supplier_id)
);

CREATE INDEX brand_supplier_supplier_id_idx ON brand_supplier (supplier_id);

CREATE TABLE dispensing_types (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT dispensing_types_slug_unique UNIQUE (slug)
);

-- Roles operativos de locker (independientes de users.role de autenticación API).
CREATE TABLE roles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT roles_slug_unique UNIQUE (slug)
);

CREATE TABLE dispensing_type_roles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    dispensing_type_id UUID NOT NULL REFERENCES dispensing_types(id) ON DELETE CASCADE,
    role_id UUID NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT dispensing_type_roles_unique UNIQUE (dispensing_type_id, role_id)
);

CREATE INDEX dispensing_type_roles_role_id_idx ON dispensing_type_roles (role_id);

-- ---------------------------------------------------------------------------
-- Products: campos de catálogo (sku / is_active se conservan)
-- ---------------------------------------------------------------------------

ALTER TABLE products
    ADD COLUMN barcode VARCHAR(64) NULL,
    ADD COLUMN internal_reference VARCHAR(100) NULL,
    ADD COLUMN category_id UUID NULL REFERENCES categories(id) ON DELETE RESTRICT,
    ADD COLUMN subcategory_id UUID NULL REFERENCES subcategories(id) ON DELETE RESTRICT,
    ADD COLUMN brand_id UUID NULL REFERENCES brands(id) ON DELETE RESTRICT,
    ADD COLUMN dispensing_type_id UUID NULL REFERENCES dispensing_types(id) ON DELETE RESTRICT,
    ADD COLUMN unit_of_measure VARCHAR(64) NOT NULL DEFAULT 'Unidades';

CREATE UNIQUE INDEX products_barcode_unique
    ON products (barcode)
    WHERE barcode IS NOT NULL;

CREATE INDEX products_category_id_idx ON products (category_id);
CREATE INDEX products_subcategory_id_idx ON products (subcategory_id);
CREATE INDEX products_brand_id_idx ON products (brand_id);
CREATE INDEX products_dispensing_type_id_idx ON products (dispensing_type_id);

CREATE TABLE product_suppliers (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    supplier_id UUID NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
    supplier_reference VARCHAR(100) NULL,
    purchase_price NUMERIC(12, 4) NULL,
    pvp NUMERIC(12, 4) NULL,
    net_cost NUMERIC(12, 4) NULL,
    is_preferred BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- NULL en supplier_reference no rompe unicidad (COALESCE a '').
CREATE UNIQUE INDEX product_suppliers_product_supplier_ref_unique
    ON product_suppliers (product_id, supplier_id, (COALESCE(supplier_reference, '')));

CREATE INDEX product_suppliers_supplier_id_idx ON product_suppliers (supplier_id);

-- Solo un proveedor preferente por producto.
CREATE UNIQUE INDEX product_suppliers_one_preferred_per_product
    ON product_suppliers (product_id)
    WHERE is_preferred = TRUE;

-- ---------------------------------------------------------------------------
-- Users: vínculo con rol operativo (además de users.role de API)
-- ---------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN operational_role_id UUID NULL REFERENCES roles(id) ON DELETE SET NULL;

CREATE INDEX users_operational_role_id_idx ON users (operational_role_id);
