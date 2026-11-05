<?php
/**
 * Content type definitions: storage and validation.
 *
 * A definition is plain data, so it can come from the settings screen, from the
 * REST API (the Archer builder writes one when it publishes a site) or from a filter.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static helpers around the archer_ct_types option.
 */
class Archer_CT_Types {

	/**
	 * Definitions of this request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Post type keys WordPress, WooCommerce and Bricks use themselves.
	 *
	 * @var string[]
	 */
	const RESERVED = array( 'post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'action', 'author', 'order', 'theme', 'product', 'product_variation', 'shop_order', 'shop_coupon', 'bricks_template', 'bricks_fonts', 'category', 'post_tag', 'post_format', 'nav_menu', 'link_category', 'type', 'name', 'year', 'month', 'day', 'feed', 'search', 'term', 'taxonomy', 'tag', 'cat', 'page_id', 'p', 'm', 's', 'w' );

	/**
	 * Field types the editor screen and the Bricks tags understand.
	 *
	 * @return array<string,string> type => label
	 */
	public static function field_types() {
		return array(
			'text'     => __( 'Text (one line)', 'archer-content-types' ),
			'textarea' => __( 'Text (several lines)', 'archer-content-types' ),
			'number'   => __( 'Number', 'archer-content-types' ),
			'price'    => __( 'Price', 'archer-content-types' ),
			'date'     => __( 'Date', 'archer-content-types' ),
			'url'      => __( 'Web address', 'archer-content-types' ),
			'email'    => __( 'Email address', 'archer-content-types' ),
			'phone'    => __( 'Phone number', 'archer-content-types' ),
			'select'   => __( 'Choice from a list', 'archer-content-types' ),
			'checkbox' => __( 'Yes / no', 'archer-content-types' ),
			'image'    => __( 'Image', 'archer-content-types' ),
			'gallery'  => __( 'Gallery (several images)', 'archer-content-types' ),
		);
	}

	/**
	 * Editor features a type can switch on.
	 *
	 * @return array<string,string>
	 */
	public static function supports_options() {
		return array(
			'title'           => __( 'Title', 'archer-content-types' ),
			'editor'          => __( 'Text (editor)', 'archer-content-types' ),
			'thumbnail'       => __( 'Featured image', 'archer-content-types' ),
			'excerpt'         => __( 'Short description', 'archer-content-types' ),
			'page-attributes' => __( 'Order', 'archer-content-types' ),
			'revisions'       => __( 'Revisions', 'archer-content-types' ),
		);
	}

	/**
	 * Dashicons offered on the settings screen (any dashicons-* value is accepted).
	 *
	 * @return array<string,string>
	 */
	public static function icons() {
		return array(
			'dashicons-portfolio'        => __( 'Portfolio', 'archer-content-types' ),
			'dashicons-format-gallery'   => __( 'Gallery', 'archer-content-types' ),
			'dashicons-building'         => __( 'Building', 'archer-content-types' ),
			'dashicons-hammer'           => __( 'Hammer', 'archer-content-types' ),
			'dashicons-car'              => __( 'Car', 'archer-content-types' ),
			'dashicons-star-filled'      => __( 'Star', 'archer-content-types' ),
			'dashicons-format-quote'     => __( 'Quote', 'archer-content-types' ),
			'dashicons-groups'           => __( 'People', 'archer-content-types' ),
			'dashicons-id'               => __( 'Person', 'archer-content-types' ),
			'dashicons-businessman'      => __( 'Job', 'archer-content-types' ),
			'dashicons-calendar-alt'     => __( 'Calendar', 'archer-content-types' ),
			'dashicons-location'         => __( 'Location', 'archer-content-types' ),
			'dashicons-cart'             => __( 'Products', 'archer-content-types' ),
			'dashicons-tag'              => __( 'Label', 'archer-content-types' ),
			'dashicons-media-document'   => __( 'Document', 'archer-content-types' ),
			'dashicons-admin-home'       => __( 'House', 'archer-content-types' ),
			'dashicons-heart'            => __( 'Heart', 'archer-content-types' ),
			'dashicons-awards'           => __( 'Award', 'archer-content-types' ),
			'dashicons-admin-post'       => __( 'Pin', 'archer-content-types' ),
		);
	}

	/**
	 * All valid definitions, keyed by post type.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$raw = get_option( ARCHER_CT_OPTION, array() );
		$out = array();

		foreach ( (array) $raw as $def ) {
			$def = self::sanitize( (array) $def );
			if ( ! is_wp_error( $def ) ) {
				$out[ $def['key'] ] = $def;
			}
		}

		/**
		 * Filters the content type definitions before they are registered.
		 *
		 * @param array $out Definitions keyed by post type.
		 */
		self::$cache = (array) apply_filters( 'archer_ct_types', $out );

		return self::$cache;
	}

	/**
	 * One definition.
	 *
	 * @param string $key Post type key.
	 * @return array<string,mixed>|null
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Store or replace one definition.
	 *
	 * @param array $def Raw definition.
	 * @return array<string,mixed>|WP_Error The stored definition.
	 */
	public static function save( array $def ) {
		$def = self::sanitize( $def );
		if ( is_wp_error( $def ) ) {
			return $def;
		}

		$raw = (array) get_option( ARCHER_CT_OPTION, array() );
		$old = isset( $raw[ $def['key'] ] ) ? (array) $raw[ $def['key'] ] : array();

		// A type the client created or changed by hand stays the client's.
		if ( ! empty( $old['source'] ) && 'manual' === $old['source'] && 'archer' === $def['source'] ) {
			$def['source'] = 'manual';
		}

		$raw[ $def['key'] ] = $def;
		update_option( ARCHER_CT_OPTION, $raw, true );
		self::request_flush();

		return $def;
	}

	/**
	 * Remove a definition. The posts stay in the database and come back when the
	 * type is added again with the same key.
	 *
	 * @param string $key Post type key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$raw = (array) get_option( ARCHER_CT_OPTION, array() );
		if ( ! isset( $raw[ $key ] ) ) {
			return false;
		}

		unset( $raw[ $key ] );
		update_option( ARCHER_CT_OPTION, $raw, true );
		self::request_flush();

		return true;
	}

	/**
	 * Ask for a rewrite flush on the next request, after the types are registered.
	 *
	 * @return void
	 */
	public static function request_flush() {
		self::$cache = null;
		update_option( 'archer_ct_flush', 1, true );
	}

	/**
	 * Turn a label into a key: "Wagens te koop" -> "wagens_te_koop".
	 *
	 * @param string $text Label.
	 * @param int    $max  Maximum length.
	 * @return string
	 */
	public static function key_from( $text, $max ) {
		$key = remove_accents( str_replace( array( "'", '’', '`' ), '', (string) $text ) );
		$key = strtolower( preg_replace( '/[^A-Za-z0-9]+/', '_', $key ) );
		$key = trim( $key, '_' );

		return substr( $key, 0, $max );
	}

	/**
	 * Validate and normalise a definition.
	 *
	 * @param array $in Raw definition.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function sanitize( array $in ) {
		$singular = trim( sanitize_text_field( (string) ( $in['singular'] ?? '' ) ) );
		$plural   = trim( sanitize_text_field( (string) ( $in['plural'] ?? '' ) ) );

		if ( '' === $singular && '' === $plural ) {
			return new WP_Error( 'archer_ct_name', __( 'Give the content type a name.', 'archer-content-types' ) );
		}
		if ( '' === $singular ) {
			$singular = $plural;
		}
		if ( '' === $plural ) {
			$plural = $singular;
		}

		// WordPress allows 20 characters for a post type key.
		$key = self::key_from( '' !== (string) ( $in['key'] ?? '' ) ? (string) $in['key'] : $singular, 20 );
		$key = trim( str_replace( '-', '_', $key ), '_' );
		if ( '' === $key || preg_match( '/^\d/', $key ) ) {
			$key = 'ct_' . $key;
		}
		if ( in_array( $key, self::RESERVED, true ) || 0 === strpos( $key, 'wp_' ) ) {
			return new WP_Error( 'archer_ct_key', sprintf( /* translators: %s: key */ __( '"%s" is already used by WordPress. Choose another name or key.', 'archer-content-types' ), $key ) );
		}

		$slug = sanitize_title( (string) ( $in['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $plural );
		}

		$icon = (string) ( $in['icon'] ?? '' );
		if ( ! preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
			$icon = 'dashicons-portfolio';
		}

		$supports = array_values( array_intersect( array_map( 'strval', (array) ( $in['supports'] ?? array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ) ) ), array_keys( self::supports_options() ) ) );
		if ( ! in_array( 'title', $supports, true ) ) {
			array_unshift( $supports, 'title' );
		}

		$fields = array();
		foreach ( (array) ( $in['fields'] ?? array() ) as $field ) {
			$field = self::sanitize_field( (array) $field );
			if ( $field && ! isset( $fields[ $field['key'] ] ) ) {
				$fields[ $field['key'] ] = $field;
			}
		}

		$taxonomies = array();
		foreach ( (array) ( $in['taxonomies'] ?? array() ) as $tax ) {
			$tax = self::sanitize_taxonomy( (array) $tax, $key );
			if ( $tax && ! isset( $taxonomies[ $tax['key'] ] ) ) {
				$taxonomies[ $tax['key'] ] = $tax;
			}
		}

		return array(
			'key'         => $key,
			'singular'    => $singular,
			'plural'      => $plural,
			'description' => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ),
			'slug'        => $slug,
			'icon'        => $icon,
			'public'      => ! isset( $in['public'] ) || ! empty( $in['public'] ),
			'archive'     => ! isset( $in['archive'] ) || ! empty( $in['archive'] ),
			'supports'    => $supports,
			'fields'      => array_values( $fields ),
			'taxonomies'  => array_values( $taxonomies ),
			'source'      => ( isset( $in['source'] ) && 'archer' === $in['source'] ) ? 'archer' : 'manual',
		);
	}

	/**
	 * One field.
	 *
	 * @param array $in Raw field.
	 * @return array<string,mixed>|null
	 */
	public static function sanitize_field( array $in ) {
		$label = trim( sanitize_text_field( (string) ( $in['label'] ?? '' ) ) );
		$key   = self::key_from( '' !== (string) ( $in['key'] ?? '' ) ? (string) $in['key'] : $label, 40 );

		if ( '' === $label && '' === $key ) {
			return null;
		}
		if ( '' === $key || preg_match( '/^\d/', $key ) ) {
			$key = 'field_' . $key;
		}
		if ( '' === $label ) {
			$label = ucfirst( str_replace( '_', ' ', $key ) );
		}

		$type = (string) ( $in['type'] ?? 'text' );
		if ( ! array_key_exists( $type, self::field_types() ) ) {
			$type = 'text';
		}

		$options = array();
		if ( 'select' === $type ) {
			$list = is_array( $in['options'] ?? null ) ? $in['options'] : preg_split( '/\r\n|\r|\n/', (string) ( $in['options'] ?? '' ) );
			foreach ( (array) $list as $option ) {
				$option = trim( sanitize_text_field( (string) $option ) );
				if ( '' !== $option ) {
					$options[] = $option;
				}
			}
			$options = array_values( array_unique( $options ) );
		}

		return array(
			'key'     => $key,
			'label'   => $label,
			'type'    => $type,
			'options' => $options,
			'help'    => sanitize_text_field( (string) ( $in['help'] ?? '' ) ),
		);
	}

	/**
	 * One taxonomy (category list) of a type.
	 *
	 * @param array  $in       Raw taxonomy.
	 * @param string $type_key Post type it belongs to.
	 * @return array<string,mixed>|null
	 */
	public static function sanitize_taxonomy( array $in, $type_key ) {
		$singular = trim( sanitize_text_field( (string) ( $in['singular'] ?? '' ) ) );
		$plural   = trim( sanitize_text_field( (string) ( $in['plural'] ?? '' ) ) );

		if ( '' === $singular && '' === $plural ) {
			return null;
		}
		if ( '' === $singular ) {
			$singular = $plural;
		}
		if ( '' === $plural ) {
			$plural = $singular;
		}

		// Taxonomy keys allow 32 characters; prefix with the type so "categorie" of
		// two types never collides.
		$key = self::key_from( '' !== (string) ( $in['key'] ?? '' ) ? (string) $in['key'] : $type_key . '_' . self::key_from( $singular, 20 ), 32 );
		if ( in_array( $key, self::RESERVED, true ) || '' === $key ) {
			$key = substr( $type_key . '_' . $key, 0, 32 );
		}

		$slug = sanitize_title( (string) ( $in['slug'] ?? '' ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( $plural );
		}

		return array(
			'key'          => $key,
			'singular'     => $singular,
			'plural'       => $plural,
			'slug'         => $slug,
			'hierarchical' => ! isset( $in['hierarchical'] ) || ! empty( $in['hierarchical'] ),
		);
	}
}
