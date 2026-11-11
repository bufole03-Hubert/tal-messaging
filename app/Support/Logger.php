<?php
declare(strict_types=1);

namespace App\Support;

/** Journal fichier. Les clés sensibles sont masquées automatiquement. */
final class Logger
{
    private const SENSITIVE = [
        'password', 'new_password', 'current_password', 'code', 'token', 'secret',
        'totp_secret', 'temporary_password', 'authorization', 'cookie',
        'apikey', 'api_key', 'africastalking_api_key', 'credentials', 'client_secret',
        'api_key_enc', 'extra_enc'
    ];

    /** @param array<string,mixed> $context */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function scrub(array $data): array
    {
        foreach ($data as $k => $v) {
            if (in_array(strtolower((string) $k), self::SENSITIVE, true)) {
                $data[$k] = '[MASQUÉ]';
            } elseif (is_array($v)) {
                $data[$k] = self::scrub($v);
            }
        }
        return $data;
    }

    /** @param array<string,mixed> $context */
    private static function write(string $level, string $message, array $context): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[TAL][$level] $message");
            return;
        }
        $line = sprintf("[%s] %s: %s %s\n", gmdate('c'), $level, $message, json_encode(self::scrub($context), JSON_UNESCAPED_UNICODE));
        @file_put_contents($dir . '/app-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
