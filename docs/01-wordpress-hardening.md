## 1.0 Web Server Hardening (`.htaccess` Rules)

##### **Objective:** 
Block direct HTTP access to sensitive configuration files, disable directory browsing, and neutralize the `xmlrpc.php` brute-force vector at the Apache web server layer before PHP is even invoked.
##### **Action:**
###### Step 1: Harden the Root `.htaccess` File
Access the WordPress root directory (`/var/www/html/`) via the Coolify container terminal and append the following rules to the top of the main `.htaccess` file:

```Apache
# 1. Protect wp-config.php
<Files wp-config.php>
  Require all denied
</Files>

# 2. Block XML-RPC (Brute-force & DDoS amplification vector)
<Files xmlrpc.php>
  Require all denied
</Files>

# 3. Disable Directory Listing
Options -Indexes

# 4. Block version-leaking documentation and sample files
<FilesMatch "^(readme\.html|license\.txt|wp-config-sample\.php)$">
  Require all denied
</FilesMatch>

# 5. Block access to hidden dotfiles (e.g., .env, .git, .htaccess)
<FilesMatch "^\.">
  Require all denied
</FilesMatch>

# 6. Block direct PHP execution inside wp-includes (persists across Docker image updates) 
<IfModule mod_rewrite.c> 
RewriteEngine On 
RewriteBase / RewriteRule ^wp-admin/includes/ - [F,L]
RewriteRule !^wp-includes/ - [S=3]
RewriteRule ^wp-includes/[^/]+\.php$ - [F,L] 
RewriteRule ^wp-includes/js/tinymce/langs/.+\.php - [F,L] 
RewriteRule ^wp-includes/theme-compat/ - [F,L] 
</IfModule>

# 7. Enforce Application Security Headers (HSTS and nosniff are handled by Cloudflare) 
<IfModule mod_headers.c>
 Header always set X-Frame-Options "SAMEORIGIN"
 Header always set X-XSS-Protection "0"
 Header always set Referrer-Policy "strict-origin-when-cross-origin"
 Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(self)" 
 Header always set Content-Security-Policy "upgrade-insecure-requests;" </IfModule>
```

###### Step 2: Disable Script Execution in `/uploads` 
1. Create or edit `/var/www/html/wp-content/uploads/.htaccess` and insert:

```Apache
Options -Indexes

<FilesMatch "\.(php|php\.|php3|php4|php5|php7|php8|phtml|pl|py|jsp|asp|htm|shtml|sh|cgi)$">
  Require all denied
</FilesMatch>
```

###### Step 3: Lock Down `wp-config.php` File Permissions
Execute the following commands inside the WordPress container terminal to ensure only the `www-data` web server process can read the configuration file:

```Bash
chown www-data:www-data /var/www/html/wp-config.php
chmod 600 /var/www/html/wp-config.php
```
##### **Rationale:**
**Killing `xmlrpc.php`:** 
Legacy XML-RPC allows attackers to test hundreds of passwords in a single HTTP request using the `system.multicall` method, bypassing standard login rate limits.

**Directory Execution Locks:** 
The `wp-content/uploads/` folder must be writable by the web server so admins can upload product images. If a vulnerable plugin allows an attacker to upload a malicious `.php` webshell into `/uploads/`, the local `.htaccess` rule forces Apache to return `403 Forbidden` instead of executing the payload.

**`payment=(self)` in Permissions-Policy:**
Unlike standard blogs where `payment=()` can be completely disabled, WooCommerce requires `payment=(self)` so modern checkout integrations (Apple Pay, Google Pay, Stripe Elements, BLIK iframes) are not blocked by the browser.

## 1.1 Cryptographic Salts & Core Update Lockdowns (Docker Compose)

##### **Objective:** 
Inject static cryptographic salts, suppress PHP error leakage in production, and restrict automatic core updates strictly to minor security patches via Coolify environment variables.
##### **Action:**
1. Generate a fresh set of cryptographic keys and salts from the official WordPress API (`[https://api.wordpress.org/secret-key/1.1/salt/](https://api.wordpress.org/secret-key/1.1/salt/)`).
2. In Coolify, open your WordPress resource, click **Edit Compose File**, and append the update, error-suppression, and salt parameters to your `environment` block:

```YAML
    environment:
      # ... (keep existing database variables)
	  WORDPRESS_AUTH_KEY: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_SECURE_AUTH_KEY: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_LOGGED_IN_KEY: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_NONCE_KEY: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_AUTH_SALT: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_SECURE_AUTH_SALT: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_LOGGED_IN_SALT: 'paste_unique_64_char_random_string_here'
	  WORDPRESS_NONCE_SALT: 'paste_unique_64_char_random_string_here'
      WORDPRESS_CONFIG_EXTRA: |
        # ... (keep existing memory, SSL, cron, and Redis constants)
        define('WP_AUTO_UPDATE_CORE', 'minor');
        @ini_set('display_errors', 0);
```

> [!Warning] 
> **Important (Existing Installations):** 
> If WordPress is already initialized, its `wp-config.php` file already contains auto-generated salts at the bottom of the file. Before redeploying with custom salts in `WORDPRESS_CONFIG_EXTRA`, remove `/var/www/html/wp-config.php` (or remove the duplicate `define()` lines inside it) and click **Deploy** so the container cleanly regenerates the file without throwing `Constant AUTH_KEY already defined` warnings.

> [!Danger]
> **Incident Response:** 
> In the event of a security breach, immediately rotate these 8 salt values and redeploy the container. This instantly invalidates all active session cookies and forces every logged-in user (and attacker) to re-authenticate.
##### **Rationale:**
**Minor Updates Only (`WP_AUTO_UPDATE_CORE`):** 
Major WordPress releases frequently introduce breaking changes to WooCommerce templates and payment plugins. Restricting auto-updates to `minor` ensures zero-day security patches (e.g., `6.7.1` to `6.7.2`) install automatically while major upgrades remain controlled via container image pinning (monitored via Diun in `vps-linux-hardening-paas/docs/03-monitoring-alerts.md`).

**Suppressing `display_errors`:** 
Publicly rendered PHP warnings leak full server directory paths and database schema structures, giving attackers reconnaissance data.
## 1.2 Must-Use Hardening & Performance Plugin (`mu-plugins`)

##### **Objective:** 
Deploy a permanent, non-deactivatable Must-Use (MU) plugin to block REST API username enumeration, mask login error verbosity, strip WordPress version fingerprints, and optimize WooCommerce database and AJAX overhead.
##### **Action:**
1. Open the WordPress container terminal in Coolify.
2. Create the `mu-plugins` directory if it does not already exist, and open a new plugin file:

```Bash
mkdir -p /var/www/html/wp-content/mu-plugins
nano /var/www/html/wp-content/mu-plugins/wp-hardening.php
```

3. Paste the following verified PHP code into the file:

```php
<?php
/*
Plugin Name: WP Hardening & WooCommerce Optimizer (MU-Plugin) Description: Blocks REST API user enumeration, masks login errors, strips version fingerprints, trims Action Scheduler bloat, and prevents page caching for active carts. Version: 1.1.0
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. Block Unauthenticated REST API User Enumeration
add_filter( 'rest_endpoints', function( $endpoints ) {
    if ( ! is_user_logged_in() ) {
        if ( isset( $endpoints['/wp/v2/users'] ) ) {
            unset( $endpoints['/wp/v2/users'] );
        }
        if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
            unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
        }
    }
    return $endpoints;
});

// 2. Mask Verbose Login Errors (Prevent Username Discovery)
add_filter( 'login_errors', function() {
    return 'Nieprawidłowa nazwa użytkownika lub hasło.';
});

// 3. Strip WordPress Version Fingerprints & oEmbed Links
remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

function inogi_remove_wp_version_from_assets( $src ) {
    if ( strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) ) {
        $src = remove_query_arg( 'ver', $src );
    }
    return $src;
}
add_filter( 'style_loader_src', 'inogi_remove_wp_version_from_assets', 9999 );
add_filter( 'script_loader_src', 'inogi_remove_wp_version_from_assets', 9999 );

// 4. Trim WooCommerce Action Scheduler Log Retention to 3 Days (Default is 30)
add_filter( 'action_scheduler_retention_period', function() {
    return DAY_IN_SECONDS * 3;
});

// 5. Bypass WP Super Cache When WooCommerce Cart or Session Cookies Exist
add_action( 'init', function() {
 if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) ) {
  if ( ! defined( 'DONOTCACHEPAGE' ) ) {
   define( 'DONOTCACHEPAGE', true ); 
   } 
 } 
}, 1 );
```

4. Ensure proper ownership of the MU-plugin:

```Bash
chown -R www-data:www-data /var/www/html/wp-content/mu-plugins
```

##### **Rationale:**
**Why `mu-plugins`?** 
Must-Use plugins execute automatically on every request and cannot be accidentally deactivated by clients or sub-administrators in the WordPress UI.

**Neutralizing User Enumeration:** 
By default, WordPress reveals whether an account exists during failed logins (_"Invalid password for user admin"_) and exposes all usernames publicly at `/wp-json/wp/v2/users`. Blocking both forces attackers to guess both the username and the password blindly.

**Action Scheduler Cleanup:**
WooCommerce logs every background webhook, stock sync, and cron job in `wp_actionscheduler_actions` and `wp_actionscheduler_logs`. Reducing retention from 30 days to 3 days keeps the MariaDB database lean and backups fast.

## 1.3 Two-Factor Authentication (2FA) Enforcement

##### **Objective:**
Protect administrator and shop manager accounts against credential stuffing and phishing by requiring a second verification factor at login.
##### **Action:**
1. Log into the WordPress Admin Dashboard (`[https://yourdomain.com/wp-admin](https://yourdomain.com/wp-admin)`).
2. Navigate to **Plugins** ➔ **Add New Plugin** and install **Two Factor** (by WordPress.org Contributors).
3. Activate the plugin and navigate to **Users** ➔ **Profile**.
4. Scroll to the **Two-Factor Options** section and configure **Time Based One-Time Password (TOTP)** using an authenticator app (e.g., Proton Pass, Bitwarden, or Ente Auth) or register your hardware **YubiKey (FIDO U2F / WebAuthn)** if supported.
5. Generate and save the **Backup Verification Codes** in your password manager.
6. Set your primary method as default and click **Update Profile**.

##### **Rationale:**
**Defense in Depth:** 
Even if an attacker bypasses Cloudflare Rate Limiting (`vps-linux-hardening-paas/docs/05-cloudflare-waf-security.md`) and compromises an administrator password via malware on a client's computer, they cannot access `/wp-admin` or export customer order data without the physical 2FA token.