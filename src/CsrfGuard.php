<?php

declare(strict_types=1);

namespace Wedding;

/**
 * Session-bound anti-CSRF token and same-origin/same-site request validation.
 */
final class CsrfGuard
{
    private const SESSION_KEY = 'csrf_token';

    /** @var array<mixed> */
    private array $session;

    /**
     * @param array<mixed> $session the session store (normally $_SESSION), written through on token issuance
     * @param ?string $configuredBaseUrl optional trusted application base URL for TLS-terminating proxy origin matching
     */
    public function __construct(
        array &$session,
        private readonly ?string $configuredBaseUrl = null,
    ) {
        $this->session = &$session;
    }

    /** Return the current session-bound anti-CSRF token, issuing one if absent. */
    public function token(): string
    {
        $existing = $this->session[self::SESSION_KEY] ?? null;
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        $this->session[self::SESSION_KEY] = $token;
        return $token;
    }

    /**
     * Verify same-origin/same-site request metadata and the session-bound anti-CSRF token.
     *
     * Requires Sec-Fetch-Site: same-origin when present, validates Origin and Referer
     * against the request origin (plus configuredBaseUrl when it shares the request host),
     * and requires at least one of those same-origin request headers. Returns null when
     * valid or a client-safe 403 rejection reason otherwise.
     *
     * @param array<string,mixed> $server HTTP server parameters (normally $_SERVER)
     */
    public function verifyRequest(array $server, ?string $submittedToken): ?string
    {
        if (!$this->hasSameOriginMetadata($server)) {
            return 'Invalid request origin';
        }
        $sessionToken = $this->session[self::SESSION_KEY] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '' || !is_string($submittedToken) || $submittedToken === '') {
            return 'Invalid CSRF token';
        }
        return hash_equals($sessionToken, $submittedToken) ? null : 'Invalid CSRF token';
    }

    /** @param array<string,mixed> $server */
    private function hasSameOriginMetadata(array $server): bool
    {
        $expected = $this->expectedOrigins($server);
        if ($expected === null) {
            return false;
        }

        $hasSignal = false;
        if (array_key_exists('HTTP_SEC_FETCH_SITE', $server)) {
            $secFetchSite = $server['HTTP_SEC_FETCH_SITE'];
            if (!is_string($secFetchSite) || strtolower(trim($secFetchSite)) !== 'same-origin') {
                return false;
            }
            $hasSignal = true;
        }

        foreach (['HTTP_ORIGIN' => true, 'HTTP_REFERER' => false] as $header => $strictOriginOnly) {
            if (!array_key_exists($header, $server)) {
                continue;
            }
            $raw = $server[$header];
            if (!is_string($raw)) {
                return false;
            }
            $parsed = self::parseOrigin($raw, $strictOriginOnly);
            if ($parsed === null || !in_array($parsed['canonical'], $expected, true)) {
                return false;
            }
            $hasSignal = true;
        }

        return $hasSignal;
    }

    /**
     * @param array<string,mixed> $server
     * @return ?list<string> null when HTTP_HOST is present and malformed
     */
    private function expectedOrigins(array $server): ?array
    {
        if (!array_key_exists('HTTP_HOST', $server)) {
            return [];
        }
        $hostHeader = $server['HTTP_HOST'];
        if (!is_string($hostHeader) || $hostHeader === '') {
            return null;
        }
        $scheme = $this->requestScheme($server);
        $requestOrigin = self::parseOrigin($scheme . '://' . $hostHeader, true);
        if ($requestOrigin === null) {
            return null;
        }

        $origins = [$requestOrigin['canonical']];
        if (is_string($this->configuredBaseUrl) && $this->configuredBaseUrl !== '') {
            $base = self::parseOrigin($this->configuredBaseUrl, false);
            $portsCompatible = $base !== null
                && $base['host'] === $requestOrigin['host']
                && (($requestOrigin['explicit_port'] === null && $base['explicit_port'] === null)
                    || $requestOrigin['explicit_port'] === $base['explicit_port']);
            if ($portsCompatible && !in_array($base['canonical'], $origins, true)) {
                $origins[] = $base['canonical'];
            }
        }
        return $origins;
    }

    /** @param array<string,mixed> $server */
    private function requestScheme(array $server): string
    {
        $https = $server['HTTPS'] ?? null;
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return 'https';
        }
        $scheme = $server['REQUEST_SCHEME'] ?? null;
        if (is_string($scheme) && strtolower($scheme) === 'https') {
            return 'https';
        }
        $port = $server['SERVER_PORT'] ?? null;
        if ((is_string($port) || is_int($port)) && (string) $port === '443') {
            return 'https';
        }
        return 'http';
    }

    /**
     * @return ?array{host:string,explicit_port:?int,canonical:string}
     */
    private static function parseOrigin(string $raw, bool $strictOriginOnly): ?array
    {
        if ($raw === '' || preg_match('/\s/', $raw) === 1 || str_contains($raw, '\\')) {
            return null;
        }
        $parts = parse_url($raw);
        if (!is_array($parts)) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ($strictOriginOnly && (isset($parts['path']) || isset($parts['query']) || isset($parts['fragment']))) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $explicitPort = $parts['port'] ?? null;
        $effectivePort = $explicitPort ?? ($scheme === 'https' ? 443 : 80);

        return [
            'host' => $host,
            'explicit_port' => $explicitPort,
            'canonical' => $scheme . '://' . $host . ':' . $effectivePort,
        ];
    }
}
