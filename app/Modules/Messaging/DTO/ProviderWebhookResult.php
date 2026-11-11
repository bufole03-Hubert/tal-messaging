<?php
declare(strict_types=1);

namespace App\Modules\Messaging\DTO;

/**
 * Normalisation d'un accusé de réception / Delivery Report (DLR) entrant.
 */
final class ProviderWebhookResult
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $phone,
        public readonly string $status,              // 'delivered', 'failed', 'rejected', 'buffered', 'sent'
        public readonly string $rawStatus,           // Statut textuel brut du fournisseur (ex: 'Success', 'Failed')
        public readonly ?string $failureReason = null,
        public readonly ?string $networkCode = null,
        public readonly ?int $retryCount = null,
        public readonly ?string $rawRetryCount = null,
        public readonly ?string $cost = null,
        public readonly array $rawPayload = []
    ) {
    }

    public function isDelivered(): bool
    {
        return $this->status === 'delivered';
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['delivered', 'failed', 'rejected'], true);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_message_id' => $this->providerMessageId,
            'phone' => $this->phone,
            'status' => $this->status,
            'raw_status' => $this->rawStatus,
            'failure_reason' => $this->failureReason,
            'network_code' => $this->networkCode,
            'retry_count' => $this->retryCount,
            'cost' => $this->cost,
        ];
    }
}
