<?php
/**
 * WP-CLI commands: wp kme-export build|manifest|item.
 *
 * @package kme-exporter
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build or inspect KME export bundles.
 */
class KME_Exporter_CLI {

	/**
	 * Build a bundle.
	 *
	 * ## OPTIONS
	 *
	 * --out=<dir>
	 * : Directory to write into. The zip is written as <dir>/kme-export-<site>-<timestamp>.zip,
	 * or with --dir, the unzipped bundle is written straight into <dir>.
	 *
	 * [--dir]
	 * : Write an unzipped directory instead of a zip (used to regenerate the contract fixture).
	 *
	 * [--anonymise-authors]
	 * : Replace author logins and emails with placeholders. Use this for anything committed to a repo.
	 *
	 * [--include=<ids>]
	 * : Comma-separated item IDs to export (plus their attachments). Default: everything.
	 *
	 * [--max-revisions=<n>]
	 * : Keep only each item's newest n revisions. Default: all. For small fixtures.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kme-export build --out=./bundles
	 *     wp kme-export build --out=./fixtures/bundle-v1 --dir --anonymise-authors --include=1496,9470,9481 --max-revisions=2
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function build( array $args, array $assoc_args ): void {
		unset( $args );

		$out = (string) ( $assoc_args['out'] ?? '' );
		if ( '' === $out ) {
			WP_CLI::error( '--out=<dir> is required.' );
		}

		$include  = array_filter( array_map( 'intval', explode( ',', (string) ( $assoc_args['include'] ?? '' ) ) ) );
		$exporter = new KME_Exporter(
			(bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'anonymise-authors', false ),
			array_values( $include ),
			(int) ( $assoc_args['max-revisions'] ?? 0 )
		);
		$writer   = new KME_Export_Bundle_Writer();
		$bundle   = $exporter->build();

		try {
			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dir', false ) ) {
				$target = $writer->write_dir( $bundle, $out );
			} else {
				wp_mkdir_p( $out );
				$target = $writer->write_zip( $bundle, trailingslashit( $out ) . $writer->filename() );
			}
		} catch ( RuntimeException $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$counts = $bundle['manifest']['counts'];
		WP_CLI::log( sprintf( 'Items: %d · revisions: %d · archived versions: %d (orphaned, left out: %d) · presets: %d · media files: %d (missing: %d)', count( $bundle['items'] ), $counts['revisions'], $counts['archives_chained'], $counts['archives_orphaned'], $counts['presets'], $counts['media_files'], $counts['media_missing'] ) );
		WP_CLI::success( sprintf( 'Bundle written to %s', $target ) );
	}

	/**
	 * Print the manifest (site info, counts and the change index) as JSON.
	 *
	 * @subcommand manifest
	 *
	 * @param array<int, string>    $args       Positional arguments (unused).
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function manifest( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
		$bundle = ( new KME_Exporter() )->build();
		WP_CLI::line( (string) wp_json_encode( $bundle['manifest'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Print one item document as JSON.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * @param array<int, string>    $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function item( array $args, array $assoc_args ): void {
		unset( $assoc_args );
		$post = get_post( (int) ( $args[0] ?? 0 ) );
		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( 'No post with that ID.' );
		}
		WP_CLI::line( (string) wp_json_encode( ( new KME_Exporter() )->item( $post ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
