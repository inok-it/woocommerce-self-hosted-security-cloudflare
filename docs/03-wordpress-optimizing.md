## 3.1. Revision Limiting & Autosave Interval (Docker Compose)

##### **Objective:** 
Stop WordPress from inflating the MariaDB database with hundreds of redundant post revisions and reduce CPU wakeups during content editing.

##### **Action:**
1. Open your WordPress resource in Coolify and click **Edit Compose File**.
2. Append the following constants to your existing `WORDPRESS_CONFIG_EXTRA` environment block:

```YAML
    environment:
      WORDPRESS_CONFIG_EXTRA: |
        # ... (keep all previous constants: memory limits, Redis, security salts, etc.)
        define('WP_POST_REVISIONS', 5);
        define('AUTOSAVE_INTERVAL', 300);
        define('WP_CACHE', true);
```

3. Save the compose file and click **Deploy**.

##### **Rationale:**
**Database Bloat Control:** 
By default, WordPress saves an unlimited history of every revision every time you click "Save Draft". For pages edited dozens of times, this creates thousands of bloated rows in `wp_posts`, slowing down database queries. Limiting revisions to `5` keeps history manageable.

**Extended Autosave:** 
Changing `AUTOSAVE_INTERVAL` from 60 seconds to 300 seconds (5 minutes) drastically reduces background HTTP requests and database write operations while editing products.

## 3.2. PHP Resource Limits & OPCache Tuning (Volume Mapping)

##### **Objective:** 
Allocate dedicated memory to PHP's bytecode cache (OPCache) and expand execution limits to support large WooCommerce product imports and file uploads, using immutable configuration files mounted directly from the host.
##### **Action:**
###### Step 1: Create Custom PHP `.ini` Files on the Host Server

1. Log into your VPS via SSH.
2. Create a dedicated configuration directory inside `/opt/coolify/` (or your preferred provisioning path):

```Bash
sudo mkdir -p /opt/coolify/wordpress-conf
```

3. Create and populate the OPCache custom configuration file:

```Bash
sudo nano /opt/coolify/wordpress-conf/opcache-custom.ini
```

Paste the following parameters and save (`Ctrl+O`, `Enter`, `Ctrl+X`):

```Ini, TOML
[opcache]
opcache.enable=1
opcache.memory_consumption=384
opcache.interned_strings_buffer=64
opcache.max_accelerated_files=32531
opcache.revalidate_freq=60
opcache.validate_timestamps=1
opcache.save_comments=1
opcache.max_wasted_percentage=5
```

4. Create and populate the PHP limits and upload configuration file:

```Bash
sudo nano /opt/coolify/wordpress-conf/uploads.ini
```

Paste the following corrected parameters and save (`Ctrl+O`, `Enter`, `Ctrl+X`):

```Ini, TOML
file_uploads = On
memory_limit = 512M
upload_max_filesize = 64M
post_max_size = 64M
max_execution_time = 300
max_input_vars = 5000
```

###### Step 2: Mount `.ini` Files via Docker Compose
1. In the Coolify dashboard, open your WordPress resource and click **Edit Compose File**.
2. Locate the `volumes:` section and append read-only mounts (`:ro`) pointing to our newly created host `.ini` files:

```YAML
    volumes:
      - 'wordpress-files:/var/www/html'
      - '/opt/coolify/wordpress-conf/opcache-custom.ini:/usr/local/etc/php/conf.d/opcache-custom.ini:ro'
      - '/opt/coolify/wordpress-conf/uploads.ini:/usr/local/etc/php/conf.d/uploads.ini:ro'
```

3. Save the compose file and click **Deploy** to restart the container with the new PHP configuration.

##### **Rationale:**
**OPCache Bytecode Caching:** 
PHP is an interpreted language; by default, it compiles script files into bytecode on every single request. OPCache stores this compiled bytecode directly in RAM (`384MB`), eliminating disk I/O and dropping PHP execution times by up to 70%.

**Read-Only Volume Mounts (`:ro`):** 
Mounting configuration files directly into `/usr/local/etc/php/conf.d/` ensures that container updates or rebuilds will never wipe out our custom PHP performance tunings.

## 3.3 Page Caching Implementation (WP Super Cache)

##### **Objective:** 
Install and configure WP Super Cache to serve pre-rendered static HTML files to anonymous visitors, bypassing PHP and database execution entirely for lightning-fast Time to First Byte (TTFB).
##### **Action:**
###### Step 1: Installation & Basic Setup

1. Log into your WordPress Admin Dashboard (`[https://yourdomain.com/wp-admin](https://yourdomain.com/wp-admin)`).
2. Navigate to **Plugins** ➔ **Add New Plugin**, search for **WP Super Cache**, install, and activate it.
3. Navigate to **Settings** ➔ **WP Super Cache**.
4. In the **Easy** tab, select **Caching On (Recommended)** and click **Update Status**.
###### Step 2: Advanced Configuration & E-Commerce Cart Protection
1. Switch to the **Advanced** tab and configure the following parameters:
    - **Cache Delivery Method:** Select **Simple**.
    - **Miscellaneous:** Check the following boxes:
        - `Don't cache pages for known users.` _(Critical: ensures logged-in admins and active customers never see cached generic pages)._
        - `Cache rebuild.` _(Serves the old static cache file while a new one is being generated in the background, preventing downtime)._

> [!Note]
> Leave `Compress pages so they're served more quickly to visitors` **UNCHECKED**. Cloudflare already applies superior Brotli/Zstandard compression at the network edge; enabling GZIP inside WP Super Cache wastes VPS CPU cycles and risks double-compression encoding errors.

###### Step 3: Configure Rejected URL Strings (Crucial for WooCommerce)

Scroll down on the **Advanced** tab to the **Rejected URL Strings** section. You must explicitly exclude all dynamic WooCommerce transactional endpoints from being cached.

> [!Danger] 
> **Match Your Store's Actual Slugs (Polish vs. English)**
> Default tutorials often list English slugs like /cart/ or /checkout/. If your WooCommerce store uses Polish permalinks, you MUST enter your store's exact custom slugs. Caching a dynamic page like /koszyk/ will leak private customer data and cart contents between buyers.

In the **Rejected URL Strings** text area, enter each path on a new line (adjust these to match your actual store setup):

```Plaintext
/koszyk/
/zamowienie/
/moje-konto/
/cart/
/checkout/
/my-account/
```

2. Scroll to the bottom and click **Update Status**.

###### Step 4: Verify Cache Operation

1. Open your store in a **Private Window / Incognito** (ensuring you are logged out of WordPress).
2. Right-click anywhere on the homepage and select **View Page Source**. 
3. Scroll down to the very last line of the HTML document. Look for the generation comment injected by the caching engine:
```HTML
<!-- Cached page generated by WP-Super-Cache on 2026-10-15 10:23:00 -->
<!-- super cache -->
```

##### **Rationale:**
**Defense in Depth (Redis + Page Cache):** 
Redis Object Cache accelerates database queries for dynamic elements, while WP Super Cache eliminates PHP processing completely for static catalog pages and blog posts. Together, they allow a modest VPS instance to handle thousands of concurrent visitors effortlessly.

 **Rejected URL Enforcement:** 
 E-commerce stores cannot afford generic page caching on transactional routes. Explicitly rejecting checkout and cart paths guarantees that dynamic inventory checks, tax calculations, and cart session tokens evaluate fresh on every request.