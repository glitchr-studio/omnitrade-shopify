<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Exception\InvalidNotificationException;
use Omnitrade\Exception\ProviderException;
use Omnitrade\Model\Notification;
use Omnitrade\Model\Status;
use Omnitrade\Request\Notify;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Api\Hmac;
use Omnitrade\Shopify\Documents;

/**
 * A Shopify webhook, its HMAC and its shop checked. The order topics read as
 * what they mean: orders/paid is PAID, orders/cancelled CANCELLED,
 * refunds/create REFUNDED; the rest (fulfilments, products) is handed back
 * with no status, for the application to read from $raw.
 *
 * The reference is the draft order the sale began with (the transaction's
 * reference) when the order names one, else the order's own gid; the
 * merchant's reference travels in the note attributes (Documents::REFERENCE_KEY).
 */
final class NotifyAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
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

        $request->setResult(new Notification(
            provider: 'shopify',
            event: $topic,
            reference: $reference,
            status: $status,
            id: $request->header('X-Shopify-Webhook-Id'),
            raw: ['topic' => $topic, 'shop' => $shop, 'merchant_reference' => $merchantReference, 'order' => $payload['admin_graphql_api_id'] ?? null, 'payload' => $payload],
        ));
    }
}
