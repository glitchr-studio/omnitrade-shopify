<?php

namespace Omnitrade\Shopify\Tests;

use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\Payment;
use Omnitrade\Model\Status;
use Omnitrade\Request\Refund;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\ShopifyGatewayFactory;
use Omnitrade\Tests\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ShopifyGatewayTest extends TestCase
{
    private const SECRET = 'shpss_secret';

    /** @var list<array{query: string, variables: array}> */
    private array $queries = [];

    private function gateway(): \Omnitrade\GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('https://example.myshopify.com/admin/api/2026-07/graphql.json', $url);
            self::assertContains('X-Shopify-Access-Token: shpat_x', $options['headers']);
            $sent = json_decode($options['body'], true);
            $this->queries[] = $sent;
            $data = match (true) {
                str_contains($sent['query'], 'draftOrderCreate') => ['draftOrderCreate' => ['draftOrder' => ['id' => 'gid://shopify/DraftOrder/7', 'name' => '#D7', 'invoiceUrl' => 'https://example.myshopify.com/invoices/abc', 'status' => 'OPEN', 'totalPrice' => '12.50'], 'userErrors' => []]],
                str_contains($sent['query'], 'query DraftOrder') => ['draftOrder' => 'gid://shopify/DraftOrder/8' === $sent['variables']['id']
                    ? ['id' => 'gid://shopify/DraftOrder/8', 'name' => '#D8', 'status' => 'COMPLETED', 'order' => ['id' => 'gid://shopify/Order/1042', 'name' => '#1042', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED']]
                    : ('gid://shopify/DraftOrder/9' === $sent['variables']['id'] ? null : ['id' => $sent['variables']['id'], 'name' => '#D7', 'status' => 'OPEN', 'invoiceUrl' => 'https://example.myshopify.com/invoices/abc', 'order' => null])],
                str_contains($sent['query'], 'query Order') => ['order' => ['id' => 'gid://shopify/Order/1042', 'name' => '#1042', 'createdAt' => '2026-10-01T10:00:00Z', 'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED', 'totalPriceSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'EUR']], 'email' => 'camille@example.org', 'customer' => ['displayName' => 'Camille Durand', 'email' => 'camille@example.org'], 'shippingAddress' => ['address1' => '1 rue du Test', 'zip' => '67000', 'city' => 'Strasbourg', 'countryCodeV2' => 'FR'], 'lineItems' => ['nodes' => [['title' => 'Dix heures', 'quantity' => 1, 'sku' => null, 'variant' => ['id' => 'gid://shopify/ProductVariant/55'], 'originalUnitPriceSet' => ['shopMoney' => ['amount' => '12.50', 'currencyCode' => 'EUR']]]]]]],
                default => [],
            };

            return new MockResponse(json_encode(['data' => $data, 'extensions' => ['cost' => ['throttleStatus' => ['currentlyAvailable' => 1990, 'restoreRate' => 100]]]]));
        });

        return (new ShopifyGatewayFactory($http))->create(['shop_domain' => 'example.myshopify.com', 'admin_token' => 'shpat_x', 'webhook_secret' => self::SECRET, 'draft_order_tags' => ['omnitrade']]);
    }

    public function testAPurchaseIsADraftOrderPaidOnTheInvoicePage(): void
    {
        $gateway = $this->gateway();
        $payment = new Payment(Money::of(1250 + 490, 'EUR'), 'ORDER-1042', 'Commande 1042', Fixtures::payment()->customer,
            lines: [new Line('Dix heures', Money::of(1250, 'EUR'), reference: 'gid://shopify/ProductVariant/55'), ], shipping: Money::of(490, 'EUR'));
        $transaction = $gateway->purchase($payment);

        self::assertTrue($transaction->isRedirect());
        self::assertSame('gid://shopify/DraftOrder/7', $transaction->reference);
        self::assertSame('https://example.myshopify.com/invoices/abc', $transaction->redirectUrl);
        $input = $this->queries[0]['variables']['input'];
        self::assertSame('gid://shopify/ProductVariant/55', $input['lineItems'][0]['variantId'], 'a line with a variant gid names the variant');
        self::assertSame('12.50', $input['lineItems'][0]['originalUnitPriceWithCurrency']['amount']);
        self::assertSame('4.90', $input['shippingLine']['priceWithCurrency']['amount'], 'the shipping carried over whole');
        self::assertSame([['key' => 'omnitrade_reference', 'value' => 'ORDER-1042']], $input['customAttributes']);
        self::assertSame(['omnitrade'], $input['tags']);
        self::assertSame('camille@example.org', $input['email']);
        self::assertFalse($gateway->supports(Refund::class), 'refunds are Shopify\'s own');
    }

    public function testADraftOrderIsFetchedAsWhatBecameOfIt(): void
    {
        $gateway = $this->gateway();
        self::assertSame(Status::PENDING, $gateway->fetch('gid://shopify/DraftOrder/7')->status);
        $paid = $gateway->fetch('gid://shopify/DraftOrder/8');
        self::assertTrue($paid->isPaid());
        self::assertSame('gid://shopify/Order/1042', $paid->metadata['order']);
        self::assertSame(Status::CANCELLED, $gateway->fetch('gid://shopify/DraftOrder/9')->status, 'deleted on Shopify: the sale is off');
    }

    public function testAShopOrderIsRead(): void
    {
        $order = $this->gateway()->fetchOrder('1042');
        self::assertSame('#1042', $order->number);
        self::assertSame(Status::PAID, $order->status);
        self::assertSame(1250, $order->total->amount);
        self::assertSame('Camille Durand', $order->customer->name);
        self::assertSame('FR', $order->customer->country);
        self::assertSame('gid://shopify/ProductVariant/55', $order->lines[0]->reference);
        self::assertSame('FULFILLED', $order->fulfillmentStatus);
    }

    public function testANotificationIsCheckedAgainstItsHmacAndShop(): void
    {
        $gateway = $this->gateway();
        $body = json_encode(['id' => 1042, 'admin_graphql_api_id' => 'gid://shopify/Order/1042', 'draft_order_id' => 7, 'note_attributes' => [['name' => 'omnitrade_reference', 'value' => 'ORDER-1042']]]);
        $headers = ['X-Shopify-Hmac-Sha256' => Hmac::sign($body, self::SECRET), 'X-Shopify-Topic' => 'orders/paid', 'X-Shopify-Shop-Domain' => 'example.myshopify.com', 'X-Shopify-Webhook-Id' => 'wh-1'];

        $notification = $gateway->notify($body, $headers);
        self::assertSame('orders/paid', $notification->event);
        self::assertSame(Status::PAID, $notification->status);
        self::assertSame('gid://shopify/DraftOrder/7', $notification->reference, 'the draft the sale began with: the transaction\'s reference');
        self::assertSame('ORDER-1042', $notification->raw['merchant_reference']);
        self::assertSame('wh-1', $notification->id);

        try {
            $gateway->notify($body, ['X-Shopify-Hmac-Sha256' => Hmac::sign($body, self::SECRET), 'X-Shopify-Topic' => 'orders/paid', 'X-Shopify-Shop-Domain' => 'other.myshopify.com']);
            self::fail('another shop\'s');
        } catch (InvalidNotificationException $e) {
            self::assertStringContainsString('other.myshopify.com', $e->getMessage());
        }
        $this->expectException(InvalidNotificationException::class);
        $gateway->notify($body, ['X-Shopify-Hmac-Sha256' => 'nope', 'X-Shopify-Topic' => 'orders/paid']);
    }
}
