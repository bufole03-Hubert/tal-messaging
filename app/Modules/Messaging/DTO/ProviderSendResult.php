<?php
declare(strict_types=1);

namespace App\Modules\Messaging\DTO;

/**
 * Résultat global d'un appel d'envoi chez un fournisseur.
 */
final class ProviderSendResult
{
    /**
     * @param ProviderRecipientResult[] $recipients
     * @param array<string,mixed> $rawResponse
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $recipients,
        public readonly string $rawMessage = '',
        public readonly int $httpCode = 200,
        public readonly bool $isTimeout = false,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = []
    ) {
    }

    public function isUncertain(): bool
    {
        return $this->isTimeout;
    }

    public function hasFailures(): bool
    {
        foreach ($this->recipients as $recipient) {
            if (!$recipient->isSuccess()) {
                return true;
            }
        }
        return false;
    }

    public function getRecipient(string $phone): ?ProviderRecipientResult
    {
        foreach ($this->recipients as $recipient) {
            if ($recipient->phone === $phone) {
                return $recipient;
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'http_code' => $this->httpCode,
            'is_timeout' => $this->isTimeout,
            'error_message' => $this->errorMessage,
            'raw_message' => $this->rawMessage,
            'recipients' => array_map(fn(ProviderRecipientResult $r) => $r->toArray(), $this->recipients),
        ];
    }
}
