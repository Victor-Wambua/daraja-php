<?php

declare(strict_types=1);

namespace Daraja\Stk;

/** The STK "Password" field: base64(shortcode . passkey . timestamp). */
final class StkPassword
{
    public static function generate(string $shortCode, #[\SensitiveParameter] string $passKey, string $timestamp): string
    {
        return base64_encode($shortCode . $passKey . $timestamp);
    }

    /**
     * Timestamp in the YmdHis format, in East Africa Time (UTC+3), the time zone Safaricom runs in.
     */
    public static function timestamp(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('Africa/Nairobi'))->format('YmdHis');
    }
}
