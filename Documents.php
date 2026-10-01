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

    /** The custom attribute both sides correlate on: the merchant's reference on the draft order. */
    public const REFERENCE_KEY = 'omnitrade_reference';
}
