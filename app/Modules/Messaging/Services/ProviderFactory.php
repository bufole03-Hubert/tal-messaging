<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Config;
use App\Core\Env;
use App\Modules\Messaging\Contracts\MessagingProvider;
use App\Modules\Messaging\Http\CurlHttpClient;
use App\Modules\Messaging\Providers\AfricasTalkingProvider;
use App\Modules\Messaging\Providers\MockProvider;

/**
 * Résout et instancie l'adaptateur fournisseur de messagerie applicable.
 * Lecture stricte de la configuration : config/providers.php + variables d'environnement.
 */
final class ProviderFactory
{
    /**
     * Crée et retourne l'adaptateur MessagingProvider configuré.
     *
     * @param string|null $providerId Identifiant du fournisseur (null = utiliser la valeur par défaut)
     */
    public static function create(?string $providerId = null): MessagingProvider
    {
        $config = (array) Config::get('providers', []);
        $defaultProvider = (string) ($config['default'] ?? Env::get('DEFAULT_MESSAGING_PROVIDER', 'africas_talking'));
        $resolved = $providerId ?? $defaultProvider;

        return match ($resolved) {
            'mock'            => new MockProvider(),
            'africas_talking' => self::createAfricasTalking($config),
            default           => throw new \InvalidArgumentException(
                sprintf("Fournisseur de messagerie inconnu : '%s'.", $resolved)
            ),
        };
    }

    /**
     * Instancie l'adaptateur Africa's Talking selon le driver configuré.
     *
     * @param array<string,mixed> $config
     */
    private static function createAfricasTalking(array $config): MessagingProvider
    {
        $atConfig = (array) ($config['africas_talking'] ?? []);
        $driver   = (string) ($atConfig['driver'] ?? Env::get('AFRICASTALKING_DRIVER', 'mock'));

        if (!in_array($driver, ['mock', 'http'], true)) {
            throw new \InvalidArgumentException(
                sprintf("Driver Africa's Talking non supporté : '%s'. Seuls 'mock' et 'http' sont autorisés.", $driver)
            );
        }

        if ($driver === 'mock') {
            return new MockProvider();
        }

        // driver === 'http' : instance réelle
        $username    = (string) ($atConfig['username'] ?? Env::get('AFRICASTALKING_USERNAME', ''));
        $apiKey      = (string) ($atConfig['api_key']  ?? Env::get('AFRICASTALKING_API_KEY', ''));
        $environment = (string) ($atConfig['environment'] ?? Env::get('AFRICASTALKING_ENVIRONMENT', 'sandbox'));
        $timeout     = (int)   ($atConfig['timeout'] ?? (int) Env::get('AFRICASTALKING_TIMEOUT', '8'));

        if ($username === '' || $apiKey === '') {
            throw new \RuntimeException(
                'Africa\'s Talking : identifiants manquants (AFRICASTALKING_USERNAME / AFRICASTALKING_API_KEY).'
            );
        }

        $http = new CurlHttpClient($timeout);

        return new AfricasTalkingProvider(
            username:    $username,
            apiKey:      $apiKey,
            environment: $environment,
            httpClient:  $http
        );
    }
}
