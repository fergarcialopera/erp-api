ALTER TABLE clinics
    ADD COLUMN IF NOT EXISTS visible BOOLEAN NOT NULL DEFAULT TRUE,
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(512) NULL;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS pin_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS image_path VARCHAR(512) NULL,
    ADD COLUMN IF NOT EXISTS is_locked BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS locked_at TIMESTAMP WITH TIME ZONE NULL;

CREATE TABLE IF NOT EXISTS recovery_tokens (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type VARCHAR(32) NOT NULL,
    subject_id UUID NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP WITH TIME ZONE NOT NULL,
    used_at TIMESTAMP WITH TIME ZONE NULL,
    created_by_user_id UUID NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS recovery_tokens_subject_type_idx ON recovery_tokens (subject_id, type);
CREATE INDEX IF NOT EXISTS recovery_tokens_token_hash_idx ON recovery_tokens (token_hash);
