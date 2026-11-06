<?php
/**
 * REST API for the Archer builder.
 *
 * - GET    /archer-ct/v1/types           all definitions
 * - POST   /archer-ct/v1/types           add or update definitions ({types:[...], replace:false})
 * - DELETE /archer-ct/v1/types/<key>     remove a definition (the posts are kept)
 * - POST   /archer-ct/v1/template        Bricks template for one item of a type ({type, title})
 * - POST   /archer-ct/v1/megamenu        Bricks mega menu templates for big menu items ({menu, items:[{url,title}]})
 * - POST   /archer-ct/v1/items           add or update items of one type ({type, items:[...]})
 *
 * Items carry a "uid" from the builder. Publishing again updates an item only while
 * nobody changed it on the site; an item the client edited is left alone.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class Archer_CT_Rest {

	const NS = 'archer-ct/v1';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ), 10, 0 );
	}

	/**
	 * Only administrators (the builder logs in as one).
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Routes.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/types',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_types' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/types/(?P<key>[a-z0-9_]{1,20})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_type' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/template',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_template' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/megamenu',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_megamenu' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NS,
			'/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_items' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/filters',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'index_filters' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * POST /filters: turn on Bricks query filters and index the filter elements of the given pages.
	 *
	 * The Archer builder writes pages straight into the Bricks meta, so Bricks never saw their filter
	 * elements. This registers them and builds the index from the items that exist now.
	 *
	 * @param WP_REST_Request $request Request with "pages" (post IDs).
	 * @return WP_REST_Response|WP_Error
	 */
	public function index_filters( $request ) {
		if ( ! class_exists( '\\Bricks\\Query_Filters' ) ) {
			return new WP_Error( 'archer_ct_no_bricks', 'Bricks query filters are not available.', array( 'status' => 400 ) );
		}
		$settings = get_option( 'bricks_global_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$turned_on = empty( $settings['enableQueryFilters'] );
		if ( $turned_on ) {
			$settings['enableQueryFilters'] = true;
			update_option( 'bricks_global_settings', $settings );
		}
		$qf = \Bricks\Query_Filters::get_instance();
		if ( method_exists( $qf, 'maybe_create_tables' ) ) {
			$qf->maybe_create_tables();
		}
		// Private in Bricks 2.x: called the same way Bricks does after saving a page.
		$call = static function ( $name, ...$args ) use ( $qf ) {
			if ( ! method_exists( $qf, $name ) ) {
				return null;
			}
			$method = new ReflectionMethod( $qf, $name );
			$method->setAccessible( true );
			return $method->invoke( $qf, ...$args );
		};
		$call( 'maybe_update_table_structure' );
		$done = array();
		foreach ( array_map( 'absint', (array) $request->get_param( 'pages' ) ) as $page_id ) {
			if ( ! $page_id || ! get_post( $page_id ) ) {
				continue;
			}
			$data = get_post_meta( $page_id, BRICKS_DB_PAGE_CONTENT, true );
			if ( is_array( $data ) ) {
				$call( 'maybe_update_element', $page_id, $data );
				$done[] = $page_id;
			}
		}
		$reindex = method_exists( $qf, 'reindex' ) ? $qf->reindex() : null;
		return rest_ensure_response(
			array(
				'enabled' => true,
				'turned_on' => $turned_on,
				'pages'   => $done,
				'reindex' => true === $reindex ? 'ok' : $reindex,
			)
		);
	}

	/**
	 * GET /types.
	 *
	 * @return WP_REST_Response
	 */
	public function get_types() {
		return rest_ensure_response( array_values( Archer_CT_Types::all() ) );
	}

	/**
	 * POST /types.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function save_types( WP_REST_Request $request ) {
		$types  = (array) $request->get_param( 'types' );
		$saved  = array();
		$errors = array();

		foreach ( $types as $def ) {
			$def           = (array) $def;
			$def['source'] = isset( $def['source'] ) ? $def['source'] : 'archer';
			$result        = Archer_CT_Types::save( $def );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'type'    => (string) ( $def['key'] ?? $def['singular'] ?? '' ),
					'message' => $result->get_error_message(),
				);
			} else {
				$saved[] = $result['key'];
			}
		}

		// replace: types the builder made earlier and no longer sends go away.
		// Types the client made or changed by hand are never touched.
		$removed = array();
		if ( $request->get_param( 'replace' ) ) {
			foreach ( Archer_CT_Types::all() as $key => $def ) {
				if ( 'archer' === $def['source'] && ! in_array( $key, $saved, true ) && Archer_CT_Types::delete( $key ) ) {
					$removed[] = $key;
				}
			}
		}

		// Register right away, so items can be added in the same publication.
		( new Archer_CT_Register() )->register_types();

		return rest_ensure_response(
			array(
				'saved'   => $saved,
				'removed' => $removed,
				'errors'  => $errors,
				'types'   => array_values( Archer_CT_Types::all() ),
			)
		);
	}

	/**
	 * DELETE /types/<key>.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_type( WP_REST_Request $request ) {
		$key = (string) $request['key'];
		if ( ! Archer_CT_Types::delete( $key ) ) {
			return new WP_Error( 'archer_ct_not_found', __( 'This content type does not exist.', 'archer-content-types' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( array( 'deleted' => $key ) );
	}

	/**
	 * POST /template: the Bricks template for one item of a type ({type, title}).
	 *
	 * Creates (or finds) a content template with the condition "post type = this type" and returns its ID.
	 * The builder then saves the Bricks content through Bricks itself, like any other page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_template( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$def  = Archer_CT_Types::get( $type );
		if ( ! $def ) {
			return new WP_Error( 'archer_ct_not_found', __( 'This content type does not exist.', 'archer-content-types' ), array( 'status' => 404 ) );
		}
		if ( ! post_type_exists( 'bricks_template' ) ) {
			return new WP_Error( 'archer_ct_no_bricks', 'Bricks is not active.', array( 'status' => 409 ) );
		}

		$found = get_posts(
			array(
				'post_type'      => 'bricks_template',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_archer_ct_template', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $type, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		$title = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$title = '' !== $title ? $title : $def['singular'];

		if ( $found ) {
			$id = (int) $found[0];
		} else {
			$id = wp_insert_post(
				array(
					'post_type'   => 'bricks_template',
					'post_status' => 'publish',
					/* translators: %s: singular name */
					'post_title'  => sprintf( __( '%s (page per item)', 'archer-content-types' ), $title ),
				),
				true
			);
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			update_post_meta( $id, '_archer_ct_template', $type );
		}

		update_post_meta( $id, '_bricks_template_type', 'content' );
		$settings = get_post_meta( $id, '_bricks_template_settings', true );
		$settings = is_array( $settings ) ? $settings : array();
		$settings['templateConditions'] = array(
			array(
				'id'       => substr( md5( 'archer-ct-' . $type ), 0, 6 ),
				'main'     => 'postType',
				'postType' => array( $type ),
			),
		);
		update_post_meta( $id, '_bricks_template_settings', $settings );

		return rest_ensure_response( array( 'id' => (int) $id, 'type' => $type ) );
	}

	/**
	 * Fingerprint of what the builder wrote, to see later whether someone changed it.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $def     Definition.
	 * @return string
	 */
	private function fingerprint( $post_id, array $def ) {
		$post = get_post( $post_id );
		$data = array( $post->post_title, $post->post_content, $post->post_excerpt, (int) get_post_thumbnail_id( $post_id ) );
		foreach ( $def['fields'] as $field ) {
			$data[] = get_post_meta( $post_id, $field['key'], true );
		}

		return md5( (string) wp_json_encode( $data ) );
	}

	/**
	 * Image from a URL into the media library, once per URL.
	 *
	 * Unsplash and Pexels addresses have no file extension, which
	 * media_sideload_image() refuses, so the type comes from the file itself.
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Parent post.
	 * @param string $title   Title and alt text.
	 * @return int Attachment ID or 0.
	 */
	private function sideload( $url, $post_id, $title ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return 0;
		}

		$known = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_archer_ct_src', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => md5( $url ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $known ) {
			return (int) $known[0];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$mime = wp_get_image_mime( $tmp );
		$ext  = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( ! $mime || ! isset( $ext[ $mime ] ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		$name = sanitize_title( $title );
		$id   = media_handle_sideload(
			array(
				'name'     => ( '' !== $name ? $name : 'image' ) . '.' . $ext[ $mime ],
				'tmp_name' => $tmp,
			),
			$post_id,
			$title
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}

		update_post_meta( $id, '_archer_ct_src', md5( $url ) );
		update_post_meta( $id, '_wp_attachment_image_alt', $title );

		return (int) $id;
	}

	/**
	 * POST /items.
	 *
	 * Item: {uid, title, content, excerpt, status, menu_order, image (URL or ID),
	 * meta: {field: value}, terms: {taxonomy: [names]}}. Image and gallery fields
	 * accept URLs or attachment IDs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_items( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		$def  = Archer_CT_Types::get( $type );
		if ( ! $def || ! post_type_exists( $type ) ) {
			return new WP_Error( 'archer_ct_not_found', __( 'This content type does not exist.', 'archer-content-types' ), array( 'status' => 404 ) );
		}

		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$out = array();
		foreach ( array_slice( (array) $request->get_param( 'items' ), 0, 100 ) as $i => $item ) {
			$item = (array) $item;
			$uid  = sanitize_text_field( (string) ( $item['uid'] ?? '' ) );
			if ( '' === $uid ) {
				$out[] = array( 'index' => $i, 'result' => 'skipped', 'message' => 'uid missing' );
				continue;
			}

			$existing = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_archer_ct_uid', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $uid, // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			$post_id = $existing ? (int) $existing[0] : 0;

			if ( $post_id && get_post_meta( $post_id, '_archer_ct_hash', true ) !== $this->fingerprint( $post_id, $def ) ) {
				$out[] = array( 'uid' => $uid, 'id' => $post_id, 'result' => 'kept', 'url' => get_permalink( $post_id ) );
				continue;
			}

			$status = in_array( $item['status'] ?? '', array( 'publish', 'draft', 'pending', 'private' ), true ) ? $item['status'] : 'draft';
			$data   = array(
				'post_type'    => $type,
				'post_title'   => sanitize_text_field( (string) ( $item['title'] ?? '' ) ),
				'post_content' => wp_kses_post( (string) ( $item['content'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $item['excerpt'] ?? '' ) ),
				'post_status'  => $status,
				'menu_order'   => (int) ( $item['menu_order'] ?? 0 ),
			);

			if ( $post_id ) {
				$data['ID'] = $post_id;
				$result     = wp_update_post( wp_slash( $data ), true );
			} else {
				$result = wp_insert_post( wp_slash( $data ), true );
			}

			if ( is_wp_error( $result ) ) {
				$out[] = array( 'uid' => $uid, 'result' => 'error', 'message' => $result->get_error_message() );
				continue;
			}

			$new     = ! $post_id;
			$post_id = (int) $result;
			update_post_meta( $post_id, '_archer_ct_uid', $uid );

			$title = $data['post_title'];
			if ( ! empty( $item['image'] ) ) {
				$aid = is_numeric( $item['image'] ) ? (int) $item['image'] : $this->sideload( (string) $item['image'], $post_id, $title );
				if ( $aid ) {
					set_post_thumbnail( $post_id, $aid );
				}
			}

			$meta = (array) ( $item['meta'] ?? array() );
			foreach ( $def['fields'] as $field ) {
				if ( ! array_key_exists( $field['key'], $meta ) ) {
					continue;
				}

				$value = $meta[ $field['key'] ];
				if ( 'image' === $field['type'] && ! is_numeric( $value ) ) {
					$value = $this->sideload( (string) $value, $post_id, $title );
				} elseif ( 'gallery' === $field['type'] ) {
					$ids = array();
					foreach ( (array) $value as $n => $one ) {
						$ids[] = is_numeric( $one ) ? (int) $one : $this->sideload( (string) $one, $post_id, $title . ' ' . ( $n + 1 ) );
					}
					$value = $ids;
				}

				$value = Archer_CT_Fields::sanitize_value( $field, $value );
				if ( null === $value || '' === $value || array() === $value ) {
					delete_post_meta( $post_id, $field['key'] );
				} else {
					update_post_meta( $post_id, $field['key'], $value );
				}
			}

			foreach ( (array) ( $item['terms'] ?? array() ) as $tax => $names ) {
				if ( taxonomy_exists( (string) $tax ) && is_object_in_taxonomy( $type, (string) $tax ) ) {
					wp_set_object_terms( $post_id, array_map( 'sanitize_text_field', (array) $names ), (string) $tax, false );
				}
			}

			update_post_meta( $post_id, '_archer_ct_hash', $this->fingerprint( $post_id, $def ) );

			$out[] = array(
				'uid'    => $uid,
				'id'     => $post_id,
				'result' => $new ? 'created' : 'updated',
				'url'    => get_permalink( $post_id ),
			);
		}

		return rest_ensure_response( array( 'type' => $type, 'items' => $out ) );
	}
	/**
	 * POST /megamenu: Bricks mega menu templates for top-level items of one menu.
	 * Body: {menu: <term id>, items: [{url, title}]}. Every earlier mega menu of that menu is cleared first,
	 * so publishing again with a smaller menu removes it. Returns [{url, item, template}].
	 * The builder fills each template afterwards through the normal Bricks injection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_megamenu( WP_REST_Request $request ) {
		$menu_id = absint( $request->get_param( 'menu' ) );
		$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : false;
		if ( ! $menu ) {
			return new WP_Error( 'archer_ct_menu', 'Unknown menu.', array( 'status' => 400 ) );
		}
		$items = (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) );
		foreach ( $items as $it ) {
			$old = (int) get_post_meta( $it->ID, '_bricks_mega_menu_template_id', true );
			if ( $old && 'archer-megamenu' === get_post_meta( $old, '_archer_ct_source', true ) ) {
				wp_delete_post( $old, true );
			}
			delete_post_meta( $it->ID, '_bricks_mega_menu_template_id' );
		}
		// Megamenu's van menu-items die niet meer bestaan (een vorige publicatie) ook opruimen.
		global $wpdb;
		$used = array_map( 'intval', (array) $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_bricks_mega_menu_template_id'" ) );
		foreach ( get_posts( array( 'post_type' => 'bricks_template', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_archer_ct_source', 'meta_value' => 'archer-megamenu' ) ) as $orphan ) {
			if ( ! in_array( (int) $orphan, $used, true ) ) {
				wp_delete_post( $orphan, true );
			}
		}
		$norm = static function ( $u ) {
			return untrailingslashit( (string) wp_parse_url( (string) $u, PHP_URL_PATH ) );
		};
		$out = array();
		foreach ( (array) $request->get_param( 'items' ) as $want ) {
			$want  = (array) $want;
			$match = null;
			foreach ( $items as $it ) {
				if ( (int) $it->menu_item_parent !== 0 ) {
					continue;
				}
				if ( $norm( $it->url ) === $norm( $want['url'] ?? '' ) || ( '' !== (string) ( $want['title'] ?? '' ) && $it->title === $want['title'] ) ) {
					$match = $it;
					break;
				}
			}
			if ( ! $match ) {
				continue;
			}
			$tid = wp_insert_post(
				array(
					'post_type'   => 'bricks_template',
					'post_status' => 'publish',
					'post_title'  => 'Megamenu: ' . $match->title,
				),
				true
			);
			if ( is_wp_error( $tid ) ) {
				continue;
			}
			update_post_meta( $tid, '_bricks_template_type', 'section' );
			update_post_meta( $tid, '_bricks_editor_mode', 'bricks' );
			update_post_meta( $tid, '_archer_ct_source', 'archer-megamenu' );
			update_post_meta( $match->ID, '_bricks_mega_menu_template_id', $tid );
			$out[] = array(
				'url'      => (string) ( $want['url'] ?? '' ),
				'item'     => $match->ID,
				'template' => $tid,
			);
		}
		return rest_ensure_response( $out );
	}
}
