<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Customer;
use Omnitrade\Model\Line;
use Omnitrade\Model\Money;
use Omnitrade\Model\PlatformOrder;
use Omnitrade\Model\Status;
use Omnitrade\Request\FetchOrder;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Documents;

/** A Shopify order, by its gid or its bare id. */
final class FetchOrderAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchOrder;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchOrder);
        $data = $this->api->admin->query(Documents::ORDER, ['id' => Endpoint::gid('Order', $request->reference)]);
        $order = $data['order'] ?? null;
        if (!\is_array($order)) {
            throw new ProviderException('shopify', sprintf('Shopify knows no order %s.', $request->reference));
        }
        $request->setResult(self::order($order));
    }

    /** @param array<string, mixed> $order as Documents::ORDER returns it */
    public static function order(array $order): PlatformOrder
    {
        $total = $order['totalPriceSet']['shopMoney'] ?? [];
        $currency = strtoupper((string) ($total['currencyCode'] ?? 'EUR'));
        $financial = strtoupper((string) ($order['displayFinancialStatus'] ?? ''));
        $address = $order['shippingAddress'] ?? [];
        $lines = [];
        foreach ($order['lineItems']['nodes'] ?? [] as $node) {
            $unit = $node['originalUnitPriceSet']['shopMoney'] ?? [];
            $lines[] = new Line(
                (string) ($node['title'] ?? ''),
                Money::fromDecimal((string) ($unit['amount'] ?? '0'), (string) ($unit['currencyCode'] ?? $currency)),
                (int) ($node['quantity'] ?? 1),
                $node['sku'] ?? null,
                reference: $node['variant']['id'] ?? null,
            );
        }

        return new PlatformOrder(
            provider: 'shopify',
            reference: (string) $order['id'],
            number: $order['name'] ?? null,
            status: match ($financial) {
                'PAID' => Status::PAID,
                'PARTIALLY_REFUNDED' => Status::PARTIALLY_REFUNDED,
                'REFUNDED' => Status::REFUNDED,
                'VOIDED' => Status::CANCELLED,
                'AUTHORIZED' => Status::AUTHORIZED,
                default => Status::PENDING,
            },
            total: Money::fromDecimal((string) ($total['amount'] ?? '0'), $currency),
            customer: new Customer(
                email: $order['email'] ?? $order['customer']['email'] ?? null,
                name: $order['customer']['displayName'] ?? null,
                phone: $address['phone'] ?? null,
                street: array_values(array_filter([$address['address1'] ?? null, $address['address2'] ?? null])),
                postalCode: $address['zip'] ?? null,
                city: $address['city'] ?? null,
                country: $address['countryCodeV2'] ?? null,
            ),
            lines: $lines,
            financialStatus: $financial ?: null,
            fulfillmentStatus: $order['displayFulfillmentStatus'] ?? null,
            createdAt: isset($order['createdAt']) ? new \DateTimeImmutable((string) $order['createdAt']) : null,
            raw: $order,
        );
    }
}
