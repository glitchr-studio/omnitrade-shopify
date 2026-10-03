<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Request\FetchInventory;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Documents;
use Omnitrade\Shopify\Products;

/**
 * The variants' inventory: inventoryQuantity (summed over the locations) when
 * inventoryItem.tracked, else a null quantity. The variants by their gids (or
 * bare ids) through nodes(ids:), 250 at a time; none given, every variant of
 * the shop through productVariants, page by page.
 */
final class FetchInventoryAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    private const PAGE = 250;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof FetchInventory;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchInventory);
        $stocks = [];
        if ([] !== $request->references) {
            $ids = array_values(array_unique(array_map(static fn ($id) => Endpoint::gid('ProductVariant', (string) $id), $request->references)));
            foreach (array_chunk($ids, self::PAGE) as $chunk) {
                $data = $this->api->admin->query(Documents::VARIANT_STOCKS, ['ids' => $chunk]);
                foreach ($data['nodes'] ?? [] as $node) {
                    if (\is_array($node) && isset($node['id'])) {
                        $stocks[] = Products::stock($node);
                    }
                }
            }
        } else {
            $after = null;
            do {
                $data = $this->api->admin->query(Documents::STOCKS, ['first' => self::PAGE, 'after' => $after]);
                foreach ($data['productVariants']['nodes'] ?? [] as $node) {
                    $stocks[] = Products::stock($node);
                }
                $info = $data['productVariants']['pageInfo'] ?? [];
                $after = !empty($info['hasNextPage']) ? ($info['endCursor'] ?? null) : null;
            } while (null !== $after);
        }

        $request->setResult($stocks);
    }
}
