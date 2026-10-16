## 4.0 Automated Database Hygiene (Garbage Collection)

##### **Objective:** 
Prevent the MariaDB database from degrading over time due to accumulated expired transients, orphaned WooCommerce session data, trashed items, and old post revisions.
##### **Action:**
1. Log into the WordPress Admin Dashboard (`[https://yourdomain.com/wp-admin](https://yourdomain.com/wp-admin)`).
2. Navigate to **Plugins** ➔ **Add New Plugin**, search for **WP-Optimize**, install, and activate it.

> [!Danger] 
> **Disable Caching & Image Compression Modules!**
> Immediately verify that the **Cache**, **Images**, and **Minify** tabs inside WP-Optimize remain completely **DISABLED**. Full-page caching is already handled by **WP Super Cache** (Section 3.3), and asset compression is handled by **Cloudflare Edge** (Section 2.2). Enabling a second caching or minification engine will cause severe race conditions, broken layouts, and session leaks. Use WP-Optimize strictly as a database cleanup utility.

3. Navigate to **WP-Optimize** ➔ **Database** ➔ **Settings** (top menu tab).
4. Under **General settings**, check **Keep last 2 weeks data** _(prevents the scheduler from deleting active auto-drafts or recent revisions while an administrator is editing a product)_.
5. Scroll down to **Auto clean-up settings** and check **Enable scheduled clean-up and optimization**.
6. Select the schedule frequency (e.g., **Weekly**) and check the following tasks:
    - `Optimize database tables`
    - `Clean all post revisions` _(respects the 2-week retention window)_
    - `Clean all auto-draft posts`
    - `Clean all trashed posts`
    - `Remove spam and trashed comments`
    - `Remove expired transient options`
7. Click **Save settings**.
##### **Rationale:**
**Transient & Session Bloat:** 
WooCommerce and third-party plugins heavily utilize the `wp_options` table to store temporary cached data (_transients_). When expired transients are not purged, `wp_options` swells to hundreds of thousands of rows, slowing down every uncached request.

**Why Keep a Retention Window:** 
Running an aggressive cleanup without a retention window (`Keep last 2 weeks data`) risks deleting an active `auto-draft` while a shop manager is in the middle of creating a new product listing.

## 4.1 Transactional Email (FluentSMTP) & Security Audit Logging (Simple History)

##### **Objective:** 
Configure reliable, authenticated SMTP delivery for WooCommerce order emails without storing plaintext SMTP passwords in the MariaDB database, and enable automated weekly audit reports tracking all administrative actions.
##### **Action:**
###### Step 1: Install Required Plugins
1. In the WordPress Admin Dashboard, navigate to **Plugins** ➔ **Add New Plugin**.
2. Search for, install, and activate both plugins:
    - **FluentSMTP** _(Handles transactional WooCommerce emails and system notifications)_.
    - **Simple History** _(Tracks user logins, failed brute-force attempts, plugin changes, product edits, and core file integrity)_.
###### Step 2: Obtain FluentSMTP `wp-config.php` Constants
1. Navigate to **Settings** ➔ **FluentSMTP**.
2. Select your transactional email provider (e.g., **Other SMTP** for standard mailbox hosting, or a dedicated API provider like Brevo, Amazon SES, or Postmark).
3. Fill in the non-sensitive sender and server details (**From Email**, **From Name**, **SMTP Host**, **SMTP Port**, **Encryption**).
4. Scroll down to the authentication section and select the radio button: **Store Access Keys in Config File** (`Store in wp-config.php`).
5. Once selected, the password input fields will disappear and FluentSMTP will display the exact `define()` constants required for your chosen provider (for example, `FLUENTMAIL_SMTP_USERNAME` and `FLUENTMAIL_SMTP_PASSWORD` for _Other SMTP_).
6. Copy those `define()` lines to your clipboard **and keep this browser tab open**.
###### Step 3: Inject SMTP Credentials via Coolify Environment Variables
1. Open your Coolify dashboard, navigate to your WordPress resource, and click **Edit Compose File**.
2. Locate the `WORDPRESS_CONFIG_EXTRA` variable under `environment:` and append your copied FluentSMTP constants, replacing the placeholders with your actual SMTP credentials:

```YAML
    environment:
      WORDPRESS_CONFIG_EXTRA: |
        # ... (keep all existing constants: Redis, memory limits, salts, etc.)
        # FluentSMTP Credentials (Other SMTP example):
        define('FLUENTMAIL_SMTP_USERNAME', 'shop@yourdomain.com');
        define('FLUENTMAIL_SMTP_PASSWORD', 'your_secure_smtp_password');
```

> [!Note]
> If using an API provider like Amazon SES, SendGrid, or Brevo, paste the exact provider constant shown by FluentSMTP in Step 2, such as `define('FLUENTMAIL_SENDGRID_API_KEY', '...');`).

3. Click **Save** and **Deploy** (restart) the WordPress container so PHP loads the new constants into `wp-config.php`.

###### Step 4: Finalize & Verify FluentSMTP Connection
1. Return to your open WordPress browser tab (from Step 2) and click **Save Connection Settings** at the bottom of the FluentSMTP form. _(Because "Store in wp-config.php" is selected, FluentSMTP saves only the host/port configuration to the database and reads the secret credentials directly from your container environment)_.
2. Navigate to **FluentSMTP** ➔ **Email Test**, send a test email to your administrative address, and verify receipt.
###### Step 5: Configure Simple History Weekly Audit Reports
Now that outbound SMTP delivery is operational, enable automated activity digests:
1. Navigate to **Simple History** ➔ **Settings** ➔ **Email Reports**.
2. Check **Enable weekly email reports**.
3. Enter the administrator or agency monitoring email address in the **Recipients** field.
4. Click **Preview email** or send a test report to confirm formatting, then click **Save Changes**.

##### **Rationale:**
**Why Containers Need Dedicated SMTP:** 
Docker containers do not run local Mail Transfer Agents (like Postfix or Exim), and cloud VPS IP ranges (like OVH) are frequently blocked by Gmail and Outlook spam filters when attempting direct port-25 delivery. Routing emails through FluentSMTP over authenticated TLS guarantees that WooCommerce order confirmations and password resets reach the customer's inbox.

**Credential Isolation (`Store in wp-config.php`):**
By default, WordPress SMTP plugins store mail passwords inside the `wp_options` database table. If an attacker exploits a read-only SQL Injection vulnerability in a third-party plugin, or if a database dump is shared with an external freelance developer, database-stored SMTP credentials can be extracted to send phishing campaigns from your domain. Injecting them via Coolify's `WORDPRESS_CONFIG_EXTRA` keeps secrets strictly in the container runtime configuration.

**Non-Repudiation & Core Integrity (Simple History):** 
In an agency environment where both engineers and store owners have backend access, _Simple History_ provides an immutable audit trail of who changed product prices, deactivated a plugin, or logged in from an unfamiliar IP, while its built-in _Core Files Integrity Logger_ automatically verifies WordPress core checksums every night.