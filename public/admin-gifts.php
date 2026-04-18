<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
require_once __DIR__ . '/../private/admin_auth.php';
require_once __DIR__ . '/../private/admin_sample.php';

session_start();

$error = '';
$success = '';
$sampleMode = isAdminSampleMode();
$authenticated = $sampleMode;

// Check unified admin auth first
if (!$sampleMode && isAdminAuthenticated()) {
    $authenticated = true;
} elseif (!$sampleMode) {
    // Fallback to old auth system for backward compatibility
    if (isset($_SESSION['registry_admin_authenticated']) && $_SESSION['registry_admin_authenticated'] === true) {
        $authenticated = true;
    }
}

// Handle login
if (!$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !isset($_POST['description']) && !isset($_POST['gift_action'])) {
    $password = trim($_POST['password'] ?? '');

    // Try unified admin password first
    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';
    if ($password === $adminPassword) {
        $_SESSION['admin_authenticated'] = true;
        $authenticated = true;
    } else {
        // Fallback to old password
        $correctPassword = $_ENV['REGISTRY_ADMIN_PASSWORD'] ?? '';
        if ($password === $correctPassword) {
            $_SESSION['registry_admin_authenticated'] = true;
            $authenticated = true;
        } else {
            $error = 'Incorrect password. Please try again.';
        }
    }
}

// Handle logout
if (!$sampleMode && isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin-gifts');
    exit;
}

// Handle toggling thank-you status for a registry purchase
if (!$sampleMode && $authenticated && isset($_GET['toggle_registry_thanks']) && is_numeric($_GET['toggle_registry_thanks'])) {
    try {
        $pdo = getDbConnection();
        $id = (int) $_GET['toggle_registry_thanks'];
        $stmt = $pdo->prepare("SELECT thank_you_sent FROM registry_items WHERE id = ? AND purchased = 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newValue = $row['thank_you_sent'] ? 0 : 1;
            $sentAt = $newValue ? date('Y-m-d H:i:s') : null;
            $upd = $pdo->prepare("UPDATE registry_items SET thank_you_sent = ?, thank_you_sent_at = ? WHERE id = ?");
            $upd->execute([$newValue, $sentAt, $id]);
        }
        header('Location: /admin-gifts#registry-gifts');
        exit;
    } catch (Exception $e) {
        $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle toggling thank-you status for a manual gift
if (!$sampleMode && $authenticated && isset($_GET['toggle_gift_thanks']) && is_numeric($_GET['toggle_gift_thanks'])) {
    try {
        $pdo = getDbConnection();
        $id = (int) $_GET['toggle_gift_thanks'];
        $stmt = $pdo->prepare("SELECT thank_you_sent FROM gifts WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if ($row) {
            $newValue = $row['thank_you_sent'] ? 0 : 1;
            $sentAt = $newValue ? date('Y-m-d H:i:s') : null;
            $upd = $pdo->prepare("UPDATE gifts SET thank_you_sent = ?, thank_you_sent_at = ? WHERE id = ?");
            $upd->execute([$newValue, $sentAt, $id]);
        }
        header('Location: /admin-gifts#manual-gifts');
        exit;
    } catch (Exception $e) {
        $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle deleting a manual gift
if (!$sampleMode && $authenticated && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM gifts WHERE id = ?");
        $stmt->execute([(int) $_GET['delete']]);
        header('Location: /admin-gifts?deleted=1#manual-gifts');
        exit;
    } catch (Exception $e) {
        $error = 'Error deleting gift: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle adding/updating a manual gift
if (!$sampleMode && $authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['description'])) {
    try {
        $pdo = getDbConnection();
        $giftId = $_POST['gift_id'] ?? null;
        $description = trim($_POST['description'] ?? '');
        $purchaserName = trim($_POST['purchaser_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $receivedOn = trim($_POST['received_on'] ?? '');
        $thankYouSent = isset($_POST['thank_you_sent']) && $_POST['thank_you_sent'] === '1' ? 1 : 0;

        if ($description === '') {
            $error = 'A description is required.';
        } else {
            $receivedOnValue = $receivedOn !== '' ? $receivedOn : null;
            if ($giftId) {
                // Preserve thank_you_sent_at if already sent, otherwise set/clear based on new value
                $stmt = $pdo->prepare("SELECT thank_you_sent, thank_you_sent_at FROM gifts WHERE id = ?");
                $stmt->execute([(int) $giftId]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $sentAt = $existing['thank_you_sent_at'];
                    if ($thankYouSent && !$existing['thank_you_sent']) {
                        $sentAt = date('Y-m-d H:i:s');
                    } elseif (!$thankYouSent) {
                        $sentAt = null;
                    }
                    $upd = $pdo->prepare("
                        UPDATE gifts
                        SET description = ?, purchaser_name = ?, notes = ?, received_on = ?, thank_you_sent = ?, thank_you_sent_at = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $description,
                        $purchaserName !== '' ? $purchaserName : null,
                        $notes !== '' ? $notes : null,
                        $receivedOnValue,
                        $thankYouSent,
                        $sentAt,
                        (int) $giftId,
                    ]);
                    header('Location: /admin-gifts?updated=1#manual-gifts');
                    exit;
                }
            } else {
                $sentAt = $thankYouSent ? date('Y-m-d H:i:s') : null;
                $ins = $pdo->prepare("
                    INSERT INTO gifts (description, purchaser_name, notes, received_on, thank_you_sent, thank_you_sent_at)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $description,
                    $purchaserName !== '' ? $purchaserName : null,
                    $notes !== '' ? $notes : null,
                    $receivedOnValue,
                    $thankYouSent,
                    $sentAt,
                ]);
                header('Location: /admin-gifts?added=1#manual-gifts');
                exit;
            }
        }
    } catch (Exception $e) {
        $error = 'Error saving gift: ' . htmlspecialchars($e->getMessage());
    }
}

// Status flash from redirect
if (isset($_GET['added'])) {
    $success = 'Gift added successfully!';
} elseif (isset($_GET['updated'])) {
    $success = 'Gift updated successfully!';
} elseif (isset($_GET['deleted'])) {
    $success = 'Gift deleted.';
}

// Fetch gift for editing
$editGift = null;
if ($sampleMode && isset($_GET['edit'])) {
    foreach (getSampleGifts() as $sample) {
        if ((int) $sample['id'] === (int) $_GET['edit']) {
            $editGift = $sample;
            break;
        }
    }
} elseif ($authenticated && isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM gifts WHERE id = ?");
        $stmt->execute([(int) $_GET['edit']]);
        $editGift = $stmt->fetch() ?: null;
        if (!$editGift) {
            $error = 'Gift not found.';
        }
    } catch (Exception $e) {
        $error = 'Error loading gift: ' . htmlspecialchars($e->getMessage());
    }
}

// Fetch registry purchases and manual gifts
$registryPurchases = [];
$manualGifts = [];
if ($sampleMode) {
    foreach (getSampleRegistryItems() as $item) {
        if (!empty($item['purchased'])) {
            $registryPurchases[] = $item + [
                'purchase_message' => $item['purchase_message'] ?? null,
                'thank_you_sent' => $item['thank_you_sent'] ?? 0,
                'thank_you_sent_at' => $item['thank_you_sent_at'] ?? null,
                'updated_at' => $item['updated_at'] ?? $item['created_at'] ?? null,
            ];
        }
    }
    $manualGifts = getSampleGifts();
} elseif ($authenticated) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT id, title, price, purchased_by, purchase_message, thank_you_sent, thank_you_sent_at, updated_at
            FROM registry_items
            WHERE purchased = 1
            ORDER BY thank_you_sent ASC, updated_at DESC, id DESC
        ");
        $registryPurchases = $stmt->fetchAll();

        $stmt = $pdo->query("
            SELECT id, description, purchaser_name, notes, received_on, thank_you_sent, thank_you_sent_at, created_at
            FROM gifts
            ORDER BY thank_you_sent ASC, received_on DESC, created_at DESC
        ");
        $manualGifts = $stmt->fetchAll();
    } catch (Exception $e) {
        $error = 'Error loading gifts: ' . htmlspecialchars($e->getMessage());
    }
}

// Compute stats
$totalGifts = count($registryPurchases) + count($manualGifts);
$thanksSent = 0;
foreach ($registryPurchases as $r) { if (!empty($r['thank_you_sent'])) $thanksSent++; }
foreach ($manualGifts as $g) { if (!empty($g['thank_you_sent'])) $thanksSent++; }
$thanksPending = $totalGifts - $thanksSent;

// Time zones for display
$utcTz = new DateTimeZone('UTC');
$displayTz = new DateTimeZone(date_default_timezone_get() ?: 'America/New_York');

function formatGiftDate(?string $raw, DateTimeZone $utcTz, DateTimeZone $displayTz): string {
    if (!$raw) return '';
    try {
        $dt = new DateTime($raw, $utcTz);
        $dt->setTimezone($displayTz);
        return $dt->format('M j, Y g:i A');
    } catch (Exception $e) {
        return htmlspecialchars($raw);
    }
}

$page_title = "Manage Gifts - Jacob & Melissa";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <?php include __DIR__ . '/includes/theme_init.php'; ?>
    <?php renderAdminSampleModeAssets(); ?>
    <link rel="stylesheet" href="/css/style.css?v=<?php
        $cssPath = __DIR__ . '/../css/style.css';
        echo file_exists($cssPath) ? filemtime($cssPath) : time();
    ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400&family=Beloved+Script&family=Crimson+Text:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/admin_menu.php'; ?>
    <style>
        .admin-container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .intro {
            background-color: var(--color-light);
            border-left: 4px solid var(--color-green);
            padding: 1rem 1.25rem;
            border-radius: 6px;
            margin-bottom: 2rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            line-height: 1.5;
        }
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            background-color: var(--color-surface);
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--color-shadow);
        }
        .stat-card {
            text-align: center;
            padding: 0.5rem 1rem;
            border-right: 1px solid var(--color-border);
        }
        .stat-card:last-child { border-right: none; }
        @media (max-width: 600px) {
            .stat-card { border-right: none; border-bottom: 1px solid var(--color-border); }
            .stat-card:last-child { border-bottom: none; }
        }
        .stat-value {
            display: block;
            font-size: 2rem;
            font-weight: bold;
            color: var(--color-green);
        }
        .stat-label {
            display: block;
            color: var(--color-text-secondary);
            font-family: 'Crimson Text', serif;
            font-size: 1rem;
        }
        .form-container {
            background-color: var(--color-surface);
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--color-shadow);
            margin-bottom: 2rem;
        }
        .form-container h2 {
            color: var(--color-green);
            margin-bottom: 1rem;
        }
        .gifts-table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--color-surface);
        }
        .gifts-table th,
        .gifts-table td {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--color-border);
            vertical-align: top;
        }
        .gifts-table th {
            background-color: var(--color-green);
            color: white;
            font-family: 'Cinzel', serif;
            font-size: 0.95rem;
        }
        .gifts-table tr.thanked td {
            background-color: var(--color-light);
        }
        .gifts-table .gift-title { font-weight: bold; color: var(--color-dark); }
        .gifts-table .gift-message {
            margin-top: 0.35rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            color: var(--color-text-secondary);
            font-style: italic;
            white-space: pre-wrap;
        }
        .gifts-table .gift-notes {
            margin-top: 0.35rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            color: var(--color-text-secondary);
            white-space: pre-wrap;
        }
        .no-name { color: var(--color-text-muted); font-style: italic; }
        .actions-cell { white-space: nowrap; }
        .btn-small {
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-right: 0.25rem;
            transition: background-color 0.3s;
        }
        .btn-thanks-pending {
            background-color: var(--color-gold);
            color: white;
        }
        .btn-thanks-pending:hover { background-color: hsl(13 37% 55% / 1); }
        .btn-thanks-sent {
            background-color: var(--color-green);
            color: white;
        }
        .btn-thanks-sent:hover { background-color: #2d5016; }
        .btn-edit {
            background-color: var(--color-green);
            color: white;
        }
        .btn-delete {
            background-color: #dc3545;
            color: white;
        }
        .btn-delete:hover { background-color: #c82333; }
        .btn-secondary {
            background-color: #6c757d;
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            display: inline-block;
        }
        .btn-secondary:hover { background-color: #5a6268; color: white; }
        .section-title {
            color: var(--color-green);
            margin-top: 2.5rem;
            margin-bottom: 1rem;
            font-family: 'Cinzel', serif;
        }
        .badge-thanks {
            display: inline-block;
            padding: 0.2rem 0.65rem;
            border-radius: 12px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
            vertical-align: middle;
        }
        .badge-thanks.sent { background-color: var(--color-green); color: white; }
        .badge-thanks.pending { background-color: #f1c40f; color: #3b2b00; }
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
        }
        .form-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            margin-top: 1rem;
            flex-wrap: wrap;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.25rem;
        }
        .table-wrapper { overflow-x: auto; }
        .empty-state {
            padding: 1rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            color: var(--color-text-secondary);
        }
        .back-to-site { text-align: center; margin-bottom: 2rem; }
        .back-to-site a {
            color: var(--color-green);
            text-decoration: none;
        }
        .back-to-site a:hover {
            color: var(--color-gold);
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <main class="page-container">
        <div class="admin-container">
            <?php renderAdminSampleBanner('Gift Manager Sample Mode'); ?>
            <div class="back-to-site">
                <a href="/">← Back to Main Site</a>
            </div>

            <?php if (!$authenticated): ?>
                <div class="form-container">
                    <h1 class="page-title">Manage Gifts</h1>
                    <?php if ($error): ?>
                        <div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div>
                    <?php endif; ?>
                    <form method="POST" action="/admin-gifts">
                        <div class="form-group required">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required autofocus>
                        </div>
                        <button type="submit" class="btn">Login</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="admin-header">
                    <h1 class="page-title">Manage Gifts</h1>
                    <div>
                        <a href="<?php echo htmlspecialchars($sampleMode ? '/admin' : '/admin-gifts?logout=1'); ?>"<?php echo $sampleMode ? ' data-sample-ignore="true"' : ''; ?> class="btn btn-secondary"><?php echo $sampleMode ? 'Exit Sample Mode' : 'Logout'; ?></a>
                    </div>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><p><?php echo htmlspecialchars($success); ?></p></div>
                <?php endif; ?>

                <p class="intro">Track every gift the two of you receive and which thank-you cards have gone out. Registry purchases show up here automatically once a guest marks them as purchased. Use the form below to record gifts that weren't on the registry.</p>

                <div class="summary-stats">
                    <div class="stat-card">
                        <span class="stat-value"><?php echo (int) $totalGifts; ?></span>
                        <span class="stat-label">Total gifts</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo (int) $thanksSent; ?></span>
                        <span class="stat-label">Thank-you sent</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo (int) $thanksPending; ?></span>
                        <span class="stat-label">Thank-you pending</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo count($registryPurchases); ?></span>
                        <span class="stat-label">Registry purchases</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-value"><?php echo count($manualGifts); ?></span>
                        <span class="stat-label">Off-registry gifts</span>
                    </div>
                </div>

                <h2 class="section-title" id="registry-gifts">Registry Purchases</h2>
                <div class="form-container">
                    <?php if (empty($registryPurchases)): ?>
                        <p class="empty-state">No registry items have been marked as purchased yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="gifts-table">
                                <thead>
                                    <tr>
                                        <th>Thank-you</th>
                                        <th>Gift</th>
                                        <th>From</th>
                                        <th>Message</th>
                                        <th>Price</th>
                                        <th>Purchased</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registryPurchases as $r): ?>
                                        <tr class="<?php echo !empty($r['thank_you_sent']) ? 'thanked' : ''; ?>">
                                            <td>
                                                <?php if (!empty($r['thank_you_sent'])): ?>
                                                    <span class="badge-thanks sent">Sent</span>
                                                <?php else: ?>
                                                    <span class="badge-thanks pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gift-title"><?php echo htmlspecialchars($r['title']); ?></td>
                                            <td>
                                                <?php if (!empty(trim((string) ($r['purchased_by'] ?? '')))): ?>
                                                    <?php echo htmlspecialchars($r['purchased_by']); ?>
                                                <?php else: ?>
                                                    <span class="no-name">(no name provided)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($r['purchase_message'])): ?>
                                                    <div class="gift-message">&ldquo;<?php echo nl2br(htmlspecialchars($r['purchase_message'])); ?>&rdquo;</div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo !empty($r['price']) ? '$' . number_format((float) $r['price'], 2) : '—'; ?></td>
                                            <td><?php echo htmlspecialchars(formatGiftDate($r['updated_at'] ?? null, $utcTz, $displayTz)); ?></td>
                                            <td class="actions-cell">
                                                <a href="/admin-gifts?toggle_registry_thanks=<?php echo (int) $r['id']; ?>#registry-gifts" class="btn-small <?php echo !empty($r['thank_you_sent']) ? 'btn-thanks-sent' : 'btn-thanks-pending'; ?>">
                                                    <?php echo !empty($r['thank_you_sent']) ? 'Mark as Not Sent' : 'Mark Thank-You Sent'; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <h2 class="section-title" id="add-gift"><?php echo $editGift ? 'Edit Off-Registry Gift' : 'Add Off-Registry Gift'; ?></h2>
                <div class="form-container">
                    <form method="POST" action="/admin-gifts#add-gift">
                        <?php if ($editGift): ?>
                            <input type="hidden" name="gift_id" value="<?php echo (int) $editGift['id']; ?>">
                        <?php endif; ?>
                        <div class="form-row">
                            <div class="form-group required">
                                <label for="description">Gift Description</label>
                                <input type="text" id="description" name="description" required maxlength="255"
                                       value="<?php echo $editGift ? htmlspecialchars($editGift['description']) : ''; ?>"
                                       placeholder="e.g. Crystal vase">
                            </div>
                            <div class="form-group">
                                <label for="purchaser_name">From (optional)</label>
                                <input type="text" id="purchaser_name" name="purchaser_name" maxlength="255"
                                       value="<?php echo $editGift ? htmlspecialchars($editGift['purchaser_name'] ?? '') : ''; ?>"
                                       placeholder="Name of giver">
                            </div>
                            <div class="form-group">
                                <label for="received_on">Received On (optional)</label>
                                <input type="date" id="received_on" name="received_on"
                                       value="<?php echo $editGift ? htmlspecialchars($editGift['received_on'] ?? '') : ''; ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="notes">Notes (optional)</label>
                            <textarea id="notes" name="notes" rows="3" placeholder="Anything to remember when writing the thank-you card"><?php echo $editGift ? htmlspecialchars($editGift['notes'] ?? '') : ''; ?></textarea>
                        </div>
                        <div class="form-group">
                            <label class="checkbox-row">
                                <input type="checkbox" name="thank_you_sent" value="1" <?php echo ($editGift && !empty($editGift['thank_you_sent'])) ? 'checked' : ''; ?>>
                                Thank-you card sent
                            </label>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="btn"><?php echo $editGift ? 'Update Gift' : 'Add Gift'; ?></button>
                            <?php if ($editGift): ?>
                                <a href="/admin-gifts" class="btn-secondary">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <h2 class="section-title" id="manual-gifts">Off-Registry Gifts</h2>
                <div class="form-container">
                    <?php if (empty($manualGifts)): ?>
                        <p class="empty-state">No off-registry gifts recorded yet. Add one with the form above.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="gifts-table">
                                <thead>
                                    <tr>
                                        <th>Thank-you</th>
                                        <th>Gift</th>
                                        <th>From</th>
                                        <th>Notes</th>
                                        <th>Received</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($manualGifts as $g): ?>
                                        <tr class="<?php echo !empty($g['thank_you_sent']) ? 'thanked' : ''; ?>">
                                            <td>
                                                <?php if (!empty($g['thank_you_sent'])): ?>
                                                    <span class="badge-thanks sent">Sent</span>
                                                <?php else: ?>
                                                    <span class="badge-thanks pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gift-title"><?php echo htmlspecialchars($g['description']); ?></td>
                                            <td>
                                                <?php if (!empty($g['purchaser_name'])): ?>
                                                    <?php echo htmlspecialchars($g['purchaser_name']); ?>
                                                <?php else: ?>
                                                    <span class="no-name">(no name)</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($g['notes'])): ?>
                                                    <div class="gift-notes"><?php echo nl2br(htmlspecialchars($g['notes'])); ?></div>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo !empty($g['received_on']) ? htmlspecialchars(date('M j, Y', strtotime($g['received_on']))) : '—'; ?></td>
                                            <td class="actions-cell">
                                                <a href="/admin-gifts?toggle_gift_thanks=<?php echo (int) $g['id']; ?>#manual-gifts" class="btn-small <?php echo !empty($g['thank_you_sent']) ? 'btn-thanks-sent' : 'btn-thanks-pending'; ?>">
                                                    <?php echo !empty($g['thank_you_sent']) ? 'Mark as Not Sent' : 'Mark Sent'; ?>
                                                </a>
                                                <a href="/admin-gifts?edit=<?php echo (int) $g['id']; ?>#add-gift" class="btn-small btn-edit">Edit</a>
                                                <a href="/admin-gifts?delete=<?php echo (int) $g['id']; ?>#manual-gifts" class="btn-small btn-delete" onclick="return confirm('Delete this gift entry?');">Delete</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
