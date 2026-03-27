INSERT INTO clinics (id, name, created_at)
VALUES
    ('11111111-1111-1111-1111-111111111111', 'Clinic A', NOW()),
    ('99999999-9999-9999-9999-999999999999', 'Clinic B', NOW())
ON CONFLICT DO NOTHING;

UPDATE users SET clinic_id = '11111111-1111-1111-1111-111111111111', role = 'ADMIN', is_active = TRUE, updated_at = NOW() WHERE email = 'admin@clinic.local';
UPDATE users SET clinic_id = '11111111-1111-1111-1111-111111111111', role = 'TECHNICIAN', is_active = TRUE, updated_at = NOW() WHERE email = 'tech@clinic.local';
UPDATE users SET clinic_id = '11111111-1111-1111-1111-111111111111', role = 'STAFF', is_active = TRUE, updated_at = NOW() WHERE email = 'staff@clinic.local';
UPDATE users SET clinic_id = '99999999-9999-9999-9999-999999999999', role = 'ADMIN', is_active = TRUE, updated_at = NOW() WHERE email = 'admin2@clinic.local';
UPDATE users SET clinic_id = '99999999-9999-9999-9999-999999999999', role = 'TECHNICIAN', is_active = TRUE, updated_at = NOW() WHERE email = 'tech2@clinic.local';
UPDATE users SET clinic_id = '99999999-9999-9999-9999-999999999999', role = 'STAFF', is_active = TRUE, updated_at = NOW() WHERE email = 'staff2@clinic.local';

INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '22222222-2222-2222-2222-222222222222', '01J0000000000000000000001', '11111111-1111-1111-1111-111111111111', 'admin@clinic.local', 'Admin Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'ADMIN', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin@clinic.local');
INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '33333333-3333-3333-3333-333333333333', '01J0000000000000000000002', '11111111-1111-1111-1111-111111111111', 'tech@clinic.local', 'Tech Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'TECHNICIAN', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'tech@clinic.local');
INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '44444444-4444-4444-4444-444444444444', '01J0000000000000000000003', '11111111-1111-1111-1111-111111111111', 'staff@clinic.local', 'Staff Clinic A', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'STAFF', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'staff@clinic.local');
INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '55555555-5555-5555-5555-555555555555', '01J0000000000000000000004', '99999999-9999-9999-9999-999999999999', 'admin2@clinic.local', 'Admin Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'ADMIN', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'admin2@clinic.local');
INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '66666666-6666-6666-6666-666666666666', '01J0000000000000000000005', '99999999-9999-9999-9999-999999999999', 'tech2@clinic.local', 'Tech Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'TECHNICIAN', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'tech2@clinic.local');
INSERT INTO users (id, public_id, clinic_id, email, name, password_hash, role, is_active, created_at, updated_at)
SELECT '77777777-7777-7777-7777-777777777777', '01J0000000000000000000006', '99999999-9999-9999-9999-999999999999', 'staff2@clinic.local', 'Staff Clinic B', '$2y$12$MhBJgI6jq1uXk0y6zB9VGu4IjOGVx4Bb.cSK9BoV0mpkgYSHSJcKy', 'STAFF', TRUE, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'staff2@clinic.local');

