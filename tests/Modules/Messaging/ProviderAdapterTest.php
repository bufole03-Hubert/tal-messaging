<?php
declare(strict_types=1);

/**
 * Tests unitaires du Provider Adapter Layer (Africa's Talking & Mock) - Phase 1.
 * Exécutable de façon autonome : php tests/Modules/Messaging/ProviderAdapterTest.php
 * Ou inclus dans la suite globale : php tests/run.php
 */

use App\Modules\Messaging\DTO\ProviderBalanceResult;
use App\Modules\Messaging\DTO\ProviderRecipientResult;
use App\Modules\Messaging\DTO\ProviderSendResult;
use App\Modules\Messaging\DTO\ProviderWebhookResult;
use App\Modules\Messaging\Http\HttpResponse;
use App\Modules\Messaging\Http\MockHttpClient;
use App\Modules\Messaging\Providers\AfricasTalkingProvider;
use App\Modules\Messaging\Providers\MockProvider;
use App\Support\Crypto;
use App\Support\Logger;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__, 3));
    putenv('APP_KEY=' . str_repeat('k', 40));
    require BASE_PATH . '/app/bootstrap.php';
}

if (!function_exists('test')) {
    $passed = 0;
    $failed = 0;

    function test(string $name, callable $fn): void
    {
        global $passed, $failed;
        try {
            $fn();
            $passed++;
            echo "  OK   $name\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "  FAIL $name — " . $e->getMessage() . "\n";
        }
    }

    function check(bool $cond, string $msg = 'assertion échouée'): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    function throws(callable $fn, string $class): bool
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e instanceof $class;
        }
        return false;
    }

    $isStandalone = true;
} else {
    $isStandalone = false;
}

echo "\nProvider Adapter Layer (Africa's Talking & Mock)\n";

test('MockHttpClient enregistre les requêtes et ne déclenche aucun appel réseau', function (): void {
    $mock = new MockHttpClient(new HttpResponse(200, '{"ok":true}'));
    $res = $mock->request('POST', 'https://example.com/api', ['apiKey' => 'secret123'], ['foo' => 'bar']);
    check($res->statusCode === 200);
    check($res->isSuccess());
    check($res->json() === ['ok' => true]);
    $last = $mock->getLastRequest();
    check($last !== null && $last['method'] === 'POST' && $last['url'] === 'https://example.com/api');
    check($last['headers']['apiKey'] === 'secret123');
});

test('AfricasTalkingProvider : construction conforme de la requête d\'envoi (URL, headers, body)', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(201, json_encode([
        'SMSMessageData' => [
            'Message' => 'Sent to 1/1 Total Cost: KES 0.8000',
            'Recipients' => [
                [
                    'statusCode' => 101,
                    'number' => '+243812345678',
                    'status' => 'Success',
                    'cost' => 'KES 0.8000',
                    'messageId' => 'ATXid_test_123',
                ],
            ],
        ],
    ])));

    $provider = new AfricasTalkingProvider(
        username: 'tal_test_user',
        apiKey: 'test_api_key_xyz',
        environment: 'sandbox',
        httpClient: $mock
    );

    $res = $provider->send([
        'to' => '+243812345678',
        'message' => 'Bonjour TAL Messaging',
        'from' => 'TALMSG',
        'enqueue' => true,
    ]);

    check($res->success === true, 'Le résultat global doit être un succès');
    check(count($res->recipients) === 1);
    $recipient = $res->recipients[0];
    check($recipient->phone === '+243812345678');
    check($recipient->status === 'sent');
    check($recipient->statusCode === 101);
    check($recipient->providerMessageId === 'ATXid_test_123');
    check($recipient->cost === 'KES 0.8000');
    check($recipient->isSuccess() === true);

    $req = $mock->getLastRequest();
    check($req !== null);
    check(str_contains($req['url'], 'sandbox.africastalking.com/version1/messaging'));
    check(($req['headers']['apiKey'] ?? '') === 'test_api_key_xyz');
    check(is_array($req['body']));
    check($req['body']['username'] === 'tal_test_user');
    check($req['body']['to'] === '+243812345678');
    check($req['body']['message'] === 'Bonjour TAL Messaging');
    check($req['body']['from'] === 'TALMSG');
    check($req['body']['enqueue'] === '1');
});

test('AfricasTalkingProvider : envoi groupé active bulkSMSMode', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(201, json_encode([
        'SMSMessageData' => [
            'Message' => 'Sent to 2/2',
            'Recipients' => [
                ['statusCode' => 101, 'number' => '+243810000001', 'status' => 'Success', 'messageId' => 'id1'],
                ['statusCode' => 101, 'number' => '+243810000002', 'status' => 'Success', 'messageId' => 'id2'],
            ],
        ],
    ])));

    $provider = new AfricasTalkingProvider('user', 'key', 'production', $mock);
    $res = $provider->send([
        'to' => ['+243810000001', '+243810000002'],
        'message' => 'Alerte',
    ]);

    check($res->success === true);
    check(count($res->recipients) === 2);
    $req = $mock->getLastRequest();
    check($req['body']['bulkSMSMode'] === '1');
    check($req['body']['to'] === '+243810000001,+243810000002');
    check(str_contains($req['url'], 'api.africastalking.com/version1/messaging'));
});

test('AfricasTalkingProvider : gestion des rejets applicatifs (SenderID 402, Solde 405, Numéro 403)', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(201, json_encode([
        'SMSMessageData' => [
            'Message' => 'Sent to 0/3',
            'Recipients' => [
                ['statusCode' => 402, 'number' => '+243810000001', 'status' => 'InvalidSenderId'],
                ['statusCode' => 405, 'number' => '+243810000002', 'status' => 'InsufficientBalance'],
                ['statusCode' => 403, 'number' => '+243810000003', 'status' => 'InvalidPhoneNumber'],
            ],
        ],
    ])));

    $provider = new AfricasTalkingProvider('user', 'key', 'sandbox', $mock);
    $res = $provider->send([
        'to' => ['+243810000001', '+243810000002', '+243810000003'],
        'message' => 'Test statuts',
    ]);

    check($res->success === false, 'Doit être globalement en échec');
    check($res->hasFailures() === true);

    $r1 = $res->getRecipient('+243810000001');
    check($r1->status === 'rejected' && $r1->statusCode === 402 && $r1->isRetriable === false);

    $r2 = $res->getRecipient('+243810000002');
    check($r2->status === 'failed' && $r2->statusCode === 405 && $r2->isRetriable === true, 'Le solde insuffisant doit être réessayable/failover');

    $r3 = $res->getRecipient('+243810000003');
    check($r3->status === 'failed' && $r3->statusCode === 403 && $r3->isRetriable === false);
});

test('AfricasTalkingProvider : timeout réseau marque uncertain_delivery sans rejeu automatique', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(
        statusCode: 0,
        body: '',
        isTimeout: true,
        errorMessage: 'Operation timed out after 8000 milliseconds'
    ));

    $provider = new AfricasTalkingProvider('user', 'key', 'sandbox', $mock);
    $res = $provider->send([
        'to' => '+243812345678',
        'message' => 'Message critique',
    ]);

    check($res->success === false);
    check($res->isTimeout === true);
    check($res->isUncertain() === true);
    check(count($res->recipients) === 1);
    check($res->recipients[0]->status === 'uncertain_delivery');
    check($res->recipients[0]->isRetriable === false, 'Ne doit pas être rejoué immédiatement');
});

test('AfricasTalkingProvider : gestion des erreurs de transport HTTP (401/403/429/500)', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(401, 'Unauthorized'));

    $provider = new AfricasTalkingProvider('bad_user', 'bad_key', 'sandbox', $mock);
    $res = $provider->send(['to' => '+243812345678', 'message' => 'Test']);

    check($res->success === false);
    check($res->httpCode === 401);
    check(str_contains($res->errorMessage ?? '', '401'));
});

test('AfricasTalkingProvider : décodage DLR Webhook (Success, Buffered, Failed, Rejected)', function (): void {
    $provider = new AfricasTalkingProvider('user', 'key');

    $wSuccess = $provider->handleWebhook([
        'id' => 'ATXid_abc',
        'status' => 'Success',
        'phoneNumber' => '+243812345678',
        'networkCode' => '63001',
    ]);
    check($wSuccess->status === 'delivered');
    check($wSuccess->isDelivered() === true);
    check($wSuccess->isFinal() === true);

    $wBuffered = $provider->handleWebhook([
        'id' => 'ATXid_abc',
        'status' => 'Buffered',
        'phoneNumber' => '+243812345678',
    ]);
    check($wBuffered->status === 'buffered');
    check($wBuffered->isFinal() === false, 'Buffered est un état transitoire');

    $wFailed = $provider->handleWebhook([
        'id' => 'ATXid_abc',
        'status' => 'Failed',
        'failureReason' => 'SubscriberUnavailable',
    ]);
    check($wFailed->status === 'failed');
    check($wFailed->failureReason === 'SubscriberUnavailable');
    check($wFailed->isFinal() === true);

    $wRejected = $provider->handleWebhook([
        'id' => 'ATXid_abc',
        'status' => 'Rejected',
    ]);
    check($wRejected->status === 'rejected');
    check($wRejected->isFinal() === true);
});

test('AfricasTalkingProvider : consultation du solde (getBalance)', function (): void {
    $mock = new MockHttpClient();
    $mock->queueResponse(new HttpResponse(200, json_encode([
        'UserData' => ['balance' => 'USD 142.50'],
    ])));

    $provider = new AfricasTalkingProvider('user', 'key', 'production', $mock);
    $bal = $provider->getBalance();

    check($bal->isSuccess === true);
    check($bal->currency === 'USD');
    check($bal->amount === 142.50);
    check($bal->rawBalance === 'USD 142.50');
});

test('MockProvider : simulation complète sans réseau', function (): void {
    $mockProv = new MockProvider();
    $res = $mockProv->send(['to' => '+243810000000', 'message' => 'Simu']);
    check($res->success === true);
    check(count($res->recipients) === 1);
    check(str_starts_with($res->recipients[0]->providerMessageId ?? '', 'MOCK_'));

    $bal = $mockProv->getBalance();
    check($bal->isSuccess === true && $bal->amount === 1000.0);
});

test('Sécurité : chiffrement AES-256-GCM des credentials et masquage dans Logger', function (): void {
    $rawKey = 'atsk_live_super_secret_api_key_999';
    $encrypted = Crypto::encrypt($rawKey);
    check($encrypted !== $rawKey);
    check(Crypto::decrypt($encrypted) === $rawKey);

    $scrubbed = Logger::scrub([
        'user' => 'admin',
        'apiKey' => $rawKey,
        'africastalking_api_key' => $rawKey,
        'api_key_enc' => $encrypted,
        'nested' => ['secret' => 'toto', 'public' => 'hello'],
    ]);

    check($scrubbed['apiKey'] === '[MASQUÉ]');
    check($scrubbed['africastalking_api_key'] === '[MASQUÉ]');
    check($scrubbed['api_key_enc'] === '[MASQUÉ]');
    check($scrubbed['nested']['secret'] === '[MASQUÉ]');
    check($scrubbed['nested']['public'] === 'hello');
});

if ($isStandalone) {
    echo sprintf("\n%d réussi(s), %d échec(s)\n", $passed, $failed);
    exit($failed > 0 ? 1 : 0);
}
