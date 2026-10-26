<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\AdminAuth;

final class AdminAuthTest extends TestCase
{
    private const PASSWORDS = [
        AdminAuth::ADMIN    => 'admin-secret',
        AdminAuth::REGISTRY => 'registry-secret',
    ];

    public function testAdminPasswordUnlocksAdminForTheSession(): void
    {
        $session = [];

        $this->assertTrue((new AdminAuth($session, self::PASSWORDS))->login(AdminAuth::ADMIN, 'admin-secret'));

        $this->assertTrue((new AdminAuth($session, self::PASSWORDS))->isAuthorized(AdminAuth::ADMIN));
    }

    public function testUnconfiguredPasswordNeverMatchesEvenWhenBlank(): void
    {
        $session = [];
        $auth = new AdminAuth($session, [AdminAuth::ADMIN => '', AdminAuth::REGISTRY => null]);

        $this->assertFalse($auth->login(AdminAuth::ADMIN, ''));
        $this->assertFalse($auth->login(AdminAuth::REGISTRY, ''));
        $this->assertFalse($auth->login(AdminAuth::RSVP_CHECK, ''));
        $this->assertFalse($auth->isAuthorized(AdminAuth::ADMIN));
        $this->assertFalse($auth->isAuthorized(AdminAuth::REGISTRY));
    }

    public function testAdminPasswordAtAnAreaLoginGrantsEveryArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);

        $this->assertTrue($auth->login(AdminAuth::REGISTRY, 'admin-secret'));

        $this->assertTrue($auth->isAuthorized(AdminAuth::ADMIN));
        $this->assertTrue($auth->isAuthorized(AdminAuth::RSVP_CHECK));
        $this->assertTrue($auth->isAuthorized(AdminAuth::REHEARSAL_SEATING));
    }

    public function testAreaPasswordGrantsOnlyItsOwnArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);

        $this->assertFalse($auth->login(AdminAuth::RSVP_CHECK, 'registry-secret'));
        $this->assertTrue($auth->login(AdminAuth::REGISTRY, 'registry-secret'));

        $this->assertTrue($auth->isAuthorized(AdminAuth::REGISTRY));
        $this->assertFalse($auth->isAuthorized(AdminAuth::ADMIN));
        $this->assertFalse($auth->isAuthorized(AdminAuth::RSVP_CHECK));
    }

    public function testLogoutLocksEveryArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);
        $auth->login(AdminAuth::ADMIN, 'admin-secret');
        $auth->login(AdminAuth::REGISTRY, 'registry-secret');

        $auth->logout();

        $fresh = new AdminAuth($session, self::PASSWORDS);
        $this->assertFalse($fresh->isAuthorized(AdminAuth::ADMIN));
        $this->assertFalse($fresh->isAuthorized(AdminAuth::REGISTRY));
    }
}
