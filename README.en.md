> 🌐 **[نسخه فارسی (Persian)](README.md)**

---

# Behine Chi

[![Version](https://img.shields.io/badge/version-2.1.0-blue.svg)](https://github.com/)
[![License](https://img.shields.io/badge/license-GPLv2%20or%20later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Behine Chi** is a WordPress image optimization plugin designed to automatically convert images to next-generation formats (WebP and AVIF), resize dimensions, strip EXIF metadata, apply watermarks, optimize SVGs, and manage backups and batch processing without relying on external cloud APIs or third-party subscription services.

---

## 📋 Table of Contents

- [Features](#-features)
- [System Requirements](#-system-requirements)
- [Installation](#-installation)
- [Usage](#-usage)
- [Configuration & Settings](#-configuration--settings)
- [Database Architecture](#-database-architecture)
- [AJAX Endpoints](#-ajax-endpoints)
- [Troubleshooting & Health Check](#-troubleshooting--health-check)
- [License](#-license)
- [Author](#-author)

---

## 🚀 Features

### 1. Image Conversion & Compression
- **Next-Gen Format Generation:** Converts JPEG, PNG, and WebP images to **WebP** and **AVIF** formats.
- **Dual Processing Engines:**
  - **Imagick:** Primary processing driver for high-fidelity conversion and compression when `imagick` PHP extension is available.
  - **GD Library:** Full fallback processing driver when Imagick is not installed.
- **Quality Control:** Configurable image compression quality (1–100%, default: 75%).
- **WordPress Image Editor Integration:** Hooks into `image_editor_output_format` to enforce WebP or AVIF output for all core-generated thumbnails.

### 2. Dimension & Metadata Management
- **Automatic Resizing:** Downscales images exceeding user-defined maximum width and height limits (default: 2560×2560 px), preserving aspect ratio.
- **EXIF Stripping:** Strips metadata (camera details, GPS coordinates, timestamps) to reduce payload size.
- **File Size Threshold:** Skips images exceeding the configured maximum size limit (in MB) to protect server resources.

### 3. SVG Optimization
- **Pure-PHP Minification:** Built-in standalone SVG optimizer (`WSO\Engine\SVG_Optimizer`) that cleans unnecessary XML declarations, comments, doctype tags, and whitespace.
- **Integrity Validation:** Verifies `<svg>` root tags and safe XML parsing before replacing the file.

### 4. Watermarking Engine
- **Multi-Format Support:** Overlays transparent PNG watermarks using either GD or Imagick drivers.
- **9 Alignment Positions:** `top-left`, `top-center`, `top-right`, `center-left`, `center`, `center-right`, `bottom-left`, `bottom-center`, `bottom-right`.
- **Configurable Settings:** Custom opacity (1–100%) and pixel margins (0–200 px).
- **Double-Apply Protection:** Generates and stores an MD5 watermark signature in post meta (`_wso_wm_hash`) to avoid repeatedly stamping the same media file.

### 5. Backup & Restoration
- **Original File Preservation:** Copies original untouched files to a dedicated folder (`wp-content/uploads/wso-backups/`) before any optimization takes place.
- **Directory Security:** Automatically protects backup directories with an Apache `.htaccess` rule (`Deny from all`) and an empty `index.php`.
- **Single & Bulk Restoration:** Restore any individual media item back to its original state from the Media Library or restore all backed-up items from the admin panel.

### 6. Bulk Optimization & Folder Scanner
- **Queue System:** Dedicated database table (`wso_queue`) for tracking items in pending, processing, completed, or failed states.
- **Batch Processing:** Processes queue items asynchronously via AJAX in batches to avoid PHP timeouts or memory limits.
- **Directory Scanner:** Scans non-media-library directories (e.g., active theme directory, plugins directory, or custom ABSPATH subdirectories) for unoptimized image files and imports them into the optimization queue.

### 7. Media Library Integration
- **Custom Column:** Adds an optimization status column in the WordPress Media Library list table displaying compression status, savings percentage, and quick action buttons (Optimize / Restore).
- **Attachment Details:** Displays detailed metadata (`original_size`, `optimized_size`, `saved_bytes`, `driver`) on the attachment edit screen.

### 8. System Diagnostics & Administration
- **Built-In Health Check:** 6-category diagnostic suite scanning server settings, PHP extensions, image format capabilities, WordPress permissions, database tables, and active engine status.
- **Audit Logging:** Records every optimization attempt (Success, Warning, Error, Skipped) in a custom database table (`wso_logs`).
- **In-App Notifications:** Notification center backed by a custom database table (`wso_notifications`).
- **Settings Import / Export:** Export and import settings in JSON format, or reset all configurations to factory defaults.
- **Cache Management:** Clear generated WebP/AVIF files or clear all backup archives directly from admin settings.
- **Automatic Alt Text:** Generates readable Persian/English image alternative texts from sanitized filenames for attachments missing `_wp_attachment_image_alt`.
- **Customizable Admin UI:** Built-in admin theme customizer with dark mode, customizable colors, and bundled typography options (Vazir, IRANSansX, IRANYekanX).

---

## 💻 System Requirements

| Requirement | Supported / Recommended | Code Reference |
|---|---|---|
| **WordPress** | 5.5 or higher | Checked in `WSO\Tools\Health_Check` |
| **PHP Version** | 7.4 or higher | Checked in `WSO\Tools\Health_Check` |
| **PHP Memory Limit** | ≥ 128MB (≥ 256MB recommended) | Checked in `WSO\Tools\Health_Check` |
| **Image Drivers** | `imagick` (recommended) or `gd` | `WSO\Engine\Imagick_Driver` / `GD_Driver` |
| **File Formats (Input)** | JPEG, PNG, WebP, SVG | Supported across processing engines |
| **File Formats (Output)** | WebP (requires GD/Imagick WebP support), AVIF (requires PHP 8.1+ & AVIF support) | Verified at runtime |

---

## 📥 Installation

### Manual Installation via WordPress Admin

1. Download or clone this repository folder into a `.zip` archive named `behine-chi.zip`.
2. Go to your WordPress admin dashboard: **Plugins > Add New > Upload Plugin**.
3. Choose `behine-chi.zip` and click **Install Now**.
4. Activate the plugin via the **Plugins** menu.
5. Upon activation, the plugin automatically creates the required custom tables (`wso_queue`, `wso_logs`, `wso_notifications`) and initial options.

### Manual Installation via Filesystem / FTP

1. Copy the plugin directory (`behine-chi`) into your WordPress installation under:
   ```text
   wp-content/plugins/behine-chi/
   ```
2. Navigate to **Plugins > Installed Plugins** in the WordPress dashboard.
3. Locate **بهینه چی | افزونه حرفه‌ای بهینه‌سازی تصاویر وردپرس** and click **Activate**.

---

## 🛠️ Usage

### 1. Automatic Optimization on Upload
When enabled (`wso_auto_optimize = 1`), any image uploaded through the WordPress Media Library is automatically processed via the `wp_generate_attachment_metadata` filter (priority `20`); the main file and all thumbnails are optimized exactly once (resize, convert, watermark, backup).

### 2. Bulk Optimization
1. Go to **بهینه چی > صف بهینه‌سازی (Bulk Queue)** in the WordPress admin.
2. Click **ساخت صف (Build Queue)** to index all unoptimized media attachments into the queue.
3. Click **شروع بهینه‌سازی (Start Optimization)** to process images in batches via asynchronous AJAX requests.

### 3. Folder Scanner
1. Navigate to the **اسکنر پوشه‌ها (Folder Scanner)** tab.
2. Select one of the predefined targets (Uploads Directory, Active Theme, Plugins Directory) or input a custom relative path under `ABSPATH`.
3. Click **اسکن (Scan)** to view uncompressed image files and add them to the optimization queue.

### 4. Media Library Single Optimization
1. Go to **Media > Library** (List view).
2. Look at the **وضعیت بهینه‌سازی (Optimization Status)** column.
3. Click **بهینه‌سازی (Optimize)** to compress a single attachment, or click **بازگردانی (Restore)** to restore the original backup file.

---

## ⚙️ Configuration & Settings

All settings are stored in the WordPress `wp_options` table with the `wso_` prefix:

| Option Key | Control Type | Default | Description |
|---|---|---|---|
| `wso_enable` | Checkbox | `1` | Master switch to enable or disable the optimization engine. |
| `wso_quality` | Number (1–100) | `75` | Target compression quality for lossy conversions. |
| `wso_delete_original` | Checkbox | `0` | If enabled, removes original image files after WebP/AVIF conversion. |
| `wso_max_size` | Number | `2` | Maximum file size (in megabytes) eligible for optimization. Larger files are skipped. |
| `wso_convert_webp` | Checkbox | `1` | Automatically convert raster images (JPEG/PNG) to WebP. |
| `wso_convert_avif` | Checkbox | `0` | Automatically convert raster images to AVIF (requires server support). |
| `wso_strip_exif` | Checkbox | `1` | Strip EXIF and camera metadata from processed images. |
| `wso_backup_originals` | Checkbox | `1` | Create a secure backup of original images in `wso-backups/` before modifying them. |
| `wso_optimize_svg` | Checkbox | `1` | Minify and clean SVG files upon upload. |
| `wso_max_width` | Number | `2560` | Maximum pixel width. Images wider than this value are resized proportionally. |
| `wso_max_height` | Number | `2560` | Maximum pixel height. Images taller than this value are resized proportionally. |
| `wso_auto_optimize` | Checkbox | `1` | Automatically optimize images immediately during upload via `wp_generate_attachment_metadata`. |
| `wso_dark_mode` | Checkbox | `0` | Toggle dark mode styling for the plugin's admin dashboard. |
| `wso_watermark_enabled`| Checkbox | `0` | Enable transparent PNG watermark overlay on processed images. |
| `wso_watermark_image`  | Attachment ID | `0` | Media Library attachment ID of the PNG watermark image. |
| `wso_watermark_position`| Select | `'bottom-right'` | Position of the watermark (9 alignment points). |
| `wso_watermark_opacity` | Number (1–100) | `70` | Transparency percentage of the applied watermark. |
| `wso_watermark_margin`  | Number (0–200) | `12` | Distance in pixels from the edge of the image. |
| `wso_auto_alt_enabled` | Checkbox | `0` | Automatically generate image Alt text from the filename if not provided. |
| `wso_auto_alt_overwrite`| Checkbox | `0` | If enabled, overwrites existing Alt text instead of filling only empty fields. |

---

## 🗄️ Database Architecture

The plugin creates 3 custom tables during activation using `dbDelta`:

### 1. `{wp_prefix}_wso_queue`
Manages bulk optimization jobs:
- `id`: Primary key (bigint auto-increment).
- `attachment_id`: Associated WordPress attachment ID (bigint).
- `file_path`: Absolute server path to the target image file.
- `status`: Processing state (`pending`, `processing`, `completed`, `failed`).
- `attempts`: Retry counter (int).
- `error_message`: Failure description if processing fails.
- `created_at`, `updated_at`: Timestamps.

### 2. `{wp_prefix}_wso_logs`
Audit history of image optimization actions:
- `id`: Primary key.
- `attachment_id`: Associated attachment ID.
- `filename`: Image file name.
- `driver`: Engine used (`imagick` or `gd`).
- `original_size`: File size in bytes before optimization.
- `optimized_size`: File size in bytes after optimization.
- `status`: Outcome (`success`, `warning`, `error`, `skipped`).
- `message`: Context message or error details.
- `created_at`: Log timestamp.

### 3. `{wp_prefix}_wso_notifications`
Internal in-app notification center:
- `id`: Primary key.
- `type`: Notification level (`info`, `success`, `warning`, `error`).
- `title`: Short summary.
- `message`: Notification body.
- `is_read`: Read receipt flag (`0` or `1`).
- `created_at`: Creation timestamp.

### Post Meta Keys
- `_wso_optimized`: Set to `1` when an attachment has been processed.
- `_wso_opt_data`: Serialized array containing optimization metrics (`original_size`, `optimized_size`, `saved_bytes`, `driver`, `timestamp`).
- `_wso_wm_hash`: MD5 hash signature of the applied watermark configuration to prevent redundant stamping.

---

## 🔌 AJAX Endpoints

All AJAX endpoints require the `manage_options` capability and verify the nonce `wso_admin_nonce` via `check_ajax_referer`:

| Action | Function Callback | Description |
|---|---|---|
| `wso_save_settings` | `AJAX_Handler::save_settings` | Sanitizes and updates plugin options. |
| `wso_get_logs` | `AJAX_Handler::get_logs` | Retrieves paginated records from `wso_logs`. |
| `wso_clear_logs` | `AJAX_Handler::clear_logs` | Truncates the `wso_logs` table. |
| `wso_get_stats` | `AJAX_Handler::get_stats` | Returns total savings, counts, and format distribution. |
| `wso_get_notifications` | `AJAX_Handler::get_notifications` | Fetches unread/read messages from `wso_notifications`. |
| `wso_mark_notification_read` | `AJAX_Handler::mark_notification_read` | Marks a single notification as read. |
| `wso_mark_all_notifications_read` | `AJAX_Handler::mark_all_notifications_read` | Marks all notifications as read. |
| `wso_clear_notifications` | `AJAX_Handler::clear_notifications` | Clears all records from `wso_notifications`. |
| `wso_run_health_check` | `AJAX_Handler::run_health_check` | Runs the 6-category system diagnostics. |
| `wso_run_health_fix` | `AJAX_Handler::run_health_fix` | Attempts automated repair of missing tables or permissions. |
| `wso_build_queue` | `Async_Processor::ajax_build_queue` | Indexes unoptimized media items into `wso_queue`. |
| `wso_process_batch` | `Async_Processor::ajax_process_batch` | Processes a batch of queued images. |
| `wso_get_queue_status` | `Async_Processor::ajax_get_queue_status` | Polls current queue progress and statistics. |
| `wso_reset_queue` | `Async_Processor::ajax_reset_queue` | Clears all rows in `wso_queue`. |
| `wso_scan_folder` | `Folder_Scanner::ajax_scan_folder` | Scans a folder on disk for image files. |
| `wso_queue_scanned_folder` | `Folder_Scanner::ajax_queue_scanned_folder` | Pushes scanned directory images into `wso_queue`. |
| `wso_single_optimize` | `Media_Library::ajax_single_optimize` | Optimizes an individual attachment from Media Library. |
| `wso_single_restore` | `Media_Library::ajax_single_restore` | Restores an individual attachment from its backup. |
| `wso_restore_all_backups` | `Cache_Manager::ajax_restore_all_backups` | Restores all backed-up images to their original paths. |
| `wso_clear_backups` | `Cache_Manager::ajax_clear_backups` | Deletes all files stored in `uploads/wso-backups/`. |
| `wso_clear_generated_cache` | `Cache_Manager::ajax_clear_generated_cache` | Deletes all generated `.webp` and `.avif` sibling files. |
| `wso_export_settings` | `Exporter_Importer::ajax_export_settings` | Returns a JSON string of all active settings. |
| `wso_import_settings` | `Exporter_Importer::ajax_import_settings` | Imports settings from an uploaded JSON payload. |
| `wso_reset_settings` | `Exporter_Importer::ajax_reset_settings` | Resets all `wso_*` options to code defaults. |
| `wso_fill_missing_alts` | `Auto_Alt::ajax_fill_missing_alts` | Scans attachments and generates missing Alt text. |

---

## 🔍 Troubleshooting & Health Check

The plugin includes an internal diagnostic module (`WSO\Tools\Health_Check`) accessible via the plugin admin page. It evaluates:

1. **Server Environment:** Checks PHP version (≥ 7.4), memory limit (≥ 128MB), and execution time limit.
2. **PHP Extensions:** Checks whether `imagick` or `gd` are installed.
3. **Format Support:** Checks whether the active engine supports WebP reading/writing and AVIF conversion.
4. **WordPress & Permissions:** Verifies WordPress version (≥ 5.5) and ensures write permissions on `wp-content/uploads/` and `wp-content/uploads/wso-backups/`.
5. **Database Integrity:** Verifies existence and structure of `wso_queue`, `wso_logs`, and `wso_notifications`.
6. **Active Engine:** Identifies whether Imagick or GD is actively handling conversion routines.

### Common Issues:
- **AVIF conversion is not working:** AVIF requires PHP 8.1+ compiled with `libavif` support in either GD or Imagick. If unavailable on your server, leave `wso_convert_avif` disabled and use WebP.
- **Backups taking too much storage:** Use the **پاکسازی پشتیبان‌ها (Clear Backups)** tool in admin settings or disable `wso_backup_originals`.
- **Large file skipped:** Check `wso_max_size` (default: 2MB). Images larger than this setting will be skipped to protect server memory.

---

## 📄 License

This project is licensed under the **GPLv2 or later** (GNU General Public License version 2 or later).

---

## 👤 Author

- **Author:** Ehsan.dev
- **Website:** [https://sir-developer.ir/](https://sir-developer.ir/)
