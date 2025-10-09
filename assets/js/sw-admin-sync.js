(function () {
  "use strict";

  function rows() {
    return document.querySelectorAll('[data-order_item_id], tr.item, .wc-order-item');
  }
  function urlFromRow(r) {
    // Prefer explicit product_image_full meta
    var labels = r.querySelectorAll('dt, .wc-item-meta-label, .meta-label');
    for (var i = 0; i < labels.length; i++) {
      var t = (labels[i].textContent || '').trim().toLowerCase().replace(':', '');
      if (t === 'product_image_full') {
        var dd = labels[i].nextElementSibling; if (!dd) break;
        var a = dd.querySelector('a'); if (a && a.href) return a.href;
        var txt = (dd.textContent || '').trim(); if (/^https?:\/\//i.test(txt)) return txt;
      }
    }
    // Fallback: any uploads image URL in the row
    var a2 = r.querySelector('a[href*="/uploads/"]');
    return a2 ? a2.href : null;
  }
  function paint(r, url) {
    if (!url) return;
    var box = r.querySelector('.wc-order-item-thumbnail, .wc-order-item-thumbnail__image, td.thumb, .thumb');
    if (!box) return;
    var img = box.querySelector('img');
    if (!img) { img = document.createElement('img'); box.innerHTML = ''; box.appendChild(img); }
    img.src = url;
    img.style.cssText = 'display:block;width:48px;height:48px;object-fit:contain;border:1px solid #eee;background:#fff;';
  }
  function apply() { rows().forEach(function (r) { paint(r, urlFromRow(r)); }); }

  // Run now, then a few times to catch HPOS React mounts; keep watching mutations.
  if (document.readyState !== 'loading') apply(); else document.addEventListener('DOMContentLoaded', apply);
  var tries = 0, t = setInterval(function () { apply(); if (++tries > 30) clearInterval(t); }, 200);
  new MutationObserver(apply).observe(document.body, { childList: true, subtree: true });
})();
