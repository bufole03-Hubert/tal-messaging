<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;

/**
 * Règles de conservation des données (réglables par le Data & Compliance Manager) et purge.
 * Stockées dans app_settings (clé « retention »). La purge est globale : elle s'exécute hors contexte tenant
 * (script cron ou bouton réservé aux comptes internes).
 */
final class RetentionService
{
    private const KEY = 'retention';
    private const LIMITS = ['import_files_days' => [1, 3650], 'import_errors_days' => [1, 3650], 'deleted_contacts_days' => [1, 3650]];

    /** @return array<string,int> */
    public static function get(): array
    {
        $defaults = (array) Config::get('contacts.retention_defaults');
        $raw = Database::scalar('SELECT setting_value FROM app_settings WHERE setting_key = ?', [self::KEY]);
        $saved = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $out = [];
        foreach (self::LIMITS as $k => [$min, $max]) {
            $v = isset($saved[$k]) ? (int) $saved[$k] : (int) $defaults[$k];
            $out[$k] = max($min, min($max, $v));
        }
        return $out;
    }

    /** @param array<string,mixed> $in @return array<string,int> */
    public static function save(array $in): array
    {
        $new = [];
        foreach (self::LIMITS as $k => [$min, $max]) {
            $v = filter_var($in[$k] ?? null, FILTER_VALIDATE_INT);
            if ($v === false || $v < $min || $v > $max) {
                throw ApiException::validation([$k => ["Entier entre $min et $max jours attendu."]]);
            }
            $new[$k] = $v;
        }
        Database::execute(
            'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [self::KEY, json_encode($new)]
        );
        return $new;
    }

    /** @return array<string,int> compteurs de ce qui a été supprimé */
    public static function purge(): array
    {
        $r = self::get();
        $res = ['import_files' => 0, 'import_errors' => 0, 'contacts' => 0];

        $files = Database::select(
            "SELECT id, file_path FROM contact_imports WHERE file_path IS NOT NULL AND status IN ('completed','cancelled','failed')
             AND COALESCE(completed_at, updated_at) < ?",
            [gmdate('Y-m-d H:i:s', strtotime('-' . $r['import_files_days'] . ' days'))]
        );
        foreach ($files as $f) {
            $path = BASE_PATH . '/storage/' . $f['file_path'];
            if (str_starts_with((string) $f['file_path'], 'imports/') && is_file($path)) {
                @unlink($path);
            }
            Database::execute('UPDATE contact_imports SET file_path = NULL WHERE id = ?', [$f['id']]);
            $res['import_files']++;
        }

        $res['import_errors'] = Database::execute(
            'DELETE FROM contact_import_errors WHERE created_at < ?',
            [gmdate('Y-m-d H:i:s', strtotime('-' . $r['import_errors_days'] . ' days'))]
        );

        // Suppression définitive : étiquettes, groupes et historique partent en cascade (clés étrangères).
        $res['contacts'] = Database::execute(
            'DELETE FROM contacts WHERE deleted_at IS NOT NULL AND deleted_at < ?',
            [gmdate('Y-m-d H:i:s', strtotime('-' . $r['deleted_contacts_days'] . ' days'))]
        );
        return $res;
    }
}
