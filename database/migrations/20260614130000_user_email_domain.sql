-- Actualiza el dominio de email de usuarios existentes.

UPDATE users
SET email = REPLACE(email, '@clinic.local', '@clinic-erp.com'),
    updated_at = NOW()
WHERE email LIKE '%@clinic.local';
