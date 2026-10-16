# 🛒 High-Performance WooCommerce PaaS Deployment (Single-Tenant)

This repository contains the complete operational runbooks, security configurations, and performance optimization guides for deploying and maintaining production-ready, Single-Tenant WordPress & WooCommerce stores on Coolify.

> [!Important]
> **Prerequisite Infrastructure:**
> This repository is an application-layer continuation of our core infrastructure guide. Before following these runbooks, ensure your host server, Coolify control plane, Traefik proxy, Cloudflare R2 storage, and monitoring stack are provisioned according to **[vps-linux-hardening-paas](https://github.com/inok-it/vps-linux-hardening-paas)**.

---

### 🏗️ Architecture & Rationale (Why this stack?)

Every component in this e-commerce stack is engineered to eliminate the classic bottlenecks and security vulnerabilities of shared WordPress hosting:

- **Single-Tenant Resource Isolation (MariaDB & Redis):** Every WooCommerce store runs inside its own dedicated WordPress, MariaDB, and Redis containers. Heavy database locks during product imports or traffic spikes on one store never impact neighboring client environments.
- **Multi-Tier Caching Strategy:**
  - **Edge Layer (Cloudflare):** Serves static assets compressed with Brotli/Zstandard and accelerates initial rendering via Early Hints while strictly bypassing dynamic cart and session cookies.
  - **Page Cache Layer (WP Super Cache):** Serves pre-rendered static HTML files directly from disk to anonymous catalog visitors, completely bypassing PHP execution.
  - **Object Cache Layer (Redis):** Stores repetitive SQL query results and PHP objects in RAM, accelerating uncached dynamic requests (cart calculations, checkout, admin operations).
  - **Bytecode Layer (OPCache):** Keeps precompiled PHP bytecode in dedicated RAM (`384MB`) via immutable host-mounted `.ini` configurations.
- **Defense-in-Depth Hardening:** Security is enforced across four independent tiers: Cloudflare WAF & Geo-Challenge at the network edge, Apache `.htaccess` execution locks at the web server level, non-deactivatable Must-Use (`mu-plugins`) filters in PHP, and mandatory 2FA for administrators.
- **Zero-Database Secret Storage:** Cryptographic salts and transactional SMTP credentials (`FluentSMTP`) are injected strictly via Docker Compose environment variables rather than stored in plaintext inside the `wp_options` database table.

---

### ⚙️ Tech Stack

- **Application & PaaS:** WordPress (Pinned Docker Image), WooCommerce (HPOS Enabled), Coolify Scheduled Tasks (Native CLI Cron)
- **Database & Object Cache:** MariaDB (Isolated Container), Redis (In-Memory Object Cache)
- **Runtime & Page Cache:** PHP 8.x with Custom OPCache (`opcache-custom.ini`), WP Super Cache
- **Edge Security & CDN:** Cloudflare (Security Rules / WAF, Rate Limiting, Geo-Challenge, Dynamic Cache Rules, Full Strict SSL, HSTS)
- **Maintenance, SMTP & Auditing:** WP-Optimize (Database Garbage Collection), FluentSMTP, Simple History, Two Factor
- **Backup & Disaster Recovery:** Coolify Volume Snapshots (`/var/www/html`), Cloudflare R2 (Automated SQL Dumps), OVH Host VM Snapshots

---

### 📂 Repository Structure

```text
woocommerce-coolify-deployment/
├── docs/   # Step-by-step deployment, hardening, and optimization runbooks
│   ├── 00-provisioning-woo-setup.md
│   ├── 01-wordpress-hardening.md
│   ├── 02-cloudflare-setup.md
│   ├── 03-wordpress-optimizing.md
│   ├── 04-wordpress-plugins.md
│   └── 05-backup-policy.md
├── config/                # Reference PHP .ini and Apache .htaccess templates
│   ├── opcache-custom.ini
│   ├── uploads.ini
│   └── .htaccess.example
├── mu-plugins/ # Must-Use hardening and WooCommerce optimization plugin
│   └── wp-hardening.php
├── LICENSE                # Repository license
└── README.md              # Project overview and documentation index
```

### 📖 Documentation (Table of Contents)

Detailed deployment and operational guides are located in the `/docs` directory. Follow them in strict chronological sequence when onboarding a new WooCommerce store:

**[00 - WooCommerce Provisioning & High-Performance Setup](docs/00-provisioning-woo-setup.md)** 
Dedicated MariaDB schema initialization, version-pinned WordPress container deployment, native CLI `wp-cron.php` offloading via Coolify Scheduled Tasks, HPOS verification, and Redis Object Cache integration.
 
**[01 - Application-Level Hardening & MU-Plugin](docs/01-wordpress-hardening.md)** 
Apache `.htaccess` lockdowns (`wp-config.php`, `xmlrpc.php`, `/uploads` and `/wp-includes` script execution blocks, application security headers), Docker Compose cryptographic salts, the `wp-hardening.php` Must-Use plugin, and 2FA enforcement.
 
**[02 - Cloudflare Edge Security & WooCommerce Caching Rules](docs/02-cloudflare-setup.md)** 
`Full (Strict)` SSL and HSTS graduation, `/wp-login.php` POST rate limiting, WAF custom rules blocking enumeration and sensitive dotfiles, Foreign Login Geo-Challenge, dynamic WooCommerce cache bypass rules, and payment/ERP webhook whitelisting (`wc-api` & REST API).

**[03 - Performance Optimization, OPCache & Page Caching](docs/03-wordpress-optimizing.md)** 
Post revision and autosave tuning, host-mounted read-only PHP configuration files (`opcache-custom.ini` and `uploads.ini`), and WP Super Cache setup with strict e-commerce slug exclusions.

**[04 - Automated Maintenance, Audit Logging & Transactional SMTP](docs/04-wordpress-plugins.md)** Scheduled database garbage collection via WP-Optimize (with a 2-week retention safety window), zero-database credential configuration for FluentSMTP via `wp-config.php`, and automated weekly audit digests with Simple History.

**[05 - WooCommerce Backup & Disaster Recovery Strategy](docs/05-backup-policy.md)** Multi-tiered recovery procedures covering pre-upgrade `/var/www/html` volume archives, Docker Compose secret backups, staggered 6-hour/daily MariaDB backups to Cloudflare R2, and host-level OVH VM snapshots.