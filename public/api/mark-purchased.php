<?php
require_once __DIR__ . '/../../private/config.php';
require_once __DIR__ . '/../../private/db.php';
require_once __DIR__ . '/../../private/email_handler.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$itemId = $input['item_id'] ?? null;
$purchaserName = trim($input['purchaser_name'] ?? '');

if (!$itemId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Item ID is required']);
    exit;
}

try {
    $pdo = getDbConnection();
    
    // Check if item exists
    $stmt = $pdo->prepare("SELECT id, purchased FROM registry_items WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Item not found']);
        exit;
    }
    
    // Toggle purchased status
    $newPurchasedStatus = !$item['purchased'];
    $stmt = $pdo->prepare("
        UPDATE registry_items 
        SET purchased = ?, 
            purchased_by = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $newPurchasedStatus ? 1 : 0,
        $newPurchasedStatus && $purchaserName ? $purchaserName : null,
        $itemId
    ]);
    
    // Send notification email when an item is marked as purchased
    if ($newPurchasedStatus) {
        $titleStmt = $pdo->prepare("SELECT title, price FROM registry_items WHERE id = ?");
        $titleStmt->execute([$itemId]);
        $itemInfo = $titleStmt->fetch();
        $itemTitle = $itemInfo['title'] ?? 'Unknown item';
        $itemPrice = $itemInfo['price'] ? '$' . number_format($itemInfo['price'], 2) : '';

        $subject = "Registry Item Purchased: $itemTitle";
        $body = "A registry item has been purchased!\n\n";
        $body .= "Item: $itemTitle\n";
        if ($itemPrice) {
            $body .= "Price: $itemPrice\n";
        }
        $body .= "\n— Wedding Website";

        $recipients = $_ENV['REGISTRY_PURCHASE_NOTIFY'] ?? '';
        foreach (array_filter(array_map('trim', explode(',', $recipients))) as $email) {
            sendEmail($email, $subject, $body);
        }
    }

    echo json_encode([
        'success' => true,
        'purchased' => $newPurchasedStatus,
        'message' => $newPurchasedStatus ? 'Item marked as purchased' : 'Item marked as available'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}


