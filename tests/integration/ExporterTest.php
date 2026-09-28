<?php
/**
 * Integration tests for the KME exporter: what is exported, the bundle layout,
 * the contract schemas, and the safety rules (no tokens, no server paths, no
 * secrets, admin-only download).
 *
 * @package kme-exporter
 */

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Exporter behaviour against a real WordPress test install.
 */
class ExporterTest extends WP_UnitTestCase {

	/**
	 * Temp paths created by a test, removed in tear_down().
	 *
	 * @var string[]
	 */
	private array $cleanup = array();

	/**
	 * Register a dynamic test block that signs a token into its output, like
	 * the NDO widgets and the KME chart embed do.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'test/dynamic' ) ) {
			register_block_type(
				'test/dynamic',
				array(
					'render_callback' => static fn(): string => '<div class="test-dynamic"><iframe src="https://example.test/embed?token=abc.def.ghi"></iframe></div>',
				)
			);
		}
	}

	/**
	 * Remove temp files and the test block.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			$this->remove( $path );
		}
		if ( WP_Block_Type_Registry::get_instance()->is_registered( 'test/dynamic' ) ) {
			unregister_block_type( 'test/dynamic' );
		}
		parent::tear_down();
	}

	/**
	 * Published, draft and private pages and posts are exported, and so are
	 * attachments. Trash and auto-drafts are not.
	 */
	public function test_exports_every_status_except_trash_and_auto_draft(): void {
		$publish = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);
		$private = self::factory()->post->create( array( 'post_status' => 'private' ) );
		$trash   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'trash',
			)
		);
		$auto    = self::factory()->post->create( array( 'post_status' => 'auto-draft' ) );
		$image   = self::factory()->attachment->create_object( 'photo.png', $publish, array( 'post_mime_type' => 'image/png' ) );

		$ids = array_keys( ( new KME_Exporter() )->build()['items'] );

		foreach ( array( $publish, $draft, $private, $image ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( $trash, $ids );
		$this->assertNotContains( $auto, $ids );
	}

	/**
	 * An item carries its identity, tree position and raw block content.
	 */
	public function test_item_fields(): void {
		$parent = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Parent',
			)
		);
		$id     = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Child',
				'post_name'    => 'child',
				'post_parent'  => $parent,
				'menu_order'   => 3,
				'post_excerpt' => 'Lead text',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			)
		);
		update_post_meta( $id, '_wp_page_template', 'page-templates/full-width.php' );

		$item = ( new KME_Exporter() )->item( get_post( $id ) );

		$this->assertSame( $id, $item['source_id'] );
		$this->assertSame( 'page', $item['type'] );
		$this->assertSame( 'child', $item['slug'] );
		$this->assertSame( $parent, $item['parent'] );
		$this->assertSame( 3, $item['menu_order'] );
		$this->assertSame( 'Lead text', $item['excerpt'] );
		$this->assertSame( 'page-templates/full-width.php', $item['template'] );
		$this->assertSame( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->', $item['content_raw'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $item['content_sha256'] );
	}

	/**
	 * Display settings (_nhs_*) are exported as lists; editor bookkeeping is not.
	 */
	public function test_meta_policy(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $id, '_nhs_show_hero', '1' );
		update_post_meta( $id, '_edit_lock', '123:1' );
		update_post_meta( $id, '_some_other_protected', 'x' );
		update_post_meta( $id, 'public_key', 'y' );

		$meta = (array) ( new KME_Exporter() )->item( get_post( $id ) )['meta'];

		$this->assertSame( array( '1' ), $meta['_nhs_show_hero'] );
		$this->assertSame( array( 'y' ), $meta['public_key'] );
		$this->assertArrayNotHasKey( '_edit_lock', $meta );
		$this->assertArrayNotHasKey( '_some_other_protected', $meta );
	}

	/**
	 * Revisions are exported oldest first, without autosaves, and can be capped.
	 */
	public function test_revisions(): void {
		$id = self::factory()->post->create( array( 'post_content' => 'v1' ) );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'v2',
			)
		);
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'v3',
			)
		);
		wp_create_post_autosave(
			array(
				'post_ID'      => $id,
				'post_content' => 'autosave',
				'post_title'   => 'x',
				'post_type'    => 'post',
			)
		);

		$revisions = ( new KME_Exporter() )->item( get_post( $id ) )['revisions'];
		$contents  = array_column( $revisions, 'content_raw' );

		$this->assertNotContains( 'autosave', $contents );
		$this->assertSame( 'v3', end( $contents ) );
		$this->assertLessThan( array_search( 'v3', $contents, true ), array_search( 'v2', $contents, true ) );

		$capped = ( new KME_Exporter( false, array(), 1 ) )->item( get_post( $id ) )['revisions'];
		$this->assertCount( 1, $capped );
		$this->assertSame( 'v3', $capped[0]['content_raw'] );
	}

	/**
	 * Widget blocks, iframes and embeds are all found, with their block paths.
	 */
	public function test_embeds_are_found_with_paths(): void {
		$content = '<!-- wp:nhs/grid-row --><div><!-- wp:nhs/metabase-widget {"resourceId":2029} /--></div><!-- /wp:nhs/grid-row -->'
			. '<!-- wp:nhs/ndo-widget {"resourcePath":"/kpi/total-drugs"} /-->'
			. '<!-- wp:html --><iframe src="https://example.test/raw"></iframe><!-- /wp:html -->';

		$embeds = ( new KME_Exporter() )->embeds( parse_blocks( $content ) );
		$byname = array_column( $embeds, null, 'block' );

		$this->assertSame( '0.0', $byname['nhs/metabase-widget']['path'] );
		$this->assertSame( 2029, $byname['nhs/metabase-widget']['attrs']['resourceId'] );
		$this->assertSame( '/kpi/total-drugs', $byname['nhs/ndo-widget']['attrs']['resourcePath'] );
		$this->assertSame( 'https://example.test/raw', $byname['core/html']['src'] );
	}

	/**
	 * Rendered HTML covers server-rendered leaf blocks only, never the widget
	 * blocks, and has tokens stripped.
	 */
	public function test_rendered_html_is_leaf_only_and_token_free(): void {
		$id      = self::factory()->post->create(
			array(
				'post_content' => '<!-- wp:test/dynamic /-->'
					. '<!-- wp:nhs/ndo-widget {"resourcePath":"/x"} /-->',
			)
		);
		$item    = ( new KME_Exporter() )->item( get_post( $id ) );
		$encoded = (string) wp_json_encode( $item['rendered'] );

		$this->assertStringContainsString( 'test-dynamic', $encoded );
		$this->assertStringContainsString( 'token=[removed]', $encoded );
		$this->assertStringNotContainsString( 'abc.def.ghi', $encoded );
		$this->assertStringNotContainsString( '"1"', $encoded, 'The widget block at path 1 must not be rendered.' );
	}

	/**
	 * Token stripping covers query parameters and Metabase signed-embed paths.
	 */
	public function test_strip_tokens(): void {
		$exporter = new KME_Exporter();

		$this->assertSame( '<a href="/x?token=[removed]&amp;a=1">', $exporter->strip_tokens( '<a href="/x?token=eyJhbGci.eyJzdWIi.sig&amp;a=1">' ) );
		$this->assertSame( 'https://metabase.test/embed/dashboard/[removed]#bordered', $exporter->strip_tokens( 'https://metabase.test/embed/dashboard/eyJhbGci.eyJyZXNvdXJjZSI.c2ln#bordered' ) );
	}

	/**
	 * The hash changes when an imported field changes, and not otherwise.
	 */
	public function test_content_hash_tracks_imported_fields(): void {
		$id       = self::factory()->post->create( array( 'post_content' => 'one' ) );
		$exporter = new KME_Exporter();
		$first    = $exporter->item( get_post( $id ) )['content_sha256'];

		$this->assertSame( $first, $exporter->item( get_post( $id ) )['content_sha256'] );

		update_post_meta( $id, '_nhs_show_hero', '1' );
		$this->assertNotSame( $first, $exporter->item( get_post( $id ) )['content_sha256'] );
	}

	/**
	 * --include limits the bundle to those items and their attachments.
	 */
	public function test_include_filter_keeps_child_attachments(): void {
		$kept    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$dropped = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$image   = self::factory()->attachment->create_object( 'photo.png', $kept, array( 'post_mime_type' => 'image/png' ) );

		$ids = array_keys( ( new KME_Exporter( false, array( $kept ) ) )->build()['items'] );

		$this->assertContains( $kept, $ids );
		$this->assertContains( $image, $ids );
		$this->assertNotContains( $dropped, $ids );
	}

	/**
	 * Anonymised builds contain no real author identities.
	 */
	public function test_anonymised_authors(): void {
		$user = self::factory()->user->create(
			array(
				'user_login' => 'realperson',
				'user_email' => 'real.person@example.org',
			)
		);
		self::factory()->post->create( array( 'post_author' => $user ) );

		$bundle  = ( new KME_Exporter( true ) )->build();
		$encoded = (string) wp_json_encode( $bundle );

		$this->assertStringNotContainsString( 'realperson', $encoded );
		$this->assertStringNotContainsString( 'real.person@example.org', $encoded );
		$this->assertContains( 'author-' . $user, array_column( $bundle['users'], 'login' ) );
	}

	/**
	 * The zip has the contract layout, media.json has no server paths, and every
	 * document validates against its schema.
	 */
	public function test_zip_layout_and_schemas(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/test-image.png', $page );

		$zip_path        = wp_tempnam( 'kme-test.zip' );
		$this->cleanup[] = $zip_path;
		( new KME_Export_Bundle_Writer() )->write_zip( ( new KME_Exporter() )->build(), $zip_path );

		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $zip_path ) );

		foreach ( array( 'manifest.json', 'media.json', 'menus.json', 'users.json', 'options.json', 'items/' . $page . '.json' ) as $name ) {
			$this->assertNotFalse( $zip->locateName( $name ), $name . ' is missing' );
		}

		$media = json_decode( (string) $zip->getFromName( 'media.json' ), true );
		$this->assertNotEmpty( $media );
		$this->assertArrayNotHasKey( 'path', $media[0], 'Server file paths must not be written to media.json.' );
		$this->assertNotFalse( $zip->locateName( $media[0]['file'] ), 'The media file is missing from files/.' );

		$this->assert_valid( $zip, 'manifest.json', 'manifest.schema.json' );
		$this->assert_valid( $zip, 'media.json', 'media.schema.json' );
		$this->assert_valid( $zip, 'menus.json', 'menus.schema.json' );
		$this->assert_valid( $zip, 'users.json', 'users.schema.json' );
		$this->assert_valid( $zip, 'options.json', 'options.schema.json' );
		$this->assert_valid( $zip, 'items/' . $page . '.json', 'item.schema.json' );

		$zip->close();
	}

	/**
	 * Empty maps encode as JSON objects, not arrays.
	 */
	public function test_empty_maps_encode_as_objects(): void {
		$id   = self::factory()->post->create( array( 'post_content' => '' ) );
		$json = (string) wp_json_encode( ( new KME_Exporter() )->item( get_post( $id ) ) );

		$this->assertStringContainsString( '"rendered":{}', $json );
		$this->assertStringContainsString( '"blocks_used":{}', $json );
	}

	/**
	 * The "KME" option shows on Tools → Export for admins only.
	 */
	public function test_export_option_is_for_admins_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		KME_Export_Admin::render_option();
		$this->assertStringContainsString( 'value="' . KME_Export_Admin::CONTENT . '"', (string) ob_get_clean() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		KME_Export_Admin::render_option();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * The download refuses anyone without manage_options.
	 */
	public function test_download_requires_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->expectException( WPDieException::class );
		KME_Export_Admin::maybe_download( array( 'content' => KME_Export_Admin::CONTENT ) );
	}

	/**
	 * WordPress's own export choices are left alone.
	 */
	public function test_other_exports_are_left_alone(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		KME_Export_Admin::maybe_download( array( 'content' => 'all' ) );
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * Building a bundle never writes to the database.
	 */
	public function test_build_is_read_only(): void {
		global $wpdb;
		self::factory()->post->create_many( 3 );

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) . ':' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		( new KME_Exporter() )->build();
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) . ':' . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertSame( $before, $after );
	}

	// ---------------------------------------------------------------------------------------
	// KME additions: archived versions, pending copies, presets, the chart embed.
	// ---------------------------------------------------------------------------------------

	/**
	 * Register KME's "archive" status, as the Archived Post Status plugin does on KME.
	 *
	 * @return void
	 */
	private function register_archive_status(): void {
		if ( ! get_post_status_object( KME_Exporter::ARCHIVE_STATUS ) ) {
			register_post_status( KME_Exporter::ARCHIVE_STATUS, array( 'label' => 'Archived' ) );
		}
	}

	/**
	 * An archived version, as Ready for Live leaves it: the live slug plus "-archived-<time>",
	 * the title plus " - Archived on <date>", and a link to the version before it.
	 *
	 * @param string $slug     The live post's slug.
	 * @param string $title    The live post's title.
	 * @param int    $previous The version before this one, or 0.
	 * @param string $date     When this version was published.
	 * @return int
	 */
	private function archive( string $slug, string $title, int $previous, string $date ): int {
		$id = self::factory()->post->create(
			array(
				'post_status'  => KME_Exporter::ARCHIVE_STATUS,
				'post_name'    => $slug . '-archived-' . wp_rand( 1000000, 9999999 ),
				'post_title'   => $title . ' - Archived on January 1, 2026',
				'post_content' => '<!-- wp:paragraph --><p>Version from ' . $date . '</p><!-- /wp:paragraph -->',
				'post_date'    => $date,
			)
		);
		if ( $previous > 0 ) {
			update_post_meta( $id, KME_Exporter::ORIGINAL_META, $previous );
		}

		return $id;
	}

	/**
	 * A live report's archived versions are exported oldest first, with the archive suffix
	 * removed from their titles, and counted in the manifest.
	 */
	public function test_archive_chain_is_exported_oldest_first(): void {
		$this->register_archive_status();
		$oldest = $this->archive( 'report', 'Report', 0, '2025-01-01 10:00:00' );
		$middle = $this->archive( 'report', 'Report', $oldest, '2025-02-01 10:00:00' );
		$live   = self::factory()->post->create(
			array(
				'post_name'  => 'report',
				'post_title' => 'Report',
			)
		);
		update_post_meta( $live, KME_Exporter::ORIGINAL_META, $middle );

		$bundle = ( new KME_Exporter() )->build();
		$item   = $bundle['items'][ $live ];

		$this->assertSame( array( $oldest, $middle ), array_column( $item['archives'], 'archive_id' ) );
		$this->assertSame( 'Report', $item['archives'][0]['title'] );
		$this->assertSame( 2, $bundle['manifest']['counts']['archives_chained'] );
		$this->assertSame( 0, $bundle['manifest']['counts']['archives_orphaned'] );
		$this->assertArrayNotHasKey( $oldest, $bundle['items'], 'Archived versions are not items of their own.' );
	}

	/**
	 * A report duplicated from another molecule's report keeps that report's
	 * `_original_post_id`. The chain stops there (the slug doesn't match), and the other
	 * report's archives are listed as orphans instead.
	 */
	public function test_archive_chain_stops_at_another_reports_history(): void {
		$this->register_archive_status();
		$other = $this->archive( 'compare-for-pomalidomide', 'Compare for pomalidomide', 0, '2025-01-01 10:00:00' );
		$live  = self::factory()->post->create( array( 'post_name' => 'compare-for-nintedanib' ) );
		update_post_meta( $live, KME_Exporter::ORIGINAL_META, $other );

		$bundle = ( new KME_Exporter() )->build();

		$this->assertSame( array(), $bundle['items'][ $live ]['archives'] );
		$this->assertSame( array( $other ), array_column( $bundle['manifest']['orphan_archives'], 'id' ) );
	}

	/**
	 * A pending Ready for Live copy names the live post it replaces, and has no archive
	 * chain of its own.
	 */
	public function test_pending_copy_names_its_original(): void {
		$live    = self::factory()->post->create();
		$pending = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		update_post_meta( $pending, KME_Exporter::ORIGINAL_META, $live );

		$bundle = ( new KME_Exporter() )->build();

		$this->assertSame( $live, $bundle['items'][ $pending ]['original_post_id'] );
		$this->assertSame( array(), $bundle['items'][ $pending ]['archives'] );
		$this->assertNull( $bundle['items'][ $live ]['original_post_id'] );
		$this->assertSame( 1, $bundle['manifest']['counts']['pending_copies'] );
	}

	/**
	 * Preset blocks are listed by title; the chart embed is found with its URL and never
	 * rendered (its render brokers a token).
	 */
	public function test_presets_used_and_chart_embed(): void {
		$url     = 'https://kme.sps.direct/grouped_by_trust/Adoption/?selected_molecule=nintedanib&production=true';
		$content = '<!-- wp:create-block/todo-list {"text":"Intro"} --><p class="wp-block-create-block-todo-list">x</p><!-- /wp:create-block/todo-list -->'
			. '<!-- wp:create-block/kme-embed ' . serialize_block_attributes( array( 'iframe_url' => $url ) ) . ' --><p>x</p><!-- /wp:create-block/kme-embed -->'
			. '<!-- wp:create-block/todo-list {"text":"Intro"} --><p class="wp-block-create-block-todo-list">x</p><!-- /wp:create-block/todo-list -->';
		// As KME's editors save it (they have unfiltered_html, so KSES doesn't touch block
		// attributes). wp_insert_post() unslashes, which would strip the backslash from \u0026.
		kses_remove_filters();
		$id = self::factory()->post->create( array( 'post_content' => wp_slash( $content ) ) );
		kses_init_filters();
		$item    = ( new KME_Exporter() )->item( get_post( $id ) );

		$this->assertSame( array( 'Intro' ), $item['presets_used'] );
		$this->assertSame( KME_Exporter::EMBED_BLOCK, $item['embeds'][0]['block'] );
		$this->assertSame( '1', $item['embeds'][0]['path'] );
		$this->assertSame( $url, $item['embeds'][0]['src'] );
		$this->assertArrayNotHasKey( '1', (array) $item['rendered'] );
	}

	/**
	 * Without ACF (as in this test install) there are no presets and no ACF values, and the
	 * bundle is still valid.
	 */
	public function test_without_acf_the_kme_documents_are_empty(): void {
		if ( function_exists( 'get_field_objects' ) ) {
			$this->markTestSkipped( 'ACF is active.' );
		}
		$id     = self::factory()->post->create();
		$bundle = ( new KME_Exporter() )->build();

		$this->assertSame( array(), $bundle['options']['presets'] );
		$this->assertSame( '{}', (string) wp_json_encode( $bundle['items'][ $id ]['acf'] ) );
	}

	/**
	 * Assert a zip entry validates against a schema file.
	 *
	 * @param ZipArchive $zip    Open zip.
	 * @param string     $name   Entry name.
	 * @param string     $schema Schema filename in schema/.
	 * @return void
	 */
	private function assert_valid( ZipArchive $zip, string $name, string $schema ): void {
		$data      = json_decode( (string) $zip->getFromName( $name ) );
		$validator = new Validator();
		$validator->validate(
			$data,
			(object) array( '$ref' => 'file://' . str_replace( '\\', '/', dirname( __DIR__, 2 ) ) . '/schema/' . $schema ),
			Constraint::CHECK_MODE_NORMAL
		);

		$messages = array_map( static fn( array $e ): string => $e['property'] . ': ' . $e['message'], $validator->getErrors() );
		$this->assertTrue( $validator->isValid(), $name . ' does not match ' . $schema . ":\n" . implode( "\n", $messages ) );
	}

	/**
	 * Remove a file or directory tree.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	private function remove( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}
}
