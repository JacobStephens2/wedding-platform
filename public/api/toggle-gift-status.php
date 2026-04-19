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
$authed = isAdminAuthenticated()
    || (isset($_SESSION['registry_admin_authenticated']) && $_SESSION['registry_admin_authenticated'] === true);
if (!$authed) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$source = $input['source'] ?? '';
$id = (int) ($input['id'] ?? 0);
$field = $input['field'] ?? '';

$allowedFields = ['received' => true, 'written' => true, 'sent' => true];
if (!isset($allowedFields[$field])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid field']);
    exit;
}
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}
$fundTables = [
    'housefund'     => 'house_fund_contributions',
    'honeymoonfund' => 'honeymoon_fund_contributions',
];
$allowedSources = ['registry' => true, 'offregistry' => true]
    + array_fill_keys(array_keys($fundTables), true);
if (!isset($allowedSources[$source])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid source']);
    exit;
}
if ($source !== 'registry' && $field === 'received') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'This source does not track a received flag']);
    exit;
}

try {
    $pdo = getDbConnection();

    if ($source === 'registry') {
        $col = $field === 'received' ? 'received' : 'thank_you_' . $field;
        $colAt = $col . '_at';
        $stmt = $pdo->prepare("SELECT $col FROM registry_items WHERE id = ? AND purchased = 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Registry item not found']);
            exit;
        }
        $newValue = $row[$col] ? 0 : 1;
        $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
        // Preserve updated_at so status toggles do not bump the row in
        // updated_at-based sort orders used elsewhere in the admin area.
        $upd = $pdo->prepare("UPDATE registry_items SET $col = ?, $colAt = ?, updated_at = updated_at WHERE id = ?");
        $upd->execute([$newValue, $stageAt, $id]);
    } elseif ($source === 'offregistry') {
        $col = 'thank_you_' . $field;
        $colAt = $col . '_at';
        $stmt = $pdo->prepare("SELECT $col FROM gifts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Gift not found']);
            exit;
        }
        $newValue = $row[$col] ? 0 : 1;
        $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
        $upd = $pdo->prepare("UPDATE gifts SET $col = ?, $colAt = ? WHERE id = ?");
        $upd->execute([$newValue, $stageAt, $id]);
    } else {
        $fundTable = $fundTables[$source];
        $col = 'thank_you_' . $field;
        $colAt = $col . '_at';
        $stmt = $pdo->prepare("SELECT $col FROM $fundTable WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Contribution not found']);
            exit;
        }
        $newValue = $row[$col] ? 0 : 1;
        $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
        $upd = $pdo->prepare("UPDATE $fundTable SET $col = ?, $colAt = ? WHERE id = ?");
        $upd->execute([$newValue, $stageAt, $id]);
    }

    // $stageAt is recorded in UTC (server runs UTC). Pre-format to
    // Eastern time so clients display the same zone as the PHP page.
    $atDisplay = null;
    if ($stageAt) {
        try {
            $dt = new DateTime($stageAt, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('America/New_York'));
            $atDisplay = $dt->format('M j, Y');
        } catch (Exception $e) {
            $atDisplay = null;
        }
    }

    echo json_encode([
        'success' => true,
        'source' => $source,
        'id' => $id,
        'field' => $field,
        'active' => (bool) $newValue,
        'at' => $stageAt,
        'at_display' => $atDisplay,
    ]);
} catch (Exception $e) {
    error_log('toggle-gift-status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
