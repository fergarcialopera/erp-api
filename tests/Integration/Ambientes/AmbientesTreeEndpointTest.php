<?php

declare(strict_types=1);

namespace Tests\Integration\Ambientes;

use Tests\Integration\Support\BaseApiTestCase;

final class AmbientesTreeEndpointTest extends BaseApiTestCase
{
    private const AMBIENTE_A1 = '40000000-0000-4000-8000-000000000001';
    private const AMBIENTE_B1 = '40000000-0000-4000-8000-000000000002';

    public function testListAmbientesTreeRequiresAuth(): void
    {
        $res = $this->request('GET', '/api/v1/ambientes/tree');
        $this->assertSame(401, $res['status']);
    }

    public function testListAmbientesTreeReturnsNestedZonesForClinic(): void
    {
        $res = $this->request('GET', '/api/v1/ambientes/tree', null, $this->authHeaderFor('staff@clinic-erp.com'));
        $this->assertSame(200, $res['status']);

        $data = $res['json']['data'] ?? null;
        $this->assertIsArray($data);

        $ambienteIds = array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), $data);
        $this->assertContains(self::AMBIENTE_A1, $ambienteIds);
        $this->assertNotContains(self::AMBIENTE_B1, $ambienteIds);

        $ambiente = null;
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['id'] ?? '') === self::AMBIENTE_A1) {
                $ambiente = $row;
                break;
            }
        }
        $this->assertIsArray($ambiente);
        $zones = $ambiente['zones'] ?? null;
        $this->assertIsArray($zones);
        $this->assertCount(3, $zones);

        foreach ($zones as $zone) {
            $this->assertIsArray($zone);
            $this->assertSame(self::AMBIENTE_A1, (string) ($zone['ambiente_id'] ?? ''));
            $this->assertNotSame('', (string) ($zone['code'] ?? ''));
        }
    }

    public function testListAmbientesTreeActiveFilter(): void
    {
        $super = $this->authHeaderForSuperAdmin();
        $ambienteId = $this->createAmbienteLinkedToClinicA('Inactive-' . bin2hex(random_bytes(2)));

        $deactivate = $this->request(
            'DELETE',
            '/api/v1/ambientes/' . $ambienteId,
            null,
            $super
        );
        $this->assertSame(200, $deactivate['status']);

        $comp = $this->request(
            'POST',
            '/api/v1/zones',
            ['ambiente_id' => $ambienteId, 'code' => 'X-' . bin2hex(random_bytes(2))],
            $super
        );
        $this->assertSame(201, $comp['status']);

        // Vista admin: sin filtro incluye inactivos; STAFF siempre filtra is_active=TRUE.
        $admin = $this->authHeaderFor('admin@clinic-erp.com');
        $all = $this->request('GET', '/api/v1/ambientes/tree', null, $admin);
        $this->assertSame(200, $all['status']);
        $allIds = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($all['json']['data'] ?? [])
        );
        $this->assertContains($ambienteId, $allIds);

        $activeOnly = $this->request(
            'GET',
            '/api/v1/ambientes/tree?active=true',
            null,
            $admin
        );
        $this->assertSame(200, $activeOnly['status']);
        $activeIds = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($activeOnly['json']['data'] ?? [])
        );
        $this->assertNotContains($ambienteId, $activeIds);

        $invalid = $this->request(
            'GET',
            '/api/v1/ambientes/tree?active=maybe',
            null,
            $this->authHeaderFor('staff@clinic-erp.com')
        );
        $this->assertSame(422, $invalid['status']);
    }

    public function testListAmbientesTreeIsolationByClinic(): void
    {
        $res = $this->request('GET', '/api/v1/ambientes/tree', null, $this->authHeaderFor('admin2@clinic-erp.com'));
        $this->assertSame(200, $res['status']);

        $ids = array_map(
            static fn (array $row): string => (string) ($row['id'] ?? ''),
            (array) ($res['json']['data'] ?? [])
        );
        $this->assertNotContains(self::AMBIENTE_A1, $ids);
    }
}
