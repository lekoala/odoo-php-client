<?php

namespace LeKoala\OdooClient\Tests;

use LeKoala\OdooClient\Services\SubscriptionService;
use LeKoala\OdooClient\Models\Subscription;
use LeKoala\OdooClient\Models\SubscriptionPlan;
use LeKoala\OdooClient\Inputs\SubscriptionInput;
use LeKoala\OdooClient\Events\SubscriptionCancelledEvent;
use PHPUnit\Framework\TestCase;
use LeKoala\OdooClient\QueryOptions;

/**
 * @covers \LeKoala\OdooClient\Services\SubscriptionService
 */
class SubscriptionServiceTest extends TestCase
{
    private MockClient $client;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->client = new MockClient();
        $this->service = new SubscriptionService($this->client);
    }

    public function testListReturnsSubscriptions(): void
    {
        // Mocking sale.subscription (aliased to sale.order in MockClient)
        // Note: The service now calls sale.order
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/001', 'partner_id' => [10, 'Customer A'], 'state' => 'sale', 'is_subscription' => true],
            ['id' => 2, 'name' => 'SUB/002', 'partner_id' => [20, 'Customer B'], 'state' => 'draft', 'is_subscription' => true],
        ]);

        $subscriptions = $this->service->list();

        self::assertCount(2, $subscriptions);
        self::assertInstanceOf(Subscription::class, $subscriptions[0]);
        self::assertEquals('SUB/001', $subscriptions[0]->name);
        self::assertEquals(10, $subscriptions[0]->partnerId);
        self::assertEquals('Customer A', $subscriptions[0]->partnerName);
    }

    public function testListReturnsSubscriptionsWithPlanName(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/001', 'plan_id' => [1, 'Monthly Plan'], 'is_subscription' => true, 'access_token' => 'token456'],
        ]);

        $subscriptions = $this->service->list();

        self::assertCount(1, $subscriptions);
        self::assertEquals('Monthly Plan', $subscriptions[0]->planName);
        self::assertEquals('token456', $subscriptions[0]->accessToken);
    }

    public function testGetPortalUrl(): void
    {
        $this->client->mockResponse('sale.order', 'read', [
            ['id' => 1, 'access_token' => 'token456', 'access_url' => '/my/orders/1'],
        ]);

        $url = $this->service->getPortalUrl(1);

        self::assertIsString($url);
        self::assertStringContainsString('token456', $url);
        self::assertStringContainsString('/my/orders/1', $url);
    }

    public function testGetReturnsSubscription(): void
    {
        $this->client->mockResponse('sale.order', 'read', [
            ['id' => 1, 'name' => 'SUB/001', 'partner_id' => [10, 'Customer A'], 'state' => 'sale', 'start_date' => '2026-01-01'],
        ]);

        $subscription = $this->service->get(1);

        self::assertInstanceOf(Subscription::class, $subscription);
        self::assertEquals(1, $subscription->id);
        self::assertEquals('sale', $subscription->state);
        self::assertEquals('2026-01-01', $subscription->startDate);
    }

    public function testGetReturnsNullWhenNotFound(): void
    {
        $this->client->mockResponse('sale.order', 'read', []);

        $subscription = $this->service->get(999);

        self::assertNull($subscription);
    }

    public function testCreateReturnsId(): void
    {
        $this->client->mockResponse('sale.order', 'create', 42);

        $input = new SubscriptionInput(partnerId: 10, planId: 1);
        $id = $this->service->create($input);

        self::assertEquals(42, $id);
        $lastCall = $this->client->getLastCall();
        self::assertEquals('create', $lastCall['method']);
        /** @var array<string, mixed> $values */
        $values = $lastCall['params']['vals_list'][0] ?? [];
        self::assertTrue($values['is_subscription'] ?? false);
    }

    public function testUpdateReturnsTrue(): void
    {
        $this->client->mockResponse('sale.order', 'write', true);

        $input = new SubscriptionInput(partnerId: 10, state: 'sale');
        $result = $this->service->update(1, $input);

        self::assertTrue($result);
    }

    public function testUpdateFieldsDoesNotAddSubscriptionCreationDefaults(): void
    {
        $this->client->mockResponse('sale.order', 'write', true);

        self::assertTrue($this->service->updateFields(1, ['next_invoice_date' => '2026-10-01']));

        $lastCall = $this->client->getLastCall();
        self::assertSame(['next_invoice_date' => '2026-10-01'], $lastCall['params']['vals']);
    }

    public function testCancelUsesOdoosWorkflowAndVerifiesState(): void
    {
        $this->client->mockResponse('sale.order', 'action_cancel', true);
        $this->client->mockResponse('sale.order', 'read', [
            ['id' => 1, 'name' => 'SUB/001', 'state' => 'cancel'],
        ]);

        $result = $this->service->cancel(1);

        self::assertTrue($result);

        $cancelCall = null;
        foreach ($this->client->getCallLog() as $call) {
            if ($call['method'] === 'action_cancel') {
                $cancelCall = $call;
            }
        }
        self::assertNotNull($cancelCall, 'action_cancel must be called');
        self::assertSame([1], $cancelCall['params']['ids']);
    }

    public function testCancelSkipsStateVerificationOnDemand(): void
    {
        $this->client->mockResponse('sale.order', 'action_cancel', true);

        self::assertTrue($this->service->cancel(1, false));

        $methods = array_column($this->client->getCallLog(), 'method');
        self::assertContains('action_cancel', $methods);
        self::assertNotContains('read', $methods);
    }

    public function testCancelFailsWhenOdooDidNotCancelTheRecord(): void
    {
        $this->client->mockResponse('sale.order', 'action_cancel', true);
        $this->client->mockResponse('sale.order', 'read', [
            ['id' => 1, 'name' => 'SUB/001', 'state' => 'sale'],
        ]);

        try {
            $this->service->cancel(1);
            self::fail('A record still in state sale must raise.');
        } catch (\LeKoala\OdooClient\OdooException $e) {
            self::assertStringContainsString('was not cancelled', $e->getMessage());
        }

        self::assertSame([], $this->client->getDispatchedEvents());
    }

    public function testCancelDispatchesEvent(): void
    {
        $this->client->mockResponse('sale.order', 'action_cancel', true);
        $this->client->mockResponse('sale.order', 'read', [
            ['id' => 55, 'name' => 'SUB/055', 'state' => 'cancel'],
        ]);
        $this->service->cancel(55);

        $events = $this->client->getDispatchedEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(SubscriptionCancelledEvent::class, $events[0]);
        self::assertEquals(55, $events[0]->getId());
        self::assertEquals('sale.order', $events[0]->getModel());
    }

    public function testGetByPartnerFiltersCorrectly(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/001', 'partner_id' => [10, 'Customer A'], 'state' => 'sale', 'is_subscription' => true],
        ]);

        $subscriptions = $this->service->getByPartner(10);

        self::assertCount(1, $subscriptions);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['partner_id', '=', 10], $domain);
        self::assertContains(['is_subscription', '=', true], $domain);
        self::assertArrayNotHasKey('limit', $lastCall['params']);
    }

    public function testGetByPartnerCanExplicitlyRequestAllRows(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', []);

        $this->service->getByPartner(10, new QueryOptions());

        $params = $this->client->getLastCall()['params'];
        self::assertArrayNotHasKey('limit', $params);
        self::assertContains(['partner_id', '=', 10], $params['domain']);
        self::assertContains(['is_subscription', '=', true], $params['domain']);
    }

    public function testListPlans(): void
    {
        $this->client->mockResponse('sale.subscription.plan', 'search_read', [
            ['id' => 1, 'name' => 'Monthly', 'billing_period_value' => 1, 'billing_period_unit' => 'month'],
            ['id' => 2, 'name' => 'Annual', 'billing_period_value' => 1, 'billing_period_unit' => 'year'],
        ]);

        $plans = $this->service->listPlans();

        self::assertCount(2, $plans);
        self::assertInstanceOf(SubscriptionPlan::class, $plans[0]);
        self::assertTrue($plans[0]->isMonthly());
        self::assertTrue($plans[1]->isAnnual());
        self::assertEquals('Monthly', $plans[0]->name);
    }

    public function testListPaymentErrors(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/ERR', 'payment_exception' => true, 'is_subscription' => true],
        ]);

        $subscriptions = $this->service->listPaymentErrors();

        self::assertCount(1, $subscriptions);
        $lastCall = $this->client->getLastCall();
        /** @var array<int, mixed> $domain */
        $domain = $lastCall['params']['domain'] ?? [];
        self::assertContains(['payment_exception', '=', true], $domain);
    }

    public function testListRequestsAndPopulatesPaymentStatus(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/001', 'payment_status' => 'paid', 'is_subscription' => true],
        ]);

        $subscriptions = $this->service->list();

        self::assertTrue($subscriptions[0]->isPaid());
        self::assertContains('payment_status', $this->client->getLastCall()['params']['fields']);
    }

    public function testPaymentStatusStaysNullWhenOdooOmitsIt(): void
    {
        $this->client->mockResponse('sale.order', 'search_read', [
            ['id' => 1, 'name' => 'SUB/001', 'is_subscription' => true],
        ]);

        $subscriptions = $this->service->list();

        self::assertNull($subscriptions[0]->paymentStatus);
        self::assertFalse($subscriptions[0]->isPaid());
    }

    public function testSubscriptionPaymentHelpers(): void
    {
        $sub = new Subscription(['payment_status' => 'paid', 'payment_exception' => false]);
        self::assertTrue($sub->isPaid());
        self::assertFalse($sub->hasPaymentError());
        self::assertEquals('Paid', $sub->getPaymentStatusLabel());

        $sub = new Subscription(['payment_status' => 'not_paid', 'payment_exception' => true]);
        self::assertFalse($sub->isPaid());
        self::assertTrue($sub->hasPaymentError());
        self::assertEquals('Not Paid', $sub->getPaymentStatusLabel());
    }
}
