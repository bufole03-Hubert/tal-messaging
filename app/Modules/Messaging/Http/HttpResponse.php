<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Http;

/**
 * Représentation immuable d'une réponse de transport HTTP.
 */
final class HttpResponse
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
        public readonly bool $isTimeout = false,
        public readonly ?string $errorMessage = null,
        public readonly int $curlErrno = 0
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300 && !$this->isTimeout;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === '') {
            return null;
        }
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
