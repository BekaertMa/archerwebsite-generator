<?php
/**
 * Admin labels built from the singular and plural name.
 *
 * Gettext cannot translate names the client typed in, so the label sentences are
 * templates per language. They avoid adjectives that change with the gender of
 * the noun ("nieuw project" / "nieuwe realisatie").
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Label templates for nl, fr, de and en.
 */
class Archer_CT_Labels {

	/**
	 * Templates. %1$s = singular, %2$s = plural, %3$s = plural in lower case.
	 *
	 * @return array<string,array<string,string>>
	 */
	private static function templates() {
		return array(
			'nl' => array(
				'add_new'            => 'Toevoegen',
				'add_new_item'       => '%1$s toevoegen',
				'edit_item'          => '%1$s bewerken',
				'new_item'           => '%1$s toevoegen',
				'view_item'          => '%1$s bekijken',
				'view_items'         => '%2$s bekijken',
				'search_items'       => '%2$s zoeken',
				'not_found'          => 'Geen %3$s gevonden',
				'not_found_in_trash' => 'Geen %3$s in de prullenbak',
				'all_items'          => 'Alle %3$s',
				'archives'           => 'Archief %3$s',
				'attributes'         => 'Eigenschappen',
				'insert_into_item'   => 'Invoegen',
				'featured_image'     => 'Uitgelichte afbeelding',
				'set_featured_image' => 'Uitgelichte afbeelding instellen',
				'remove_featured_image' => 'Uitgelichte afbeelding verwijderen',
				'use_featured_image' => 'Als uitgelichte afbeelding gebruiken',
				'items_list'         => 'Lijst %3$s',
				'item_published'     => '%1$s gepubliceerd.',
				'item_updated'       => '%1$s bijgewerkt.',
				'tax_search'         => '%2$s zoeken',
				'tax_all'            => 'Alle %3$s',
				'tax_edit'           => '%1$s bewerken',
				'tax_update'         => '%1$s bijwerken',
				'tax_add'            => '%1$s toevoegen',
				'tax_new_name'       => 'Naam',
				'tax_parent'         => 'Bovenliggend',
				'tax_not_found'      => 'Geen %3$s gevonden',
				'tax_choose'         => 'Kies uit de meest gebruikte',
				'tax_separate'       => 'Scheid met komma\'s',
			),
			'fr' => array(
				'add_new'            => 'Ajouter',
				'add_new_item'       => 'Ajouter : %1$s',
				'edit_item'          => 'Modifier : %1$s',
				'new_item'           => 'Ajouter : %1$s',
				'view_item'          => 'Voir : %1$s',
				'view_items'         => 'Voir : %2$s',
				'search_items'       => 'Rechercher : %2$s',
				'not_found'          => 'Aucun résultat',
				'not_found_in_trash' => 'Aucun résultat dans la corbeille',
				'all_items'          => '%2$s',
				'archives'           => 'Archives : %2$s',
				'attributes'         => 'Attributs',
				'insert_into_item'   => 'Insérer',
				'featured_image'     => 'Image mise en avant',
				'set_featured_image' => 'Définir l’image mise en avant',
				'remove_featured_image' => 'Supprimer l’image mise en avant',
				'use_featured_image' => 'Utiliser comme image mise en avant',
				'items_list'         => 'Liste : %2$s',
				'item_published'     => 'Publié.',
				'item_updated'       => 'Mis à jour.',
				'tax_search'         => 'Rechercher : %2$s',
				'tax_all'            => '%2$s',
				'tax_edit'           => 'Modifier : %1$s',
				'tax_update'         => 'Mettre à jour : %1$s',
				'tax_add'            => 'Ajouter : %1$s',
				'tax_new_name'       => 'Nom',
				'tax_parent'         => 'Parent',
				'tax_not_found'      => 'Aucun résultat',
				'tax_choose'         => 'Choisir parmi les plus utilisés',
				'tax_separate'       => 'Séparez par des virgules',
			),
			'de' => array(
				'add_new'            => 'Hinzufügen',
				'add_new_item'       => '%1$s hinzufügen',
				'edit_item'          => '%1$s bearbeiten',
				'new_item'           => '%1$s hinzufügen',
				'view_item'          => '%1$s ansehen',
				'view_items'         => '%2$s ansehen',
				'search_items'       => '%2$s durchsuchen',
				'not_found'          => 'Keine %2$s gefunden',
				'not_found_in_trash' => 'Keine %2$s im Papierkorb',
				'all_items'          => 'Alle %2$s',
				'archives'           => '%2$s-Archiv',
				'attributes'         => 'Eigenschaften',
				'insert_into_item'   => 'Einfügen',
				'featured_image'     => 'Beitragsbild',
				'set_featured_image' => 'Beitragsbild festlegen',
				'remove_featured_image' => 'Beitragsbild entfernen',
				'use_featured_image' => 'Als Beitragsbild verwenden',
				'items_list'         => 'Liste %2$s',
				'item_published'     => '%1$s veröffentlicht.',
				'item_updated'       => '%1$s aktualisiert.',
				'tax_search'         => '%2$s durchsuchen',
				'tax_all'            => 'Alle %2$s',
				'tax_edit'           => '%1$s bearbeiten',
				'tax_update'         => '%1$s aktualisieren',
				'tax_add'            => '%1$s hinzufügen',
				'tax_new_name'       => 'Name',
				'tax_parent'         => 'Übergeordnet',
				'tax_not_found'      => 'Keine %2$s gefunden',
				'tax_choose'         => 'Aus den meistgenutzten wählen',
				'tax_separate'       => 'Mit Kommas trennen',
			),
			'en' => array(
				'add_new'            => 'Add new',
				'add_new_item'       => 'Add %1$s',
				'edit_item'          => 'Edit %1$s',
				'new_item'           => 'New %1$s',
				'view_item'          => 'View %1$s',
				'view_items'         => 'View %2$s',
				'search_items'       => 'Search %2$s',
				'not_found'          => 'No %3$s found',
				'not_found_in_trash' => 'No %3$s in the trash',
				'all_items'          => 'All %3$s',
				'archives'           => '%1$s archive',
				'attributes'         => 'Attributes',
				'insert_into_item'   => 'Insert',
				'featured_image'     => 'Featured image',
				'set_featured_image' => 'Set featured image',
				'remove_featured_image' => 'Remove featured image',
				'use_featured_image' => 'Use as featured image',
				'items_list'         => '%2$s list',
				'item_published'     => '%1$s published.',
				'item_updated'       => '%1$s updated.',
				'tax_search'         => 'Search %2$s',
				'tax_all'            => 'All %3$s',
				'tax_edit'           => 'Edit %1$s',
				'tax_update'         => 'Update %1$s',
				'tax_add'            => 'Add %1$s',
				'tax_new_name'       => 'Name',
				'tax_parent'         => 'Parent',
				'tax_not_found'      => 'No %3$s found',
				'tax_choose'         => 'Choose from the most used',
				'tax_separate'       => 'Separate with commas',
			),
		);
	}

	/**
	 * Templates for the language of the current admin user.
	 *
	 * @return array<string,string>
	 */
	private static function current() {
		$all = self::templates();

		return isset( $all[ self::lang() ] ) ? $all[ self::lang() ] : $all['en'];
	}

	/**
	 * Language of the labels: the admin user's language in wp-admin, else the site's.
	 *
	 * @return string
	 */
	private static function lang() {
		$locale = is_admin() && function_exists( 'get_user_locale' ) ? get_user_locale() : get_locale();

		return strtolower( substr( (string) $locale, 0, 2 ) );
	}

	/**
	 * Fill one template.
	 *
	 * @param string $tpl      Template.
	 * @param string $singular Singular name.
	 * @param string $plural   Plural name.
	 * @return string
	 */
	private static function fill( $tpl, $singular, $plural ) {
		// German nouns keep their capital letter.
		$lower = 'de' === self::lang() ? $plural : mb_strtolower( $plural );

		return sprintf( $tpl, $singular, $plural, $lower );
	}

	/**
	 * Labels for register_post_type().
	 *
	 * @param array $def Definition.
	 * @return array<string,string>
	 */
	public static function post_type( array $def ) {
		$t   = self::current();
		$s   = $def['singular'];
		$p   = $def['plural'];
		$out = array(
			'name'          => $p,
			'singular_name' => $s,
			'menu_name'     => $p,
			'name_admin_bar' => $s,
		);

		foreach ( array( 'add_new', 'add_new_item', 'edit_item', 'new_item', 'view_item', 'view_items', 'search_items', 'not_found', 'not_found_in_trash', 'all_items', 'archives', 'attributes', 'insert_into_item', 'featured_image', 'set_featured_image', 'remove_featured_image', 'use_featured_image', 'items_list', 'item_published', 'item_updated' ) as $label ) {
			$out[ $label ] = self::fill( $t[ $label ], $s, $p );
		}

		return $out;
	}

	/**
	 * Labels for register_taxonomy().
	 *
	 * @param array $tax Taxonomy definition.
	 * @return array<string,string>
	 */
	public static function taxonomy( array $tax ) {
		$t = self::current();
		$s = $tax['singular'];
		$p = $tax['plural'];

		return array(
			'name'                       => $p,
			'singular_name'              => $s,
			'menu_name'                  => $p,
			'search_items'               => self::fill( $t['tax_search'], $s, $p ),
			'all_items'                  => self::fill( $t['tax_all'], $s, $p ),
			'edit_item'                  => self::fill( $t['tax_edit'], $s, $p ),
			'update_item'                => self::fill( $t['tax_update'], $s, $p ),
			'add_new_item'               => self::fill( $t['tax_add'], $s, $p ),
			'new_item_name'              => $t['tax_new_name'],
			'parent_item'                => $t['tax_parent'],
			'not_found'                  => self::fill( $t['tax_not_found'], $s, $p ),
			'choose_from_most_used'      => $t['tax_choose'],
			'separate_items_with_commas' => $t['tax_separate'],
		);
	}
}
