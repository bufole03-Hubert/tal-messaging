<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\DTO\ProviderBalanceResult;
use App\Modules\Messaging\DTO\ProviderSendResult;
use App\Modules\Messaging\DTO\ProviderWebhookResult;

/**
 * Interface standardisée pour tous les adaptateurs de passerelles télécom.
 * Conforme aux spécifications de la Section 5 du Cahier des Charges.
 */
interface MessagingProvider
{
    /**
     * Identifiant unique de l'adaptateur (ex: 'africas_talking', 'twilio', 'mock').
     */
    public function getId(): string;

    /**
     * Nom lisible pour affichage (ex: "Africa's Talking").
     */
    public function getName(): string;

    /**
     * Envoi d'un message unitaire ou groupé.
     *
     * @param array{
     *   to: string|string[],
     *   message: string,
     *   from?: string|null,
     *   enqueue?: bool,
     *   idempotency_token?: string|null,
     *   metadata?: array<string,mixed>
     * } $payload
     */
    public function send(array $payload): ProviderSendResult;

    /**
     * Récupération du statut d'un message envoyé.
     * Note : si le fournisseur ne supporte pas l'interrogation unitaire (modèle push uniquement),
     * renvoie le dernier état connu ou un statut 'unknown'.
     */
    public function getStatus(string $providerMessageId): array;

    /**
     * Traitement et normalisation d'un webhook entrant (Accusé de réception / DLR).
     *
     * @param array<string,mixed> $payload
     * @param array<string,string> $headers
     */
    public function handleWebhook(array $payload, array $headers = []): ProviderWebhookResult;

    /**
     * Consultation du solde du compte chez le fournisseur.
     */
    public function getBalance(): ProviderBalanceResult;

    /**
     * Consultation des tarifs par pays (code ISO2, ex: 'CD').
     *
     * @return array<string,mixed>
     */
    public function getPricing(string $countryCode): array;

    /**
     * Limites techniques contractuelles du fournisseur.
     *
     * @return array{
     *   max_recipients_per_batch: int,
     *   tps: int,
     *   max_message_chars: int,
     *   supports_alphanumeric_sender: bool
     * }
     */
    public function getLimits(): array;
}
