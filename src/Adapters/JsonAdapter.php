<?php

namespace LeKoala\OdooClient\Adapters;

use LeKoala\OdooClient\Enums\OdooModel;
use LeKoala\OdooClient\Exceptions\OdooAccessException;
use LeKoala\OdooClient\Exceptions\OdooAuthException;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\OdooValidationException;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Http\CurlTransport;
use LeKoala\OdooClient\Http\HttpClient;
use LeKoala\OdooClient\Http\HttpRequest;

/**
 * Adapter for Odoo 19+ JSON-2 API.
 */
class JsonAdapter implements OdooAdapterInterface
{
    private Config $config;
    private string $baseUrl;
    private HttpClient $httpClient;

    public function __construct(Config $config, ?HttpClient $httpClient = null)
    {
        $this->config = $config;
        $this->baseUrl = rtrim($config->getUrl(), '/') . '/json/2';
        $this->httpClient = $httpClient ?? new HttpClient(
            $config->getHttpTransport() ?? new CurlTransport(
                $config->getConnectTimeout(),
                $config->getTimeout()
            )
        );
    }

    public function authenticate(): bool
    {
        $context = $this->call('res.users', 'context_get');

        return is_array($context);
    }

    /**
     * @param OdooModel|string $model
     * @param array<string, mixed> $params
     */
    public function call(OdooModel|string $model, string $method, array $params = []): mixed
    {
        $modelName = $model instanceof OdooModel ? $model->value : $model;

        if ($modelName === 'common' && $method === 'version') {
            $versionUrl = rtrim($this->config->getUrl(), '/') . '/web/version';
            $resp = $this->makeHttpRequest($versionUrl, 'GET');
            if (!is_array($resp)) {
                throw new OdooValidationException('Invalid response from /web/version: expected a JSON object');
            }

            $version = isset($resp['version']) && is_string($resp['version']) ? $resp['version'] : '';
            $versionInfo = isset($resp['version_info']) && is_array($resp['version_info'])
                ? $resp['version_info']
                : [];

            return [
                'server_version' => $version,
                'server_version_info' => $versionInfo,
                'server_serie' => $version,
                'protocol_version' => 1,
            ];
        }

        $url = "{$this->baseUrl}/{$modelName}/{$method}";

        $encodedBody = $this->encodeParams($params, $modelName, $method);

        return $this->makeHttpRequest($url, 'POST', $encodedBody);
    }

    /** @param array<string, mixed> $params */
    private function encodeParams(array $params, string $model, string $method): string
    {
        try {
            return json_encode($params === [] ? new \stdClass() : $params, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new OdooValidationException(
                "Failed to encode request body to JSON for {$model}/{$method}",
                0,
                $e
            );
        }
    }

    private function makeHttpRequest(string $url, string $method, ?string $encodedBody = null): mixed
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->config->getApiKey(),
            'Content-Type' => 'application/json',
            'User-Agent' => 'lekoala-odoo-php-client',
        ];
        if ($this->config->getDb() !== '') {
            $headers['X-Odoo-Database'] = $this->config->getDb();
        }

        $httpResponse = $this->httpClient->send(new HttpRequest(
            method: $method,
            url: $url,
            headers: $headers,
            body: $encodedBody,
            maxRedirects: $this->config->getMaxRedirects(),
        ));
        $response = $httpResponse->body;
        $httpCode = $httpResponse->statusCode;

        $result = json_decode($response, true);
        if ($result === null && json_last_error() !== JSON_ERROR_NONE) {
            if ($httpCode >= 400) {
                $this->throwForErrorResponse((int) $httpCode, $response, null);
            }

            throw new OdooValidationException(
                'Invalid JSON-2 response: ' . json_last_error_msg(),
                (int) $httpCode
            );
        }

        $this->throwForErrorResponse((int) $httpCode, $response, $result);

        return $result;
    }

    private function throwForErrorResponse(int $httpCode, string $response, mixed $result): void
    {
        $odooName = (is_array($result) && isset($result['name']) && is_string($result['name'])) ? $result['name'] : '';
        $odooMessage = (is_array($result) && isset($result['message']) && is_string($result['message'])) ? $result['message'] : '';
        $odooArguments = (is_array($result) && isset($result['arguments']) && is_array($result['arguments'])) ? $result['arguments'] : [];

        if ($httpCode === 401) {
            throw new OdooAuthException(
                $this->authFailureMessage('Authentication failed (HTTP 401)', $odooMessage),
                $httpCode,
                null,
                $odooName,
                $odooMessage,
                $odooArguments
            );
        }

        if ($httpCode === 403) {
            throw new OdooAccessException(
                $this->authFailureMessage(
                    'Access denied (HTTP 403): the API key is accepted, but the user it belongs to has no right on this model or record',
                    $odooMessage
                ),
                $httpCode,
                null,
                $odooName,
                $odooMessage,
                $odooArguments
            );
        }

        if ($httpCode === 502 || $httpCode === 503 || $httpCode === 504) {
            throw new OdooNetworkException("Transient server error (HTTP {$httpCode})", $httpCode);
        }

        if ($httpCode >= 400 || ($odooName !== '' && str_contains($odooName, 'exception'))) {
            if ($odooMessage !== '') {
                $message = $odooMessage;
            } else {
                $clean = preg_replace('/\s+/', ' ', trim(strip_tags($response)));
                $message = (is_string($clean) && $clean !== '') ? $clean : 'Unknown JSON-2 API error';
            }

            $prefix = $odooName !== '' ? "Odoo JSON-2 API Error [{$odooName}]" : 'Odoo JSON-2 API Error';

            throw new OdooValidationException(
                "{$prefix}: {$message}",
                $httpCode,
                null,
                $odooName,
                $odooMessage,
                $odooArguments
            );
        }
    }

    private function authFailureMessage(string $diagnosis, string $odooMessage): string
    {
        return $odooMessage !== '' ? "{$diagnosis}: {$odooMessage}" : $diagnosis;
    }

}
