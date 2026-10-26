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
    /** @var array<mixed> */
    private array $session;

    /**
     * @param array<mixed> $session the session store (normally $_SESSION), written through
     * @param array<value-of<AdminArea>,?string> $passwords configured password per area, keyed by AdminArea value
     */
    public function __construct(array &$session, private readonly array $passwords)
    {
        $this->session = &$session;
    }

    public function isAuthorized(AdminArea $area): bool
    {
        return $this->hasFlag(AdminArea::Admin) || $this->hasFlag($area);
    }

    /**
     * Try a password at an area's login form. The admin password grants full
     * admin; the area's own password grants just that area.
     */
    public function login(AdminArea $area, string $password): bool
    {
        foreach (array_unique([AdminArea::Admin, $area], SORT_REGULAR) as $grant) {
            $configured = (string) ($this->passwords[$grant->value] ?? '');
            // An unset password locks the area; it never matches a blank submission.
            if ($configured !== '' && hash_equals($configured, $password)) {
                $this->session[$grant->value] = true;
                return true;
            }
        }
        return false;
    }

    public function logout(): void
    {
        foreach (AdminArea::cases() as $area) {
            unset($this->session[$area->value]);
        }
    }

    private function hasFlag(AdminArea $area): bool
    {
        return ($this->session[$area->value] ?? false) === true;
    }
}
