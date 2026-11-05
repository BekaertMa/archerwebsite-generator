<?php
/**
 * Ready-made content types to start from. They fill in the form; everything stays
 * editable before saving.
 *
 * @package Archer_Content_Types
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presets in Dutch, French and English (site language decides).
 */
class Archer_CT_Presets {

	/**
	 * All presets for the language of the site.
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$lang = strtolower( substr( (string) get_locale(), 0, 2 ) );
		$t    = self::texts();
		$t    = isset( $t[ $lang ] ) ? $t[ $lang ] : $t['en'];

		$presets = array(
			'realisatie' => array(
				'key'        => 'realisatie',
				'singular'   => $t['realisatie'][0],
				'plural'     => $t['realisatie'][1],
				'icon'       => 'dashicons-portfolio',
				'fields'     => array(
					array( 'key' => 'plaats', 'label' => $t['place'], 'type' => 'text' ),
					array( 'key' => 'datum', 'label' => $t['date_done'], 'type' => 'date' ),
					array( 'key' => 'klant', 'label' => $t['client'], 'type' => 'text' ),
					array( 'key' => 'galerij', 'label' => $t['photos'], 'type' => 'gallery' ),
				),
				'taxonomies' => array( array( 'key' => 'realisatie_dienst', 'singular' => $t['service'][0], 'plural' => $t['service'][1] ) ),
			),
			'project'    => array(
				'key'        => 'project',
				'singular'   => $t['project'][0],
				'plural'     => $t['project'][1],
				'icon'       => 'dashicons-building',
				'fields'     => array(
					array( 'key' => 'klant', 'label' => $t['client'], 'type' => 'text' ),
					array( 'key' => 'plaats', 'label' => $t['place'], 'type' => 'text' ),
					array( 'key' => 'jaar', 'label' => $t['year'], 'type' => 'number' ),
					array( 'key' => 'status', 'label' => $t['status'], 'type' => 'select', 'options' => $t['status_options'] ),
					array( 'key' => 'galerij', 'label' => $t['photos'], 'type' => 'gallery' ),
				),
				'taxonomies' => array( array( 'key' => 'project_categorie', 'singular' => $t['category'][0], 'plural' => $t['category'][1] ) ),
			),
			'referentie' => array(
				'key'      => 'referentie',
				'singular' => $t['referentie'][0],
				'plural'   => $t['referentie'][1],
				'icon'     => 'dashicons-format-quote',
				'supports' => array( 'title', 'editor', 'thumbnail', 'revisions' ),
				'archive'  => false,
				'fields'   => array(
					array( 'key' => 'naam', 'label' => $t['name'], 'type' => 'text' ),
					array( 'key' => 'bedrijf', 'label' => $t['company'], 'type' => 'text' ),
					array( 'key' => 'functie', 'label' => $t['role'], 'type' => 'text' ),
					array( 'key' => 'score', 'label' => $t['score'], 'type' => 'select', 'options' => array( '5', '4', '3', '2', '1' ) ),
					array( 'key' => 'logo', 'label' => 'Logo', 'type' => 'image' ),
				),
			),
			'wagen'      => array(
				'key'        => 'wagen',
				'singular'   => $t['wagen'][0],
				'plural'     => $t['wagen'][1],
				'icon'       => 'dashicons-car',
				'fields'     => array(
					array( 'key' => 'merk', 'label' => $t['brand'], 'type' => 'text' ),
					array( 'key' => 'model', 'label' => $t['model'], 'type' => 'text' ),
					array( 'key' => 'bouwjaar', 'label' => $t['built'], 'type' => 'number' ),
					array( 'key' => 'kilometerstand', 'label' => $t['mileage'], 'type' => 'number' ),
					array( 'key' => 'brandstof', 'label' => $t['fuel'], 'type' => 'select', 'options' => $t['fuel_options'] ),
					array( 'key' => 'transmissie', 'label' => $t['gearbox'], 'type' => 'select', 'options' => $t['gearbox_options'] ),
					array( 'key' => 'vermogen', 'label' => $t['power'], 'type' => 'number' ),
					array( 'key' => 'prijs', 'label' => $t['price'], 'type' => 'price' ),
					array( 'key' => 'galerij', 'label' => $t['photos'], 'type' => 'gallery' ),
				),
				'taxonomies' => array( array( 'key' => 'wagen_carrosserie', 'singular' => $t['body'][0], 'plural' => $t['body'][1] ) ),
			),
			'vacature'   => array(
				'key'        => 'vacature',
				'singular'   => $t['vacature'][0],
				'plural'     => $t['vacature'][1],
				'icon'       => 'dashicons-businessman',
				'fields'     => array(
					array( 'key' => 'locatie', 'label' => $t['location'], 'type' => 'text' ),
					array( 'key' => 'contract', 'label' => $t['contract'], 'type' => 'select', 'options' => $t['contract_options'] ),
					array( 'key' => 'solliciteren', 'label' => $t['apply'], 'type' => 'url' ),
				),
				'taxonomies' => array( array( 'key' => 'vacature_afdeling', 'singular' => $t['department'][0], 'plural' => $t['department'][1] ) ),
			),
			'teamlid'    => array(
				'key'      => 'teamlid',
				'singular' => $t['teamlid'][0],
				'plural'   => $t['teamlid'][1],
				'icon'     => 'dashicons-groups',
				'supports' => array( 'title', 'editor', 'thumbnail', 'page-attributes' ),
				'archive'  => false,
				'fields'   => array(
					array( 'key' => 'functie', 'label' => $t['role'], 'type' => 'text' ),
					array( 'key' => 'email', 'label' => $t['email'], 'type' => 'email' ),
					array( 'key' => 'telefoon', 'label' => $t['phone'], 'type' => 'phone' ),
					array( 'key' => 'linkedin', 'label' => 'LinkedIn', 'type' => 'url' ),
				),
			),
		);

		/**
		 * Filters the ready-made content types.
		 *
		 * @param array $presets Presets.
		 */
		return (array) apply_filters( 'archer_ct_presets', $presets );
	}

	/**
	 * Words per language.
	 *
	 * @return array<string,array>
	 */
	private static function texts() {
		return array(
			'nl' => array(
				'realisatie' => array( 'Realisatie', 'Realisaties' ),
				'project' => array( 'Project', 'Projecten' ),
				'referentie' => array( 'Referentie', 'Referenties' ),
				'wagen' => array( 'Wagen', 'Wagens' ),
				'vacature' => array( 'Vacature', 'Vacatures' ),
				'teamlid' => array( 'Teamlid', 'Team' ),
				'service' => array( 'Dienst', 'Diensten' ),
				'category' => array( 'Categorie', 'Categorieën' ),
				'body' => array( 'Carrosserie', 'Carrosserieën' ),
				'department' => array( 'Afdeling', 'Afdelingen' ),
				'place' => 'Plaats', 'date_done' => 'Datum van uitvoering', 'client' => 'Klant', 'photos' => 'Foto\'s',
				'year' => 'Jaar', 'status' => 'Status', 'status_options' => array( 'Lopend', 'Afgerond' ),
				'name' => 'Naam', 'company' => 'Bedrijf', 'role' => 'Functie', 'score' => 'Score',
				'brand' => 'Merk', 'model' => 'Model', 'built' => 'Bouwjaar', 'mileage' => 'Kilometerstand',
				'fuel' => 'Brandstof', 'fuel_options' => array( 'Benzine', 'Diesel', 'Hybride', 'Elektrisch', 'LPG' ),
				'gearbox' => 'Transmissie', 'gearbox_options' => array( 'Manueel', 'Automaat' ),
				'power' => 'Vermogen (pk)', 'price' => 'Prijs',
				'location' => 'Locatie', 'contract' => 'Contract', 'contract_options' => array( 'Voltijds', 'Deeltijds', 'Student', 'Freelance' ),
				'apply' => 'Link om te solliciteren', 'email' => 'E-mail', 'phone' => 'Telefoon',
			),
			'fr' => array(
				'realisatie' => array( 'Réalisation', 'Réalisations' ),
				'project' => array( 'Projet', 'Projets' ),
				'referentie' => array( 'Témoignage', 'Témoignages' ),
				'wagen' => array( 'Véhicule', 'Véhicules' ),
				'vacature' => array( 'Offre d’emploi', 'Offres d’emploi' ),
				'teamlid' => array( 'Membre de l’équipe', 'Équipe' ),
				'service' => array( 'Service', 'Services' ),
				'category' => array( 'Catégorie', 'Catégories' ),
				'body' => array( 'Carrosserie', 'Carrosseries' ),
				'department' => array( 'Département', 'Départements' ),
				'place' => 'Lieu', 'date_done' => 'Date de réalisation', 'client' => 'Client', 'photos' => 'Photos',
				'year' => 'Année', 'status' => 'Statut', 'status_options' => array( 'En cours', 'Terminé' ),
				'name' => 'Nom', 'company' => 'Entreprise', 'role' => 'Fonction', 'score' => 'Note',
				'brand' => 'Marque', 'model' => 'Modèle', 'built' => 'Année', 'mileage' => 'Kilométrage',
				'fuel' => 'Carburant', 'fuel_options' => array( 'Essence', 'Diesel', 'Hybride', 'Électrique', 'LPG' ),
				'gearbox' => 'Transmission', 'gearbox_options' => array( 'Manuelle', 'Automatique' ),
				'power' => 'Puissance (ch)', 'price' => 'Prix',
				'location' => 'Lieu', 'contract' => 'Contrat', 'contract_options' => array( 'Temps plein', 'Temps partiel', 'Étudiant', 'Freelance' ),
				'apply' => 'Lien pour postuler', 'email' => 'E-mail', 'phone' => 'Téléphone',
			),
			'en' => array(
				'realisatie' => array( 'Case study', 'Case studies' ),
				'project' => array( 'Project', 'Projects' ),
				'referentie' => array( 'Testimonial', 'Testimonials' ),
				'wagen' => array( 'Car', 'Cars' ),
				'vacature' => array( 'Job', 'Jobs' ),
				'teamlid' => array( 'Team member', 'Team' ),
				'service' => array( 'Service', 'Services' ),
				'category' => array( 'Category', 'Categories' ),
				'body' => array( 'Body type', 'Body types' ),
				'department' => array( 'Department', 'Departments' ),
				'place' => 'Location', 'date_done' => 'Date completed', 'client' => 'Client', 'photos' => 'Photos',
				'year' => 'Year', 'status' => 'Status', 'status_options' => array( 'Ongoing', 'Completed' ),
				'name' => 'Name', 'company' => 'Company', 'role' => 'Job title', 'score' => 'Rating',
				'brand' => 'Make', 'model' => 'Model', 'built' => 'Year', 'mileage' => 'Mileage',
				'fuel' => 'Fuel', 'fuel_options' => array( 'Petrol', 'Diesel', 'Hybrid', 'Electric', 'LPG' ),
				'gearbox' => 'Gearbox', 'gearbox_options' => array( 'Manual', 'Automatic' ),
				'power' => 'Power (hp)', 'price' => 'Price',
				'location' => 'Location', 'contract' => 'Contract', 'contract_options' => array( 'Full-time', 'Part-time', 'Student', 'Freelance' ),
				'apply' => 'Application link', 'email' => 'Email', 'phone' => 'Phone',
			),
		);
	}
}
