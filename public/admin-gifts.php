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

// Allowed thank-you stages to toggle
$allowedStages = ['written', 'sent'];

// Handle toggling thank-you written/sent status for a registry purchase
foreach ($allowedStages as $stage) {
    $param = 'toggle_registry_' . $stage;
    if (!$sampleMode && $authenticated && isset($_GET[$param]) && is_numeric($_GET[$param])) {
        try {
            $pdo = getDbConnection();
            $id = (int) $_GET[$param];
            $col = 'thank_you_' . $stage;
            $colAt = $col . '_at';
            $stmt = $pdo->prepare("SELECT $col FROM registry_items WHERE id = ? AND purchased = 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $newValue = $row[$col] ? 0 : 1;
                $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
                // Preserve updated_at so marking thank-you status doesn't jump the row
                // in any updated_at-based sort used by other admin screens.
                $upd = $pdo->prepare("UPDATE registry_items SET $col = ?, $colAt = ?, updated_at = updated_at WHERE id = ?");
                $upd->execute([$newValue, $stageAt, $id]);
            }
            header('Location: /admin-gifts#registry-gifts');
            exit;
        } catch (Exception $e) {
            $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Handle toggling thank-you written/sent status for a manual gift
foreach ($allowedStages as $stage) {
    $param = 'toggle_gift_' . $stage;
    if (!$sampleMode && $authenticated && isset($_GET[$param]) && is_numeric($_GET[$param])) {
        try {
            $pdo = getDbConnection();
            $id = (int) $_GET[$param];
            $col = 'thank_you_' . $stage;
            $colAt = $col . '_at';
            $stmt = $pdo->prepare("SELECT $col FROM gifts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $newValue = $row[$col] ? 0 : 1;
                $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
                $upd = $pdo->prepare("UPDATE gifts SET $col = ?, $colAt = ? WHERE id = ?");
                $upd->execute([$newValue, $stageAt, $id]);
            }
            header('Location: /admin-gifts#manual-gifts');
            exit;
        } catch (Exception $e) {
            $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
        }
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
        $thankYouWritten = isset($_POST['thank_you_written']) && $_POST['thank_you_written'] === '1' ? 1 : 0;
        $thankYouSent = isset($_POST['thank_you_sent']) && $_POST['thank_you_sent'] === '1' ? 1 : 0;

        if ($description === '') {
            $error = 'A description is required.';
        } else {
            $receivedOnValue = $receivedOn !== '' ? $receivedOn : null;
            if ($giftId) {
                $stmt = $pdo->prepare("SELECT thank_you_written, thank_you_written_at, thank_you_sent, thank_you_sent_at FROM gifts WHERE id = ?");
                $stmt->execute([(int) $giftId]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $writtenAt = $existing['thank_you_written_at'];
                    if ($thankYouWritten && !$existing['thank_you_written']) {
                        $writtenAt = date('Y-m-d H:i:s');
                    } elseif (!$thankYouWritten) {
                        $writtenAt = null;
                    }
                    $sentAt = $existing['thank_you_sent_at'];
                    if ($thankYouSent && !$existing['thank_you_sent']) {
                        $sentAt = date('Y-m-d H:i:s');
                    } elseif (!$thankYouSent) {
                        $sentAt = null;
                    }
                    $upd = $pdo->prepare("
                        UPDATE gifts
                        SET description = ?, purchaser_name = ?, notes = ?, received_on = ?,
                            thank_you_written = ?, thank_you_written_at = ?,
                            thank_you_sent = ?, thank_you_sent_at = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $description,
                        $purchaserName !== '' ? $purchaserName : null,
                        $notes !== '' ? $notes : null,
                        $receivedOnValue,
                        $thankYouWritten,
                        $writtenAt,
                        $thankYouSent,
                        $sentAt,
                        (int) $giftId,
                    ]);
                    header('Location: /admin-gifts?updated=1#manual-gifts');
                    exit;
                }
            } else {
                $writtenAt = $thankYouWritten ? date('Y-m-d H:i:s') : null;
                $sentAt = $thankYouSent ? date('Y-m-d H:i:s') : null;
                $ins = $pdo->prepare("
                    INSERT INTO gifts (description, purchaser_name, notes, received_on,
                        thank_you_written, thank_you_written_at, thank_you_sent, thank_you_sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $description,
                    $purchaserName !== '' ? $purchaserName : null,
                    $notes !== '' ? $notes : null,
                    $receivedOnValue,
                    $thankYouWritten,
                    $writtenAt,
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
                'thank_you_written' => $item['thank_you_written'] ?? 0,
                'thank_you_written_at' => $item['thank_you_written_at'] ?? null,
                'thank_you_sent' => $item['thank_you_sent'] ?? 0,
                'thank_you_sent_at' => $item['thank_you_sent_at'] ?? null,
                'updated_at' => $item['updated_at'] ?? $item['created_at'] ?? null,
            ];
        }
    }
    // Sort sample registry purchases by purchaser name (case-insensitive),
    // with entries that have no name sinking to the bottom.
    usort($registryPurchases, function ($a, $b) {
        $an = trim((string) ($a['purchased_by'] ?? ''));
        $bn = trim((string) ($b['purchased_by'] ?? ''));
        if ($an === '' && $bn !== '') return 1;
        if ($an !== '' && $bn === '') return -1;
        $cmp = strcasecmp($an, $bn);
        if ($cmp !== 0) return $cmp;
        return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    $manualGifts = getSampleGifts();
} elseif ($authenticated) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT id, title, price, purchased_by, purchase_message,
                   thank_you_written, thank_you_written_at,
                   thank_you_sent, thank_you_sent_at, updated_at
            FROM registry_items
            WHERE purchased = 1
            ORDER BY (purchased_by IS NULL OR purchased_by = '') ASC,
                     LOWER(purchased_by) ASC,
                     LOWER(title) ASC,
                     id ASC
        ");
        $registryPurchases = $stmt->fetchAll();

        $stmt = $pdo->query("
            SELECT id, description, purchaser_name, notes, received_on,
                   thank_you_written, thank_you_written_at,
                   thank_you_sent, thank_you_sent_at, created_at
            FROM gifts
            ORDER BY (thank_you_written AND thank_you_sent) ASC,
                     (purchaser_name IS NULL OR purchaser_name = '') ASC,
                     LOWER(purchaser_name) ASC,
                     created_at DESC
        ");
        $manualGifts = $stmt->fetchAll();
    } catch (Exception $e) {
        $error = 'Error loading gifts: ' . htmlspecialchars($e->getMessage());
    }
}

// Compute stats
function isGiftCompleted(array $g): bool {
    return !empty($g['thank_you_written']) && !empty($g['thank_you_sent']);
}
$totalGifts = count($registryPurchases) + count($manualGifts);
$thanksCompleted = 0;
$thanksWritten = 0;
$thanksSent = 0;
foreach ($registryPurchases as $r) {
    if (isGiftCompleted($r)) $thanksCompleted++;
    if (!empty($r['thank_you_written'])) $thanksWritten++;
    if (!empty($r['thank_you_sent'])) $thanksSent++;
}
foreach ($manualGifts as $g) {
    if (isGiftCompleted($g)) $thanksCompleted++;
    if (!empty($g['thank_you_written'])) $thanksWritten++;
    if (!empty($g['thank_you_sent'])) $thanksSent++;
}
$thanksPending = $totalGifts - $thanksCompleted;

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
            max-width: none;
            margin: 2rem auto;
            padding: 1.5rem 2rem 2rem;
        }
        .admin-inner {
            max-width: 1200px;
            margin: 0 auto;
        }
        .admin-full {
            max-width: none;
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
        .btn-thanks-written {
            background-color: #5b8def;
            color: white;
        }
        .btn-thanks-written:hover { background-color: #3f6fd4; }
        .btn-thanks-active {
            background-color: #6b7280;
            color: white;
        }
        .btn-thanks-active:hover { background-color: #4b5563; }
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
        .badge-thanks.completed { background-color: var(--color-green); color: white; }
        .badge-thanks.sent { background-color: #5b8def; color: white; }
        .badge-thanks.written { background-color: #b08cd6; color: white; }
        .badge-thanks.pending { background-color: #f1c40f; color: #3b2b00; }
        /* Inline-editable purchaser name input (mirrors admin-registry recent purchases) */
        .gift-name-input {
            width: 100%;
            min-width: 8rem;
            padding: 0.3rem 0.45rem;
            border: 1px solid transparent;
            border-radius: 4px;
            background-color: transparent;
            color: inherit;
            font: inherit;
            font-family: 'Crimson Text', serif;
            transition: border-color 0.2s, background-color 0.2s;
        }
        .gift-name-input:hover { border-color: var(--color-border); }
        .gift-name-input:focus {
            outline: none;
            border-color: var(--color-green);
            background-color: var(--color-surface);
            box-shadow: 0 0 0 2px rgba(127, 143, 101, 0.25);
        }
        .gift-name-input.gift-name-input-empty::placeholder {
            color: #b02a37;
            font-style: italic;
            opacity: 1;
        }
        .gift-name-input[readonly] { cursor: default; }
        .gift-name-status {
            display: inline-block;
            margin-left: 0.4rem;
            font-size: 0.8rem;
            font-family: 'Crimson Text', serif;
            color: var(--color-text-secondary);
            min-width: 3rem;
        }
        .gift-name-status.status-saving { color: var(--color-text-secondary); }
        .gift-name-status.status-saved { color: #2d5016; }
        .gift-name-status.status-error { color: #b02a37; }
        .gifts-table tr.noname { background-color: #fff6f6; }
        [data-theme="dark"] .gifts-table tr.noname { background-color: rgba(176, 42, 55, 0.15); }
        details.add-gift-panel {
            background-color: var(--color-surface);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--color-shadow);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        details.add-gift-panel summary {
            list-style: none;
            cursor: pointer;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background-color: var(--color-green);
            color: white;
            font-family: 'Cinzel', serif;
            font-size: 1.1rem;
        }
        details.add-gift-panel summary::-webkit-details-marker { display: none; }
        details.add-gift-panel summary::after {
            content: '+';
            font-size: 1.5rem;
            line-height: 1;
            transition: transform 0.2s;
        }
        details.add-gift-panel[open] summary::after { content: '–'; }
        details.add-gift-panel .add-gift-body {
            padding: 1.5rem;
        }
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
            <div class="admin-inner">
                <div class="back-to-site">
                    <a href="/">← Back to Main Site</a>
                </div>
            </div>

            <?php if (!$authenticated): ?>
                <div class="admin-inner">
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
                </div>
            <?php else: ?>
                <div class="admin-inner">
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

                    <p class="intro">Track every gift the two of you receive and the thank-you card workflow. Registry purchases show up here automatically once a guest marks them as purchased. A thank-you is <strong>completed</strong> when it has been both written and sent.</p>

                    <div class="summary-stats">
                        <div class="stat-card">
                            <span class="stat-value"><?php echo (int) $totalGifts; ?></span>
                            <span class="stat-label">Total gifts</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo (int) $thanksCompleted; ?></span>
                            <span class="stat-label">Thank-you completed</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo (int) $thanksWritten; ?></span>
                            <span class="stat-label">Written</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo (int) $thanksSent; ?></span>
                            <span class="stat-label">Sent</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-value"><?php echo (int) $thanksPending; ?></span>
                            <span class="stat-label">Outstanding</span>
                        </div>
                    </div>

                    <details class="add-gift-panel" id="add-gift" <?php echo $editGift ? 'open' : ''; ?>>
                        <summary><?php echo $editGift ? 'Edit Off-Registry Gift' : 'Add Off-Registry Gift'; ?></summary>
                        <div class="add-gift-body">
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
                                               value="<?php echo $editGift ? htmlspecialchars($editGift['received_on'] ?? '') : date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="notes">Notes (optional)</label>
                                    <textarea id="notes" name="notes" rows="3" placeholder="Anything to remember when writing the thank-you card"><?php echo $editGift ? htmlspecialchars($editGift['notes'] ?? '') : ''; ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="checkbox-row">
                                        <input type="checkbox" name="thank_you_written" value="1" <?php echo ($editGift && !empty($editGift['thank_you_written'])) ? 'checked' : ''; ?>>
                                        Thank-you card written
                                    </label>
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
                    </details>
                </div>

                <h2 class="section-title admin-inner" id="registry-gifts">Registry Purchases</h2>
                <div class="form-container admin-full">
                    <?php if (empty($registryPurchases)): ?>
                        <p class="empty-state">No registry items have been marked as purchased yet.</p>
                    <?php else: ?>
                        <div class="table-wrapper">
                            <table class="gifts-table">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>From</th>
                                        <th>Gift</th>
                                        <th>Message</th>
                                        <th>Price</th>
                                        <th>Purchased</th>
                                        <th>Thank-you</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registryPurchases as $r):
                                        $written = !empty($r['thank_you_written']);
                                        $sent = !empty($r['thank_you_sent']);
                                        $completed = $written && $sent;
                                        $nameRaw = trim((string) ($r['purchased_by'] ?? ''));
                                        $noName = $nameRaw === '';
                                    ?>
                                        <tr class="<?php echo $completed ? 'thanked' : ($noName ? 'noname' : ''); ?>">
                                            <td>
                                                <?php if ($completed): ?>
                                                    <span class="badge-thanks completed">Completed</span>
                                                <?php elseif ($sent): ?>
                                                    <span class="badge-thanks sent">Sent</span>
                                                <?php elseif ($written): ?>
                                                    <span class="badge-thanks written">Written</span>
                                                <?php else: ?>
                                                    <span class="badge-thanks pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <input type="text"
                                                       class="gift-name-input<?php echo $noName ? ' gift-name-input-empty' : ''; ?>"
                                                       value="<?php echo htmlspecialchars($nameRaw); ?>"
                                                       placeholder="(add name)"
                                                       maxlength="255"
                                                       data-item-id="<?php echo (int) $r['id']; ?>"
                                                       data-original="<?php echo htmlspecialchars($nameRaw); ?>"
                                                       <?php echo $sampleMode ? 'readonly' : ''; ?>>
                                                <span class="gift-name-status" aria-live="polite"></span>
                                            </td>
                                            <td class="gift-title"><?php echo htmlspecialchars($r['title']); ?></td>
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
                                                <a href="/admin-gifts?toggle_registry_written=<?php echo (int) $r['id']; ?>#registry-gifts" class="btn-small <?php echo $written ? 'btn-thanks-active' : 'btn-thanks-written'; ?>">
                                                    <?php echo $written ? '✓ Written' : 'Mark Written'; ?>
                                                </a>
                                                <a href="/admin-gifts?toggle_registry_sent=<?php echo (int) $r['id']; ?>#registry-gifts" class="btn-small <?php echo $sent ? 'btn-thanks-active' : 'btn-thanks-sent'; ?>">
                                                    <?php echo $sent ? '✓ Sent' : 'Mark Sent'; ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="admin-inner">
                    <h2 class="section-title" id="manual-gifts">Off-Registry Gifts</h2>
                    <div class="form-container">
                        <?php if (empty($manualGifts)): ?>
                            <p class="empty-state">No off-registry gifts recorded yet. Add one with the form above.</p>
                        <?php else: ?>
                            <div class="table-wrapper">
                                <table class="gifts-table">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th>Gift</th>
                                            <th>From</th>
                                            <th>Notes</th>
                                            <th>Received</th>
                                            <th>Thank-you</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($manualGifts as $g):
                                            $written = !empty($g['thank_you_written']);
                                            $sent = !empty($g['thank_you_sent']);
                                            $completed = $written && $sent;
                                        ?>
                                            <tr class="<?php echo $completed ? 'thanked' : ''; ?>">
                                                <td>
                                                    <?php if ($completed): ?>
                                                        <span class="badge-thanks completed">Completed</span>
                                                    <?php elseif ($sent): ?>
                                                        <span class="badge-thanks sent">Sent</span>
                                                    <?php elseif ($written): ?>
                                                        <span class="badge-thanks written">Written</span>
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
                                                    <a href="/admin-gifts?toggle_gift_written=<?php echo (int) $g['id']; ?>#manual-gifts" class="btn-small <?php echo $written ? 'btn-thanks-active' : 'btn-thanks-written'; ?>">
                                                        <?php echo $written ? '✓ Written' : 'Mark Written'; ?>
                                                    </a>
                                                    <a href="/admin-gifts?toggle_gift_sent=<?php echo (int) $g['id']; ?>#manual-gifts" class="btn-small <?php echo $sent ? 'btn-thanks-active' : 'btn-thanks-sent'; ?>">
                                                        <?php echo $sent ? '✓ Sent' : 'Mark Sent'; ?>
                                                    </a>
                                                </td>
                                                <td class="actions-cell">
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
                </div>
            <?php endif; ?>
        </div>
    </main>
    <?php if ($authenticated && !$sampleMode): ?>
    <script>
        // Inline edit of purchased_by name on registry purchases table.
        // Reuses /api/update-purchaser.php.
        (function() {
            const inputs = document.querySelectorAll('.gift-name-input');
            inputs.forEach(function(input) {
                if (input.readOnly) return;
                const status = input.parentElement.querySelector('.gift-name-status');

                function setStatus(text, cls) {
                    if (!status) return;
                    status.textContent = text;
                    status.className = 'gift-name-status' + (cls ? ' ' + cls : '');
                }

                function saveIfChanged() {
                    const current = input.value.trim();
                    const original = (input.dataset.original || '').trim();
                    if (current === original) {
                        setStatus('', '');
                        return;
                    }
                    setStatus('Saving…', 'status-saving');
                    fetch('/api/update-purchaser.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            item_id: parseInt(input.dataset.itemId, 10),
                            purchaser_name: current
                        })
                    })
                    .then(function(resp) {
                        return resp.json().then(function(data) { return { ok: resp.ok, data: data }; });
                    })
                    .then(function(result) {
                        if (result.ok && result.data && result.data.success) {
                            input.dataset.original = current;
                            input.classList.toggle('gift-name-input-empty', current === '');
                            const row = input.closest('tr');
                            if (row) row.classList.toggle('noname', current === '');
                            setStatus('Saved ✓', 'status-saved');
                            setTimeout(function() { setStatus('', ''); }, 2000);
                        } else {
                            const msg = (result.data && result.data.error) ? result.data.error : 'Error';
                            setStatus(msg, 'status-error');
                        }
                    })
                    .catch(function() {
                        setStatus('Network error', 'status-error');
                    });
                }

                input.addEventListener('blur', saveIfChanged);
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        input.blur();
                    } else if (e.key === 'Escape') {
                        input.value = input.dataset.original || '';
                        input.blur();
                        setStatus('', '');
                    }
                });
            });
        })();
    </script>
    <?php endif; ?>
</body>
</html>
