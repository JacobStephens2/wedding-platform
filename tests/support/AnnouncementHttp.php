<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use Throwable;

/** Runs the real Announcements page with a disposable DB and a file-backed mail recorder. */
final class AnnouncementHttp
{
    private readonly TemporaryDirectory $directory;
    private ?OwnedProcess $server = null;
    public readonly string $origin;
    public const SESSION_COOKIE = 'PHPSESSID=announcements-test';

    public function __construct(LocalMariaDb $database)
    {
        $this->directory = new TemporaryDirectory('announcement-http');
        $root = $this->directory->path;
        $repo = dirname(__DIR__, 2);
        try {
            foreach (['public/api', 'public/includes', 'private', 'sessions'] as $path) {
                mkdir($root . '/' . $path, 0700, true);
            }
            foreach (['public/admin-announcements.php', 'public/api/markdown-preview.php',
                'public/includes/admin_menu.php', 'public/includes/theme_init.php',
                'private/admin_auth.php', 'private/admin_sample.php'] as $path) {
                copy($repo . '/' . $path, $root . '/' . $path);
            }
            foreach (['vendor', 'public/css', 'public/favicon.ico'] as $path) {
                symlink($repo . '/' . $path, $root . '/' . $path);
            }
            file_put_contents($root . '/sessions/sess_announcements-test', 'admin_authenticated|b:1;');
            file_put_contents($root . '/private/config.php', <<<'PHP'
                <?php
                require __DIR__ . '/../vendor/autoload.php';
                const BASE_URL = 'https://wedding.example';
                ini_set('session.save_path', __DIR__ . '/../sessions');
                function content(string $key, ?string $default = null): ?string {
                    return $key === 'couple_names' ? 'Sample Couple' : $default;
                }
                PHP);
            file_put_contents($root . '/private/db.php', '<?php function getDbConnection(): PDO {'
                . 'return new PDO(' . var_export($database->dsn, true) . ', '
                . var_export($database->username, true) . ', "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, '
                . 'PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }');
            file_put_contents($root . '/sent.log', '');
            file_put_contents($root . '/private/email_handler.php', <<<'PHP'
                <?php
                function sendEmail(string $email, string $subject, string $body, ?string $replyTo, array $options): bool {
                    return file_put_contents(__DIR__ . '/../sent.log', "$email\t$subject\n", FILE_APPEND) !== false;
                }
                PHP);
            file_put_contents($root . '/router.php', <<<'PHP'
                <?php
                $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                if ($path === '/admin-announcements' || $path === '/api/markdown-preview') {
                    require __DIR__ . '/public' . $path . '.php';
                    return true;
                }
                return false;
                PHP);
            $this->server = new OwnedProcess([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
                '-S', '127.0.0.1:0', '-t', $root . '/public', $root . '/router.php'], $root . '/server.log');
            $port = '';
            $this->server->awaitReady(function () use (&$port): bool {
                if (preg_match('/Development Server \(http:\/\/127\.0\.0\.1:(\d+)\) started/', $this->log(), $match) !== 1) {
                    return false;
                }
                $port = $match[1];
                return true;
            });
            $this->origin = 'http://127.0.0.1:' . $port;
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    public static function createSchema(LocalMariaDb $database): void
    {
        $sql = dirname(__DIR__, 2) . '/private/sql/';
        foreach (['create_guests_table', 'add_phone_column', 'add_plus_one_columns',
            'add_ceremony_reception_columns', 'add_rehearsal_invited_column', 'create_custom_audiences_table',
            'create_email_blasts_table', 'create_announcement_drafts_table', 'add_custom_recipients_to_announcements'] as $migration) {
            $database->pdo->exec((string) file_get_contents($sql . $migration . '.sql'));
        }
    }

    public static function seed(LocalMariaDb $database): void
    {
        foreach (['guests', 'custom_audiences', 'email_blasts', 'announcement_drafts'] as $table) {
            $database->pdo->exec('TRUNCATE TABLE ' . $table);
        }
        $database->pdo->exec(<<<'SQL'
            INSERT INTO guests (first_name, last_name, email, attending, ceremony_attending) VALUES
                ('Alex', 'Arbor', 'Alex@example.com', 'yes', 'yes'),
                ('Alias', 'Birch', 'alex@EXAMPLE.com', 'yes', 'yes'),
                ('Blair', 'Cedar', 'blair@example.com', 'yes', 'no'),
                ('Casey', 'Elm', 'casey@example.com', 'no', 'no');
            INSERT INTO custom_audiences (name, custom_recipients) VALUES
                ('Sample list', 'Saved Recipient <Saved@example.com>, invalid, saved@EXAMPLE.com, extra@example.com');
            SQL);
    }

    public function sentMessages(): string
    {
        return (string) file_get_contents($this->directory->path . '/sent.log');
    }

    public function log(): string
    {
        return $this->server?->output() ?? '';
    }

    public function close(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->directory->remove();
    }
}
