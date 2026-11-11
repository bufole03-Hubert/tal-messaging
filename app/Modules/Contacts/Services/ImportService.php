<?php
declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\ApiException;
use App\Core\Config;
use App\Core\Database;
use App\Modules\Contacts\Repositories\ContactRepository;
use App\Modules\Contacts\Repositories\GroupRepository;
use App\Modules\Contacts\Repositories\ImportRepository;
use App\Services\AuditService;
use App\Support\PhoneNumber;

/**
 * Import CSV en 4 temps : upload (aperçu) → start (mapping + options) → process (lots de N lignes, répétable)
 * → rapport. Le traitement est reprenable : chaque lot est transactionnel et avance un offset d'octets.
 * Fichiers stockés hors du dossier public : storage/imports/{organisation}/{uuid}.csv
 */
final class ImportService
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    /* ------------------------------------------------------------------ Upload */

    /**
     * @param array<string,mixed> $file entrée de $_FILES
     * @return array<string,mixed>
     */
    public static function upload(array $file, int $orgId, int $actorId): array
    {
        $cfg = (array) Config::get('contacts.import');
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw ApiException::validation(['file' => ['Fichier trop volumineux (' . intdiv((int) $cfg['max_bytes'], 1048576) . ' Mo max, ou limite PHP upload_max_filesize).']]);
        }
        if ($err !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            throw ApiException::validation(['file' => ['Aucun fichier reçu.']]);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > (int) $cfg['max_bytes']) {
            throw ApiException::validation(['file' => ['Fichier vide ou supérieur à ' . intdiv((int) $cfg['max_bytes'], 1048576) . ' Mo.']]);
        }
        $name = (string) ($file['name'] ?? 'import.csv');
        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['csv', 'txt'], true)) {
            throw ApiException::validation(['file' => ['Format non pris en charge : exportez votre feuille Excel en CSV (UTF-8).']]);
        }
        $tmp = (string) $file['tmp_name'];
        $head = (string) file_get_contents($tmp, false, null, 0, 65536);
        if (str_contains($head, "\0")) {
            throw ApiException::validation(['file' => ['Fichier binaire détecté (Excel .xlsx ?). Exportez-le en CSV.']]);
        }

        $encoding = (str_starts_with($head, "\xEF\xBB\xBF") || mb_check_encoding($head, 'UTF-8')) ? 'UTF-8' : 'Windows-1252';
        $delimiter = self::detectDelimiter($head);

        $uuid = uuid4();
        $dir = BASE_PATH . '/storage/imports/' . $orgId;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier d’import.');
        }
        $rel = 'imports/' . $orgId . '/' . $uuid . '.csv';
        if (!move_uploaded_file($tmp, BASE_PATH . '/storage/' . $rel)) {
            throw new \RuntimeException('Impossible d’enregistrer le fichier.');
        }
        $path = BASE_PATH . '/storage/' . $rel;

        try {
            [$columns, $total, $preview] = self::scan($path, $delimiter, $encoding, (int) $cfg['max_rows']);
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }

        $repo = new ImportRepository();
        $id = $repo->insert([
            'uuid' => $uuid,
            'organization_id' => $orgId,
            'created_by' => $actorId,
            'filename' => mb_substr(preg_replace('/[^\p{L}\p{N}._\- ]+/u', '_', basename($name)) ?? 'import.csv', 0, 180),
            'file_path' => $rel,
            'delimiter' => $delimiter,
            'encoding' => $encoding,
            'columns_json' => json_encode($columns, JSON_UNESCAPED_UNICODE),
            'status' => 'uploaded',
            'total_rows' => $total,
        ]);

        return [
            'id' => $id,
            'filename' => $name,
            'total_rows' => $total,
            'columns' => $columns,
            'preview' => $preview,
            'suggested_mapping' => self::suggestMapping($columns),
            'delimiter' => $delimiter === "\t" ? 'tab' : $delimiter,
            'encoding' => $encoding,
        ];
    }

    private static function detectDelimiter(string $head): string
    {
        $line = strtok($head, "\r\n") ?: '';
        $best = ',';
        $max = 0;
        foreach (self::DELIMITERS as $d) {
            $n = substr_count($line, $d);
            if ($n > $max) {
                $max = $n;
                $best = $d;
            }
        }
        return $best;
    }

    /**
     * Lit l'entête, compte les lignes non vides (plafonné) et prélève 5 lignes d'aperçu.
     * @return array{0:array<int,string>,1:int,2:array<int,array<int,string>>}
     */
    private static function scan(string $path, string $delimiter, string $encoding, int $maxRows): array
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new \RuntimeException('Fichier illisible.');
        }
        $header = fgetcsv($h, 0, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            fclose($h);
            throw ApiException::validation(['file' => ['Le fichier est vide.']]);
        }
        $columns = [];
        foreach ($header as $i => $c) {
            $c = self::text((string) $c, $encoding);
            if ($i === 0) {
                $c = ltrim($c, "\xEF\xBB\xBF");
            }
            $columns[] = $c !== '' ? mb_substr($c, 0, 80) : 'Colonne ' . ($i + 1);
        }
        $total = 0;
        $preview = [];
        while (($row = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            if (self::isEmptyRow($row)) {
                continue;
            }
            $total++;
            if ($total > $maxRows) {
                fclose($h);
                throw ApiException::validation(['file' => ['Trop de lignes (' . $maxRows . ' maximum par import). Découpez le fichier.']]);
            }
            if (count($preview) < 5) {
                $preview[] = array_map(static fn($v): string => mb_substr(self::text((string) $v, $encoding), 0, 120), $row);
            }
        }
        fclose($h);
        if ($total === 0) {
            throw ApiException::validation(['file' => ['Le fichier ne contient aucune ligne de données.']]);
        }
        return [$columns, $total, $preview];
    }

    /** @param array<int,string> $columns @return array<string,int|null> */
    private static function suggestMapping(array $columns): array
    {
        $rules = [
            'phone' => '/(phone|t[ée]l|mobile|gsm|num[ée]ro|numero|msisdn|whatsapp|contact)/iu',
            'email' => '/(e-?mail|courriel|mail)/iu',
            'first_name' => '/(pr[ée]nom|first|given)/iu',
            'last_name' => '/(^nom$|last|family|surname|^name$|^nom\b)/iu',
            'country' => '/(pays|country)/iu',
            'tags' => '/(tag|[ée]tiquette|label)/iu',
        ];
        $map = array_fill_keys(array_keys($rules), null);
        foreach ($columns as $i => $name) {
            foreach ($rules as $field => $rx) {
                if ($map[$field] === null && preg_match($rx, $name) === 1 && !in_array($i, $map, true)) {
                    $map[$field] = $i;
                }
            }
        }
        return $map;
    }

    /* ------------------------------------------------------------------- Start */

    /**
     * @param array<string,mixed> $mapping
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public static function start(int $id, array $mapping, array $options, int $actorId): array
    {
        $repo = new ImportRepository();
        $imp = $repo->find($id) ?? throw ApiException::notFound('Import introuvable.');
        if ($imp['status'] !== 'uploaded') {
            throw ApiException::conflict('Cet import a déjà été lancé.', 'IMPORT_ALREADY_STARTED');
        }
        $orgId = (int) $imp['organization_id'];
        $columns = json_decode((string) $imp['columns_json'], true) ?: [];
        $nCols = count($columns);

        $col = static function (mixed $v) use ($nCols): ?int {
            if ($v === null || $v === '' || $v === false) {
                return null;
            }
            $i = filter_var($v, FILTER_VALIDATE_INT);
            if ($i === false || $i < 0 || $i >= $nCols) {
                throw ApiException::validation(['mapping' => ['Colonne inconnue dans le mappage.']]);
            }
            return $i;
        };
        $map = [
            'phone' => $col($mapping['phone'] ?? null),
            'first_name' => $col($mapping['first_name'] ?? null),
            'last_name' => $col($mapping['last_name'] ?? null),
            'email' => $col($mapping['email'] ?? null),
            'country' => $col($mapping['country'] ?? null),
            'tags' => $col($mapping['tags'] ?? null),
            'custom' => [],
        ];
        if ($map['phone'] === null) {
            throw ApiException::validation(['mapping' => ['Indiquez la colonne du numéro de téléphone.']]);
        }
        $customMax = (int) Config::get('contacts.import.max_custom_columns');
        foreach ((array) ($mapping['custom'] ?? []) as $c) {
            if (!is_array($c)) {
                continue;
            }
            $key = strtolower(trim((string) ($c['key'] ?? '')));
            $ci = $col($c['column'] ?? null);
            if ($ci === null || preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
                throw ApiException::validation(['mapping' => ['Champ personnalisé invalide (nom : minuscules, chiffres, _).']]);
            }
            $map['custom'][$key] = $ci;
        }
        if (count($map['custom']) > $customMax) {
            throw ApiException::validation(['mapping' => ["$customMax champs personnalisés maximum."]]);
        }

        $mode = (string) ($options['duplicate_mode'] ?? 'skip');
        if (!in_array($mode, ['skip', 'update', 'merge'], true)) {
            throw ApiException::validation(['duplicate_mode' => ['Règle de doublon inconnue.']]);
        }
        $country = strtoupper((string) ($options['default_country'] ?? Config::get('contacts.default_country')));
        if (!isset(((array) Config::get('countries.countries'))[$country])) {
            throw ApiException::validation(['default_country' => ['Pays par défaut inconnu.']]);
        }
        $groupId = null;
        if (!empty($options['group_id'])) {
            $g = (new GroupRepository())->find((int) $options['group_id']);
            if ($g === null || (int) $g['organization_id'] !== $orgId) {
                throw ApiException::validation(['group_id' => ['Groupe introuvable.']]);
            }
            $groupId = (int) $g['id'];
        } elseif (!empty($options['new_group_name'])) {
            $name = trim((string) $options['new_group_name']);
            if ($name === '' || mb_strlen($name) > 100) {
                throw ApiException::validation(['new_group_name' => ['Nom de groupe invalide (100 caractères max).']]);
            }
            $groupId = GroupService::createOrFind($orgId, $name, $actorId);
        }
        $tags = ContactService::tags($options['tags'] ?? []);

        $repo->update($id, [
            'mapping' => json_encode($map, JSON_UNESCAPED_UNICODE),
            'options' => json_encode(['duplicate_mode' => $mode, 'default_country' => $country, 'group_id' => $groupId, 'tags' => $tags], JSON_UNESCAPED_UNICODE),
            'status' => 'processing',
            'started_at' => gmdate('Y-m-d H:i:s'),
        ]);
        AuditService::log('contacts.import_started', 'contact_import', $id, ['total_rows' => (int) $imp['total_rows']], $actorId, $orgId);
        return self::progress($id);
    }

    /* ----------------------------------------------------------------- Process */

    /** @return array<string,mixed> */
    public static function process(int $id, int $actorId): array
    {
        $repo = new ImportRepository();
        $found = $repo->find($id) ?? throw ApiException::notFound('Import introuvable.');
        $orgId = (int) $found['organization_id'];

        $done = Database::transaction(function () use ($id, $orgId, $actorId): bool {
            // Verrou de ligne : deux appels simultanés ne traitent jamais le même lot.
            $imp = Database::first('SELECT * FROM contact_imports WHERE id = ? AND organization_id = ? FOR UPDATE', [$id, $orgId]);
            if ($imp === null || $imp['status'] !== 'processing') {
                return false;
            }
            return self::processChunk($imp, $actorId);
        });

        if ($done) {
            AuditService::log('contacts.import_completed', 'contact_import', $id, [], $actorId, $orgId);
        }
        return self::progress($id);
    }

    /** @param array<string,mixed> $imp @return bool vrai si le fichier est entièrement traité */
    private static function processChunk(array $imp, int $actorId): bool
    {
        $id = (int) $imp['id'];
        $orgId = (int) $imp['organization_id'];
        $map = json_decode((string) $imp['mapping'], true) ?: [];
        $opt = json_decode((string) $imp['options'], true) ?: [];
        $mode = (string) ($opt['duplicate_mode'] ?? 'skip');
        $country = (string) ($opt['default_country'] ?? 'CD');
        $groupId = $opt['group_id'] ?? null;
        $importTags = (array) ($opt['tags'] ?? []);
        $encoding = (string) $imp['encoding'];
        $delimiter = (string) $imp['delimiter'];
        $chunk = (int) Config::get('contacts.import.chunk_size');
        $path = BASE_PATH . '/storage/' . $imp['file_path'];

        $h = is_file($path) ? fopen($path, 'rb') : false;
        if ($h === false) {
            Database::execute("UPDATE contact_imports SET status = 'failed' WHERE id = ?", [$id]);
            throw ApiException::conflict('Le fichier source n’est plus disponible (conservation dépassée ?).', 'IMPORT_FILE_MISSING');
        }
        $offset = (int) $imp['byte_offset'];
        $lineNo = (int) $imp['processed_rows'];
        if ($offset === 0) {
            fgetcsv($h, 0, $delimiter, '"', ''); // entête
        } else {
            fseek($h, $offset);
        }

        // 1) Lecture du lot
        $batch = [];
        $eof = false;
        while (count($batch) < $chunk) {
            $row = fgetcsv($h, 0, $delimiter, '"', '');
            if ($row === false) {
                $eof = true;
                break;
            }
            if (self::isEmptyRow($row)) {
                continue;
            }
            $lineNo++;
            $batch[] = ['line' => $lineNo, 'row' => array_map(static fn($v): string => self::text((string) $v, $encoding), $row)];
        }
        $newOffset = (int) ftell($h);
        if (!$eof && fgetcsv($h, 0, $delimiter, '"', '') === false) {
            $eof = true; // plus rien après ce lot
        }
        fclose($h);

        // 2) Normalisation
        $get = static fn(array $row, ?int $i): ?string => ($i !== null && isset($row[$i]) && trim($row[$i]) !== '') ? trim($row[$i]) : null;
        $valid = [];
        $errors = [];
        $seen = [];
        $invalid = 0;
        $dups = 0;
        foreach ($batch as $b) {
            $row = $b['row'];
            $rawPhone = (string) ($get($row, $map['phone']) ?? '');
            $p = PhoneNumber::normalize($rawPhone, $country);
            if (!$p['ok']) {
                $invalid++;
                $errors[] = [$b['line'], 'invalid', $rawPhone, PhoneNumber::reasonLabel((string) $p['reason']), $row];
                continue;
            }
            $e164 = (string) $p['e164'];
            if (isset($seen[$e164])) {
                $dups++;
                $errors[] = [$b['line'], 'duplicate', $rawPhone, 'Doublon dans le fichier (ligne ' . $seen[$e164] . ')', $row];
                continue;
            }
            $seen[$e164] = $b['line'];

            $email = $get($row, $map['email']);
            if ($email !== null) {
                $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
            }
            $attrs = [];
            foreach ((array) ($map['custom'] ?? []) as $key => $ci) {
                $v = $get($row, (int) $ci);
                if ($v !== null) {
                    $attrs[(string) $key] = mb_substr($v, 0, 255);
                }
            }
            $rowTags = [];
            $tagCell = $get($row, $map['tags'] ?? null);
            if ($tagCell !== null) {
                foreach (preg_split('/[;,|]/', $tagCell) ?: [] as $t) {
                    $t = mb_strtolower(trim($t));
                    if ($t !== '' && preg_match('/^[\p{L}\p{N}_\- ]{1,50}$/u', $t) === 1) {
                        $rowTags[] = $t;
                    }
                }
            }
            $rowCountry = $p['country'];
            $cc = $get($row, $map['country'] ?? null);
            if ($rowCountry === null && $cc !== null && preg_match('/^[A-Za-z]{2}$/', $cc) === 1) {
                $rowCountry = strtoupper($cc);
            }
            $valid[$e164] = [
                'line' => $b['line'], 'raw' => $rawPhone, 'row' => $row, 'e164' => $e164, 'country' => $rowCountry, 'operator' => $p['operator'],
                'first_name' => self::cut($get($row, $map['first_name'] ?? null), 80),
                'last_name' => self::cut($get($row, $map['last_name'] ?? null), 80),
                'email' => $email !== null ? mb_substr($email, 0, 190) : null,
                'attrs' => $attrs,
                'tags' => array_values(array_unique(array_merge($rowTags, $importTags))),
            ];
        }

        // 3) Contacts déjà présents (une seule requête pour le lot)
        $existing = [];
        if ($valid) {
            $keys = array_keys($valid);
            foreach (Database::select(
                'SELECT id, phone_e164, first_name, last_name, email, country, operator, attributes, import_id FROM contacts
                 WHERE organization_id = ? AND deleted_at IS NULL AND phone_e164 IN (' . implode(',', array_fill(0, count($keys), '?')) . ')',
                array_merge([$orgId], $keys)
            ) as $r) {
                $existing[(string) $r['phone_e164']] = $r;
            }
        }

        // 4) Écriture
        $contacts = new ContactRepository();
        $created = 0;
        $updated = 0;
        $touched = []; // ids de contacts créés ou mis à jour : groupes + étiquettes
        foreach ($valid as $e164 => $v) {
            $ex = $existing[$e164] ?? null;
            if ($ex === null) {
                $cid = $contacts->insert([
                    'uuid' => uuid4(), 'organization_id' => $orgId, 'phone_e164' => $e164,
                    'first_name' => $v['first_name'], 'last_name' => $v['last_name'], 'email' => $v['email'],
                    'country' => $v['country'], 'operator' => $v['operator'], 'status' => 'active', 'source' => 'import', 'import_id' => $id,
                    'attributes' => $v['attrs'] ? json_encode($v['attrs'], JSON_UNESCAPED_UNICODE) : null, 'created_by' => $actorId,
                ]);
                $created++;
                $touched[$cid] = $v['tags'];
                continue;
            }
            if ($mode === 'skip') {
                $dups++;
                $why = ((int) $ex['import_id'] === $id) ? 'Doublon dans le fichier (déjà créé par cet import)' : 'Existe déjà dans la base';
                $errors[] = [$v['line'], 'duplicate', $v['raw'], $why, $v['row']];
                continue;
            }
            // update : les valeurs du fichier remplacent. merge : seules les cases vides sont complétées.
            $upd = [];
            $diff = [];
            foreach (['first_name', 'last_name', 'email'] as $f) {
                $new = $v[$f];
                if ($new === null) {
                    continue;
                }
                if ($mode === 'update' ? $new !== $ex[$f] : ($ex[$f] === null || $ex[$f] === '')) {
                    $upd[$f] = $new;
                    $diff[$f] = [$ex[$f], $new];
                }
            }
            $old = is_string($ex['attributes']) ? (json_decode($ex['attributes'], true) ?: []) : [];
            $merged = $mode === 'update' ? array_merge($old, $v['attrs']) : array_merge($v['attrs'], $old);
            if ($merged !== $old) {
                $upd['attributes'] = json_encode(array_slice($merged, 0, (int) Config::get('contacts.attributes_max_per_contact'), true), JSON_UNESCAPED_UNICODE);
                $diff['attributes'] = [$old, $merged];
            }
            if ($upd) {
                $contacts->update((int) $ex['id'], $upd);
                ContactService::log($orgId, (int) $ex['id'], $actorId, 'import_update', $diff + ['import_id' => [null, $id]]);
            }
            $updated++;
            $touched[(int) $ex['id']] = $v['tags'];
        }

        foreach ($touched as $cid => $tags) {
            foreach ($tags as $t) {
                Database::execute('INSERT IGNORE INTO contact_tags (contact_id, organization_id, tag) VALUES (?, ?, ?)', [$cid, $orgId, $t]);
            }
            if ($groupId) {
                Database::execute('INSERT IGNORE INTO contact_group_members (group_id, contact_id, organization_id) VALUES (?, ?, ?)', [$groupId, $cid, $orgId]);
            }
        }

        // 5) Rapport d'erreurs (plafonné) + compteurs
        $stored = (int) Database::scalar('SELECT COUNT(*) FROM contact_import_errors WHERE import_id = ?', [$id]);
        $cap = (int) Config::get('contacts.import.max_stored_errors');
        foreach ($errors as [$line, $kind, $phone, $reason, $row]) {
            if ($stored >= $cap) {
                break;
            }
            Database::execute(
                'INSERT INTO contact_import_errors (import_id, organization_id, line_number, kind, phone_raw, reason, raw_data) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$id, $orgId, $line, $kind, mb_substr((string) $phone, 0, 100), mb_substr((string) $reason, 0, 190),
                    mb_substr((string) json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 0, 4000)]
            );
            $stored++;
        }

        Database::execute(
            'UPDATE contact_imports SET processed_rows = ?, created_rows = created_rows + ?, updated_rows = updated_rows + ?,
                duplicate_rows = duplicate_rows + ?, invalid_rows = invalid_rows + ?, byte_offset = ?,
                status = ?, completed_at = ? WHERE id = ?',
            [$lineNo, $created, $updated, $dups, $invalid, $newOffset, $eof ? 'completed' : 'processing',
                $eof ? gmdate('Y-m-d H:i:s') : null, $id]
        );
        return $eof;
    }

    /* ------------------------------------------------------ Suivi et rapport */

    /** @return array<string,mixed> */
    public static function progress(int $id): array
    {
        $imp = (new ImportRepository())->find($id) ?? throw ApiException::notFound('Import introuvable.');
        $p = ImportRepository::present($imp);
        $total = max(1, (int) $p['total_rows']);
        $p['percent'] = $p['status'] === 'completed' ? 100 : min(99, (int) floor(((int) $p['processed_rows'] / $total) * 100));
        return $p;
    }

    public static function cancel(int $id, int $actorId): array
    {
        $repo = new ImportRepository();
        $imp = $repo->find($id) ?? throw ApiException::notFound('Import introuvable.');
        if (!in_array($imp['status'], ['uploaded', 'processing'], true)) {
            throw ApiException::conflict('Cet import est déjà terminé.', 'IMPORT_NOT_CANCELLABLE');
        }
        $repo->update($id, ['status' => 'cancelled']);
        AuditService::log('contacts.import_cancelled', 'contact_import', $id, [], $actorId, (int) $imp['organization_id']);
        return self::progress($id);
    }

    /**
     * @return array{data:array<int,array<string,mixed>>,meta:array<string,int>}
     */
    public static function errors(int $id, ?string $kind, int $page, int $per): array
    {
        (new ImportRepository())->find($id) ?? throw ApiException::notFound('Import introuvable.');
        $where = 'import_id = ?';
        $p = [$id];
        if (in_array($kind, ['invalid', 'duplicate'], true)) {
            $where .= ' AND kind = ?';
            $p[] = $kind;
        }
        $total = (int) Database::scalar("SELECT COUNT(*) FROM contact_import_errors WHERE $where", $p);
        $rows = Database::select(
            "SELECT id, line_number, kind, phone_raw, reason FROM contact_import_errors WHERE $where ORDER BY line_number, id LIMIT " . (int) $per . ' OFFSET ' . (int) (($page - 1) * $per),
            $p
        );
        return ['data' => $rows, 'meta' => ['page' => $page, 'per_page' => $per, 'total' => $total]];
    }

    /** CSV des lignes rejetées : colonnes d'origine conservées pour corriger puis réimporter. */
    public static function errorsCsv(int $id): string
    {
        $imp = (new ImportRepository())->find($id) ?? throw ApiException::notFound('Import introuvable.');
        $columns = json_decode((string) $imp['columns_json'], true) ?: [];
        $out = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['ligne', 'type', 'motif'], $columns), ';', '"', '');
        $safe = static fn(string $v): string => ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
        foreach (Database::select('SELECT line_number, kind, reason, raw_data FROM contact_import_errors WHERE import_id = ? ORDER BY line_number, id', [$id]) as $r) {
            $raw = is_string($r['raw_data']) ? (json_decode($r['raw_data'], true) ?: []) : [];
            fputcsv($out, array_merge(
                [(string) $r['line_number'], $r['kind'] === 'invalid' ? 'invalide' : 'doublon', $r['reason']],
                array_map(static fn($v): string => $safe((string) $v), $raw)
            ), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /* ------------------------------------------------------------- Utilitaires */

    /** @param array<int,string|null> $row */
    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return false;
            }
        }
        return true;
    }

    private static function text(string $v, string $encoding): string
    {
        if ($encoding !== 'UTF-8') {
            $v = (string) mb_convert_encoding($v, 'UTF-8', $encoding);
        } elseif (!mb_check_encoding($v, 'UTF-8')) {
            $v = (string) mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
        }
        return trim($v);
    }

    private static function cut(?string $v, int $max): ?string
    {
        return $v === null ? null : mb_substr($v, 0, $max);
    }
}
