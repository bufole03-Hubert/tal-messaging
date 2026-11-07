<?php
declare(strict_types=1);

namespace App\Core;

/** Sessions stockées en base ; l'ID de session n'est jamais stocké en clair (SHA-256). */
final class DbSessionHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $min = time() - (int) Config::get('app.session.absolute');
        $row = Database::first('SELECT payload FROM sessions WHERE id = ? AND created_at > ?', [hash('sha256', $id), $min]);
        return $row['payload'] ?? '';
    }

    public function write(string $id, string $data): bool
    {
        $req = Request::current();
        $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $now = time();
        Database::execute(
            'INSERT INTO sessions (id, user_id, payload, ip, user_agent, last_activity, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), payload = VALUES(payload),
               ip = VALUES(ip), user_agent = VALUES(user_agent), last_activity = VALUES(last_activity)',
            [hash('sha256', $id), $uid, $data, $req->ip, $req->userAgent, $now, $now]
        );
        return true;
    }

    public function destroy(string $id): bool
    {
        Database::execute('DELETE FROM sessions WHERE id = ?', [hash('sha256', $id)]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return Database::execute('DELETE FROM sessions WHERE created_at < ?', [time() - (int) Config::get('app.session.absolute')]);
    }
}
