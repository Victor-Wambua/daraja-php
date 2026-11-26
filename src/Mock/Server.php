<?php

declare(strict_types=1);

namespace Daraja\Mock;

use Daraja\Http\CurlClient;
use Daraja\Support\Json;
use Daraja\Support\SystemClock;

/**
 * The `bin/daraja-mock` supervisor: starts PHP's built-in web server on the mock router and, in this
 * same process, runs the Dispatcher loop that times out unanswered prompts and delivers callbacks.
 */
final class Server
{
    private bool $stop = false;

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        $options = self::parseOptions(\array_slice($argv, 1));
        if (isset($options['help'])) {
            fwrite(STDOUT, self::usage());

            return 0;
        }

        try {
            return (new self())->run($options);
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'daraja-mock: ' . $e->getMessage() . "\n\n" . self::usage());

            return 2;
        }
    }

    /** @param array<string, string> $o */
    private function run(array $o): int
    {
        $host = $o['host'] ?? '127.0.0.1';
        $port = (int) ($o['port'] ?? 4150);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('--port must be between 1 and 65535');
        }
        $loopback = \in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
        if (!$loopback && !isset($o['allow-remote'])) {
            throw new \InvalidArgumentException('Refusing to listen on ' . $host . ': the console has no login. Pass --allow-remote if you really mean it.');
        }

        $delays = [];
        foreach (explode(',', $o['retry-delays'] ?? '1,3,10,30') as $d) {
            if ($d === '') {
                continue;
            }
            if (!is_numeric($d) || (float) $d < 0) {
                throw new \InvalidArgumentException('--retry-delays must be comma-separated seconds, e.g. 1,3,10');
            }
            $delays[] = (float) $d;
        }

        $display = ($host === '0.0.0.0' ? '127.0.0.1' : $host);
        $config = new MockConfig(
            consumerKey: $o['consumer-key'] ?? 'mock-consumer-key',
            consumerSecret: $o['consumer-secret'] ?? 'mock-consumer-secret',
            shortCode: $o['shortcode'] ?? '174379',
            passKey: $o['passkey'] ?? 'mock-passkey-not-a-real-secret',
            tokenTtlSeconds: (int) ($o['token-ttl'] ?? 3599),
            stkTimeoutSeconds: (int) ($o['stk-timeout'] ?? 60),
            retryDelays: $delays,
            signingKey: bin2hex(random_bytes(16)),
            baseUrl: 'http://' . $display . ':' . $port,
        );

        $dbPath = $o['db'] ?? sys_get_temp_dir() . '/daraja-mock-' . $port . '.sqlite';
        if (!isset($o['keep-data'])) {
            foreach ([$dbPath, $dbPath . '-wal', $dbPath . '-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        $store = Store::open($dbPath);

        $scenarios = new Scenarios($store, new SystemClock(), $config);
        $dispatcher = new Dispatcher($store, new CurlClient($config->callbackTimeoutSeconds, 3), $config, $scenarios);

        $env = [...getenv(), 'DARAJA_MOCK_CONFIG' => Json::encode($config->toArray()), 'DARAJA_MOCK_DB' => $dbPath];
        $quiet = isset($o['quiet']);
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => $quiet ? ['file', '/dev/null', 'w'] : STDOUT,
            2 => $quiet ? ['file', '/dev/null', 'w'] : STDERR,
        ];
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-S', $host . ':' . $port, __DIR__ . '/router.php'],
            $descriptors,
            $pipes,
            \dirname(__DIR__, 2),
            $env,
        );
        if (!\is_resource($process)) {
            fwrite(STDERR, "daraja-mock: could not start the PHP built-in server\n");

            return 1;
        }

        $this->installSignalHandlers();
        $pid = proc_get_status($process)['pid'];
        register_shutdown_function(static function () use ($process): void {
            if (get_resource_type($process) === 'process' && proc_get_status($process)['running']) {
                proc_terminate($process);
            }
        });

        // Give the child a moment to fail on an occupied port before announcing success.
        usleep(300_000);
        if (!self::isRunning($process)) {
            fwrite(STDERR, "daraja-mock: the web server exited immediately. Is port {$port} already in use?\n");

            return 1;
        }

        if (!$quiet) {
            fwrite(STDOUT, self::banner($config, $dbPath, $pid));
        }
        if (isset($o['pid-file'])) {
            file_put_contents($o['pid-file'], (string) getmypid());
        }

        while (!$this->stop) {
            if (!self::isRunning($process)) {
                fwrite(STDERR, "daraja-mock: web server stopped\n");

                return 1;
            }
            try {
                $dispatcher->tick();
            } catch (\Throwable $e) {
                fwrite(STDERR, 'daraja-mock: dispatcher error: ' . $e->getMessage() . "\n");
            }
            usleep(200_000);
        }

        proc_terminate($process);
        proc_close($process);
        if (!$quiet) {
            fwrite(STDOUT, "\ndaraja-mock stopped.\n");
        }

        return 0;
    }

    /**
     * @param resource $process
     *
     * @phpstan-impure
     */
    private static function isRunning($process): bool
    {
        return proc_get_status($process)['running'];
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal') || !\function_exists('pcntl_async_signals')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stop = true;
        };
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGTERM, $handler);
    }

    private static function banner(MockConfig $c, string $db, int $pid): string
    {
        return <<<TXT

              daraja-mock  ·  a stand-in for Safaricom's Daraja API
              ----------------------------------------------------
              Console     {$c->baseUrl}/
              Base URL    {$c->baseUrl}        (set DARAJA_BASE_URL to this)
              Consumer    {$c->consumerKey} / {$c->consumerSecret}
              Short code  {$c->shortCode}
              Passkey     {$c->passKey}
              STK expiry  {$c->stkTimeoutSeconds}s     Database  {$db}
              Development use only. Nothing here is real, nothing leaves this machine.
              Ctrl+C to stop.


            TXT;
    }

    private static function usage(): string
    {
        return <<<TXT
            Usage: bin/daraja-mock [options]

              --host=127.0.0.1        Address to listen on (loopback only unless --allow-remote)
              --port=4150             Port to listen on
              --consumer-key=...      Credentials the mock accepts (defaults are fake)
              --consumer-secret=...
              --shortcode=174379
              --passkey=...
              --stk-timeout=60        Seconds before an unanswered STK prompt times out (code 1037)
              --retry-delays=1,3,10,30  Seconds between callback delivery retries
              --token-ttl=3599        OAuth token lifetime in seconds
              --db=PATH               SQLite file (default: system temp dir, wiped on start)
              --keep-data             Do not wipe the database on start
              --quiet                 No banner and no request log
              --allow-remote          Permit a non-loopback --host (the console has no login)
              --help

            TXT;
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string>
     */
    private static function parseOptions(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
                throw new \InvalidArgumentException('Unrecognised argument: ' . $arg);
            }
            $out[$m[1]] = $m[2] ?? '1';
        }

        return $out;
    }
}
