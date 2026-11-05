<?php
/**
 * Bricks dynamic data: one tag per field, {act_<field>}.
 *
 * Plain {cf_<field>} also works for text, but these tags format dates, prices and
 * yes/no, and give image and gallery fields to the image elements as attachment IDs.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bricks integration. Does nothing when Bricks is not the active theme.
 */
class Archer_CT_Bricks {

	const PREFIX = 'act_';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'bricks/dynamic_tags_list', array( $this, 'tags' ), 10, 1 );
		add_filter( 'bricks/dynamic_data/render_tag', array( $this, 'render_tag' ), 20, 3 );
		add_filter( 'bricks/dynamic_data/render_content', array( $this, 'render_content' ), 20, 3 );
		add_filter( 'bricks/frontend/render_data', array( $this, 'render_content' ), 20, 2 );
	}

	/**
	 * Fields by key over all types (a key can exist in several types).
	 *
	 * @return array<string,array<string,array>> field key => [post type => field]
	 */
	private function fields() {
		$out = array();
		foreach ( Archer_CT_Types::all() as $def ) {
			foreach ( $def['fields'] as $field ) {
				$out[ $field['key'] ][ $def['key'] ] = $field;
			}
		}

		return $out;
	}

	/**
	 * Add the tags to the builder's dynamic data list.
	 *
	 * @param array $tags Tags.
	 * @return array
	 */
	public function tags( $tags ) {
		foreach ( Archer_CT_Types::all() as $def ) {
			foreach ( $def['fields'] as $field ) {
				$tags[] = array(
					'name'  => '{' . self::PREFIX . $field['key'] . '}',
					'label' => $field['label'],
					'group' => 'Archer: ' . $def['plural'],
				);
			}
		}

		return $tags;
	}

	/**
	 * Field for a tag name and the current post.
	 *
	 * @param string $tag  Tag without braces.
	 * @param mixed  $post Post.
	 * @return array{0:array|null,1:int}
	 */
	private function resolve( $tag, $post ) {
		$key    = substr( $tag, strlen( self::PREFIX ) );
		$fields = $this->fields();
		if ( ! isset( $fields[ $key ] ) ) {
			return array( null, 0 );
		}

		$post_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : (int) get_the_ID();
		$type    = get_post_type( $post_id );
		$field   = isset( $fields[ $key ][ $type ] ) ? $fields[ $key ][ $type ] : reset( $fields[ $key ] );

		return array( $field, $post_id );
	}

	/**
	 * A tag used on its own (element setting "dynamic data").
	 *
	 * @param mixed  $tag     Tag.
	 * @param mixed  $post    Post.
	 * @param string $context text|image|link|...
	 * @return mixed
	 */
	public function render_tag( $tag, $post, $context = 'text' ) {
		if ( ! is_string( $tag ) ) {
			return $tag;
		}

		$clean = explode( ':', str_replace( array( '{', '}' ), '', $tag ) )[0];
		if ( 0 !== strpos( $clean, self::PREFIX ) ) {
			return $tag;
		}

		list( $field, $post_id ) = $this->resolve( $clean, $post );
		if ( ! $field || ! $post_id ) {
			return $tag;
		}

		if ( 'image' === $context && in_array( $field['type'], array( 'image', 'gallery' ), true ) ) {
			$value = get_post_meta( $post_id, $field['key'], true );

			return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		}

		if ( 'link' === $context ) {
			$value = (string) get_post_meta( $post_id, $field['key'], true );
			if ( 'email' === $field['type'] && '' !== $value ) {
				return 'mailto:' . $value;
			}
			if ( 'phone' === $field['type'] && '' !== $value ) {
				return 'tel:' . preg_replace( '/[^0-9+]/', '', $value );
			}
		}

		return Archer_CT_Fields::display_value( $field, $post_id );
	}

	/**
	 * Tags inside text ("Gebouwd in {act_jaar}").
	 *
	 * @param string $content Content.
	 * @param mixed  $post    Post.
	 * @param string $context Context.
	 * @return string
	 */
	public function render_content( $content, $post, $context = 'text' ) {
		if ( ! is_string( $content ) || false === strpos( $content, '{' . self::PREFIX ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/\{(' . self::PREFIX . '[a-z0-9_]+)(?::[^}]*)?\}/',
			function ( $m ) use ( $post ) {
				list( $field, $post_id ) = $this->resolve( $m[1], $post );
				if ( ! $field || ! $post_id ) {
					return $m[0];
				}

				return esc_html( Archer_CT_Fields::display_value( $field, $post_id ) );
			},
			$content
		);
	}
}
