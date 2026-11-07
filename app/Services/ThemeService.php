<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\ApiException;
use App\Core\Database;

/**
 * Thème de la plateforme : palette claire + sombre et mode par défaut, éditables par le super admin.
 * Stockage : table app_settings (clé "theme"). Rendu : /theme.css (variables CSS "r g b", compatibles opacité Tailwind).
 */
final class ThemeService
{
    private const KEY = 'theme';

    /** @var array<string,string> jeton => libellé */
    public const TOKENS = [
        'canvas' => 'Arrière-plan',
        'surface' => 'Cartes et panneaux',
        'surface2' => 'Zones secondaires',
        'line' => 'Bordures',
        'ink' => 'Texte principal',
        'mute' => 'Texte secondaire',
        'brand' => 'Couleur principale',
        'brandfg' => 'Texte sur couleur principale',
    ];

    public const MODES = ['light', 'dark', 'system'];

    public const DEFAULTS = [
        'light' => [
            'canvas' => '#F5F6F8', 'surface' => '#FFFFFF', 'surface2' => '#EEF0F4', 'line' => '#E2E5EB',
            'ink' => '#0F172A', 'mute' => '#64748B', 'brand' => '#4F46E5', 'brandfg' => '#FFFFFF',
        ],
        'dark' => [
            'canvas' => '#0B0D12', 'surface' => '#12151C', 'surface2' => '#1A1E27', 'line' => '#262B36',
            'ink' => '#E8EAF0', 'mute' => '#8B93A5', 'brand' => '#818CF8', 'brandfg' => '#0B0D12',
        ],
    ];

    /** @var array{default_mode:string,palette:array<string,array<string,string>>}|null */
    private static ?array $cache = null;

    /** @return array{default_mode:string,palette:array<string,array<string,string>>} */
    public static function settings(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $result = ['default_mode' => 'system', 'palette' => self::DEFAULTS];
        try {
            $raw = Database::scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', [self::KEY]);
            $saved = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($saved)) {
                if (in_array($saved['default_mode'] ?? null, self::MODES, true)) {
                    $result['default_mode'] = $saved['default_mode'];
                }
                foreach (['light', 'dark'] as $mode) {
                    foreach (array_keys(self::TOKENS) as $token) {
                        $v = $saved['palette'][$mode][$token] ?? null;
                        if (is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) === 1) {
                            $result['palette'][$mode][$token] = strtoupper($v);
                        }
                    }
                }
            }
        } catch (\Throwable) {
            // Table absente (migration non jouée) ou base indisponible : thème par défaut, jamais d'erreur d'affichage.
        }
        return self::$cache = $result;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{default_mode:string,palette:array<string,array<string,string>>}
     */
    public static function save(array $input, int $userId): array
    {
        $errors = [];
        $mode = $input['default_mode'] ?? 'system';
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            $errors['default_mode'][] = 'Mode invalide (light, dark ou system).';
            $mode = 'system';
        }
        $palette = self::DEFAULTS;
        $given = is_array($input['palette'] ?? null) ? $input['palette'] : [];
        foreach (['light', 'dark'] as $m) {
            foreach (array_keys(self::TOKENS) as $token) {
                $v = $given[$m][$token] ?? null;
                if ($v === null) {
                    continue;
                }
                if (!is_string($v) || preg_match('/^#[0-9A-Fa-f]{6}$/', $v) !== 1) {
                    $errors['palette.' . $m . '.' . $token][] = 'Couleur hexadécimale attendue (ex. #4F46E5).';
                    continue;
                }
                $palette[$m][$token] = strtoupper($v);
            }
        }
        if ($errors) {
            throw ApiException::validation($errors);
        }
        $data = ['default_mode' => $mode, 'palette' => $palette];
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
            [self::KEY, json_encode($data, JSON_UNESCAPED_UNICODE), $userId]
        );
        self::$cache = null;
        return self::settings();
    }

    public static function reset(): void
    {
        Database::execute('DELETE FROM app_settings WHERE setting_key = ?', [self::KEY]);
        self::$cache = null;
    }

    /** Feuille de style des variables de thème (servie par /theme.css). */
    public static function css(): string
    {
        $s = self::settings();
        $block = static function (array $colors): string {
            $out = '';
            foreach ($colors as $token => $hex) {
                $out .= '--c-' . $token . ':' . self::rgb($hex) . ';';
            }
            return $out;
        };
        return ":root{" . $block($s['palette']['light']) . "color-scheme:light}\n"
            . ".dark{" . $block($s['palette']['dark']) . "color-scheme:dark}\n";
    }

    private static function rgb(string $hex): string
    {
        $h = ltrim($hex, '#');
        return hexdec(substr($h, 0, 2)) . ' ' . hexdec(substr($h, 2, 2)) . ' ' . hexdec(substr($h, 4, 2));
    }
}
