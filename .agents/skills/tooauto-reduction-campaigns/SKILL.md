---
name: tooauto-reduction-campaigns
description: Maintain and extend the TooAuto Laravel reduction campaign, user reduction card, automatic subscription, Wasabi image, campaign application, and usage history flows. Use when changing reduction campaigns, card scanning, discount calculations, campaign services, or automatic card assignment in tooauto-lavage-api.
---

# TooAuto Reduction Campaigns

Read [the technical documentation](../../../docs/reduction-campaigns.md) before changing this domain. It records the implemented API contract, models, database assumptions, business rules, and known limitations.

## Preserve These Rules

- An establishment creates campaigns; it never creates user reduction cards.
- Several campaigns may be active simultaneously for the same establishment.
- A campaign is usable only when `statut = 1`, today is inside its date range, and its quantity is not exhausted.
- The API derives `promotional_price`; clients submit `normal_price`, `discount_type`, and `discount_value`.
- Accept `montant` as an API alias, but persist it as `fixed`.
- Never allow a percentage above 100 or a fixed discount above the normal price.
- Once a campaign has a usage, its discount type, discount value, and prices are immutable.
- A scanned user card must exist, be active, have started, and not be expired.
- Applying a campaign and incrementing `quantity_used` must remain in one database transaction.
- Campaign images are real multipart files stored in Wasabi. Allow JPEG, PNG, WEBP, or GIF up to 2 MiB, and return a signed `image_url`.
- For a lavage, `product_or_service` stores one or more `type_lavages.id` values as a comma-separated string. Responses must also expose the corresponding labels.
- Do not add migrations unless the user explicitly requests them; these tables are managed manually.
- Keep existing payloads backward compatible unless the user explicitly approves a contract change.

## Change Workflow

1. Inspect `ReductionCampaignController`, the two campaign models, routes, `WasabiService`, and any related card assignment code before editing.
2. Check whether a change affects both campaign discovery and campaign application. Multiple active campaigns make implicit `first()` selection especially important.
3. Keep establishment scoping (`establishment_type` and `establishment_id`) on list, active, apply, and history queries.
4. Preserve snapshot fields in `reduction_campaign_usages`; history must remain meaningful if a campaign later changes or is deleted.
5. After changes, run PHP lint on every edited PHP file, `php artisan test`, and `php artisan route:list --path=reduction-campaigns` when routes change.

## Current Design Caveats

- `verify-card` and `apply` currently select the newest eligible campaign with `first()`. Because multiple active campaigns are now allowed, add an explicit campaign selection contract before building UI that applies a specific campaign.
- Controller validation scopes lavage service IDs to the submitted lavage, but current routes do not independently verify that the authenticated principal owns the submitted establishment identifiers.
- `applied_by_id` is accepted from the request and is not currently derived from the authenticated principal.
- Product/service label resolution is implemented only for `lavage`; station and generic establishment lookup tables still need dedicated resolution when that work is requested.
- The intended domain speaks of one user card, but `ReductionCardService` currently creates one card per active `reduction_cards` configuration attached to the subscription package. Do not silently change this cardinality.
