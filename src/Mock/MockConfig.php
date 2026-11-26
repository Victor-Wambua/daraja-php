<?php

declare(strict_types=1);

namespace Daraja\Mock;

/**
 * Settings for the mock Daraja server. Every credential here is a made-up development value:
 * the mock never talks to Safaricom and accepts nothing real.
 */
final readonly class MockConfig
{
    /**
     * @param list<float> $retryDelays seconds to wait before retry 1, 2, 3...; the last value repeats.
     *                                 With N delays a callback is attempted N + 1 times in total.
     */
    public function __construct(
        public string $consumerKey = 'mock-consumer-key',
        public string $consumerSecret = 'mock-consumer-secret',
        public string $shortCode = '174379',
        public string $passKey = 'mock-passkey-not-a-real-secret',
        public int $tokenTtlSeconds = 3599,
        public int $stkTimeoutSeconds = 60,
        public array $retryDelays = [1.0, 3.0, 10.0, 30.0],
        public string $signingKey = 'mock-signing-key',
        public string $baseUrl = 'http://127.0.0.1:4150',
        public int $callbackTimeoutSeconds = 5,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'consumerKey' => $this->consumerKey,
            'consumerSecret' => $this->consumerSecret,
            'shortCode' => $this->shortCode,
            'passKey' => $this->passKey,
            'tokenTtlSeconds' => $this->tokenTtlSeconds,
            'stkTimeoutSeconds' => $this->stkTimeoutSeconds,
            'retryDelays' => $this->retryDelays,
            'signingKey' => $this->signingKey,
            'baseUrl' => $this->baseUrl,
            'callbackTimeoutSeconds' => $this->callbackTimeoutSeconds,
        ];
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): self
    {
        $defaults = new self();
        $str = static fn (string $k, string $d): string => isset($data[$k]) && \is_string($data[$k]) && $data[$k] !== '' ? $data[$k] : $d;
        $int = static fn (string $k, int $d): int => isset($data[$k]) && \is_int($data[$k]) ? $data[$k] : $d;
        $delays = [];
        if (isset($data['retryDelays']) && \is_array($data['retryDelays'])) {
            foreach ($data['retryDelays'] as $d) {
                if (\is_int($d) || \is_float($d)) {
                    $delays[] = (float) $d;
                }
            }
        }

        return new self(
            consumerKey: $str('consumerKey', $defaults->consumerKey),
            consumerSecret: $str('consumerSecret', $defaults->consumerSecret),
            shortCode: $str('shortCode', $defaults->shortCode),
            passKey: $str('passKey', $defaults->passKey),
            tokenTtlSeconds: $int('tokenTtlSeconds', $defaults->tokenTtlSeconds),
            stkTimeoutSeconds: $int('stkTimeoutSeconds', $defaults->stkTimeoutSeconds),
            retryDelays: $delays !== [] || isset($data['retryDelays']) ? $delays : $defaults->retryDelays,
            signingKey: $str('signingKey', $defaults->signingKey),
            baseUrl: $str('baseUrl', $defaults->baseUrl),
            callbackTimeoutSeconds: $int('callbackTimeoutSeconds', $defaults->callbackTimeoutSeconds),
        );
    }
}
