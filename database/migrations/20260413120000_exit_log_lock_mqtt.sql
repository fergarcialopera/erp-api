-- MQTT device identity per locker (ESP32 / topic lockers/{deviceId}/cmd)
ALTER TABLE lockers
    ADD COLUMN IF NOT EXISTS device_id VARCHAR(128) NULL;

CREATE UNIQUE INDEX IF NOT EXISTS lockers_device_id_unique
    ON lockers (device_id)
    WHERE device_id IS NOT NULL;

-- Optional link from an exit log to the physical compartment (for remote lock open)
ALTER TABLE exit_logs
    ADD COLUMN IF NOT EXISTS compartment_public_id VARCHAR(26) NULL;

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

-- Audit trail: exit_log_id type must match exit_logs.id (bigint vs uuid depending on deployment)
DO $$
DECLARE
    id_data_type text;
    stmt text;
BEGIN
    SELECT c.data_type INTO id_data_type
    FROM information_schema.columns c
    WHERE c.table_schema = 'public'
      AND c.table_name = 'exit_logs'
      AND c.column_name = 'id';

    IF id_data_type IS NULL THEN
        RAISE EXCEPTION 'exit_logs.id not found';
    END IF;

    IF EXISTS (
        SELECT 1 FROM information_schema.tables
        WHERE table_schema = 'public' AND table_name = 'exit_log_lock_commands'
    ) THEN
        RETURN;
    END IF;

    IF id_data_type = 'bigint' THEN
        stmt := 'CREATE TABLE exit_log_lock_commands (
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
        )';
    ELSIF id_data_type = 'uuid' THEN
        stmt := 'CREATE TABLE exit_log_lock_commands (
            id BIGSERIAL PRIMARY KEY,
            exit_log_id UUID NOT NULL REFERENCES exit_logs (id),
            clinic_id UUID NOT NULL,
            device_id VARCHAR(128) NOT NULL,
            topic VARCHAR(512) NOT NULL,
            payload VARCHAR(64) NOT NULL,
            requested_by VARCHAR(64) NOT NULL,
            success BOOLEAN NOT NULL,
            error_message TEXT NULL,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
        )';
    ELSE
        RAISE EXCEPTION 'Unsupported exit_logs.id SQL type: %', id_data_type;
    END IF;

    EXECUTE stmt;
END $$;

CREATE INDEX IF NOT EXISTS exit_log_lock_commands_exit_log_id_idx
    ON exit_log_lock_commands (exit_log_id);
