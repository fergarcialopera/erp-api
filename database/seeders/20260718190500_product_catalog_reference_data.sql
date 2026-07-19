-- Datos de referencia para tipos de dispensación y roles operativos de locker.

INSERT INTO dispensing_types (id, name, slug, description, is_active, created_at, updated_at)
VALUES
    (uuid_generate_v4(), 'OTC', 'otc', 'Venta libre general', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'OTC-CLINICA', 'otc-clinica', 'Venta libre de uso clínico', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'OTC-TIENDA', 'otc-tienda', 'Venta libre de tienda', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'RECETA VETERINARIA', 'receta-veterinaria', 'Requiere receta veterinaria', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'CONTROL ESPECIAL', 'control-especial', 'Control especial / restringido', TRUE, NOW(), NOW())
ON CONFLICT (slug) DO NOTHING;

INSERT INTO roles (id, name, slug, description, is_active, created_at, updated_at)
VALUES
    (uuid_generate_v4(), 'Administrador', 'administrador', 'Administrador operativo de clínica', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'Veterinario', 'veterinario', 'Personal veterinario', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'Auxiliar', 'auxiliar', 'Personal auxiliar', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'Personal tienda', 'personal-tienda', 'Personal de tienda', TRUE, NOW(), NOW()),
    (uuid_generate_v4(), 'Responsable clínico', 'responsable-clinico', 'Responsable clínico', TRUE, NOW(), NOW())
ON CONFLICT (slug) DO NOTHING;

-- Permisos de retirada por tipo de dispensación (según matriz de negocio).
INSERT INTO dispensing_type_roles (id, dispensing_type_id, role_id, created_at, updated_at)
SELECT uuid_generate_v4(), dt.id, r.id, NOW(), NOW()
FROM dispensing_types dt
CROSS JOIN roles r
WHERE
    (dt.slug = 'otc' AND r.slug IN ('administrador', 'veterinario', 'auxiliar'))
    OR (dt.slug = 'otc-clinica' AND r.slug IN ('administrador', 'veterinario', 'auxiliar'))
    OR (dt.slug = 'otc-tienda' AND r.slug IN ('administrador', 'personal-tienda'))
    OR (dt.slug = 'receta-veterinaria' AND r.slug IN ('administrador', 'veterinario'))
    OR (dt.slug = 'control-especial' AND r.slug IN ('administrador', 'responsable-clinico'))
ON CONFLICT (dispensing_type_id, role_id) DO NOTHING;
