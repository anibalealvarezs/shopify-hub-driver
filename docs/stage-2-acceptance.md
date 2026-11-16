# Stage 2 implementation and acceptance — completed 2026-11-13

## Implemented behavior

- `sync()` dispatches ordinary metric jobs to `syncMetrics()` and `entity=entities` to `syncEntities()`; unsupported modes fail. Legacy SDK REST methods remain available, while the driver uses GraphQL.
- Entity jobs discover shop ownership, then customers, collections (including empty collections), products/variants and collection memberships, discounts/redeem codes, orders/line items/discount applications and order/refund events. Entity queries have no date filters. Shopify scopes still bound accessible history.
- Nested connections complete before their parent snapshot is dispatched. Cancellation and provider errors propagate. Entity callbacks persist idempotently; supplied collection memberships replace old memberships.
- `transport=bulk` streams customer, product and order roots through the SDK bulk service. Nested details retain guarded pagination. This is a hybrid bulk path; it does not bulk-export every nested connection or resume an existing bulk operation after timeout.
- ShopifyQL metrics use calendar-quarter chunks, source currency and snapshot replacement. The driver declares an empty preaggregation rule set deliberately: ShopifyQL financial totals are not recalculated from orders. A 30-day default attribution window is a policy default, not a claim about Shopify attribution.
- Multi-account routing, returned-shop validation, encrypted credential-file storage and a two-worker default are implemented. See credential-storage.md for host key requirements.

## Integration boundaries

The plan's proposed `channeled_metrics_daily` table does not exist in the current worker. Daily ShopifyQL observations use the existing `metric_configs`, `metrics` and `channeled_metrics` pipeline through `MetricRequests`; no parallel driver-owned metrics table is created. Core aggregation and scheduler rollout belong to Stage 3.

Apply the coordinated worker changes, including the ProductCategory string-ID schema migration, before enabling collection sync on PostgreSQL. The migration is supplied, not applied to any deployed database.

Order events retain source order/refund IDs. A refund event records Shopify's refund object and explicitly leaves financial completion unverified; it is not proof of a successful refund transaction. Automatic discount applications retain order-scoped evidence, with no inferred discount-node match based on title.

## Verification and remaining acceptance

- Driver: 54 tests, 159 assertions with Sodium enabled, including stale credential-writer rejection.
- Worker SQLite commerce: 20 tests, 114 assertions; complete entity job replay includes categories, order and refund events.
- Worker SQLite metric persistence: 12 tests, 56 assertions.
- Development-store complete GraphQL entity dispatch: passed, 1 test / 4 assertions. Order query also passed separately.
- PostgreSQL 16.15 isolated local runtime: 6 tests / 29 assertions passed, covering Shopify entities and metrics, category ID migration, currency conversion and concurrent store-domain lock rollback/retry. Runtime lives in the ignored shared .stage2-postgres directory; no deployed database was modified.
- Bulk development-store run passed on 2026-11-13 after the operator re-enabled bulk queries: complete hybrid bulk entity dispatch, 1 test / 4 assertions, about 59 seconds. Existing client-credentials authentication remained valid; no replacement OAuth token was required. IP allowlisting and app bulk-query permission were distinct gates.
- Live independent-store routing passed on 2026-11-13: two distinct shop IDs, per-shop credentials, account-selected customer dispatch and removal of one account preserving access to the other (1 test / 17 assertions). Live records are validated by callbacks, with persistence separately tested in PostgreSQL.
- Live populated discount snapshots match source IDs, titles and redeem codes (1 test / 28 assertions).
- Nonzero ShopifyQL financial parity passed for 91 days: live source-to-driver comparison (1 test / 731 assertions), then the private source fixture persisted and replayed in real PostgreSQL with every amount and shop link verified (1 test / 1094 assertions).
- Populated live refund acceptance passed after the operator refunded a test order: source-to-driver IDs, timestamps, amount/currency and account ownership (1 test / 8 assertions); real PostgreSQL persistence and replay with no duplicate events and correct order/store relationship (1 test / 4 assertions).
- Production OAuth authorization/refresh and credential distribution require host/Facade integration. This driver does not implement a public OAuth callback server.

Stage 2 implementation and the recorded acceptance checks are complete. Automatic scheduling remains disabled until Stage 3 orchestration rollout. Deployment of the supplied migration, production configuration and Facade OAuth integration are separate rollout work; these checks do not claim production deployment or exhaustive coverage of every Shopify plan/version.
