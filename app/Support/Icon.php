<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Icônes via la police d'icônes Tabler (npm : @tabler/icons-webfont), servie en local.
 * Aucun SVG inline : une icône = <i class="ti ti-nom">. Les noms ci-dessous sont des alias internes ;
 * tout autre nom est utilisé tel quel (ex. icon('bell') → ti-bell). Catalogue : https://tabler.io/icons
 */
final class Icon
{
    /** @var array<string,string> */
    private const ALIASES = [
        'home' => 'home-2',
        'users' => 'users',
        'user' => 'user',
        'building' => 'building-skyscraper',
        'shield' => 'shield-lock',
        'lock' => 'lock',
        'logout' => 'logout-2',
        'flag' => 'flag',
        'file-text' => 'file-text',
        'chart' => 'chart-line',
        'server' => 'server-2',
        'headset' => 'headset',
        'send' => 'send',
        'database' => 'database',
        'briefcase' => 'briefcase',
        'menu' => 'menu-2',
        'check' => 'check',
        'palette' => 'palette',
        'sun' => 'sun',
        'moon' => 'moon',
        'system' => 'device-desktop',
    ];

    public static function tag(string $name, string $class = ''): string
    {
        $icon = self::ALIASES[$name] ?? $name;
        if (preg_match('/^[a-z0-9-]+$/', $icon) !== 1) {
            $icon = 'file-text';
        }
        return '<i class="ti ti-' . $icon . ($class !== '' ? ' ' . htmlspecialchars($class, ENT_QUOTES) : '') . '" aria-hidden="true"></i>';
    }
}
