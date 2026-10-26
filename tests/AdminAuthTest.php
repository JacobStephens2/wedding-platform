<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\AdminArea;
use Wedding\AdminAuth;

final class AdminAuthTest extends TestCase
{
    private const PASSWORDS = [
        AdminArea::Admin->value    => 'admin-secret',
        AdminArea::Registry->value => 'registry-secret',
    ];

    public function testAdminPasswordUnlocksAdminForTheSession(): void
    {
        $session = [];

        $this->assertTrue((new AdminAuth($session, self::PASSWORDS))->login(AdminArea::Admin, 'admin-secret'));

        $this->assertTrue((new AdminAuth($session, self::PASSWORDS))->isAuthorized(AdminArea::Admin));
    }

    public function testUnconfiguredPasswordNeverMatchesEvenWhenBlank(): void
    {
        $session = [];
        $auth = new AdminAuth($session, [AdminArea::Admin->value => '', AdminArea::Registry->value => null]);

        $this->assertFalse($auth->login(AdminArea::Admin, ''));
        $this->assertFalse($auth->login(AdminArea::Registry, ''));
        $this->assertFalse($auth->login(AdminArea::RsvpCheck, ''));
        $this->assertFalse($auth->isAuthorized(AdminArea::Admin));
        $this->assertFalse($auth->isAuthorized(AdminArea::Registry));
    }

    public function testAdminPasswordAtAnAreaLoginGrantsEveryArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);

        $this->assertTrue($auth->login(AdminArea::Registry, 'admin-secret'));

        $this->assertTrue($auth->isAuthorized(AdminArea::Admin));
        $this->assertTrue($auth->isAuthorized(AdminArea::RsvpCheck));
        $this->assertTrue($auth->isAuthorized(AdminArea::RehearsalSeating));
    }

    public function testAreaPasswordGrantsOnlyItsOwnArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);

        $this->assertFalse($auth->login(AdminArea::RsvpCheck, 'registry-secret'));
        $this->assertTrue($auth->login(AdminArea::Registry, 'registry-secret'));

        $this->assertTrue($auth->isAuthorized(AdminArea::Registry));
        $this->assertFalse($auth->isAuthorized(AdminArea::Admin));
        $this->assertFalse($auth->isAuthorized(AdminArea::RsvpCheck));
    }

    public function testLogoutLocksEveryArea(): void
    {
        $session = [];
        $auth = new AdminAuth($session, self::PASSWORDS);
        $auth->login(AdminArea::Admin, 'admin-secret');
        $auth->login(AdminArea::Registry, 'registry-secret');

        $auth->logout();

        $fresh = new AdminAuth($session, self::PASSWORDS);
        $this->assertFalse($fresh->isAuthorized(AdminArea::Admin));
        $this->assertFalse($fresh->isAuthorized(AdminArea::Registry));
    }
}
