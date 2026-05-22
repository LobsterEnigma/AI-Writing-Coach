<?php

namespace Support;

class OpenSourceAccess
{
    private const SESSION_KEY = 'open_source_access_granted';

    public static function isEnabled(array $config): bool
    {
        $password = self::password($config);
        return $password !== '';
    }

    public static function isGranted(array $config): bool
    {
        if (! self::isEnabled($config)) {
            return true;
        }

        return ($_SESSION[self::SESSION_KEY] ?? false) === true;
    }

    public static function attempt(string $inputPassword, array $config): bool
    {
        $password = self::password($config);
        if ($password === '') {
            $_SESSION[self::SESSION_KEY] = true;
            return true;
        }

        if (hash_equals($password, $inputPassword)) {
            $_SESSION[self::SESSION_KEY] = true;
            return true;
        }

        return false;
    }

    public static function clear(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    private static function password(array $config): string
    {
        return (string) ($config['open_source']['access_password'] ?? '');
    }
}
