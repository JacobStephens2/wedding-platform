<?php
/**
 * Unmark a registry item as purchased from the admin gift manager.
 * Matches the existing admin-registry toggle behavior: clears the
 * purchaser name and message but preserves the thank-you and
 * received tracking fields in case the action was accidental.
 *
 * POST /api/unmark-registry-purchase
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
    $stmt = $pdo->prepare("SELECT purchased FROM registry_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    if (empty($row['purchased'])) {
        // Already unpurchased — succeed idempotently
        echo json_encode(['success' => true, 'item_id' => $itemId, 'purchased' => false]);
        exit;
    }
    $upd = $pdo->prepare("
        UPDATE registry_items
        SET purchased = 0,
            purchased_by = NULL,
            purchase_message = NULL
        WHERE id = ?
    ");
    $upd->execute([$itemId]);
    echo json_encode(['success' => true, 'item_id' => $itemId, 'purchased' => false]);
} catch (Exception $e) {
    error_log('unmark-registry-purchase error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
