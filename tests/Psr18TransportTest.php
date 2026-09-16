<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Http\HttpRequest;
use LeKoala\OdooClient\Http\Psr18Transport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(\LeKoala\OdooClient\Http\Psr18Transport::class)]
final class Psr18TransportTest extends TestCase
{
    public function testMapsPackageRequestAndPsrResponse(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->expects(self::once())->method('withHeader')->with('Content-Type', 'application/json')
            ->willReturnSelf();

        $bodyStream = $this->createStub(StreamInterface::class);
        $request->expects(self::once())->method('withBody')->with($bodyStream)->willReturnSelf();

        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $requestFactory->expects(self::once())->method('createRequest')
            ->with('POST', 'https://odoo.example.test/json/2/res.users/context_get')
            ->willReturn($request);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->expects(self::once())->method('createStream')->with('{}')->willReturn($bodyStream);

        $responseBody = $this->createStub(StreamInterface::class);
        $responseBody->method('__toString')->willReturn('{"ok":true}');
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getHeaders')->willReturn(['Content-Type' => ['application/json']]);
        $response->method('getBody')->willReturn($responseBody);

        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('sendRequest')->with($request)->willReturn($response);

        $transport = new Psr18Transport($client, $requestFactory, $streamFactory);
        $result = $transport->send(new HttpRequest(
            'POST',
            'https://odoo.example.test/json/2/res.users/context_get',
            ['Content-Type' => 'application/json'],
            '{}',
        ));

        self::assertSame(200, $result->statusCode);
        self::assertSame('application/json', $result->getHeaderLine('content-type'));
        self::assertSame('{"ok":true}', $result->body);
    }

    public function testMapsPsrClientFailureToOdooNetworkException(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $requestFactory = $this->createStub(RequestFactoryInterface::class);
        $requestFactory->method('createRequest')->willReturn($request);

        $client = $this->createStub(ClientInterface::class);
        $client->method('sendRequest')->willThrowException(
            new class ('connection failed for https://odoo.test/pdf?access_token=secret&download=1') extends \RuntimeException implements ClientExceptionInterface {
            }
        );

        try {
            (new Psr18Transport(
                $client,
                $requestFactory,
                $this->createStub(StreamFactoryInterface::class),
            ))->send(new HttpRequest('GET', 'https://odoo.example.test'));
            self::fail('A PSR-18 failure must be mapped');
        } catch (OdooNetworkException $exception) {
            self::assertStringContainsString('connection failed', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
            self::assertStringContainsString('access_token=***', $exception->getMessage());
        }
    }
}
