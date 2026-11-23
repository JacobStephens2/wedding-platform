<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use RuntimeException;
use Throwable;

/** Real HTTP requests to unchanged callers with test-owned configuration only. */
final class GiftStatusHttp
{
    private readonly TemporaryDirectory $directory;
    private ?OwnedProcess $server = null;
    private readonly string $address;

    public function __construct(LocalMariaDb $database)
    {
        LocalHttpRequest::requireCurl();
        $this->directory = new TemporaryDirectory('gift-http');
        $root = $this->directory->path;
        $repo = dirname(__DIR__, 2);
        try {
            foreach (['public/api', 'public/includes', 'private', 'sessions'] as $path) {
                mkdir($root . '/' . $path, 0700, true);
            }
            foreach (['public/admin-gifts.php', 'public/api/toggle-gift-status.php',
                'public/includes/admin_menu.php', 'public/includes/theme_init.php',
                'private/admin_auth.php', 'private/admin_sample.php'] as $path) {
                copy($repo . '/' . $path, $root . '/' . $path);
            }
            file_put_contents($root . '/sessions/sess_synthetic', 'registry_admin_authenticated|b:1;');
            file_put_contents($root . '/private/config.php', '<?php require '
                . var_export($repo . '/vendor/autoload.php', true) . ';'
                . '$defaults = require ' . var_export($repo . '/private/content_defaults.php', true) . ';'
                . 'date_default_timezone_set("Pacific/Honolulu");'
                . 'ini_set("session.save_path", ' . var_export($root . '/sessions', true) . ');'
                . 'if (($_SERVER["HTTP_X_TEST_AUTHORIZED"] ?? "") === "1") { session_id("synthetic"); }'
                . 'function content(string $key, ?string $default = null): ?string {'
                . 'global $defaults; return $defaults["scalars"][$key] ?? $default; }');
            file_put_contents($root . '/private/db.php', '<?php function getDbConnection(): PDO {'
                . 'file_put_contents(' . var_export($root . '/acquisitions', true) . ', "connection\n", FILE_APPEND);'
                . 'if (($_SERVER["HTTP_X_TEST_FAIL_DB"] ?? "") === "1") { throw new PDOException("owned connection failure"); }'
                . '$pdo = new PDO(' . var_export($database->dsn, true) . ', '
                . var_export($database->username, true) . ', "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,'
                . 'PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);'
                . '$pdo->exec("SET time_zone = \'+00:00\'"); return $pdo; }');
            // Ask the OS for a free loopback port. The server's readiness probe
            // verifies its unique token, so a port race cannot target another app.
            $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
            if ($reservation === false) {
                throw new RuntimeException('Cannot reserve loopback port: ' . $error);
            }
            $address = stream_socket_get_name($reservation, false);
            fclose($reservation);
            if ($address === false) {
                throw new RuntimeException('Cannot determine loopback port');
            }
            $this->address = $address;
            $token = bin2hex(random_bytes(16));
            file_put_contents($root . '/public/ready', $token);
            file_put_contents($root . '/router.php', '<?php '
                . '$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);'
                . 'if ($path === "/admin-gifts") { require __DIR__ . "/public/admin-gifts.php"; return true; }'
                . 'if ($path === "/api/toggle-gift-status") { require __DIR__ . "/public/api/toggle-gift-status.php"; return true; }'
                . 'return false;');
            $this->server = new OwnedProcess([PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1',
                '-S', $address, '-t', $root . '/public', $root . '/router.php'], $root . '/server.log');
            $this->server->awaitReady(fn (): bool => $this->request('/ready')['body'] === $token);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /**
     * @param list<string> $headers
     * @param non-empty-string $method
     * @return array{status:int,body:string,headers:string}
     */
    public function request(string $path, string $method = 'GET', string $body = '', array $headers = []): array
    {
        return LocalHttpRequest::send($this->origin() . $path, $method, $body, $headers);
    }

    public function origin(): string
    {
        return 'http://' . $this->address;
    }

    public function acquiredDatabase(): bool
    {
        return is_file($this->directory->path . '/acquisitions');
    }

    public function clearAcquisitions(): void
    {
        $path = $this->directory->path . '/acquisitions';
        if (is_file($path)) {
            unlink($path);
        }
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
