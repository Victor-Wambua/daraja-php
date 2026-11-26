<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

/**
 * Starts the real `bin/daraja-mock` and a callback receiver as background processes on localhost,
 * for the integration tests. Everything is loopback-only; nothing touches the network.
 *
 * Ports are taken from DARAJA_TEST_PORT_BASE (default 4152) upwards, at most eight of them.
 */
final class BackgroundServers
{
    /** @var list<resource> */
    private array $processes = [];

    public int $mockPort = 0;
    public int $receiverPort = 0;
    public string $receiverFile = '';
    private string $db = '';

    /** @param list<string> $mockArgs extra bin/daraja-mock arguments */
    public function start(array $mockArgs = []): void
    {
        $base = (int) (getenv('DARAJA_TEST_PORT_BASE') ?: 4152);
        $free = [];
        for ($p = $base; $p < $base + 8 && \count($free) < 2; ++$p) {
            $probe = @stream_socket_server('tcp://127.0.0.1:' . $p, $errno, $errstr);
            if ($probe !== false) {
                fclose($probe);
                $free[] = $p;
            }
        }
        if (\count($free) < 2) {
            throw new \RuntimeException('Need two free ports at or above ' . $base . '. Set DARAJA_TEST_PORT_BASE.');
        }
        [$this->mockPort, $this->receiverPort] = $free;

        $root = \dirname(__DIR__, 2);
        $tmp = sys_get_temp_dir() . '/daraja-it-' . getmypid() . '-' . $this->mockPort;
        @mkdir($tmp);
        $this->receiverFile = $tmp . '/received.jsonl';
        $this->db = $tmp . '/mock.sqlite';
        touch($this->receiverFile);

        $this->spawn(
            [PHP_BINARY, $root . '/bin/daraja-mock', '--port=' . $this->mockPort, '--quiet', '--db=' . $this->db, ...$mockArgs],
            [],
            $root,
        );
        $this->spawn(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->receiverPort, __DIR__ . '/receiver.php'],
            ['RECEIVER_FILE' => $this->receiverFile],
            $root,
        );

        $this->waitUntil(fn (): bool => $this->healthy($this->mockPort), 10, 'mock server did not start');
        $this->waitUntil(fn (): bool => $this->healthy($this->receiverPort), 10, 'receiver did not start');
    }

    public function stop(): void
    {
        foreach ($this->processes as $p) {
            if (get_resource_type($p) !== 'process') {
                continue;
            }
            proc_terminate($p);
            $deadline = microtime(true) + 3;
            while (microtime(true) < $deadline && proc_get_status($p)['running']) {
                usleep(50_000);
            }
            if (proc_get_status($p)['running']) {
                proc_terminate($p, 9);
            }
            proc_close($p);
        }
        $this->processes = [];
        foreach ([$this->receiverFile, $this->receiverFile . '.fail', $this->db, $this->db . '-wal', $this->db . '-shm'] as $f) {
            if ($f !== '' && is_file($f)) {
                unlink($f);
            }
        }
        if ($this->receiverFile !== '') {
            @rmdir(\dirname($this->receiverFile));
        }
    }

    public function mockUrl(): string
    {
        return 'http://127.0.0.1:' . $this->mockPort;
    }

    public function receiverUrl(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->receiverPort . $path;
    }

    /** @return list<array{path: string, status: int, body: string}> */
    public function received(): array
    {
        $out = [];
        foreach (file($this->receiverFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (\is_array($row) && \is_string($row['path'] ?? null) && \is_int($row['status'] ?? null) && \is_string($row['body'] ?? null)) {
                $out[] = ['path' => $row['path'], 'status' => $row['status'], 'body' => $row['body']];
            }
        }

        return $out;
    }

    public function failNextReceiverRequests(int $n): void
    {
        file_put_contents($this->receiverFile . '.fail', (string) $n);
    }

    public function resetReceiver(): void
    {
        file_put_contents($this->receiverFile, '');
        file_put_contents($this->receiverFile . '.fail', '0');
    }

    public function waitUntil(callable $condition, float $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(50_000);
        }
        throw new \RuntimeException('Timed out: ' . $message);
    }

    private function healthy(int $port): bool
    {
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);

        return @file_get_contents('http://127.0.0.1:' . $port . '/__health', false, $ctx) !== false
            || @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx) !== false;
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     */
    private function spawn(array $command, array $env, string $cwd): void
    {
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $cwd, [...getenv(), ...$env]);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start ' . $command[0]);
        }
        $this->processes[] = $process;
    }
}
