<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?Request $current = null;

    /** @var array<string,mixed> */
    public array $attributes = [];
    /** @var array<string,string> */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        // Application installée dans un sous-dossier (XAMPP) : on retire le préfixe avant le routage.
        $base = base_url();
        if ($base !== '' && strncasecmp($path, $base, strlen($base)) === 0) {
            $rest = substr($path, strlen($base));
            if ($rest === '' || $rest[0] === '/') {
                $path = $rest;
            }
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            if (str_contains($headers['content-type'] ?? '', 'application/json')) {
                $raw = file_get_contents('php://input', false, null, 0, 1_048_576) ?: '';
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (!is_array($decoded)) {
                        throw ApiException::badRequest('Corps JSON invalide.');
                    }
                    $body = $decoded;
                }
            } else {
                $body = $_POST;
            }
        }

        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = (array) Config::get('app.security.trusted_proxies', []);
        if ($trusted && in_array($ip, $trusted, true) && !empty($headers['x-forwarded-for'])) {
            $candidate = trim(explode(',', $headers['x-forwarded-for'])[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }

        $req = new self($method, $path, $_GET, $body, $headers, $ip, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255));
        self::$current = $req;
        return $req;
    }

    public static function current(): self
    {
        return self::$current ?? new self('GET', '/', [], [], [], '0.0.0.0', 'cli');
    }

    public static function setCurrent(self $r): void
    {
        self::$current = $r;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    /** @return array<string,mixed> */
    public function user(): array
    {
        $u = $this->attributes['user'] ?? null;
        if (!is_array($u)) {
            throw ApiException::unauthorized();
        }
        return $u;
    }

    public function can(string $permission): bool
    {
        $u = $this->attributes['user'] ?? null;
        return is_array($u) && in_array($permission, $u['permissions'], true);
    }
}
