Soundwave — Order Relay (Affiliate ➜ thebeartraxs.com)

Purpose: Push WooCommerce orders from affiliate sites (“sources”) to thebeartraxs.com (“hub”) with the exact item-level metadata required for production and WooCommerce Analytics—while keeping the codebase modular and predictable (≤100 lines per file).

High-Level Flow

Source (affiliate)

Detect “new/changed” orders.

Build the data contract (below) per order and per line item.

POST to hub (Woo REST, authenticated).

Mark as synced (idempotency), retry on failure with bounded backoff.

Hub (thebeartraxs.com)

Create/update the order using the original created date.

Persist line-item meta exactly as sent (no renaming on save).

Admin/Email display helpers enforce meta order and render thumbnails from product_image_full.

Data Contract (Per Line Item)

Every line item must include these visible fields (in addition to Woo standard fields):

Color (string)

Size (string)

Print Location (string; e.g., “Front”, “Back”, “Left Chest”)
Hub display maps common alias keys to this label.

Quality (string)

company-name (string) — the affiliate site’s WordPress Site Title

product_image_full (string; URL to image; direct URL preferred; link OK—hub extracts href)

original-art (string; newline-separated list of URLs; hub displays all URLs)

Display Labels in Hub Admin/Emails

company-name → Company Name

product_image_full → Product Image

original-art → Original Art (each URL shown)

Multi-Item Guarantee

Multi-line-item orders carry the full meta set per line. No values are stored only at the order level.

Meta Display Order (Hub)

When viewing an order item on the hub, meta must appear in this order, then all other meta:

Color

Size

Print Location

Quality

Company Name

Product Image

Original Art

(all other meta unchanged)

Thumbnails (Hub)

Admin & emails show the thumbnail from product_image_full.

If product_image_full is stored as a clickable link (<a href="…">), hub extracts the href.

If missing/invalid, fallback to the product’s featured image.

Single authority: one hub display helper controls thumbnails to prevent “code stepping on code.”

Created Date & WooCommerce Analytics (including Stock)

Hub order must use the original source created timestamp:

Set both date_created and date_created_gmt to the source’s order time.

Move the order to a stock-reducing status (e.g., processing or completed).

Product mapping is required so stock analytics update correctly:

Each line item must include sku (prefer variation SKU).

Hub resolves SKU → product_id / variation_id and sets them on the line item before save.

Products on hub must have “Manage stock” enabled where relevant.

Order-level audit meta (hub): _sw_source_site, _sw_source_order_id, _sw_original_created (ISO8601), optional _sw_idempotency_key.

Idempotency & Duplicates

Each source order syncs exactly once.

Idempotency key (order-level):
idempotency_key = {source_site_slug}:{source_order_id} (e.g., vicki-biz:38492)

Do NOT use SKU for idempotency (SKU is line-item scope and repeats across orders).

Hub behavior:

If _sw_idempotency_key already exists, update/append items safely (idempotent re-push) and do not create a duplicate order.

Otherwise, create the order once and store _sw_idempotency_key, _sw_source_site, _sw_source_order_id, _sw_sync_status.

Repository Principles

≤ 100 lines per file. Split before you exceed ~100 lines to keep diffs small and reviews fast.

Single place per concern: one idempotency gate, one thumbnail filter, one meta-order filter.

Boundaries:

Source (Soundwave plugin): order detection, mapping, auth, POST, retries, data contract integrity.

Hub (thebeartraxs.com): order creation with original dates, SKU→product mapping, stock-reducing status, and display rules (meta order + thumbnails).

No mu-plugins on hub per team preference; hub helpers live in the active child theme or designated include (still ≤100 lines).

Acceptance Tests (5-Minute Checklist)

A. Single-item order

 Line item shows the seven required fields with the labels above, in order.

 Thumbnail shows from Product Image or falls back to product image.

 Hub order’s Created equals the source order’s original time; status reduces stock; Analytics → Stock reflects the item.

B. Multi-item order

 Each line item has its own full meta set and its own thumbnail.

 All items resolve SKUs to hub products/variations; stock reduces per item.

C. Idempotency

 Re-push with the same {site}:{order_id} updates safely; no duplicate orders.

D. Labels & aliasing

 Attribute-style keys for Print Location (e.g., pa_print-location, print_location) display as Print Location and sit right under Size.

Troubleshooting Quick Checks

Missing thumbnail → Confirm product_image_full is a URL or a link with href.

Print Location in wrong place → Confirm hub meta-order helper is active; check raw keys against alias list.

Analytics/Stock off → Verify date_created is the original time, item is attached to a real product/variation via SKU, and status reduces stock.

Duplicate hub orders → Confirm idempotency key handling and that _sw_idempotency_key is stored/checked.

Security

Use Woo REST authentication (server-to-server).

Keep keys out of the repo; load from constants/env.

Send only what’s required.

Change Policy (to avoid complexity creep)

No new features without updating this README’s Data Contract, Acceptance Tests, and Boundaries first.

If a change can’t be explained here in ~3 lines, split it or rethink it.

Keep each file ≤100 lines; if you must exceed, split before committing.

Known Aliases (Hub Display)

Print Location visible label is applied if raw keys are (case-insensitive):

print_location, print-location

pa_print-location, attribute_pa_print-location

pa_print_location, attribute_pa_print_location

Open Questions / To Confirm

Do we also want order-level “Company Name” for filtering/reporting (in addition to line items)?

Retry cadence and max attempts on the source (document defaults).

Any additional non-required meta we consistently pass and should document?

TL;DR

Soundwave sends 7 required line-item fields (Color, Size, Print Location, Quality, company-name, product_image_full, original-art).

company-name is the affiliate’s WordPress Site Title; labels in hub are Company Name, Product Image, and Original Art.

Original Art accepts newline-separated URLs only; hub displays all.

Hub preserves original created date, maps SKU → product/variation, reduces stock, and shows thumbnails from Product Image.

Meta order is deterministic; files remain ≤100 lines; each side owns its layer.

Ship only after Acceptance Tests pass.
