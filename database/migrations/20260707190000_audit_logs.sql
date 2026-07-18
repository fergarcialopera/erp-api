-- Auditoría de accesos y actividad del sistema.

CREATE TABLE audit_logs (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    event VARCHAR(32) NOT NULL,
    success BOOLEAN NOT NULL,
    error VARCHAR(64) NULL,
    clinic_id UUID NULL REFERENCES clinics(id) ON DELETE SET NULL,
    user_id UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    request_id UUID NULL
);

CREATE INDEX idx_audit_logs_registered_at ON audit_logs (registered_at DESC);
CREATE INDEX idx_audit_logs_clinic_registered ON audit_logs (clinic_id, registered_at DESC);
CREATE INDEX idx_audit_logs_user_registered ON audit_logs (user_id, registered_at DESC);
CREATE INDEX idx_audit_logs_event ON audit_logs (event);

CREATE TABLE audit_activity (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    registered_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    type VARCHAR(16) NOT NULL CHECK (type IN ('add', 'edit', 'delete')),
    entity VARCHAR(32) NOT NULL,
    entity_id UUID NOT NULL,
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    clinic_id UUID NULL REFERENCES clinics(id) ON DELETE SET NULL,
    data JSONB NULL
);

CREATE INDEX idx_audit_activity_registered_at ON audit_activity (registered_at DESC);
CREATE INDEX idx_audit_activity_clinic_registered ON audit_activity (clinic_id, registered_at DESC);
CREATE INDEX idx_audit_activity_entity ON audit_activity (entity, entity_id, registered_at DESC);
CREATE INDEX idx_audit_activity_user_registered ON audit_activity (user_id, registered_at DESC);
CREATE INDEX idx_audit_activity_type ON audit_activity (type);
