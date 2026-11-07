<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = [],
    ) {
    }

    /** @param array<string,mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        return new self($status, (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);
    }

    /** @param array<string,mixed>|list<mixed> $data */
    public static function data(array $data, int $status = 200): self
    {
        return self::json(['data' => $data], $status);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        // Les chemins internes ("/login") sont préfixés par le sous-dossier de l'application.
        if (str_starts_with($to, '/') && !str_starts_with($to, '//')) {
            $to = base_url() . $to;
        }
        return new self($status, '', ['Location' => $to]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            $csp = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; "
                . "connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";
            header('Content-Security-Policy: ' . $csp);
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header('Cross-Origin-Opener-Policy: same-origin');
            header('Cache-Control: no-store');
            header_remove('X-Powered-By');
            if (Session::isSecureTransport()) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
        echo $this->body;
    }
}
