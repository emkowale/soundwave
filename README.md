# Soundwave — Single-File Source of Truth (v1.4.19)

**Purpose**  
Push WooCommerce orders from affiliate/source sites to **thebeartraxs.com** (“hub”) so the hub can fulfill them.

## Canonical behavior
- **Orders column:** Unsynced → **Sync** button; Synced → green text **Synced** (not a button).  
- **Real‑time reconciliation:** On Orders list, only visible rows are polled on load and ~25s. If the hub returns **404** or `trash`, `_soundwave_synced` and `_soundwave_hub_id` are cleared and the UI flips back to **Sync**.
- **Validation gate (per line item):** Required fields:
  - Attributes: **Color**, **Size**, **Print Location**, **Quality**
  - Custom fields: **company-name**, **original-art**, **production**
  - **Image** required (pulled from product, variation, item meta, or featured image)
  If any are missing, sync is blocked; a private order note lists exactly what is missing per line item and the Orders list shows a **Fix errors** link to the order.
- **Payload:** Each source line item -> one hub item with `product_id=40158`. Meta **keys and order**:
  1. **Company Name** (site title)
  2. **SKU** (affiliate/source SKU only — never the hub placeholder)
  3. **Color**
  4. **Size**
  5. **Print Location**
  6. **Quality**
  7. **Product Image** (absolute URL)
  8. **Original Art** (absolute URL)
  9. **Production** (e.g., “Screen Print”, “DF”, “Embroidery”)

> Note: Earlier keys `product_image_full` and `original-art` are still accepted as inputs on the source, but the hub **receives** the friendly keys **Product Image** and **Original Art** in the correct display order.

- **Duplicate guard:** `_soundwave_synced = "1"` prevents resending.
- **Success note:** `Soundwave: synced to hub (hub_id XXXX)`
- **Failure note:** Includes HTTP code/JSON details and the **missing-by-line-item** list.

## Options & meta
- Option: `soundwave_settings` → `{endpoint, consumer_key, consumer_secret}`
- Order meta:
  - `_soundwave_synced` = "1" when synced
  - `_soundwave_hub_id` = hub order id
  - `_soundwave_last_response_code`, `_soundwave_last_response_body`, `_soundwave_last_error`

## Updater & Release
- Built-in updater checks GitHub releases at `emkowale/soundwave`.
- `release.sh {major|minor|patch}` bumps version and produces `soundwave-vX.Y.Z.zip` with top-level `soundwave/` folder.

## Hub helper (optional but recommended)
To hide the hub placeholder SKU (“thebeartraxs-40158-0”) in the hub’s **admin order item** display, drop `docs/hub-mu-plugin-hide-placeholder-sku.php` into `wp-content/mu-plugins/` on the **hub** site.

