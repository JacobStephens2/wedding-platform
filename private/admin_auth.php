<?php
/**
 * Admin authentication for pages and API endpoints.
 *
 * The rules (which password unlocks which area) live in Wedding\AdminAuth;
 * this file wires it to $_SESSION, the .env passwords, and the HTTP request.
 */

use Wedding\AdminArea;
use Wedding\AdminAuth;

require_once __DIR__ . '/admin_sample.php';

/** The request's AdminAuth, bound to $_SESSION (started on first use). */
function adminAuth(): AdminAuth
{
    static $auth = null;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!$auth instanceof AdminAuth) {
        $env = static fn (string $key): ?string => is_string($_ENV[$key] ?? null) ? $_ENV[$key] : null;
        $auth = new AdminAuth($_SESSION, [
            AdminArea::Admin->value            => $env('ADMIN_PASSWORD'),
            AdminArea::Registry->value         => $env('REGISTRY_ADMIN_PASSWORD'),
            AdminArea::RsvpCheck->value        => $env('RSVP_CHECK_PASSWORD'),
            AdminArea::RehearsalSeating->value => $env('REHEARSAL_SEATING_PASSWORD'),
        ]);
    }
    return $auth;
}

/**
 * Gate an admin page to one area. Call it before any output.
 *
 * Handles the page's login form (any POST carrying a `password` field while
 * locked) and `?logout` (clears the session, redirects to $logoutRedirect).
 * Sample mode (?sample=1) is always authenticated and never logs in or out.
 *
 * @return array{authenticated:bool,error:string}
 */
function adminPageAuth(AdminArea $area, string $logoutRedirect): array
{
    if (isAdminSampleMode()) {
        return ['authenticated' => true, 'error' => ''];
    }

    $auth = adminAuth();

    if (isset($_GET['logout'])) {
        $auth->logout();
        session_destroy();
        header('Location: ' . $logoutRedirect);
        exit;
    }

    $error = '';
    $password = $_POST['password'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_string($password) && !$auth->isAuthorized($area)
        && !$auth->login($area, trim($password))) {
        $error = 'Incorrect password. Please try again.';
    }

    return ['authenticated' => $auth->isAuthorized($area), 'error' => $error];
}
