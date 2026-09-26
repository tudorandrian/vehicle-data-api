<?php

declare(strict_types=1);

namespace VehicleData\Core\Auth;

final class ApiKey
{
    public const PREFIX = 'vd_live_';

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public static function generate(): string
    {
        $out = '';
        for ($i = 0; $i < 40; $i++) {
            $out .= self::ALPHABET[random_int(0, 61)];
        }

        return self::PREFIX.$out;
    }

    public static function looksValid(string $key): bool
    {
        return preg_match('/^vd_live_[0-9A-Za-z]{40}$/', $key) === 1;
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    public static function prefix(string $key): string
    {
        return substr($key, strlen(self::PREFIX), 8);
    }
}
