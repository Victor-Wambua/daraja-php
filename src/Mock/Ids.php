<?php

declare(strict_types=1);

namespace Daraja\Mock;

/** Id generators that imitate the look of Daraja's identifiers. All random, all fake. */
final class Ids
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';

    /** 10-character receipt such as "QK7MXH2T9A". */
    public static function receipt(): string
    {
        $out = '';
        for ($i = 0; $i < 10; ++$i) {
            $out .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
        }

        return $out;
    }

    public static function merchantRequestId(): string
    {
        return \sprintf('%d-%d-%d', random_int(10000, 99999), random_int(10000000, 99999999), random_int(1, 9));
    }

    public static function checkoutRequestId(\DateTimeImmutable $now): string
    {
        return 'ws_CO_' . $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('YmdHis') . random_int(100000000, 999999999);
    }

    public static function conversationId(): string
    {
        return 'AG_' . gmdate('Ymd') . '_' . bin2hex(random_bytes(10));
    }

    public static function originatorConversationId(): string
    {
        return \sprintf('%d-%d-%d', random_int(1000, 9999), random_int(10000000, 99999999), random_int(1, 9));
    }
}
