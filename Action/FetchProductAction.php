<?php

namespace Omnitrade\Shopify\Action;

use Omnitrade\Action\ActionInterface;
use Omnitrade\Action\ApiAwareInterface;
use Omnitrade\Action\ApiAwareTrait;
use Omnitrade\Model\Product;
use Omnitrade\Model\Reference;
use Omnitrade\Request\FetchProduct;
use Omnitrade\Request\Request;
use Omnitrade\Shopify\Api;
use Omnitrade\Shopify\Api\Endpoint;
use Omnitrade\Shopify\Documents;
use Omnitrade\Shopify\Products;

/**
 * One product: by its gid, its bare numeric id, its handle, or the address
 * of a page of it - the storefront's /products/<handle> (the handle is the
 * last segment) or the admin's /admin/products/<id>. Null when the shop has
 * no such product.
 */
final class FetchProductAction implements ActionInterface, ApiAwareInterface
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
        return $request instanceof FetchProduct;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FetchProduct);
        [$id, $handle] = self::target($request->reference);
        if (null !== $id) {
            $data = $this->api->admin->query(Documents::PRODUCT, ['id' => $id]);
            $node = $data['product'] ?? null;
        } elseif (null !== $handle) {
            // productByHandle is gone from recent versions; the search
            // filter is everywhere. It matches loosely: keep the exact one.
            $data = $this->api->admin->query(Documents::PRODUCTS, ['first' => 5, 'after' => null, 'query' => sprintf('handle:"%s"', addcslashes($handle, '"\\'))]);
            $node = null;
            foreach ($data['products']['nodes'] ?? [] as $candidate) {
                if (($candidate['handle'] ?? null) === $handle) {
                    $node = $candidate;
                    break;
                }
            }
        } else {
            $request->setResult(null);

            return;
        }
        if (!\is_array($node)) {
            $request->setResult(null);

            return;
        }

        $request->setResult(Products::fromGraphQL(
            $node,
            $this->currency ?: (string) ($data['shop']['currencyCode'] ?? 'EUR'),
            isset($data['shop']['taxesIncluded']) ? (bool) $data['shop']['taxesIncluded'] : null,
        ));
    }

    /** @return array{0: ?string, 1: ?string} the gid, or the handle */
    public static function target(Reference $reference): array
    {
        if (!$reference->isUrl()) {
            $id = (string) $reference->id;
            if (str_starts_with($id, 'gid://shopify/Product/')) {
                return [$id, null];
            }
            if (ctype_digit($id)) {
                return [Endpoint::gid('Product', $id), null];
            }

            return str_starts_with($id, 'gid://') ? [null, null] : [null, $id];
        }
        $path = (string) $reference->path();
        if (preg_match('~/admin/products/(\d+)~', $path, $m)) {
            return [Endpoint::gid('Product', $m[1]), null];
        }
        $slug = $reference->slug();

        return [null, null !== $slug && '' !== $slug ? $slug : null];
    }
}
