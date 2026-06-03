<?php

/**
 * Plugin Name:       RH Sync
 * Plugin URI:        https://github.com/herbeckrobin/rh-sync
 * Update URI:        https://github.com/herbeckrobin/rh-sync
 * Description:       Peer-to-Peer Sync zwischen WordPress-Instanzen über REST-API und HMAC-SHA256. Teil der rh-blueprint Kollektion.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Robin Herbeck
 * Author URI:        https://robinherbeck.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rh-sync
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('RHSYNC_VERSION', '0.2.0');
define('RHSYNC_PLUGIN_FILE', __FILE__);
define('RHSYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));

$rhsync_autoload = RHSYNC_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($rhsync_autoload)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>RH Sync:</strong> Composer-Dependencies fehlen. Bitte <code>composer install</code> im Plugin-Verzeichnis ausführen.</p></div>';
    });
    return;
}

require_once $rhsync_autoload;

RhSync\Plugin::boot();
