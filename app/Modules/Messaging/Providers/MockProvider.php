<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\MessagingProvider;
use App\Modules\Messaging\DTO\ProviderBalanceResult;
use App\Modules\Messaging\DTO\ProviderRecipientResult;
use App\Modules\Messaging\DTO\ProviderSendResult;
use App\Modules\Messaging\DTO\ProviderWebhookResult;

/**
 * Fournisseur fictif utilisé pour le développement et les tests.
 * Aucun trafic réseau, génération déterministe d'identifiants simulés.
 */
final class MockProvider implements MessagingProvider
{
    private bool $simulateFailure = false;
    private bool $simulateTimeout = false;

    public function __construct(bool $simulateFailure = false, bool $simulateTimeout = false)
    {
        $this->simulateFailure = $simulateFailure;
        $this->simulateTimeout = $simulateTimeout;
    }

    public function getId(): string
    {
        return 'mock';
    }

    public function getName(): string
    {
        return 'Mock Provider (Simulation)';
    }

    public function send(array $payload): ProviderSendResult
    {
        $rawTo = $payload['to'];
        $recipientsList = is_array($rawTo) ? array_values(array_filter(array_map('trim', $rawTo))) : [trim($rawTo)];

        if ($this->simulateTimeout) {
            $recipients = [];
            foreach ($recipientsList as $phone) {
                $recipients[] = new ProviderRecipientResult(
                    phone: $phone,
                    status: 'uncertain_delivery',
                    statusCode: 0,
                    error: 'Timeout simulé'
                );
            }
            return new ProviderSendResult(
                success: false,
                recipients: $recipients,
                isTimeout: true,
                errorMessage: 'Simulation de timeout réseau.'
            );
        }

        if ($this->simulateFailure) {
            $recipients = [];
            foreach ($recipientsList as $phone) {
                $recipients[] = new ProviderRecipientResult(
                    phone: $phone,
                    status: 'failed',
                    statusCode: 500,
                    error: 'Simulation d\'échec de passerelle'
                );
            }
            return new ProviderSendResult(
                success: false,
                recipients: $recipients,
                httpCode: 500,
                errorMessage: 'Erreur de simulation'
            );
        }

        $recipients = [];
        foreach ($recipientsList as $i => $phone) {
            $recipients[] = new ProviderRecipientResult(
                phone: $phone,
                status: 'sent',
                statusCode: 101,
                providerMessageId: 'MOCK_' . bin2hex(random_bytes(8)),
                cost: 'USD 0.0350'
            );
        }

        return new ProviderSendResult(
            success: true,
            recipients: $recipients,
            rawMessage: 'Simulated send success'
        );
    }

    public function getStatus(string $providerMessageId): array
    {
        return [
            'supported' => true,
            'provider_message_id' => $providerMessageId,
            'status' => 'delivered',
            'message' => 'Statut simulé',
        ];
    }

    public function handleWebhook(array $payload, array $headers = []): ProviderWebhookResult
    {
        return new ProviderWebhookResult(
            providerMessageId: (string) ($payload['id'] ?? 'MOCK_123'),
            phone: (string) ($payload['phoneNumber'] ?? '+243812345678'),
            status: 'delivered',
            rawStatus: 'Success'
        );
    }

    public function getBalance(): ProviderBalanceResult
    {
        return new ProviderBalanceResult(
            isSuccess: true,
            currency: 'USD',
            amount: 1000.00,
            rawBalance: 'USD 1000.00'
        );
    }

    public function getPricing(string $countryCode): array
    {
        return [
            'country' => strtoupper($countryCode),
            'currency' => 'USD',
            'cost_per_sms' => 0.035,
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_recipients_per_batch' => 5000,
            'tps' => 100,
            'max_message_chars' => 1600,
            'supports_alphanumeric_sender' => true,
        ];
    }
}
