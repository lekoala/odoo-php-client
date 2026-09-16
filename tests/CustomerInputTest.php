<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Inputs\CustomerInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Inputs\CustomerInput::class)]
class CustomerInputTest extends TestCase
{
    public function testToArrayIncludesBasicFields(): void
    {
        $input = new CustomerInput(name: 'Test Corp');
        $array = $input->toArray();

        self::assertEquals('Test Corp', $array['name']);
        self::assertFalse($array['is_company']);
        self::assertArrayNotHasKey('email', $array);
    }

    public function testToArrayIncludesPeppolFields(): void
    {
        $input = new CustomerInput(
            name: 'Peppol Corp',
            peppolEas: '0208',
            peppolEndpoint: '1234567890',
            invoiceEdiFormat: 'peppol_bis3'
        );

        $array = $input->toArray();

        self::assertEquals('Peppol Corp', $array['name']);
        self::assertEquals('0208', $array['peppol_eas']);
        self::assertEquals('1234567890', $array['peppol_endpoint']);
        self::assertEquals('peppol_bis3', $array['invoice_edi_format']);
    }

    public function testExtraDataIsMerged(): void
    {
        $input = new CustomerInput(
            name: 'Extra Corp',
            extraData: ['custom_field' => 'value']
        );

        $array = $input->toArray();

        self::assertEquals('Extra Corp', $array['name']);
        self::assertEquals('value', $array['custom_field']);
    }
}
