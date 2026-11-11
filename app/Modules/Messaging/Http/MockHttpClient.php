<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Http;

/**
 * Transport HTTP simulé pour l'environnement de développement et les tests unitaires.
 * Garantit qu'aucun appel réseau réel n'est effectué.
 */
final class MockHttpClient implements HttpClientInterface
{
    /** @var HttpResponse[] */
    private array $responseQueue = [];

    /** @var array<int,array{method:string,url:string,headers:array<string,string>,body:string|array<string,mixed>,timeout:int}> */
    private array $recordedRequests = [];

    private ?HttpResponse $defaultResponse = null;

    public function __construct(?HttpResponse $defaultResponse = null)
    {
        $this->defaultResponse = $defaultResponse ?? new HttpResponse(
            statusCode: 200,
            body: '{"SMSMessageData":{"Message":"Sent to 1/1","Recipients":[{"statusCode":101,"number":"+243812345678","status":"Success","cost":"KES 0.8000","messageId":"MOCK_msg_123"}]}}'
        );
    }

    public function queueResponse(HttpResponse $response): self
    {
        $this->responseQueue[] = $response;
        return $this;
    }

    public function setDefaultResponse(HttpResponse $response): self
    {
        $this->defaultResponse = $response;
        return $this;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string|array $body = '',
        int $timeoutSeconds = 8,
        int $connectTimeoutSeconds = 3
    ): HttpResponse {
        $this->recordedRequests[] = [
            'method' => $method,
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeoutSeconds,
        ];

        if (!empty($this->responseQueue)) {
            return array_shift($this->responseQueue);
        }

        return $this->defaultResponse;
    }

    /**
     * @return array<int,array{method:string,url:string,headers:array<string,string>,body:string|array<string,mixed>,timeout:int}>
     */
    public function getRecordedRequests(): array
    {
        return $this->recordedRequests;
    }

    /**
     * @return array{method:string,url:string,headers:array<string,string>,body:string|array<string,mixed>,timeout:int}|null
     */
    public function getLastRequest(): ?array
    {
        if (empty($this->recordedRequests)) {
            return null;
        }
        return $this->recordedRequests[count($this->recordedRequests) - 1];
    }

    public function reset(): void
    {
        $this->responseQueue = [];
        $this->recordedRequests = [];
    }
}
