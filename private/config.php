<?php
// Load environment variables
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Define constants
// Public site URL without a trailing slash. Set BASE_URL in .env; cron scripts
// (no HTTP request) rely on it. Web requests fall back to the request's host.
define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? (
    isset($_SERVER['HTTP_HOST'])
        ? ((($_SERVER['HTTPS'] ?? '') === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'])
        : 'http://localhost'
), '/'));
define('PRIVATE_DIR', __DIR__);
define('PUBLIC_DIR', __DIR__ . '/../public');

// Couple-specific content accessors: content(), contentBlocks(), etc.
// Loaded here so every page that includes config.php can read couple content.
require_once __DIR__ . '/content.php';








