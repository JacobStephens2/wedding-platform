<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use Closure;
use RuntimeException;

/** A test-owned child with bounded startup and explicit stop/wait cleanup. */
final class OwnedProcess
{
    /** @var resource|null */
    private $process;

    /** @param list<string> $command */
    public function __construct(array $command, private readonly string $log)
    {
        if (!function_exists('proc_open')) {
            throw new RuntimeException('Gift status tests require PHP proc_open to launch owned local processes.');
        }
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start test process: ' . $command[0]);
        }
        $this->process = $process;
    }

    /** @param Closure():bool $ready */
    public function awaitReady(Closure $ready): void
    {
        $deadline = microtime(true) + 15;
        do {
            if ($ready()) {
                return;
            }
            if ($this->process === null || !proc_get_status($this->process)['running']) {
                throw new RuntimeException('Test process exited during startup: ' . $this->output());
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Test process startup timed out: ' . $this->output());
    }

    public function awaitExit(): void
    {
        $deadline = microtime(true) + 30;
        do {
            if ($this->process === null) {
                throw new RuntimeException('Test process was already stopped');
            }
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                proc_close($this->process);
                $this->process = null;
                if ($status['exitcode'] !== 0) {
                    throw new RuntimeException('Test process failed: ' . $this->output());
                }
                return;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Test process timed out: ' . $this->output());
    }

    public function output(): string
    {
        return (string) file_get_contents($this->log);
    }

    public function stop(): void
    {
        if ($this->process === null) {
            return;
        }
        proc_terminate($this->process);
        $deadline = microtime(true) + 2;
        while (proc_get_status($this->process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process, 9);
        }
        proc_close($this->process);
        $this->process = null;
    }

    public function __destruct()
    {
        $this->stop();
    }
}
