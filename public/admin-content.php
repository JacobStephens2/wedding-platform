<?php
/**
 * Admin: edit couple-specific site content.
 *
 * Edits the site_content (scalars) and content_blocks (page prose) tables that
 * the public pages read through the content() / contentBlocks() helpers. The
 * fallback values live in private/content_defaults.php.
 */

require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
require_once __DIR__ . '/../private/admin_auth.php';
require_once __DIR__ . '/../private/admin_sample.php';

session_start();

$error = '';
$success = '';
$sampleMode = isAdminSampleMode();
$authenticated = $sampleMode;

if (!$sampleMode && isAdminAuthenticated()) {
    $authenticated = true;
}

// Login
if (!$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && isset($_POST['admin_login'])) {
    if (trim($_POST['password']) === ($_ENV['ADMIN_PASSWORD'] ?? '')) {
        $_SESSION['admin_authenticated'] = true;
        $authenticated = true;
    } else {
        $error = 'Incorrect password. Please try again.';
    }
}

// Logout
if (!$sampleMode && isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin-content');
    exit;
}

// Field groups for the scalar editor. Keys must match content_defaults.php.
$scalarGroups = [
    'Couple' => [
        'couple_names'       => ['label' => 'Couple names (e.g. "Jacob & Melissa")'],
        'partner1_name'      => ['label' => 'Partner 1 first name'],
        'partner2_name'      => ['label' => 'Partner 2 first name'],
        'partner1_full_name' => ['label' => 'Partner 1 full name'],
        'partner2_full_name' => ['label' => 'Partner 2 full name'],
        'site_author_name'   => ['label' => 'Footer credit name'],
        'site_author_url'    => ['label' => 'Footer credit link (URL)'],
    ],
    'Event' => [
        'wedding_date'     => ['label' => 'Wedding date', 'type' => 'date'],
        'wedding_city'     => ['label' => 'City'],
        'ceremony_label'   => ['label' => 'Ceremony label (e.g. "Nuptial Mass")'],
        'ceremony_venue'   => ['label' => 'Ceremony venue'],
        'ceremony_address' => ['label' => 'Ceremony address'],
        'ceremony_time'    => ['label' => 'Ceremony time'],
        'reception_venue'  => ['label' => 'Reception venue'],
        'reception_address'=> ['label' => 'Reception address'],
        'reception_time'   => ['label' => 'Reception time'],
    ],
    'Contact & branding' => [
        'contact_email' => ['label' => 'Public contact email'],
        'theme_color'   => ['label' => 'Theme color (hex)'],
        'analytics_id'  => ['label' => 'Google Analytics ID (blank to disable)'],
    ],
    'Home & media' => [
        'home_video'        => ['label' => 'Home hero video (filename in private/videos)'],
        'home_poster'       => ['label' => 'Home hero poster (path in private/photos)'],
        'gallery_full_url'  => ['label' => 'Gallery: full gallery link'],
        'gallery_bw_url'    => ['label' => 'Gallery: B&W portraits link'],
        'wedding_video_url' => ['label' => 'Gallery: wedding video embed URL'],
        'wedding_video_url_2'=> ['label' => 'Gallery: second video embed URL'],
    ],
];

$pageOptions = ['story', 'about', 'travel', 'blessing'];

// Save scalars
if (!$sampleMode && $authenticated && ($_POST['action'] ?? '') === 'save_scalars') {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO site_content (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $count = 0;
        foreach ($scalarGroups as $fields) {
            foreach ($fields as $key => $meta) {
                if (array_key_exists($key, $_POST)) {
                    $stmt->execute([$key, trim((string) $_POST[$key])]);
                    $count++;
                }
            }
        }
        $success = "Saved {$count} site detail fields.";
    } catch (Exception $e) {
        $error = 'Error saving details: ' . htmlspecialchars($e->getMessage());
    }
}

// Save / add a block
if (!$sampleMode && $authenticated && ($_POST['action'] ?? '') === 'save_block') {
    try {
        $pdo = getDbConnection();
        $id = $_POST['block_id'] ?? '';
        $heading = trim((string) ($_POST['heading'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $sort = (int) ($_POST['sort_order'] ?? 0);
        $published = isset($_POST['published']) ? 1 : 0;

        if ($id !== '') {
            $stmt = $pdo->prepare(
                "UPDATE content_blocks SET heading = ?, body = ?, sort_order = ?, published = ? WHERE id = ?"
            );
            $stmt->execute([$heading, $body, $sort, $published, $id]);
            $success = 'Section updated.';
        } else {
            $page = $_POST['page'] ?? '';
            $sectionKey = trim((string) ($_POST['section_key'] ?? ''));
            if (!in_array($page, $pageOptions, true) || $sectionKey === '') {
                $error = 'A new section needs a valid page and a section key.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO content_blocks (page, section_key, heading, body, sort_order, published)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([$page, $sectionKey, $heading, $body, $sort, $published]);
                $success = 'Section added.';
            }
        }
    } catch (Exception $e) {
        if ((int) $e->getCode() === 23000) {
            $error = 'That page already has a section with this key.';
        } else {
            $error = 'Error saving section: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Delete a block
if (!$sampleMode && $authenticated && isset($_GET['delete_block'])) {
    try {
        $pdo = getDbConnection();
        $pdo->prepare("DELETE FROM content_blocks WHERE id = ?")->execute([$_GET['delete_block']]);
        $success = 'Section deleted.';
    } catch (Exception $e) {
        $error = 'Error deleting section: ' . htmlspecialchars($e->getMessage());
    }
}

// Load current values for display
$scalarValues = [];
$blocksByPage = array_fill_keys($pageOptions, []);
if ($authenticated && !$sampleMode) {
    try {
        $pdo = getDbConnection();
        $scalarValues = $pdo->query("SELECT setting_key, setting_value FROM site_content")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = $pdo->query(
            "SELECT id, page, section_key, heading, body, sort_order, published
             FROM content_blocks ORDER BY page, sort_order, id"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $blocksByPage[$r['page']][] = $r;
        }
    } catch (Exception $e) {
        $error = $error ?: ('Error loading content: ' . htmlspecialchars($e->getMessage()));
    }
}
// Fall back to defaults for any scalar without a row, so the form is always full.
$defaultScalars = contentDefaults()['scalars'] ?? [];
$scalarFor = static function (string $key) use ($scalarValues, $defaultScalars): string {
    if (array_key_exists($key, $scalarValues) && $scalarValues[$key] !== null) {
        return (string) $scalarValues[$key];
    }
    return (string) ($defaultScalars[$key] ?? '');
};

$page_title = "Site Content - " . content('couple_names', 'Our Wedding');
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
        .admin-container { max-width: 1000px; margin: 2rem auto; padding: 2rem; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .content-card {
            background-color: var(--color-surface);
            padding: 1.5rem 2rem 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--color-shadow);
            margin-bottom: 2rem;
        }
        .content-card h2 { color: var(--color-green); margin-bottom: 1rem; }
        .field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem 1.5rem; }
        .field-grid .form-group { margin: 0; }
        .field-group-title {
            grid-column: 1 / -1;
            font-family: 'Cinzel', serif;
            color: var(--color-gold);
            border-bottom: 1px solid var(--color-border);
            padding-bottom: 0.35rem;
            margin: 0.75rem 0 0.25rem;
        }
        .block-item { border: 1px solid var(--color-border); border-radius: 6px; padding: 1rem 1.25rem; margin-bottom: 1.25rem; }
        .block-item .block-meta { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.5rem; }
        .block-item .block-meta code { background: var(--color-light); padding: 0.15rem 0.4rem; border-radius: 4px; }
        .block-item textarea { width: 100%; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.85rem; line-height: 1.45; }
        .block-row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: flex-end; }
        .block-row .form-group { margin: 0; }
        .placeholder-hint { font-family: 'Crimson Text', serif; text-transform: none; font-size: 0.95rem; color: var(--color-text-secondary, #555); margin-bottom: 1rem; }
        .page-group > h2 { text-transform: capitalize; }
        details.add-block summary { cursor: pointer; color: var(--color-green); font-family: 'Cinzel', serif; }
    </style>
</head>
<body>
    <main class="page-container">
        <div class="admin-container">
            <?php renderAdminSampleBanner('Site Content Sample Mode'); ?>
            <div class="back-to-site"><a href="/">← Back to Main Site</a></div>

            <?php if (!$authenticated): ?>
                <div class="content-card">
                    <h1 class="page-title">Site Content</h1>
                    <?php if ($error): ?><div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif; ?>
                    <form method="POST" action="/admin-content">
                        <input type="hidden" name="admin_login" value="1">
                        <div class="form-group required">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required autofocus>
                        </div>
                        <button type="submit" class="btn">Login</button>
                    </form>
                </div>
            <?php elseif ($sampleMode): ?>
                <div class="content-card">
                    <h1 class="page-title">Site Content</h1>
                    <p class="placeholder-hint">Sample mode is read-only. Log in to the admin area to edit the couple's names, dates, venues, and page text.</p>
                </div>
            <?php else: ?>
                <div class="admin-header">
                    <h1 class="page-title">Site Content</h1>
                    <a href="/admin-content?logout=1" class="btn btn-secondary">Logout</a>
                </div>

                <?php if ($error): ?><div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div><?php endif; ?>
                <?php if ($success): ?><div class="alert alert-success"><p><?php echo htmlspecialchars($success); ?></p></div><?php endif; ?>

                <!-- Scalars -->
                <form method="POST" action="/admin-content" class="content-card">
                    <input type="hidden" name="action" value="save_scalars">
                    <h2>Site details</h2>
                    <p class="placeholder-hint">Names, date, venues, and links reused across every page.</p>
                    <div class="field-grid">
                        <?php foreach ($scalarGroups as $groupName => $fields): ?>
                            <div class="field-group-title"><?php echo htmlspecialchars($groupName); ?></div>
                            <?php foreach ($fields as $key => $meta): ?>
                                <div class="form-group">
                                    <label for="f_<?php echo $key; ?>"><?php echo htmlspecialchars($meta['label']); ?></label>
                                    <input type="<?php echo $meta['type'] ?? 'text'; ?>"
                                           id="f_<?php echo $key; ?>" name="<?php echo $key; ?>"
                                           value="<?php echo htmlspecialchars($scalarFor($key)); ?>">
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions" style="margin-top:1.5rem;">
                        <button type="submit" class="btn">Save site details</button>
                    </div>
                </form>

                <!-- Blocks -->
                <p class="placeholder-hint">
                    Page sections accept HTML. In the Story page you can drop a photo group inline with
                    <code>{{carousel:KEY}}</code> (swipeable) or <code>{{blockimages:KEY}}</code> (grid),
                    where <code>KEY</code> matches a photo's story section in Manage Gallery.
                </p>

                <?php foreach ($pageOptions as $page): ?>
                    <div class="content-card page-group">
                        <h2><?php echo htmlspecialchars($page); ?> page</h2>

                        <?php foreach ($blocksByPage[$page] as $b): ?>
                            <form method="POST" action="/admin-content" class="block-item">
                                <input type="hidden" name="action" value="save_block">
                                <input type="hidden" name="block_id" value="<?php echo (int) $b['id']; ?>">
                                <div class="block-meta">
                                    <span>Section <code><?php echo htmlspecialchars($b['section_key']); ?></code></span>
                                    <a href="/admin-content?delete_block=<?php echo (int) $b['id']; ?>"
                                       class="btn btn-small btn-delete" style="background:#dc3545;color:#fff;"
                                       onclick="return confirm('Delete this section?');">Delete</a>
                                </div>
                                <div class="block-row" style="margin-bottom:0.75rem;">
                                    <div class="form-group" style="flex:1 1 320px;">
                                        <label>Heading</label>
                                        <input type="text" name="heading" value="<?php echo htmlspecialchars((string) $b['heading']); ?>">
                                    </div>
                                    <div class="form-group" style="width:110px;">
                                        <label>Order</label>
                                        <input type="number" name="sort_order" value="<?php echo (int) $b['sort_order']; ?>">
                                    </div>
                                    <div class="form-group" style="width:120px;">
                                        <label><input type="checkbox" name="published" <?php echo $b['published'] ? 'checked' : ''; ?>> Published</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Body (HTML)</label>
                                    <textarea name="body" rows="10"><?php echo htmlspecialchars((string) $b['body']); ?></textarea>
                                </div>
                                <button type="submit" class="btn btn-small">Save section</button>
                            </form>
                        <?php endforeach; ?>

                        <details class="add-block">
                            <summary>+ Add a section to the <?php echo htmlspecialchars($page); ?> page</summary>
                            <form method="POST" action="/admin-content" class="block-item" style="margin-top:1rem;">
                                <input type="hidden" name="action" value="save_block">
                                <input type="hidden" name="page" value="<?php echo htmlspecialchars($page); ?>">
                                <div class="block-row" style="margin-bottom:0.75rem;">
                                    <div class="form-group" style="flex:1 1 220px;">
                                        <label>Section key (lowercase, no spaces)</label>
                                        <input type="text" name="section_key" placeholder="e.g. our_first_dance">
                                    </div>
                                    <div class="form-group" style="flex:1 1 220px;">
                                        <label>Heading</label>
                                        <input type="text" name="heading">
                                    </div>
                                    <div class="form-group" style="width:110px;">
                                        <label>Order</label>
                                        <input type="number" name="sort_order" value="0">
                                    </div>
                                    <div class="form-group" style="width:120px;">
                                        <label><input type="checkbox" name="published" checked> Published</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>Body (HTML)</label>
                                    <textarea name="body" rows="8"></textarea>
                                </div>
                                <button type="submit" class="btn btn-small">Add section</button>
                            </form>
                        </details>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
