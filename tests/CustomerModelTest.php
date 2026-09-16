<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Models\Customer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(\LeKoala\OdooClient\Models\Customer::class)]
class CustomerModelTest extends TestCase
{
    public function testModelMapping(): void
    {
        $data = [
            'id' => 123,
            'name' => 'The Doctor',
            'email' => 'doctor@tardis.blue',
            'is_company' => false,
            'country_id' => [1, 'Gallifrey'],
            'street' => 'TARDIS',
            'city' => 'Space and Time',
        ];

        $customer = new Customer($data);

        self::assertEquals(123, $customer->id);
        self::assertEquals('The Doctor', $customer->name);
        self::assertFalse($customer->isCompany);
        self::assertEquals(1, $customer->countryId);
        self::assertEquals('Gallifrey', $customer->countryName);
        self::assertEquals(0, $customer->paymentTokenCount);
        self::assertFalse($customer->hasSavedPaymentMethod());
    }

    public function testGetFullAddress(): void
    {
        $data = [
            'street' => '123 Main St',
            'street2' => 'Building B',
            'zip' => '1000',
            'city' => 'Brussels',
            'state_id' => [5, 'Brussels-Capital'],
            'country_id' => [1, 'Belgium'],
        ];

        $customer = new Customer($data);
        $expected = '123 Main St, Building B, 1000 Brussels, Brussels-Capital, Belgium';
        
        self::assertEquals($expected, $customer->getFullAddress());
    }

    public function testGetFullAddressHandlesMissingFields(): void
    {
        $data = [
            'street' => 'Isolated House',
            'country_id' => [1, 'Nowhere'],
        ];

        $customer = new Customer($data);
        $expected = 'Isolated House, Nowhere';
        
        self::assertEquals($expected, $customer->getFullAddress());
    }

    public function testModelMappingWithPeppol(): void
    {
        $data = [
            'peppol_eas' => '0208',
            'peppol_endpoint' => '0123456789',
            'invoice_edi_format' => 'ubl_bis3_peppol',
        ];

        $customer = new Customer($data);

        self::assertEquals('0208', $customer->peppolEas);
        self::assertEquals('0123456789', $customer->peppolEndpoint);
        self::assertEquals('ubl_bis3_peppol', $customer->invoiceEdiFormat);
    }

    public function testPeppolHelpers(): void
    {
        $customer = new Customer(['peppol_eas' => '0208', 'peppol_endpoint' => '0123456789', 'invoice_edi_format' => 'peppol_bis3']);
        self::assertTrue($customer->hasPeppolConfig());
        self::assertTrue($customer->isPeppolEnabled());

        $customer = new Customer(['peppol_eas' => '0208', 'peppol_endpoint' => '0123456789', 'invoice_edi_format' => 'ubl_bis3']);
        self::assertTrue($customer->hasPeppolConfig());
        self::assertFalse($customer->isPeppolEnabled(), 'Should be false if format does not contain "peppol"');

        $customer = new Customer(['peppol_eas' => null, 'peppol_endpoint' => '0123456789']);
        self::assertFalse($customer->hasPeppolConfig());
        self::assertFalse($customer->isPeppolEnabled());
    }

    public function testPaymentMethodHelpers(): void
    {
        $customer = new Customer(['payment_token_count' => 5]);
        self::assertEquals(5, $customer->paymentTokenCount);
        self::assertTrue($customer->hasSavedPaymentMethod());
    }
}
