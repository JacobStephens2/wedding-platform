<?php
/**
 * Admin authentication for pages and API endpoints.
 *
 * The rules (which password unlocks which area) live in Wedding\AdminAuth;
 * this file wires it to $_SESSION, the .env passwords, and the HTTP request.
 */

use Wedding\AdminAuth;

require_once __DIR__ . '/admin_sample.php';

/** The request's AdminAuth, bound to $_SESSION (started on first use). */
function adminAuth(): AdminAuth
{
    static $auth = null;
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return $auth ??= new AdminAuth($_SESSION, [
        AdminAuth::ADMIN             => $_ENV['ADMIN_PASSWORD'] ?? null,
        AdminAuth::REGISTRY          => $_ENV['REGISTRY_ADMIN_PASSWORD'] ?? null,
        AdminAuth::RSVP_CHECK        => $_ENV['RSVP_CHECK_PASSWORD'] ?? null,
        AdminAuth::REHEARSAL_SEATING => $_ENV['REHEARSAL_SEATING_PASSWORD'] ?? null,
    ]);
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
function adminPageAuth(string $area, string $logoutRedirect): array
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$auth->isAuthorized($area)
        && !$auth->login($area, trim((string) $_POST['password']))) {
        $error = 'Incorrect password. Please try again.';
    }

    return ['authenticated' => $auth->isAuthorized($area), 'error' => $error];
}

function isAdminAuthenticated(): bool
{
    return adminAuth()->isAuthorized(AdminAuth::ADMIN);
}
