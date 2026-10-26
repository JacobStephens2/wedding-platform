<?php

declare(strict_types=1);

namespace Wedding;

/**
 * A password-protected admin area. The value is the session flag that
 * remembers access; the names predate this enum and are kept so existing
 * sessions survive.
 */
enum AdminArea: string
{
    case Admin = 'admin_authenticated';
    case Registry = 'registry_admin_authenticated';
    case RsvpCheck = 'rsvp_check_authenticated';
    case RehearsalSeating = 'rehearsal_seating_authenticated';
}
