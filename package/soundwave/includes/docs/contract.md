# Soundwave Data Contract (v1.1.10)

Affiliate → Hub (thebeartraxs.com)

## Order status
- status: processing
- set_paid: true

## Order meta (commissions)
- _order_origin (required)
- _origin_order_id (required)
- _origin_customer (required)

## Line items (custom lines)
- name, quantity, total
- meta:
  - Quality, Color, Size, Print Location (required)
  - variation_image_url (preferred: variation image)
  - original-art (required), rendered-art (optional)
  - sw_origin_ref = origin–orderId–email

## Shipping & coupons
- shipping_lines and coupon_lines are passed exactly as sent from affiliate.
