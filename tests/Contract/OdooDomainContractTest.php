<?php

namespace LeKoala\OdooClient\Tests\Contract;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Domain;
use PHPUnit\Framework\TestCase;

/**
 * Live contract test against a real Odoo 19 instance.
 *
 * Skipped unless ODOO_CONTRACT_URL, ODOO_CONTRACT_DB and ODOO_CONTRACT_API_KEY
 * are set. It sends one composed domain (A AND (B OR C)) through a real
 * `search` to prove our flat prefix representation passes Odoo's parser.
 */
class OdooDomainContractTest extends TestCase
{
    public function testComposedDomainPassesRealOdooParser(): void
    {
        $url = getenv('ODOO_CONTRACT_URL') ?: '';
        $db = getenv('ODOO_CONTRACT_DB') ?: '';
        $apiKey = getenv('ODOO_CONTRACT_API_KEY') ?: '';

        if ($url === '' || $db === '' || $apiKey === '') {
            self::markTestSkipped('Set ODOO_CONTRACT_URL, ODOO_CONTRACT_DB and ODOO_CONTRACT_API_KEY to run the Odoo contract test.');
        }

        $client = new Client(new Config($url, $db, $apiKey));

        $domain = Domain::all(
            Domain::condition('is_company', '=', true),
            Domain::any(
                Domain::condition('name', 'ilike', 'a'),
                Domain::condition('email', 'ilike', 'example'),
            ),
        );

        $ids = $client->search('res.partner', $domain, ['limit' => 1]);

        self::assertIsArray($ids);
    }
}
