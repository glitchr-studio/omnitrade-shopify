<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\Status;
use Omnitrade\Model\Transaction;
use Omnitrade\Request\FetchTransaction;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Documents;

/**
 * What became of a draft order: completed into a paid order (PAID), deleted
 * on Shopify (CANCELLED - the sale is not happening), or still open (PENDING).
 * This is what covers a buyer who comes back before the webhook, or a webhook
 * that never arrived.
 */
final class FetchTransactionAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchTransaction;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchTransaction);
        $data = $this->api->admin->query(Documents::DRAFT_ORDER, ['id' => $request->reference]);
        $draft = $data['draftOrder'] ?? null;
        if (null === $draft) {
            $request->setResult(new Transaction('shopify', $request->reference, Status::CANCELLED, message: 'deleted', raw: $data));

            return;
        }
        $status = strtoupper((string) ($draft['status'] ?? ''));
        $financial = strtoupper((string) ($draft['order']['displayFinancialStatus'] ?? ''));
        $state = match (true) {
            'COMPLETED' === $status && 'REFUNDED' === $financial => Status::REFUNDED,
            'COMPLETED' === $status && 'PARTIALLY_REFUNDED' === $financial => Status::PARTIALLY_REFUNDED,
            'COMPLETED' === $status && \in_array($financial, ['PAID', ''], true) => Status::PAID,
            'COMPLETED' === $status && 'VOIDED' === $financial => Status::CANCELLED,
            default => Status::PENDING,
        };

        $request->setResult(new Transaction(
            provider: 'shopify',
            reference: $request->reference,
            status: $state,
            redirectUrl: Status::PENDING === $state ? ($draft['invoiceUrl'] ?? null) : null,
            message: $financial ?: $status,
            metadata: array_filter(['name' => $draft['name'] ?? null, 'order' => $draft['order']['id'] ?? null, 'order_name' => $draft['order']['name'] ?? null]),
            raw: $draft,
        ));
    }
}
