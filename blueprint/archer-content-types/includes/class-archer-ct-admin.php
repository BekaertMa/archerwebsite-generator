<?php
/**
 * Settings screen: Settings > Content types.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * List, add, edit and remove content types.
 */
class Archer_CT_Admin {

	const PAGE = 'archer-content-types';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10, 0 );
		add_action( 'admin_post_archer_ct_save', array( $this, 'handle_save' ), 10, 0 );
		add_action( 'admin_post_archer_ct_delete', array( $this, 'handle_delete' ), 10, 0 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ), 10, 1 );
		add_filter( 'plugin_action_links_' . plugin_basename( ARCHER_CT_FILE ), array( $this, 'action_links' ), 10, 1 );
	}

	/**
	 * Menu entry.
	 *
	 * @return void
	 */
	public function menu() {
		add_options_page( __( 'Content types', 'archer-content-types' ), __( 'Content types', 'archer-content-types' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( $this->url() ) . '">' . esc_html__( 'Settings', 'archer-content-types' ) . '</a>' );

		return $links;
	}

	/**
	 * Screen URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * Scripts on our screen only.
	 *
	 * @param string $hook Admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'archer-ct-admin', ARCHER_CT_URL . 'assets/admin.css', array(), ARCHER_CT_VERSION );
		wp_enqueue_script( 'archer-ct-admin', ARCHER_CT_URL . 'assets/admin.js', array(), ARCHER_CT_VERSION, true );
		wp_localize_script(
			'archer-ct-admin',
			'archerCtAdmin',
			array(
				'confirmDelete' => __( 'Remove this content type? The items stay in the database and come back when you add the type again with the same key.', 'archer-content-types' ),
			)
		);
	}

	/**
	 * Render the list or the form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
		$edit   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$new    = isset( $_GET['new'] );
		$preset = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';
		$error  = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap archer-ct">';

		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Content type saved. It is in the menu on the left.', 'archer-content-types' ) . '</p></div>';
		} elseif ( 'deleted' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Content type removed. The items are kept.', 'archer-content-types' ) . '</p></div>';
		}
		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( '' !== $edit || $new ) {
			$def = '' !== $edit ? Archer_CT_Types::get( $edit ) : null;
			if ( ! $def && $new ) {
				$presets = Archer_CT_Presets::all();
				$def     = isset( $presets[ $preset ] ) ? Archer_CT_Types::sanitize( $presets[ $preset ] ) : null;
				$def     = is_wp_error( $def ) ? null : $def;
			}
			$this->render_form( $def, '' !== $edit );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/**
	 * The overview.
	 *
	 * @return void
	 */
	private function render_list() {
		$types = Archer_CT_Types::all();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Content types', 'archer-content-types' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( $this->url( array( 'new' => 1 ) ) ) . '">' . esc_html__( 'Add content type', 'archer-content-types' ) . '</a>';
		echo '<hr class="wp-header-end">';
		echo '<p class="archer-ct-intro">' . esc_html__( 'A content type is a list of similar items with their own fields, such as projects, references, cars or vacancies. Each type gets its own menu item, an overview and a page per item.', 'archer-content-types' ) . '</p>';

		if ( $types ) {
			echo '<table class="widefat striped archer-ct-table"><thead><tr>';
			echo '<th>' . esc_html__( 'Name', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Fields', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Items', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Address', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Made by', 'archer-content-types' ) . '</th><th></th>';
			echo '</tr></thead><tbody>';

			foreach ( $types as $def ) {
				$count = wp_count_posts( $def['key'] );
				$n     = isset( $count->publish ) ? (int) $count->publish : 0;
				$d     = isset( $count->draft ) ? (int) $count->draft : 0;

				echo '<tr>';
				echo '<td><span class="dashicons ' . esc_attr( $def['icon'] ) . '" aria-hidden="true"></span> <strong><a href="' . esc_url( admin_url( 'edit.php?post_type=' . $def['key'] ) ) . '">' . esc_html( $def['plural'] ) . '</a></strong><br><code>' . esc_html( $def['key'] ) . '</code></td>';
				echo '<td>' . esc_html( implode( ', ', wp_list_pluck( $def['fields'], 'label' ) ) ?: '—' ) . '</td>';
				/* translators: 1: published items, 2: drafts */
				echo '<td>' . esc_html( sprintf( __( '%1$d published, %2$d draft', 'archer-content-types' ), $n, $d ) ) . '</td>';
				echo '<td><code>/' . esc_html( $def['slug'] ) . '/</code></td>';
				echo '<td>' . esc_html( 'archer' === $def['source'] ? 'Archer Websites' : __( 'This site', 'archer-content-types' ) ) . '</td>';
				echo '<td class="archer-ct-actions"><a class="button" href="' . esc_url( $this->url( array( 'edit' => $def['key'] ) ) ) . '">' . esc_html__( 'Edit', 'archer-content-types' ) . '</a> ';
				echo '<a class="button-link archer-ct-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=archer_ct_delete&key=' . $def['key'] ), 'archer_ct_delete_' . $def['key'] ) ) . '">' . esc_html__( 'Remove', 'archer-content-types' ) . '</a></td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p><em>' . esc_html__( 'No content types yet.', 'archer-content-types' ) . '</em></p>';
		}

		echo '<h2>' . esc_html__( 'Start from an example', 'archer-content-types' ) . '</h2>';
		echo '<p>' . esc_html__( 'Fills in the form with useful fields. You can change everything before saving.', 'archer-content-types' ) . '</p><p class="archer-ct-presets">';
		foreach ( Archer_CT_Presets::all() as $id => $preset ) {
			$taken = isset( $types[ $preset['key'] ] );
			echo '<a class="button' . ( $taken ? ' disabled' : '' ) . '" href="' . esc_url( $taken ? '#' : $this->url( array( 'new' => 1, 'preset' => $id ) ) ) . '"' . ( $taken ? ' aria-disabled="true"' : '' ) . '><span class="dashicons ' . esc_attr( $preset['icon'] ) . '" aria-hidden="true"></span> ' . esc_html( $preset['plural'] ) . '</a> ';
		}
		echo '</p>';
	}

	/**
	 * One row of the fields table.
	 *
	 * @param string $i     Index or "__i__" for the template.
	 * @param array  $field Field.
	 * @return string
	 */
	private function field_row( $i, array $field = array() ) {
		$field = wp_parse_args( $field, array( 'key' => '', 'label' => '', 'type' => 'text', 'options' => array(), 'help' => '' ) );
		$name  = 'fields[' . $i . ']';

		$types = '';
		foreach ( Archer_CT_Types::field_types() as $value => $label ) {
			$types .= '<option value="' . esc_attr( $value ) . '"' . selected( $field['type'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}

		return '<tr class="archer-ct-row">'
			. '<td><input type="text" class="regular-text archer-ct-label" name="' . esc_attr( $name . '[label]' ) . '" value="' . esc_attr( $field['label'] ) . '" placeholder="' . esc_attr__( 'e.g. Mileage', 'archer-content-types' ) . '"></td>'
			. '<td><input type="text" class="archer-ct-key" name="' . esc_attr( $name . '[key]' ) . '" value="' . esc_attr( $field['key'] ) . '" pattern="[a-z0-9_]*" maxlength="40"></td>'
			. '<td><select class="archer-ct-type" name="' . esc_attr( $name . '[type]' ) . '">' . $types . '</select>'
			. '<textarea class="archer-ct-options" name="' . esc_attr( $name . '[options]' ) . '" rows="3" placeholder="' . esc_attr__( 'One choice per line', 'archer-content-types' ) . '"' . ( 'select' === $field['type'] ? '' : ' hidden' ) . '>' . esc_textarea( implode( "\n", (array) $field['options'] ) ) . '</textarea></td>'
			. '<td><input type="text" name="' . esc_attr( $name . '[help]' ) . '" value="' . esc_attr( $field['help'] ) . '"></td>'
			. '<td><button type="button" class="button-link archer-ct-remove" aria-label="' . esc_attr__( 'Remove field', 'archer-content-types' ) . '">×</button></td>'
			. '</tr>';
	}

	/**
	 * One row of the categories table.
	 *
	 * @param string $i   Index or "__i__".
	 * @param array  $tax Taxonomy.
	 * @return string
	 */
	private function tax_row( $i, array $tax = array() ) {
		$tax  = wp_parse_args( $tax, array( 'key' => '', 'singular' => '', 'plural' => '', 'hierarchical' => true ) );
		$name = 'taxonomies[' . $i . ']';

		return '<tr class="archer-ct-row">'
			. '<td><input type="text" class="archer-ct-label" name="' . esc_attr( $name . '[singular]' ) . '" value="' . esc_attr( $tax['singular'] ) . '" placeholder="' . esc_attr__( 'e.g. Brand', 'archer-content-types' ) . '"></td>'
			. '<td><input type="text" name="' . esc_attr( $name . '[plural]' ) . '" value="' . esc_attr( $tax['plural'] ) . '" placeholder="' . esc_attr__( 'e.g. Brands', 'archer-content-types' ) . '"></td>'
			. '<td><input type="text" class="archer-ct-key" name="' . esc_attr( $name . '[key]' ) . '" value="' . esc_attr( $tax['key'] ) . '" pattern="[a-z0-9_]*" maxlength="32" placeholder="' . esc_attr__( 'automatic', 'archer-content-types' ) . '"></td>'
			. '<td><label><input type="checkbox" name="' . esc_attr( $name . '[hierarchical]' ) . '" value="1"' . checked( $tax['hierarchical'], true, false ) . '> ' . esc_html__( 'Fixed list to tick', 'archer-content-types' ) . '</label></td>'
			. '<td><button type="button" class="button-link archer-ct-remove" aria-label="' . esc_attr__( 'Remove', 'archer-content-types' ) . '">×</button></td>'
			. '</tr>';
	}

	/**
	 * Add or edit form.
	 *
	 * @param array|null $def     Definition or null.
	 * @param bool       $editing Whether the type already exists.
	 * @return void
	 */
	private function render_form( $def, $editing ) {
		$def = wp_parse_args(
			(array) $def,
			array( 'key' => '', 'singular' => '', 'plural' => '', 'description' => '', 'slug' => '', 'icon' => 'dashicons-portfolio', 'public' => true, 'archive' => true, 'supports' => array( 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ), 'fields' => array(), 'taxonomies' => array() )
		);

		echo '<h1>' . esc_html( $editing ? sprintf( /* translators: %s: plural name */ __( 'Edit %s', 'archer-content-types' ), $def['plural'] ) : __( 'Add content type', 'archer-content-types' ) ) . '</h1>';
		echo '<p><a href="' . esc_url( $this->url() ) . '">← ' . esc_html__( 'All content types', 'archer-content-types' ) . '</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="archer-ct-form">';
		echo '<input type="hidden" name="action" value="archer_ct_save">';
		echo '<input type="hidden" name="editing" value="' . esc_attr( $editing ? $def['key'] : '' ) . '">';
		wp_nonce_field( 'archer_ct_save' );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="act-singular">' . esc_html__( 'Name (one item)', 'archer-content-types' ) . '</label></th><td><input type="text" id="act-singular" name="singular" class="regular-text" required value="' . esc_attr( $def['singular'] ) . '" placeholder="' . esc_attr__( 'e.g. Car', 'archer-content-types' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="act-plural">' . esc_html__( 'Name (several)', 'archer-content-types' ) . '</label></th><td><input type="text" id="act-plural" name="plural" class="regular-text" required value="' . esc_attr( $def['plural'] ) . '" placeholder="' . esc_attr__( 'e.g. Cars', 'archer-content-types' ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="act-key">' . esc_html__( 'Key', 'archer-content-types' ) . '</label></th><td><input type="text" id="act-key" name="key" class="archer-ct-key" pattern="[a-z0-9_]*" maxlength="20" value="' . esc_attr( $def['key'] ) . '"' . ( $editing ? ' readonly' : '' ) . '><p class="description">' . esc_html( $editing ? __( 'The key cannot change: the items are linked to it.', 'archer-content-types' ) : __( 'Filled in automatically. Lower case, digits and _ only, at most 20 characters.', 'archer-content-types' ) ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="act-slug">' . esc_html__( 'Address', 'archer-content-types' ) . '</label></th><td><code>' . esc_html( home_url( '/' ) ) . '</code><input type="text" id="act-slug" name="slug" value="' . esc_attr( $def['slug'] ) . '" class="archer-ct-slug"><code>/…</code><p class="description">' . esc_html__( 'Each item gets its own page under this address. When a page with the same address exists, that page stays the overview.', 'archer-content-types' ) . '</p></td></tr>';

		$icons = '';
		foreach ( Archer_CT_Types::icons() as $icon => $label ) {
			$icons .= '<label class="archer-ct-icon"><input type="radio" name="icon" value="' . esc_attr( $icon ) . '"' . checked( $def['icon'], $icon, false ) . '><span class="dashicons ' . esc_attr( $icon ) . '" title="' . esc_attr( $label ) . '"></span><span class="screen-reader-text">' . esc_html( $label ) . '</span></label>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Icon', 'archer-content-types' ) . '</th><td><div class="archer-ct-icons">' . $icons . '</div></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		$supports = '';
		foreach ( Archer_CT_Types::supports_options() as $value => $label ) {
			$supports .= '<label><input type="checkbox" name="supports[]" value="' . esc_attr( $value ) . '"' . checked( in_array( $value, $def['supports'], true ), true, false ) . ( 'title' === $value ? ' disabled checked' : '' ) . '> ' . esc_html( $label ) . '</label><br>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'On the edit screen', 'archer-content-types' ) . '</th><td>' . $supports . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<tr><th scope="row">' . esc_html__( 'Visibility', 'archer-content-types' ) . '</th><td><label><input type="checkbox" name="public" value="1"' . checked( $def['public'], true, false ) . '> ' . esc_html__( 'Visible on the website (a page per item)', 'archer-content-types' ) . '</label><br><label><input type="checkbox" name="archive" value="1"' . checked( $def['archive'], true, false ) . '> ' . esc_html__( 'Automatic overview page when there is no page at that address', 'archer-content-types' ) . '</label></td></tr>';
		echo '<tr><th scope="row"><label for="act-description">' . esc_html__( 'Description', 'archer-content-types' ) . '</label></th><td><textarea id="act-description" name="description" rows="2" class="large-text">' . esc_textarea( $def['description'] ) . '</textarea></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Fields', 'archer-content-types' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Extra information per item, such as a place, a price or photos. Title, text and featured image are there already.', 'archer-content-types' ) . '</p>';
		echo '<table class="widefat archer-ct-repeater" data-repeater="fields"><thead><tr><th>' . esc_html__( 'Label', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Key', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Type', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Help text', 'archer-content-types' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_values( $def['fields'] ) as $i => $field ) {
			echo $this->field_row( (string) $i, $field ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_row().
		}
		echo '</tbody></table>';
		echo '<template id="archer-ct-tpl-fields">' . $this->field_row( '__i__' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in field_row().
		echo '<p><button type="button" class="button archer-ct-add" data-target="fields">+ ' . esc_html__( 'Add field', 'archer-content-types' ) . '</button></p>';

		echo '<h2>' . esc_html__( 'Categories', 'archer-content-types' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Lists to group or filter the items, such as a brand, a service or a region.', 'archer-content-types' ) . '</p>';
		echo '<table class="widefat archer-ct-repeater" data-repeater="taxonomies"><thead><tr><th>' . esc_html__( 'Name (one)', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Name (several)', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Key', 'archer-content-types' ) . '</th><th>' . esc_html__( 'Kind', 'archer-content-types' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_values( $def['taxonomies'] ) as $i => $tax ) {
			echo $this->tax_row( (string) $i, $tax ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tax_row().
		}
		echo '</tbody></table>';
		echo '<template id="archer-ct-tpl-taxonomies">' . $this->tax_row( '__i__' ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tax_row().
		echo '<p><button type="button" class="button archer-ct-add" data-target="taxonomies">+ ' . esc_html__( 'Add category list', 'archer-content-types' ) . '</button></p>';

		if ( $editing && $def['fields'] ) {
			echo '<h2>' . esc_html__( 'In Bricks', 'archer-content-types' ) . '</h2>';
			echo '<p>' . esc_html__( 'Use a query loop on this post type for the overview, and a template with the condition "post type" for the page of one item. The fields are available as dynamic data:', 'archer-content-types' ) . '</p><ul class="archer-ct-tags">';
			echo '<li><code>{post_title}</code> · <code>{featured_image}</code> · <code>{post_excerpt}</code> · <code>{post_url}</code></li>';
			foreach ( $def['fields'] as $field ) {
				echo '<li><code>{' . esc_html( Archer_CT_Bricks::PREFIX . $field['key'] ) . '}</code> ' . esc_html( $field['label'] ) . '</li>';
			}
			echo '</ul>';
		}

		submit_button( $editing ? __( 'Save changes', 'archer-content-types' ) : __( 'Create content type', 'archer-content-types' ) );
		echo '</form>';
	}

	/**
	 * Save from the form.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'archer-content-types' ), 403 );
		}
		check_admin_referer( 'archer_ct_save' );

		$post    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Archer_CT_Types::sanitize() cleans every value.
		$editing = sanitize_key( (string) ( $post['editing'] ?? '' ) );

		$def = array(
			'key'         => '' !== $editing ? $editing : (string) ( $post['key'] ?? '' ),
			'singular'    => (string) ( $post['singular'] ?? '' ),
			'plural'      => (string) ( $post['plural'] ?? '' ),
			'description' => (string) ( $post['description'] ?? '' ),
			'slug'        => (string) ( $post['slug'] ?? '' ),
			'icon'        => (string) ( $post['icon'] ?? '' ),
			'public'      => ! empty( $post['public'] ),
			'archive'     => ! empty( $post['archive'] ),
			'supports'    => array_merge( array( 'title' ), (array) ( $post['supports'] ?? array() ) ),
			'fields'      => array_values( (array) ( $post['fields'] ?? array() ) ),
			'taxonomies'  => array_values( array_map(
				static function ( $tax ) {
					$tax                 = (array) $tax;
					$tax['hierarchical'] = ! empty( $tax['hierarchical'] );
					return $tax;
				},
				(array) ( $post['taxonomies'] ?? array() )
			) ),
			'source'      => 'manual',
		);

		// A new type must not overwrite an existing one with the same key.
		if ( '' === $editing ) {
			$check = Archer_CT_Types::sanitize( $def );
			if ( ! is_wp_error( $check ) && ( Archer_CT_Types::get( $check['key'] ) || post_type_exists( $check['key'] ) ) ) {
				/* translators: %s: key */
				wp_safe_redirect( $this->url( array( 'new' => 1, 'error' => rawurlencode( sprintf( __( 'The key "%s" is already in use. Choose another one.', 'archer-content-types' ), $check['key'] ) ) ) ) );
				exit;
			}
		}

		$result = Archer_CT_Types::save( $def );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( $this->url( array( '' !== $editing ? 'edit' : 'new' => '' !== $editing ? $editing : 1, 'error' => rawurlencode( $result->get_error_message() ) ) ) );
			exit;
		}

		wp_safe_redirect( $this->url( array( 'edit' => $result['key'], 'notice' => 'saved' ) ) );
		exit;
	}

	/**
	 * Remove a definition.
	 *
	 * @return void
	 */
	public function handle_delete() {
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'archer-content-types' ), 403 );
		}
		check_admin_referer( 'archer_ct_delete_' . $key );

		Archer_CT_Types::delete( $key );
		wp_safe_redirect( $this->url( array( 'notice' => 'deleted' ) ) );
		exit;
	}
}
