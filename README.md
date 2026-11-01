# DEV3 AI Smart Coupons & Dynamic Discounts for WooCommerce

Detect shoppers about to abandon their cart and show a personal, margin-safe, single-use coupon with a countdown, chosen by AI within your rules.

## Features

DEV3 AI Smart Coupons watches for the moments shoppers usually leave, such as exit intent, idle time or switching tabs. When the abandonment risk is high enough, it shows a personal offer: a percentage off, a fixed amount off or free shipping. Each offer comes with a short headline, a one-click "Apply" button and a countdown.

The AI picks the incentive and writes the copy. **Your safety rules always win.** Every suggestion is clamped on the server: maximum percentage and amount, minimum spend, a margin floor per product, excluded products, categories and sale items, allowed incentive types, one offer per shopper per day and a global daily discount budget. If the AI is unavailable, the plugin makes a rules-based offer instead, or none if you prefer.

**Features**

* **Abandonment risk score (0–100).** A transparent, weighted model of exit intent, idle time, cart value against your average order, new or returning visitor, order history and recency, and repeated cart views. Every point is explained in the simulator.
* **Personal incentives.** Percentage off, fixed cart discount or free shipping, with headline, message, button text and a time limit.
* **Margin protection.** Reads product cost from a meta key you choose (for example `_wc_cog_cost`). No product is ever discounted below cost plus your minimum margin, and products with no room are left out of the coupon.
* **Real WooCommerce coupons.** Each coupon has a unique code, is single use (usage limit 1, 1 per user) and expires with the countdown. It is limited to the products that were checked, mirrors your exclusions and minimum spend, is restricted to the shopper's email when known, can be individual use only, and only works in the shopping session it was issued to.
* **Banners.** Inline on cart and checkout, plus a floating bottom bar, top bar, corner card or modal. Colours, radius and copy are customisable with a live preview. Banners are accessible (keyboard, focus management, screen-reader announcements) and cause no layout shift.
* **One-click apply.** Works with the block cart and checkout (Store API) and with the classic shortcode pages.
* **Flash sales.** Schedule site-wide urgency bars with a countdown, and generate the copy with AI.
* **Analytics.** Offers shown, applied and redeemed; conversion; revenue from offered carts; total discount cost, by incentive type and trigger; recent offers with links to their orders.
* **Background cleanup.** Action Scheduler removes expired, unused coupons after your retention period and resets the daily budget at midnight.
* **Privacy controls.** Optional consent-cookie requirement, Do Not Track and Global Privacy Control support, and no personal data in AI requests.
* Compatible with HPOS (custom order tables), Cart & Checkout blocks and full-page caching.

AI features are powered by the Google AI API using your own API key.

### Privacy
**What is sent to the Google AI API.** The API is called only when a shopper's risk score passes your threshold, or when an admin uses "Generate copy with DEV3 AI" or the simulator. Each request contains anonymous cart context only:

* store currency, cart subtotal, number of items and up to 8 product category names
* your store's average order value
* the risk score and its reasons (for example "Exit intent", "First-time visitor")
* the trigger and page type, and the customer segment (new or returning, number of previous orders)
* your limits (allowed types, ranges) and brand-voice text

No name, email address, IP address, street address, user ID, coupon code or product name is sent. Requests use your API key, are subject to Google's API terms, and are logged locally (task, model, tokens) in Usage Logs.

**What is stored on your site.** Each offer is logged in the `wp_dev3sc_offers` table: the coupon, amounts, risk score, trigger, status and order. The shopper's session, email and IP are stored only as keyed one-way hashes (HMAC with your site salts), used to enforce "one offer per day". The storefront script keeps a first-visit timestamp in localStorage, and a cart-view counter and the current offer in sessionStorage. It sets no cookies. With "Require a consent cookie" enabled, the script does nothing until consent is given.

## Requirements

- WordPress 6.0 or newer (tested up to 6.8)
- PHP 7.4 or newer
- WooCommerce 7.6 or newer (required)
- Optional: your own Google AI API key (from Google AI Studio) to switch on the DEV3 AI features. The rest of the plugin works without one.

## Installation

### From the release zip

1. Download `dev3-ai-smart-coupons-for-woocommerce.zip` from the [latest release](https://github.com/dev3tech/dev3-ai-smart-coupons-for-woocommerce/releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**.

### Build from source

```bash
git clone https://github.com/dev3tech/dev3-ai-smart-coupons-for-woocommerce.git
cd dev3-ai-smart-coupons-for-woocommerce
composer install --no-dev
npm ci
npm run build
```

Then copy the `dev3-ai-smart-coupons-for-woocommerce` folder into `wp-content/plugins/` and activate it.

### Getting started

1. Make sure WooCommerce is installed and active.
2. Upload the plugin zip in Plugins → Add New → Upload Plugin, then activate it.
3. Go to **DEV3 AI Smart Coupons → Rules** and set your caps, margin floor, minimum spend and daily budget.
4. Optional, for AI offers: create an API key at https://aistudio.google.com/app/apikey and paste it in **DEV3 AI Smart Coupons → API Config**. Without a key the plugin makes rules-based offers.
5. Optional, for free-shipping offers: add a "Free shipping" method that requires "A valid free shipping coupon" to your shipping zones.

## FAQ

### Can the AI give away more than I allow?
No. The model only suggests. The server clamps type, amount, time limit and copy to your rules, checks the margin floor for every cart line and the daily budget, and rejects copy that mentions numbers, links or codes. Malformed output falls back to a rules-based offer.

### Does it work with page caching?
Yes. Cached pages contain only public settings and flash sales. A shopper's offer is returned by a REST call when a trigger fires, and printed inline only on cart and checkout, which WooCommerce never caches.

### Which cost plugins are supported?
Any plugin that stores cost in product meta: set the key under Rules → Margin protection. Developers can also provide costs with the `dev3sc_product_cost` filter.

### Can a code be shared or reused?
Each code works once, only until its countdown ends, and only in the shopping session it was issued to (or for the logged-in customer whose email it is restricted to).

## Documentation

The full HTML guide (installation, settings, hooks and FAQ) is in [`docs/index.html`](docs/index.html). Open it in a browser after cloning or downloading the repository.

## Changelog

### 1.0.0
* Initial release.

## Licence

Released under the GNU General Public License v2.0 or later (GPL-2.0-or-later). See [LICENSE](LICENSE).

## More from DEV3

- [DEV3 AI Smart Coupons & Dynamic Discounts for WooCommerce on the DEV3 Marketplace](https://www.dev3.tech/item/dev3-ai-smart-coupons-for-woocommerce)
- [More WordPress plugins, themes and templates from DEV3](https://www.dev3.tech/marketplace)
