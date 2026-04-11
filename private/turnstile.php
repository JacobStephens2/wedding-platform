<?php
/**
 * Cloudflare Turnstile verification helper.
 */

/**
 * Verify a Cloudflare Turnstile token against the siteverify endpoint.
 * Returns true when verification succeeds, false otherwise.
 */
function verifyTurnstileToken(string $token, ?string $remoteIp = null): bool
{
    $secret = $_ENV['TURNSTILE_SECRET_KEY'] ?? '';
    if ($secret === '' || $token === '') {
        return false;
    }
    $payload = http_build_query(array_filter([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]));
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 6,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    if ($resp === false) {
        return false;
    }
    $data = json_decode($resp, true);
    return is_array($data) && !empty($data['success']);
}

/**
 * Is Turnstile currently enabled on this deployment?
 */
function turnstileEnabled(): bool
{
    return !empty($_ENV['TURNSTILE_SITE_KEY'] ?? '') && !empty($_ENV['TURNSTILE_SECRET_KEY'] ?? '');
}

/**
 * Client IP (Cloudflare-aware).
 */
function turnstileClientIp(): ?string
{
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
}
