<?php

declare(strict_types=1);

namespace Daraja\Config;

use Daraja\Exception\ConfigurationException;

/**
 * Immutable settings for one Daraja app. Secrets are hidden from var_dump / print_r / logs.
 */
final readonly class DarajaConfig
{
    public string $baseUrl;

    /**
     * @param string      $consumerKey                Daraja app consumer key
     * @param string      $consumerSecret             Daraja app consumer secret
     * @param string      $shortCode                  Paybill / store number (BusinessShortCode)
     * @param string      $passKey                    Lipa na M-Pesa Online passkey (needed for STK Push and query)
     * @param string|null $baseUrl                    Override the host, e.g. the mock server. Plain http is only accepted for loopback hosts.
     * @param int         $timeoutSeconds             Total per-request timeout used by the bundled cURL client
     * @param int         $tokenSafetyMarginSeconds   Treat a token as expired this many seconds early
     * @param string|null $initiatorName              API initiator username (B2C, Transaction Status, Reversal)
     * @param string|null $securityCredential         Encrypted initiator password; see SecurityCredential
     * @param int         $accountReferenceMaxLength  STK AccountReference limit. 12 is what the docs said when this was written; raise it if Safaricom's current docs differ.
     * @param int         $transactionDescMaxLength   STK TransactionDesc limit. 13 per the docs when this was written; configurable for the same reason.
     */
    public function __construct(
        #[\SensitiveParameter] public string $consumerKey,
        #[\SensitiveParameter] public string $consumerSecret,
        public string $shortCode,
        #[\SensitiveParameter] public string $passKey = '',
        public Environment $environment = Environment::Sandbox,
        ?string $baseUrl = null,
        public int $timeoutSeconds = 30,
        public int $tokenSafetyMarginSeconds = 60,
        public ?string $initiatorName = null,
        #[\SensitiveParameter] public ?string $securityCredential = null,
        public int $accountReferenceMaxLength = 12,
        public int $transactionDescMaxLength = 13,
    ) {
        if ($consumerKey === '' || $consumerSecret === '') {
            throw new ConfigurationException('Consumer key and secret are required.');
        }
        if (preg_match('/^\d{5,7}$/', $shortCode) !== 1) {
            throw new ConfigurationException('Short code must be 5 to 7 digits.');
        }
        if ($timeoutSeconds < 1 || $tokenSafetyMarginSeconds < 0) {
            throw new ConfigurationException('Timeout must be at least 1 second and the safety margin cannot be negative.');
        }
        if ($accountReferenceMaxLength < 1 || $transactionDescMaxLength < 1) {
            throw new ConfigurationException('Field length limits must be positive.');
        }
        $this->baseUrl = self::normaliseBaseUrl($baseUrl ?? $environment->baseUrl());
    }

    /**
     * Build from environment-style variables:
     * DARAJA_ENV (sandbox|production), DARAJA_CONSUMER_KEY, DARAJA_CONSUMER_SECRET, DARAJA_SHORTCODE,
     * DARAJA_PASSKEY, DARAJA_BASE_URL, DARAJA_INITIATOR_NAME, DARAJA_SECURITY_CREDENTIAL.
     *
     * @param array<string, mixed> $env usually $_ENV or getenv()
     */
    public static function fromEnv(array $env): self
    {
        $get = static function (string $key) use ($env): ?string {
            $value = $env[$key] ?? null;

            return \is_string($value) && $value !== '' ? $value : null;
        };

        $envName = $get('DARAJA_ENV') ?? 'sandbox';
        $environment = Environment::tryFrom($envName)
            ?? throw new ConfigurationException('DARAJA_ENV must be "sandbox" or "production".');

        return new self(
            consumerKey: $get('DARAJA_CONSUMER_KEY') ?? throw new ConfigurationException('DARAJA_CONSUMER_KEY is not set.'),
            consumerSecret: $get('DARAJA_CONSUMER_SECRET') ?? throw new ConfigurationException('DARAJA_CONSUMER_SECRET is not set.'),
            shortCode: $get('DARAJA_SHORTCODE') ?? throw new ConfigurationException('DARAJA_SHORTCODE is not set.'),
            passKey: $get('DARAJA_PASSKEY') ?? '',
            environment: $environment,
            baseUrl: $get('DARAJA_BASE_URL'),
            initiatorName: $get('DARAJA_INITIATOR_NAME'),
            securityCredential: $get('DARAJA_SECURITY_CREDENTIAL'),
        );
    }

    public function isProduction(): bool
    {
        return $this->environment === Environment::Production;
    }

    /**
     * @return array{name: string, credential: string}
     *
     * @throws ConfigurationException when the initiator settings are missing
     */
    public function requireInitiator(): array
    {
        if ($this->initiatorName === null || $this->initiatorName === '' || $this->securityCredential === null || $this->securityCredential === '') {
            throw new ConfigurationException('initiatorName and securityCredential are required for B2C, Transaction Status and Reversal.');
        }

        return ['name' => $this->initiatorName, 'credential' => $this->securityCredential];
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'environment' => $this->environment->value,
            'baseUrl' => $this->baseUrl,
            'shortCode' => $this->shortCode,
            'consumerKey' => '***',
            'consumerSecret' => '***',
            'passKey' => $this->passKey === '' ? '' : '***',
            'initiatorName' => $this->initiatorName,
            'securityCredential' => $this->securityCredential === null ? null : '***',
        ];
    }

    private static function normaliseBaseUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = \is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = \is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || !\in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException('Base URL must be an absolute http(s) URL.');
        }
        if ($scheme === 'http' && !\in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            throw new ConfigurationException('Plain http is only allowed for loopback hosts (the mock server). Use https.');
        }

        return rtrim($url, '/');
    }
}
