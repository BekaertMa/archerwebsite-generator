<?php
/**
 * Registers the post types, taxonomies and meta fields from the definitions.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registration on init.
 */
class Archer_CT_Register {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_types' ), 5, 0 );
		add_action( 'init', array( $this, 'maybe_flush' ), 99, 0 );
	}

	/**
	 * Meta schema per field type.
	 *
	 * @param string $type Field type.
	 * @return array{type:string,schema:array}
	 */
	public static function meta_schema( $type ) {
		switch ( $type ) {
			case 'number':
			case 'price':
				return array( 'type' => 'number', 'schema' => array( 'type' => 'number' ) );
			case 'checkbox':
				return array( 'type' => 'boolean', 'schema' => array( 'type' => 'boolean' ) );
			case 'image':
				return array( 'type' => 'integer', 'schema' => array( 'type' => 'integer' ) );
			case 'gallery':
				return array(
					'type'   => 'array',
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				);
			default:
				return array( 'type' => 'string', 'schema' => array( 'type' => 'string' ) );
		}
	}

	/**
	 * Register everything.
	 *
	 * @return void
	 */
	public function register_types() {
		foreach ( Archer_CT_Types::all() as $def ) {
			$this->register_one( $def );
		}
	}

	/**
	 * One post type with its taxonomies and fields.
	 *
	 * @param array $def Definition.
	 * @return void
	 */
	private function register_one( array $def ) {
		$key = $def['key'];

		// The overview is usually a designed page with a query loop at the same
		// address ("/realisaties/"). A post type archive would take that address
		// over, so the page wins when it exists.
		$archive = false;
		if ( $def['archive'] && $def['public'] ) {
			$page    = get_page_by_path( $def['slug'] );
			$archive = ( $page && 'publish' === $page->post_status ) ? false : $def['slug'];
		}

		$args = array(
			'labels'              => Archer_CT_Labels::post_type( $def ),
			'description'         => $def['description'],
			'public'              => $def['public'],
			'publicly_queryable'  => $def['public'],
			'exclude_from_search' => ! $def['public'],
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => $def['public'],
			'show_in_rest'        => true,
			'rest_base'           => $key,
			'menu_position'       => 21,
			'menu_icon'           => $def['icon'],
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			// custom-fields: without it the REST API leaves the fields out.
			'supports'            => array_values( array_unique( array_merge( $def['supports'], array( 'custom-fields' ) ) ) ),
			'has_archive'         => $archive,
			'rewrite'             => array(
				'slug'       => $def['slug'],
				'with_front' => false,
			),
			'query_var'           => $def['public'] ? $key : false,
		);

		/**
		 * Filters the register_post_type() arguments of one content type.
		 *
		 * @param array $args Arguments.
		 * @param array $def  Definition.
		 */
		register_post_type( $key, (array) apply_filters( 'archer_ct_post_type_args', $args, $def ) );

		foreach ( $def['taxonomies'] as $tax ) {
			register_taxonomy(
				$tax['key'],
				array( $key ),
				array(
					'labels'            => Archer_CT_Labels::taxonomy( $tax ),
					'public'            => $def['public'],
					'show_ui'           => true,
					'show_in_rest'      => true,
					'show_admin_column' => true,
					'hierarchical'      => $tax['hierarchical'],
					'rewrite'           => array(
						'slug'       => $def['slug'] . '/' . $tax['slug'],
						'with_front' => false,
					),
				)
			);
		}

		foreach ( $def['fields'] as $field ) {
			$meta = self::meta_schema( $field['type'] );

			$meta_args = array(
				'type'              => $meta['type'],
				'single'            => true,
				'show_in_rest'      => array( 'schema' => $meta['schema'] ),
				'sanitize_callback' => static function ( $value ) use ( $field ) {
					return Archer_CT_Fields::sanitize_value( $field, $value );
				},
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			);
			if ( 'array' === $meta['type'] ) {
				$meta_args['default'] = array();
			}

			register_post_meta( $key, $field['key'], $meta_args );
		}
	}

	/**
	 * Rebuild the permalinks once after a definition changed.
	 *
	 * @return void
	 */
	public function maybe_flush() {
		if ( get_option( 'archer_ct_flush' ) ) {
			delete_option( 'archer_ct_flush' );
			flush_rewrite_rules( false );
		}
	}
}
