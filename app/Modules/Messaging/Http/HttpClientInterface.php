<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Http;

/**
 * Contrat de transport HTTP abstrait, découplant les adaptateurs du réseau réel.
 */
interface HttpClientInterface
{
    /**
     * Exécute une requête HTTP.
     *
     * @param string $method GET, POST, PUT, DELETE, etc.
     * @param string $url URL absolue cible
     * @param array<string,string> $headers En-têtes HTTP (ex: ['apiKey' => '...'])
     * @param string|array<string,mixed> $body Corps de la requête
     * @param int $timeoutSeconds Délai d'attente total en secondes
     * @param int $connectTimeoutSeconds Délai maximal de connexion socket
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string|array $body = '',
        int $timeoutSeconds = 8,
        int $connectTimeoutSeconds = 3
    ): HttpResponse;
}
