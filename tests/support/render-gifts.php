<?php

declare(strict_types=1);

// Copy the real page unchanged; configuration/content stand-ins exist only here.
$repo = dirname(__DIR__, 2);
$root = sys_get_temp_dir() . '/gift-render-' . bin2hex(random_bytes(8));
$copies = ['public/admin-gifts.php', 'public/includes/admin_menu.php', 'public/includes/theme_init.php'];
$links = ['private/admin_auth.php', 'private/admin_sample.php'];
mkdir($root . '/public/includes', 0700, true);
mkdir($root . '/private', 0700, true);
try {
    foreach ($copies as $path) {
        copy($repo . '/' . $path, $root . '/' . $path);
    }
    foreach ($links as $path) {
        symlink($repo . '/' . $path, $root . '/' . $path);
    }
    file_put_contents($root . '/private/config.php', '<?php require ' . var_export($repo . '/vendor/autoload.php', true) . ';'
        . '$defaults = require ' . var_export($repo . '/private/content_defaults.php', true) . ';'
        . 'function content(string $key, ?string $default = null): ?string { global $defaults; return $defaults["scalars"][$key] ?? $default; }');
    file_put_contents($root . '/private/db.php', '<?php function getDbConnection(): never {'
        . 'fwrite(STDERR, "Unexpected database access in sample render"); throw new LogicException("Database access is forbidden"); }');
    $_GET = ['sample' => '1', 'toggle_registry_received' => '305', 'delete' => '802'];
    $_POST = ['description' => 'Should never be saved', 'save_gift' => '1'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    require $root . '/public/admin-gifts.php';
} finally {
    foreach (array_merge($copies, $links, ['private/config.php', 'private/db.php']) as $path) {
        unlink($root . '/' . $path);
    }
    rmdir($root . '/public/includes');
    rmdir($root . '/public');
    rmdir($root . '/private');
    rmdir($root);
}
