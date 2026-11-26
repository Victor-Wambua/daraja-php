<?php

declare(strict_types=1);

namespace Daraja\Safety;

use Daraja\Exception\ConfigurationException;

/**
 * An allow-list of IPv4/IPv6 addresses and CIDR ranges for callback endpoints.
 *
 * This library ships NO Safaricom addresses. The list of source IPs Safaricom publishes can change, and
 * a wrong hard-coded list either blocks real payments or gives false confidence. Take the current list from
 * Safaricom's documentation or your account manager and pass it in. An empty list is refused.
 *
 * Behind a proxy or load balancer, REMOTE_ADDR is the proxy. Resolve the real client address yourself (and only
 * trust X-Forwarded-For when it was set by your own proxy) before calling isAllowed().
 */
final readonly class IpAllowList
{
    /** @var list<array{string, int}> packed address (binary) and prefix length */
    private array $ranges;

    /** @param list<string> $entries e.g. ['203.0.113.10', '198.51.100.0/24', '2001:db8::/32'] (documentation addresses) */
    public function __construct(array $entries)
    {
        if ($entries === []) {
            throw new ConfigurationException('The IP allow-list is empty. Supply the addresses you trust, taken from Safaricom.');
        }
        $ranges = [];
        foreach ($entries as $entry) {
            $ranges[] = self::parseEntry($entry);
        }
        $this->ranges = $ranges;
    }

    public function isAllowed(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($this->ranges as [$network, $prefix]) {
            if (\strlen($network) === \strlen($packed) && self::matches($packed, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{string, int} */
    private static function parseEntry(string $entry): array
    {
        $entry = trim($entry);
        $parts = explode('/', $entry, 2);
        $packed = @inet_pton($parts[0]);
        if ($packed === false) {
            throw new ConfigurationException(\sprintf('"%s" is not a valid IP address or CIDR range.', $entry));
        }
        $max = \strlen($packed) * 8;
        $prefix = $max;
        if (isset($parts[1])) {
            if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $max) {
                throw new ConfigurationException(\sprintf('"%s" has an invalid prefix length.', $entry));
            }
            $prefix = (int) $parts[1];
        }

        return [$packed, $prefix];
    }

    private static function matches(string $ip, string $network, int $prefix): bool
    {
        $fullBytes = intdiv($prefix, 8);
        if ($fullBytes > 0 && substr($ip, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }
        $remaining = $prefix % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $remaining)) & 0xFF;

        return (\ord($ip[$fullBytes]) & $mask) === (\ord($network[$fullBytes]) & $mask);
    }
}
