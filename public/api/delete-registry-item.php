<?php
/**
 * Delete a registry item from the admin registry page so the UI can
 * remove the card in place without a full page reload + scroll reset.
 *
 * POST /api/delete-registry-item
 * Body (JSON): { "item_id": 123 }
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
$authed = isAdminAuthenticated()
    || (isset($_SESSION['registry_admin_authenticated']) && $_SESSION['registry_admin_authenticated'] === true);
if (!$authed) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$itemId = (int) ($input['item_id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid item_id']);
    exit;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("DELETE FROM registry_items WHERE id = ?");
    $stmt->execute([$itemId]);
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    echo json_encode(['success' => true, 'item_id' => $itemId]);
} catch (Exception $e) {
    error_log('delete-registry-item error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
