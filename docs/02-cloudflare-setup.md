## 2.0 Baseline SSL/TLS, HSTS, and Login Rate Limiting

##### **Objective:** 
Enforce strict transport encryption and protect `/wp-login.php` against automated credential-stuffing and brute-force attacks at the Cloudflare Edge before requests reach the VPS.
##### **Action:**
###### Step 1: Apply Baseline Edge SSL & HSTS
Do not configure SSL/TLS from scratch. Follow the exact baseline procedure defined in the core infrastructure repository:

**Reference:** 
Complete **`vps-linux-hardening-paas/docs/05-cloudflare-waf-security.md` (Section 5.1: Domain Onboarding & Section 5.2: SSL/TLS Configuration)**.

> [!Note]
> **Production HSTS Graduation (12 Months & Preload)**
> Our baseline infrastructure guide sets HSTS Max-Age to 6 months with Preload: Off to prevent accidental lockouts during initial setup. Once the WooCommerce store has operated stably over HTTPS for at least 30 days across all subdomains, navigate to SSL/TLS ➔ Edge Certificates ➔ HTTP Strict Transport Security (HSTS) and upgrade Max-Age to 12 months and set Preload to On.
###### Step 2: Configure WordPress Login Rate Limiting
Adapt the generic Rate Limiting procedure from **`vps-linux-hardening-paas/docs/05-cloudflare-waf-security.md` (Section 4)** specifically for WordPress authentication endpoints:
1. In the Cloudflare dashboard, navigate to **Security** ➔ **Security Rules** (formerly WAF) ➔ **Rate limiting rules**.
2. Click **Create rule** and set the name to `WP Hardening - Protect Login`.
3. Configure the matching logic (**If incoming requests match...**):
    - **Field:** `URI Path` | **Operator:** `equals` | **Value:** `/wp-login.php`
    - **AND**
    - **Field:** `Method` | **Operator:** `equals` | **Value:** `POST`
4. Set the rate threshold: **Requests:** `5` | **Period:** `1 minute`.
5. Set the **Action** to **Block** (or **Managed Challenge** if several staff members log in simultaneously from the same office IP).
6. Click **Deploy**.

##### **Rationale:**
**Targeted POST Filtering:** 
Filtering strictly by `Method equals POST` ensures that standard page loads (`GET /wp-login.php`) are never throttled, while rapid password-guessing submissions are neutralized at the edge, saving PHP-FPM workers and MariaDB from exhaustion.

## 2.1 Custom Security Rules (WAF Hardening & Enumeration Block)

##### **Objective:** 
Drop malicious probing requests targeting sensitive files (`wp-config.php`, `.env`, `.git`), legacy endpoints (`xmlrpc.php`, `wp-trackback.php`), and author archive enumeration (`/?author=1`) at the Cloudflare network edge.
##### **Action:**
1. In the Cloudflare dashboard, navigate to **Security** ➔ **Security Rules** ➔ **Custom rules**.
2. Click **Create rule** and name it `WP Hardening - Core & Enumeration Block`.
3. Under **If incoming requests match...**, configure the conditions using the Visual Expression Builder (connected with **Or**), or click **Edit expression** and paste the following verified expression directly:

```Plaintext
(http.request.uri.path contains "xmlrpc.php") or
(http.request.uri.path contains "wp-config.php") or
(http.request.uri.path contains "/.env") or
(http.request.uri.path contains "/.git") or
(http.request.uri.path contains "wp-trackback.php") or
(http.request.uri.query contains "author=" and not starts_with(http.request.uri.path, "/wp-admin"))
```

> [!Note]
> If using the GUI Builder instead of the raw expression, match `URI Path contains` for `xmlrpc.php`, `wp-config.php`, `/.env`, `/.git`, `wp-trackback.php`, and `URI Query String contains author=`

4. Under **Then take action...**, select **Block**.
5. Click **Deploy**.
##### **Rationale:**
**Defense in Depth (Edge vs. Origin):** 
Although we already blocked `xmlrpc.php`, `.env`, and `wp-config.php` inside Apache's `.htaccess` (Section 4.1), blocking them at the Cloudflare Edge drops automated vulnerability scanners before their packets ever reach our OVH server or consume container I/O.

**Stopping `?author=1` Scans Safely:** 
Attackers bypass REST API blocks by requesting `[https://yourdomain.com/?author=1](https://yourdomain.com/?author=1)`, causing WordPress to issue a `301 Redirect` to `/author/admin-username/`. Blocking `author=` in the query string stops this vector cold, while the `not starts_with(..., "/wp-admin")` exception ensures administrators can still filter posts by author inside the WordPress backend.
## 2.2 Edge Content Optimization & Compression

##### **Objective:** 
Leverage Cloudflare's native edge compression (Brotli / Zstandard) and safeguard WooCommerce checkout scripts from aggressive JavaScript rewrites.
##### **Action:**
1. Navigate to **Speed** ➔ **Optimization** ➔ **Content Optimization**.
2. Verify that **Rocket Loader** is set to **OFF**.
3. Enable **Early Hints** (`ON`) to allow browsers to preload CSS and fonts before the PHP container finishes generating the HTML response.
4. _(Note on Compression & Minification):_ You do not need to manually enable **Brotli** or **Auto Minify**. Cloudflare automatically applies **Brotli** and **Zstandard** compression to all eligible assets by default, and officially retired the legacy _Auto Minify_ feature in August 2024.
##### **Rationale:**
**Why Rocket Loader Must Stay OFF:** 
Rocket Loader asynchronously defers and rewrites all JavaScript tags on the page. In WooCommerce, this frequently breaks inline jQuery dependencies, dynamic checkout gateways (Stripe, PayPal, Przelewy24/BLIK), and cart calculation scripts.

**Native Brotli over PHP Plugins:** 
Allowing Cloudflare to compress static assets at the edge eliminates the need for heavy PHP minification plugins (like Autoptimize), which consume VPS CPU cycles and clutter the container filesystem with thousands of cached `.css`/`.js` files.

## 2.3 Dynamic WooCommerce Cache Bypass Rules

##### **Objective:** 
Guarantee that active shopping carts, checkout flows, customer account pages, and logged-in sessions always bypass Cloudflare's edge cache, preventing session leakage and stale cart states.
##### **Action:**
1. In the Cloudflare dashboard, navigate to **Caching** ➔ **Cache Rules**.
2. Click **Create rule** and name it `WooCommerce & WP Bypass Cache`.
3. Click **Edit expression** and paste the following comprehensive rule covering both WooCommerce session cookies and dynamic URL endpoints:

**Raw Expression (Alternative fast setup):**
```Plaintext
(http.cookie contains "woocommerce_items_in_cart") or
(http.cookie contains "wp_woocommerce_session_") or
(http.cookie contains "woocommerce_cart_hash") or
(http.cookie contains "wordpress_logged_in_") or
(http.cookie contains "wp-postpass_") or
(starts_with(http.request.uri.path, "/wp-admin")) or
(starts_with(http.request.uri.path, "/koszyk")) or (starts_with(http.request.uri.path, "/zamowienie")) or (starts_with(http.request.uri.path, "/moje-konto")) or
(starts_with(http.request.uri.path, "/cart")) or
(starts_with(http.request.uri.path, "/checkout")) or
(starts_with(http.request.uri.path, "/my-account")) or
(http.request.uri.query contains "wc-ajax=") or
(http.request.uri.query contains "add-to-cart=")
```

4. Scroll down to **Then... Cache eligibility** and select **Bypass cache**.
5. Click **Deploy**.

**Rationale:**
**Preventing Session & PII Leakage:** 
In e-commerce, caching HTML responses for users with active session cookies is a critical GDPR/RODO risk. If a customer adds an item to their cart or views the checkout page and Cloudflare caches that response, the next anonymous visitor could see the previous customer's cart contents or pre-filled billing details.

**Granular Cookie Matching:**
Anonymous visitors browsing the catalog without items in their cart have no WooCommerce cookies set and receive fast edge-cached assets, while the moment they add a product (`woocommerce_items_in_cart`) or log in (`wordpress_logged_in_`), requests route directly to our Redis-accelerated PHP container.

**Cookie + Path Redundancy:**
Relying solely on cookies can fail on a user's very first request to `/moje-konto` or during initial `/?wc-ajax=` calls before the browser establishes a session cookie. Combining cookie detection with explicit Polish and English WooCommerce checkout paths guarantees zero cache collisions.
## 2.4 Payment Gateways, Couriers & ERP Webhook Whitelisting (WAF Skip Rule)

 **Objective:** 
Prevent Cloudflare's anti-bot mechanisms from blocking asynchronous server-to-server webhooks sent by payment providers (Przelewy24, BLIK, PayU, Stripe) and external ERP/shipping integrations (BaseLinker, InPost).
##### **Action:**
1. Navigate to **Security** ➔ **Security Rules** ➔ **Custom rules**.
2. Click **Create rule** and name it `WooCommerce - Allow Payment & API Webhooks`.
3. Click **Edit expression** and paste:

```Plaintext
(http.request.uri.query contains "wc-api=") or
(starts_with(http.request.uri.path, "/wc-api/")) or
(starts_with(http.request.uri.path, "/wp-json/wc/v3/"))
```

4. Under **Then take action...**, select **Skip**.
5. Configure the **Skip options** (Check the following boxes):
    - **All remaining custom rules**
    - **Browser Integrity Check** (under _More components to skip_)
    - **Security Level** (under _More components to skip_)
6. Under **Place at**, select **First** (this rule must execute _before_ any blocking rules).
7. Click **Deploy**.

> [!Warning]
> **(Bot Fight Mode vs. Webhooks):** 
> On the Cloudflare Free Plan, the native _Bot Fight Mode_ cannot be bypassed via Custom WAF `Skip` rules. If you notice in **Security ➔ Events** that incoming webhooks from Przelewy24, Stripe, or BaseLinker are blocked by `Bot Fight Mode` (causing paid orders to remain stuck in _Pending Payment_), navigate to **Security ➔ Settings** and turn **Bot Fight Mode OFF**, relying instead on our custom WAF rules.
##### **Rationale:**
**Protecting Revenue Flow:** 
Payment gateways confirm transactions asynchronously via headless HTTP POST requests (`/?wc-api=WC_Gateway_...`). Because these servers do not execute JavaScript or send standard browser headers, Cloudflare's _Browser Integrity Check_ will drop them if not explicitly skipped, leading to unfulfilled paid orders.

## 2.5 SSL/TLS Strict Mode & Geo-Challenge for Admin Login
##### **Objective:** 
Prevent infinite HTTPS redirect loops between Cloudflare and Coolify's Traefik proxy, and neutralize foreign botnets attempting to access `/wp-login.php`.
##### **Action:**
###### Step 1: Enforce `Full (Strict)` SSL Mode
1. Navigate to **SSL/TLS** ➔ **Overview**.
2. Set the encryption mode strictly to **Full (Strict)**.
###### Step 2: Add Foreign Login Geo-Challenge
1. Navigate to **Security** ➔ **Security Rules** ➔ **Custom rules**.
2. Click **Create rule** and name it `WP Hardening - Foreign Login Challenge`.
3. Click **Edit expression** and paste:

```Plaintext
(http.request.uri.path contains "/wp-login.php" or starts_with(http.request.uri.path, "/wp-admin")) and
(ip.src.country ne "PL") and
(not starts_with(http.request.uri.path, "/wp-admin/admin-ajax.php"))
```

4. Under **Then take action...**, select **Managed Challenge**.
5. Click **Deploy**.

##### **Rationale:**
**Eliminating Redirect Loops (`ERR_TOO_MANY_REDIRECTS`):** 
Coolify's Traefik reverse proxy automatically provisions valid Let's Encrypt certificates on the origin server. If Cloudflare is left on `Flexible` SSL, it sends unencrypted HTTP requests to the origin, while WordPress (`FORCE_SSL_ADMIN`) continually redirects back to HTTPS. `Full (Strict)` ensures end-to-end encrypted communication.

**Geo-Challenge Efficiency:** 
Challenging non-Polish IPs on `/wp-login.php` stops 99% of global automated brute-force traffic at the edge while still allowing the store owner to log in while traveling abroad by completing a quick Turnstile verification. Note that `/wp-admin/admin-ajax.php` is explicitly excluded so foreign customers can still use frontend AJAX filters and cart actions.