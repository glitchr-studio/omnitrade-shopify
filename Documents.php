<?php

namespace Omnitrade\Shopify;

/** The GraphQL documents the actions send. */
final class Documents
{
    public const DRAFT_ORDER_CREATE = <<<'GRAPHQL'
        mutation DraftOrderCreate($input: DraftOrderInput!) {
          draftOrderCreate(input: $input) {
            draftOrder { id name invoiceUrl status totalPrice }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public const DRAFT_ORDER = <<<'GRAPHQL'
        query DraftOrder($id: ID!) {
          draftOrder(id: $id) {
            id name status invoiceUrl totalPrice
            order { id name displayFinancialStatus displayFulfillmentStatus }
          }
        }
        GRAPHQL;

    public const ORDER = <<<'GRAPHQL'
        query Order($id: ID!) {
          order(id: $id) {
            id name createdAt displayFinancialStatus displayFulfillmentStatus
            totalPriceSet { shopMoney { amount currencyCode } }
            totalRefundedSet { shopMoney { amount currencyCode } }
            email
            customer { displayName email }
            shippingAddress { address1 address2 zip city countryCodeV2 phone }
            lineItems(first: 100) { nodes { title quantity sku variant { id } originalUnitPriceSet { shopMoney { amount currencyCode } } } }
          }
        }
        GRAPHQL;

    /**
     * What the catalogue reads of a product: its texts, vendor, type,
     * collections, options, metafields, media and variants (100 at most,
     * Shopify's former limit - a product with more is read in part).
     */
    public const PRODUCT_FIELDS = <<<'GRAPHQL'
        fragment ProductFields on Product {
          id handle title descriptionHtml vendor productType status tags updatedAt onlineStoreUrl
          options { name optionValues { name } }
          collections(first: 20) { nodes { title } }
          metafields(first: 20) { nodes { namespace key value } }
          media(first: 20) {
            nodes {
              id alt mediaContentType
              ... on MediaImage { image { url width height } }
              ... on Video { sources { url width height } }
              ... on ExternalVideo { originUrl }
            }
          }
          variants(first: 100) {
            nodes {
              id title sku barcode availableForSale inventoryQuantity price compareAtPrice
              selectedOptions { name value }
              image { url altText width height }
              inventoryItem { id tracked measurement { weight { unit value } } }
            }
          }
        }
        GRAPHQL;

    /**
     * A page of products, the most recently updated last. The shop's currency
     * and tax setting come along: the prices are in it. first: 50 by default
     * rather than the permitted 250 - the query is costed by the nodes it can
     * return (products x variants), and the cost bucket is waited for.
     */
    public const PRODUCTS = <<<'GRAPHQL'
        query Products($first: Int!, $after: String, $query: String) {
          shop { currencyCode taxesIncluded }
          products(first: $first, after: $after, query: $query, sortKey: UPDATED_AT) {
            pageInfo { hasNextPage endCursor }
            nodes { ...ProductFields }
          }
        }
        GRAPHQL."\n".self::PRODUCT_FIELDS;

    public const PRODUCT = <<<'GRAPHQL'
        query Product($id: ID!) {
          shop { currencyCode taxesIncluded }
          product(id: $id) { ...ProductFields }
        }
        GRAPHQL."\n".self::PRODUCT_FIELDS;

    /** The shop's currency and tax setting, for a webhook's prices (they come bare). */
    public const SHOP = <<<'GRAPHQL'
        query Shop { shop { currencyCode taxesIncluded } }
        GRAPHQL;

    /** The variants' inventory, by their gids. */
    public const VARIANT_STOCKS = <<<'GRAPHQL'
        query VariantStocks($ids: [ID!]!) {
          nodes(ids: $ids) {
            ... on ProductVariant { id sku inventoryQuantity inventoryItem { id tracked } }
          }
        }
        GRAPHQL;

    /** Every variant's inventory, page by page. */
    public const STOCKS = <<<'GRAPHQL'
        query Stocks($first: Int!, $after: String) {
          productVariants(first: $first, after: $after) {
            pageInfo { hasNextPage endCursor }
            nodes { id sku inventoryQuantity inventoryItem { id tracked } }
          }
        }
        GRAPHQL;

    /** The custom attribute both sides correlate on: the merchant's reference on the draft order. */
    public const REFERENCE_KEY = 'omnitrade_reference';
}
