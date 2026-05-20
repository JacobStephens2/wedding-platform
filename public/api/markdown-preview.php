<?php
/**
 * Render Markdown to HTML for the announcements composer preview.
 * Admin-only.
 *
 * POST /api/markdown-preview
 *   Body: { "markdown": "..." }
 *   Reply: { "html": "..." }
 */

require_once __DIR__ . '/../../private/config.php';
require_once __DIR__ . '/../../private/admin_auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';

header('Content-Type: application/json');

if (!isAdminAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Admin authentication required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$markdown = (string)($input['markdown'] ?? '');

$parsedown = new Parsedown();
$parsedown->setSafeMode(true); // Disallow raw HTML and risky URLs in admin-typed input.
$html = $parsedown->text($markdown);

echo json_encode(['html' => $html]);
