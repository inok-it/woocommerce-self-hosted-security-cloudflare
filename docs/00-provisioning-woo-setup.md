## 0.0 Dedicated Database Provisioning (MariaDB)

##### **Objective:** 
Deploy an isolated MariaDB container within Coolify's internal network and manually initialize the schema and privileges for the WordPress installation.
##### **Action:**
###### Step 1: Deploy the MariaDB Resource
1. Log into the Coolify dashboard and navigate to your target Project and Environment (e.g., `Production` or `Staging`).
2. Click **+ Add Resource** ➔ **Database** ➔ **MariaDB**.
3. Review the automatically generated credentials on the configuration screen and note down the following:
    - `Database Normal User` (typically defaults to `mariadb`)
    - `Database Normal User Password`
    - `Database Root Password`

> [!Note] 
> Keep the Database Root Password open or save it directly to your password manager. You will need it immediately in Step 2 to initialize the database privileges via the container terminal.

4. Click **Deploy** to spin up the database container inside the internal Docker network.
###### Step 2: Initialize the WordPress Schema
1. Once the MariaDB container status changes to `Running`, navigate to the **Terminal** tab within the MariaDB resource view in Coolify.
2. Authenticate into the MariaDB monitor as `root` (replace `<YOUR_ROOT_PASSWORD>` with the generated password, keeping the single quotes):

```Bash
mariadb -uroot -p'<YOUR_ROOT_PASSWORD>'
```

3. Execute the following SQL statements to create the database and bind full privileges to the standard database user:

```SQL
CREATE DATABASE wordpress;
GRANT ALL PRIVILEGES ON wordpress.* TO 'mariadb'@'%';
FLUSH PRIVILEGES;
SHOW DATABASES;
```

4. Type `exit` to close the database shell.
###### Step 3: Enable Off-Site Backups
Before proceeding to the application layer, immediately configure automated nightly backups to Cloudflare R2 for this database container.

**Reference:** 
Follow the exact procedure outlined in **`vps-linux-hardening-paas/docs/04-backup-recovery-policy.md` (Section 4.4: Application & Database Backups)**. Remember to stagger the Cron expression by 1 minute relative to existing backups.
##### **Rationale:**
**Single-Tenant Isolation:** 
Running a dedicated MariaDB container per WooCommerce client ensures that heavy database locks (e.g., during massive product imports or seasonal traffic spikes) only impact that specific container, leaving other client databases untouched.

**Explicit Privilege Granting (`'mariadb'@'%'`):** 
Because Docker containers communicate across internal virtual bridges using dynamic IP addresses, binding the user to `'%'` (any host within the isolated Docker network) prevents host-resolution access denied errors when the WordPress container restarts and receives a new internal IP.


## 0.1 WordPress Container Deployment & Native Cron Offloading

##### **Objective:** 
Deploy a standalone WordPress container with hardened PHP/WP constants, link it to the isolated MariaDB container, and replace the inefficient native WordPress pseudo-cron with a true system-level cron managed by Coolify.
##### **Action:**
###### Step 1: Configure the WordPress Service
1. In your Coolify environment, click **+ Add Resource** ➔ **Service** (or **1-Click App**).
2. Search for and select **WordPress (without database)**.
3. **Version Pinning:** Change the image tag from `latest` to a specific, current version number (e.g., `6.7.2` or `7.1.2`). **Never use the `latest` tag in production.**

    - _Reference:_ Container image updates will be tracked safely via notifications configured in **`vps-linux-hardening-paas/docs/03-monitoring-alerts.md` (Diun)**.
    
4. In the **General** tab, set your target domain in the **Domains** field (e.g., `[https://store.yourdomain.com](https://store.yourdomain.com)`).

    - _Reference:_ Ensure the domain is properly proxied through Cloudflare with strict SSL settings as defined in **`vps-linux-hardening-paas/docs/05-cloudflare-waf-security.md`**.
    
5. Scroll down to the **Network** section and check the box: **Connect to Predefined Network**.
###### Step 2: Inject Environment Variables & Hardening Constants
1. Click on **Edit Compose File** in the WordPress resource settings.
2. Locate the `environment:` block and update it to match the structure below. Ensure proper YAML indentation (2 spaces) and replace `<MARIADB_CONTAINER_NAME>`, `<NORMAL_USER_PASSWORD>`, and `[https://yourdomain.com](https://yourdomain.com)` with your actual values:

```YAML
    environment:
      SERVICE_URL_WORDPRESS: null
      WORDPRESS_DB_HOST: <MARIADB_CONTAINER_NAME>
      WORDPRESS_DB_USER: mariadb
      WORDPRESS_DB_PASSWORD: <NORMAL_USER_PASSWORD>
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_TABLE_PREFIX: 'sklep_'
      WORDPRESS_CONFIG_EXTRA: |
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) { $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP']; 
        }
        define('FORCE_SSL_ADMIN', true);
        define('DISALLOW_FILE_EDIT', true);
        define('WP_MEMORY_LIMIT', '256M');
        define('WP_MAX_MEMORY_LIMIT', '512M');
        define('WP_HOME', 'https://yourdomain.com');
        define('WP_SITEURL', 'https://yourdomain.com');
        define('CORE_UPGRADE_SKIP_NEW_BUNDLED', true);
        define('EMPTY_TRASH_DAYS', 7);
        define('MEDIA_TRASH', true);
        define('DISABLE_WP_CRON', true);
```

3. Save the Compose file and click **Deploy**.
4. Once deployed, open `[https://yourdomain.com](https://yourdomain.com)` in your browser, complete the initial WordPress installation wizard, and create your administrator account.
5. After installing WooCommerce, navigate to **WooCommerce** ➔ **Settings** ➔ **Advanced** ➔ **Features** and verify that **High-Performance Order Storage (HPOS)** is enabled (with Compatibility Mode disabled).
###### Step 3: Configure Server-Level Scheduled Tasks (WP-Cron)
Because we explicitly disabled the default visitor-triggered cron (`DISABLE_WP_CRON`), we must configure Coolify to execute scheduled WooCommerce tasks (like subscription renewals, stock holds, and webhook deliveries) directly via the PHP CLI.

1. Inside your WordPress resource in Coolify, navigate to the **Scheduled Tasks** tab on the left menu.
2. Click **+ Add** and configure the task:
    - **Name:** `WP Cron (WooCommerce)`
    - **Command:** `php /var/www/html/wp-cron.php`
    - **Schedule (Cron expression):** `*/5 * * * *` _(Executes every 5 minutes)_
3. Click **Save**.
##### **Rationale:**
**Predefined Network Bridging:**
By default, Coolify isolates each resource into its own Docker network stack. Enabling _Connect to Predefined Network_ attaches the WordPress container to the primary `coolify` bridge network, allowing it to resolve the standalone MariaDB and Redis containers via internal Docker DNS names.

**Custom Table Prefix (`sklep_`):** 
Changing the default `wp_` prefix mitigates automated, blind SQL-injection scripts that hardcode standard table names like `wp_users`.

**Security & Performance Constants (`WORDPRESS_CONFIG_EXTRA`):**
- `DISALLOW_FILE_EDIT`: Disables the built-in Theme and Plugin code editors inside the WP Admin dashboard. If an attacker compromises an admin account, they cannot easily inject PHP backdoors through the UI.

- `WP_MEMORY_LIMIT` (`256M` / `512M` for Admin): WooCommerce is memory-intensive. Default PHP limits (often 40M–64M) cause fatal `Out of Memory` errors during checkout or complex product filtering.

- `CORE_UPGRADE_SKIP_NEW_BUNDLED`: Prevents WordPress from reinstalling bloated default themes (e.g., _Twenty Twenty-Four_) and the _Hello Dolly_ plugin during core updates.

**CLI Cron vs. Native WP-Cron:**
Native `wp-cron.php` triggers on page loads. On low-traffic sites, scheduled jobs miss their deadlines; on high-traffic WooCommerce stores, multiple visitors trigger overlapping HTTP cron requests, starving PHP workers. Running `php /var/www/html/wp-cron.php` via Coolify's internal task scheduler executes inside the isolated container over the CLI—completely bypassing HTTP timeouts, Cloudflare WAF blocks, and web server overhead.

## 0.2 Redis Object Caching Integration

##### **Objective:** 
Deploy an in-memory Redis data store to cache complex WooCommerce database queries, drastically reducing Time to First Byte (TTFB) and MariaDB CPU load.
##### **Action:**
###### Step 1: Provision the Redis Container
1. Return to your Coolify Project environment view.
2. Click **+ Add Resource** ➔ **Database** (or **Service**) ➔ **Redis**.
3. Deploy the Redis instance and immediately copy the generated **Redis Password** and the **Container Name** (e.g., `redis-xyz123`).
###### Step 2: Link Redis to WordPress Compose Configuration
1. Open your **WordPress** resource in Coolify and click **Edit Compose File**.
2. Append the Redis connection constants directly to the bottom of your existing `WORDPRESS_CONFIG_EXTRA` block.

> [!Warning]
> **Do Not Overwrite Existing Constants**
> Ensure you append the Redis lines to your existing WORDPRESS_CONFIG_EXTRA block rather than replacing the entire variable. Replacing the block will wipe out your memory limits, security lockdowns, and cron settings from Section 2.

Your complete, unified `environment:` block should look exactly like this (replace `<REDIS_CONTAINER_NAME>` and `<REDIS_PASSWORD>` with your actual values):

```YAML
    environment:
      SERVICE_URL_WORDPRESS: null
      WORDPRESS_DB_HOST: <MARIADB_CONTAINER_NAME>
      WORDPRESS_DB_USER: mariadb
      WORDPRESS_DB_PASSWORD: <NORMAL_USER_PASSWORD>
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_TABLE_PREFIX: 'sklep_'
      WORDPRESS_CONFIG_EXTRA: |
        #... (keep existing constants)
        define('WP_REDIS_HOST', '<REDIS_CONTAINER_NAME>');
        define('WP_REDIS_PASSWORD', '<REDIS_PASSWORD>');
        define('WP_REDIS_PORT', 6379);
		define('WP_REDIS_PREFIX', 'sklep_');
		define('WP_REDIS_MAXTTL', 86400);
```

3. Save the configuration and click **Deploy** (or **Restart**) to inject the new environment variables into `wp-config.php`.

###### Step 3: Activate the Drop-in Plugin
1. Log into your WordPress Admin Dashboard (`[https://yourdomain.com/wp-admin](https://yourdomain.com/wp-admin)`).
2. Navigate to **Plugins** ➔ **Add New Plugin**.
3. Search for **Redis Object Cache** (by Till Krüss).
4. Click **Install Now**, then **Activate**.
5. Navigate to **Settings** ➔ **Redis** and click the **Enable Object Cache** button.
6. _Verification:_ Confirm that the status indicator turns green and displays **Connected**
##### **Rationale:**
**Overcoming the WooCommerce Bottleneck:** 
Unlike static blogs, WooCommerce cannot rely purely on full-page HTML caching because cart contents, user sessions, and dynamic pricing vary per visitor. Every uncached page load triggers dozens of heavy SQL `JOIN` queries against `wp_postmeta` and `wp_options`.

**In-Memory Speed:** 
Redis intercepts these repetitive SQL queries and stores the resulting PHP objects directly in RAM. Subsequent requests fetch the data from memory in sub-milliseconds rather than hitting the disk-bound MariaDB container, effortlessly scaling the store's concurrency capacity.