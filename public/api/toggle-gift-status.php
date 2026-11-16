<?php
/**
 * Toggle a received/written/sent flag on a registry purchase, off-registry
 * gift, or fund contribution. Used by the gift manager page to flip status
 * without a full page reload.
 *
 * POST /api/toggle-gift-status
 * Headers: X-CSRF-Token (or JSON "csrf_token") + same-origin POST metadata
 * Body (JSON): { "source": "registry"|"offregistry"|"housefund"|"honeymoonfund", "id": 123, "field": "received"|"written"|"sent" }
 * Response: { success: true, source: "registry", id: 123, field: "received", active: bool, at: ?string, at_display: ?string }
 */

require_once __DIR__ . '/../../private/config.php';
require_once __DIR__ . '/../../private/db.php';
require_once __DIR__ . '/../../private/admin_auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$authed = adminAuth()->isAuthorized(\Wedding\AdminArea::Registry);
if (!$authed) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

$rawBody = file_get_contents('php://input');
$decoded = is_string($rawBody) ? json_decode($rawBody, true) : null;
$input = is_array($decoded) ? $decoded : [];

$submittedToken = array_key_exists('HTTP_X_CSRF_TOKEN', $_SERVER)
    ? (is_string($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '')
    : (is_string($input['csrf_token'] ?? null) ? $input['csrf_token'] : '');
$rejection = adminVerifyPost($submittedToken);
if ($rejection !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $rejection]);
    exit;
}

$source = is_string($input['source'] ?? null) ? $input['source'] : '';
$id = (int) ($input['id'] ?? 0);
$field = is_string($input['field'] ?? null) ? $input['field'] : '';

try {
    $status = new \Wedding\GiftStatus(static fn (): PDO => getDbConnection());
    echo json_encode(['success' => true] + $status->toggle($source, $id, $field));
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('toggle-gift-status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
