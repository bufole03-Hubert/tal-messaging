<?php
declare(strict_types=1);

namespace App\Modules\Messaging\DTO;

/**
 * Solde du compte auprès du fournisseur de passerelle.
 */
final class ProviderBalanceResult
{
    public function __construct(
        public readonly bool $isSuccess,
        public readonly string $currency,
        public readonly float $amount,
        public readonly string $rawBalance,
        public readonly int $httpCode = 200,
        public readonly ?string $errorMessage = null
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'raw_balance' => $this->rawBalance,
            'http_code' => $this->httpCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
