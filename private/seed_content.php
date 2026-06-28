<?php
/**
 * Seed (or refresh) the site_content / content_blocks tables from
 * private/content_defaults.php.
 *
 * Usage:
 *   php private/seed_content.php            # insert only missing rows (safe)
 *   php private/seed_content.php --force    # overwrite existing rows from defaults
 *
 * Without --force this never clobbers content you have edited in the admin: it
 * inserts any key/section that is missing and leaves existing rows untouched.
 * Run create_content_tables.sql first.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/content.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is CLI-only.\n");
}

$force = in_array('--force', $argv, true);
$defaults = contentDefaults();

try {
    $pdo = getDbConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

$scalarsInserted = 0;
$scalarsUpdated = 0;

if ($force) {
    $scalarSql = "INSERT INTO site_content (setting_key, setting_value) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)";
} else {
    $scalarSql = "INSERT IGNORE INTO site_content (setting_key, setting_value) VALUES (?, ?)";
}
$scalarStmt = $pdo->prepare($scalarSql);

foreach (($defaults['scalars'] ?? []) as $key => $value) {
    $scalarStmt->execute([$key, $value]);
    if ($scalarStmt->rowCount() > 0) {
        // rowCount is 1 for an insert, 2 for an ON DUPLICATE KEY update.
        $force && $scalarStmt->rowCount() === 2 ? $scalarsUpdated++ : $scalarsInserted++;
    }
}

$blocksInserted = 0;
$blocksUpdated = 0;

if ($force) {
    $blockSql = "INSERT INTO content_blocks (page, section_key, heading, body, sort_order, published)
                 VALUES (?, ?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE heading = VALUES(heading), body = VALUES(body), sort_order = VALUES(sort_order)";
} else {
    $blockSql = "INSERT IGNORE INTO content_blocks (page, section_key, heading, body, sort_order, published)
                 VALUES (?, ?, ?, ?, ?, 1)";
}
$blockStmt = $pdo->prepare($blockSql);

foreach (($defaults['blocks'] ?? []) as $page => $blocks) {
    foreach ($blocks as $b) {
        $blockStmt->execute([
            $page,
            $b['section_key'],
            $b['heading'] ?? '',
            $b['body'] ?? '',
            $b['sort'] ?? 0,
        ]);
        if ($blockStmt->rowCount() > 0) {
            $force && $blockStmt->rowCount() === 2 ? $blocksUpdated++ : $blocksInserted++;
        }
    }
}

printf(
    "Seed complete (%s mode).\n  site_content:   %d inserted, %d updated\n  content_blocks: %d inserted, %d updated\n",
    $force ? 'force' : 'safe',
    $scalarsInserted,
    $scalarsUpdated,
    $blocksInserted,
    $blocksUpdated
);
