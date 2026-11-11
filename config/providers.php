<?php
declare(strict_types=1);

use App\Core\Env;

/**
 * Configuration des passerelles et fournisseurs télécom (Provider Adapter Layer).
 */
return [
    'default' => Env::get('DEFAULT_MESSAGING_PROVIDER', 'africas_talking'),

    'africas_talking' => [
        // 'mock' en environnement local/test pour garantir zéro SMS réel ; 'http' pour les appels réels
        'driver' => Env::get('AFRICASTALKING_DRIVER', 'mock'),
        'username' => Env::get('AFRICASTALKING_USERNAME', 'sandbox'),
        'api_key' => Env::get('AFRICASTALKING_API_KEY', ''),
        'environment' => Env::get('AFRICASTALKING_ENVIRONMENT', 'sandbox'), // 'sandbox' ou 'production'
        'timeout' => (int) Env::get('AFRICASTALKING_TIMEOUT', '8'),
        'connect_timeout' => 3,

        // Grille tarifaire de référence (exprimée en USD par SMS standard)
        'pricing' => [
            'CD' => ['currency' => 'USD', 'cost_per_sms' => 0.035, 'country_name' => 'RD Congo'],
            'CG' => ['currency' => 'USD', 'cost_per_sms' => 0.040, 'country_name' => 'Congo-Brazzaville'],
            'RW' => ['currency' => 'USD', 'cost_per_sms' => 0.030, 'country_name' => 'Rwanda'],
            'KE' => ['currency' => 'USD', 'cost_per_sms' => 0.015, 'country_name' => 'Kenya'],
        ],
    ],
];
