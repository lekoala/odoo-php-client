<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Legacy\LegacyRpcArgumentMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyRpcArgumentMapper::class)]
class LegacyRpcArgumentMapperTest extends TestCase
{
    private LegacyRpcArgumentMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new LegacyRpcArgumentMapper();
    }

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}>
     */
    public static function productionCallShapes(): array
    {
        $context = ['active_model' => 'account.move', 'active_ids' => [7]];
        $wizardVals = ['line_ids' => [[6, 0, [7]]], 'amount' => 140.0];

        return [
            'search positional domain' => [
                'search', [[['name', '=', 'Foo']]], [], ['domain' => [['name', '=', 'Foo']]],
            ],
            'search_read positional domain' => [
                'search_read', [[]], ['fields' => ['name']], ['domain' => [], 'fields' => ['name']],
            ],
            'read positional ids' => [
                'read', [[1, 2]], ['fields' => ['name']], ['ids' => [1, 2], 'fields' => ['name']],
            ],
            'write positional ids and values' => [
                'write', [[1], ['name' => 'Foo']], [], ['ids' => [1], 'vals' => ['name' => 'Foo']],
            ],
            'create several records' => [
                'create', [[['name' => 'Foo'], ['name' => 'Bar']]], [],
                ['vals_list' => [['name' => 'Foo'], ['name' => 'Bar']]],
            ],
            'name_search positional name' => [
                'name_search', ['Foo'], [], ['name' => 'Foo'],
            ],
            'name_get positional ids' => [
                'name_get', [[1, 2]], [], ['ids' => [1, 2]],
            ],
            'unlink positional ids' => [
                'unlink', [[5, 6]], [], ['ids' => [5, 6]],
            ],
            'fields_get positional attributes' => [
                'fields_get', [['string', 'type']], [], ['attributes' => ['string', 'type']],
            ],
            'named ids override positional ids' => [
                'read', [[1, 2]], ['ids' => [99]], ['ids' => [99]],
            ],
            'action_confirm' => [
                'action_confirm', [[42]], [], ['ids' => [42]],
            ],
            'create_invoices with context' => [
                'create_invoices', [[5]], ['context' => $context], ['ids' => [5], 'context' => $context],
            ],
            'get_portal_url' => [
                'get_portal_url', [[11]], [], ['ids' => [11]],
            ],
            'action_post' => [
                'action_post', [[7]], [], ['ids' => [7]],
            ],
            'reverse_moves with context' => [
                'reverse_moves', [[9]], ['context' => $context], ['ids' => [9], 'context' => $context],
            ],
            'action_process_edi_web_services' => [
                'action_process_edi_web_services', [[12]], [], ['ids' => [12]],
            ],
            'wizard create with context' => [
                'create', [$wizardVals], ['context' => $context],
                ['vals_list' => [$wizardVals], 'context' => $context],
            ],
            'action_create_payments with context' => [
                'action_create_payments', [[3]], ['context' => $context],
                ['ids' => [3], 'context' => $context],
            ],
            'payment token create' => [
                'create', [['provider_id' => 4]], [], ['vals_list' => [['provider_id' => 4]]],
            ],
            'apikeys generate' => [
                'generate', [], ['key' => 'k', 'name' => 'n', 'scope' => null, 'expiration_date' => null],
                ['key' => 'k', 'name' => 'n', 'scope' => null, 'expiration_date' => null],
            ],
            'apikeys remove' => [
                'remove', [], ['ids' => [9]], ['ids' => [9]],
            ],
            'message_post with body' => [
                'message_post', [[1872]], ['body' => 'Recovery path: manual_payment_required'],
                ['ids' => [1872], 'body' => 'Recovery path: manual_payment_required'],
            ],
            'reconcile a flat id list' => [
                'reconcile', [[901, 902]], [], ['ids' => [901, 902]],
            ],
            'context_get takes nothing' => [
                'context_get', [], [], [],
            ],
            'fields_get carries only attributes' => [
                'fields_get', [], ['attributes' => ['string', 'type']], ['attributes' => ['string', 'type']],
            ],
            'search_count with a named domain' => [
                'search_count', [], ['domain' => []], ['domain' => []],
            ],
        ];
    }

    /**
     * @param array<int, mixed> $args
     * @param array<string, mixed> $kwargs
     * @param array<string, mixed> $expected
     */
    #[DataProvider('productionCallShapes')]
    public function testEachProductionCallShapeTranslatesToTheseNamedParameters(
        string $method,
        array $args,
        array $kwargs,
        array $expected
    ): void {
        self::assertSame($expected, $this->mapper->toNamedParams($method, $args, $kwargs));
    }

    public function testPositionalArgumentsWithNoNameAvailableAreDroppedSilently(): void
    {
        $params = $this->mapper->toNamedParams('some_method', [[42], 'aggressive', true], []);

        self::assertSame(['ids' => [42]], $params);
        self::assertNotContains('aggressive', $params);
    }

    public function testNamingTheParametersIsWhatKeepsThem(): void
    {
        $params = ['ids' => [42], 'mode' => 'aggressive', 'force' => true];

        self::assertSame($params, $this->mapper->toNamedParams('some_method', [], $params));
    }
}
