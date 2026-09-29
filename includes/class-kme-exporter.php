<?php
/**
 * Builds the contents of a KME export bundle from the live WordPress data.
 *
 * Pure reads through WordPress APIs: nothing here writes to the database or the
 * filesystem. {@see KME_Export_Bundle_Writer} turns the result into a zip or a
 * directory. The shape of every document is specified in docs/contract.md and
 * schema/*.json, which are the source of truth for SPS2's importer.
 *
 * @package kme-exporter
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects items, revisions, media, menus and authors into bundle documents.
 */
class KME_Exporter {

	/**
	 * Contract identifier written to every manifest.
	 */
	public const SCHEMA = 'kme-export';

	/**
	 * Contract version. Minor bumps only add optional fields; a major bump is breaking.
	 */
	public const SCHEMA_VERSION = '1.1';

	/**
	 * Post types that are exported as items.
	 */
	public const POST_TYPES = array( 'page', 'post', 'attachment' );

	/**
	 * Statuses exported for pages and posts. Trash and auto-drafts are left out.
	 */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future' );

	/**
	 * Meta keys that are never exported: editor bookkeeping and data that is
	 * carried elsewhere in the bundle (the template, attachment file details).
	 */
	private const SKIPPED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wp_desired_post_slug',
		'_wp_page_template',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_backup_sizes',
		'_encloseme',
		'_pingme',
	);

	/**
	 * Protected (underscore) meta prefixes that are still exported, because they
	 * carry content or display settings SPS2 needs.
	 */
	private const EXPORTED_PROTECTED_PREFIXES = array( '_nhs_', '_members_', '_thumbnail_id', '_wp_attachment_image_alt' );

	/**
	 * Blocks whose rendered HTML is never exported. They sign short-lived tokens
	 * into their output (a JWT in the iframe URL); their attributes are enough
	 * for SPS2 to rebuild them. KME's chart embed brokers one too.
	 */
	private const NO_RENDER_BLOCKS = array( 'nhs/ndo-widget', 'nhs/metabase-widget', 'create-block/kme-embed' );

	/**
	 * The KME chart embed block ("KME Embed", Key-Molecules-Plugin).
	 */
	public const EMBED_BLOCK = 'create-block/kme-embed';

	/**
	 * The preset text block ("Todo List", Key-Molecules-Plugin). Its `text`
	 * attribute names an entry of the `preset_text_list` ACF option.
	 */
	public const PRESET_BLOCK = 'create-block/todo-list';

	/**
	 * The post status the "Ready for Live" workflow gives a replaced version.
	 */
	public const ARCHIVE_STATUS = 'archive';

	/**
	 * Links a newer version to the version it replaced (set by Ready for Live).
	 */
	public const ORIGINAL_META = '_original_post_id';

	/**
	 * How far back an archive chain is followed. The deepest on KME is 12.
	 */
	private const MAX_ARCHIVE_DEPTH = 100;

	/**
	 * Replace author logins and emails with placeholders. Used when generating
	 * the committed contract fixture, so no real accounts end up in the repo.
	 *
	 * @var bool
	 */
	private bool $anonymise_authors;

	/**
	 * Limit the bundle to these item IDs (plus their attachments). Empty = everything.
	 *
	 * @var list<int>
	 */
	private array $include_ids;

	/**
	 * Keep only each item's newest N revisions. 0 = all. Used for small fixtures.
	 *
	 * @var int
	 */
	private int $max_revisions;

	/**
	 * Authors seen while building, keyed by user ID.
	 *
	 * @var array<int, array{login: string, email: string, display_name: string}>
	 */
	private array $authors = array();

	/**
	 * Constructor.
	 *
	 * @param bool  $anonymise_authors Replace author identities with placeholders.
	 * @param int[] $include_ids       Only export these items (plus their attachments). Empty = everything.
	 * @param int   $max_revisions     Keep each item's newest N revisions. 0 = all.
	 */
	public function __construct( bool $anonymise_authors = false, array $include_ids = array(), int $max_revisions = 0 ) {
		$this->anonymise_authors = $anonymise_authors;
		$this->include_ids       = array_values( array_unique( array_map( 'intval', $include_ids ) ) );
		$this->max_revisions     = max( 0, $max_revisions );
	}

	/**
	 * Build every document in the bundle.
	 *
	 * @return array{manifest: array<string, mixed>, items: array<int, array<string, mixed>>, media: list<array<string, mixed>>, menus: array<string, mixed>, users: list<array<string, string>>, options: array<string, mixed>}
	 */
	public function build(): array {
		$this->authors = array();

		$items   = array();
		$chained = array();
		foreach ( $this->posts() as $post ) {
			$items[ $post->ID ] = $this->item( $post );
			foreach ( $items[ $post->ID ]['archives'] as $archive ) {
				$chained[ (int) $archive['archive_id'] ] = true;
			}
		}

		$media = array();
		foreach ( $items as $item ) {
			if ( 'attachment' === $item['type'] ) {
				$media[] = $this->media_entry( (int) $item['source_id'] );
			}
		}

		return array(
			'manifest' => $this->manifest( $items, $media, $chained ),
			'items'    => $items,
			'media'    => $media,
			'menus'    => $this->menus(),
			'users'    => array_values( $this->authors ),
			'options'  => $this->options(),
		);
	}

	/**
	 * A cheap summary for the admin screen: counts only, no rendering or hashing.
	 *
	 * @return array{by_type: array<string, array<string, int>>, revisions: int, archives: int, archives_orphaned: int, media: int, embeds: int, presets: int}
	 */
	public function summary(): array {
		$by_type   = array();
		$revisions = 0;
		$archives  = 0;
		$media     = 0;
		$embeds    = 0;

		foreach ( $this->posts() as $post ) {
			$by_type[ $post->post_type ][ $post->post_status ] = ( $by_type[ $post->post_type ][ $post->post_status ] ?? 0 ) + 1;

			if ( 'attachment' === $post->post_type ) {
				++$media;
				continue;
			}

			$revisions += count( $this->revision_posts( $post->ID ) );
			$archives  += count( $this->archive_chain( $post ) );
			$embeds    += count( $this->embeds( parse_blocks( $post->post_content ) ) );
		}

		return array(
			'by_type'           => $by_type,
			'revisions'         => $revisions,
			'archives'          => $archives,
			'archives_orphaned' => max( 0, $this->archive_count() - $archives ),
			'media'             => $media,
			'embeds'            => $embeds,
			'presets'           => count( $this->presets() ),
		);
	}

	/**
	 * Every exported post, oldest ID first so bundles diff cleanly.
	 *
	 * @return list<WP_Post>
	 */
	public function posts(): array {
		$content = get_posts(
			array(
				'post_type'        => array( 'page', 'post' ),
				'post_status'      => self::STATUSES,
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$attachments = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);

		$posts = array_merge( $content, $attachments );

		if ( array() !== $this->include_ids ) {
			$posts = $this->filter_included( $posts );
		}

		usort( $posts, static fn( WP_Post $a, WP_Post $b ): int => $a->ID <=> $b->ID );

		return $posts;
	}

	/**
	 * Keep the included items, plus attachments that are their children or
	 * featured images.
	 *
	 * @param WP_Post[] $posts All exportable posts.
	 * @return list<WP_Post>
	 */
	private function filter_included( array $posts ): array {
		$keep = array_flip( $this->include_ids );

		foreach ( $this->include_ids as $id ) {
			$thumbnail = (int) get_post_thumbnail_id( $id );
			if ( $thumbnail > 0 ) {
				$keep[ $thumbnail ] = true;
			}
		}

		return array_values(
			array_filter(
				$posts,
				static fn( WP_Post $post ): bool => isset( $keep[ $post->ID ] )
					|| ( 'attachment' === $post->post_type && isset( $keep[ (int) $post->post_parent ] ) )
			)
		);
	}

	/**
	 * One item document.
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, mixed>
	 */
	public function item( WP_Post $post ): array {
		$blocks    = parse_blocks( $post->post_content );
		$permalink = (string) get_permalink( $post );

		$item = array(
			'source_id'        => $post->ID,
			'type'             => $post->post_type,
			'status'           => $post->post_status,
			'slug'             => $post->post_name,
			'title'            => $post->post_title,
			'parent'           => $post->post_parent ? (int) $post->post_parent : null,
			'menu_order'       => (int) $post->menu_order,
			'url_path'         => $this->url_path( $permalink ),
			'link'             => $permalink,
			'template'         => (string) get_page_template_slug( $post ),
			'content_raw'      => $post->post_content,
			'excerpt'          => $post->post_excerpt,
			'date_gmt'         => $this->gmt( $post->post_date_gmt ),
			'modified_gmt'     => $this->gmt( $post->post_modified_gmt ),
			'author'           => $this->author( (int) $post->post_author ),
			'featured_media'   => has_post_thumbnail( $post ) ? (int) get_post_thumbnail_id( $post ) : null,
			'attachments'      => $this->attachment_ids( $post->ID ),
			'meta'             => $this->meta( $post->ID ),
			'acf'              => $this->acf( $post->ID ),
			'terms'            => $this->terms( $post ),
			'term_names'       => $this->term_names( $post ),
			'original_post_id' => $this->original_post_id( $post ),
			'embeds'           => $this->embeds( $blocks ),
			'links'            => $this->links( $post->post_content ),
			'blocks_used'      => $this->blocks_used( $blocks ),
			'presets_used'     => $this->presets_used( $blocks ),
			'rendered'         => $this->rendered( $blocks, $post ),
			'revisions'        => $this->revisions( $post->ID ),
			'archives'         => $this->archives( $post ),
		);

		$item['content_sha256'] = $this->content_hash( $item );

		// Maps must encode as JSON objects even when empty ({} not []), so the
		// contract's types hold for every item.
		foreach ( array( 'meta', 'acf', 'terms', 'term_names', 'blocks_used', 'rendered' ) as $map ) {
			$item[ $map ] = $this->as_map( $item[ $map ] );
		}

		return $item;
	}

	/**
	 * The fingerprint SPS2 compares on re-import. It covers every field the
	 * importer writes, and nothing that changes without an edit (links, dates).
	 *
	 * @param array<string, mixed> $item The item document.
	 * @return string
	 */
	public function content_hash( array $item ): string {
		$fields                 = array_intersect_key(
			$item,
			array_flip( array( 'type', 'status', 'slug', 'title', 'parent', 'menu_order', 'template', 'content_raw', 'excerpt', 'featured_media', 'meta', 'acf', 'terms', 'term_names', 'original_post_id' ) )
		);
		$fields['revision_ids'] = array_column( $item['revisions'] ?? array(), 'revision_id' );
		$fields['archive_ids']  = array_column( $item['archives'] ?? array(), 'archive_id' );

		return hash( 'sha256', (string) wp_json_encode( $fields ) );
	}

	/**
	 * The manifest, including the change index.
	 *
	 * @param array<int, array<string, mixed>> $items   Items keyed by source ID.
	 * @param list<array<string, mixed>>       $media   Media entries.
	 * @param array<int, bool>                 $chained Archive IDs reached from an exported item.
	 * @return array<string, mixed>
	 */
	private function manifest( array $items, array $media, array $chained ): array {
		$counts  = array();
		$index   = array();
		$revs    = 0;
		$pending = 0;

		foreach ( $items as $item ) {
			$counts[ $item['type'] ][ $item['status'] ] = ( $counts[ $item['type'] ][ $item['status'] ] ?? 0 ) + 1;
			$revs                                      += count( $item['revisions'] );
			if ( null !== $item['original_post_id'] ) {
				++$pending;
			}

			$index[] = array(
				'source_id'      => $item['source_id'],
				'type'           => $item['type'],
				'status'         => $item['status'],
				'parent'         => $item['parent'],
				'modified_gmt'   => $item['modified_gmt'],
				'content_sha256' => $item['content_sha256'],
			);
		}

		$theme   = wp_get_theme();
		$orphans = $this->orphan_archives( $chained );

		return array(
			'schema'          => self::SCHEMA,
			'schema_version'  => self::SCHEMA_VERSION,
			'generator'       => array(
				'name'    => 'kme-exporter',
				'version' => KME_EXPORT_VERSION,
			),
			'generated_at'    => gmdate( 'c' ),
			'site'            => array(
				'name'                => (string) get_bloginfo( 'name' ),
				'home'                => home_url( '/' ),
				'siteurl'             => site_url( '/' ),
				'wp_version'          => (string) get_bloginfo( 'version' ),
				'page_on_front'       => (int) get_option( 'page_on_front' ),
				'page_for_posts'      => (int) get_option( 'page_for_posts' ),
				'permalink_structure' => (string) get_option( 'permalink_structure' ),
				'theme'               => array(
					'stylesheet' => $theme->get_stylesheet(),
					'version'    => (string) $theme->get( 'Version' ),
				),
			),
			'counts'          => array(
				'items'             => $counts,
				'revisions'         => $revs,
				'media_files'       => count( array_filter( $media, static fn( array $m ): bool => ! $m['missing'] ) ),
				'media_missing'     => count( array_filter( $media, static fn( array $m ): bool => (bool) $m['missing'] ) ),
				'archives_chained'  => count( $chained ),
				'archives_orphaned' => count( $orphans ),
				'pending_copies'    => $pending,
				'presets'           => count( $this->presets() ),
			),
			'change_index'    => $index,
			'orphan_archives' => $orphans,
		);
	}

	/**
	 * A media entry (the file itself is copied by the writer).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function media_entry( int $attachment_id ): array {
		$path    = (string) get_attached_file( $attachment_id );
		$exists  = '' !== $path && is_readable( $path );
		$name    = $exists ? wp_basename( $path ) : wp_basename( (string) get_post_meta( $attachment_id, '_wp_attached_file', true ) );
		$post    = get_post( $attachment_id );
		$caption = $post instanceof WP_Post ? $post->post_excerpt : '';

		return array(
			'source_id' => $attachment_id,
			'file'      => 'files/' . $attachment_id . '/' . $name,
			'filename'  => $name,
			'mime_type' => (string) get_post_mime_type( $attachment_id ),
			'title'     => get_the_title( $attachment_id ),
			'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption'   => $caption,
			'parent'    => $post instanceof WP_Post && $post->post_parent ? (int) $post->post_parent : null,
			'url'       => (string) wp_get_attachment_url( $attachment_id ),
			'filesize'  => $exists ? (int) filesize( $path ) : null,
			'sha256'    => $exists ? (string) hash_file( 'sha256', $path ) : null,
			'missing'   => ! $exists,
			'path'      => $exists ? $path : null,
		);
	}

	/**
	 * Menus, their locations and their items.
	 *
	 * @return array<string, mixed>
	 */
	public function menus(): array {
		$locations = array();
		foreach ( (array) get_nav_menu_locations() as $location => $menu_id ) {
			$locations[ $location ] = (int) $menu_id;
		}

		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}
				$items[] = array(
					'id'        => (int) $item->ID,
					'parent'    => (int) $item->menu_item_parent ? (int) $item->menu_item_parent : null,
					'order'     => (int) $item->menu_order,
					'title'     => (string) $item->title,
					'url'       => (string) $item->url,
					'url_path'  => $this->url_path( (string) $item->url ),
					'type'      => (string) $item->type,
					'object'    => (string) $item->object,
					'object_id' => (int) $item->object_id,
				);
			}

			$menus[] = array(
				'id'    => (int) $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'items' => $items,
			);
		}

		return array(
			'locations' => $this->as_map( $locations ),
			'menus'     => $menus,
		);
	}

	/**
	 * Revisions of a post, oldest first, without autosaves.
	 *
	 * @param int $post_id Post ID.
	 * @return list<array<string, mixed>>
	 */
	private function revisions( int $post_id ): array {
		$out = array();
		foreach ( $this->revision_posts( $post_id ) as $revision ) {
			$out[] = array(
				'revision_id' => $revision->ID,
				'date_gmt'    => $this->gmt( $revision->post_date_gmt ),
				'author'      => $this->author( (int) $revision->post_author ),
				'title'       => $revision->post_title,
				'excerpt'     => $revision->post_excerpt,
				'content_raw' => $revision->post_content,
			);
		}

		return $out;
	}

	/**
	 * Revision posts for a post, oldest first, autosaves excluded.
	 *
	 * @param int $post_id Post ID.
	 * @return list<WP_Post>
	 */
	private function revision_posts( int $post_id ): array {
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'order'   => 'ASC',
				'orderby' => 'ID',
			)
		);

		$revisions = array_values(
			array_filter(
				$revisions,
				static fn( $revision ): bool => $revision instanceof WP_Post && ! wp_is_post_autosave( $revision )
			)
		);

		return $this->max_revisions > 0 ? array_slice( $revisions, -$this->max_revisions ) : $revisions;
	}

	/**
	 * Record an author and return the reference stored on items and revisions.
	 *
	 * @param int $user_id User ID.
	 * @return array{login: string, email: string}|null
	 */
	private function author( int $user_id ): ?array {
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( ! isset( $this->authors[ $user_id ] ) ) {
			$user = get_userdata( $user_id );
			if ( ! $user instanceof WP_User ) {
				return null;
			}

			$this->authors[ $user_id ] = $this->anonymise_authors
				? array(
					'login'        => 'author-' . $user_id,
					'email'        => 'author-' . $user_id . '@example.invalid',
					'display_name' => 'Author ' . $user_id,
				)
				: array(
					'login'        => $user->user_login,
					'email'        => $user->user_email,
					'display_name' => $user->display_name,
				);
		}

		return array(
			'login' => $this->authors[ $user_id ]['login'],
			'email' => $this->authors[ $user_id ]['email'],
		);
	}

	/**
	 * Child attachment IDs.
	 *
	 * @param int $post_id Post ID.
	 * @return list<int>
	 */
	private function attachment_ids( int $post_id ): array {
		$ids = get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
			)
		);

		return array_map( 'intval', array_values( (array) $ids ) );
	}

	/**
	 * Exported post meta: every non-protected key plus the allowed protected ones.
	 * Values are always lists, because a key can hold several rows.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, list<mixed>>
	 */
	private function meta( int $post_id ): array {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			$key = (string) $key;
			if ( ! $this->export_meta_key( $key, $post_id ) ) {
				continue;
			}
			$out[ $key ] = array_map( 'maybe_unserialize', array_values( (array) $values ) );
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Whether a meta key is exported.
	 *
	 * @param string $key     Meta key.
	 * @param int    $post_id Post the key belongs to (0 skips the ACF check).
	 * @return bool
	 */
	private function export_meta_key( string $key, int $post_id = 0 ): bool {
		if ( in_array( $key, self::SKIPPED_META, true ) ) {
			return false;
		}

		// ACF values are exported under `acf`, by field name. Their raw rows
		// (blocks_0_type, _blocks_0_type = field_…) would only duplicate them.
		if ( $post_id > 0 && $this->is_acf_meta( $key, $post_id ) ) {
			return false;
		}

		if ( ! is_protected_meta( $key, 'post' ) ) {
			return true;
		}

		foreach ( self::EXPORTED_PROTECTED_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Term slugs per taxonomy.
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, list<string>>
	 */
	private function terms( WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}
			$slugs = array_map( static fn( WP_Term $term ): string => $term->slug, $terms );
			sort( $slugs );
			$out[ $taxonomy ] = $slugs;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Whether a meta row belongs to an ACF field: the value row `name` has a
	 * reference row `_name` holding a field key, and the reference row is the
	 * `_name` of such a value row.
	 *
	 * @param string $key     Meta key.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	private function is_acf_meta( string $key, int $post_id ): bool {
		$reference = get_post_meta( $post_id, '_' . $key, true );
		if ( is_string( $reference ) && str_starts_with( $reference, 'field_' ) ) {
			return true;
		}

		$value = get_post_meta( $post_id, $key, true );

		return str_starts_with( $key, '_' ) && is_string( $value ) && str_starts_with( $value, 'field_' );
	}

	/**
	 * The post's ACF values by field name, unformatted: relationships are post
	 * IDs, dates are Ymd, wysiwyg is the saved HTML. Empty when ACF isn't active.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	public function acf( int $post_id ): array {
		if ( ! function_exists( 'get_field_objects' ) ) {
			return array();
		}

		$fields = get_field_objects( $post_id, false );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$out = array();
		foreach ( $fields as $name => $field ) {
			$out[ (string) $name ] = $this->acf_typed( (string) ( $field['type'] ?? '' ), $field['value'] ?? null );
		}
		ksort( $out );

		return $out;
	}

	/**
	 * One field's value by its ACF type: relationship-like fields become lists of
	 * post IDs; everything else goes through {@see self::acf_value()}.
	 *
	 * @param string $type  ACF field type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private function acf_typed( string $type, $value ) {
		if ( in_array( $type, array( 'relationship', 'post_object', 'page_link' ), true ) ) {
			return array_values( array_map( 'intval', array_filter( (array) $value, 'is_numeric' ) ) );
		}

		return $this->acf_value( $value );
	}

	/**
	 * Normalise an unformatted ACF value. Repeater rows come back keyed by field
	 * key (field_…); they are re-keyed by field name so the bundle is readable
	 * and doesn't depend on KME's field keys. Numeric strings in relationship
	 * lists become integers.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private function acf_value( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $inner ) {
			if ( is_string( $key ) && str_starts_with( $key, 'field_' ) && function_exists( 'acf_get_field' ) ) {
				$field = acf_get_field( $key );
				$name  = is_array( $field ) && '' !== (string) ( $field['name'] ?? '' ) ? (string) $field['name'] : $key;
				$type  = is_array( $field ) ? (string) ( $field['type'] ?? '' ) : '';

				$out[ $name ] = $this->acf_typed( $type, $inner );
				continue;
			}
			$out[ $key ] = $this->acf_value( $inner );
		}

		return $out;
	}

	/**
	 * For a pending copy made by Ready for Live, the live post it will replace.
	 *
	 * @param WP_Post $post The post.
	 * @return int|null
	 */
	private function original_post_id( WP_Post $post ): ?int {
		if ( 'pending' !== $post->post_status ) {
			return null;
		}
		$original = (int) get_post_meta( $post->ID, self::ORIGINAL_META, true );

		return $original > 0 ? $original : null;
	}

	/**
	 * The archived versions a post replaced, newest first as found: each version
	 * points at the one before it through `_original_post_id`.
	 *
	 * Ready for Live gives every archived version the live slug plus
	 * "-archived-<time>", so a genuine chain keeps that prefix. The chain stops at
	 * the first link that doesn't: KME editors start a new molecule's reports by
	 * duplicating another molecule's, which copies `_original_post_id`, so without
	 * the check a new report would inherit the other molecule's history.
	 *
	 * @param WP_Post $post The post.
	 * @return list<WP_Post>
	 */
	private function archive_chain( WP_Post $post ): array {
		if ( 'post' !== $post->post_type || 'pending' === $post->post_status || '' === $post->post_name ) {
			return array();
		}

		$prefix = $post->post_name . '-archived-';
		$chain  = array();
		$seen   = array( $post->ID => true );
		$id     = (int) get_post_meta( $post->ID, self::ORIGINAL_META, true );

		for ( $depth = 0; $id > 0 && ! isset( $seen[ $id ] ) && $depth < self::MAX_ARCHIVE_DEPTH; $depth++ ) {
			$archive = get_post( $id );
			if ( ! $archive instanceof WP_Post || self::ARCHIVE_STATUS !== $archive->post_status || ! str_starts_with( $archive->post_name, $prefix ) ) {
				break;
			}
			$seen[ $id ] = true;
			$chain[]     = $archive;
			$id          = (int) get_post_meta( $archive->ID, self::ORIGINAL_META, true );
		}

		return $chain;
	}

	/**
	 * The archived versions of a post, oldest first. Ready for Live renamed each
	 * one "<title> - Archived on <date>" and flattened its presets to paragraphs;
	 * the suffix is removed here, and the content is left as it was archived.
	 *
	 * @param WP_Post $post The post.
	 * @return list<array<string, mixed>>
	 */
	private function archives( WP_Post $post ): array {
		$out = array();
		foreach ( array_reverse( $this->archive_chain( $post ) ) as $archive ) {
			$out[] = array(
				'archive_id'   => $archive->ID,
				'date_gmt'     => $this->gmt( $archive->post_date_gmt ),
				'archived_gmt' => $this->gmt( $archive->post_modified_gmt ),
				'author'       => $this->author( (int) $archive->post_author ),
				'title'        => (string) preg_replace( '/ - Archived on .+$/', '', $archive->post_title ),
				'excerpt'      => $archive->post_excerpt,
				'content_raw'  => $archive->post_content,
				'acf'          => $this->as_map( $this->acf( $archive->ID ) ),
			);
		}

		return $out;
	}

	/**
	 * How many posts are in the archive status.
	 *
	 * @return int
	 */
	private function archive_count(): int {
		$counts = wp_count_posts( 'post' );

		return (int) ( $counts->{self::ARCHIVE_STATUS} ?? 0 );
	}

	/**
	 * Archived posts that no exported item chains to: versions of molecules since
	 * removed from KME, and the history of reports that new reports were
	 * duplicated from. Listed so the import report can account for them.
	 *
	 * @param array<int, bool> $chained Archive IDs reached from an exported item.
	 * @return list<array{id: int, title: string, parent: int|null}>
	 */
	private function orphan_archives( array $chained ): array {
		$ids = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => self::ARCHIVE_STATUS,
				'numberposts'      => -1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $chained[ $id ] ) ) {
				continue;
			}
			$parent = $this->acf( $id )['parent'] ?? array();
			$out[]  = array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'parent' => is_array( $parent ) && array() !== $parent ? (int) reset( $parent ) : null,
			);
		}

		return $out;
	}

	/**
	 * The shared preset texts (the `preset_text_list` ACF option), in order.
	 *
	 * @return list<array{title: string, text: string, sha256: string}>
	 */
	public function presets(): array {
		if ( ! function_exists( 'get_field' ) ) {
			return array();
		}

		$rows = get_field( 'preset_text_list', 'option' );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$title = trim( (string) ( $row['preset_title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$text  = (string) ( $row['preset_text'] ?? '' );
			$out[] = array(
				'title'  => $title,
				'text'   => $text,
				'sha256' => hash( 'sha256', $title . "\n" . $text ),
			);
		}

		return $out;
	}

	/**
	 * The options document: the presets and a hash over all of them.
	 *
	 * @return array<string, mixed>
	 */
	public function options(): array {
		$presets = $this->presets();

		return array(
			'presets'        => $presets,
			'presets_sha256' => hash( 'sha256', (string) wp_json_encode( $presets ) ),
		);
	}

	/**
	 * Preset titles referenced by preset blocks, unique, in order of use.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return list<string>
	 */
	public function presets_used( array $blocks ): array {
		$used = array();
		$walk = static function ( array $nodes ) use ( &$walk, &$used ): void {
			foreach ( $nodes as $block ) {
				if ( self::PRESET_BLOCK === ( $block['blockName'] ?? '' ) ) {
					$title = trim( (string) ( $block['attrs']['text'] ?? '' ) );
					if ( '' !== $title ) {
						$used[ $title ] = true;
					}
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );

		return array_keys( $used );
	}

	/**
	 * The display name of each term in `terms`, by taxonomy then slug. Slugs alone can't be
	 * turned back into names (a slug may carry an old typo, or differ in wording), and the
	 * names are what KME shows as the report's topics.
	 *
	 * @param WP_Post $post The post.
	 * @return array<string, array<string, string>>
	 */
	private function term_names( WP_Post $post ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}
			$names = array();
			foreach ( $terms as $term ) {
				$names[ $term->slug ] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			ksort( $names );
			$out[ $taxonomy ] = $names;
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Every embed in the block tree, with its position ("0", "2.1", …).
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param string                           $prefix Position prefix for recursion.
	 * @return list<array<string, mixed>>
	 */
	public function embeds( array $blocks, string $prefix = '' ): array {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			$path  = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			switch ( $name ) {
				case 'nhs/ndo-widget':
				case 'nhs/metabase-widget':
					$out[] = array(
						'block' => $name,
						'path'  => $path,
						'attrs' => $attrs,
					);
					break;
				case self::EMBED_BLOCK:
					$out[] = array(
						'block' => $name,
						'path'  => $path,
						'src'   => (string) ( $attrs['iframe_url'] ?? '' ),
					);
					break;
				case 'nhs/iframe':
					$out[] = array(
						'block' => $name,
						'path'  => $path,
						'src'   => (string) ( $attrs['src'] ?? '' ),
					);
					break;
				case 'core/embed':
					$out[] = array(
						'block' => $name,
						'path'  => $path,
						'src'   => (string) ( $attrs['url'] ?? '' ),
					);
					break;
				default:
					foreach ( $this->iframe_srcs( (string) ( $block['innerHTML'] ?? '' ) ) as $src ) {
						$out[] = array(
							'block' => '' === $name ? 'freeform' : $name,
							'path'  => $path,
							'src'   => $src,
						);
					}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, $this->embeds( $block['innerBlocks'], $path ) );
			}
		}

		return $out;
	}

	/**
	 * Raw iframe sources in a piece of HTML.
	 *
	 * @param string $html HTML.
	 * @return list<string>
	 */
	private function iframe_srcs( string $html ): array {
		if ( false === stripos( $html, '<iframe' ) ) {
			return array();
		}

		$srcs = array();
		$tags = new WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
			$src = $tags->get_attribute( 'src' );
			if ( is_string( $src ) && '' !== $src ) {
				$srcs[] = $src;
			}
		}

		return $srcs;
	}

	/**
	 * Internal links in the content (relative, or to this site's host), unique.
	 *
	 * @param string $content Raw post content.
	 * @return list<string>
	 */
	public function links( string $content ): array {
		if ( false === stripos( $content, 'href' ) ) {
			return array();
		}

		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$links = array();
		$tags  = new WP_HTML_Tag_Processor( $content );
		while ( $tags->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$href = $tags->get_attribute( 'href' );
			if ( ! is_string( $href ) || '' === $href ) {
				continue;
			}
			$link_host = (string) wp_parse_url( $href, PHP_URL_HOST );
			$relative  = str_starts_with( $href, '/' ) && ! str_starts_with( $href, '//' );
			if ( $relative || ( '' !== $host && strcasecmp( $link_host, $host ) === 0 ) ) {
				$links[ $href ] = true;
			}
		}

		return array_keys( $links );
	}

	/**
	 * Block names and how often each appears, including nested blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<string, int>
	 */
	public function blocks_used( array $blocks ): array {
		$counts = array();
		$walk   = static function ( array $nodes ) use ( &$walk, &$counts ): void {
			foreach ( $nodes as $block ) {
				$name = (string) ( $block['blockName'] ?? '' );
				if ( '' !== $name ) {
					$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );
		ksort( $counts );

		return $counts;
	}

	/**
	 * KME's own rendered HTML for server-rendered leaf blocks (no saved
	 * HTML), keyed by position. SPS2 falls back to it for blocks it can't map.
	 * Widget blocks are skipped, and any token-looking URL parts are stripped,
	 * so no signed credentials end up in the bundle.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param WP_Post                          $post   The post being exported.
	 * @param string                           $prefix Position prefix for recursion.
	 * @return array<string, string>
	 */
	public function rendered( array $blocks, WP_Post $post, string $prefix = '' ): array {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			$path = '' === $prefix ? (string) $i : $prefix . '.' . $i;
			$name = (string) ( $block['blockName'] ?? '' );

			// Leaf blocks only: rendering a container would also render its children
			// (including the widget blocks excluded above), and containers can be
			// rebuilt from their children anyway.
			$is_leaf = empty( $block['innerBlocks'] );

			if ( '' !== $name && $is_leaf && ! in_array( $name, self::NO_RENDER_BLOCKS, true ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
				$previous        = $GLOBALS['post'] ?? null;
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- dynamic blocks read the current post; restored below.
				setup_postdata( $post );
				$html = render_block( $block );
				wp_reset_postdata();
				$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the value saved above.

				$html = $this->strip_tokens( trim( $html ) );
				if ( '' !== $html ) {
					$out[ $path ] = $html;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out += $this->rendered( $block['innerBlocks'], $post, $path );
			}
		}

		return $out;
	}

	/**
	 * Remove signed-token URL parts from rendered HTML.
	 *
	 * @param string $html Rendered HTML.
	 * @return string
	 */
	public function strip_tokens( string $html ): string {
		// token=… / secret=… query parameters.
		$html = (string) preg_replace( '/([?&](?:token|secret|signature|access_token)=)[^&"\'\s<>]+/i', '$1[removed]', $html );
		// Metabase signed-embed paths: /embed/question/<jwt> or /embed/dashboard/<jwt>.
		return (string) preg_replace( '#(/embed/(?:question|dashboard)/)[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+#', '$1[removed]', $html );
	}

	/**
	 * An associative array that always encodes as a JSON object: when it's empty,
	 * and when its keys look like a list (block paths "0", "1" become integer
	 * keys in PHP, which wp_json_encode() would otherwise write as an array).
	 *
	 * @param array<mixed> $map Associative array.
	 * @return array<mixed>|stdClass
	 */
	private function as_map( array $map ) {
		return array_is_list( $map ) ? (object) $map : $map;
	}

	/**
	 * Path part of a URL on this site, or '' for external URLs.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_path( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' !== $host && strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) !== 0 ) {
			return '';
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '' === $path ? '/' : $path;
	}

	/**
	 * A GMT MySQL date as ISO 8601, or null for the zero date.
	 *
	 * @param string $mysql_gmt MySQL GMT datetime.
	 * @return string|null
	 */
	private function gmt( string $mysql_gmt ): ?string {
		if ( '' === $mysql_gmt || str_starts_with( $mysql_gmt, '0000-00-00' ) ) {
			return null;
		}

		return gmdate( 'c', (int) strtotime( $mysql_gmt . ' UTC' ) );
	}
}
