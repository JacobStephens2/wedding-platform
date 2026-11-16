<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\CsrfGuard;

final class CsrfGuardTest extends TestCase
{
    public function testTokenIsIssuedOncePerSessionAndReused(): void
    {
        $session = [];
        $guard = new CsrfGuard($session);

        $first = $guard->token();
        $second = (new CsrfGuard($session))->token();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first);
        self::assertSame($first, $second);
    }

    public function testValidSameOriginRequestWithSessionTokenPasses(): void
    {
        $session = [];
        $token = (new CsrfGuard($session))->token();

        $server = [
            'HTTP_HOST' => '127.0.0.1:8080',
            'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_REFERER' => 'http://127.0.0.1:8080/admin-gifts',
        ];

        self::assertNull((new CsrfGuard($session))->verifyRequest($server, $token));
    }

    public function testDefaultPortsAndCaseNormalizeWhenComparingOrigins(): void
    {
        $session = [];
        $token = (new CsrfGuard($session))->token();

        self::assertNull((new CsrfGuard($session))->verifyRequest([
            'HTTPS' => 'on',
            'HTTP_HOST' => 'Example.COM:443',
            'HTTP_ORIGIN' => 'https://example.com',
        ], $token));

        self::assertNull((new CsrfGuard($session))->verifyRequest([
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'http://[::1]:8080/admin-gifts?filter=1',
        ], $token));

        // Behind a TLS-terminating proxy matching the configured BASE_URL host.
        self::assertNull((new CsrfGuard($session, 'https://wedding.example.com'))->verifyRequest([
            'HTTP_HOST' => 'wedding.example.com',
            'HTTP_ORIGIN' => 'https://wedding.example.com',
        ], $token));
    }

    public function testMissingInvalidOrUnissuedTokensAreRejected(): void
    {
        $emptySession = [];
        $validServer = [
            'HTTP_HOST' => '127.0.0.1:8080',
            'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ];
        self::assertSame('Invalid CSRF token', (new CsrfGuard($emptySession))->verifyRequest($validServer, ''));
        self::assertSame('Invalid CSRF token', (new CsrfGuard($emptySession))->verifyRequest($validServer, 'guessed'));
        self::assertSame([], $emptySession);

        $session = [];
        $token = (new CsrfGuard($session))->token();
        foreach ([null, '', 'wrong-token', substr($token, 0, -1) . ($token[-1] === '0' ? '1' : '0')] as $bad) {
            self::assertSame('Invalid CSRF token', (new CsrfGuard($session))->verifyRequest($validServer, $bad));
        }

        $otherSession = [];
        $otherToken = (new CsrfGuard($otherSession))->token();
        self::assertNotSame($token, $otherToken);
        self::assertSame('Invalid CSRF token', (new CsrfGuard($session))->verifyRequest($validServer, $otherToken));
    }

    public function testInvalidOrMissingOriginAndFetchMetadataAreRejected(): void
    {
        $session = [];
        $token = (new CsrfGuard($session))->token();
        $guard = new CsrfGuard($session, 'https://wedding.example.com');

        $invalidServers = [
            'missing all origin signals' => [
                'HTTP_HOST' => '127.0.0.1:8080',
            ],
            'cross-site sec-fetch-site' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
            ],
            'same-site sec-fetch-site' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_SEC_FETCH_SITE' => 'same-site',
            ],
            'none sec-fetch-site' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_SEC_FETCH_SITE' => 'none',
            ],
            'cross-origin Origin header' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'https://evil.example',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
            'null Origin header' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'null',
            ],
            'scheme mismatch Origin' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'https://127.0.0.1:8080',
            ],
            'port mismatch Origin' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'http://127.0.0.1:9090',
            ],
            'Origin with path' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'http://127.0.0.1:8080/admin-gifts',
            ],
            'cross-origin Referer' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
                'HTTP_REFERER' => 'https://evil.example/attack',
            ],
            'unmatched BASE_URL host does not allow foreign Origin' => [
                'HTTP_HOST' => '127.0.0.1:8080',
                'HTTP_ORIGIN' => 'https://wedding.example.com',
            ],
            'malformed HTTP_HOST' => [
                'HTTP_HOST' => '127.0.0.1:8080@evil.example',
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ],
            'missing HTTP_HOST with Origin' => [
                'HTTP_ORIGIN' => 'http://127.0.0.1:8080',
            ],
        ];

        foreach ($invalidServers as $label => $server) {
            self::assertSame(
                'Invalid request origin',
                $guard->verifyRequest($server, $token),
                $label,
            );
        }
    }
}
