<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use Tests\Integration\Support\BaseApiTestCase;

final class AuditEndpointTest extends BaseApiTestCase
{
    public function test_staff_cannot_list_audit_logs(): void
    {
        $response = $this->request('GET', '/api/v1/audit/logs', null, $this->authHeaderFor('staff@clinic-erp.com'));

        $this->assertSame(403, $response['status']);
    }

    public function test_admin_lists_access_logs_for_own_clinic(): void
    {
        $login = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'admin@clinic-erp.com',
            'password' => 'admin123',
        ]);
        $this->assertSame(200, $login['status']);

        $response = $this->request('GET', '/api/v1/audit/logs', null, $this->authHeaderFor('admin@clinic-erp.com'));

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['json']);
        $this->assertArrayHasKey('data', $response['json']);
        $this->assertArrayHasKey('meta', $response['json']);
        $this->assertIsArray($response['json']['meta']);
        $this->assertArrayHasKey('page', $response['json']['meta']);
        $this->assertArrayHasKey('per_page', $response['json']['meta']);
        $this->assertArrayHasKey('total', $response['json']['meta']);
    }

    public function test_admin_lists_activity_after_product_visibility_change(): void
    {
        $product = $this->createProductVisibleInClinicA('Audit-Product');

        $response = $this->request(
            'GET',
            '/api/v1/audit/activity?entity=clinic-product&entity_id=' . $product['id'],
            null,
            $this->authHeaderFor('admin@clinic-erp.com'),
        );

        $this->assertSame(200, $response['status']);
        $this->assertIsArray($response['json']['data']);
        $this->assertNotEmpty($response['json']['data']);

        $first = $response['json']['data'][0];
        $this->assertSame('edit', $first['type']);
        $this->assertSame('clinic-product', $first['entity']);
        $this->assertSame($product['id'], $first['entity_id']);
    }

    public function test_super_admin_lists_global_activity_after_product_create(): void
    {
        $name = 'Audit-Super-Product-' . bin2hex(random_bytes(2));
        $created = $this->request('POST', '/api/v1/products', ['name' => $name], $this->authHeaderForSuperAdmin());
        $this->assertSame(201, $created['status']);
        $productId = (string) ($created['json']['data']['id'] ?? '');
        $this->assertNotSame('', $productId);

        $response = $this->request(
            'GET',
            '/api/v1/audit/activity?entity=product&entity_id=' . $productId,
            null,
            $this->authHeaderForSuperAdmin(),
        );

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['json']['data']);

        $detailId = (string) ($response['json']['data'][0]['id'] ?? '');
        $this->assertNotSame('', $detailId);

        $detail = $this->request(
            'GET',
            '/api/v1/audit/activity/' . $detailId,
            null,
            $this->authHeaderForSuperAdmin(),
        );
        $this->assertSame(200, $detail['status']);
        $this->assertIsArray($detail['json']['data']['data'] ?? null);
        $this->assertArrayHasKey('after', $detail['json']['data']['data']);
    }

    public function test_failed_login_creates_access_log_with_error(): void
    {
        $before = $this->request('GET', '/api/v1/audit/logs?event=email_login&success=false', null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $before['status']);
        $totalBefore = (int) ($before['json']['meta']['total'] ?? 0);

        $failed = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'admin@clinic-erp.com',
            'password' => 'wrong-password',
        ]);
        $this->assertSame(401, $failed['status']);

        $after = $this->request('GET', '/api/v1/audit/logs?event=email_login&success=false', null, $this->authHeaderForSuperAdmin());
        $this->assertSame(200, $after['status']);
        $this->assertGreaterThan($totalBefore, (int) ($after['json']['meta']['total'] ?? 0));

        $latest = $after['json']['data'][0] ?? null;
        $this->assertIsArray($latest);
        $this->assertFalse($latest['success']);
        $this->assertSame('invalid_credentials', $latest['error']);
    }
}
