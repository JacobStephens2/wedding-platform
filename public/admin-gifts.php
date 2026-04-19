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

// Column names on registry_items that the admin can toggle from this page.
// Keyed by the ?toggle_registry_<key> query param.
$registryToggleColumns = [
    'received' => ['col' => 'received',          'col_at' => 'received_at'],
    'written'  => ['col' => 'thank_you_written', 'col_at' => 'thank_you_written_at'],
    'sent'     => ['col' => 'thank_you_sent',    'col_at' => 'thank_you_sent_at'],
];

// Handle toggling received / thank-you written / thank-you sent on a registry purchase
foreach ($registryToggleColumns as $key => $cols) {
    $param = 'toggle_registry_' . $key;
    if (!$sampleMode && $authenticated && isset($_GET[$param]) && is_numeric($_GET[$param])) {
        try {
            $pdo = getDbConnection();
            $id = (int) $_GET[$param];
            $col = $cols['col'];
            $colAt = $cols['col_at'];
            $stmt = $pdo->prepare("SELECT $col FROM registry_items WHERE id = ? AND purchased = 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row) {
                $newValue = $row[$col] ? 0 : 1;
                $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
                // Preserve updated_at so marking these fields doesn't jump the row
                // in any updated_at-based sort used by other admin screens.
                $upd = $pdo->prepare("UPDATE registry_items SET $col = ?, $colAt = ?, updated_at = updated_at WHERE id = ?");
                $upd->execute([$newValue, $stageAt, $id]);
            }
            header('Location: /admin-gifts#gifts-table');
            exit;
        } catch (Exception $e) {
            $error = 'Error updating status: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Allowed thank-you stages (used below for gift table toggles)
$allowedStages = ['written', 'sent'];

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
            header('Location: /admin-gifts#gifts-table');
            exit;
        } catch (Exception $e) {
            $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Handle toggling thank-you written/sent status for a fund contribution
$fundTables = [
    'housefund'     => 'house_fund_contributions',
    'honeymoonfund' => 'honeymoon_fund_contributions',
];
foreach ($fundTables as $fundKey => $fundTable) {
    foreach ($allowedStages as $stage) {
        $param = 'toggle_' . $fundKey . '_' . $stage;
        if (!$sampleMode && $authenticated && isset($_GET[$param]) && is_numeric($_GET[$param])) {
            try {
                $pdo = getDbConnection();
                $id = (int) $_GET[$param];
                $col = 'thank_you_' . $stage;
                $colAt = $col . '_at';
                $stmt = $pdo->prepare("SELECT $col FROM $fundTable WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row) {
                    $newValue = $row[$col] ? 0 : 1;
                    $stageAt = $newValue ? date('Y-m-d H:i:s') : null;
                    $upd = $pdo->prepare("UPDATE $fundTable SET $col = ?, $colAt = ? WHERE id = ?");
                    $upd->execute([$newValue, $stageAt, $id]);
                }
                header('Location: /admin-gifts#gifts-table');
                exit;
            } catch (Exception $e) {
                $error = 'Error updating thank-you status: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Handle saving an admin note on a registry purchase
if (!$sampleMode && $authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_registry_note'])) {
    try {
        $pdo = getDbConnection();
        $noteId = (int) ($_POST['registry_item_id'] ?? 0);
        $note = trim((string) ($_POST['admin_note'] ?? ''));
        if (mb_strlen($note) > 4000) {
            $note = mb_substr($note, 0, 4000);
        }
        if ($noteId > 0) {
            // Preserve updated_at so note edits don't jump the row in any
            // updated_at-ordered admin view.
            $stmt = $pdo->prepare("UPDATE registry_items SET admin_note = ?, updated_at = updated_at WHERE id = ?");
            $stmt->execute([$note !== '' ? $note : null, $noteId]);
            header('Location: /admin-gifts?note_saved=1#gifts-table');
            exit;
        }
    } catch (Exception $e) {
        $error = 'Error saving note: ' . htmlspecialchars($e->getMessage());
    }
}

// Handle deleting a manual gift
if (!$sampleMode && $authenticated && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM gifts WHERE id = ?");
        $stmt->execute([(int) $_GET['delete']]);
        header('Location: /admin-gifts?deleted=1#gifts-table');
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
        $valueRaw = trim((string) ($_POST['value'] ?? ''));
        // Accept "$1,234.56" etc. — strip anything that isn't a digit or decimal point
        $valueClean = preg_replace('/[^0-9.]/', '', $valueRaw);
        $valueValue = ($valueClean !== '' && is_numeric($valueClean)) ? (float) $valueClean : null;
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
                            value = ?,
                            thank_you_written = ?, thank_you_written_at = ?,
                            thank_you_sent = ?, thank_you_sent_at = ?
                        WHERE id = ?
                    ");
                    $upd->execute([
                        $description,
                        $purchaserName !== '' ? $purchaserName : null,
                        $notes !== '' ? $notes : null,
                        $receivedOnValue,
                        $valueValue,
                        $thankYouWritten,
                        $writtenAt,
                        $thankYouSent,
                        $sentAt,
                        (int) $giftId,
                    ]);
                    header('Location: /admin-gifts?updated=1#gifts-table');
                    exit;
                }
            } else {
                $writtenAt = $thankYouWritten ? date('Y-m-d H:i:s') : null;
                $sentAt = $thankYouSent ? date('Y-m-d H:i:s') : null;
                $ins = $pdo->prepare("
                    INSERT INTO gifts (description, purchaser_name, notes, received_on, value,
                        thank_you_written, thank_you_written_at, thank_you_sent, thank_you_sent_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $description,
                    $purchaserName !== '' ? $purchaserName : null,
                    $notes !== '' ? $notes : null,
                    $receivedOnValue,
                    $valueValue,
                    $thankYouWritten,
                    $writtenAt,
                    $thankYouSent,
                    $sentAt,
                ]);
                header('Location: /admin-gifts?added=1#gifts-table');
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
} elseif (isset($_GET['note_saved'])) {
    $success = 'Note saved.';
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

// Fetch registry purchases, manual gifts, and fund contributions
$registryPurchases = [];
$manualGifts = [];
$houseFundContribs = [];
$honeymoonFundContribs = [];
if ($sampleMode) {
    foreach (getSampleRegistryItems() as $item) {
        if (!empty($item['purchased'])) {
            $registryPurchases[] = $item + [
                'purchase_message' => $item['purchase_message'] ?? null,
                'admin_note' => $item['admin_note'] ?? null,
                'received' => $item['received'] ?? 0,
                'received_at' => $item['received_at'] ?? null,
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
    $houseFundContribs = getSampleHouseFundContributions();
    $honeymoonFundContribs = getSampleHoneymoonFundContributions();
} elseif ($authenticated) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("
            SELECT id, title, price, purchased_by, purchase_message, admin_note,
                   received, received_at,
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
            SELECT id, description, purchaser_name, notes, received_on, value,
                   thank_you_written, thank_you_written_at,
                   thank_you_sent, thank_you_sent_at, created_at
            FROM gifts
            ORDER BY (thank_you_written AND thank_you_sent) ASC,
                     (purchaser_name IS NULL OR purchaser_name = '') ASC,
                     LOWER(purchaser_name) ASC,
                     created_at DESC
        ");
        $manualGifts = $stmt->fetchAll();

        $stmt = $pdo->query("
            SELECT id, amount, contributor_name, created_at,
                   thank_you_written, thank_you_written_at,
                   thank_you_sent, thank_you_sent_at
            FROM house_fund_contributions
            ORDER BY (thank_you_written AND thank_you_sent) ASC,
                     (contributor_name IS NULL OR contributor_name = '') ASC,
                     LOWER(contributor_name) ASC,
                     created_at DESC
        ");
        $houseFundContribs = $stmt->fetchAll();

        $stmt = $pdo->query("
            SELECT id, amount, contributor_name, created_at,
                   thank_you_written, thank_you_written_at,
                   thank_you_sent, thank_you_sent_at
            FROM honeymoon_fund_contributions
            ORDER BY (thank_you_written AND thank_you_sent) ASC,
                     (contributor_name IS NULL OR contributor_name = '') ASC,
                     LOWER(contributor_name) ASC,
                     created_at DESC
        ");
        $honeymoonFundContribs = $stmt->fetchAll();
    } catch (Exception $e) {
        $error = 'Error loading gifts: ' . htmlspecialchars($e->getMessage());
    }
}

// Compute stats
function isGiftCompleted(array $g): bool {
    return !empty($g['thank_you_written']) && !empty($g['thank_you_sent']);
}
$totalGifts = count($registryPurchases) + count($manualGifts)
    + count($houseFundContribs) + count($honeymoonFundContribs);
$thanksCompleted = 0;
$thanksWritten = 0;
$thanksSent = 0;
$registryReceived = 0;
$registryAwaiting = 0;
$totalGiftValue = 0.0;
foreach ($registryPurchases as $r) {
    if (isGiftCompleted($r)) $thanksCompleted++;
    if (!empty($r['thank_you_written'])) $thanksWritten++;
    if (!empty($r['thank_you_sent'])) $thanksSent++;
    if (!empty($r['received'])) {
        $registryReceived++;
    } else {
        $registryAwaiting++;
    }
    if (isset($r['price']) && is_numeric($r['price'])) {
        $totalGiftValue += (float) $r['price'];
    }
}
foreach ($manualGifts as $g) {
    if (isGiftCompleted($g)) $thanksCompleted++;
    if (!empty($g['thank_you_written'])) $thanksWritten++;
    if (!empty($g['thank_you_sent'])) $thanksSent++;
    if (isset($g['value']) && is_numeric($g['value'])) {
        $totalGiftValue += (float) $g['value'];
    }
}
foreach (array_merge($houseFundContribs, $honeymoonFundContribs) as $f) {
    if (isGiftCompleted($f)) $thanksCompleted++;
    if (!empty($f['thank_you_written'])) $thanksWritten++;
    if (!empty($f['thank_you_sent'])) $thanksSent++;
    if (isset($f['amount']) && is_numeric($f['amount'])) {
        $totalGiftValue += (float) $f['amount'];
    }
}
$thanksPending = $totalGifts - $thanksCompleted;

// Time zones for display. Database TIMESTAMPs are stored in UTC
// (server runs UTC), so convert to Eastern time for the couple.
$utcTz = new DateTimeZone('UTC');
$displayTz = new DateTimeZone('America/New_York');

function formatGiftDate(?string $raw, DateTimeZone $utcTz, DateTimeZone $displayTz, string $format = 'M j, Y g:i A'): string {
    if (!$raw) return '';
    try {
        $dt = new DateTime($raw, $utcTz);
        $dt->setTimezone($displayTz);
        return $dt->format($format);
    } catch (Exception $e) {
        return htmlspecialchars($raw);
    }
}

// Normalize registry purchases and off-registry gifts into a single list
// so they can be rendered in one unified table.
$allGifts = [];
foreach ($registryPurchases as $r) {
    $allGifts[] = [
        'source' => 'registry',
        'id' => (int) $r['id'],
        'title' => $r['title'] ?? '',
        'from' => trim((string) ($r['purchased_by'] ?? '')),
        'details' => (string) ($r['purchase_message'] ?? ''),
        'admin_note' => (string) ($r['admin_note'] ?? ''),
        'price' => $r['price'] ?? null,
        'date_display' => !empty($r['received_at'])
            ? formatGiftDate($r['received_at'], $utcTz, $displayTz, 'M j, Y')
            : formatGiftDate($r['updated_at'] ?? null, $utcTz, $displayTz),
        'received' => !empty($r['received']),
        'received_at' => $r['received_at'] ?? null,
        'written' => !empty($r['thank_you_written']),
        'sent' => !empty($r['thank_you_sent']),
    ];
}
foreach ($manualGifts as $g) {
    $allGifts[] = [
        'source' => 'offregistry',
        'id' => (int) $g['id'],
        'title' => $g['description'] ?? '',
        'from' => trim((string) ($g['purchaser_name'] ?? '')),
        'details' => (string) ($g['notes'] ?? ''),
        'admin_note' => '', // off-registry gifts use `details` for admin notes already
        'price' => (isset($g['value']) && $g['value'] !== null && $g['value'] !== '') ? (float) $g['value'] : null,
        'date_display' => !empty($g['received_on']) ? date('M j, Y', strtotime($g['received_on'])) : '',
        'received' => null, // not tracked as a toggle for off-registry gifts
        'received_at' => $g['received_on'] ?? null,
        'written' => !empty($g['thank_you_written']),
        'sent' => !empty($g['thank_you_sent']),
    ];
}
// Append fund contributions so donors show up in the gift manager
// alongside registry and off-registry gifts.
$fundSources = [
    ['source' => 'housefund',     'label' => 'House Fund',     'contribs' => $houseFundContribs],
    ['source' => 'honeymoonfund', 'label' => 'Honeymoon Fund', 'contribs' => $honeymoonFundContribs],
];
foreach ($fundSources as $fs) {
    foreach ($fs['contribs'] as $c) {
        $allGifts[] = [
            'source' => $fs['source'],
            'id' => (int) $c['id'],
            'title' => $fs['label'] . ' contribution',
            'from' => trim((string) ($c['contributor_name'] ?? '')),
            'details' => '',
            'admin_note' => '',
            'price' => (isset($c['amount']) && is_numeric($c['amount'])) ? (float) $c['amount'] : null,
            'date_display' => !empty($c['created_at']) ? formatGiftDate($c['created_at'], $utcTz, $displayTz, 'M j, Y') : '',
            'received' => null,
            'received_at' => $c['created_at'] ?? null,
            'written' => !empty($c['thank_you_written']),
            'sent' => !empty($c['thank_you_sent']),
        ];
    }
}
// Sort the combined list alphabetically by giver name (case-insensitive).
// Entries without a name sink to the bottom, ties broken by title.
usort($allGifts, function (array $a, array $b) {
    if ($a['from'] === '' && $b['from'] !== '') return 1;
    if ($a['from'] !== '' && $b['from'] === '') return -1;
    $cmp = strcasecmp($a['from'], $b['from']);
    if ($cmp !== 0) return $cmp;
    return strcasecmp((string) $a['title'], (string) $b['title']);
});

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
        /* Full-bleed: break out of .page-container's 1200px max-width and
           stretch to the viewport edges so the wide registry table can
           display more columns without cramping. */
        .admin-bleed {
            width: 100vw;
            margin-left: calc(50% - 50vw);
            margin-right: calc(50% - 50vw);
            max-width: none;
            box-sizing: border-box;
            padding-left: 1rem;
            padding-right: 1rem;
        }
        @media (min-width: 768px) {
            .admin-bleed { padding-left: 1.5rem; padding-right: 1.5rem; }
        }
        .gift-filter-bar {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .gift-filter-bar label {
            font-family: 'Crimson Text', serif;
            text-transform: none;
            font-size: 1rem;
            color: var(--color-text-secondary);
        }
        .gift-filter-bar input[type="search"] {
            flex: 1;
            min-width: 16rem;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            background-color: var(--color-surface);
            color: inherit;
            font: inherit;
            font-family: 'Crimson Text', serif;
        }
        .gift-filter-bar input[type="search"]:focus {
            outline: none;
            border-color: var(--color-green);
            box-shadow: 0 0 0 2px rgba(127, 143, 101, 0.25);
        }
        .gift-filter-count {
            font-family: 'Crimson Text', serif;
            color: var(--color-text-secondary);
            font-size: 0.95rem;
        }
        .gift-filter-select {
            padding: 0.45rem 0.65rem;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            background-color: var(--color-surface);
            color: inherit;
            font: inherit;
            font-family: 'Crimson Text', serif;
        }
        .gift-filter-select:focus {
            outline: none;
            border-color: var(--color-green);
            box-shadow: 0 0 0 2px rgba(127, 143, 101, 0.25);
        }
        .gift-filter-bar #filter-reset {
            padding: 0.5rem 0.9rem;
        }
        .gift-filter-empty {
            padding: 1rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            color: var(--color-text-secondary);
            display: none;
        }
        .gift-filter-empty.visible { display: block; }
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
        .gifts-table th.sortable {
            cursor: pointer;
            user-select: none;
            position: relative;
            padding-right: 1.4rem;
        }
        .gifts-table th.sortable::after {
            content: '⇅';
            position: absolute;
            right: 0.55rem;
            top: 50%;
            transform: translateY(-50%);
            opacity: 0.5;
            font-size: 0.8rem;
        }
        .gifts-table th.sortable.sort-asc::after { content: '▲'; opacity: 1; }
        .gifts-table th.sortable.sort-desc::after { content: '▼'; opacity: 1; }
        .gifts-table th.sortable:hover { background-color: #2d5016; }
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
        .gifts-table .gift-admin-note {
            margin-top: 0.35rem;
            font-family: 'Crimson Text', serif;
            text-transform: none;
            color: var(--color-dark);
            white-space: pre-wrap;
            border-left: 3px solid var(--color-gold);
            padding-left: 0.6rem;
        }
        .gifts-table .gift-admin-note strong { color: var(--color-gold); }
        .gift-title-link {
            background: none;
            border: none;
            padding: 0;
            margin: 0;
            font: inherit;
            color: var(--color-dark);
            cursor: pointer;
            text-align: left;
            font-weight: bold;
            text-decoration: none;
            border-bottom: 1px dashed transparent;
            transition: border-color 0.2s, color 0.2s;
        }
        .gift-title-link:hover,
        .gift-title-link:focus {
            color: var(--color-green);
            border-bottom-color: var(--color-green);
            outline: none;
        }
        .note-cell { padding: 0 !important; }
        .note-cell-button {
            display: block;
            width: 100%;
            min-height: 100%;
            text-align: left;
            background: none;
            border: none;
            padding: 0.85rem 1rem;
            margin: 0;
            font: inherit;
            color: inherit;
            text-decoration: none;
            cursor: pointer;
            border-radius: 4px;
            transition: background-color 0.15s;
        }
        .note-cell-button:hover,
        .note-cell-button:focus {
            background-color: rgba(127, 143, 101, 0.08);
            outline: none;
        }
        .note-cell-placeholder {
            color: var(--color-text-muted);
            font-family: 'Crimson Text', serif;
            font-style: italic;
            text-transform: none;
        }
        .note-cell-button:hover .note-cell-placeholder,
        .note-cell-button:focus .note-cell-placeholder {
            color: var(--color-green);
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
        .btn-received {
            background-color: #b08cd6;
            color: white;
        }
        .btn-received:hover { background-color: #916dbc; }
        .js-toggle-status.is-busy {
            opacity: 0.6;
            cursor: progress;
            pointer-events: none;
        }
        .gifts-table tr.awaiting-delivery { background-color: #fffdf3; }
        [data-theme="dark"] .gifts-table tr.awaiting-delivery { background-color: rgba(241, 196, 15, 0.08); }
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
        .badge-source {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-family: 'Cinzel', serif;
            letter-spacing: 0.03em;
            white-space: nowrap;
        }
        .badge-source.registry { background-color: var(--color-green); color: white; }
        .badge-source.offregistry { background-color: var(--color-gold); color: white; }
        .badge-source.housefund { background-color: #5b8def; color: white; }
        .badge-source.honeymoonfund { background-color: #b08cd6; color: white; }
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
        details.add-gift-panel .add-gift-body input,
        details.add-gift-panel .add-gift-body textarea,
        details.add-gift-panel .add-gift-body select {
            font-family: 'Crimson Text', serif;
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
                            <span class="stat-value"><?php echo (int) $registryAwaiting; ?></span>
                            <span class="stat-label">Awaiting delivery</span>
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
                        <div class="stat-card">
                            <span class="stat-value">$<?php echo number_format($totalGiftValue, 2); ?></span>
                            <span class="stat-label">Total gift value</span>
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
                                    <div class="form-group">
                                        <label for="value">Value (optional)</label>
                                        <input type="number" id="value" name="value" step="0.01" min="0"
                                               value="<?php echo ($editGift && isset($editGift['value']) && $editGift['value'] !== null && $editGift['value'] !== '') ? htmlspecialchars(number_format((float) $editGift['value'], 2, '.', '')) : ''; ?>"
                                               placeholder="e.g. 200.00">
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

                <h2 class="section-title admin-inner" id="gifts-table">Gifts</h2>
                <div class="form-container admin-full admin-bleed">
                    <?php if (empty($allGifts)): ?>
                        <p class="empty-state">No gifts recorded yet. Guests can purchase from the registry, or add off-registry gifts with the form above.</p>
                    <?php else: ?>
                        <div class="gift-filter-bar">
                            <label for="gift-filter">Search</label>
                            <input type="search" id="gift-filter" placeholder="Filter by gift, purchaser, message, or notes…" autocomplete="off">
                            <label for="filter-received">Received</label>
                            <select id="filter-received" class="gift-filter-select">
                                <option value="">All</option>
                                <option value="yes">Received</option>
                                <option value="no">Not received</option>
                            </select>
                            <label for="filter-written">Written</label>
                            <select id="filter-written" class="gift-filter-select">
                                <option value="">All</option>
                                <option value="yes">Written</option>
                                <option value="no">Not written</option>
                            </select>
                            <label for="filter-sent">Sent</label>
                            <select id="filter-sent" class="gift-filter-select">
                                <option value="">All</option>
                                <option value="yes">Sent</option>
                                <option value="no">Not sent</option>
                            </select>
                            <button type="button" id="filter-reset" class="btn-small btn-secondary">Reset</button>
                            <span class="gift-filter-count" id="gift-filter-count"><?php echo count($allGifts); ?> of <?php echo count($allGifts); ?></span>
                        </div>
                        <p class="gift-filter-empty" id="gift-filter-empty">No gifts match the current filters.</p>
                        <div class="table-wrapper">
                            <table class="gifts-table" id="gifts-unified-table">
                                <thead>
                                    <tr>
                                        <th class="sortable" data-sort-key="source">Source</th>
                                        <th class="sortable" data-sort-key="status">Status</th>
                                        <th class="sortable" data-sort-key="from">From</th>
                                        <th class="sortable" data-sort-key="title">Gift</th>
                                        <th>Message / Notes</th>
                                        <th class="sortable" data-sort-key="price">Price</th>
                                        <th class="sortable" data-sort-key="date">Date</th>
                                        <th class="sortable" data-sort-key="received">Received</th>
                                        <th class="sortable" data-sort-key="written">Written</th>
                                        <th class="sortable" data-sort-key="sent">Sent</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allGifts as $g):
                                        $isRegistry = $g['source'] === 'registry';
                                        $isOffRegistry = $g['source'] === 'offregistry';
                                        $isHouseFund = $g['source'] === 'housefund';
                                        $isHoneymoonFund = $g['source'] === 'honeymoonfund';
                                        $isFund = $isHouseFund || $isHoneymoonFund;
                                        $completed = $g['written'] && $g['sent'];
                                        $noName = $g['from'] === '';
                                        $rowClasses = ['gift-row', 'source-' . $g['source']];
                                        if ($completed) $rowClasses[] = 'thanked';
                                        elseif ($noName) $rowClasses[] = 'noname';
                                        if ($isRegistry && !$g['received']) $rowClasses[] = 'awaiting-delivery';
                                        $sourceSearchTag = 'registry';
                                        if ($isOffRegistry) $sourceSearchTag = 'off-registry off registry';
                                        elseif ($isHouseFund) $sourceSearchTag = 'house fund housefund';
                                        elseif ($isHoneymoonFund) $sourceSearchTag = 'honeymoon fund honeymoonfund';
                                        $searchParts = array_filter([
                                            $g['title'],
                                            $g['from'],
                                            $g['details'],
                                            $sourceSearchTag,
                                        ]);
                                        $searchBlob = strtolower(trim(preg_replace('/\s+/', ' ', implode(' ', $searchParts))));
                                        // For filter UX, off-registry gifts and fund contributions
                                        // are treated as "received" since admins only record them
                                        // after the gift/contribution arrives.
                                        $receivedAttr = $isRegistry ? ($g['received'] ? 'yes' : 'no') : 'yes';
                                        $writtenAttr = $g['written'] ? 'yes' : 'no';
                                        $sentAttr = $g['sent'] ? 'yes' : 'no';
                                        // Sort keys. Empty strings use "~" so they sort last in ascending.
                                        $statusRank = $completed ? 3 : ($g['sent'] ? 2 : ($g['written'] ? 1 : 0));
                                        $fromSort = $g['from'] !== '' ? mb_strtolower($g['from']) : '~';
                                        $titleSort = mb_strtolower($g['title'] ?? '');
                                        $priceSort = $g['price'] !== null && $g['price'] !== '' ? (float) $g['price'] : -1;
                                        $dateSort = !empty($g['received_at']) ? strtotime($g['received_at']) : 0;
                                        $sourceSortKey = 'a';
                                        if ($isOffRegistry) $sourceSortKey = 'b';
                                        elseif ($isHouseFund) $sourceSortKey = 'c';
                                        elseif ($isHoneymoonFund) $sourceSortKey = 'd';
                                    ?>
                                        <tr class="<?php echo htmlspecialchars(implode(' ', $rowClasses)); ?>"
                                            data-search="<?php echo htmlspecialchars($searchBlob); ?>"
                                            data-received="<?php echo $receivedAttr; ?>"
                                            data-written="<?php echo $writtenAttr; ?>"
                                            data-sent="<?php echo $sentAttr; ?>"
                                            data-sort-source="<?php echo htmlspecialchars($sourceSortKey); ?>"
                                            data-sort-status="<?php echo (int) $statusRank; ?>"
                                            data-sort-from="<?php echo htmlspecialchars($fromSort); ?>"
                                            data-sort-title="<?php echo htmlspecialchars($titleSort); ?>"
                                            data-sort-price="<?php echo htmlspecialchars((string) $priceSort); ?>"
                                            data-sort-date="<?php echo (int) $dateSort; ?>"
                                            data-sort-received="<?php echo $receivedAttr === 'yes' ? 1 : 0; ?>"
                                            data-sort-written="<?php echo $writtenAttr === 'yes' ? 1 : 0; ?>"
                                            data-sort-sent="<?php echo $sentAttr === 'yes' ? 1 : 0; ?>">
                                            <td>
                                                <?php
                                                    $sourceBadgeClass = 'registry';
                                                    $sourceBadgeLabel = 'Registry';
                                                    if ($isOffRegistry) { $sourceBadgeClass = 'offregistry'; $sourceBadgeLabel = 'Off-Registry'; }
                                                    elseif ($isHouseFund) { $sourceBadgeClass = 'housefund'; $sourceBadgeLabel = 'House Fund'; }
                                                    elseif ($isHoneymoonFund) { $sourceBadgeClass = 'honeymoonfund'; $sourceBadgeLabel = 'Honeymoon Fund'; }
                                                ?>
                                                <span class="badge-source <?php echo $sourceBadgeClass; ?>">
                                                    <?php echo htmlspecialchars($sourceBadgeLabel); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($completed): ?>
                                                    <span class="badge-thanks completed">Completed</span>
                                                <?php elseif ($g['sent']): ?>
                                                    <span class="badge-thanks sent">Sent</span>
                                                <?php elseif ($g['written']): ?>
                                                    <span class="badge-thanks written">Written</span>
                                                <?php else: ?>
                                                    <span class="badge-thanks pending">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isRegistry): ?>
                                                    <input type="text"
                                                           class="gift-name-input<?php echo $noName ? ' gift-name-input-empty' : ''; ?>"
                                                           value="<?php echo htmlspecialchars($g['from']); ?>"
                                                           placeholder="(add name)"
                                                           maxlength="255"
                                                           data-item-id="<?php echo (int) $g['id']; ?>"
                                                           data-original="<?php echo htmlspecialchars($g['from']); ?>"
                                                           <?php echo $sampleMode ? 'readonly' : ''; ?>>
                                                    <span class="gift-name-status" aria-live="polite"></span>
                                                <?php elseif ($noName): ?>
                                                    <span class="no-name">(no name)</span>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($g['from']); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="gift-title">
                                                <?php if ($isRegistry): ?>
                                                    <button type="button" class="gift-title-link js-open-note-modal"
                                                            data-item-id="<?php echo (int) $g['id']; ?>"
                                                            data-item-title="<?php echo htmlspecialchars($g['title']); ?>"
                                                            data-item-note="<?php echo htmlspecialchars($g['admin_note']); ?>"
                                                            title="Click to add or edit an admin note">
                                                        <?php echo htmlspecialchars($g['title']); ?>
                                                    </button>
                                                <?php elseif ($isFund): ?>
                                                    <?php $fundAdminUrl = $isHouseFund ? '/admin-house-fund' : '/admin-honeymoon-fund'; ?>
                                                    <a href="<?php echo $fundAdminUrl; ?>" class="gift-title-link" title="Manage this contribution">
                                                        <?php echo htmlspecialchars($g['title']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="/admin-gifts?edit=<?php echo (int) $g['id']; ?>#add-gift" class="gift-title-link" title="Click to edit this gift">
                                                        <?php echo htmlspecialchars($g['title']); ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td class="note-cell">
                                                <?php
                                                    $hasDetails = $g['details'] !== '';
                                                    $hasAdminNote = $isRegistry && ($g['admin_note'] ?? '') !== '';
                                                    $noteTooltip = $isRegistry
                                                        ? 'Click to add or edit an admin note'
                                                        : ($isFund ? 'Manage this contribution' : 'Click to edit this gift');
                                                ?>
                                                <?php if ($isRegistry): ?>
                                                    <button type="button" class="note-cell-button js-open-note-modal"
                                                            data-item-id="<?php echo (int) $g['id']; ?>"
                                                            data-item-title="<?php echo htmlspecialchars($g['title']); ?>"
                                                            data-item-note="<?php echo htmlspecialchars($g['admin_note']); ?>"
                                                            title="<?php echo htmlspecialchars($noteTooltip); ?>">
                                                <?php elseif ($isFund): ?>
                                                    <?php $fundAdminUrl = $isHouseFund ? '/admin-house-fund' : '/admin-honeymoon-fund'; ?>
                                                    <a href="<?php echo $fundAdminUrl; ?>"
                                                       class="note-cell-button"
                                                       title="<?php echo htmlspecialchars($noteTooltip); ?>">
                                                <?php else: ?>
                                                    <a href="/admin-gifts?edit=<?php echo (int) $g['id']; ?>#add-gift"
                                                       class="note-cell-button"
                                                       title="<?php echo htmlspecialchars($noteTooltip); ?>">
                                                <?php endif; ?>
                                                    <?php if ($hasDetails || $hasAdminNote): ?>
                                                        <?php if ($hasDetails && $isRegistry): ?>
                                                            <div class="gift-message">&ldquo;<?php echo nl2br(htmlspecialchars($g['details'])); ?>&rdquo;</div>
                                                        <?php elseif ($hasDetails): ?>
                                                            <div class="gift-notes"><?php echo nl2br(htmlspecialchars($g['details'])); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($hasAdminNote): ?>
                                                            <div class="gift-admin-note"><strong>Note:</strong> <?php echo nl2br(htmlspecialchars($g['admin_note'])); ?></div>
                                                        <?php endif; ?>
                                                    <?php elseif ($isFund): ?>
                                                        <span class="note-cell-placeholder">—</span>
                                                    <?php else: ?>
                                                        <span class="note-cell-placeholder">+ Add note</span>
                                                    <?php endif; ?>
                                                <?php if ($isRegistry): ?>
                                                    </button>
                                                <?php else: ?>
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo !empty($g['price']) ? '$' . number_format((float) $g['price'], 2) : '—'; ?></td>
                                            <td><?php echo $g['date_display'] !== '' ? htmlspecialchars($g['date_display']) : '—'; ?></td>
                                            <td class="actions-cell">
                                                <?php if ($isRegistry): ?>
                                                    <a href="/admin-gifts?toggle_registry_received=<?php echo (int) $g['id']; ?>#gifts-table"
                                                       class="btn-small js-toggle-status <?php echo $g['received'] ? 'btn-thanks-active' : 'btn-received'; ?>"
                                                       data-source="registry"
                                                       data-id="<?php echo (int) $g['id']; ?>"
                                                       data-field="received"
                                                       title="<?php echo $g['received'] && !empty($g['received_at']) ? 'Received ' . htmlspecialchars(formatGiftDate($g['received_at'], $utcTz, $displayTz, 'M j, Y')) : 'Mark gift as received'; ?>">
                                                        <?php echo $g['received'] ? '✓ Received' : 'Mark Received'; ?>
                                                    </a>
                                                <?php elseif ($isFund): ?>
                                                    <span class="no-name" title="Fund contributions are already received">—</span>
                                                <?php else: ?>
                                                    <span class="no-name" title="Off-registry gifts are recorded after arrival">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions-cell">
                                                <?php
                                                    if ($isRegistry) $writtenHref = 'toggle_registry_written';
                                                    elseif ($isHouseFund) $writtenHref = 'toggle_housefund_written';
                                                    elseif ($isHoneymoonFund) $writtenHref = 'toggle_honeymoonfund_written';
                                                    else $writtenHref = 'toggle_gift_written';
                                                ?>
                                                <a href="/admin-gifts?<?php echo $writtenHref; ?>=<?php echo (int) $g['id']; ?>#gifts-table"
                                                   class="btn-small js-toggle-status <?php echo $g['written'] ? 'btn-thanks-active' : 'btn-thanks-written'; ?>"
                                                   data-source="<?php echo htmlspecialchars($g['source']); ?>"
                                                   data-id="<?php echo (int) $g['id']; ?>"
                                                   data-field="written">
                                                    <?php echo $g['written'] ? '✓ Written' : 'Mark Written'; ?>
                                                </a>
                                            </td>
                                            <td class="actions-cell">
                                                <?php
                                                    if ($isRegistry) $sentHref = 'toggle_registry_sent';
                                                    elseif ($isHouseFund) $sentHref = 'toggle_housefund_sent';
                                                    elseif ($isHoneymoonFund) $sentHref = 'toggle_honeymoonfund_sent';
                                                    else $sentHref = 'toggle_gift_sent';
                                                ?>
                                                <a href="/admin-gifts?<?php echo $sentHref; ?>=<?php echo (int) $g['id']; ?>#gifts-table"
                                                   class="btn-small js-toggle-status <?php echo $g['sent'] ? 'btn-thanks-active' : 'btn-thanks-sent'; ?>"
                                                   data-source="<?php echo htmlspecialchars($g['source']); ?>"
                                                   data-id="<?php echo (int) $g['id']; ?>"
                                                   data-field="sent">
                                                    <?php echo $g['sent'] ? '✓ Sent' : 'Mark Sent'; ?>
                                                </a>
                                            </td>
                                            <td class="actions-cell">
                                                <?php if ($isOffRegistry): ?>
                                                    <a href="/admin-gifts?edit=<?php echo (int) $g['id']; ?>#add-gift" class="btn-small btn-edit">Edit</a>
                                                    <a href="/admin-gifts?delete=<?php echo (int) $g['id']; ?>#gifts-table" class="btn-small btn-delete" onclick="return confirm('Delete this gift entry?');">Delete</a>
                                                <?php elseif ($isFund): ?>
                                                    <?php $fundAdminUrl = $isHouseFund ? '/admin-house-fund' : '/admin-honeymoon-fund'; ?>
                                                    <a href="<?php echo $fundAdminUrl; ?>" class="btn-small btn-edit">Manage</a>
                                                <?php else: ?>
                                                    <button type="button" class="btn-small btn-delete js-unmark-purchased"
                                                            data-id="<?php echo (int) $g['id']; ?>"
                                                            data-item-title="<?php echo htmlspecialchars($g['title']); ?>"
                                                            title="Remove this purchase record (clears name &amp; message, leaves the item back on the registry)">
                                                        Unmark Purchased
                                                    </button>
                                                <?php endif; ?>
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
    <?php if ($authenticated): ?>
    <!-- Admin note modal for registry purchases (click a gift title to open) -->
    <div id="registry-note-modal" class="modal">
        <div class="modal-content">
            <span class="modal-close" id="registry-note-modal-close">&times;</span>
            <h3>Add / Edit Note</h3>
            <p id="registry-note-item-title" style="font-family: 'Cinzel', serif; color: var(--color-dark); font-weight: bold; margin-bottom: 1rem;"></p>
            <form method="POST" action="/admin-gifts#gifts-table" id="registry-note-form">
                <input type="hidden" name="save_registry_note" value="1">
                <input type="hidden" name="registry_item_id" id="registry-note-item-id">
                <div class="form-group">
                    <label for="registry-note-textarea">Private note (only visible in the admin area)</label>
                    <textarea id="registry-note-textarea" name="admin_note" rows="5" maxlength="4000" placeholder="Anything to remember when writing the thank-you card"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="submit" class="btn">Save Note</button>
                    <button type="button" class="btn btn-secondary" id="registry-note-modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        (function() {
            const modal = document.getElementById('registry-note-modal');
            if (!modal) return;
            const idInput = document.getElementById('registry-note-item-id');
            const textarea = document.getElementById('registry-note-textarea');
            const titleEl = document.getElementById('registry-note-item-title');
            const closeBtn = document.getElementById('registry-note-modal-close');
            const cancelBtn = document.getElementById('registry-note-modal-cancel');

            function open(itemId, itemTitle, note) {
                if (idInput) idInput.value = itemId;
                if (titleEl) titleEl.textContent = itemTitle || '';
                if (textarea) textarea.value = note || '';
                modal.style.display = 'block';
                setTimeout(function() { if (textarea) textarea.focus(); }, 50);
            }
            function close() { modal.style.display = 'none'; }

            document.querySelectorAll('.js-open-note-modal').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    open(btn.dataset.itemId, btn.dataset.itemTitle, btn.dataset.itemNote);
                });
            });

            if (closeBtn) closeBtn.addEventListener('click', close);
            if (cancelBtn) cancelBtn.addEventListener('click', close);
            modal.addEventListener('click', function(e) {
                if (e.target === modal) close();
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.style.display === 'block') close();
            });
        })();
    </script>
    <script>
        // Persist the open/closed state of the Add Off-Registry Gift panel
        // across page loads. When the user lands on the page mid-edit (PHP
        // output `open` attribute), honor that and leave it open — the edit
        // flow needs the form visible regardless of stored preference.
        (function() {
            const STORAGE_KEY = 'admin-gifts-add-panel-open';
            const panel = document.getElementById('add-gift');
            if (!panel || panel.tagName.toLowerCase() !== 'details') return;

            const isEditing = document.querySelector('input[name="gift_id"]') !== null;
            if (!isEditing) {
                try {
                    const stored = localStorage.getItem(STORAGE_KEY);
                    if (stored === '1') panel.open = true;
                    else if (stored === '0') panel.open = false;
                } catch (e) { /* ignore storage errors */ }
            }

            panel.addEventListener('toggle', function() {
                try {
                    localStorage.setItem(STORAGE_KEY, panel.open ? '1' : '0');
                } catch (e) { /* ignore storage errors */ }
            });
        })();

        // Live filter for the unified gifts table. Combines a text search
        // with three yes/no/all dropdowns for received / written / sent.
        // Off-registry rows are pre-tagged as "received" since we only
        // record them after the gift has arrived.
        (function() {
            const input = document.getElementById('gift-filter');
            const table = document.getElementById('gifts-unified-table');
            const count = document.getElementById('gift-filter-count');
            const empty = document.getElementById('gift-filter-empty');
            const selReceived = document.getElementById('filter-received');
            const selWritten = document.getElementById('filter-written');
            const selSent = document.getElementById('filter-sent');
            const resetBtn = document.getElementById('filter-reset');
            if (!input || !table) return;
            const rows = Array.from(table.querySelectorAll('tbody tr.gift-row'));
            const total = rows.length;

            function matchesStatus(row, sel, key) {
                const want = sel ? sel.value : '';
                if (!want) return true;
                return (row.dataset[key] || '') === want;
            }

            function applyFilter() {
                const q = input.value.trim().toLowerCase();
                let visible = 0;
                rows.forEach(function(row) {
                    const haystack = row.dataset.search || '';
                    const textOk = q === '' || haystack.indexOf(q) !== -1;
                    const rOk = matchesStatus(row, selReceived, 'received');
                    const wOk = matchesStatus(row, selWritten, 'written');
                    const sOk = matchesStatus(row, selSent, 'sent');
                    const show = textOk && rOk && wOk && sOk;
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                if (count) count.textContent = visible + ' of ' + rows.length;
                if (empty) empty.classList.toggle('visible', visible === 0 && rows.length > 0);
            }

            input.addEventListener('input', applyFilter);
            [selReceived, selWritten, selSent].forEach(function(sel) {
                if (sel) sel.addEventListener('change', applyFilter);
            });
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    input.value = '';
                    if (selReceived) selReceived.value = '';
                    if (selWritten) selWritten.value = '';
                    if (selSent) selSent.value = '';
                    applyFilter();
                    input.focus();
                });
            }
            applyFilter();

            // Clickable sortable headers. Numeric columns parse as numbers;
            // everything else compares as lowercase strings. Click once for
            // ascending, click the same header again for descending.
            const numericKeys = { status: true, price: true, date: true, received: true, written: true, sent: true };
            const tbody = table.querySelector('tbody');
            const headers = Array.from(table.querySelectorAll('th.sortable'));
            let sortState = { key: null, dir: null };

            function readSort(row, key) {
                const raw = row.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)];
                if (numericKeys[key]) return parseFloat(raw);
                return (raw || '').toLowerCase();
            }

            function sortBy(key) {
                if (!tbody) return;
                if (sortState.key === key) {
                    sortState.dir = sortState.dir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortState.key = key;
                    sortState.dir = 'asc';
                }
                const dirMul = sortState.dir === 'asc' ? 1 : -1;
                const sorted = rows.slice().sort(function(a, b) {
                    const av = readSort(a, key);
                    const bv = readSort(b, key);
                    if (av < bv) return -1 * dirMul;
                    if (av > bv) return 1 * dirMul;
                    // Stable tie-break: fall back to lowercase title
                    const at = (a.dataset.sortTitle || '');
                    const bt = (b.dataset.sortTitle || '');
                    if (at < bt) return -1;
                    if (at > bt) return 1;
                    return 0;
                });
                sorted.forEach(function(row) { tbody.appendChild(row); });
                headers.forEach(function(h) {
                    h.classList.remove('sort-asc', 'sort-desc');
                    if (h.dataset.sortKey === key) {
                        h.classList.add(sortState.dir === 'asc' ? 'sort-asc' : 'sort-desc');
                    }
                });
            }

            headers.forEach(function(h) {
                h.addEventListener('click', function() {
                    sortBy(h.dataset.sortKey);
                });
            });

            // In-place toggle for Mark Received / Mark Written / Mark Sent.
            // Hits /api/toggle-gift-status and updates the row in place so
            // the user's scroll position, sort, and filters stay intact.
            // Sample mode is bypassed (the server would reject the call
            // anyway) so the anchor fallback still navigates normally.
            if (!document.querySelector('.sample-mode-banner')) {
                function updateStatusBadge(row) {
                    const written = row.dataset.written === 'yes';
                    const sent = row.dataset.sent === 'yes';
                    const completed = written && sent;
                    const badge = row.querySelector('.badge-thanks');
                    if (badge) {
                        badge.classList.remove('completed', 'sent', 'written', 'pending');
                        if (completed) { badge.classList.add('completed'); badge.textContent = 'Completed'; }
                        else if (sent) { badge.classList.add('sent'); badge.textContent = 'Sent'; }
                        else if (written) { badge.classList.add('written'); badge.textContent = 'Written'; }
                        else { badge.classList.add('pending'); badge.textContent = 'Pending'; }
                    }
                    let rank = 0;
                    if (completed) rank = 3;
                    else if (sent) rank = 2;
                    else if (written) rank = 1;
                    row.dataset.sortStatus = String(rank);
                    row.classList.toggle('thanked', completed);
                    // When not completed, keep any "noname" row class intact;
                    // completed rows get the thanked background regardless.
                }

                function updateReceivedRowClass(row) {
                    const isRegistry = row.classList.contains('source-registry');
                    const received = row.dataset.received === 'yes';
                    row.classList.toggle('awaiting-delivery', isRegistry && !received);
                }

                function applyButtonState(btn, field, active, atDisplay) {
                    btn.classList.remove('btn-received', 'btn-thanks-written', 'btn-thanks-sent', 'btn-thanks-active');
                    if (active) {
                        btn.classList.add('btn-thanks-active');
                        if (field === 'received') {
                            btn.textContent = '✓ Received';
                            btn.title = atDisplay ? 'Received ' + atDisplay : 'Received';
                        } else if (field === 'written') {
                            btn.textContent = '✓ Written';
                        } else if (field === 'sent') {
                            btn.textContent = '✓ Sent';
                        }
                    } else {
                        if (field === 'received') {
                            btn.classList.add('btn-received');
                            btn.textContent = 'Mark Received';
                            btn.title = 'Mark gift as received';
                        } else if (field === 'written') {
                            btn.classList.add('btn-thanks-written');
                            btn.textContent = 'Mark Written';
                        } else if (field === 'sent') {
                            btn.classList.add('btn-thanks-sent');
                            btn.textContent = 'Mark Sent';
                        }
                    }
                }

                // Unmark a registry purchase (clears name + message, leaves
                // the item available on the public registry again).
                document.querySelectorAll('.js-unmark-purchased').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (btn.classList.contains('is-busy')) return;
                        const title = btn.dataset.itemTitle || 'this item';
                        if (!window.confirm('Unmark "' + title + '" as purchased? This clears the purchaser name and message and returns the item to the public registry. Thank-you and received tracking are kept in case this was accidental.')) {
                            return;
                        }
                        btn.classList.add('is-busy');
                        const id = parseInt(btn.dataset.id, 10);
                        const row = btn.closest('tr.gift-row');
                        fetch('/api/unmark-registry-purchase.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ item_id: id })
                        })
                        .then(function(resp) { return resp.json().then(function(data) { return { ok: resp.ok, data: data }; }); })
                        .then(function(result) {
                            if (result.ok && result.data && result.data.success) {
                                if (row && row.parentNode) {
                                    row.style.transition = 'opacity 0.25s';
                                    row.style.opacity = '0';
                                    setTimeout(function() {
                                        if (row.parentNode) row.parentNode.removeChild(row);
                                        // Remove from the filtered row list so the
                                        // visible-count stays accurate on re-filter.
                                        const idx = rows.indexOf(row);
                                        if (idx !== -1) rows.splice(idx, 1);
                                        applyFilter();
                                    }, 260);
                                }
                            } else {
                                btn.classList.remove('is-busy');
                                window.alert((result.data && result.data.error) || 'Could not unmark the purchase. Please try again.');
                            }
                        })
                        .catch(function() {
                            btn.classList.remove('is-busy');
                            window.alert('Network error unmarking the purchase.');
                        });
                    });
                });

                document.querySelectorAll('.js-toggle-status').forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (btn.classList.contains('is-busy')) return;
                        btn.classList.add('is-busy');

                        const source = btn.dataset.source;
                        const id = parseInt(btn.dataset.id, 10);
                        const field = btn.dataset.field;

                        fetch('/api/toggle-gift-status.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ source: source, id: id, field: field })
                        })
                        .then(function(resp) { return resp.json().then(function(data) { return { ok: resp.ok, data: data }; }); })
                        .then(function(result) {
                            if (result.ok && result.data && result.data.success) {
                                const active = !!result.data.active;
                                const row = btn.closest('tr.gift-row');
                                applyButtonState(btn, field, active, result.data.at_display);
                                if (row) {
                                    row.dataset[field] = active ? 'yes' : 'no';
                                    row.dataset['sort' + field.charAt(0).toUpperCase() + field.slice(1)] = active ? '1' : '0';
                                    if (field === 'received') updateReceivedRowClass(row);
                                    if (field === 'written' || field === 'sent') updateStatusBadge(row);
                                }
                                // Re-run filters so rows that no longer match hide
                                // without touching scroll position or sort order.
                                applyFilter();
                            } else {
                                // Fall back to the href navigation on failure
                                window.location.href = btn.href;
                            }
                        })
                        .catch(function() {
                            window.location.href = btn.href;
                        })
                        .finally(function() {
                            btn.classList.remove('is-busy');
                        });
                    });
                });
            }
        })();
    </script>
    <?php endif; ?>
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
