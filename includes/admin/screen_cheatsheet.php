<?php
defined('ABSPATH') || exit;

function soundwave_render_cheatsheet_screen() { ?>
<div class="wrap">
  <h1>Soundwave — Quick Start (for Affiliate Stores)</h1>
  <p><strong>What Soundwave does:</strong> When your customer buys a shirt, Soundwave sends the order to our factory so we can order blanks, print, and ship on your behalf.</p>
  <h2>1) One-time setup</h2>
  <ul>
    <li>WooCommerce is active.</li>
    <li>Shipping calculates from Florida to the customer (UPS).</li>
    <li>Customer email & phone are collected at checkout.</li>
  </ul>
  <h2>2) For every product <em>variation</em></h2>
  <p>We always send the variation the customer chose. Please set these on the variation.</p>
  <ul>
    <li><strong>Variation Image</strong> — the visual we’ll show to production.</li>
    <li><strong>Attributes (required):</strong> Quality (e.g., SanMar(PC43)), Color, Size, Print Location.</li>
    <li><strong>original-art (required):</strong> URL to print-ready vector (PDF/SVG/EPS/AI or high-res PNG).</li>
    <li><strong>rendered-art (optional):</strong> URL to a rendered/mockup image.</li>
  </ul>
  <h2>3) After an order comes in</h2>
  <ul>
    <li>Go to <em>Soundwave → Manual Order Sync</em>.</li>
    <li>⏳ Not Synced → click <strong>Sync</strong>.</li>
    <li>✅ Synced → done (button disabled to prevent duplicates).</li>
    <li>❌ Failed → click <strong>View</strong> for the reason, fix the product/variation, then <strong>Retry</strong>.</li>
  </ul>
  <h2>4) What we send to the factory</h2>
  <ul>
    <li>Customer & shipping exactly as entered (UPS).</li>
    <li>Line items with name, quantity, price, all attributes, variation image, and artwork links.</li>
    <li>Discounts & shipping charges exactly as in your store.</li>
  </ul>
  <h2>5) Checklist before you publish</h2>
  <ul>
    <li>Variation image set.</li>
    <li>Attributes: Quality, Color, Size, Print Location.</li>
    <li>original-art URL present (required).</li>
    <li>rendered-art URL (optional).</li>
    <li>Test order → Soundwave → Sync → confirm ✅ Synced.</li>
  </ul>
  <h2>6) If you get stuck</h2>
  <p>Open <em>Soundwave → Manual Order Sync</em>, click <strong>View</strong> on the order, and read the reason shown. It tells you exactly which field is missing. Fix it, then click <strong>Retry</strong>.</p>
</div>
<?php }
