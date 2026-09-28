<?php
/**
 * Writes the documents built by {@see KME_Exporter} as a bundle: a zip file, or
 * an unzipped directory (used to regenerate the contract fixture).
 *
 * Layout (docs/contract.md):
 *   manifest.json
 *   items/{id}.json
 *   media.json
 *   files/{id}/{basename}
 *   menus.json
 *   users.json
 *   options.json
 *
 * @package kme-exporter
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serialises a built bundle to disk.
 */
class KME_Export_Bundle_Writer {

	/**
	 * JSON flags used for every document: readable, stable diffs, no escaping of
	 * slashes or non-ASCII text.
	 */
	private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Every file in the bundle as path => contents or source file.
	 *
	 * Media entries carry the server-side file path so the file can be copied;
	 * that path is removed before media.json is written, so no server paths leak
	 * into the bundle.
	 *
	 * @param array{manifest: array<string, mixed>, items: array<int, array<string, mixed>>, media: list<array<string, mixed>>, menus: array<string, mixed>, users: list<array<string, string>>, options: array<string, mixed>} $bundle Built bundle.
	 * @return array{documents: array<string, string>, files: array<string, string>}
	 */
	public function entries( array $bundle ): array {
		$documents = array(
			'manifest.json' => $this->json( $bundle['manifest'] ),
			'menus.json'    => $this->json( $bundle['menus'] ),
			'users.json'    => $this->json( $bundle['users'] ),
			'options.json'  => $this->json( $bundle['options'] ),
		);

		foreach ( $bundle['items'] as $id => $item ) {
			$documents[ 'items/' . $id . '.json' ] = $this->json( $item );
		}

		$files = array();
		$media = array();
		foreach ( $bundle['media'] as $entry ) {
			if ( ! $entry['missing'] && is_string( $entry['path'] ) ) {
				$files[ (string) $entry['file'] ] = $entry['path'];
			}
			unset( $entry['path'] );
			$media[] = $entry;
		}
		$documents['media.json'] = $this->json( $media );

		return array(
			'documents' => $documents,
			'files'     => $files,
		);
	}

	/**
	 * Write the bundle as a zip.
	 *
	 * @param array{manifest: array<string, mixed>, items: array<int, array<string, mixed>>, media: list<array<string, mixed>>, menus: array<string, mixed>, users: list<array<string, string>>, options: array<string, mixed>} $bundle Built bundle.
	 * @param string                                                                                                                                                                                                            $zip_path Destination file.
	 * @return string The zip path.
	 * @throws RuntimeException When the zip can't be created or written.
	 */
	public function write_zip( array $bundle, string $zip_path ): string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'The PHP zip extension (ZipArchive) is required to build a bundle.', 'kme-exporter' ) );
		}

		$entries = $this->entries( $bundle );
		$zip     = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not create the bundle zip at %s.', $zip_path ) ) );
		}

		foreach ( $entries['documents'] as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		foreach ( $entries['files'] as $name => $source ) {
			$zip->addFile( $source, $name );
		}

		if ( true !== $zip->close() ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not write the bundle zip at %s.', $zip_path ) ) );
		}

		return $zip_path;
	}

	/**
	 * Write the bundle as an unzipped directory.
	 *
	 * @param array{manifest: array<string, mixed>, items: array<int, array<string, mixed>>, media: list<array<string, mixed>>, menus: array<string, mixed>, users: list<array<string, string>>, options: array<string, mixed>} $bundle Built bundle.
	 * @param string                                                                                                                                                                                                            $dir Destination directory (created if missing).
	 * @return string The directory.
	 * @throws RuntimeException When a file can't be written.
	 */
	public function write_dir( array $bundle, string $dir ): string {
		$dir     = untrailingslashit( $dir );
		$entries = $this->entries( $bundle );

		foreach ( $entries['documents'] as $name => $contents ) {
			$this->put( $dir . '/' . $name, $contents );
		}
		foreach ( $entries['files'] as $name => $source ) {
			$target = $dir . '/' . $name;
			wp_mkdir_p( dirname( $target ) );
			if ( ! copy( $source, $target ) ) {
				throw new RuntimeException( esc_html( sprintf( 'Could not copy %s into the bundle.', $name ) ) );
			}
		}

		return $dir;
	}

	/**
	 * The suggested download filename: kme-export-<site>-<YYYYmmdd-HHMMSS>.zip.
	 *
	 * @return string
	 */
	public function filename(): string {
		$site = sanitize_title( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return 'kme-export-' . ( '' === $site ? 'site' : $site ) . '-' . gmdate( 'Ymd-His' ) . '.zip';
	}

	/**
	 * Encode one document.
	 *
	 * @param mixed $data Document.
	 * @return string
	 * @throws RuntimeException When the data can't be encoded.
	 */
	private function json( $data ): string {
		$json = wp_json_encode( $data, self::JSON_FLAGS );
		if ( false === $json ) {
			throw new RuntimeException( esc_html__( 'Could not encode a bundle document as JSON.', 'kme-exporter' ) );
		}

		return $json . "\n";
	}

	/**
	 * Write a file, creating its directory.
	 *
	 * @param string $path     File path.
	 * @param string $contents Contents.
	 * @return void
	 * @throws RuntimeException When the file can't be written.
	 */
	private function put( string $path, string $contents ): void {
		wp_mkdir_p( dirname( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI/export writer; WP_Filesystem would need credentials on some hosts.
		if ( false === file_put_contents( $path, $contents ) ) {
			throw new RuntimeException( esc_html( sprintf( 'Could not write %s.', $path ) ) );
		}
	}
}
