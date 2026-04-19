<?php
/**
 * Update the monetary value of an off-registry gift. Used by the gift
 * manager page to edit a gift's value inline from the unified table.
 *
 * POST /api/update-gift-value
 * Body (JSON): { "gift_id": 123, "value": 200.00 | null | "" }
 * Response: { success: true, gift_id: 123, value: 200.00 | null, value_display: "$200.00" | "" }
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
$giftId = (int) ($input['gift_id'] ?? 0);
if ($giftId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid gift_id']);
    exit;
}

$raw = $input['value'] ?? null;
if ($raw === null || $raw === '') {
    $value = null;
} else {
    // Accept numbers or strings like "$1,234.56"
    $clean = preg_replace('/[^0-9.]/', '', (string) $raw);
    if ($clean === '' || !is_numeric($clean)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid value']);
        exit;
    }
    $value = (float) $clean;
    if ($value < 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Value cannot be negative']);
        exit;
    }
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id FROM gifts WHERE id = ?");
    $stmt->execute([$giftId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Gift not found']);
        exit;
    }
    // Preserve updated_at so a value edit doesn't jump the row in any
    // updated_at-based sort used elsewhere in the admin area.
    $upd = $pdo->prepare("UPDATE gifts SET value = ?, updated_at = updated_at WHERE id = ?");
    $upd->execute([$value, $giftId]);

    echo json_encode([
        'success' => true,
        'gift_id' => $giftId,
        'value' => $value,
        'value_display' => $value !== null ? '$' . number_format($value, 2) : '',
    ]);
} catch (Exception $e) {
    error_log('update-gift-value error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
