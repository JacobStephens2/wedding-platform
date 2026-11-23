<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Disposable, socket-only MariaDB. Never loads application configuration. */
final class LocalMariaDb
{
    private readonly TemporaryDirectory $directory;
    private ?OwnedProcess $server = null;
    public readonly string $dsn;
    public readonly string $username;
    public readonly PDO $pdo;

    public function __construct()
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException('Gift status tests require the PDO MySQL extension (php-mysql).');
        }
        if (!function_exists('posix_getpwuid')) {
            throw new RuntimeException('Gift status tests require the PHP POSIX extension for socket authentication.');
        }
        $account = posix_getpwuid(posix_geteuid());
        if ($account === false) {
            throw new RuntimeException('Cannot determine the test process OS account.');
        }
        $this->username = $account['name'];
        $installer = self::executable('mariadb-install-db');
        $daemon = self::executable('mariadbd');
        $this->directory = new TemporaryDirectory('gift-db');
        $root = $this->directory->path;
        $initializer = null;
        try {
            $initializer = new OwnedProcess([$installer, '--no-defaults', '--datadir=' . $root . '/data', '--tmpdir=' . $root,
                '--auth-root-authentication-method=socket', '--auth-root-socket-user=' . $this->username,
                '--skip-test-db'], $root . '/install.log');
            $initializer->awaitExit();
            $this->server = new OwnedProcess([$daemon, '--no-defaults', '--datadir=' . $root . '/data', '--tmpdir=' . $root,
                '--socket=' . $root . '/mysql.sock', '--pid-file=' . $root . '/mysql.pid',
                '--skip-networking', '--default-time-zone=+00:00', '--innodb-buffer-pool-size=32M'], $root . '/server.log');
            // mariadb-install-db grants socket authentication to the installing OS user.
            $socketDsn = 'mysql:unix_socket=' . $root . '/mysql.sock;charset=utf8mb4';
            $username = $this->username;
            $this->server->awaitReady(static function () use ($socketDsn, $username): bool {
                try {
                    new PDO($socketDsn, $username, '', [PDO::ATTR_TIMEOUT => 1]);
                    return true;
                } catch (PDOException) {
                    return false;
                }
            });
            $pdo = new PDO($socketDsn, $username, '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE DATABASE gift_status_test');
            $this->dsn = $socketDsn . ';dbname=gift_status_test';
            $this->pdo = new PDO($this->dsn, $username, '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
        } catch (Throwable $error) {
            $initializer?->stop();
            $this->close();
            throw $error;
        } finally {
            $initializer?->stop();
        }
    }

    public function reset(): void
    {
        $this->pdo->exec((string) file_get_contents(__DIR__ . '/gift-status.sql'));
    }

    public function close(): void
    {
        $this->server?->stop();
        $this->server = null;
        $this->directory->remove();
    }

    private static function executable(string $name): string
    {
        foreach (explode(PATH_SEPARATOR, (getenv('PATH') ?: '') . ':/usr/sbin:/usr/bin') as $path) {
            if (is_executable($path . '/' . $name)) {
                return $path . '/' . $name;
            }
        }
        throw new RuntimeException('Gift status tests require local MariaDB: missing ' . $name . ' (install mariadb-server).');
    }
}
