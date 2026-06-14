ALTER TABLE entry_logs
    ADD COLUMN zone_id UUID NULL REFERENCES zones(id) ON DELETE SET NULL;

CREATE INDEX entry_logs_zone_idx ON entry_logs (zone_id);
