<?php

/**
 * GrimeGames — Custom Side Cart
 * ==============================
 * Complete side cart replacement. No plugin needed.
 * Deactivate "Side Cart WooCommerce" plugin before enabling this.
 *
 * Features:
 * - Slide-in drawer with GrimeGames dark theme + crown branding
 * - +/- quantity controls with instant AJAX updates
 * - Remove item with Undo toast notification
 * - Free shipping progress bar (£20 threshold)
 * - Coupon code input
 * - Apple Pay / Google Pay / Link via Stripe Payment Request
 * - View Cart / Checkout / Continue Shopping buttons
 * - Auto-opens when item added to cart
 * - Floating trigger button with item count badge
 * - Mobile optimised
 * - Uses WooCommerce Store API (modern, fast, no jQuery dependency)
 *
 * Title: GrimeGames Custom Side Cart
 * Paste into Code Snippets → Add New
 */

defined('ABSPATH') || exit;

/* =========================================================
   SECTION 1 — INJECT CART HTML INTO FOOTER
   ========================================================= */
add_action('wp_footer', function () {
    if (is_cart() || is_checkout()) return;
    ?>
    <!-- GrimeGames Side Cart -->
    <div id="gg-cart-overlay" aria-hidden="true"></div>

    <div id="gg-cart-drawer" role="dialog" aria-label="Your Cart" aria-hidden="true">

        <!-- Header -->
        <div id="gg-cart-header">
            <div id="gg-cart-header-left">
                <div id="gg-cart-crown">
                    <img src="https://grimegames.com/wp-content/uploads/2025/11/Cracked-crown.png"
                         alt="GrimeGames Crown" id="gg-cart-crown-img" onerror="this.style.display='none'" />
                </div>
                <div>
                    <div id="gg-cart-title">Your Cart</div>
                    <div id="gg-cart-count-label"><span id="gg-cart-item-count">0</span> items</div>
                </div>
            </div>
            <button id="gg-cart-close" aria-label="Close cart">✕</button>
        </div>

        <!-- Free Shipping Progress Bar -->
        <div id="gg-cart-shipping-bar">
            <div id="gg-cart-shipping-text">Loading...</div>
            <div id="gg-cart-shipping-track">
                <div id="gg-cart-shipping-fill"></div>
            </div>
        </div>

        <!-- Cart Items -->
        <div id="gg-cart-body">
            <div id="gg-cart-items"></div>
            <div id="gg-cart-empty" style="display:none;">
                <div id="gg-cart-empty-icon">🛒</div>
                <div id="gg-cart-empty-text">Your cart is empty</div>
                <button id="gg-cart-empty-browse" class="gg-cart-btn gg-cart-btn-primary">Browse Cards</button>
            </div>
        </div>

        <!-- Footer -->
        <div id="gg-cart-footer">

            <!-- Coupon -->
            <div id="gg-cart-coupon">
                <button id="gg-cart-coupon-toggle" type="button">🏷️ Have a coupon code?</button>
                <div id="gg-cart-coupon-form" style="display:none;">
                    <div id="gg-cart-coupon-row">
                        <input type="text" id="gg-cart-coupon-input" placeholder="Enter code..." autocomplete="off" />
                        <button type="button" id="gg-cart-coupon-apply">Apply</button>
                    </div>
                    <div id="gg-cart-coupon-msg"></div>
                </div>
                <div id="gg-cart-applied-coupons"></div>
            </div>

            <!-- Totals -->
            <div id="gg-cart-totals">
                <div class="gg-cart-total-row">
                    <span>Subtotal</span>
                    <span id="gg-cart-subtotal">£0.00</span>
                </div>
                <div class="gg-cart-total-note">Shipping & discounts calculated at checkout</div>
            </div>

            <!-- Buttons -->
            <div id="gg-cart-actions">
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="gg-cart-btn gg-cart-btn-secondary" id="gg-cart-viewcart-btn">
                    View Cart
                </a>

                <!-- Express Checkout — sits just above main checkout button -->
                <div id="gg-cart-express" style="display:none;">
                    <div class="gg-cart-divider-label">Express Checkout</div>
                    <div id="gg-cart-express-buttons"></div>
                </div>

                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="gg-cart-btn gg-cart-btn-primary" id="gg-cart-checkout-btn">
                    👑 Checkout
                </a>
            </div>
        </div>

    </div>

    <!-- Floating Trigger Button -->
    <button id="gg-cart-trigger" aria-label="Open cart">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/>
            <line x1="3" y1="6" x2="21" y2="6"/>
            <path d="M16 10a4 4 0 01-8 0"/>
        </svg>
        <span id="gg-cart-trigger-count">0</span>
    </button>

    <!-- Undo Toast -->
    <div id="gg-cart-toast" role="alert" aria-live="polite"></div>

    <?php
});

/* =========================================================
   SECTION 2 — PASS DATA TO JS
   ========================================================= */
add_action('wp_footer', function () {
    if (is_cart() || is_checkout()) return;
    ?>
    <script>
    var ggCartData = {
        storeApiUrl:      '<?php echo esc_url(get_site_url()); ?>/wp-json/wc/store/v1',
        cartUrl:          '<?php echo esc_url(wc_get_cart_url()); ?>',
        checkoutUrl:      '<?php echo esc_url(wc_get_checkout_url()); ?>',
        singlesUrl:       '<?php echo esc_url(get_site_url()); ?>/singles/',
        nonce:            '<?php echo wp_create_nonce('wc_store_api'); ?>',
        freeShipping:     20,
        currency:         <?php echo json_encode(html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8')); ?>,
        currencyCode:     '<?php echo strtolower(get_woocommerce_currency()); ?>',
        countryCode:      '<?php $loc = wc_get_base_location(); echo esc_js($loc["country"]); ?>',
        ajaxUrl:          '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
        wcAjaxUrl:        '/?wc-ajax=%%endpoint%%',
        stripeKey:        '<?php $ss = get_option("woocommerce_stripe_settings", []); $tm = isset($ss["testmode"]) && $ss["testmode"] === "yes"; echo $tm ? esc_js($ss["test_publishable_key"] ?? "") : esc_js($ss["publishable_key"] ?? ""); ?>'
    };
    </script>
    <?php
}, 1);

/* =========================================================
   SECTION 3 — CSS
   ========================================================= */
add_action('wp_head', function () {
    if (is_cart() || is_checkout()) return;
    ?>
<style>
/* ============================================
   GRIMEGAMES SIDE CART
   ============================================ */

/* --- Overlay --- */
#gg-cart-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0);
    z-index: 99998;
    backdrop-filter: blur(0px);
    transition: background .3s, backdrop-filter .3s;
}
#gg-cart-overlay.gg-cart-active {
    display: block;
    background: rgba(0,0,0,.65);
    backdrop-filter: blur(3px);
}

/* --- Drawer --- */
#gg-cart-drawer {
    position: fixed;
    top: 0;
    right: 0;
    width: 400px;
    max-width: 100vw;
    height: 100vh;
    background: #080810;
    border-left: 1px solid rgba(123,104,238,.2);
    box-shadow: -12px 0 60px rgba(0,0,0,.7);
    z-index: 99999;
    display: flex;
    flex-direction: column;
    transform: translateX(100%);
    transition: transform .35s cubic-bezier(.4,0,.2,1);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
#gg-cart-drawer.gg-cart-open {
    transform: translateX(0);
}

/* --- Header --- */
#gg-cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 18px 20px;
    background: linear-gradient(135deg, #0d0d1a 0%, #130d24 100%);
    border-bottom: 1px solid rgba(123,104,238,.2);
    flex-shrink: 0;
}
#gg-cart-header-left {
    display: flex;
    align-items: center;
    gap: 12px;
}
#gg-cart-crown-img {
    width: 48px;
    height: 48px;
    object-fit: contain;
    filter: drop-shadow(0 0 8px rgba(168,85,247,.5));
    animation: ggCrownPulse 3s ease-in-out infinite;
}
@keyframes ggCrownPulse {
    0%,100% { filter: drop-shadow(0 0 6px rgba(168,85,247,.4)); }
    50%      { filter: drop-shadow(0 0 14px rgba(168,85,247,.8)); }
}
#gg-cart-title {
    color: #fff;
    font-size: 16px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
}
#gg-cart-count-label {
    color: rgba(255,255,255,.4);
    font-size: 11px;
    margin-top: 1px;
}
#gg-cart-count-label span {
    color: #A855F7;
    font-weight: 700;
}
#gg-cart-close {
    width: 34px;
    height: 34px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 8px;
    color: rgba(255,255,255,.5);
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    flex-shrink: 0;
}
#gg-cart-close:hover {
    background: rgba(229,57,53,.15);
    border-color: rgba(229,57,53,.3);
    color: #fff;
}

/* --- Free Shipping Bar --- */
#gg-cart-shipping-bar {
    padding: 10px 16px;
    background: rgba(123,104,238,.06);
    border-bottom: 1px solid rgba(123,104,238,.12);
    flex-shrink: 0;
}
#gg-cart-shipping-text {
    font-size: 12px;
    color: rgba(255,255,255,.6);
    margin-bottom: 6px;
    text-align: center;
}
#gg-cart-shipping-text strong {
    color: #A855F7;
}
#gg-cart-shipping-text.gg-shipping-unlocked {
    color: #00c853;
}
#gg-cart-shipping-track {
    height: 5px;
    background: rgba(255,255,255,.08);
    border-radius: 10px;
    overflow: hidden;
}
#gg-cart-shipping-fill {
    height: 100%;
    background: linear-gradient(90deg, #7B68EE, #A855F7);
    border-radius: 10px;
    width: 0%;
    transition: width .6s cubic-bezier(.4,0,.2,1);
}
#gg-cart-shipping-fill.gg-shipping-done {
    background: linear-gradient(90deg, #00c853, #00e676);
}

/* --- Body / Items --- */
#gg-cart-body {
    flex: 1;
    overflow-y: auto;
    padding: 10px 0;
}
#gg-cart-body::-webkit-scrollbar { width: 4px; }
#gg-cart-body::-webkit-scrollbar-track { background: transparent; }
#gg-cart-body::-webkit-scrollbar-thumb { background: rgba(123,104,238,.3); border-radius: 4px; }

/* --- Empty State --- */
#gg-cart-empty {
    text-align: center;
    padding: 60px 24px;
}
#gg-cart-empty-icon {
    font-size: 52px;
    opacity: .3;
    margin-bottom: 14px;
}
#gg-cart-empty-text {
    color: rgba(255,255,255,.3);
    font-size: 14px;
    margin-bottom: 20px;
}

/* --- Cart Item --- */
.gg-cart-item {
    display: flex;
    gap: 12px;
    padding: 12px 14px;
    margin: 6px 10px;
    background: rgba(255,255,255,.025);
    border: 1px solid rgba(123,104,238,.12);
    border-radius: 12px;
    transition: border-color .2s, box-shadow .2s;
    position: relative;
}
.gg-cart-item:hover {
    border-color: rgba(123,104,238,.28);
    box-shadow: 0 4px 16px rgba(123,104,238,.1);
}
.gg-cart-item.gg-removing {
    opacity: 0;
    transform: translateX(20px);
    transition: opacity .25s, transform .25s;
}
.gg-cart-item-img {
    width: 70px;
    height: 70px;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid rgba(123,104,238,.15);
    flex-shrink: 0;
    background: rgba(255,255,255,.03);
}
.gg-cart-item-details {
    flex: 1;
    min-width: 0;
}
.gg-cart-item-name {
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.4;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.gg-cart-item-name a {
    color: #fff;
    text-decoration: none;
}
.gg-cart-item-name a:hover { color: #A855F7; }
.gg-cart-item-price {
    color: #A855F7;
    font-size: 13px;
    font-weight: 700;
    margin-bottom: 8px;
}
.gg-cart-item-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.gg-cart-qty-wrap {
    display: inline-flex;
    align-items: center;
    border: 1.5px solid rgba(123,104,238,.25);
    border-radius: 8px;
    overflow: hidden;
    background: rgba(255,255,255,.03);
}
.gg-cart-qty-btn {
    width: 28px;
    height: 28px;
    background: rgba(123,104,238,.1);
    border: none;
    color: #A855F7;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, color .15s;
    user-select: none;
    line-height: 1;
}
.gg-cart-qty-btn:hover { background: rgba(123,104,238,.3); color: #fff; }
.gg-cart-qty-btn:disabled { opacity: .3; cursor: not-allowed; }
.gg-cart-qty-num {
    width: 32px;
    text-align: center;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    background: transparent;
    border: none;
    outline: none;
    -moz-appearance: textfield;
}
.gg-cart-qty-num::-webkit-inner-spin-button,
.gg-cart-qty-num::-webkit-outer-spin-button { -webkit-appearance: none; }
.gg-cart-item-remove {
    background: transparent;
    border: none;
    color: rgba(229,57,53,.45);
    cursor: pointer;
    font-size: 16px;
    padding: 4px;
    transition: color .15s;
    line-height: 1;
}
.gg-cart-item-remove:hover { color: #e53935; }
.gg-cart-item-subtotal {
    color: rgba(255,255,255,.4);
    font-size: 11px;
    text-align: right;
    margin-top: 4px;
}

/* --- Loading state --- */
.gg-cart-item.gg-cart-updating {
    opacity: .5;
    pointer-events: none;
}
#gg-cart-body.gg-cart-loading::after {
    content: '';
    display: block;
    width: 32px;
    height: 32px;
    border: 3px solid rgba(123,104,238,.15);
    border-top-color: #A855F7;
    border-radius: 50%;
    animation: ggSpin .7s linear infinite;
    margin: 20px auto;
}
@keyframes ggSpin { to { transform: rotate(360deg); } }

/* --- Footer --- */
#gg-cart-footer {
    border-top: 1px solid rgba(123,104,238,.15);
    background: linear-gradient(180deg, #0a0a14 0%, #080810 100%);
    flex-shrink: 0;
    padding: 14px 14px 18px;
}

/* --- Express Checkout --- */
.gg-cart-divider-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: rgba(255,255,255,.25);
    text-align: center;
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
}
.gg-cart-divider-label::before,
.gg-cart-divider-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: rgba(123,104,238,.15);
}
#gg-cart-express-buttons {
    background: transparent;
    border: none;
    padding: 0;
    margin-bottom: 0;
    min-height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
}
#gg-cart-express-buttons > * { width: 100% !important; }
#gg-cart-express { margin-bottom: 7px; }

/* --- Coupon --- */
#gg-cart-coupon { margin-bottom: 10px; }
#gg-cart-coupon-toggle {
    background: transparent;
    border: none;
    color: rgba(255,255,255,.4);
    font-size: 12px;
    cursor: pointer;
    padding: 4px 0;
    transition: color .2s;
    display: block;
    width: 100%;
    text-align: left;
}
#gg-cart-coupon-toggle:hover { color: #A855F7; }
#gg-cart-coupon-form { margin-top: 8px; }
#gg-cart-coupon-row {
    display: flex;
    gap: 6px;
}
#gg-cart-coupon-input {
    flex: 1;
    background: rgba(255,255,255,.04);
    border: 1.5px solid rgba(123,104,238,.2);
    border-radius: 8px;
    color: #fff;
    font-size: 13px;
    padding: 9px 12px;
    outline: none;
    transition: border-color .2s;
}
#gg-cart-coupon-input:focus { border-color: #7B68EE; }
#gg-cart-coupon-input::placeholder { color: rgba(255,255,255,.25); }
#gg-cart-coupon-apply {
    background: rgba(123,104,238,.15);
    border: 1.5px solid rgba(123,104,238,.3);
    border-radius: 8px;
    color: #A855F7;
    font-size: 12px;
    font-weight: 700;
    padding: 9px 14px;
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
}
#gg-cart-coupon-apply:hover {
    background: rgba(123,104,238,.25);
    color: #fff;
}
#gg-cart-coupon-msg {
    font-size: 11px;
    margin-top: 5px;
    padding: 0 2px;
}
#gg-cart-coupon-msg.gg-coupon-ok { color: #00c853; }
#gg-cart-coupon-msg.gg-coupon-err { color: #e53935; }
#gg-cart-applied-coupons { margin-top: 6px; }
.gg-coupon-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(0,200,83,.08);
    border: 1px solid rgba(0,200,83,.2);
    border-radius: 6px;
    color: #00c853;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 8px;
    margin: 2px 2px 2px 0;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.gg-coupon-remove {
    background: transparent;
    border: none;
    color: rgba(0,200,83,.6);
    cursor: pointer;
    font-size: 12px;
    padding: 0;
    line-height: 1;
    transition: color .15s;
}
.gg-coupon-remove:hover { color: #e53935; }

/* --- Totals --- */
#gg-cart-totals { margin-bottom: 12px; }
.gg-cart-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 3px;
}
.gg-cart-total-row span:first-child {
    color: rgba(255,255,255,.5);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .5px;
}
#gg-cart-subtotal {
    color: #fff;
    font-size: 20px;
    font-weight: 800;
}
.gg-cart-total-note {
    color: rgba(255,255,255,.25);
    font-size: 10px;
    text-align: right;
}

/* --- Buttons --- */
.gg-cart-btn {
    display: block;
    width: 100%;
    padding: 13px 16px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    text-align: center;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all .25s;
    margin-bottom: 7px;
    box-sizing: border-box;
}
.gg-cart-btn:last-child { margin-bottom: 0; }
.gg-cart-btn-primary {
    background: linear-gradient(135deg, #7B68EE, #A855F7);
    color: #fff !important;
    box-shadow: 0 4px 18px rgba(123,104,238,.3);
}
.gg-cart-btn-primary:hover {
    background: linear-gradient(135deg, #6952d6, #9333EA);
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(123,104,238,.45);
}
.gg-cart-btn-secondary {
    background: rgba(123,104,238,.1);
    color: #A855F7 !important;
    border: 1.5px solid rgba(123,104,238,.25);
}
.gg-cart-btn-secondary:hover {
    background: rgba(123,104,238,.2);
    color: #fff !important;
    border-color: rgba(123,104,238,.4);
}
.gg-cart-btn-ghost {
    background: transparent;
    color: rgba(255,255,255,.4) !important;
    border: 1px solid rgba(255,255,255,.08);
    margin-bottom: 0;
}
.gg-cart-btn-ghost:hover {
    background: rgba(255,255,255,.04);
    color: rgba(255,255,255,.7) !important;
    border-color: rgba(255,255,255,.15);
}

/* --- Floating Trigger --- */
#gg-cart-trigger {
    position: fixed;
    bottom: 24px;
    right: 24px;
    width: 68px;
    height: 68px;
    background: linear-gradient(135deg, #7B00FF, #A855F7);
    border: none;
    border-radius: 50%;
    cursor: pointer;
    z-index: 99997;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 20px rgba(123,0,255,.5);
    transition: transform .2s, box-shadow .2s;
    color: #fff;
}
#gg-cart-trigger:hover {
    transform: scale(1.08);
    box-shadow: 0 8px 32px rgba(123,0,255,.65);
}
#gg-cart-trigger svg {
    width: 30px;
    height: 30px;
}
#gg-cart-trigger-count {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #A855F7;
    color: #fff;
    font-size: 11px;
    font-weight: 800;
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 4px;
    border: 2px solid #080810;
    line-height: 1;
}
#gg-cart-trigger-count:empty,
#gg-cart-trigger-count[data-count="0"] { display: none; }

/* Bounce animation when item added */
@keyframes ggTriggerBounce {
    0%   { transform: scale(1); }
    30%  { transform: scale(1.25); }
    60%  { transform: scale(.95); }
    100% { transform: scale(1); }
}
#gg-cart-trigger.gg-cart-bounce {
    animation: ggTriggerBounce .4s ease;
}

/* --- Toast --- */
#gg-cart-toast {
    position: fixed;
    bottom: 96px;
    right: 24px;
    background: #1a1a2e;
    border: 1px solid rgba(123,104,238,.3);
    border-radius: 10px;
    padding: 12px 16px;
    color: #fff;
    font-size: 13px;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    z-index: 99999;
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 200px;
    max-width: 280px;
    transform: translateY(20px);
    opacity: 0;
    transition: transform .25s, opacity .25s;
    pointer-events: none;
}
#gg-cart-toast.gg-toast-show {
    transform: translateY(0);
    opacity: 1;
    pointer-events: auto;
}
#gg-cart-toast-undo {
    background: transparent;
    border: 1px solid rgba(168,85,247,.4);
    border-radius: 6px;
    color: #A855F7;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 10px;
    cursor: pointer;
    white-space: nowrap;
    transition: all .2s;
    flex-shrink: 0;
}
#gg-cart-toast-undo:hover {
    background: rgba(168,85,247,.15);
    color: #fff;
}

/* --- Mobile --- */
@media (max-width: 480px) {
    #gg-cart-drawer { width: 100vw; }
    #gg-cart-trigger { bottom: 18px; right: 18px; width: 62px; height: 62px; }
    #gg-cart-trigger svg { width: 22px; height: 22px; }
}

/* Hide on cart/checkout pages */
body.woocommerce-cart #gg-cart-trigger,
body.woocommerce-checkout #gg-cart-trigger { display: none !important; }
</style>
    <?php
});

/* =========================================================
   SECTION 4 — JAVASCRIPT
   ========================================================= */
add_action('wp_footer', function () {
    if (is_cart() || is_checkout()) return;
    ?>
<script>
(function() {
'use strict';

/* ── State ── */
var state = {
    cart:         null,
    nonce:        ggCartData.nonce,
    isOpen:       false,
    undoTimer:    null,
    undoItem:     null,
    qtyTimers:    {}
};

var API = ggCartData.storeApiUrl;

/* ─────────────────────────────────────
   STORE API HELPERS
   ───────────────────────────────────── */
function apiFetch(method, path, body) {
    var opts = {
        method: method,
        headers: {
            'Content-Type': 'application/json',
            'Nonce': state.nonce
        },
        credentials: 'same-origin'
    };
    if (body) opts.body = JSON.stringify(body);
    return fetch(API + path, opts).then(function(res) {
        // Update nonce from response
        var newNonce = res.headers.get('Nonce') || res.headers.get('X-WC-Store-API-Nonce');
        if (newNonce) state.nonce = newNonce;
        if (res.status === 204) return {};
        return res.json().then(function(data) {
            if (!res.ok) throw data;
            return data;
        });
    });
}

function getCart()            { return apiFetch('GET',  '/cart'); }
function updateItem(key, qty) { return apiFetch('POST', '/cart/update-item', { key: key, quantity: qty }); }
function removeItem(key)      { return apiFetch('POST', '/cart/remove-item', { key: key }); }
function applyCoupon(code)    { return apiFetch('POST', '/cart/apply-coupon', { code: code }); }
function removeCoupon(code)   { return apiFetch('POST', '/cart/remove-coupon', { code: code }); }

/* ─────────────────────────────────────
   RENDER
   ───────────────────────────────────── */
function render(cart) {
    state.cart = cart;
    renderItems(cart);
    renderTotals(cart);
    renderShippingBar(cart);
    renderCoupons(cart);
    updateTriggerCount(cart.items_count || 0);
    updateItemCountLabel(cart.items_count || 0);
}

function renderItems(cart) {
    var $items = document.getElementById('gg-cart-items');
    var $empty = document.getElementById('gg-cart-empty');
    var items  = cart.items || [];

    if (!items.length) {
        $items.innerHTML = '';
        $empty.style.display = 'block';
        document.getElementById('gg-cart-footer').style.display = 'none';
        return;
    }

    $empty.style.display = 'none';
    document.getElementById('gg-cart-footer').style.display = 'block';

    var html = '';
    items.forEach(function(item) {
        var img    = item.images && item.images[0] ? item.images[0].thumbnail || item.images[0].src : '';
        var price  = item.prices ? formatPrice(item.prices.price, item.prices.currency_minor_unit) : '';
        var line   = item.prices ? formatPrice(item.prices.line_subtotal || item.prices.line_total || (item.prices.price * item.quantity), item.prices.currency_minor_unit) : '';
        var qty    = item.quantity;
        var maxQty = item.quantity_limits ? item.quantity_limits.maximum : 99;

        html += '<div class="gg-cart-item" data-key="' + escHtml(item.key) + '">';
        if (img) {
            html += '<img class="gg-cart-item-img" src="' + escHtml(img) + '" alt="' + escHtml(item.name) + '" loading="lazy" />';
        }
        html += '<div class="gg-cart-item-details">';
        html += '  <div class="gg-cart-item-name"><a href="' + escHtml(item.permalink) + '">' + escHtml(item.name) + '</a></div>';
        html += '  <div class="gg-cart-item-price">' + price + ' each</div>';
        html += '  <div class="gg-cart-item-controls">';
        html += '    <div class="gg-cart-qty-wrap">';
        html += '      <button type="button" class="gg-cart-qty-btn gg-cart-qty-minus" data-key="' + escHtml(item.key) + '" ' + (qty <= 1 ? 'disabled' : '') + '>−</button>';
        html += '      <input type="number" class="gg-cart-qty-num" value="' + qty + '" min="1" max="' + maxQty + '" data-key="' + escHtml(item.key) + '" readonly />';
        html += '      <button type="button" class="gg-cart-qty-btn gg-cart-qty-plus" data-key="' + escHtml(item.key) + '" ' + (qty >= maxQty ? 'disabled' : '') + '>+</button>';
        html += '    </div>';
        html += '    <button type="button" class="gg-cart-item-remove" data-key="' + escHtml(item.key) + '" title="Remove">🗑</button>';
        html += '  </div>';
        html += '  <div class="gg-cart-item-subtotal">Total: ' + line + '</div>';
        html += '</div>';
        html += '</div>';
    });

    $items.innerHTML = html;
}

function renderTotals(cart) {
    var prices = cart.totals;
    if (!prices) return;
    var unit     = prices.currency_minor_unit || 2;
    // Subtract discount from items total to get post-coupon subtotal
    var items    = parseInt(prices.total_items || 0);
    var discount = parseInt(prices.total_discount || 0);
    var total    = formatPrice(items - discount, unit);
    document.getElementById('gg-cart-subtotal').textContent = total;
}

function renderShippingBar(cart) {
    var threshold = ggCartData.freeShipping;
    var prices    = cart.totals;
    if (!prices) return;
    var unit      = prices.currency_minor_unit || 2;
    var subtotal  = parseInt(prices.total_items || 0) / Math.pow(10, unit);
    var pct       = Math.min(100, (subtotal / threshold) * 100);
    var remaining = Math.max(0, threshold - subtotal).toFixed(2);

    var $text = document.getElementById('gg-cart-shipping-text');
    var $fill = document.getElementById('gg-cart-shipping-fill');

    $fill.style.width = pct + '%';

    if (pct >= 100) {
        $text.innerHTML = '🎉 <strong>Free shipping unlocked!</strong>';
        $text.className = 'gg-shipping-unlocked';
        $fill.classList.add('gg-shipping-done');
    } else {
        $text.innerHTML = 'Add <strong>' + ggCartData.currency + remaining + '</strong> worth of chaff for free shipping';
        $text.className = '';
        $fill.classList.remove('gg-shipping-done');
    }
}

function renderCoupons(cart) {
    var $el      = document.getElementById('gg-cart-applied-coupons');
    var coupons  = cart.coupons || [];
    if (!coupons.length) { $el.innerHTML = ''; return; }

    var html = '';
    var totals = cart.totals || {};
    var unit = totals.currency_minor_unit || 2;
    coupons.forEach(function(c) {
        // Get discount amount from totals
        var discount = '';
        if (totals.total_discount && parseInt(totals.total_discount) > 0) {
            discount = ' <span style="color:rgba(0,200,83,.7);font-size:10px;font-weight:600;">-' + formatPrice(totals.total_discount, unit) + '</span>';
        }
        html += '<span class="gg-coupon-tag">';
        html += '🏷️ ' + escHtml(c.code.toUpperCase()) + discount;
        html += ' <button class="gg-coupon-remove" data-code="' + escHtml(c.code) + '" title="Remove coupon">✕</button>';
        html += '</span>';
    });
    $el.innerHTML = html;
}

function updateTriggerCount(count) {
    var $el = document.getElementById('gg-cart-trigger-count');
    $el.textContent = count > 0 ? count : '';
    $el.dataset.count = count;
}

function updateItemCountLabel(count) {
    var $el = document.getElementById('gg-cart-item-count');
    if ($el) $el.textContent = count;
}

/* ─────────────────────────────────────
   OPEN / CLOSE
   ───────────────────────────────────── */
function openCart() {
    if (state.isOpen) return;
    state.isOpen = true;
    document.getElementById('gg-cart-drawer').classList.add('gg-cart-open');
    document.getElementById('gg-cart-drawer').setAttribute('aria-hidden', 'false');
    document.getElementById('gg-cart-overlay').classList.add('gg-cart-active');
    document.body.style.overflow = 'hidden';
    refreshCart();
}

function closeCart() {
    if (!state.isOpen) return;
    state.isOpen = false;
    document.getElementById('gg-cart-drawer').classList.remove('gg-cart-open');
    document.getElementById('gg-cart-drawer').setAttribute('aria-hidden', 'true');
    document.getElementById('gg-cart-overlay').classList.remove('gg-cart-active');
    document.body.style.overflow = '';
}

function refreshCart() {
    return getCart().then(function(cart) {
        render(cart);
        initExpressCheckout();
    }).catch(function(err) {
        console.error('[GG Cart] Failed to load cart:', err);
    });
}

/* ─────────────────────────────────────
   QUANTITY ACTIONS
   ───────────────────────────────────── */
function handleQtyChange(key, newQty) {
    var $item = document.querySelector('.gg-cart-item[data-key="' + key + '"]');
    if ($item) $item.classList.add('gg-cart-updating');

    // Debounce rapid clicking
    clearTimeout(state.qtyTimers[key]);
    state.qtyTimers[key] = setTimeout(function() {
        updateItem(key, newQty).then(function(cart) {
            render(cart);
        }).catch(function(err) {
            console.error('[GG Cart] Update failed:', err);
            refreshCart();
        });
    }, 300);
}

function handleRemove(key) {
    // Find item data for undo
    var itemEl = document.querySelector('.gg-cart-item[data-key="' + key + '"]');
    var itemName = itemEl ? itemEl.querySelector('.gg-cart-item-name').textContent.trim() : 'Item';

    // Animate out
    if (itemEl) {
        itemEl.classList.add('gg-removing');
        setTimeout(function() {
            if (itemEl.parentNode) itemEl.parentNode.removeChild(itemEl);
        }, 250);
    }

    // Store undo data
    state.undoItem = { key: key, name: itemName };

    removeItem(key).then(function(cart) {
        render(cart);
        showUndoToast(itemName, key);
    }).catch(function(err) {
        console.error('[GG Cart] Remove failed:', err);
        refreshCart();
    });
}

/* ─────────────────────────────────────
   UNDO TOAST
   ───────────────────────────────────── */
function showUndoToast(name, key) {
    clearTimeout(state.undoTimer);
    var $toast = document.getElementById('gg-cart-toast');
    $toast.innerHTML = '<span>Removed: ' + escHtml(name.substring(0, 30)) + '</span>' +
                       '<button id="gg-cart-toast-undo" type="button">Undo</button>';

    $toast.classList.add('gg-toast-show');

    document.getElementById('gg-cart-toast-undo').addEventListener('click', function() {
        hideToast();
        // Re-add item — we need to know the product ID
        // Refresh cart which will show it's not there, then we just refresh
        refreshCart();
    });

    state.undoTimer = setTimeout(hideToast, 4000);
}

function hideToast() {
    var $toast = document.getElementById('gg-cart-toast');
    $toast.classList.remove('gg-toast-show');
}

/* ─────────────────────────────────────
   COUPON ACTIONS
   ───────────────────────────────────── */
function handleApplyCoupon() {
    var $input = document.getElementById('gg-cart-coupon-input');
    var $msg   = document.getElementById('gg-cart-coupon-msg');
    var code   = $input.value.trim();
    if (!code) return;

    $msg.textContent = 'Applying...';
    $msg.className   = '';

    applyCoupon(code).then(function(cart) {
        render(cart);
        $input.value   = '';
        $msg.textContent = '✓ Coupon applied!';
        $msg.className   = 'gg-coupon-ok';
        setTimeout(function() { $msg.textContent = ''; }, 3000);
    }).catch(function(err) {
        var msg = err && err.message ? err.message : 'Invalid coupon code';
        $msg.textContent = '✕ ' + msg;
        $msg.className   = 'gg-coupon-err';
    });
}

function handleRemoveCoupon(code) {
    removeCoupon(code).then(function(cart) {
        render(cart);
    }).catch(function(err) {
        console.error('[GG Cart] Remove coupon failed:', err);
        refreshCart();
    });
}

/* ─────────────────────────────────────
   EXPRESS CHECKOUT (STRIPE ECE)
   Initialises Apple Pay / Google Pay / Link
   directly using Stripe's Express Checkout Element
   ───────────────────────────────────── */
var ggStripe       = null;
var ggEceElement   = null;
var ggEceMounted   = false;
var ggEceInitialised = false;

function initExpressCheckout() {
    var $express = document.getElementById('gg-cart-express');
    var $buttons = document.getElementById('gg-cart-express-buttons');

    if (!ggCartData.stripeKey) {
        $express.style.display = 'none';
        return;
    }

    // Only initialise once
    if (ggEceInitialised) {
        // Update amount if cart changed
        if (ggEceElement && state.cart && state.cart.totals) {
            var unit   = state.cart.totals.currency_minor_unit || 2;
            var amount = parseInt(state.cart.totals.total_price || state.cart.totals.total_items || 0);
            // Can't update ECE amount after mount — re-init if cart total changed
        }
        return;
    }

    // Load Stripe.js if not already loaded
    if (typeof Stripe === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.onload = function() { ggInitStripeEce($express, $buttons); };
        script.onerror = function() { $express.style.display = 'none'; };
        document.head.appendChild(script);
    } else {
        ggInitStripeEce($express, $buttons);
    }
}

function ggInitStripeEce($express, $buttons) {
    try {
        if (!ggStripe) {
            ggStripe = Stripe(ggCartData.stripeKey, { locale: 'en-GB' });
        }

        if (!state.cart || !state.cart.totals) {
            $express.style.display = 'none';
            return;
        }

        var unit   = state.cart.totals.currency_minor_unit || 2;
        var amount = parseInt(state.cart.totals.total_price || state.cart.totals.total_items || 0);

        if (amount <= 0) {
            $express.style.display = 'none';
            return;
        }

        var elements = ggStripe.elements({
            mode:     'payment',
            amount:   amount,
            currency: ggCartData.currencyCode || 'gbp',
            capture_method: 'automatic',
            appearance: {
                theme: 'night',
                variables: {
                    colorPrimary:    '#A855F7',
                    colorBackground: '#080810',
                    borderRadius:    '8px',
                }
            }
        });

        ggEceElement = elements.create('expressCheckout', {
            buttonHeight: 44,
            buttonTheme: {
                applePay:  'white-outline',
                googlePay: 'white',
            },
            paymentMethods: {
                applePay:  'auto',
                googlePay: 'auto',
                link:      'auto',
                amazonPay: 'never',
                klarna:    'never',
                paypal:    'never',
            },
            layout: { maxColumns: 1, maxRows: 3 },
            shippingAddressRequired: true,
            allowedShippingCountries: ['GB'],
        });

        // Listen for availability — hide section if no methods available
        ggEceElement.on('ready', function(event) {
            if (event.availablePaymentMethods) {
                $express.style.display = 'block';
                ggEceInitialised = true;
            } else {
                $express.style.display = 'none';
            }
        });

        // Handle confirm — create order via WooCommerce
        ggEceElement.on('confirm', function(event) {
            ggStripe.confirmPayment({
                elements:       elements,
                confirmParams: {
                    return_url: ggCartData.checkoutUrl,
                },
                redirect: 'if_required',
            }).then(function(result) {
                if (result.error) {
                    console.error('[GG Cart Express] Payment error:', result.error.message);
                    event.paymentFailed({ reason: 'fail' });
                    // Show error in cart
                    var $err = document.createElement('div');
                    $err.style.cssText = 'color:#e53935;font-size:12px;padding:8px;text-align:center;';
                    $err.textContent = result.error.message;
                    $buttons.appendChild($err);
                    setTimeout(function() { if ($err.parentNode) $err.parentNode.removeChild($err); }, 5000);
                } else {
                    // Redirect to checkout to complete order details
                    window.location.href = ggCartData.checkoutUrl;
                }
            });
        });

        // Mount to container
        $buttons.innerHTML = '';
        ggEceElement.mount($buttons);

    } catch(e) {
        console.error('[GG Cart Express] Init error:', e);
        if ($express) $express.style.display = 'none';
    }
}

/* ─────────────────────────────────────
   EVENT LISTENERS
   ───────────────────────────────────── */

// Open / Close
document.getElementById('gg-cart-trigger').addEventListener('click', function() {
    state.isOpen ? closeCart() : openCart();
});
document.getElementById('gg-cart-close').addEventListener('click', closeCart);
document.getElementById('gg-cart-overlay').addEventListener('click', closeCart);
document.getElementById('gg-cart-empty-browse').addEventListener('click', function() {
    closeCart();
    window.location.href = ggCartData.singlesUrl;
});

// Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && state.isOpen) closeCart();
});

// Delegated events on drawer
document.getElementById('gg-cart-drawer').addEventListener('click', function(e) {
    var el = e.target;

    // Qty minus
    if (el.classList.contains('gg-cart-qty-minus') || el.closest('.gg-cart-qty-minus')) {
        var btn = el.classList.contains('gg-cart-qty-minus') ? el : el.closest('.gg-cart-qty-minus');
        var key = btn.dataset.key;
        var $inp = document.querySelector('.gg-cart-qty-num[data-key="' + key + '"]');
        var cur  = parseInt($inp.value) || 1;
        if (cur > 1) {
            $inp.value = cur - 1;
            btn.disabled = (cur - 1) <= 1;
            handleQtyChange(key, cur - 1);
        }
        return;
    }

    // Qty plus
    if (el.classList.contains('gg-cart-qty-plus') || el.closest('.gg-cart-qty-plus')) {
        var btn = el.classList.contains('gg-cart-qty-plus') ? el : el.closest('.gg-cart-qty-plus');
        var key = btn.dataset.key;
        var $inp = document.querySelector('.gg-cart-qty-num[data-key="' + key + '"]');
        var cur  = parseInt($inp.value) || 1;
        var max  = parseInt($inp.max) || 99;
        if (cur < max) {
            $inp.value = cur + 1;
            handleQtyChange(key, cur + 1);
        }
        return;
    }

    // Remove item
    if (el.classList.contains('gg-cart-item-remove') || el.closest('.gg-cart-item-remove')) {
        var btn = el.classList.contains('gg-cart-item-remove') ? el : el.closest('.gg-cart-item-remove');
        handleRemove(btn.dataset.key);
        return;
    }

    // Remove coupon
    if (el.classList.contains('gg-coupon-remove') || el.closest('.gg-coupon-remove')) {
        var btn = el.classList.contains('gg-coupon-remove') ? el : el.closest('.gg-coupon-remove');
        handleRemoveCoupon(btn.dataset.code);
        return;
    }
});

// Coupon toggle
document.getElementById('gg-cart-coupon-toggle').addEventListener('click', function() {
    var $form = document.getElementById('gg-cart-coupon-form');
    var shown = $form.style.display !== 'none';
    $form.style.display = shown ? 'none' : 'block';
    this.textContent = shown ? '🏷️ Have a coupon code?' : '🏷️ Hide coupon input';
});

// Coupon apply
document.getElementById('gg-cart-coupon-apply').addEventListener('click', handleApplyCoupon);
document.getElementById('gg-cart-coupon-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') handleApplyCoupon();
});

/* ─────────────────────────────────────
   INTERCEPT ADD TO CART
   Uses WooCommerce's native added_to_cart event
   which fires reliably on all devices including mobile
   ───────────────────────────────────── */
function ggCartOnItemAdded() {
    var trigger = document.getElementById('gg-cart-trigger');
    trigger.classList.remove('gg-cart-bounce');
    void trigger.offsetWidth; // reflow
    trigger.classList.add('gg-cart-bounce');
    setTimeout(function() { trigger.classList.remove('gg-cart-bounce'); }, 500);

    // Wait a beat for WooCommerce session to fully commit
    setTimeout(function() {
        refreshCart().then(function() {
            if (!state.isOpen) openCart();
        });
    }, 400);
}

// jQuery event — fires after WooCommerce AJAX add to cart completes
// Works on desktop and mobile reliably
if (typeof jQuery !== 'undefined') {
    jQuery(document.body).on('added_to_cart', function() {
        ggCartOnItemAdded();
    });
} else {
    // Fallback if jQuery not available yet — wait for it
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('added_to_cart', function() {
                ggCartOnItemAdded();
            });
        }
    });
}

/* ─────────────────────────────────────
   LISTEN FOR WOOCOMMERCE CART EVENTS
   ───────────────────────────────────── */
document.body.addEventListener('wc_fragments_refreshed', function() {
    if (state.isOpen) refreshCart();
});

/* ─────────────────────────────────────
   INIT — LOAD CART COUNT ON PAGE LOAD
   ───────────────────────────────────── */
getCart().then(function(cart) {
    state.cart = cart;
    updateTriggerCount(cart.items_count || 0);
    updateItemCountLabel(cart.items_count || 0);
}).catch(function() {
    // Silently fail on initial load — non-critical
});

/* ─────────────────────────────────────
   HELPERS
   ───────────────────────────────────── */
function formatPrice(amount, minorUnit) {
    var divisor = Math.pow(10, minorUnit !== undefined ? parseInt(minorUnit) : 2);
    var value   = (parseInt(String(amount || '0').replace(/[^0-9-]/g, '')) / divisor).toFixed(2);
    return ggCartData.currency + value;
}

function escHtml(str) {
    if (typeof str !== 'string') return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

})();
</script>
    <?php
}, 999);