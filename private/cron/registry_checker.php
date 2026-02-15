<?php
/**
 * Registry Checker (CLI)
 *
 * Checks whether we are getting low on available registry items and emails admins.
 *
 * Run:
 *   php private/cron/registry_checker.php
 *
 * Env (in private/.env):
 * - REGISTRY_LOW_AVAILABLE_THRESHOLD: int, default 10
 * - REGISTRY_CHECK_COOLDOWN_HOURS: int, default 24
 * - REGISTRY_CHECK_RECIPIENTS: comma-separated emails (optional)
 * - REGISTRY_CHECK_DRY_RUN: "1" to avoid sending email
 */
 
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../email_handler.php';

function parseEmailList(?string $raw): array {
    if (!$raw) return [];
    $parts = preg_split('/[,\s]+/', $raw) ?: [];
    $emails = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower($p);
        }
    }
    return array_values(array_unique($emails));
}

function isoNowUtc(): string {
    $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    return $dt->format('c');
}

function hoursSince(?string $isoTimestamp): ?float {
    if (!$isoTimestamp) return null;
    try {
        $then = new DateTimeImmutable($isoTimestamp);
    } catch (Exception $e) {
        return null;
    }
    $now = new DateTimeImmutable('now', $then->getTimezone());
    $seconds = $now->getTimestamp() - $then->getTimestamp();
    if ($seconds < 0) return 0.0;
    return $seconds / 3600.0;
}

// Config
$threshold = (int)($_ENV['REGISTRY_LOW_AVAILABLE_THRESHOLD'] ?? 10);
if ($threshold < 0) $threshold = 0;

$cooldownHours = (int)($_ENV['REGISTRY_CHECK_COOLDOWN_HOURS'] ?? 24);
if ($cooldownHours < 0) $cooldownHours = 0;

$dryRun = (string)($_ENV['REGISTRY_CHECK_DRY_RUN'] ?? '') === '1';

// Recipients
$recipients = parseEmailList($_ENV['REGISTRY_CHECK_RECIPIENTS'] ?? null);
if (empty($recipients)) {
    // Reasonable defaults based on existing site behavior:
    // - RSVP_EMAIL (usually Melissa)
    // - MANDRILL_FROM_EMAIL / SMTP_FROM_EMAIL (usually Jacob)
    $fallback = [];
    $fallback[] = $_ENV['RSVP_EMAIL'] ?? null;
    $fallback[] = $_ENV['CONTACT_EMAIL'] ?? null;
    $fallback[] = $_ENV['MANDRILL_FROM_EMAIL'] ?? null;
    $fallback[] = $_ENV['SMTP_FROM_EMAIL'] ?? null;
    $recipients = parseEmailList(implode(',', array_filter($fallback)));
}

if (empty($recipients)) {
    fwrite(STDERR, "Registry checker: no valid recipients. Set REGISTRY_CHECK_RECIPIENTS in private/.env.\n");
    exit(2);
}

// State + lock (anti-spam + avoid concurrent runs)
$stateDir = __DIR__ . '/../cron_state';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0750, true);
}
$lockPath = $stateDir . '/registry_checker.lock';
$statePath = $stateDir . '/registry_checker_state.json';

$lockFp = @fopen($lockPath, 'c+');
if (!$lockFp) {
    fwrite(STDERR, "Registry checker: cannot open lock file at $lockPath\n");
    exit(3);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another run is in progress; treat as non-error for cron.
    echo "Registry checker: already running, exiting.\n";
    exit(0);
}

$prevState = [];
if (is_file($statePath)) {
    $raw = @file_get_contents($statePath);
    $decoded = json_decode($raw ?: '', true);
    if (is_array($decoded)) $prevState = $decoded;
}

try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("
        SELECT
            SUM(CASE WHEN published = 1 THEN 1 ELSE 0 END) AS total_published,
            SUM(CASE WHEN published = 1 AND purchased = 0 THEN 1 ELSE 0 END) AS available_published,
            SUM(CASE WHEN published = 1 AND purchased = 1 THEN 1 ELSE 0 END) AS purchased_published,
            SUM(CASE WHEN published = 0 THEN 1 ELSE 0 END) AS total_unpublished
        FROM registry_items
    ");
    $counts = $stmt->fetch() ?: [];
} catch (Exception $e) {
    fwrite(STDERR, "Registry checker: DB query failed: " . $e->getMessage() . "\n");
    exit(4);
}

$totalPublished = (int)($counts['total_published'] ?? 0);
$availablePublished = (int)($counts['available_published'] ?? 0);
$purchasedPublished = (int)($counts['purchased_published'] ?? 0);
$totalUnpublished = (int)($counts['total_unpublished'] ?? 0);

$isLow = $availablePublished <= $threshold;
$lastAvailable = isset($prevState['last_available_published']) ? (int)$prevState['last_available_published'] : null;
$wasLow = ($lastAvailable !== null) ? ($lastAvailable <= $threshold) : false;

$lastSentAt = $prevState['last_sent_at'] ?? null;
$hours = hoursSince($lastSentAt);
$cooldownPassed = ($hours === null) ? true : ($hours >= $cooldownHours);

$shouldSend = $isLow && (!$wasLow || $cooldownPassed);

// Compose email if needed
if ($shouldSend) {
    $subject = "[Wedding Registry] Low on available items ({$availablePublished} remaining)";
    $bodyLines = [];
    $bodyLines[] = "Registry is getting low on available items.";
    $bodyLines[] = "";
    $bodyLines[] = "Published items:";
    $bodyLines[] = "  - Total:     {$totalPublished}";
    $bodyLines[] = "  - Available: {$availablePublished}";
    $bodyLines[] = "  - Purchased: {$purchasedPublished}";
    if ($totalUnpublished > 0) {
        $bodyLines[] = "";
        $bodyLines[] = "Unpublished items (not shown publicly): {$totalUnpublished}";
    }
    $bodyLines[] = "";
    $bodyLines[] = "Threshold (available <=): {$threshold}";
    $bodyLines[] = "Checked at (UTC): " . isoNowUtc();
    $bodyLines[] = "";
    $bodyLines[] = "Add more items here:";
    $bodyLines[] = "  https://wedding.stephens.page/admin-registry";
    $body = implode("\n", $bodyLines);

    if ($dryRun) {
        echo "DRY RUN: would send email to: " . implode(', ', $recipients) . "\n";
        echo "Subject: $subject\n";
        echo $body . "\n";
    } else {
        $allOk = true;
        foreach ($recipients as $to) {
            $ok = sendEmail($to, $subject, $body);
            if (!$ok) $allOk = false;
        }

        if ($allOk) {
            $newState = [
                'last_sent_at' => isoNowUtc(),
                'last_available_published' => $availablePublished,
                'last_total_published' => $totalPublished,
                'last_checked_at' => isoNowUtc(),
            ];
            @file_put_contents($statePath, json_encode($newState, JSON_PRETTY_PRINT) . "\n", LOCK_EX);
            echo "Registry checker: email sent (" . implode(', ', $recipients) . ").\n";
        } else {
            fwrite(STDERR, "Registry checker: failed to send one or more emails.\n");
            exit(5);
        }
    }
} else {
    echo "Registry checker: OK (available={$availablePublished}, threshold={$threshold}).\n";
}

// Always update last_checked_at (even when not sending), so logs/state are useful.
$stateUpdate = $prevState;
$stateUpdate['last_checked_at'] = isoNowUtc();
$stateUpdate['last_available_published'] = $availablePublished;
$stateUpdate['last_total_published'] = $totalPublished;
@file_put_contents($statePath, json_encode($stateUpdate, JSON_PRETTY_PRINT) . "\n", LOCK_EX);

exit(0);

