<?php

declare(strict_types=1);

namespace Daraja\Auth;

use Daraja\Config\DarajaConfig;
use Daraja\Exception\AuthenticationException;
use Daraja\Exception\MalformedPayloadException;
use Daraja\Http\Psr7\Request;
use Daraja\Http\Transport;
use Daraja\Support\Clock;
use Daraja\Support\Json;
use Daraja\Support\Payload;
use Psr\SimpleCache\CacheInterface;

/**
 * Fetches the OAuth token once and reuses it.
 *
 * - The token is kept in a PSR-16 cache with a TTL shortened by a safety margin, so a call
 *   never leaves with a token that expires mid-flight.
 * - Inside one process the token is also memoised, so concurrent code paths share one fetch.
 * - Across processes PSR-16 has no atomic "add", so there is no lock. Instead the cache entry
 *   carries the fetch cost and uses probabilistic early refresh (the "XFetch" scheme): as expiry
 *   approaches, each caller has a small, growing chance of refreshing early, which spreads the
 *   refreshes out instead of letting every worker hit Daraja at the same second.
 */
final class CachedTokenProvider implements TokenProvider
{
    private const EARLY_REFRESH_BETA = 1.0;

    private ?AccessToken $memo = null;

    /**
     * Replaceable in tests so the early-refresh dice are deterministic.
     *
     * @var \Closure(): float
     */
    private \Closure $random;

    /** @param (\Closure(): float)|null $random a function returning a float in (0, 1] */
    public function __construct(
        private readonly DarajaConfig $config,
        private readonly Transport $transport,
        private readonly CacheInterface $cache,
        private readonly Clock $clock,
        ?\Closure $random = null,
    ) {
        $this->random = $random ?? static fn (): float => random_int(1, PHP_INT_MAX) / PHP_INT_MAX;
    }

    public function token(): string
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->memo !== null && $this->memo->expiresAt > $now) {
            return $this->memo->value;
        }

        $cached = $this->cache->get($this->cacheKey());
        if (\is_array($cached)) {
            $value = $cached['value'] ?? null;
            $expiresAt = $cached['expiresAt'] ?? null;
            $fetchSeconds = $cached['fetchSeconds'] ?? 0.0;
            if (\is_string($value) && \is_int($expiresAt) && $expiresAt > $now && (\is_float($fetchSeconds) || \is_int($fetchSeconds))
                && !$this->shouldRefreshEarly($expiresAt, (float) $fetchSeconds, $now)) {
                $this->memo = new AccessToken($value, $expiresAt);

                return $value;
            }
        }

        return $this->fetch()->value;
    }

    public function invalidate(): void
    {
        $this->memo = null;
        $this->cache->delete($this->cacheKey());
    }

    private function shouldRefreshEarly(int $expiresAt, float $fetchSeconds, int $now): bool
    {
        $r = max(($this->random)(), 1.0E-12);

        return $now - $fetchSeconds * self::EARLY_REFRESH_BETA * log($r) >= $expiresAt;
    }

    private function fetch(): AccessToken
    {
        $started = hrtime(true);
        $request = new Request(
            'GET',
            $this->config->baseUrl . '/oauth/v1/generate?grant_type=client_credentials',
            [
                'Authorization' => 'Basic ' . base64_encode($this->config->consumerKey . ':' . $this->config->consumerSecret),
                'Accept' => 'application/json',
            ],
        );
        $response = $this->transport->send($request);
        $fetchSeconds = (hrtime(true) - $started) / 1_000_000_000;
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status < 200 || $status >= 300) {
            $e = AuthenticationException::fromResponse($status, $body);
            throw new AuthenticationException('Could not obtain an access token: ' . $e->getMessage(), $status, $e->errorCode, $e->errorMessage, $e->requestId, $e->rawBody);
        }

        try {
            $payload = Payload::root(Json::decodeObject($body));
            $value = $payload->string('access_token');
            $lifetime = $payload->int('expires_in');
        } catch (\JsonException|MalformedPayloadException $e) {
            throw new MalformedPayloadException('Daraja token response was not understood: ' . $e->getMessage(), 0, $e);
        }
        if ($lifetime < 1) {
            throw new MalformedPayloadException('Daraja token response had a non-positive expires_in.');
        }

        $now = $this->clock->now()->getTimestamp();
        // Never keep a token past (expiry - margin). For very short-lived tokens keep at least one second.
        $ttl = max(1, $lifetime - $this->config->tokenSafetyMarginSeconds);
        $effectiveExpiry = $now + $ttl;

        $this->cache->set($this->cacheKey(), [
            'value' => $value,
            'expiresAt' => $effectiveExpiry,
            'fetchSeconds' => $fetchSeconds,
        ], $ttl);
        $this->memo = new AccessToken($value, $effectiveExpiry);

        return $this->memo;
    }

    private function cacheKey(): string
    {
        return 'daraja_token_' . hash('sha256', $this->config->baseUrl . '|' . $this->config->consumerKey);
    }
}
