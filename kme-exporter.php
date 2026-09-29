<?php
/**
 * Plugin Name:       KME Exporter
 * Plugin URI:        https://github.com/zaltek-digital/kme-exporter
 * Description:       Packages the KME site's content (pages, report posts, their ACF fields, revisions and archived versions, preset texts, media, menus and authors) into a single bundle for manual import into SPS2. Read-only: it never changes content.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Zaltek Digital
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kme-exporter
 *
 * @package kme-exporter
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KME_EXPORT_VERSION', '0.2.0' );
define( 'KME_EXPORT_FILE', __FILE__ );
define( 'KME_EXPORT_DIR', plugin_dir_path( __FILE__ ) );

require_once KME_EXPORT_DIR . 'includes/class-kme-exporter.php';
require_once KME_EXPORT_DIR . 'includes/class-kme-export-bundle-writer.php';
require_once KME_EXPORT_DIR . 'includes/class-kme-export-admin.php';

/**
 * Boot the plugin.
 *
 * Only the admin screen and the WP-CLI commands attach hooks. The exporter does
 * nothing on a normal front-end or admin request until someone asks for a bundle.
 *
 * @return void
 */
function kme_export_bootstrap(): void {
	KME_Export_Admin::init();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once KME_EXPORT_DIR . 'includes/class-kme-exporter-cli.php';
		WP_CLI::add_command( 'kme-export', 'KME_Exporter_CLI' );
	}
}

kme_export_bootstrap();
