<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Adapters\JsonAdapter;
use LeKoala\OdooClient\Exceptions\OdooAccessException;
use LeKoala\OdooClient\Exceptions\OdooAuthException;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\OdooValidationException;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Http\HttpResponse;
use LeKoala\OdooClient\Tests\Support\RecordingTransport;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LeKoala\OdooClient\Adapters\JsonAdapter
 */
class JsonAdapterTest extends TestCase
{
    private JsonAdapter $adapter;

    protected function setUp(): void
    {
        $config = new Config('http://odoo.local', 'test_db', 'api_key');
        $this->adapter = new JsonAdapter($config);
    }

    /** @param array<string, mixed> $params */
    private function encodeParams(array $params): string
    {
        $reflection = new \ReflectionMethod($this->adapter, 'encodeParams');
        $reflection->setAccessible(true);

        return $reflection->invoke($this->adapter, $params, 'res.users', 'context_get');
    }

    public function testEmptyParametersAreEncodedAsAJsonObject(): void
    {
        self::assertSame('{}', $this->encodeParams([]));
    }

    public function testInjectedTransportReceivesJson2Request(): void
    {
        $transport = new RecordingTransport([
            new HttpResponse(200, ['Content-Type' => ['application/json']], '{"ok":true}', ''),
        ]);
        $config = (new Config('https://odoo.example.test', 'test_db', 'api_key'))
            ->setHttpTransport($transport);

        $result = (new JsonAdapter($config))->call('res.users', 'context_get');

        self::assertSame(['ok' => true], $result);
        self::assertCount(1, $transport->requests);
        self::assertSame('https://odoo.example.test/json/2/res.users/context_get', $transport->requests[0]->url);
        self::assertSame('{}', $transport->requests[0]->body);
        self::assertSame('Bearer api_key', $transport->requests[0]->headers['Authorization']);
        self::assertSame('test_db', $transport->requests[0]->headers['X-Odoo-Database']);
    }

    public function testNamedParametersRemainAJsonObject(): void
    {
        self::assertSame('{"ids":[1]}', $this->encodeParams(['ids' => [1]]));
    }

    private function classify(int $httpCode, mixed $result, ?string $response = null): void
    {
        $reflection = new \ReflectionMethod($this->adapter, 'throwForErrorResponse');
        $reflection->setAccessible(true);
        $reflection->invoke(
            $this->adapter,
            $httpCode,
            $response ?? (string) json_encode($result),
            $result
        );
    }

    public function testUnauthorizedIsAnAuthFailure(): void
    {
        $this->expectException(OdooAuthException::class);
        $this->expectExceptionMessage('HTTP 401');

        $this->classify(401, ['name' => 'werkzeug.exceptions.Unauthorized', 'message' => 'Invalid apikey']);
    }

    public function testForbiddenIsAnAccessFailureNotAnAuthFailure(): void
    {
        try {
            $this->classify(403, [
                'name' => 'odoo.exceptions.AccessError',
                'message' => "You are not allowed to access 'Payment' records.",
            ]);
            self::fail('A 403 must raise.');
        } catch (OdooAccessException $e) {
            self::assertStringContainsString('HTTP 403', $e->getMessage());
            self::assertStringContainsString('no right on this model', $e->getMessage());
            self::assertSame('odoo.exceptions.AccessError', $e->getOdooName());
        }

        self::assertFalse(is_a(OdooAccessException::class, OdooAuthException::class, true));
    }

    public function testAuthFailureCarriesOdoosOwnMessage(): void
    {
        try {
            $this->classify(401, ['message' => 'Invalid apikey']);
            self::fail('A 401 must raise.');
        } catch (OdooAuthException $e) {
            self::assertStringContainsString('Invalid apikey', $e->getMessage());
            self::assertSame('Invalid apikey', $e->getOdooMessage());
        }
    }

    public function testValidationErrorKeepsTheOdooEnvelope(): void
    {
        try {
            $this->classify(400, [
                'name' => 'odoo.exceptions.ValidationError',
                'message' => 'The journal is required.',
                'arguments' => ['The journal is required.'],
            ]);
            self::fail('A 400 must raise.');
        } catch (OdooValidationException $e) {
            self::assertSame('odoo.exceptions.ValidationError', $e->getOdooName());
            self::assertSame('The journal is required.', $e->getOdooMessage());
            self::assertSame(['The journal is required.'], $e->getOdooArguments());
            self::assertTrue($e->hasOdooError());
            self::assertStringContainsString('odoo.exceptions.ValidationError', $e->getMessage());
        }
    }

    public function testNonJsonErrorBodyIsStrippedAndCarriesNoOdooName(): void
    {
        try {
            $this->classify(404, null, '<html><body><h1>404 Not Found</h1></body></html>');
            self::fail('A 404 must raise.');
        } catch (OdooValidationException $e) {
            self::assertStringContainsString('404 Not Found', $e->getMessage());
            self::assertSame('', $e->getOdooName());
            self::assertFalse($e->hasOdooError());
        }
    }

    public function testGatewayErrorsStayTransient(): void
    {
        $this->expectException(OdooNetworkException::class);

        $this->classify(503, null, 'Service Unavailable');
    }

    public function testExceptionEnvelopeOnA200StillRaises(): void
    {
        $this->expectException(OdooValidationException::class);

        $this->classify(200, ['name' => 'odoo.exceptions.UserError', 'message' => 'Nope']);
    }

    public function testSuccessfulResponseIsNotClassifiedAsAnError(): void
    {
        $this->classify(200, ['records' => [['id' => 1]]]);

        self::assertTrue(true);
    }
}
