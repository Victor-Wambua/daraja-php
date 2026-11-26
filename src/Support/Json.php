<?php

declare(strict_types=1);

namespace Daraja\Support;

final class Json
{
    public const MAX_DEPTH = 16;

    /**
     * @return array<mixed>
     *
     * @throws \JsonException when the text is not a JSON object
     */
    public static function decodeObject(string $json): array
    {
        $data = json_decode($json, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \JsonException('Expected a JSON object');
        }

        return $data;
    }

    /** @param array<mixed> $data */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
