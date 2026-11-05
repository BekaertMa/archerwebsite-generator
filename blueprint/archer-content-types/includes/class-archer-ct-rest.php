<?php
/**
 * REST API for the Archer builder.
 *
 * - GET    /archer-ct/v1/types           all definitions
 * - POST   /archer-ct/v1/types           add or update definitions ({types:[...], replace:false})
 * - DELETE /archer-ct/v1/types/<key>     remove a definition (the posts are kept)
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
			'/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_items' ),
				'permission_callback' => array( $this, 'can_manage' ),
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
}
