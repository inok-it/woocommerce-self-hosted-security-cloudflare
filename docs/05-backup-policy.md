## 5.0 WooCommerce Backup & Disaster Recovery Strategy

This module defines the multi-tiered backup architecture for a Single-Tenant WooCommerce deployment. Because e-commerce environments process live financial transactions, dynamic inventory changes, and uploaded customer invoices, backups must cover three distinct layers: application volumes, isolated relational databases, and host-level VM snapshots.

> [!Note] 
> **Prerequisite Reference:** 
> Before executing the steps below, ensure that the **Cloudflare R2 S3 Storage Destination** and **Host Disaster Recovery** policies are already initialized on your Coolify server as documented in **`vps-linux-hardening-paas/docs/04-backup-recovery-policy.md`**.

## 5.1. Application Volume Backups (`/var/www/html`)

##### **Objective:** 
Create on-demand and scheduled archives of the persistent WordPress storage volume containing product images (`wp-content/uploads`), custom Must-Use plugins (`mu-plugins`), themes, and hardened `.htaccess` configurations.
##### **Action:**

###### Step 1: Execute Pre-Upgrade Manual Volume Backups
Whenever you plan to update WooCommerce core, payment gateway plugins, or themes, trigger an immediate volume snapshot first:

1. Log into the Coolify dashboard and open your **WordPress** resource.
2. Navigate to the **Storages** (Persistent Volumes) tab.
3. Locate the volume mounted to the **Destination Path:** `/var/www/html` (typically named `wordpress-files`).
4. Click the **Backup** button on the right side under the **Actions** column to generate an immediate `.tar.gz` snapshot of the entire application directory.
#### Step 2: Archive the Docker Compose Environment Configuration

1. Navigate to the **Edit Compose File** tab of your WordPress resource.
2. Copy the complete YAML configuration—specifically your `WORDPRESS_*` cryptographic salt variables and the `WORDPRESS_CONFIG_EXTRA` block containing your Redis and `FluentSMTP` credentials—and store it securely in your agency password manager (e.g., Bitwarden / Vaultwarden).

> [!Important]
   **Do Not Lose Your Cryptographic Salts & Compose File**
   >
>Backing up /var/www/html and the MariaDB database alone is insufficient if the container configuration is lost. Because your cryptographic salts, Redis credentials, and FluentSMTP passwords live inside Coolify's Docker Compose environment variables, losing those values during a server migration will invalidate user sessions and break transactional email delivery.

##### **Rationale:**
**Stateless vs. Stateful Data:** 
While the core WordPress PHP files can be recreated anytime by pulling the Docker image, `/var/www/html/wp-content/uploads` holds irreplaceable product photography and PDF invoices generated after checkout.

**Instant Rollback Capability:**
Taking a manual volume backup right before running plugin updates allows you to revert a broken storefront in under two minutes without rolling back the entire VPS.

## 5.2. Automated WooCommerce Database Backups (MariaDB to Cloudflare R2)

##### **Objective:** 
Configure automated, encrypted, off-site SQL dumps of the dedicated WooCommerce MariaDB container to Cloudflare R2, ensuring minimal Recovery Point Objective (RPO) for customer orders and payment records.
##### **Action:**
1. Exit the WordPress resource view in Coolify and open the store's dedicated **MariaDB** resource (provisioned in Section 1.1).
2. Navigate to the **Scheduled Backups** tab.
3. Click **+ Add** (or edit the existing schedule) and configure the backup pipeline:
    - **S3 Storage Destination:** Enable **S3 Storage** and select the **Cloudflare R2** bucket configured in **`vps-linux-hardening-paas/docs/04-backup-recovery-policy.md` (Section 2 & 3)**.
    - **Frequency (Cron expression):**
        - _Standard Low-Volume Store (Daily):_ `5 2 * * *` _(Runs daily at 02:05 AM)_
        - _High-Volume Active Store (Every 6 Hours):_ `5 */6 * * *` _(Runs at 00:05, 06:05, 12:05, 18:05)_
    - **Databases to Backup:** `wordpress` (or leave default to dump all schemas inside this isolated container).
4. Click **Save** and trigger a manual **Backup Now** test to verify that the `.sql.gz` archive appears inside your Cloudflare R2 bucket and triggers a success alert on your **Ntfy** mobile channel.

> [!Important]
**Mandatory Cron Staggering Across Multiple Stores**
>
Never schedule multiple client MariaDB containers on the same minute (e.g., 0 2 * * *). Simultaneous mariadb-dump compression and R2 upload streams will spike disk I/O and CPU wait times. Always stagger each store's backup schedule by at least 5 minutes (e.g., Store A at 02:05, Store B at 02:10, Store C at 02:15).
##### **Rationale:**
**Why Database Backups Are Critical for WooCommerce:** 
All customer orders, billing addresses, payment transaction IDs, stock quantities, and tax records reside strictly in MariaDB (`wp_wc_orders`, `wp_postmeta`).

**Minimizing Order Loss (6-Hour RPO):** 
If a server suffers a catastrophic hardware failure at 18:00 and backups only run at 02:00 AM, the merchant loses 16 hours of live orders and BLIK payments—creating severe accounting and customer service chaos. Running lightweight SQL dumps every 6 hours to Cloudflare R2 reduces the maximum data loss window while costing virtually nothing due to R2's zero-egress pricing model.

## 5.3. Host-Level Virtual Machine Snapshots (OVH Infrastructure Layer)

##### **Objective:** 
Maintain a full-disk block-level snapshot of the entire Ubuntu 26.04 host server to enable rapid bare-metal recovery of the OS, Docker Engine, Coolify control plane, and all client containers simultaneously.
##### **Action:**
1. Verify that automated host-level snapshots are enabled in your **OVH Public Cloud / VPS Control Panel** (or equivalent cloud provider dashboard).
2. Follow the exact verification and restoration runbook detailed in **`vps-linux-hardening-paas/docs/04-backup-recovery-policy.md`.
##### **Rationale:**
**RTO (Recovery Time Objective) Optimization:** 
Rebuilding a failed Ubuntu host from scratch, reinstalling Coolify, and individually restoring 15 separate client MariaDB dumps and `/var/www/html` volumes can take several hours. Restoring a full VM snapshot at the OVH hypervisor layer brings the entire agency infrastructure back online in minutes, after which you only need to import the latest 6-hour MariaDB dump from Cloudflare R2 to synchronize recent WooCommerce orders.