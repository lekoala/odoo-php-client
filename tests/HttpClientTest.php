<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Http\HttpClient;
use LeKoala\OdooClient\Http\HttpRequest;
use LeKoala\OdooClient\Http\HttpResponse;
use LeKoala\OdooClient\Tests\Support\RecordingTransport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Http\HttpClient::class)]
final class HttpClientTest extends TestCase
{
    public function testCrossOriginRedirectStripsCredentials(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(302, ['Location' => ['https://files.example.test/invoice.pdf']], '', ''),
            new HttpResponse(200, ['Content-Type' => ['application/pdf']], '%PDF', ''),
        ]);
        $client = new HttpClient($transport);

        $client->send(new HttpRequest('GET', 'https://odoo.example.test/portal', [
            'Authorization' => 'Bearer secret',
            'X-Odoo-Database' => 'database',
            'Cookie' => 'session=secret',
            'Accept' => 'application/pdf',
        ], maxRedirects: 2));

        self::assertCount(2, $transport->requests);
        self::assertSame('https://files.example.test/invoice.pdf', $transport->requests[1]->url);
        self::assertArrayNotHasKey('Authorization', $transport->requests[1]->headers);
        self::assertArrayNotHasKey('X-Odoo-Database', $transport->requests[1]->headers);
        self::assertArrayNotHasKey('Cookie', $transport->requests[1]->headers);
        self::assertSame('application/pdf', $transport->requests[1]->headers['Accept']);
    }

    public function testRelativeRedirectAnd303SwitchPostToGet(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(303, ['location' => ['../result?id=1']], '', ''),
            new HttpResponse(200, [], 'done', ''),
        ]);
        $client = new HttpClient($transport);

        $response = $client->send(new HttpRequest(
            'POST',
            'https://odoo.example.test/json/2/model/action',
            ['Authorization' => 'Bearer secret', 'Content-Type' => 'application/json'],
            '{}',
            2,
        ));

        self::assertSame('done', $response->body);
        self::assertSame('https://odoo.example.test/json/2/result?id=1', $transport->requests[1]->url);
        self::assertSame('GET', $transport->requests[1]->method);
        self::assertNull($transport->requests[1]->body);
        self::assertArrayNotHasKey('Content-Type', $transport->requests[1]->headers);
        self::assertSame('Bearer secret', $transport->requests[1]->headers['Authorization']);
    }

    public function testRedirectLimitIsEnforced(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(302, ['Location' => ['/again']], '', ''),
        ]);

        $this->expectException(OdooNetworkException::class);
        $this->expectExceptionMessage('Too many HTTP redirects');

        (new HttpClient($transport))->send(new HttpRequest(
            'GET',
            'https://odoo.example.test/start',
            maxRedirects: 0,
        ));
    }
}
