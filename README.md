# OptiPress — Premium WordPress Image Optimization

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF?style=flat-square&logo=php)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-0073AA?style=flat-square&logo=wordpress)](https://wordpress.org)
[![License](https://img.shields.io/badge/License-GPLv2-blue?style=flat-square)](LICENSE)

A complete, production-grade image optimization plugin for WordPress with real compression (no fake statistics), WebP/AVIF conversion, auto-serving, bulk processing, detailed analytics and full ecosystem compatibility.

![Dashboard Preview](https://via.placeholder.com/1200x600/3056d3/ffffff?text=OptiPress+Dashboard)

## ✨ Features

### 🎯 Real Optimization Engine
- **Lossless, balanced, and lossy** compression modes
- **Imagick preferred, GD fallback** — works on any host
- **Real file-size comparisons** — every stat is computed from actual `filesize()` deltas
- **Backup originals** with one-click restore
- **Animated GIF protection** — frames are never lost

### 🌐 Modern Format Conversion
- **WebP generation** for all image sizes (JPEG, PNG, GIF)
- **AVIF generation** when the server supports it
- **Auto-serving via `<picture>` tags** — format negotiation happens in the browser
- **Safe fallbacks** — original `<img>` is preserved, so nothing can break

### ⚡ Bulk Processing at Scale
- **Resumable AJAX batches** with live progress
- **Crash recovery** — refresh the page and the run resumes where it left off
- **50,000+ image support** via chunked sync and server-side SQL pagination
- **Smart time budgets** — respects your host's `max_execution_time`

### 📊 Analytics & Logs
- **Real-time dashboard** with savings, success rates, library coverage
- **Historical analytics** with Today/7/30/90/365/All-time ranges
- **Detailed event logs** with context (bytes before/after, engine used, etc.)
- **Retry workflows** for failed operations

### 🔌 Ecosystem Compatibility
Tested to work alongside:
- **Page builders:** Gutenberg, Elementor, Divi, Avada, Oxygen
- **E-commerce:** WooCommerce (products, galleries, variations)
- **Caches:** WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, Autoptimize
- **CDNs:** Compatible via cache purge APIs

## 🚀 Installation

### From Source

1. Clone this repository into your `wp-content/plugins/` folder:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/YOUR-USERNAME/optipress.git

2. Activate OptiPress in WordPress admin.

2. Go to OptiPress → Bulk Optimize → Scan Library → Start Optimization.


## 🚀 Installation

optipress/
├── optipress.php                  # Bootstrap
├── uninstall.php                  # Clean removal
├── includes/
│   ├── class-optipress-plugin.php # Core container + hooks
│   ├── class-optipress-db.php     # Schema + repositories (items/logs/daily)
│   ├── class-optipress-processor.php # Real optimization engine
│   ├── class-optipress-converter.php # WebP / AVIF generation
│   ├── class-optipress-queue.php  # Bulk runner with crash recovery
│   ├── class-optipress-autoserve.php # <picture> auto-serving
│   ├── class-optipress-cache-purge.php # Cache invalidation
│   └── ... (other services)
├── assets/
│   ├── css/optipress-admin.css
│   └── js/optipress-admin.js


## 🔒 Security

Every AJAX route implements:

✅ WordPress nonces (check_ajax_referer)
✅ Capability checks (current_user_can('manage_options'))
✅ Prepared SQL statements
✅ Path traversal protection
✅ Output escaping on all user content
✅ File validation before processing