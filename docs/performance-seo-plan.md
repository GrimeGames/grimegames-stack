# GrimeGames — Performance & SEO Improvement Plan

**Target:** 90+ PageSpeed mobile on all pages, industry-leading SEO scores
**Context:** £500k/year net profit target requires maximum organic traffic and conversion

---

## Current State (2026-04-07)

| Page | Mobile Perf | SEO | FCP | LCP | TBT | CLS |
|------|-------------|-----|-----|-----|-----|-----|
| Homepage | 60-84* | 85 | 0.5s | 2.6s | 10ms | 0.01 |
| RC5 (619 cards) | 62 | 85 | 0.5s | 2.3s | 540ms | 0 |
| Singles (837 cards) | 31 | 85 | 4.4s | 16.7s | 1,590ms | 0 |

*Score varies between runs — PageSpeed mobile simulation is inconsistent

---

## PERFORMANCE FIXES (ordered by impact)

### 1. CRITICAL: Reduce HTML document size on product pages

**Problem:** Singles delivers 12.1MB of raw HTML (370KB compressed). RC5 delivers 9.2MB (306KB compressed). Every product card, its image URL, price, stock, title, and all inline CSS/JS is embedded in one monolithic document. Even with LiteSpeed cache serving this fast, the browser still has to parse and render it all.

**Fix — Server-side pagination with client-side hydration:**
Instead of rendering all 837 products in the HTML, render only the first 48 products server-side and deliver the rest as a JSON payload that the client renders on demand.

```
Current flow:
  Server renders 837 <li> elements → 12MB HTML → browser parses all 837

Proposed flow:
  Server renders 48 <li> elements + JSON blob with 789 remaining → ~2MB HTML
  Client JS renders next batch from JSON as user scrolls
```

This would cut the HTML payload by ~80%, reduce parse time proportionally, and drop TBT from 1,590ms to ~200ms.

**Effort:** Medium (2-3 hours). Requires modifying the Elementor HTML widget to include a `<script type="application/json">` block with product data, and updating the progressive render JS to create DOM nodes from JSON rather than showing/hiding existing ones.

### 2. CRITICAL: Extract inline CSS/JS into external cached files

**Problem:** 178KB of CSS and 226KB of JS are embedded inline in the Singles HTML widget. This re-downloads with every page load — browsers can't cache inline content separately. The same CSS (dark theme, product grid, rarity glows, crown animation) is duplicated across Homepage, RC5, and Singles.

**Fix:**
- Extract shared CSS into `/wp-content/themes/astra-child/gg-shared.css` (~100KB)
- Extract page-specific CSS into `/wp-content/themes/astra-child/gg-singles.css` (~78KB)
- Extract filter/progressive-render JS into `/wp-content/themes/astra-child/gg-product-filters.js` (~226KB)
- Load via `wp_enqueue_style()` and `wp_enqueue_script()` with versioning

After first visit, all CSS/JS is cached locally. Subsequent page loads only transfer the HTML (which would be much smaller without inline styles).

**Effort:** Medium (2-3 hours). Extract, test, enqueue.

### 3. HIGH: Eliminate render-blocking resources

**Problem:** 26 CSS files load in the `<head>` before the page can render. Many are irrelevant to the page being viewed (Stripe CSS, PayPal CSS, WPForms admin bar, etc.). The `gg-performance.php` plugin already strips some for non-admin users, but several remain.

**Fix — extend `gg-performance.php`:**
- Defer non-critical CSS using `media="print" onload="this.media='all'"` pattern
- Keep only critical-path CSS inline (above-the-fold styles, ~15KB)
- Move everything else to async loading
- Specifically target: Elementor icons CSS (only needed if icons are above fold), WooCommerce grid CSS (only on product pages), Astra compatibility CSS

**Effort:** Low-medium (1-2 hours).

### 4. HIGH: Cloudflare Rocket Loader is interfering with script execution

**Problem:** Cloudflare Rocket Loader (`/cdn-cgi/scripts/7d0fa10a/cloudflare-static/rocket-loader.min.js`) intercepts all `<script>` tags and defers them. This can cause the filter JS and progressive render to execute later than expected, increasing perceived load time and TBT. It also conflicts with inline scripts in the Elementor widgets.

**Fix:** Disable Rocket Loader in Cloudflare dashboard (Speed → Optimization → Rocket Loader → OFF). LiteSpeed Cache handles caching and optimisation already — Rocket Loader on top of it causes more problems than it solves.

**Effort:** 2 minutes.

### 5. MEDIUM: Image optimisation not fully working

**Problem:** Product images are loading as JPG/PNG despite the AVIF converter having processed 81% of images. The `.htaccess` AVIF rewrite may not be matching WooCommerce thumbnail paths (which include `-300x300` size suffixes).

**Fix:**
- Verify `.htaccess` AVIF rewrite rules match thumbnail paths
- Ensure AVIF versions exist for all product thumbnail sizes (not just originals)
- Add WebP fallback for browsers that don't support AVIF
- Set explicit `width` and `height` attributes on all product images (prevents CLS)

**Effort:** Medium (1-2 hours to investigate and fix).

### 6. MEDIUM: Reduce third-party impact

**Problem:** Facebook Pixel, Google Analytics (via Site Kit gtag), and Cloudflare scripts all add to TBT. For mobile visitors on simulated slow devices, third-party JS execution is a significant chunk of the blocking time.

**Fix:**
- Delay Facebook Pixel by 3 seconds (fires after page is interactive)
- Ensure gtag.js loads with `async` attribute (it should already via Site Kit)
- Consider using Cloudflare Zaraz for tag management (moves all third-party scripts to Cloudflare's edge, zero client-side JS)

**Effort:** Low (30 min for delayed pixel, 1 hour for Zaraz migration).

### 7. LOW: Font loading optimisation

**Problem:** Google Fonts CSS is render-blocking (`fonts.googleapis.com/css?family=Google+Sans+Text...`). The font is loaded synchronously, delaying FCP.

**Fix:**
- Preload the font files directly: `<link rel="preload" href="..." as="font" crossorigin>`
- Use `font-display: swap` (already set via `display=swap` in the URL — good)
- Consider self-hosting the Google Fonts files to eliminate the DNS lookup to fonts.googleapis.com

**Effort:** Low (30 min).

---

## SEO FIXES (to push from 85 to 95+)

### 1. CRITICAL: Meta descriptions on product pages

**Problem:** PageSpeed SEO score of 85 suggests meta description issues. Product pages likely have generic or missing meta descriptions. With 600+ products, each needs a unique, keyword-rich meta description.

**Fix:**
- Use Yoast SEO (already installed as `wordpress-seo`) to generate templated meta descriptions
- Template: `Buy {product_name} ({rarity}) from GrimeGames. UK seller, fast shipping, competitive prices. Yu-Gi-Oh! TCG singles.`
- Set this as the Yoast SEO title/description template for products

**Effort:** Low (15 min in Yoast settings).

### 2. CRITICAL: Structured data / Schema markup

**Problem:** Product pages should have Product schema with price, availability, and review data. This enables rich snippets in Google (price, stock status, star ratings shown directly in search results). Rich snippets significantly improve click-through rates.

**Fix:**
- WooCommerce + Yoast should auto-generate Product schema
- Verify with Google's Rich Results Test: https://search.google.com/test/rich-results
- Ensure each product has: name, price, currency (GBP), availability (InStock/OutOfStock), image, brand ("Yu-Gi-Oh!"), SKU
- Add aggregate review schema if you collect reviews

**Effort:** Low (30 min to verify and fix gaps).

### 3. HIGH: Internal linking structure

**Problem:** Product pages link to the product but not back to category pages, related products, or set pages. Strong internal linking helps Google understand site structure and distributes page authority.

**Fix:**
- Add breadcrumbs (Yoast has built-in breadcrumb support — enable in Yoast → Search Appearance → Breadcrumbs)
- Add "Related cards from this set" section on product pages
- Add "You may also like" section with same-rarity cards
- Ensure category pages (RC5, Singles, etc.) are linked from the homepage and main navigation

**Effort:** Medium (1-2 hours).

### 4. HIGH: Page titles and H1 structure

**Problem:** Page templates use custom HTML widgets which may not have proper `<h1>` tags. Google expects one `<h1>` per page matching the topic.

**Fix:**
- Homepage: `<h1>` should be "Yu-Gi-Oh! TCG Singles UK — GrimeGames"
- RC5 page: `<h1>` should be "Rarity Collection 5 — Yu-Gi-Oh! Singles"
- Singles page: `<h1>` should be "Yu-Gi-Oh! Singles — Buy Individual Cards UK"
- Each product page: `<h1>` should be the product name (WooCommerce handles this natively)

**Effort:** Low (30 min to check and fix H1s in templates).

### 5. HIGH: Mobile usability

**Problem:** At score 85, there may be tap target sizing issues or viewport configuration problems on mobile.

**Fix:**
- Ensure all buttons/links have minimum 48x48px tap targets
- Check that filter buttons and "Add to Cart" buttons are adequately sized on mobile
- Verify `<meta name="viewport" content="width=device-width, initial-scale=1">` is present
- Test with Google's Mobile-Friendly Test

**Effort:** Low (30 min).

### 6. MEDIUM: XML Sitemap and robots.txt

**Problem:** Need to verify sitemap includes all product pages and is submitted to Google Search Console.

**Fix:**
- Check Yoast generates sitemap at `/sitemap_index.xml`
- Verify all product categories and individual products are included
- Submit sitemap to Google Search Console
- Ensure `robots.txt` allows crawling of product pages and images

**Effort:** Low (15 min).

### 7. MEDIUM: Page speed as a ranking signal

**Problem:** Google explicitly uses Core Web Vitals (LCP, FID/INP, CLS) as ranking signals. Poor mobile performance = lower rankings.

**Fix:** This is addressed by all the performance fixes above. Getting LCP under 2.5s and TBT under 200ms on mobile will satisfy Google's "Good" threshold.

---

## PRIORITY ORDER (what to do first)

1. **Disable Cloudflare Rocket Loader** — 2 minutes, immediate improvement
2. **Apply Singles progressive render in Elementor** — 15 minutes, massive TBT reduction
3. **Yoast SEO templates for meta descriptions** — 15 minutes, SEO score jump
4. **Enable Yoast breadcrumbs** — 5 minutes, internal linking + SEO
5. **Verify structured data** — 30 minutes, enables rich snippets
6. **Extract inline CSS/JS to external files** — 2-3 hours, repeat-visit speed boost
7. **Server-side pagination with JSON hydration** — 2-3 hours, fundamental architecture improvement
8. **Delayed Facebook Pixel** — 30 minutes, TBT reduction
9. **Fix AVIF serving** — 1-2 hours, image transfer reduction
10. **Self-host Google Fonts** — 30 minutes, FCP improvement

---

## TARGET METRICS AFTER ALL FIXES

| Page | Mobile Perf | SEO | FCP | LCP | TBT |
|------|-------------|-----|-----|-----|-----|
| Homepage | 90+ | 95+ | 0.3s | 1.5s | <50ms |
| RC5 | 80+ | 95+ | 0.3s | 1.8s | <200ms |
| Singles | 70+ | 95+ | 0.5s | 2.0s | <300ms |

Note: Singles will likely max out at 70-80 on mobile due to the fundamental constraint of 837 products in one page. To reach 90+, the JSON hydration approach (fix #1) is required.
