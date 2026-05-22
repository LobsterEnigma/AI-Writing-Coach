<?php

namespace Support;

use PDO;

class AppSettings
{
    private static ?array $cache = null;

    public static function get(PDO $pdo): array
    {
        if (self::$cache === null) {
            self::reload($pdo);
        }

        return self::$cache ?? [];
    }

    public static function reload(PDO $pdo): void
    {
        $stmt = $pdo->query('SELECT * FROM ai_settings WHERE id = 1 LIMIT 1');
        self::$cache = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) ?: [] : [];
    }

    public static function refresh(PDO $pdo): void
    {
        self::$cache = null;
        self::get($pdo);
    }

    public static function registrationEnabled(PDO $pdo): bool
    {
        $settings = self::get($pdo);
        return (bool) ($settings['registration_enabled'] ?? 1);
    }

    public static function maintenanceMode(PDO $pdo): bool
    {
        $settings = self::get($pdo);
        return (bool) ($settings['maintenance_mode'] ?? 0);
    }

    public static function baseUrl(PDO $pdo): string
    {
        $settings = self::get($pdo);
        return (string) ($settings['base_url'] ?? '');
    }

    public static function model(PDO $pdo): string
    {
        $settings = self::get($pdo);
        return (string) ($settings['model'] ?? '');
    }

    public static function summaryHistoryLimit(PDO $pdo): int
    {
        $settings = self::get($pdo);
        return (int) ($settings['summary_history_limit'] ?? 8);
    }

    public static function summaryRefreshDays(PDO $pdo): int
    {
        $settings = self::get($pdo);
        return (int) ($settings['summary_refresh_days'] ?? 30);
    }
}
