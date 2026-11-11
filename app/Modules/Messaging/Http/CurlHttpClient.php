<?php
declare(strict_types=1);

namespace App\Modules\Messaging\Http;

/**
 * Transport HTTP natif basé sur l'extension PHP cURL.
 */
final class CurlHttpClient implements HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string|array $body = '',
        int $timeoutSeconds = 8,
        int $connectTimeoutSeconds = 3
    ): HttpResponse {
        $ch = curl_init();
        if ($ch === false) {
            return new HttpResponse(
                statusCode: 0,
                body: '',
                isTimeout: false,
                errorMessage: 'Impossible d\'initialiser la ressource cURL.'
            );
        }

        $formattedHeaders = [];
        foreach ($headers as $k => $v) {
            $formattedHeaders[] = is_int($k) ? (string) $v : "{$k}: {$v}";
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeoutSeconds),
            CURLOPT_TIMEOUT => max(1, $timeoutSeconds),
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (strtoupper($method) === 'POST' || strtoupper($method) === 'PUT') {
            if (is_array($body)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($body);
            } else {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        }

        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $isTimeout = in_array($errno, [CURLE_OPERATION_TIMEDOUT, 28], true);

        if ($errno !== 0 && $rawResponse === false) {
            return new HttpResponse(
                statusCode: $statusCode,
                body: '',
                headers: [],
                isTimeout: $isTimeout,
                errorMessage: $isTimeout ? 'Délai d\'attente réseau dépassé (Timeout).' : $error,
                curlErrno: $errno
            );
        }

        $responseHeaders = [];
        $rawHeaders = substr((string) $rawResponse, 0, $headerSize);
        $responseBody = substr((string) $rawResponse, $headerSize);

        foreach (explode("\r\n", $rawHeaders) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[trim($parts[0])] = trim($parts[1]);
            }
        }

        return new HttpResponse(
            statusCode: $statusCode,
            body: $responseBody,
            headers: $responseHeaders,
            isTimeout: $isTimeout,
            errorMessage: $errno !== 0 ? $error : null,
            curlErrno: $errno
        );
    }
}
