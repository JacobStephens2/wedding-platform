<?php
/**
 * Toggle a received/written/sent flag on a registry purchase, off-registry
 * gift, or fund contribution. Used by the gift manager page to flip status
 * without a full page reload.
 *
 * POST /api/toggle-gift-status
 * Body (JSON): { "source": "registry"|"offregistry"|"housefund"|"honeymoonfund", "id": 123, "field": "received"|"written"|"sent" }
 * Response: { success: true, active: bool, field: "received", id: 123, source: "registry" }
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

$input = json_decode(file_get_contents('php://input'), true) ?: [];
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
