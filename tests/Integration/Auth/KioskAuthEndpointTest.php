<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use Tests\Integration\Support\BaseApiTestCase;

final class KioskAuthEndpointTest extends BaseApiTestCase
{
    private const CLINIC_ID = '11111111-1111-1111-1111-111111111111';
    private const STAFF_USER_ID = '44444444-4444-4444-4444-444444444444';
    private const TECH_USER_ID = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        self::clearAuthAttemptCounters(self::STAFF_USER_ID, self::TECH_USER_ID);
    }

    public function testListVisibleClinics(): void
    {
        $res = $this->request('GET', '/api/v1/auth/clinics');
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['json']['data'] ?? null);
        $this->assertNotEmpty($res['json']['data']);
    }

    public function testClinicLoginAndStaffFlow(): void
    {
        $clinicLogin = $this->request('POST', '/api/v1/auth/clinic/login', [
            'clinic_id' => self::CLINIC_ID,
            'password' => 'clinic123',
        ]);
        $this->assertSame(200, $clinicLogin['status']);
        $clinicToken = $clinicLogin['json']['data']['clinic_access_token'] ?? null;
        $this->assertIsString($clinicToken);

        $staff = $this->request('GET', '/api/v1/auth/staff', null, [
            'Authorization' => 'Bearer ' . $clinicToken,
        ]);
        $this->assertSame(200, $staff['status']);
        $this->assertIsArray($staff['json']['data'] ?? null);

        $pinLogin = $this->request('POST', '/api/v1/auth/login/pin', [
            'user_id' => self::STAFF_USER_ID,
            'pin' => '1234',
        ], ['Authorization' => 'Bearer ' . $clinicToken]);
        $this->assertSame(200, $pinLogin['status']);
        $this->assertArrayHasKey('access_token', $pinLogin['json']['data'] ?? []);
    }

    public function testPinLoginRejectsNonFourDigitPin(): void
    {
        $clinicToken = $this->clinicToken();

        $res = $this->request('POST', '/api/v1/auth/login/pin', [
            'user_id' => self::STAFF_USER_ID,
            'pin' => '12345',
        ], ['Authorization' => 'Bearer ' . $clinicToken]);

        $this->assertSame(422, $res['status']);
    }

    public function testPinLockFallbackAfterThreeFailures(): void
    {
        $clinicToken = $this->clinicToken();

        for ($i = 0; $i < 2; $i++) {
            $fail = $this->request('POST', '/api/v1/auth/login/pin', [
                'user_id' => self::TECH_USER_ID,
                'pin' => '0000',
            ], ['Authorization' => 'Bearer ' . $clinicToken]);
            $this->assertSame(401, $fail['status'], 'Expected failed attempt ' . ($i + 1));
        }

        $locked = $this->request('POST', '/api/v1/auth/login/pin', [
            'user_id' => self::TECH_USER_ID,
            'pin' => '0000',
        ], ['Authorization' => 'Bearer ' . $clinicToken]);
        $this->assertSame(423, $locked['status']);
        $this->assertSame('classic_login', $locked['json']['meta']['fallback'] ?? null);
    }

    public function testClassicLoginWithClinicSession(): void
    {
        $clinicToken = $this->clinicToken();
        $login = $this->request('POST', '/api/v1/auth/login', [
            'email' => 'staff@clinic-erp.com',
            'password' => 'admin123',
        ], ['Authorization' => 'Bearer ' . $clinicToken]);

        $this->assertSame(200, $login['status']);
        $this->assertArrayHasKey('access_token', $login['json']['data'] ?? []);
    }

    private function clinicToken(): string
    {
        $res = $this->request('POST', '/api/v1/auth/clinic/login', [
            'clinic_id' => self::CLINIC_ID,
            'password' => 'clinic123',
        ]);
        $this->assertSame(200, $res['status']);

        return (string) $res['json']['data']['clinic_access_token'];
    }
}
