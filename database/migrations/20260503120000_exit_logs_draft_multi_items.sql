-- Exit logs: DRAFT/CONFIRMED/CANCELLED, line items, stock only on confirm.
-- exit_log_items.exit_log_id usa el mismo tipo que exit_logs.id (bigint o uuid).

DO $migration$
DECLARE
    id_type text;
BEGIN
    SELECT c.data_type INTO STRICT id_type
    FROM information_schema.columns c
    WHERE c.table_schema = 'public'
      AND c.table_name = 'exit_logs'
      AND c.column_name = 'id';

    IF id_type = 'bigint' THEN
        EXECUTE $sql$
            CREATE TABLE IF NOT EXISTS exit_log_items (
                id BIGSERIAL PRIMARY KEY,
                exit_log_id BIGINT NOT NULL REFERENCES exit_logs (id) ON DELETE CASCADE,
                product_public_id VARCHAR(26) NOT NULL REFERENCES products (public_id),
                compartment_public_id VARCHAR(26) NULL,
                requested_quantity INTEGER NOT NULL,
                confirmed_quantity INTEGER NULL,
                created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_exit_log_items_compartment FOREIGN KEY (compartment_public_id)
                    REFERENCES compartments (public_id)
                    ON DELETE SET NULL
            )
        $sql$;
    ELSIF id_type = 'uuid' THEN
        EXECUTE $sql$
            CREATE TABLE IF NOT EXISTS exit_log_items (
                id BIGSERIAL PRIMARY KEY,
                exit_log_id UUID NOT NULL REFERENCES exit_logs (id) ON DELETE CASCADE,
                product_public_id VARCHAR(26) NOT NULL REFERENCES products (public_id),
                compartment_public_id VARCHAR(26) NULL,
                requested_quantity INTEGER NOT NULL,
                confirmed_quantity INTEGER NULL,
                created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
                CONSTRAINT fk_exit_log_items_compartment FOREIGN KEY (compartment_public_id)
                    REFERENCES compartments (public_id)
                    ON DELETE SET NULL
            )
        $sql$;
    ELSE
        RAISE EXCEPTION 'exit_logs.id type % not supported for exit_log_items', id_type;
    END IF;
END
$migration$;

CREATE INDEX IF NOT EXISTS exit_log_items_exit_log_id_idx
    ON exit_log_items (exit_log_id);

ALTER TABLE exit_logs
    ADD COLUMN IF NOT EXISTS status VARCHAR(32);

UPDATE exit_logs
SET status = 'CONFIRMED'
WHERE status IS NULL;

ALTER TABLE exit_logs
    ALTER COLUMN status SET DEFAULT 'DRAFT';

ALTER TABLE exit_logs
    ALTER COLUMN status SET NOT NULL;

ALTER TABLE exit_logs
    ADD COLUMN IF NOT EXISTS confirmed_at TIMESTAMP WITH TIME ZONE;

ALTER TABLE exit_logs
    ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP WITH TIME ZONE;

ALTER TABLE exit_logs
    ADD COLUMN IF NOT EXISTS metadata JSONB;

UPDATE exit_logs
SET confirmed_at = created_at
WHERE status = 'CONFIRMED'
  AND confirmed_at IS NULL;

ALTER TABLE exit_logs
    ALTER COLUMN sku DROP NOT NULL;

ALTER TABLE exit_logs
    ALTER COLUMN quantity DROP NOT NULL;

INSERT INTO exit_log_items (
    exit_log_id,
    product_public_id,
    compartment_public_id,
    requested_quantity,
    confirmed_quantity,
    created_at,
    updated_at
)
SELECT
    el.id,
    p.public_id,
    el.compartment_public_id,
    el.quantity,
    el.quantity,
    el.created_at,
    NOW()
FROM exit_logs el
INNER JOIN products p
    ON p.clinic_id = el.clinic_id
    AND p.sku = el.sku
WHERE el.sku IS NOT NULL
  AND NOT EXISTS (
        SELECT 1
        FROM exit_log_items i
        WHERE i.exit_log_id = el.id
    );
