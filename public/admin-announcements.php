<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
require_once __DIR__ . '/../private/admin_auth.php';
require_once __DIR__ . '/../private/admin_sample.php';
require_once __DIR__ . '/../private/email_handler.php';

session_start();

$error = '';
$success = '';
$infoMessage = '';
$sampleMode = isAdminSampleMode();
$authenticated = $sampleMode;

if (!$sampleMode && isAdminAuthenticated()) {
    $authenticated = true;
}

if (!$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    $password = trim($_POST['password'] ?? '');
    $adminPassword = $_ENV['ADMIN_PASSWORD'] ?? '';
    if ($password === $adminPassword) {
        $_SESSION['admin_authenticated'] = true;
        $authenticated = true;
    } else {
        $error = 'Incorrect password. Please try again.';
    }
}

if (!$sampleMode && isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin-announcements');
    exit;
}

$GUEST_AUDIENCES = [
    'all_with_email'    => 'All invited guests with email',
    'rsvp_yes'          => "RSVP'd Yes (any event)",
    'attending_ceremony'=> 'Attending Ceremony',
    'attending_reception'=> 'Attending Reception',
    'rsvp_no'           => "RSVP'd No",
    'no_response'       => 'No RSVP response yet',
    'rehearsal_invited' => 'Rehearsal-invited guests',
];
$CUSTOM_NEW_AUDIENCE = ['custom' => 'Custom recipients only'];

// $AUDIENCES = guest audiences + saved custom audiences + 'custom' option (assembled below).
$AUDIENCES = $GUEST_AUDIENCES;

function isCustomAudienceKey(string $audience): bool {
    return $audience === 'custom' || preg_match('/^custom_\d+$/', $audience) === 1;
}

function audienceSql(string $audience): ?string {
    if (isCustomAudienceKey($audience)) return null;
    switch ($audience) {
        case 'all_with_email':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != ''
                    ORDER BY last_name, first_name";
        case 'rsvp_yes':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND attending = 'yes'
                    ORDER BY last_name, first_name";
        case 'attending_ceremony':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND ceremony_attending = 'yes'
                    ORDER BY last_name, first_name";
        case 'attending_reception':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND reception_attending = 'yes'
                    ORDER BY last_name, first_name";
        case 'rsvp_no':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND attending = 'no'
                    ORDER BY last_name, first_name";
        case 'no_response':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND attending IS NULL
                    ORDER BY last_name, first_name";
        case 'rehearsal_invited':
            return "SELECT first_name, last_name, email FROM guests
                    WHERE email IS NOT NULL AND email != '' AND rehearsal_invited = 1
                    ORDER BY last_name, first_name";
    }
    return null;
}

function fetchAudience(PDO $pdo, string $audience): array {
    $sql = audienceSql($audience);
    if ($sql === null) return [];
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $seen = [];
    $out = [];
    foreach ($rows as $r) {
        $key = strtolower(trim($r['email']));
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $r;
    }
    return $out;
}

function renderPersonalized(string $template, array $recipient): string {
    $first = trim((string)($recipient['first_name'] ?? '')) ?: 'Friend';
    return str_replace(
        ['{{first_name}}', '{{last_name}}'],
        [$first, trim((string)($recipient['last_name'] ?? ''))],
        $template
    );
}

$DEFAULT_SUBJECT = 'Our wedding photos and video are here!';
$DEFAULT_BODY = "Hi {{first_name}},\n\n"
    . "Our wedding photos and the ceremony video are now up on the gallery: "
    . "<https://wedding.stephens.page/gallery>\n\n"
    . "Love,\nJacob & Melissa";

function renderMarkdownEmail(string $markdown): string {
    $parsedown = new Parsedown();
    $parsedown->setSafeMode(true);
    return $parsedown->text($markdown);
}

/**
 * Parse the custom-recipients textarea into [{first_name, last_name, email}, ...].
 * Accepts one entry per line (or comma-separated). Each entry is either
 * "email@example.com" or "First Last <email@example.com>". Invalid lines are
 * silently dropped; duplicates (by lowercase email) are collapsed.
 */
function parseCustomRecipients(string $raw): array {
    $tokens = preg_split('/[\r\n,]+/', $raw) ?: [];
    $out = [];
    $seen = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') continue;
        $first = '';
        $last  = '';
        $email = $token;
        if (preg_match('/^(.*?)\s*<\s*([^>]+?)\s*>\s*$/', $token, $m)) {
            $namePart = trim($m[1], " \t\"'");
            $email = trim($m[2]);
            if ($namePart !== '') {
                $parts = preg_split('/\s+/', $namePart, 2);
                $first = $parts[0] ?? '';
                $last  = $parts[1] ?? '';
            }
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $key = strtolower($email);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = ['first_name' => $first, 'last_name' => $last, 'email' => $email];
    }
    return $out;
}

// Load saved custom audiences from the DB and merge into $AUDIENCES so they're
// indistinguishable from built-ins for validation, rendering, and history labels.
$savedAudiences = [];
if ($authenticated && !$sampleMode) {
    try {
        $pdo = getDbConnection();
        $savedAudiences = $pdo->query("
            SELECT id, name, custom_recipients, updated_at
            FROM custom_audiences
            ORDER BY name COLLATE utf8mb4_general_ci, id
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $savedAudiences = [];
    }
    foreach ($savedAudiences as $sa) {
        $AUDIENCES['custom_' . $sa['id']] = $sa['name'];
    }
}
$AUDIENCES = $AUDIENCES + $CUSTOM_NEW_AUDIENCE; // 'custom' always appears last.

$savedAudiencesByKey = [];
foreach ($savedAudiences as $sa) {
    $savedAudiencesByKey['custom_' . $sa['id']] = $sa;
}

// Allow ?audience=<key> on GET to preselect an audience (used by the saved-audience
// links in the picker and by the post-save redirect).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['audience'])
    && isset($AUDIENCES[$_GET['audience']]) && !isset($_POST['audience'])) {
    $_POST['audience'] = $_GET['audience'];
}

// Draft tracking — survives across submissions via a hidden input.
$draftId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['draft_id']) && $_POST['draft_id'] !== '') {
        $draftId = (int)$_POST['draft_id'];
    }
} elseif (isset($_GET['draft'])) {
    $draftId = (int)$_GET['draft'];
}

$loadedDraftIncluded = null;

// If we arrived via GET ?draft=<id> (or a redirect from save), hydrate the form
// fields from the stored draft. POST submissions take precedence over draft state.
if ($draftId && $authenticated && !$sampleMode && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT * FROM announcement_drafts WHERE id = ?");
        $stmt->execute([$draftId]);
        $loaded = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($loaded) {
            $_POST['audience']          = $loaded['audience'];
            $_POST['subject']           = $loaded['subject'];
            $_POST['body']              = $loaded['body'];
            $_POST['from_name']         = $loaded['from_name'] ?? '';
            $_POST['reply_to']          = $loaded['reply_to'] ?? '';
            $_POST['custom_recipients'] = $loaded['custom_recipients'] ?? '';
            $includedDecoded = json_decode((string)($loaded['included_emails'] ?? ''), true);
            if (is_array($includedDecoded)) {
                $loadedDraftIncluded = $includedDecoded;
            }
            $infoMessage = 'Loaded draft from ' . date('M j, Y g:i a', strtotime($loaded['updated_at'])) . '.';
        } else {
            $draftId = null;
        }
    } catch (Exception $e) {
        $draftId = null;
    }
}

$audience      = $_POST['audience']  ?? 'all_with_email';
$subject       = $_POST['subject']   ?? $DEFAULT_SUBJECT;
$body          = $_POST['body']      ?? $DEFAULT_BODY;
// Body is authored in Markdown; we render to HTML on send and keep the
// Markdown source as the plain-text alt body.
$isHtml        = true;
$replyTo       = trim($_POST['reply_to'] ?? '');
$fromName      = trim($_POST['from_name'] ?? '') ?: 'Jacob and Melissa';
$testEmail     = trim($_POST['test_email'] ?? '');
$customRecipientsRaw = (string)($_POST['custom_recipients'] ?? '');
// If a saved custom audience is selected on initial GET and the user hasn't typed
// anything yet, preload the textarea with the saved audience's contents.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && $customRecipientsRaw === ''
    && isset($savedAudiencesByKey[$audience])) {
    $customRecipientsRaw = (string)$savedAudiencesByKey[$audience]['custom_recipients'];
}
$customRecipientsParsed = parseCustomRecipients($customRecipientsRaw);

// Track which saved audience the form is currently bound to so the "Save audience"
// section can switch between "Save as new" and "Update '<name>'".
$activeSavedAudience = $savedAudiencesByKey[$audience] ?? null;
$audienceName = trim((string)($_POST['audience_name'] ?? ($activeSavedAudience['name'] ?? '')));
$recipientsPreview = null;
$previewCount = null;

// Draft save/delete actions (handled before preview/test/send so they can short-circuit).
if ($authenticated && !$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && in_array($_POST['action'], ['save_draft', 'delete_draft'], true)
    && isset($AUDIENCES[$audience])) {
    try {
        $pdo = getDbConnection();
        if ($_POST['action'] === 'delete_draft') {
            $targetId = (int)($_POST['draft_id'] ?? 0);
            if ($targetId > 0) {
                $stmt = $pdo->prepare("DELETE FROM announcement_drafts WHERE id = ?");
                $stmt->execute([$targetId]);
            }
            // Drop draft context so the form resets.
            header('Location: /admin-announcements?draft_deleted=1');
            exit;
        } else { // save_draft
            $hasIncludeFilter = !empty($_POST['included_recipients_submitted'][$audience]);
            $includedJson = null;
            if ($hasIncludeFilter) {
                $rawIncluded = $_POST['included_recipients'][$audience] ?? [];
                if (!is_array($rawIncluded)) $rawIncluded = [];
                $includedJson = json_encode(array_values(array_unique(array_map(
                    fn($e) => strtolower(trim((string)$e)),
                    $rawIncluded
                ))));
            }
            $customForStorage = trim($customRecipientsRaw) !== '' ? $customRecipientsRaw : null;
            if ($draftId) {
                $stmt = $pdo->prepare("
                    UPDATE announcement_drafts
                    SET audience = ?, subject = ?, body = ?, from_name = ?, reply_to = ?,
                        included_emails = ?, custom_recipients = ?
                    WHERE id = ?
                ");
                $stmt->execute([$audience, $subject, $body, $fromName, $replyTo ?: null, $includedJson, $customForStorage, $draftId]);
                header('Location: /admin-announcements?draft=' . $draftId . '&draft_saved=1');
                exit;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO announcement_drafts (audience, subject, body, from_name, reply_to, included_emails, custom_recipients)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$audience, $subject, $body, $fromName, $replyTo ?: null, $includedJson, $customForStorage]);
                $newId = (int)$pdo->lastInsertId();
                header('Location: /admin-announcements?draft=' . $newId . '&draft_saved=1');
                exit;
            }
        }
    } catch (Exception $e) {
        $error = 'Draft action failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Saved-audience delete (its own per-button submit, fires before the action switch).
if ($authenticated && !$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['delete_audience_id']) && ctype_digit((string)$_POST['delete_audience_id'])) {
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("DELETE FROM custom_audiences WHERE id = ?");
        $stmt->execute([(int)$_POST['delete_audience_id']]);
        header('Location: /admin-announcements?audience_deleted=1');
        exit;
    } catch (Exception $e) {
        $error = 'Audience delete failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Saved-audience save/update.
if ($authenticated && !$sampleMode && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'save_audience') {
    try {
        $pdo = getDbConnection();
        $name = trim((string)($_POST['audience_name'] ?? ''));
        if ($name === '') {
            $error = 'Give the audience a name before saving.';
        } elseif (trim($customRecipientsRaw) === '') {
            $error = 'Type at least one email address before saving as an audience.';
        } else {
            $existingId = $activeSavedAudience['id'] ?? null;
            if ($existingId) {
                $stmt = $pdo->prepare("
                    UPDATE custom_audiences SET name = ?, custom_recipients = ? WHERE id = ?
                ");
                $stmt->execute([$name, $customRecipientsRaw, $existingId]);
                header('Location: /admin-announcements?audience=custom_' . (int)$existingId . '&audience_saved=1');
                exit;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO custom_audiences (name, custom_recipients) VALUES (?, ?)
                ");
                $stmt->execute([$name, $customRecipientsRaw]);
                $newId = (int)$pdo->lastInsertId();
                header('Location: /admin-announcements?audience=custom_' . $newId . '&audience_saved=1');
                exit;
            }
        }
    } catch (Exception $e) {
        $error = 'Audience save failed: ' . htmlspecialchars($e->getMessage());
    }
}

// Post-redirect notice flags.
if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['draft_saved'])) {
        $success = 'Draft saved.';
    } elseif (isset($_GET['draft_deleted'])) {
        $success = 'Draft deleted.';
    } elseif (isset($_GET['audience_saved'])) {
        $success = 'Audience saved.';
    } elseif (isset($_GET['audience_deleted'])) {
        $success = 'Audience deleted.';
    }
}

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($AUDIENCES[$audience])) {
        $error = 'Invalid audience selected.';
    } elseif (in_array($_POST['action'], ['preview', 'test_send', 'send_blast'], true)) {
        try {
            $pdo = getDbConnection();
            $recipients = fetchAudience($pdo, $audience);

            // Apply per-recipient inclusion filter from the audience checkboxes.
            // Marker hidden input distinguishes "user submitted an empty list (exclude everyone)"
            // from "user never interacted with checkboxes (include everyone)".
            $hasIncludeFilter = !empty($_POST['included_recipients_submitted'][$audience]);
            if ($hasIncludeFilter) {
                $rawIncluded = $_POST['included_recipients'][$audience] ?? [];
                if (!is_array($rawIncluded)) $rawIncluded = [];
                $includedSet = array_flip(array_map(
                    fn($e) => strtolower(trim((string)$e)),
                    $rawIncluded
                ));
                $recipients = array_values(array_filter($recipients, function ($r) use ($includedSet) {
                    return isset($includedSet[strtolower(trim($r['email']))]);
                }));
            }

            // Append custom recipients (admin-typed), deduped by email against the audience.
            if (!empty($customRecipientsParsed)) {
                $audienceEmailSet = array_flip(array_map(
                    fn($r) => strtolower(trim($r['email'])),
                    $recipients
                ));
                foreach ($customRecipientsParsed as $cr) {
                    $key = strtolower(trim($cr['email']));
                    if (!isset($audienceEmailSet[$key])) {
                        $recipients[] = $cr;
                        $audienceEmailSet[$key] = true;
                    }
                }
            }

            $previewCount = count($recipients);

            if ($_POST['action'] === 'preview') {
                $recipientsPreview = $recipients;
                $infoMessage = "Preview only — no email sent. " . $previewCount . " recipient" .
                    ($previewCount === 1 ? '' : 's') . ' in this audience.';
            } elseif ($_POST['action'] === 'test_send') {
                if ($subject === '' || $body === '') {
                    $error = 'Subject and body are required for a test send.';
                } elseif (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Provide a valid test email address.';
                } elseif ($sampleMode) {
                    $infoMessage = 'Sample mode — no test email actually sent.';
                } else {
                    $fakeRecipient = [
                        'first_name' => 'Test',
                        'last_name'  => 'Recipient',
                        'email'      => $testEmail,
                    ];
                    $renderedSubject  = renderPersonalized($subject, $fakeRecipient);
                    $renderedMarkdown = renderPersonalized($body, $fakeRecipient);
                    $renderedHtml     = renderMarkdownEmail($renderedMarkdown);
                    $opts = [
                        'isHtml'   => true,
                        'fromName' => $fromName,
                        'altBody'  => $renderedMarkdown,
                    ];
                    $ok = sendEmail($testEmail, '[TEST] ' . $renderedSubject, $renderedHtml,
                        $replyTo !== '' ? $replyTo : null, $opts);
                    if ($ok) {
                        $success = 'Test email sent to ' . htmlspecialchars($testEmail) . '.';
                    } else {
                        $error = 'Test send failed. Check server error log.';
                    }
                }
            } else { // send_blast
                if ($subject === '' || $body === '') {
                    $error = 'Subject and body are required.';
                } elseif ($previewCount === 0) {
                    $error = 'No recipients in this audience — nothing to send.';
                } elseif ($sampleMode) {
                    $infoMessage = 'Sample mode — no emails actually sent. Would have sent ' . $previewCount . '.';
                } else {
                    @set_time_limit(0);
                    ignore_user_abort(true);

                    $sent = 0;
                    $failed = 0;
                    $failedList = [];
                    foreach ($recipients as $r) {
                        $renderedSubject  = renderPersonalized($subject, $r);
                        $renderedMarkdown = renderPersonalized($body, $r);
                        $renderedHtml     = renderMarkdownEmail($renderedMarkdown);
                        $opts = [
                            'isHtml'   => true,
                            'fromName' => $fromName,
                            'altBody'  => $renderedMarkdown,
                        ];
                        $ok = sendEmail($r['email'], $renderedSubject, $renderedHtml,
                            $replyTo !== '' ? $replyTo : null, $opts);
                        if ($ok) {
                            $sent++;
                        } else {
                            $failed++;
                            $failedList[] = trim($r['first_name'] . ' ' . $r['last_name']) . ' <' . $r['email'] . '>';
                        }
                        usleep(150000); // 150ms between sends to be polite to SMTP
                    }

                    try {
                        $logStmt = $pdo->prepare("
                            INSERT INTO email_blasts (audience, subject, body, body_is_html, reply_to,
                                recipient_count, sent_count, failed_count, failed_recipients, custom_recipients)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $logStmt->execute([
                            $audience,
                            $subject,
                            $body,
                            0, // body column stores Markdown source; rendered to HTML on send
                            $replyTo !== '' ? $replyTo : null,
                            $previewCount,
                            $sent,
                            $failed,
                            $failed > 0 ? implode("\n", $failedList) : null,
                            trim($customRecipientsRaw) !== '' ? $customRecipientsRaw : null,
                        ]);
                    } catch (Exception $e) {
                        error_log('email_blasts log insert failed: ' . $e->getMessage());
                    }

                    if ($failed === 0) {
                        $success = "Sent to all $sent recipients.";
                    } else {
                        $success = "Sent to $sent recipient" . ($sent === 1 ? '' : 's') .
                            "; $failed failure" . ($failed === 1 ? '' : 's') . '.';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

$history = [];
$drafts = [];
if ($authenticated && !$sampleMode) {
    try {
        $pdo = getDbConnection();
        $history = $pdo->query("
            SELECT id, audience, subject, body, body_is_html, reply_to,
                   recipient_count, sent_count, failed_count, failed_recipients,
                   custom_recipients, sent_at
            FROM email_blasts
            ORDER BY sent_at DESC
            LIMIT 50
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist yet — silently skip.
    }
    try {
        $pdo = getDbConnection();
        $drafts = $pdo->query("
            SELECT id, audience, subject, updated_at
            FROM announcement_drafts
            ORDER BY updated_at DESC
            LIMIT 20
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table may not exist yet — silently skip.
    }
}

$audienceCounts = [];
$audienceRecipients = [];
if ($authenticated) {
    try {
        $pdo = getDbConnection();
        foreach (array_keys($AUDIENCES) as $key) {
            $recipients = fetchAudience($pdo, $key);
            $audienceRecipients[$key] = $recipients;
            $audienceCounts[$key] = count($recipients);
        }
    } catch (Exception $e) {
        // ignore
    }
}

$page_title = "Announcements - Admin";
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
        $cssPath = __DIR__ . '/css/style.css';
        echo file_exists($cssPath) ? filemtime($cssPath) : time();
    ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400&family=Beloved+Script&family=Crimson+Text:ital,wght@0,400;1,400&display=swap" rel="stylesheet">
    <style>
        .blast-container {
            max-width: 1100px;
            margin: 2rem auto;
            padding: 0 1.5rem 4rem;
        }
        .blast-card {
            background: var(--color-surface);
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--color-shadow);
            padding: 1.75rem;
            margin-bottom: 1.5rem;
        }
        .blast-card h2 {
            font-family: 'Cinzel', serif;
            font-size: 1.35rem;
            margin: 0 0 1rem;
            color: var(--color-green);
        }
        .audience-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 0.6rem;
            margin-bottom: 0.5rem;
        }
        .audience-option {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.65rem 0.85rem;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            cursor: pointer;
            font-family: 'Crimson Text', serif;
            font-size: 1rem;
            background: var(--color-bg);
            transition: border-color 0.2s, background-color 0.2s;
        }
        .audience-option:hover {
            border-color: var(--color-green);
        }
        .audience-option input[type="radio"] {
            margin: 0;
        }
        .audience-option.is-selected {
            border-color: var(--color-green);
            background: rgba(46, 80, 22, 0.06);
        }
        .audience-option .count-badge {
            margin-left: auto;
            font-size: 0.85rem;
            color: var(--color-text-secondary);
            background: var(--color-surface);
            padding: 0.1rem 0.5rem;
            border-radius: 999px;
        }
        .form-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .form-row > div { flex: 1 1 240px; }
        .blast-container label {
            display: block;
            font-family: 'Crimson Text', serif;
            font-weight: 600;
            margin-bottom: 0.35rem;
            color: var(--color-dark);
        }
        .blast-container input[type="text"],
        .blast-container input[type="email"],
        .blast-container textarea,
        .blast-container select {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            font-family: 'Crimson Text', serif;
            font-size: 1rem;
            background: var(--color-bg);
            color: var(--color-dark);
            box-sizing: border-box;
        }
        .blast-container textarea {
            min-height: 240px;
            font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
            font-size: 0.95rem;
            line-height: 1.45;
            resize: vertical;
        }
        .md-editor {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            align-items: stretch;
        }
        .md-editor textarea {
            min-height: 320px;
            margin: 0;
        }
        .md-preview {
            min-height: 320px;
            padding: 0.85rem 1rem;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            background: var(--color-bg);
            font-family: 'Crimson Text', Georgia, serif;
            font-size: 1rem;
            line-height: 1.5;
            color: var(--color-dark);
            overflow-y: auto;
        }
        .md-preview p { margin: 0 0 0.85rem; }
        .md-preview p:last-child { margin-bottom: 0; }
        .md-preview a { color: var(--color-green); }
        .md-preview ul, .md-preview ol { margin: 0 0 0.85rem 1.25rem; padding: 0; }
        .md-preview blockquote {
            margin: 0 0 0.85rem;
            padding-left: 0.85rem;
            border-left: 3px solid var(--color-border);
            color: var(--color-text-secondary);
        }
        @media (max-width: 720px) {
            .md-editor { grid-template-columns: 1fr; }
        }
        .helper-text {
            font-family: 'Crimson Text', serif;
            font-size: 0.9rem;
            color: var(--color-text-secondary);
            margin: 0.25rem 0 0;
        }
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.5rem 0 1rem;
            font-family: 'Crimson Text', serif;
        }
        .btn-row {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            margin-top: 1.25rem;
        }
        .btn {
            padding: 0.7rem 1.4rem;
            border: none;
            border-radius: 4px;
            background: var(--color-green);
            color: white;
            cursor: pointer;
            font-family: 'Cinzel', serif;
            letter-spacing: 0.05em;
            font-size: 0.95rem;
            transition: background-color 0.2s;
        }
        .btn:hover { background: var(--color-gold); }
        .btn-secondary { background: var(--color-lavender); }
        .btn-secondary:hover { background: var(--color-gold); }
        .btn-danger { background: #b03030; }
        .btn-danger:hover { background: #8a2424; }
        .btn-outline {
            background: transparent;
            color: var(--color-green);
            border: 1px solid var(--color-green);
        }
        .btn-outline:hover { background: rgba(46, 80, 22, 0.08); color: var(--color-green); }
        .drafts-list {
            list-style: none;
            padding: 0;
            margin: 0.75rem 0 0;
        }
        .draft-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0.85rem;
            border: 1px solid var(--color-border);
            border-radius: 6px;
            margin-bottom: 0.5rem;
            background: var(--color-bg);
        }
        .draft-row.is-current { border-color: var(--color-green); }
        .draft-link {
            flex: 1;
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 0.75rem;
            align-items: center;
            color: var(--color-dark);
            text-decoration: none;
            font-family: 'Crimson Text', serif;
            font-size: 0.95rem;
        }
        .draft-link:hover .draft-subject { color: var(--color-green); }
        .draft-subject { font-weight: 600; }
        .draft-when { color: var(--color-text-secondary); font-size: 0.9rem; white-space: nowrap; }
        .draft-badge {
            background: var(--color-green);
            color: white;
            font-size: 0.75rem;
            padding: 0.1rem 0.45rem;
            border-radius: 999px;
            letter-spacing: 0.04em;
        }
        .draft-delete-form { margin: 0; }
        .btn-link-danger {
            background: none;
            border: 0;
            padding: 0;
            color: #b03030;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9rem;
            text-decoration: underline;
        }
        .btn-link-danger:hover { color: #8a2424; }
        @media (max-width: 720px) {
            .draft-link { grid-template-columns: 1fr; gap: 0.25rem; }
        }
        .audience-subhead {
            font-family: 'Cinzel', serif;
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--color-text-secondary);
            margin: 1.25rem 0 0.5rem;
        }
        .audience-option-row {
            display: flex;
            gap: 0.4rem;
            align-items: stretch;
        }
        .audience-option-row .audience-option { flex: 1; }
        .audience-delete-btn {
            background: transparent;
            border: 1px solid var(--color-border);
            color: var(--color-text-secondary);
            border-radius: 6px;
            padding: 0 0.6rem;
            cursor: pointer;
            font-size: 1.1rem;
            line-height: 1;
            transition: color 0.2s, border-color 0.2s, background-color 0.2s;
        }
        .audience-delete-btn:hover {
            color: #b03030;
            border-color: #b03030;
            background: rgba(176, 48, 48, 0.06);
        }
        .save-audience-row {
            display: flex;
            gap: 0.6rem;
            align-items: end;
            flex-wrap: wrap;
            margin-top: 0.85rem;
            padding: 0.85rem 1rem;
            background: var(--color-bg);
            border: 1px dashed var(--color-border);
            border-radius: 6px;
        }
        .save-audience-row label {
            font-family: 'Crimson Text', serif;
            font-weight: 600;
            white-space: nowrap;
        }
        .audience-emails {
            margin-top: 1rem;
            border-top: 1px solid var(--color-border);
            padding-top: 0.85rem;
        }
        .audience-emails summary {
            cursor: pointer;
            font-family: 'Crimson Text', serif;
            color: var(--color-green);
            user-select: none;
        }
        .audience-emails summary:hover { color: var(--color-gold); }
        .audience-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0.75rem 0 0;
            font-family: 'Crimson Text', serif;
            font-size: 0.9rem;
        }
        .audience-actions .btn-link {
            background: none;
            border: 0;
            padding: 0;
            color: var(--color-green);
            cursor: pointer;
            font: inherit;
            text-decoration: underline;
        }
        .audience-actions .btn-link:hover { color: var(--color-gold); }
        .audience-actions .action-sep { color: var(--color-text-secondary); }
        .audience-selected-count {
            margin-left: auto;
            color: var(--color-text-secondary);
        }
        .recipient-preview tr.is-excluded td:not(:first-child) {
            color: var(--color-text-secondary);
            text-decoration: line-through;
        }
        .recipient-preview {
            max-height: 320px;
            overflow-y: auto;
            border: 1px solid var(--color-border);
            border-radius: 6px;
        }
        .recipient-preview table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Crimson Text', serif;
        }
        .recipient-preview th, .recipient-preview td {
            text-align: left;
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--color-border);
            font-size: 0.95rem;
        }
        .recipient-preview th {
            background: var(--color-bg);
            position: sticky;
            top: 0;
        }
        .history-list {
            list-style: none;
            padding: 0;
            margin: 0.75rem 0 0;
        }
        .history-item {
            border: 1px solid var(--color-border);
            border-radius: 6px;
            margin-bottom: 0.5rem;
            background: var(--color-bg);
        }
        .history-item details > summary {
            list-style: none;
            display: grid;
            grid-template-columns: 11rem auto 1fr auto;
            gap: 0.75rem;
            align-items: center;
            padding: 0.7rem 0.9rem;
            cursor: pointer;
            font-family: 'Crimson Text', serif;
            font-size: 0.95rem;
        }
        .history-item details > summary::-webkit-details-marker { display: none; }
        .history-item details[open] > summary { border-bottom: 1px solid var(--color-border); }
        .history-when { color: var(--color-text-secondary); white-space: nowrap; }
        .history-subject { font-weight: 600; color: var(--color-dark); }
        .history-stats { color: var(--color-text-secondary); white-space: nowrap; font-size: 0.9rem; }
        .history-failed { color: #b03030; font-weight: 600; }
        .history-detail {
            padding: 0.9rem 1rem 1rem;
            font-family: 'Crimson Text', serif;
        }
        .history-body-rendered {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 0.85rem 1rem;
            font-size: 1rem;
            line-height: 1.5;
            color: var(--color-dark);
        }
        .history-body-rendered p { margin: 0 0 0.85rem; }
        .history-body-rendered p:last-child { margin-bottom: 0; }
        .history-body-rendered a { color: var(--color-green); }
        .history-failed-list { margin-top: 0.85rem; }
        .history-failed-list summary {
            cursor: pointer;
            color: #b03030;
            font-family: 'Crimson Text', serif;
        }
        .history-failed-list pre {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: 6px;
            padding: 0.75rem 1rem;
            font-size: 0.85rem;
            white-space: pre-wrap;
            margin: 0.5rem 0 0;
        }
        @media (max-width: 720px) {
            .history-item details > summary {
                grid-template-columns: 1fr;
                gap: 0.35rem;
            }
        }
        .audience-tag {
            display: inline-block;
            background: var(--color-bg);
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 0.1rem 0.5rem;
            font-size: 0.8rem;
        }
        .alert {
            padding: 0.85rem 1.1rem;
            border-radius: 6px;
            margin-bottom: 1.25rem;
            font-family: 'Crimson Text', serif;
        }
        .alert-error { background: #fdecea; color: #7a1f1f; border: 1px solid #f4b6b3; }
        .alert-success { background: #e7f5e7; color: #1f5a1f; border: 1px solid #b2dab2; }
        .alert-info { background: #eef2f9; color: #1f3a78; border: 1px solid #b6c4e3; }
        .token-hint code {
            background: var(--color-bg);
            padding: 0.05rem 0.35rem;
            border-radius: 3px;
            font-size: 0.85em;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/admin_menu.php'; ?>
    <main class="blast-container">
        <?php renderAdminSampleBanner('Announcements Sample Mode'); ?>

        <?php if (!$authenticated): ?>
            <div class="blast-card" style="max-width: 480px; margin: 4rem auto;">
                <h2>Admin Login</h2>
                <?php if ($error): ?>
                    <div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div>
                <?php endif; ?>
                <form method="POST" action="/admin-announcements">
                    <input type="hidden" name="admin_login" value="1">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required autofocus>
                    <div class="btn-row"><button type="submit" class="btn">Login</button></div>
                </form>
            </div>
        <?php else: ?>
            <h1 class="page-title" style="text-align:center; margin-bottom: 1.5rem;">Announcements</h1>
            <p style="text-align:center; font-family:'Crimson Text', serif; color: var(--color-text-secondary); margin: -0.5rem 0 1.5rem;">
                Send an email to a chosen group of guests.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error"><p><?php echo htmlspecialchars($error); ?></p></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><p><?php echo htmlspecialchars($success); ?></p></div>
            <?php endif; ?>
            <?php if ($infoMessage): ?>
                <div class="alert alert-info"><p><?php echo htmlspecialchars($infoMessage); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($drafts)): ?>
                <div class="blast-card">
                    <h2>Drafts</h2>
                    <ul class="drafts-list">
                        <?php foreach ($drafts as $d):
                            $isCurrent = $draftId && (int)$d['id'] === (int)$draftId;
                        ?>
                            <li class="draft-row<?php echo $isCurrent ? ' is-current' : ''; ?>">
                                <a class="draft-link" href="/admin-announcements?draft=<?php echo (int)$d['id']; ?>">
                                    <span class="draft-subject">
                                        <?php echo $d['subject'] !== ''
                                            ? htmlspecialchars($d['subject'])
                                            : '<em style="color:var(--color-text-secondary);">(no subject)</em>'; ?>
                                    </span>
                                    <span class="audience-tag"><?php echo htmlspecialchars($AUDIENCES[$d['audience']] ?? $d['audience']); ?></span>
                                    <span class="draft-when">Saved <?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($d['updated_at']))); ?></span>
                                    <?php if ($isCurrent): ?><span class="draft-badge">editing</span><?php endif; ?>
                                </a>
                                <form method="POST" action="/admin-announcements" class="draft-delete-form"
                                      onsubmit="return confirm('Delete this draft? This cannot be undone.');">
                                    <input type="hidden" name="action" value="delete_draft">
                                    <input type="hidden" name="draft_id" value="<?php echo (int)$d['id']; ?>">
                                    <input type="hidden" name="audience" value="<?php echo htmlspecialchars($d['audience']); ?>">
                                    <button type="submit" class="btn-link btn-link-danger" aria-label="Delete draft">Delete</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="/admin-announcements<?php echo $sampleMode ? '?sample=1' : ''; ?>" id="blast-form">
                <input type="hidden" name="draft_id" value="<?php echo $draftId ? (int)$draftId : ''; ?>">
                <?php if ($draftId): ?>
                    <div class="alert alert-info" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                        <span>Editing draft <strong>#<?php echo (int)$draftId; ?></strong>. Saving will overwrite it. <a href="/admin-announcements">Start a new draft</a> instead.</span>
                    </div>
                <?php endif; ?>
                <div class="blast-card">
                    <h2>1. Pick an audience</h2>
                    <div class="audience-grid">
                        <?php foreach ($GUEST_AUDIENCES as $key => $label):
                            $count = $audienceCounts[$key] ?? null;
                            $selected = $audience === $key;
                        ?>
                            <label class="audience-option<?php echo $selected ? ' is-selected' : ''; ?>">
                                <input type="radio" name="audience" value="<?php echo htmlspecialchars($key); ?>"
                                       <?php echo $selected ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($label); ?></span>
                                <?php if ($count !== null): ?>
                                    <span class="count-badge"><?php echo (int)$count; ?></span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <?php if (!empty($savedAudiences)): ?>
                        <h3 class="audience-subhead">Saved audiences</h3>
                        <div class="audience-grid">
                            <?php foreach ($savedAudiences as $sa):
                                $key = 'custom_' . $sa['id'];
                                $count = count(parseCustomRecipients((string)$sa['custom_recipients']));
                                $selected = $audience === $key;
                            ?>
                                <div class="audience-option-row">
                                    <label class="audience-option<?php echo $selected ? ' is-selected' : ''; ?>">
                                        <input type="radio" name="audience" value="<?php echo htmlspecialchars($key); ?>"
                                               data-saved-name="<?php echo htmlspecialchars($sa['name']); ?>"
                                               data-saved-emails="<?php echo htmlspecialchars($sa['custom_recipients']); ?>"
                                               <?php echo $selected ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars($sa['name']); ?></span>
                                        <span class="count-badge"><?php echo (int)$count; ?></span>
                                    </label>
                                    <button type="submit" name="delete_audience_id" value="<?php echo (int)$sa['id']; ?>"
                                            class="audience-delete-btn" formnovalidate
                                            onclick="return confirm('Delete saved audience &quot;<?php echo htmlspecialchars(addslashes($sa['name']), ENT_QUOTES); ?>&quot;? This cannot be undone.');"
                                            aria-label="Delete <?php echo htmlspecialchars($sa['name']); ?>">&times;</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="audience-subhead">Type your own</h3>
                    <div class="audience-grid">
                        <?php $key = 'custom'; $selected = $audience === $key; ?>
                        <label class="audience-option<?php echo $selected ? ' is-selected' : ''; ?>">
                            <input type="radio" name="audience" value="<?php echo htmlspecialchars($key); ?>"
                                   <?php echo $selected ? 'checked' : ''; ?>>
                            <span>Custom recipients only</span>
                            <span class="count-badge"><?php echo isCustomAudienceKey($audience) ? count($customRecipientsParsed) : 0; ?></span>
                        </label>
                    </div>
                    <p class="helper-text">Guest audiences count only guests with an email on file. Plus-ones share their primary guest's email. Choose "Custom recipients only" to type your own list, or pick a Saved audience to reuse one you've stored before.</p>

                    <?php foreach ($AUDIENCES as $key => $label):
                        $recipients = $audienceRecipients[$key] ?? [];
                        $count = count($recipients);
                        $emailsCsv = implode(', ', array_map(function($r) { return $r['email']; }, $recipients));

                        // Restore prior checkbox state if user has submitted this audience already,
                        // OR if we're loading a draft whose audience matches this panel.
                        $submittedIncluded = $_POST['included_recipients'][$key] ?? null;
                        $hasSubmitted = is_array($submittedIncluded);
                        if (!$hasSubmitted && $loadedDraftIncluded !== null && $key === $audience) {
                            $submittedIncluded = $loadedDraftIncluded;
                            $hasSubmitted = true;
                        }
                        $includedSet = $hasSubmitted
                            ? array_flip(array_map(fn($e) => strtolower(trim((string)$e)), $submittedIncluded))
                            : null;
                    ?>
                        <details class="audience-emails" data-audience="<?php echo htmlspecialchars($key); ?>"
                                 <?php echo $audience === $key ? '' : 'hidden'; ?>>
                            <summary>View email addresses in this audience (<?php echo (int)$count; ?>)</summary>
                            <?php if ($count === 0): ?>
                                <p class="helper-text" style="margin-top:0.75rem;">
                                    <?php if (isCustomAudienceKey($key)): ?>
                                        This audience sends only to the addresses in the <strong>Recipients</strong> field below.
                                    <?php else: ?>
                                        No one matches this audience.
                                    <?php endif; ?>
                                </p>
                            <?php else: ?>
                                <!-- Marker: tells the server that checkbox state for this audience was submitted (so empty = no one selected, vs no marker = include everyone). -->
                                <input type="hidden" name="included_recipients_submitted[<?php echo htmlspecialchars($key); ?>]" value="1">

                                <div class="audience-actions">
                                    <button type="button" class="btn-link" data-action="select-all" data-audience="<?php echo htmlspecialchars($key); ?>">Select all</button>
                                    <span class="action-sep">·</span>
                                    <button type="button" class="btn-link" data-action="select-none" data-audience="<?php echo htmlspecialchars($key); ?>">Select none</button>
                                    <span class="audience-selected-count" data-audience-count="<?php echo htmlspecialchars($key); ?>"></span>
                                </div>
                                <div class="recipient-preview" style="margin-top:0.5rem;">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th style="width:2.2rem; text-align:center;">
                                                    <input type="checkbox" class="audience-toggle-all"
                                                           data-audience="<?php echo htmlspecialchars($key); ?>"
                                                           aria-label="Toggle all in this audience">
                                                </th>
                                                <th>Name</th>
                                                <th>Email</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($recipients as $r):
                                            $emailLower = strtolower(trim($r['email']));
                                            $checked = $hasSubmitted ? isset($includedSet[$emailLower]) : true;
                                        ?>
                                            <tr<?php echo $checked ? '' : ' class="is-excluded"'; ?>>
                                                <td style="text-align:center;">
                                                    <input type="checkbox"
                                                           class="audience-recipient-toggle"
                                                           data-audience="<?php echo htmlspecialchars($key); ?>"
                                                           name="included_recipients[<?php echo htmlspecialchars($key); ?>][]"
                                                           value="<?php echo htmlspecialchars($r['email']); ?>"
                                                           <?php echo $checked ? 'checked' : ''; ?>>
                                                </td>
                                                <td><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                                                <td><?php echo htmlspecialchars($r['email']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <label style="margin-top:0.85rem;">Comma-separated email list (for copy/paste)</label>
                                <textarea readonly onclick="this.select()" style="min-height:80px; font-size:0.85rem;"><?php echo htmlspecialchars($emailsCsv); ?></textarea>
                            <?php endif; ?>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div class="blast-card">
                    <h2>2. Compose</h2>
                    <div class="form-row">
                        <div>
                            <label for="from_name">From name</label>
                            <input type="text" id="from_name" name="from_name"
                                   value="<?php echo htmlspecialchars($fromName); ?>"
                                   placeholder="Jacob and Melissa">
                        </div>
                        <div>
                            <label for="reply_to">Reply-To (optional)</label>
                            <input type="email" id="reply_to" name="reply_to"
                                   value="<?php echo htmlspecialchars($replyTo); ?>"
                                   placeholder="you@example.com">
                        </div>
                    </div>
                    <label for="subject">Subject</label>
                    <input type="text" id="subject" name="subject"
                           value="<?php echo htmlspecialchars($subject); ?>"
                           placeholder="Our wedding photos and video are here!">

                    <label for="body" style="margin-top:1rem;">Body <small style="font-weight:400; color:var(--color-text-secondary);">(Markdown — paragraphs, **bold**, *italic*, [links](https://...), lists, &gt; quotes)</small></label>
                    <div class="md-editor">
                        <textarea id="body" name="body" spellcheck="true"><?php echo htmlspecialchars($body); ?></textarea>
                        <div class="md-preview" id="body-preview" aria-live="polite"><?php echo renderMarkdownEmail($body); ?></div>
                    </div>
                    <p class="helper-text token-hint">
                        Personalization: type <code>{{first_name}}</code> or <code>{{last_name}}</code> anywhere in the body —
                        they'll be replaced per recipient when the email is sent.
                    </p>

                    <?php $isCustomAud = isCustomAudienceKey($audience); ?>
                    <label for="custom_recipients" id="custom_recipients_label" style="margin-top:1rem;">
                        <?php echo $isCustomAud ? 'Recipients' : 'Additional recipients (optional)'; ?>
                    </label>
                    <textarea id="custom_recipients" name="custom_recipients" spellcheck="false"
                              placeholder="one per line, or comma-separated&#10;email@example.com&#10;First Last <other@example.com>"
                              style="min-height:90px;"><?php echo htmlspecialchars($customRecipientsRaw); ?></textarea>
                    <p class="helper-text">
                        Each line can be either <code>email@example.com</code> or
                        <code>First Last &lt;email@example.com&gt;</code>.
                        <span id="recipients-helper-guest"<?php echo $isCustomAud ? ' hidden' : ''; ?>>
                            These are added on top of the audience above and de-duplicated by email.
                        </span>
                        <span id="recipients-helper-custom"<?php echo $isCustomAud ? '' : ' hidden'; ?>>
                            This audience sends only to the addresses you type here.
                        </span>
                        Without a name, <code>{{first_name}}</code> falls back to "Friend".
                        <?php if (!empty($customRecipientsParsed)): ?>
                            <br><strong><?php echo count($customRecipientsParsed); ?></strong> valid address<?php
                                echo count($customRecipientsParsed) === 1 ? '' : 'es'; ?> recognized.
                        <?php endif; ?>
                    </p>

                    <div class="save-audience-row" id="save-audience-row"<?php echo $isCustomAud ? '' : ' hidden'; ?>>
                        <label for="audience_name" style="margin:0;">Audience name</label>
                        <input type="text" id="audience_name" name="audience_name"
                               value="<?php echo htmlspecialchars($audienceName); ?>"
                               placeholder="e.g., Bridal party, Out-of-town family"
                               style="flex:1; min-width:14rem;">
                        <button type="submit" name="action" value="save_audience" id="save-audience-btn" class="btn btn-outline" formnovalidate>
                            <?php echo $activeSavedAudience
                                ? 'Update "' . htmlspecialchars($activeSavedAudience['name']) . '"'
                                : 'Save as audience'; ?>
                        </button>
                    </div>
                    <p class="helper-text" id="save-audience-helper"<?php echo $isCustomAud ? '' : ' hidden'; ?>>
                        Saved audiences show up in the picker above so you can reuse them on future announcements.
                    </p>
                </div>

                <div class="blast-card">
                    <h2>3. Send</h2>
                    <div class="form-row">
                        <div>
                            <label for="test_email">Send a test to (optional)</label>
                            <input type="email" id="test_email" name="test_email"
                                   value="<?php echo htmlspecialchars($testEmail); ?>"
                                   placeholder="<?php echo htmlspecialchars($_ENV['MANDRILL_FROM_EMAIL'] ?? 'you@example.com'); ?>">
                            <p class="helper-text">A single test email goes to this address — subject is prefixed with [TEST].</p>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" name="action" value="save_draft" class="btn btn-outline" id="save-draft-btn">
                            <?php echo $draftId ? 'Update draft' : 'Save as draft'; ?>
                        </button>
                        <button type="submit" name="action" value="preview" class="btn btn-secondary">Preview recipients</button>
                        <button type="submit" name="action" value="test_send" class="btn btn-secondary">Send test only</button>
                        <button type="submit" name="action" value="send_blast" class="btn btn-danger" id="send-blast-btn">
                            Send to all recipients
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($recipientsPreview !== null): ?>
                <div class="blast-card">
                    <h2>Preview: <?php echo (int)$previewCount; ?> recipient<?php echo $previewCount === 1 ? '' : 's'; ?></h2>
                    <?php if ($previewCount === 0): ?>
                        <p>No one matches this audience.</p>
                    <?php else: ?>
                        <div class="recipient-preview">
                            <table>
                                <thead>
                                    <tr><th>Name</th><th>Email</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recipientsPreview as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(trim($r['first_name'] . ' ' . $r['last_name'])); ?></td>
                                        <td><?php echo htmlspecialchars($r['email']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="blast-card">
                <h2>Email history</h2>
                <?php if (empty($history)): ?>
                    <p class="helper-text">No emails sent yet. Past blasts will appear here.</p>
                <?php else: ?>
                    <p class="helper-text">Showing the <?php echo count($history); ?> most recent blast<?php echo count($history) === 1 ? '' : 's'; ?>. Click a row to see the body and any failed recipients.</p>
                    <ul class="history-list">
                    <?php foreach ($history as $h):
                        if ($h['body_is_html']) {
                            $renderedBody = $h['body'];
                        } else {
                            $parsedown = new Parsedown();
                            $parsedown->setSafeMode(true);
                            $renderedBody = $parsedown->text((string)$h['body']);
                        }
                        $failedRows = trim((string)$h['failed_recipients']);
                    ?>
                        <li class="history-item">
                            <details>
                                <summary>
                                    <span class="history-when"><?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($h['sent_at']))); ?></span>
                                    <span class="audience-tag"><?php echo htmlspecialchars($AUDIENCES[$h['audience']] ?? $h['audience']); ?></span>
                                    <span class="history-subject"><?php echo htmlspecialchars($h['subject']); ?></span>
                                    <span class="history-stats">
                                        <?php echo (int)$h['sent_count']; ?>/<?php echo (int)$h['recipient_count']; ?> sent<?php
                                        if ((int)$h['failed_count'] > 0) {
                                            echo ' · <span class="history-failed">' . (int)$h['failed_count'] . ' failed</span>';
                                        }
                                        ?>
                                    </span>
                                </summary>
                                <div class="history-detail">
                                    <?php if (!empty($h['reply_to'])): ?>
                                        <p class="helper-text">Reply-To: <code><?php echo htmlspecialchars($h['reply_to']); ?></code></p>
                                    <?php endif; ?>
                                    <div class="history-body-rendered"><?php echo $renderedBody; ?></div>
                                    <?php
                                        $customRows = trim((string)($h['custom_recipients'] ?? ''));
                                        if ($customRows !== ''):
                                            $parsedCustom = parseCustomRecipients($customRows);
                                    ?>
                                        <details class="history-failed-list" style="margin-top:0.85rem;">
                                            <summary style="color: var(--color-text-secondary);">Additional recipients (<?php echo count($parsedCustom); ?>)</summary>
                                            <pre><?php echo htmlspecialchars($customRows); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                    <?php if ($failedRows !== ''): ?>
                                        <details class="history-failed-list">
                                            <summary>Failed recipients (<?php echo (int)$h['failed_count']; ?>)</summary>
                                            <pre><?php echo htmlspecialchars($failedRows); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <script>
                (function () {
                    var form = document.getElementById('blast-form');
                    var sendBtn = document.getElementById('send-blast-btn');
                    var radios = form.querySelectorAll('input[name="audience"]');
                    var detailsBlocks = document.querySelectorAll('.audience-emails');
                    var labelEl = document.getElementById('custom_recipients_label');
                    var helperGuest = document.getElementById('recipients-helper-guest');
                    var helperCustom = document.getElementById('recipients-helper-custom');
                    var saveRow = document.getElementById('save-audience-row');
                    var saveHelper = document.getElementById('save-audience-helper');
                    var saveBtn = document.getElementById('save-audience-btn');
                    var nameInput = document.getElementById('audience_name');
                    var recipientsTA = document.getElementById('custom_recipients');

                    function isCustomKey(v) { return v === 'custom' || /^custom_\d+$/.test(v); }

                    function applyAudienceMode(checkedRadio) {
                        if (!checkedRadio) return;
                        var value = checkedRadio.value;
                        var custom = isCustomKey(value);
                        if (labelEl)    labelEl.textContent = custom ? 'Recipients' : 'Additional recipients (optional)';
                        if (helperGuest) helperGuest.hidden = custom;
                        if (helperCustom) helperCustom.hidden = !custom;
                        if (saveRow)     saveRow.hidden = !custom;
                        if (saveHelper)  saveHelper.hidden = !custom;
                    }

                    function updateSelected(e) {
                        var selectedKey = null;
                        var checkedRadio = null;
                        radios.forEach(function (r) {
                            r.closest('.audience-option').classList.toggle('is-selected', r.checked);
                            if (r.checked) { selectedKey = r.value; checkedRadio = r; }
                        });
                        detailsBlocks.forEach(function (d) {
                            d.hidden = d.getAttribute('data-audience') !== selectedKey;
                        });
                        applyAudienceMode(checkedRadio);

                        // Only mutate the recipients textarea / name when the user actually
                        // clicked an audience radio — never on initial load.
                        if (!e) return;
                        if (selectedKey === 'custom') {
                            if (nameInput) nameInput.value = '';
                            if (saveBtn) saveBtn.textContent = 'Save as audience';
                        } else if (/^custom_\d+$/.test(selectedKey) && checkedRadio) {
                            var savedName = checkedRadio.getAttribute('data-saved-name') || '';
                            var savedEmails = checkedRadio.getAttribute('data-saved-emails') || '';
                            if (recipientsTA) recipientsTA.value = savedEmails;
                            if (nameInput) nameInput.value = savedName;
                            if (saveBtn) saveBtn.textContent = 'Update "' + savedName + '"';
                        }
                    }
                    radios.forEach(function (r) { r.addEventListener('change', updateSelected); });

                    form.addEventListener('submit', function (e) {
                        var submitter = e.submitter;
                        if (!submitter || submitter.value !== 'send_blast') return;
                        var audienceLabel = form.querySelector('input[name="audience"]:checked')
                            .closest('.audience-option').querySelector('span').textContent;
                        var msg = 'Send this email to the audience: "' + audienceLabel + '"?\n\n' +
                            'This cannot be undone.';
                        if (!window.confirm(msg)) {
                            e.preventDefault();
                            return;
                        }
                        sendBtn.disabled = true;
                        sendBtn.textContent = 'Sending… (do not close this tab)';
                    });
                })();

                (function () {
                    // Per-recipient include/exclude controls.
                    function recipientCheckboxes(audience) {
                        return document.querySelectorAll(
                            '.audience-recipient-toggle[data-audience="' + audience + '"]'
                        );
                    }
                    function updateCount(audience) {
                        var boxes = recipientCheckboxes(audience);
                        var total = boxes.length;
                        var checked = 0;
                        boxes.forEach(function (b) {
                            if (b.checked) checked++;
                            var row = b.closest('tr');
                            if (row) row.classList.toggle('is-excluded', !b.checked);
                        });
                        var label = document.querySelector(
                            '.audience-selected-count[data-audience-count="' + audience + '"]'
                        );
                        if (label) {
                            label.textContent = checked + ' of ' + total + ' selected';
                        }
                        var master = document.querySelector(
                            '.audience-toggle-all[data-audience="' + audience + '"]'
                        );
                        if (master) {
                            master.checked = checked === total && total > 0;
                            master.indeterminate = checked > 0 && checked < total;
                        }
                    }

                    document.querySelectorAll('.audience-emails[data-audience]').forEach(function (panel) {
                        var audience = panel.getAttribute('data-audience');
                        updateCount(audience);
                    });

                    document.addEventListener('change', function (e) {
                        if (e.target.classList && e.target.classList.contains('audience-recipient-toggle')) {
                            updateCount(e.target.getAttribute('data-audience'));
                        }
                        if (e.target.classList && e.target.classList.contains('audience-toggle-all')) {
                            var audience = e.target.getAttribute('data-audience');
                            var state = e.target.checked;
                            recipientCheckboxes(audience).forEach(function (b) { b.checked = state; });
                            updateCount(audience);
                        }
                    });

                    document.addEventListener('click', function (e) {
                        var btn = e.target.closest && e.target.closest('.btn-link[data-action]');
                        if (!btn) return;
                        e.preventDefault();
                        var audience = btn.getAttribute('data-audience');
                        var checked = btn.getAttribute('data-action') === 'select-all';
                        recipientCheckboxes(audience).forEach(function (b) { b.checked = checked; });
                        updateCount(audience);
                    });
                })();

                (function () {
                    var bodyEl = document.getElementById('body');
                    var preview = document.getElementById('body-preview');
                    if (!bodyEl || !preview) return;
                    var timer = null;
                    var inflight = null;

                    function render() {
                        if (inflight) inflight.abort();
                        var controller = new AbortController();
                        inflight = controller;
                        fetch('/api/markdown-preview', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ markdown: bodyEl.value }),
                            signal: controller.signal,
                            credentials: 'same-origin'
                        })
                        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
                        .then(function (data) {
                            preview.innerHTML = data.html || '<em style="color:var(--color-text-secondary);">Empty.</em>';
                        })
                        .catch(function (err) {
                            if (err && err.name === 'AbortError') return;
                            preview.innerHTML = '<em style="color:#b03030;">Preview failed.</em>';
                        });
                    }

                    function schedule() {
                        if (timer) clearTimeout(timer);
                        timer = setTimeout(render, 250);
                    }

                    bodyEl.addEventListener('input', schedule);
                    render();
                })();
            </script>
        <?php endif; ?>
    </main>
</body>
</html>
