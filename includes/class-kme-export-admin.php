<?php
/**
 * Tools → Export: adds "KME" to WordPress's own "Choose what to export" list, with a
 * table of what the export will contain, and streams the bundle when it is chosen.
 *
 * @package kme-exporter
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Export option and the download handler.
 */
class KME_Export_Admin {

	/**
	 * The `content` value of the option on Tools → Export.
	 */
	public const CONTENT = 'kme';

	/**
	 * Capability required to see the option and download. Stricter than core's `export`.
	 */
	public const CAPABILITY = 'manage_options';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'export_filters', array( __CLASS__, 'render_option' ) );
		add_action( 'admin_footer-export.php', array( __CLASS__, 'print_script' ) );
		add_action( 'export_wp', array( __CLASS__, 'maybe_download' ) );
	}

	/**
	 * The "KME" option on Tools → Export, below WordPress's own choices. Its panel (what
	 * the export will contain) is one of core's `export-filters`, so core hides it until
	 * the option is chosen, as it does for the Posts and Pages filters.
	 *
	 * @return void
	 */
	public static function render_option(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$zip_ok  = class_exists( 'ZipArchive' );
		$command = 'wp kme-export build --out=<folder>';
		?>
		<div id="kme-export-option">
		<p><label><input type="radio" name="content" value="<?php echo esc_attr( self::CONTENT ); ?>" aria-describedby="kme-filters"<?php disabled( ! $zip_ok ); ?> /> <?php esc_html_e( 'KME', 'kme-exporter' ); ?></label></p>
		<?php if ( ! $zip_ok ) : ?>
			<p class="description">
				<?php esc_html_e( 'This server is missing the PHP zip extension, so the KME export can\'t be built here. Build it from the command line on a machine that has it:', 'kme-exporter' ); ?>
				<code><?php echo esc_html( $command ); ?></code>
			</p>
		<?php else : ?>
		<div id="kme-filters" class="export-filters">
			<p><?php esc_html_e( 'A single .zip of everything on this site, to import into SPS2 on the KME tab of SPS → Import. Exporting never changes any content here.', 'kme-exporter' ); ?></p>
			<table class="widefat striped" style="max-width:32rem">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Content', 'kme-exporter' ); ?></th>
						<th scope="col" style="text-align:right"><?php esc_html_e( 'Count', 'kme-exporter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::rows( ( new KME_Exporter() )->summary() ) as $label => $count ) : ?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td style="text-align:right"><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php esc_html_e( 'Menus, the preset texts and the authors of each page are included too. Trashed items, autosaves, passwords and other plugin settings are not.', 'kme-exporter' ); ?></p>
			<p class="description">
				<?php esc_html_e( 'If the download times out, build it from the command line instead:', 'kme-exporter' ); ?>
				<code><?php echo esc_html( $command ); ?></code>
			</p>
			<p class="description">
				<?php
				printf(
					/* translators: 1: plugin version, 2: bundle format version. */
					esc_html__( 'KME Exporter %1$s, bundle format %2$s.', 'kme-exporter' ),
					esc_html( KME_EXPORT_VERSION ),
					esc_html( KME_Exporter::SCHEMA_VERSION )
				);
				?>
			</p>
		</div>
		<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Move the KME option to the top of the list, above "All content" (core only offers
	 * a hook at the end), and show its panel when it is chosen. Core's own script hides
	 * every `export-filters` panel on load and on each change, but only knows its own ones.
	 *
	 * @return void
	 */
	public static function print_script(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<script>
			jQuery( function ( $ ) {
				var first = $( '#export-filters input[name="content"]' ).first().closest( 'p' );
				if ( first.length ) {
					$( '#kme-export-option' ).insertBefore( first );
				}
				$( '#export-filters input:radio' ).on( 'change', function () {
					if ( '<?php echo esc_js( self::CONTENT ); ?>' === $( this ).val() ) {
						$( '#kme-filters' ).stop( true, true ).slideDown();
					}
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Summary rows as label => count: one row per post type and status (using
	 * WordPress's own labels), then revisions, archived versions, embeds and presets.
	 *
	 * @param array{by_type: array<string, array<string, int>>, revisions: int, archives: int, archives_orphaned: int, media: int, embeds: int, presets: int} $summary Exporter summary.
	 * @return array<string, int>
	 */
	private static function rows( array $summary ): array {
		$rows = array();

		foreach ( $summary['by_type'] as $type => $statuses ) {
			$type_object = get_post_type_object( $type );
			$type_label  = $type_object ? (string) $type_object->labels->name : $type;

			foreach ( $statuses as $status => $count ) {
				if ( 'attachment' === $type ) {
					$rows[ __( 'Media files', 'kme-exporter' ) ] = $count;
					continue;
				}

				$status_object = get_post_status_object( $status );
				$status_label  = $status_object ? (string) $status_object->label : $status;

				/* translators: 1: post type name (e.g. Pages), 2: status (e.g. Published). */
				$rows[ sprintf( __( '%1$s, %2$s', 'kme-exporter' ), $type_label, strtolower( $status_label ) ) ] = $count;
			}
		}

		$rows[ __( 'Revisions', 'kme-exporter' ) ]                                        = $summary['revisions'];
		$rows[ __( 'Archived versions (Ready for Live), as revisions', 'kme-exporter' ) ] = $summary['archives'];
		$rows[ __( 'Archived versions left out (no live report of their own)', 'kme-exporter' ) ] = $summary['archives_orphaned'];
		$rows[ __( 'Chart embeds (KME app) and iframes', 'kme-exporter' ) ]                       = $summary['embeds'];
		$rows[ __( 'Preset texts', 'kme-exporter' ) ] = $summary['presets'];

		return $rows;
	}

	/**
	 * When "KME" was chosen on Tools → Export, stream the bundle instead of
	 * WordPress's own export file. Runs on `export_wp`, before core sends anything.
	 *
	 * @param array<string, mixed> $args Core's export arguments.
	 * @return void
	 */
	public static function maybe_download( array $args ): void {
		if ( self::CONTENT !== ( $args['content'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export this site.', 'kme-exporter' ), 403 );
		}

		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- building a full-site bundle can take longer than the default limit.
		}

		$writer = new KME_Export_Bundle_Writer();
		$tmp    = wp_tempnam( 'kme-export.zip' );

		try {
			$writer->write_zip( ( new KME_Exporter() )->build(), $tmp );
		} catch ( RuntimeException $e ) {
			wp_delete_file( $tmp );
			wp_die( esc_html( $e->getMessage() ), esc_html__( 'KME export failed', 'kme-exporter' ), 500 );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $writer->filename() . '"' );
		header( 'Content-Length: ' . (string) filesize( $tmp ) );

		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming our own temp file.
		wp_delete_file( $tmp );
		exit;
	}
}
