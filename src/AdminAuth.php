<?php

declare(strict_types=1);

namespace Wedding;

/**
 * Password access to the admin areas, remembered in the session.
 *
 * Each area has its own password, and the admin password unlocks every area.
 */
final class AdminAuth
{
    public const ADMIN = 'admin';
    public const REGISTRY = 'registry';
    public const RSVP_CHECK = 'rsvp_check';
    public const REHEARSAL_SEATING = 'rehearsal_seating';

    /** Session flag per area. Names predate this class; kept so sessions survive. */
    private const SESSION_KEYS = [
        self::ADMIN             => 'admin_authenticated',
        self::REGISTRY          => 'registry_admin_authenticated',
        self::RSVP_CHECK        => 'rsvp_check_authenticated',
        self::REHEARSAL_SEATING => 'rehearsal_seating_authenticated',
    ];

    /** @var array<mixed> */
    private array $session;

    /**
     * @param array<mixed> $session the session store (normally $_SESSION), written through
     * @param array<string,?string> $passwords area => configured password
     */
    public function __construct(array &$session, private readonly array $passwords)
    {
        $this->session = &$session;
    }

    public function isAuthorized(string $area): bool
    {
        return $this->hasFlag(self::ADMIN) || $this->hasFlag($area);
    }

    /**
     * Try a password at an area's login form. The admin password grants full
     * admin; the area's own password grants just that area.
     */
    public function login(string $area, string $password): bool
    {
        foreach (array_unique([self::ADMIN, $area]) as $grant) {
            $configured = (string) ($this->passwords[$grant] ?? '');
            // An unset password locks the area; it never matches a blank submission.
            if ($configured !== '' && hash_equals($configured, $password)) {
                $this->session[self::SESSION_KEYS[$grant]] = true;
                return true;
            }
        }
        return false;
    }

    public function logout(): void
    {
        foreach (self::SESSION_KEYS as $key) {
            unset($this->session[$key]);
        }
    }

    private function hasFlag(string $area): bool
    {
        return ($this->session[self::SESSION_KEYS[$area]] ?? false) === true;
    }
}
