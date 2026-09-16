<?php

namespace LeKoala\OdooClient\Http;

use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class Psr18Transport implements HttpTransportInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psrRequest = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psrRequest = $psrRequest->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psrRequest = $psrRequest->withBody($this->streamFactory->createStream($request->body));
        }

        try {
            $response = $this->client->sendRequest($psrRequest);
        } catch (ClientExceptionInterface $exception) {
            $safeMessage = preg_replace(
                '/([?&](?:access_token|api_key|token)=)[^&\s]+/i',
                '$1***',
                $exception->getMessage(),
            );
            throw new OdooNetworkException(
                'PSR-18 HTTP error: ' . ($safeMessage ?? 'request failed'),
                0,
                $exception,
            );
        }

        return new HttpResponse(
            $response->getStatusCode(),
            $this->normalizeHeaders($response),
            (string) $response->getBody(),
            $request->url,
        );
    }

    /** @return array<string, array<int, string>> */
    private function normalizeHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return $headers;
    }
}
