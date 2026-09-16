<?php

namespace LeKoala\OdooClient\Http;

use Composer\CaBundle\CaBundle;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;

final class CurlTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new OdooNetworkException(
                'The default CurlTransport requires ext-curl; inject a PSR-18 transport instead'
            );
        }

        $handle = curl_init($request->url);
        if ($handle === false) {
            throw new OdooNetworkException('Failed to initialise cURL');
        }

        /** @var array<string, array<int, string>> $responseHeaders */
        $responseHeaders = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $separator = strpos($line, ':');
            if ($separator === false) {
                return $length;
            }

            $name = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if ($name !== '') {
                $responseHeaders[$name][] = $value;
            }

            return $length;
        });

        $headers = [];
        foreach ($request->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }

        $method = strtoupper($request->method);
        if ($method === '') {
            curl_close($handle);
            throw new OdooNetworkException('HTTP method cannot be empty');
        }
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_FAILONERROR, false);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);

        if ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }

        $cainfo = ini_get('curl.cainfo');
        if (is_string($cainfo) && $cainfo === '') {
            $caPath = CaBundle::getBundledCaBundlePath();
            if ($caPath !== '') {
                curl_setopt($handle, CURLOPT_CAINFO, $caPath);
            }
        }

        $body = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new OdooNetworkException('cURL error: ' . ($error !== '' ? $error : 'unknown transfer error'));
        }

        return new HttpResponse($statusCode, $responseHeaders, (string) $body, $request->url);
    }
}
