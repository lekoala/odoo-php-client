<?php

namespace LeKoala\OdooClient\Tests\Support;

use LeKoala\OdooClient\Http\HttpRequest;
use LeKoala\OdooClient\Http\HttpResponse;
use LeKoala\OdooClient\Http\HttpTransportInterface;

final class RecordingTransport implements HttpTransportInterface
{
    /** @var array<int, HttpRequest> */
    public array $requests = [];

    /** @param array<int, HttpResponse> $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (!$response instanceof HttpResponse) {
            throw new \LogicException('No recorded HTTP response remains');
        }

        return new HttpResponse(
            $response->statusCode,
            $response->headers,
            $response->body,
            $request->url,
        );
    }
}
