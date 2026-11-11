<?php
declare(strict_types=1);

namespace App\Modules\Messaging\DTO;

/**
 * Résultat d'envoi pour un destinataire individuel.
 */
final class ProviderRecipientResult
{
    public function __construct(
        public readonly string $phone,
        public readonly string $status,          // 'queued', 'sent', 'failed', 'rejected', 'blocked', 'uncertain_delivery'
        public readonly int $statusCode,         // Code applicatif brut du fournisseur (ex: 101, 102, 401...)
        public readonly ?string $providerMessageId = null,
        public readonly ?string $cost = null,
        public readonly ?string $error = null,
        public readonly bool $isRetriable = false
    ) {
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, ['sent', 'queued'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'status' => $this->status,
            'status_code' => $this->statusCode,
            'provider_message_id' => $this->providerMessageId,
            'cost' => $this->cost,
            'error' => $this->error,
            'is_retriable' => $this->isRetriable,
        ];
    }
}
