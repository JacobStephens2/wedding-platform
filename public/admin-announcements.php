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

$AUDIENCES = [
    'all_with_email'    => 'All invited guests with email',
    'rsvp_yes'          => "RSVP'd Yes (any event)",
    'attending_ceremony'=> 'Attending Ceremony',
    'attending_reception'=> 'Attending Reception',
    'rsvp_no'           => "RSVP'd No",
    'no_response'       => 'No RSVP response yet',
    'rehearsal_invited' => 'Rehearsal-invited guests',
];

function audienceSql(string $audience): ?string {
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

$audience      = $_POST['audience']  ?? 'all_with_email';
$subject       = $_POST['subject']   ?? $DEFAULT_SUBJECT;
$body          = $_POST['body']      ?? $DEFAULT_BODY;
// Body is authored in Markdown; we render to HTML on send and keep the
// Markdown source as the plain-text alt body.
$isHtml        = true;
$replyTo       = trim($_POST['reply_to'] ?? '');
$fromName      = trim($_POST['from_name'] ?? '') ?: 'Jacob and Melissa';
$testEmail     = trim($_POST['test_email'] ?? '');
$recipientsPreview = null;
$previewCount = null;

if ($authenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($AUDIENCES[$audience])) {
        $error = 'Invalid audience selected.';
    } elseif (in_array($_POST['action'], ['preview', 'test_send', 'send_blast'], true)) {
        try {
            $pdo = getDbConnection();
            $recipients = fetchAudience($pdo, $audience);
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
                                recipient_count, sent_count, failed_count, failed_recipients)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $logStmt->execute([
                            $audience,
                            $subject,
                            $body,
                            $isHtml ? 1 : 0,
                            $replyTo !== '' ? $replyTo : null,
                            $previewCount,
                            $sent,
                            $failed,
                            $failed > 0 ? implode("\n", $failedList) : null,
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
if ($authenticated && !$sampleMode) {
    try {
        $pdo = getDbConnection();
        $history = $pdo->query("
            SELECT id, audience, subject, recipient_count, sent_count, failed_count, sent_at, body_is_html
            FROM email_blasts
            ORDER BY sent_at DESC
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
        .history-table { width: 100%; border-collapse: collapse; font-family: 'Crimson Text', serif; }
        .history-table th, .history-table td {
            text-align: left;
            padding: 0.55rem 0.75rem;
            border-bottom: 1px solid var(--color-border);
            font-size: 0.95rem;
        }
        .history-table th { background: var(--color-bg); }
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

            <form method="POST" action="/admin-announcements<?php echo $sampleMode ? '?sample=1' : ''; ?>" id="blast-form">
                <div class="blast-card">
                    <h2>1. Pick an audience</h2>
                    <div class="audience-grid">
                        <?php foreach ($AUDIENCES as $key => $label):
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
                    <p class="helper-text">Only guests with an email on file are counted. Plus-ones share their primary guest's email.</p>

                    <?php foreach ($AUDIENCES as $key => $label):
                        $recipients = $audienceRecipients[$key] ?? [];
                        $count = count($recipients);
                        $emailsCsv = implode(', ', array_map(function($r) { return $r['email']; }, $recipients));
                    ?>
                        <details class="audience-emails" data-audience="<?php echo htmlspecialchars($key); ?>"
                                 <?php echo $audience === $key ? '' : 'hidden'; ?>>
                            <summary>View email addresses in this audience (<?php echo (int)$count; ?>)</summary>
                            <?php if ($count === 0): ?>
                                <p class="helper-text" style="margin-top:0.75rem;">No one matches this audience.</p>
                            <?php else: ?>
                                <div class="recipient-preview" style="margin-top:0.75rem;">
                                    <table>
                                        <thead><tr><th>Name</th><th>Email</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($recipients as $r): ?>
                                            <tr>
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

            <?php if (!empty($history)): ?>
                <div class="blast-card">
                    <h2>Recent blasts</h2>
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Audience</th>
                                <th>Subject</th>
                                <th>Sent</th>
                                <th>Failed</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(date('M j, Y g:i a', strtotime($h['sent_at']))); ?></td>
                                <td><span class="audience-tag"><?php echo htmlspecialchars($AUDIENCES[$h['audience']] ?? $h['audience']); ?></span></td>
                                <td><?php echo htmlspecialchars($h['subject']); ?><?php echo $h['body_is_html'] ? ' <small style="color:var(--color-text-secondary);">(HTML)</small>' : ''; ?></td>
                                <td><?php echo (int)$h['sent_count']; ?> / <?php echo (int)$h['recipient_count']; ?></td>
                                <td><?php echo (int)$h['failed_count']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <script>
                (function () {
                    var form = document.getElementById('blast-form');
                    var sendBtn = document.getElementById('send-blast-btn');
                    var radios = form.querySelectorAll('input[name="audience"]');
                    var detailsBlocks = document.querySelectorAll('.audience-emails');
                    function updateSelected() {
                        var selectedKey = null;
                        radios.forEach(function (r) {
                            r.closest('.audience-option').classList.toggle('is-selected', r.checked);
                            if (r.checked) selectedKey = r.value;
                        });
                        detailsBlocks.forEach(function (d) {
                            d.hidden = d.getAttribute('data-audience') !== selectedKey;
                        });
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
