<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\MessagingProvider;
use App\Modules\Messaging\DTO\ProviderBalanceResult;
use App\Modules\Messaging\DTO\ProviderRecipientResult;
use App\Modules\Messaging\DTO\ProviderSendResult;
use App\Modules\Messaging\DTO\ProviderWebhookResult;
use App\Modules\Messaging\Http\CurlHttpClient;
use App\Modules\Messaging\Http\HttpClientInterface;

/**
 * Adaptateur officiel Africa's Talking utilisant le transport HTTP PHP natif.
 * Conforme aux spécifications du Cahier des Charges (Sections 2.3 et 5).
 */
final class AfricasTalkingProvider implements MessagingProvider
{
    private const URL_PROD_MESSAGING = 'https://api.africastalking.com/version1/messaging';
    private const URL_SANDBOX_MESSAGING = 'https://api.sandbox.africastalking.com/version1/messaging';
    private const URL_PROD_USER = 'https://api.africastalking.com/version1/user';
    private const URL_SANDBOX_USER = 'https://api.sandbox.africastalking.com/version1/user';

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $username,
        private readonly string $apiKey,
        private readonly string $environment = 'sandbox',
        ?HttpClientInterface $httpClient = null,
        private readonly int $timeoutSeconds = 8
    ) {
        $this->httpClient = $httpClient ?? new CurlHttpClient();
    }

    public function getId(): string
    {
        return 'africas_talking';
    }

    public function getName(): string
    {
        return "Africa's Talking";
    }

    /**
     * @param array{
     *   to: string|string[],
     *   message: string,
     *   from?: string|null,
     *   enqueue?: bool,
     *   idempotency_token?: string|null,
     *   metadata?: array<string,mixed>
     * } $payload
     */
    public function send(array $payload): ProviderSendResult
    {
        $rawTo = $payload['to'];
        $recipientsList = is_array($rawTo) ? array_values(array_filter(array_map('trim', $rawTo))) : [trim($rawTo)];
        $message = trim((string) ($payload['message'] ?? ''));
        $from = isset($payload['from']) && trim((string) $payload['from']) !== '' ? trim((string) $payload['from']) : null;
        $enqueue = (bool) ($payload['enqueue'] ?? true);

        if (empty($recipientsList)) {
            return new ProviderSendResult(
                success: false,
                recipients: [],
                errorMessage: 'Aucun destinataire fourni.',
                httpCode: 400
            );
        }

        if ($message === '') {
            return new ProviderSendResult(
                success: false,
                recipients: [],
                errorMessage: 'Le contenu du message ne peut pas être vide.',
                httpCode: 400
            );
        }

        $url = $this->environment === 'production' ? self::URL_PROD_MESSAGING : self::URL_SANDBOX_MESSAGING;

        $body = [
            'username' => $this->username,
            'to' => implode(',', $recipientsList),
            'message' => $message,
            'enqueue' => $enqueue ? '1' : '0',
        ];

        if ($from !== null) {
            $body['from'] = $from;
        }

        if (count($recipientsList) > 1) {
            $body['bulkSMSMode'] = '1';
        }

        $headers = [
            'apiKey' => $this->apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $response = $this->httpClient->request(
            method: 'POST',
            url: $url,
            headers: $headers,
            body: $body,
            timeoutSeconds: $this->timeoutSeconds
        );

        // 1. Détection des cas de Timeout réseau (sécurité anti-double envoi)
        if ($response->isTimeout) {
            $uncertainRecipients = [];
            foreach ($recipientsList as $phone) {
                $uncertainRecipients[] = new ProviderRecipientResult(
                    phone: $phone,
                    status: 'uncertain_delivery',
                    statusCode: 0,
                    error: 'Délai d\'attente dépassé (Timeout réseau). La livraison est incertaine.',
                    isRetriable: false
                );
            }

            return new ProviderSendResult(
                success: false,
                recipients: $uncertainRecipients,
                rawMessage: 'Timeout réseau avec Africa\'s Talking.',
                httpCode: 0,
                isTimeout: true,
                errorMessage: 'Délai d\'attente réseau dépassé lors de l\'appel à Africa\'s Talking. Éviter tout rejeu immédiat.'
            );
        }

        // 2. Gestion des erreurs de transport HTTP (4xx / 5xx)
        if (!$response->isSuccess()) {
            $httpErrorMsg = match ($response->statusCode) {
                401, 403 => 'Identifiants Africa\'s Talking invalides ou compte restreint (401/403).',
                405 => 'Solde insuffisant ou méthode non autorisée chez Africa\'s Talking.',
                429 => 'Limite de requêtes par seconde dépassée (Rate limit).',
                default => 'Erreur de communication avec Africa\'s Talking (HTTP ' . $response->statusCode . ').',
            };

            $failedRecipients = [];
            foreach ($recipientsList as $phone) {
                $failedRecipients[] = new ProviderRecipientResult(
                    phone: $phone,
                    status: 'failed',
                    statusCode: $response->statusCode,
                    error: $httpErrorMsg,
                    isRetriable: in_array($response->statusCode, [429, 500, 502, 503, 504], true)
                );
            }

            return new ProviderSendResult(
                success: false,
                recipients: $failedRecipients,
                rawMessage: $response->body,
                httpCode: $response->statusCode,
                errorMessage: $httpErrorMsg,
                rawResponse: (array) $response->json()
            );
        }

        // 3. Analyse de la réponse applicative JSON
        $json = $response->json();
        $smsData = $json['SMSMessageData'] ?? null;

        if (!is_array($smsData)) {
            return new ProviderSendResult(
                success: false,
                recipients: [],
                rawMessage: $response->body,
                httpCode: $response->statusCode,
                errorMessage: 'Format de réponse JSON inattendu de la part d\'Africa\'s Talking.',
                rawResponse: (array) $json
            );
        }

        $rawMessage = (string) ($smsData['Message'] ?? '');
        $recipientsData = (array) ($smsData['Recipients'] ?? []);
        $parsedRecipients = [];

        foreach ($recipientsData as $item) {
            if (!is_array($item)) {
                continue;
            }

            $phone = (string) ($item['number'] ?? '');
            $code = (int) ($item['statusCode'] ?? 0);
            $msgId = isset($item['messageId']) && $item['messageId'] !== 'None' ? (string) $item['messageId'] : null;
            $cost = isset($item['cost']) ? (string) $item['cost'] : null;
            $statusText = (string) ($item['status'] ?? '');

            [$status, $isRetriable, $error] = $this->mapStatusCode($code, $statusText);

            $parsedRecipients[] = new ProviderRecipientResult(
                phone: $phone,
                status: $status,
                statusCode: $code,
                providerMessageId: $msgId,
                cost: $cost,
                error: $error,
                isRetriable: $isRetriable
            );
        }

        // Cas où Africa's Talking renvoie un message global d'erreur sans recipients détaillés
        if (empty($parsedRecipients) && !empty($recipientsList)) {
            foreach ($recipientsList as $phone) {
                $parsedRecipients[] = new ProviderRecipientResult(
                    phone: $phone,
                    status: 'failed',
                    statusCode: 0,
                    error: $rawMessage ?: 'Rejet global de la requête par Africa\'s Talking.'
                );
            }
        }

        $globalSuccess = !empty($parsedRecipients) && array_reduce(
            $parsedRecipients,
            fn(bool $carry, ProviderRecipientResult $r) => $carry && $r->isSuccess(),
            true
        );

        return new ProviderSendResult(
            success: $globalSuccess,
            recipients: $parsedRecipients,
            rawMessage: $rawMessage,
            httpCode: $response->statusCode,
            isTimeout: false,
            errorMessage: $globalSuccess ? null : 'Un ou plusieurs destinataires n\'ont pas pu être livrés.',
            rawResponse: $json
        );
    }

    public function getStatus(string $providerMessageId): array
    {
        return [
            'supported' => false,
            'provider_message_id' => $providerMessageId,
            'status' => 'pending_dlr',
            'message' => 'Africa\'s Talking ne supporte pas l\'interrogation unitaire synchrone. Le statut est mis à jour par webhook DLR.',
        ];
    }

    public function handleWebhook(array $payload, array $headers = []): ProviderWebhookResult
    {
        $messageId = trim((string) ($payload['id'] ?? ''));
        $rawStatus = trim((string) ($payload['status'] ?? ''));
        $phone = trim((string) ($payload['phoneNumber'] ?? ''));
        $failureReason = isset($payload['failureReason']) && trim((string) $payload['failureReason']) !== ''
            ? trim((string) $payload['failureReason'])
            : null;
        $networkCode = isset($payload['networkCode']) ? trim((string) $payload['networkCode']) : null;
        $rawRetry = isset($payload['retryCount']) ? trim((string) $payload['retryCount']) : null;
        $retryCount = ($rawRetry !== null && is_numeric($rawRetry) && (int) $rawRetry >= 0) ? (int) $rawRetry : null;

        $normalizedStatus = match (strtolower($rawStatus)) {
            'success' => 'delivered',
            'failed' => 'failed',
            'rejected' => 'rejected',
            'buffered' => 'buffered',
            'submitted', 'sent' => 'sent',
            default => 'unknown',
        };

        return new ProviderWebhookResult(
            providerMessageId: $messageId,
            phone: $phone,
            status: $normalizedStatus,
            rawStatus: $rawStatus,
            failureReason: $failureReason,
            networkCode: $networkCode,
            retryCount: $retryCount,
            rawRetryCount: $rawRetry,
            cost: null,
            rawPayload: $payload
        );
    }

    public function getBalance(): ProviderBalanceResult
    {
        $baseUrl = $this->environment === 'production' ? self::URL_PROD_USER : self::URL_SANDBOX_USER;
        $url = $baseUrl . '?username=' . urlencode($this->username);

        $headers = [
            'apiKey' => $this->apiKey,
            'Accept' => 'application/json',
        ];

        $response = $this->httpClient->request(
            method: 'GET',
            url: $url,
            headers: $headers,
            timeoutSeconds: $this->timeoutSeconds
        );

        if (!$response->isSuccess()) {
            return new ProviderBalanceResult(
                isSuccess: false,
                currency: 'UNKNOWN',
                amount: 0.0,
                rawBalance: '',
                httpCode: $response->statusCode,
                errorMessage: 'Impossible de récupérer le solde Africa\'s Talking (HTTP ' . $response->statusCode . ').'
            );
        }

        $json = $response->json();
        $balanceStr = (string) ($json['UserData']['balance'] ?? '');

        // Format type : "KES 1500.00" ou "USD 25.50"
        $currency = 'USD';
        $amount = 0.0;
        if (preg_match('/^([A-Z]{3})\s+([0-9.]+)/i', trim($balanceStr), $matches) === 1) {
            $currency = strtoupper($matches[1]);
            $amount = (float) $matches[2];
        }

        return new ProviderBalanceResult(
            isSuccess: true,
            currency: $currency,
            amount: $amount,
            rawBalance: $balanceStr,
            httpCode: $response->statusCode
        );
    }

    public function getPricing(string $countryCode): array
    {
        // Les grilles Africa's Talking étant fixes et non exposées par API temps réel,
        // elles sont référencées dans la configuration locale.
        $allPricing = (array) \App\Core\Config::get('providers.africas_talking.pricing', []);
        return $allPricing[strtoupper($countryCode)] ?? [
            'country' => strtoupper($countryCode),
            'currency' => 'USD',
            'cost_per_sms' => 0.035, // Tarif indicatif standard RDC
        ];
    }

    public function getLimits(): array
    {
        return [
            'max_recipients_per_batch' => 1000,
            'tps' => 10,
            'max_message_chars' => 1600,
            'supports_alphanumeric_sender' => true,
        ];
    }

    /**
     * @return array{0:string,1:bool,2:?string} [statut TAL, réessayable, message d'erreur]
     */
    private function mapStatusCode(int $statusCode, string $statusText): array
    {
        return match ($statusCode) {
            101 => ['sent', false, null],
            100, 102 => ['queued', false, null],
            401 => ['blocked', false, 'Bloqué par les règles de risque Africa\'s Talking (RiskHold).'],
            402 => ['rejected', false, 'Sender ID invalide ou non enregistré auprès de l\'opérateur.'],
            403 => ['failed', false, 'Numéro de téléphone invalide.'],
            404 => ['failed', false, 'Type de numéro incompatible (ligne fixe).'],
            405 => ['failed', true, 'Solde du compte Africa\'s Talking insuffisant.'],
            406 => ['blocked', false, 'Le destinataire est en liste noire / s\'est désabonné.'],
            407 => ['failed', true, 'Aucune route de distribution disponible pour cet opérateur.'],
            500, 501, 502 => ['failed', true, 'Erreur technique de la passerelle télécom (' . $statusText . ').'],
            default => [
                strtolower($statusText) === 'success' ? 'sent' : 'failed',
                false,
                $statusText !== '' ? $statusText : 'Code statut inconnu : ' . $statusCode,
            ],
        };
    }
}
