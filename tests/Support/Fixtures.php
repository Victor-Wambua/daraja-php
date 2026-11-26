<?php

declare(strict_types=1);

namespace Daraja\Tests\Support;

final class Fixtures
{
    public static function raw(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/../Fixtures/' . $name . '.json');
        if ($content === false) {
            throw new \RuntimeException('Missing fixture ' . $name);
        }

        return $content;
    }

    /** @return array<mixed> */
    public static function array(string $name): array
    {
        $data = json_decode(self::raw($name), true, 32, JSON_THROW_ON_ERROR);
        \assert(\is_array($data));

        return $data;
    }

    /** Re-encode a fixture after changing it. */
    public static function with(string $name, callable $mutate): string
    {
        return json_encode($mutate(self::array($name)), JSON_THROW_ON_ERROR);
    }
}
