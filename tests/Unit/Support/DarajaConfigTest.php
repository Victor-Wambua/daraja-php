<?php

declare(strict_types=1);

namespace Daraja\Tests\Unit\Support;

use Daraja\Config\DarajaConfig;
use Daraja\Config\Environment;
use Daraja\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DarajaConfigTest extends TestCase
{
    public function testEnvironmentBaseUrls(): void
    {
        self::assertSame('https://sandbox.safaricom.co.ke', Environment::Sandbox->baseUrl());
        self::assertSame('https://api.safaricom.co.ke', Environment::Production->baseUrl());
        $c = new DarajaConfig('k', 's', '174379', 'p');
        self::assertSame('https://sandbox.safaricom.co.ke', $c->baseUrl);
        self::assertFalse($c->isProduction());
        $prod = new DarajaConfig('k', 's', '174379', 'p', Environment::Production);
        self::assertSame('https://api.safaricom.co.ke', $prod->baseUrl);
        self::assertTrue($prod->isProduction());
    }

    public function testAllowsPlainHttpOnlyForLoopback(): void
    {
        self::assertSame('http://127.0.0.1:4150', (new DarajaConfig('k', 's', '174379', baseUrl: 'http://127.0.0.1:4150/'))->baseUrl);
        self::assertSame('http://localhost:8080', (new DarajaConfig('k', 's', '174379', baseUrl: 'http://localhost:8080'))->baseUrl);
        $this->expectException(ConfigurationException::class);
        new DarajaConfig('k', 's', '174379', baseUrl: 'http://example.com');
    }

    /** @return iterable<string, array{string, string, string, int, int, string}> */
    public static function badArgs(): iterable
    {
        yield 'empty key' => ['', 's', '174379', 30, 60, 'https://sandbox.safaricom.co.ke'];
        yield 'empty secret' => ['k', '', '174379', 30, 60, 'https://sandbox.safaricom.co.ke'];
        yield 'short code letters' => ['k', 's', 'abc123', 30, 60, 'https://sandbox.safaricom.co.ke'];
        yield 'short code too short' => ['k', 's', '1234', 30, 60, 'https://sandbox.safaricom.co.ke'];
        yield 'zero timeout' => ['k', 's', '174379', 0, 60, 'https://sandbox.safaricom.co.ke'];
        yield 'negative margin' => ['k', 's', '174379', 30, -1, 'https://sandbox.safaricom.co.ke'];
        yield 'bad base url' => ['k', 's', '174379', 30, 60, 'not a url'];
        yield 'ftp base url' => ['k', 's', '174379', 30, 60, 'ftp://example.com'];
    }

    #[DataProvider('badArgs')]
    public function testRejectsBadSettings(string $key, string $secret, string $shortCode, int $timeout, int $margin, string $baseUrl): void
    {
        $this->expectException(ConfigurationException::class);
        new DarajaConfig($key, $secret, $shortCode, timeoutSeconds: $timeout, tokenSafetyMarginSeconds: $margin, baseUrl: $baseUrl);
    }

    public function testFromEnv(): void
    {
        $c = DarajaConfig::fromEnv([
            'DARAJA_ENV' => 'production', 'DARAJA_CONSUMER_KEY' => 'ck', 'DARAJA_CONSUMER_SECRET' => 'cs',
            'DARAJA_SHORTCODE' => '600638', 'DARAJA_PASSKEY' => 'pk', 'DARAJA_INITIATOR_NAME' => 'api', 'DARAJA_SECURITY_CREDENTIAL' => 'cred',
        ]);
        self::assertTrue($c->isProduction());
        self::assertSame('600638', $c->shortCode);
        self::assertSame(['name' => 'api', 'credential' => 'cred'], $c->requireInitiator());
    }

    public function testFromEnvReportsWhatIsMissing(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('DARAJA_CONSUMER_SECRET');
        DarajaConfig::fromEnv(['DARAJA_CONSUMER_KEY' => 'ck']);
    }

    public function testFromEnvRejectsUnknownEnvironment(): void
    {
        $this->expectException(ConfigurationException::class);
        DarajaConfig::fromEnv(['DARAJA_ENV' => 'staging']);
    }

    public function testSecretsAreHiddenFromPrintRAndVarDump(): void
    {
        $c = new DarajaConfig('super-key', 'super-secret', '174379', 'super-pass', securityCredential: 'super-cred');
        ob_start();
        var_dump($c);
        $dump = print_r($c, true) . (string) ob_get_clean();
        foreach (['super-key', 'super-secret', 'super-pass', 'super-cred'] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
        self::assertStringContainsString('***', $dump);
    }

    public function testInitiatorRequiredWhenAbsent(): void
    {
        $this->expectException(ConfigurationException::class);
        (new DarajaConfig('k', 's', '174379'))->requireInitiator();
    }
}
