<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\ProductPage;
use Omnitrade\Request\FetchProducts;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Documents;
use Omnitrade\Shopify\Products;

/**
 * A page of the shop's products, by the Admin GraphQL products(...) query,
 * the least recently updated first: the cursor is Shopify's endCursor,
 * updatedSince becomes the search filter updated_at:>'...' and the query is
 * appended to it as Shopify's own search text. Pass the same updatedSince
 * and query with the cursor: Shopify's cursor is only a position.
 */
final class FetchProductsAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    /** @param string|null $currency the prices' currency; null: the shop's */
    public function __construct(private readonly ?string $currency = null)
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchProducts;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProducts);
        $data = $this->api->admin->query(Documents::PRODUCTS, [
            'first' => max(1, min(250, $request->limit)),
            'after' => $request->cursor,
            'query' => self::search($request->updatedSince, $request->query),
        ]);
        $currency = $this->currency ?: (string) ($data['shop']['currencyCode'] ?? 'EUR');
        $taxIncluded = isset($data['shop']['taxesIncluded']) ? (bool) $data['shop']['taxesIncluded'] : null;
        $products = [];
        foreach ($data['products']['nodes'] ?? [] as $node) {
            $products[] = Products::fromGraphQL($node, $currency, $taxIncluded);
        }
        $info = $data['products']['pageInfo'] ?? [];

        $request->setResult(new ProductPage($products, !empty($info['hasNextPage']) && !empty($info['endCursor']) ? (string) $info['endCursor'] : null));
    }

    /** Shopify's search syntax: "updated_at:>'2026-10-01T00:00:00Z' margaux". */
    public static function search(?\DateTimeInterface $updatedSince, ?string $query): ?string
    {
        $parts = [];
        if (null !== $updatedSince) {
            $parts[] = sprintf("updated_at:>'%s'", \DateTimeImmutable::createFromInterface($updatedSince)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
        }
        if (null !== $query && '' !== trim($query)) {
            $parts[] = trim($query);
        }

        return $parts ? implode(' ', $parts) : null;
    }
}
