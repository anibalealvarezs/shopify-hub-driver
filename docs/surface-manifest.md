# Shopify driver surface and status

## Current implementation

- Legacy REST synchronization: orders, products, customers, price rules and collections. This path predates the current commerce processor contract and is not verified end to end.
- GraphQL normalization foundation: shop account, shop store and customers are converted with store ownership and optional email-based identity; an explicit shop/customer GraphQL sync method dispatches account, store and customer records, but is not scheduled as a complete entity sync.
- Configuration: flat and `channels.shopify` structures preserve unrelated settings and unpack explicit asset selections.
- Canonical dictionary: existing conversion and ROAS aliases are exposed for read-time aggregation; planned ShopifyQL sales metric mapping remains pending.

## Planned GraphQL surface

- Collections and order events: not yet implemented through GraphQL sync. Explicit order sync fetches accessible orders and line items but is not scheduled or live-validated with actual orders.
- Native ShopifyQL daily sales: explicit `shop_daily_sales` mode converts orders, gross sales, net sales and total sales; persistence and scheduling are not yet validated.
- Bulk historical and incremental ingestion: not yet wired to the driver.
- Multi-store authentication, store ownership and commerce persistence: not yet integrated.
- Metric tiers, supported breakdowns, conversion mappings and live end-to-end acceptance: pending verification.

- Source-only customers without email require the updated orchestrator; deployed schema and read-path compatibility remain to be verified.
- Explicit GraphQL product sync paginates products and each product's variants; it is not yet the scheduled full sync or bulk historical path.
- The worker can explicitly dispatch `shop_customers`, `shop_products`, and `shop_orders`; complete `entities` jobs fail until all required entity paths exist. Automatic scheduling remains disabled.
