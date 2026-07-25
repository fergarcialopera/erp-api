-- Sesiones de importación CSV de productos (export Odoo):
-- análisis dry-run, preview por fila y trazabilidad (fichero + usuario + producto resultante).

CREATE TABLE product_imports (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    filename VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_by UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    total_rows INTEGER NOT NULL DEFAULT 0,
    ready_count INTEGER NOT NULL DEFAULT 0,
    conflict_count INTEGER NOT NULL DEFAULT 0,
    invalid_count INTEGER NOT NULL DEFAULT 0,
    created_count INTEGER NOT NULL DEFAULT 0,
    updated_count INTEGER NOT NULL DEFAULT 0,
    failed_count INTEGER NOT NULL DEFAULT 0,
    skipped_count INTEGER NOT NULL DEFAULT 0,
    structural_errors JSONB NULL,
    catalog_preview JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT product_imports_status_check CHECK (
        status IN (
            'analyzing',
            'ready_for_review',
            'invalid',
            'processing',
            'completed',
            'completed_with_errors',
            'cancelled'
        )
    )
);

CREATE INDEX product_imports_created_by_idx ON product_imports (created_by);
CREATE INDEX product_imports_created_at_idx ON product_imports (created_at DESC);
CREATE INDEX product_imports_status_idx ON product_imports (status);

CREATE TABLE product_import_rows (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    import_id UUID NOT NULL REFERENCES product_imports(id) ON DELETE CASCADE,
    row_number INTEGER NOT NULL,
    status VARCHAR(32) NOT NULL,
    decision VARCHAR(32) NULL,
    existing_product_id UUID NULL REFERENCES products(id) ON DELETE SET NULL,
    result_product_id UUID NULL REFERENCES products(id) ON DELETE SET NULL,
    raw_payload JSONB NOT NULL,
    resolved_payload JSONB NULL,
    errors JSONB NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT product_import_rows_import_row_unique UNIQUE (import_id, row_number),
    CONSTRAINT product_import_rows_status_check CHECK (
        status IN (
            'ready',
            'conflict',
            'invalid',
            'created',
            'updated',
            'failed',
            'skipped'
        )
    ),
    CONSTRAINT product_import_rows_decision_check CHECK (
        decision IS NULL OR decision IN ('create_new', 'update_existing', 'skip')
    )
);

CREATE INDEX product_import_rows_import_id_idx ON product_import_rows (import_id);
CREATE INDEX product_import_rows_status_idx ON product_import_rows (import_id, status);
CREATE INDEX product_import_rows_existing_product_id_idx ON product_import_rows (existing_product_id);
CREATE INDEX product_import_rows_result_product_id_idx ON product_import_rows (result_product_id);
