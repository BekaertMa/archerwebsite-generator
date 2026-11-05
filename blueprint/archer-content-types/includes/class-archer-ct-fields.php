<?php
/**
 * The fields box on the edit screen, saving and display values.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Meta box per content type.
 */
class Archer_CT_Fields {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_boxes' ), 10, 2 );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 10, 1 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'classic_editor' ), 20, 2 );
	}

	/**
	 * The classic edit screen for our types: the fields sit right under the title
	 * instead of in a folded panel at the bottom of the block editor.
	 *
	 * @param bool   $use       Whether to use the block editor.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function classic_editor( $use, $post_type ) {
		$def = Archer_CT_Types::get( (string) $post_type );
		if ( ! $def ) {
			return $use;
		}

		/**
		 * Filters whether a content type uses the block editor (default: no).
		 *
		 * @param bool  $use Whether to use the block editor.
		 * @param array $def Definition.
		 */
		return (bool) apply_filters( 'archer_ct_block_editor', false, $def );
	}

	/**
	 * Clean one value for storage.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize_value( array $field, $value ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'number':
			case 'price':
				return self::parse_number( $value );
			case 'date':
				$value = (string) $value;
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			case 'url':
				return esc_url_raw( (string) $value );
			case 'email':
				return sanitize_email( (string) $value );
			case 'phone':
				return preg_replace( '/[^0-9+().\s\/-]/', '', (string) $value );
			case 'select':
				$value = sanitize_text_field( (string) $value );
				return in_array( $value, (array) $field['options'], true ) ? $value : '';
			case 'checkbox':
				return ! empty( $value ) && 'false' !== $value && '0' !== $value;
			case 'image':
				return absint( is_array( $value ) ? reset( $value ) : $value );
			case 'gallery':
				$ids = is_array( $value ) ? $value : explode( ',', (string) $value );
				return array_values( array_filter( array_map( 'absint', $ids ) ) );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Read a number the way people type it in Belgium, the Netherlands and France:
	 * "24.950", "24 950", "24.950,50", "1,5" and "24,950.50" all work.
	 *
	 * @param mixed $value Raw value.
	 * @return int|float|null
	 */
	public static function parse_number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		$value = preg_replace( '/[\s\x{00A0}\x{202F}€$£\']/u', '', (string) $value );
		if ( '' === $value ) {
			return null;
		}

		$dot   = strrpos( $value, '.' );
		$comma = strrpos( $value, ',' );

		if ( false !== $dot && false !== $comma ) {
			// Both: the last one is the decimal separator.
			$decimal = $dot > $comma ? '.' : ',';
			$group   = '.' === $decimal ? ',' : '.';
			$value   = str_replace( array( $group, $decimal ), array( '', '.' ), $value );
		} elseif ( false !== $comma ) {
			// Only commas: "1,5" is a decimal, "1,250,000" groups thousands.
			$value = preg_match( '/^-?\d{1,3}(,\d{3}){2,}$/', $value ) ? str_replace( ',', '', $value ) : str_replace( ',', '.', $value );
		} elseif ( false !== $dot && preg_match( '/^-?\d{1,3}(\.\d{3})+$/', $value ) ) {
			// Only dots in groups of three: "24.950" is twenty-four thousand.
			$value = str_replace( '.', '', $value );
		}

		return is_numeric( $value ) ? 0 + $value : null;
	}

	/**
	 * A number for display: decimals only when needed, thousands grouped from
	 * 10 000 on (so a year stays "2021").
	 *
	 * @param float $number Number.
	 * @return string
	 */
	private static function format_number( $number ) {
		$decimals = floor( $number ) == $number ? 0 : 2; // phpcs:ignore Universal.Operators.StrictComparisons
		$text     = number_format_i18n( $number, $decimals );

		if ( abs( $number ) < 10000 ) {
			global $wp_locale;
			$group = isset( $wp_locale->number_format['thousands_sep'] ) ? $wp_locale->number_format['thousands_sep'] : ',';
			$text  = str_replace( $group, '', $text );
		}

		return $text;
	}

	/**
	 * Value for display (text context): formatted date, price, yes/no, image URL.
	 *
	 * @param array $field   Field definition.
	 * @param int   $post_id Post ID.
	 * @return string
	 */
	public static function display_value( array $field, $post_id ) {
		$value = get_post_meta( $post_id, $field['key'], true );

		switch ( $field['type'] ) {
			case 'date':
				$time = $value ? strtotime( (string) $value ) : false;
				return $time ? date_i18n( get_option( 'date_format' ), $time ) : '';
			case 'price':
				if ( '' === $value || null === $value ) {
					return '';
				}
				$price = (float) $value;
				$text  = number_format_i18n( $price, floor( $price ) == $price ? 0 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons
				/**
				 * Filters how a price is shown ("€ 24.950").
				 *
				 * @param string $text  Formatted price with currency.
				 * @param float  $price Price.
				 * @param array  $field Field definition.
				 */
				return (string) apply_filters( 'archer_ct_price', '€ ' . $text, $price, $field );
			case 'number':
				return ( '' === $value || null === $value ) ? '' : self::format_number( (float) $value );
			case 'checkbox':
				return $value ? __( 'Yes', 'archer-content-types' ) : __( 'No', 'archer-content-types' );
			case 'image':
				return $value ? (string) wp_get_attachment_image_url( (int) $value, 'large' ) : '';
			case 'gallery':
				return implode( ',', array_map( 'absint', (array) $value ) );
			default:
				return is_scalar( $value ) ? (string) $value : '';
		}
	}

	/**
	 * Add the box to every content type with fields.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 * @return void
	 */
	public function add_boxes( $post_type, $post ) {
		$def = Archer_CT_Types::get( (string) $post_type );
		if ( ! $def || ! $def['fields'] ) {
			return;
		}

		add_meta_box( 'archer-ct-fields', __( 'Details', 'archer-content-types' ), array( $this, 'render' ), $post_type, 'normal', 'high', array( 'def' => $def ) );
	}

	/**
	 * Media library on the edit screens of our types.
	 *
	 * @param string $hook Admin page.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! Archer_CT_Types::get( (string) $screen->post_type ) ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'archer-ct-admin', ARCHER_CT_URL . 'assets/admin.css', array(), ARCHER_CT_VERSION );
		wp_enqueue_script( 'archer-ct-fields', ARCHER_CT_URL . 'assets/fields.js', array( 'jquery', 'jquery-ui-sortable' ), ARCHER_CT_VERSION, true );
		wp_localize_script(
			'archer-ct-fields',
			'archerCtFields',
			array(
				'chooseImage'  => __( 'Choose image', 'archer-content-types' ),
				'chooseImages' => __( 'Choose images', 'archer-content-types' ),
				'use'          => __( 'Use', 'archer-content-types' ),
			)
		);
	}

	/**
	 * Render the box.
	 *
	 * @param WP_Post $post Post.
	 * @param array   $box  Box arguments.
	 * @return void
	 */
	public function render( $post, $box ) {
		$def = $box['args']['def'];
		wp_nonce_field( 'archer_ct_save_' . $post->ID, 'archer_ct_nonce' );

		echo '<div class="archer-ct-fields">';
		foreach ( $def['fields'] as $field ) {
			$id    = 'archer-ct-' . $field['key'];
			$name  = 'archer_ct[' . $field['key'] . ']';
			$value = get_post_meta( $post->ID, $field['key'], true );

			echo '<div class="archer-ct-field archer-ct-field--' . esc_attr( $field['type'] ) . '">';
			if ( 'checkbox' !== $field['type'] ) {
				echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ) . '</label>';
			}

			switch ( $field['type'] ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="4">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'number':
				case 'price':
					echo '<input type="number" step="any" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				case 'date':
					echo '<input type="date" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				case 'url':
					echo '<input type="url" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" placeholder="https://">';
					break;
				case 'email':
					echo '<input type="email" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				case 'phone':
					echo '<input type="tel" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
					break;
				case 'select':
					echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">—</option>';
					foreach ( $field['options'] as $option ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
					}
					echo '</select>';
					break;
				case 'checkbox':
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0">';
					echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $field['label'] ) . '</label>';
					break;
				case 'image':
				case 'gallery':
					$ids = 'image' === $field['type'] ? array_filter( array( absint( $value ) ) ) : array_filter( array_map( 'absint', (array) $value ) );
					echo '<div class="archer-ct-media" data-multiple="' . ( 'gallery' === $field['type'] ? '1' : '0' ) . '">';
					echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( implode( ',', $ids ) ) . '">';
					echo '<ul class="archer-ct-media__list">';
					foreach ( $ids as $aid ) {
						echo '<li data-id="' . esc_attr( (string) $aid ) . '">' . wp_get_attachment_image( $aid, 'thumbnail' ) . '<button type="button" class="archer-ct-media__remove" aria-label="' . esc_attr__( 'Remove', 'archer-content-types' ) . '">×</button></li>';
					}
					echo '</ul>';
					echo '<button type="button" class="button archer-ct-media__add">' . esc_html( 'gallery' === $field['type'] ? __( 'Add images', 'archer-content-types' ) : __( 'Choose image', 'archer-content-types' ) ) . '</button>';
					echo '</div>';
					break;
				default:
					echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}

			if ( '' !== $field['help'] ) {
				echo '<p class="description">' . esc_html( $field['help'] ) . '</p>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		if ( ! isset( $_POST['archer_ct_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['archer_ct_nonce'] ) ), 'archer_ct_save_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$def = Archer_CT_Types::get( $post->post_type );
		if ( ! $def ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value goes through sanitize_value().
		$input = isset( $_POST['archer_ct'] ) && is_array( $_POST['archer_ct'] ) ? wp_unslash( $_POST['archer_ct'] ) : array();

		foreach ( $def['fields'] as $field ) {
			if ( ! array_key_exists( $field['key'], $input ) ) {
				continue;
			}

			$value = self::sanitize_value( $field, $input[ $field['key'] ] );
			if ( null === $value || '' === $value || array() === $value ) {
				delete_post_meta( $post_id, $field['key'] );
			} else {
				update_post_meta( $post_id, $field['key'], $value );
			}
		}
	}
}
