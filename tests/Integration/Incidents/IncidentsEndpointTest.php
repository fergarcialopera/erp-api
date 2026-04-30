<?php

declare(strict_types=1);

namespace Tests\Integration\Incidents;

use Tests\Integration\Support\BaseApiTestCase;

final class IncidentsEndpointTest extends BaseApiTestCase
{
    public function testCreateIncidentWithoutTokenReturns401(): void
    {
        $res = $this->request('POST', '/api/v1/incidents', [
            'title' => 'x',
            'description' => 'y',
            'severity' => 'LOW',
            'source' => 'ERP',
        ]);
        $this->assertSame(401, $res['status']);
    }

    public function testCreateIncidentRequiresTechnicianOrAdmin(): void
    {
        $payload = ['title' => 'Inc', 'description' => 'Desc', 'severity' => 'HIGH', 'source' => 'LOCKER'];

        $staff = $this->request('POST', '/api/v1/incidents', $payload, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $tech = $this->request('POST', '/api/v1/incidents', $payload, $this->authHeaderFor('tech@clinic.local'));
        $this->assertSame(201, $tech['status']);
    }

    public function testCreateIncidentValidation(): void
    {
        $res = $this->request(
            'POST',
            '/api/v1/incidents',
            ['title' => '', 'description' => '', 'severity' => 'WRONG', 'source' => 'INVALID'],
            $this->authHeaderFor('admin@clinic.local')
        );
        $this->assertSame(422, $res['status']);
    }

    public function testListIncidentsRequiresTechnicianOrAdmin(): void
    {
        $staff = $this->request('GET', '/api/v1/incidents', null, $this->authHeaderFor('staff@clinic.local'));
        $this->assertSame(403, $staff['status']);

        $admin = $this->request('GET', '/api/v1/incidents', null, $this->authHeaderFor('admin@clinic.local'));
        $this->assertSame(200, $admin['status']);
    }

    public function testIncidentsAreIsolatedByClinic(): void
    {
        $title = 'Incident-' . bin2hex(random_bytes(3));
        $this->request(
            'POST',
            '/api/v1/incidents',
            ['title' => $title, 'description' => 'Tenant A only', 'severity' => 'LOW', 'source' => 'ERP'],
            $this->authHeaderFor('admin@clinic.local')
        );

        $listA = $this->request('GET', '/api/v1/incidents', null, $this->authHeaderFor('admin@clinic.local'));
        $listB = $this->request('GET', '/api/v1/incidents', null, $this->authHeaderFor('admin2@clinic.local'));

        $hasInA = false;
        $hasInB = false;
        foreach (($listA['json']['data'] ?? []) as $row) {
            if (($row['title'] ?? '') === $title) {
                $hasInA = true;
            }
        }
        foreach (($listB['json']['data'] ?? []) as $row) {
            if (($row['title'] ?? '') === $title) {
                $hasInB = true;
            }
        }

        $this->assertTrue($hasInA);
        $this->assertFalse($hasInB);
    }
}
