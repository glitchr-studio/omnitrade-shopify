<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Status;
use Omnitrade\Model\Stock;
use Omnitrade\Request\Notify;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\Documents;
use Omnitrade\Shopify\Products;

/**
 * A Shopify webhook, its HMAC and its shop checked. The order topics read as
 * what they mean: orders/paid is PAID, orders/cancelled CANCELLED,
 * refunds/create REFUNDED; the rest (fulfilments...) is handed back with no
 * status, for the application to read from $raw.
 *
 * The catalogue topics carry the catalogue: products/create and
 * products/update the Product (the REST payload read like a GraphQL node),
 * products/delete its gid alone (no product: it is gone), and
 * inventory_levels/update one Stock - the level at one location, which
 * names the inventory item rather than the variant: Stock::$reference and
 * $item are then the InventoryItem's gid, to match on the variant's
 * Stock::$item.
 *
 * The reference is the draft order the sale began with (the transaction's
 * reference) when the order names one, else the order's own gid; the
 * merchant's reference travels in the note attributes (Documents::REFERENCE_KEY).
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private ?array $shop = null;

    /** @param string|null $currency the prices' currency; null: the shop's, asked once */
    public function __construct(private readonly ?string $currency = null)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $endpoint = $this->api->endpoint;
        if (!$endpoint->canVerifyWebhooks()) {
            throw new InvalidNotificationException('shopify', 'No webhook secret: the notification cannot be checked.');
        }
        if (!Hmac::verify($request->body, $request->header('X-Shopify-Hmac-Sha256'), $endpoint->webhookSecret)) {
            throw new InvalidNotificationException('shopify', 'The notification\'s signature does not match.');
        }
        $shop = (string) $request->header('X-Shopify-Shop-Domain');
        if ('' !== $shop && $shop !== $endpoint->shop()) {
            throw new InvalidNotificationException('shopify', sprintf('The notification is another shop\'s (%s).', $shop));
        }
        $payload = json_decode($request->body, true);
        if (!\is_array($payload)) {
            throw new ProviderException('shopify', 'The notification is not JSON.');
        }
        $topic = (string) $request->header('X-Shopify-Topic');

        $status = match ($topic) {
            'orders/paid' => Status::PAID,
            'orders/cancelled' => Status::CANCELLED,
            'refunds/create' => Status::REFUNDED,
            default => null,
        };
        $reference = null;
        if (str_starts_with($topic, 'orders/')) {
            $reference = isset($payload['draft_order_id']) && '' !== (string) $payload['draft_order_id']
                ? Endpoint::gid('DraftOrder', (string) $payload['draft_order_id'])
                : (isset($payload['admin_graphql_api_id']) ? (string) $payload['admin_graphql_api_id'] : (isset($payload['id']) ? Endpoint::gid('Order', (string) $payload['id']) : null));
        } elseif (isset($payload['admin_graphql_api_id'])) {
            $reference = (string) $payload['admin_graphql_api_id'];
        }
        $merchantReference = null;
        foreach ($payload['note_attributes'] ?? [] as $attribute) {
            if (Documents::REFERENCE_KEY === ($attribute['name'] ?? $attribute['key'] ?? null)) {
                $merchantReference = (string) ($attribute['value'] ?? '') ?: null;
            }
        }

        $product = null;
        $stocks = [];
        if ('products/create' === $topic || 'products/update' === $topic) {
            [$currency, $taxIncluded] = $this->currency();
            $product = Products::fromRest($payload, $currency, $taxIncluded);
            $reference = $product->reference;
        } elseif ('products/delete' === $topic && isset($payload['id'])) {
            $reference = Endpoint::gid('Product', (string) $payload['id']);
        } elseif ('inventory_levels/update' === $topic && isset($payload['inventory_item_id'])) {
            $item = Endpoint::gid('InventoryItem', (string) $payload['inventory_item_id']);
            $reference = $item;
            $available = \array_key_exists('available', $payload) && null !== $payload['available'] ? (int) $payload['available'] : null;
            $stocks = [new Stock($item, $available, null !== $available, location: isset($payload['location_id']) ? Endpoint::gid('Location', (string) $payload['location_id']) : null, item: $item)];
        }

        $request->setResult(new Notification(
            provider: 'shopify',
            event: $topic,
            reference: $reference,
            status: $status,
            id: $request->header('X-Shopify-Webhook-Id'),
            raw: ['topic' => $topic, 'shop' => $shop, 'merchant_reference' => $merchantReference, 'order' => $payload['admin_graphql_api_id'] ?? null, 'payload' => $payload],
            product: $product,
            stocks: $stocks,
        ));
    }

    /** @return array{0: string, 1: ?bool} the prices' currency, and whether taxes are in them */
    private function currency(): array
    {
        if (null !== $this->currency && '' !== $this->currency) {
            return [$this->currency, null];
        }
        $this->shop ??= $this->api->admin->query(Documents::SHOP)['shop'] ?? [];

        return [(string) ($this->shop['currencyCode'] ?? 'EUR'), isset($this->shop['taxesIncluded']) ? (bool) $this->shop['taxesIncluded'] : null];
    }
}
