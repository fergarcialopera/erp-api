-- Ampliación del catálogo de productos para la importación CSV (export Odoo):
-- submarcas, especies, especialidades, etiquetas y campos adicionales de producto
-- (código nacional, empaquetado).

-- ---------------------------------------------------------------------------
-- Lookup tables
-- ---------------------------------------------------------------------------

CREATE TABLE sub_brands (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    brand_id UUID NOT NULL REFERENCES brands(id) ON DELETE RESTRICT,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT sub_brands_brand_slug_unique UNIQUE (brand_id, slug)
);

CREATE INDEX sub_brands_brand_id_idx ON sub_brands (brand_id);

CREATE TABLE species (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT species_slug_unique UNIQUE (slug)
);

CREATE TABLE specialties (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT specialties_slug_unique UNIQUE (slug)
);

CREATE TABLE product_tags (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT product_tags_slug_unique UNIQUE (slug)
);

-- ---------------------------------------------------------------------------
-- Products: campos nuevos
-- ---------------------------------------------------------------------------

ALTER TABLE products
    ADD COLUMN national_code VARCHAR(64) NULL,
    ADD COLUMN packaging VARCHAR(255) NULL,
    ADD COLUMN sub_brand_id UUID NULL REFERENCES sub_brands(id) ON DELETE RESTRICT,
    ADD COLUMN species_id UUID NULL REFERENCES species(id) ON DELETE RESTRICT,
    ADD COLUMN specialty_id UUID NULL REFERENCES specialties(id) ON DELETE RESTRICT;

-- El CN identifica unívocamente el producto cuando existe.
CREATE UNIQUE INDEX products_national_code_unique
    ON products (national_code)
    WHERE national_code IS NOT NULL;

-- Índice NO unique: la importación permite duplicar internal_reference a propósito;
-- solo se usa para detectar coincidencias con rapidez.
CREATE INDEX products_internal_reference_idx ON products (internal_reference);

CREATE INDEX products_sub_brand_id_idx ON products (sub_brand_id);
CREATE INDEX products_species_id_idx ON products (species_id);
CREATE INDEX products_specialty_id_idx ON products (specialty_id);

-- ---------------------------------------------------------------------------
-- Etiquetas N:M
-- ---------------------------------------------------------------------------

CREATE TABLE product_product_tags (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    product_id UUID NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    product_tag_id UUID NOT NULL REFERENCES product_tags(id) ON DELETE CASCADE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT product_product_tags_unique UNIQUE (product_id, product_tag_id)
);

CREATE INDEX product_product_tags_tag_id_idx ON product_product_tags (product_tag_id);
