<?php

declare(strict_types=1);

namespace Daraja\Support;

use Daraja\Exception\MalformedPayloadException;

/** Daraja timestamps (YmdHis) are East Africa Time, UTC+3, with no zone marker. */
final class EatTime
{
    public static function zone(): \DateTimeZone
    {
        return new \DateTimeZone('Africa/Nairobi');
    }

    /** @throws MalformedPayloadException */
    public static function parse(string $yyyymmddhhmmss): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('!YmdHis', $yyyymmddhhmmss, self::zone());
        if ($time === false || $time->format('YmdHis') !== $yyyymmddhhmmss) {
            throw new MalformedPayloadException('Timestamp is not a valid YmdHis value.');
        }

        return $time;
    }
}
