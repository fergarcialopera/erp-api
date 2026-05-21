ALTER TABLE entry_logs
    ADD COLUMN compartment_id UUID NULL REFERENCES compartments(id) ON DELETE SET NULL;

CREATE INDEX entry_logs_compartment_idx ON entry_logs (compartment_id);
