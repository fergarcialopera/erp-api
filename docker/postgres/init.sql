-- Extensiones y base de datos de tests.
-- Nota: este script se ejecuta por psql en el arranque inicial del contenedor.

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Crear base de datos dedicada a tests si no existe.
SELECT 'CREATE DATABASE erp_test'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'erp_test')\gexec

-- Asegurar extensiones necesarias también en la BD de tests.
\connect erp_test
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
