<?php
/**
 * Banner copy for every supported language.
 *
 * These strings are NOT wrapped in __() on purpose: the banner has to render
 * English, Dutch and French simultaneously on a single-locale WordPress
 * install, and __() can only ever return the active locale. Admin-facing
 * strings elsewhere in the plugin do use the translation API.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static provider of banner copy.
 */
class Archer_CC_I18n_Strings {

	/**
	 * Get the full translation object for one language.
	 *
	 * @param string $lang       Language code (en|nl|fr).
	 * @param string $policy_url Optional cookie policy URL.
	 * @return array<string,mixed>
	 */
	public static function get( $lang, $policy_url = '' ) {
		$all = self::all( $policy_url );

		return isset( $all[ $lang ] ) ? $all[ $lang ] : $all['en'];
	}

	/**
	 * Label of the floating "Cookie settings" control, in the banner's language.
	 *
	 * Not wrapped in __() for the same reason as the banner copy: it must follow the
	 * language of the page, not the admin locale.
	 *
	 * @param string $lang Language code (en|nl|fr).
	 * @return string
	 */
	public static function trigger_label( $lang ) {
		// Same wording as the banner copy, which tells visitors to look for this control.
		$labels = array(
			'en' => 'Cookie settings',
			'nl' => 'Cookie-instellingen',
			'fr' => 'Paramètres cookies',
		);

		$label = isset( $labels[ $lang ] ) ? $labels[ $lang ] : $labels['en'];

		/**
		 * Filters the label of the "Cookie settings" control.
		 *
		 * @param string $label Label.
		 * @param string $lang  Language code.
		 */
		return (string) apply_filters( 'archer_cc_trigger_label', $label, $lang );
	}

	/**
	 * All translations, keyed by language code.
	 *
	 * @param string $policy_url Optional cookie policy URL.
	 * @return array<string,array<string,mixed>>
	 */
	public static function all( $policy_url = '' ) {
		$strings = array(
			'en' => self::english( $policy_url ),
			'nl' => self::dutch( $policy_url ),
			'fr' => self::french( $policy_url ),
		);

		/**
		 * Filters the banner copy for all languages.
		 *
		 * @param array  $strings    Translation objects.
		 * @param string $policy_url Cookie policy URL.
		 */
		return apply_filters( 'archer_cc_banner_strings', $strings, $policy_url );
	}

	/**
	 * Build the trailing "read our policy" fragment.
	 *
	 * @param string $policy_url Policy URL.
	 * @param string $label      Link label.
	 * @return string
	 */
	private static function policy_link( $policy_url, $label ) {
		if ( '' === $policy_url ) {
			return '';
		}

		return ' <a href="' . esc_url( $policy_url ) . '" class="cc__link">' . esc_html( $label ) . '</a>.';
	}

	/**
	 * English copy.
	 *
	 * @param string $policy_url Policy URL.
	 * @return array<string,mixed>
	 */
	private static function english( $policy_url ) {
		return array(
			'consentModal'     => array(
				'title'              => 'We use cookies',
				'description'        => 'We use strictly necessary cookies to make this site work. With your permission we also use cookies for functionality, statistics and marketing. You can accept all, reject everything that is not necessary, or choose per purpose. You can change or withdraw your choice at any time.' . self::policy_link( $policy_url, 'Read our cookie policy' ),
				'acceptAllBtn'       => 'Accept all',
				'acceptNecessaryBtn' => 'Reject all',
				'showPreferencesBtn' => 'Manage preferences',
				'closeIconLabel'     => 'Reject all and close',
				'footer'             => '',
				'revisionMessage'    => ' Our cookie policy has changed since your last visit, so we are asking again.',
			),
			'preferencesModal' => array(
				'title'               => 'Cookie preferences',
				'acceptAllBtn'        => 'Accept all',
				'acceptNecessaryBtn'  => 'Reject all',
				'savePreferencesBtn'  => 'Save my choices',
				'closeIconLabel'      => 'Close',
				'serviceCounterLabel' => 'Service|Services',
				'sections'            => array(
					array(
						'title'       => 'How we use cookies',
						'description' => 'Cookies and comparable techniques (local storage, pixels) are only placed for non-necessary purposes after you give consent. Nothing is pre-selected. Use the toggles below to decide per purpose, then save.',
					),
					array(
						'title'          => 'Strictly necessary',
						'description'    => 'Required for the site to function: security, load balancing, remembering your cookie choice and keeping you logged in. These cannot be switched off and rely on a legal exemption rather than consent.',
						'linkedCategory' => 'necessary',
					),
					array(
						'title'          => 'Functionality',
						'description'    => 'Remember preferences such as language, region or a chat widget, so the site behaves the way you expect. Without these the site still works, but less conveniently.',
						'linkedCategory' => 'functionality',
					),
					array(
						'title'          => 'Statistics',
						'description'    => 'Help us understand how the site is used — pages visited, time on page, errors — so we can improve it. Data is aggregated and never used to target advertising.',
						'linkedCategory' => 'analytics',
					),
					array(
						'title'          => 'Marketing',
						'description'    => 'Used to measure campaigns and to show you relevant advertising on this and other websites. This may involve sharing data with advertising partners.',
						'linkedCategory' => 'marketing',
					),
					array(
						'title'       => 'Withdrawing your consent',
						'description' => 'You can reopen this window at any time via the “Cookie settings” link in the footer and change or withdraw your consent with a single click — just as easily as you gave it.',
					),
				),
			),
		);
	}

	/**
	 * Dutch copy.
	 *
	 * @param string $policy_url Policy URL.
	 * @return array<string,mixed>
	 */
	private static function dutch( $policy_url ) {
		return array(
			'consentModal'     => array(
				'title'              => 'Wij gebruiken cookies',
				'description'        => 'Wij gebruiken strikt noodzakelijke cookies om deze site te laten werken. Met uw toestemming plaatsen wij ook cookies voor functionaliteit, statistieken en marketing. U kunt alles aanvaarden, alles weigeren wat niet noodzakelijk is, of per doel kiezen. U kunt uw keuze altijd wijzigen of intrekken.' . self::policy_link( $policy_url, 'Lees ons cookiebeleid' ),
				'acceptAllBtn'       => 'Alles aanvaarden',
				'acceptNecessaryBtn' => 'Alles weigeren',
				'showPreferencesBtn' => 'Voorkeuren beheren',
				'closeIconLabel'     => 'Alles weigeren en sluiten',
				'footer'             => '',
				'revisionMessage'    => ' Ons cookiebeleid is gewijzigd sinds uw laatste bezoek, daarom vragen wij het opnieuw.',
			),
			'preferencesModal' => array(
				'title'               => 'Cookievoorkeuren',
				'acceptAllBtn'        => 'Alles aanvaarden',
				'acceptNecessaryBtn'  => 'Alles weigeren',
				'savePreferencesBtn'  => 'Mijn keuzes opslaan',
				'closeIconLabel'      => 'Sluiten',
				'serviceCounterLabel' => 'Dienst|Diensten',
				'sections'            => array(
					array(
						'title'       => 'Hoe wij cookies gebruiken',
						'description' => 'Cookies en vergelijkbare technieken (local storage, pixels) worden voor niet-noodzakelijke doeleinden pas geplaatst nadat u toestemming geeft. Niets staat vooraf aangevinkt. Kies hieronder per doel en bewaar uw keuze.',
					),
					array(
						'title'          => 'Strikt noodzakelijk',
						'description'    => 'Nodig om de site te laten werken: beveiliging, lastverdeling, het onthouden van uw cookiekeuze en aangemeld blijven. Deze kunnen niet worden uitgeschakeld en berusten op een wettelijke uitzondering, niet op toestemming.',
						'linkedCategory' => 'necessary',
					),
					array(
						'title'          => 'Functionaliteit',
						'description'    => 'Onthouden van voorkeuren zoals taal, regio of een chatvenster, zodat de site zich gedraagt zoals u verwacht. Zonder deze cookies werkt de site nog steeds, maar minder comfortabel.',
						'linkedCategory' => 'functionality',
					),
					array(
						'title'          => 'Statistieken',
						'description'    => 'Helpen ons begrijpen hoe de site wordt gebruikt — bezochte pagina’s, tijd op de pagina, fouten — zodat wij kunnen verbeteren. De gegevens zijn geaggregeerd en worden nooit gebruikt voor advertentietargeting.',
						'linkedCategory' => 'analytics',
					),
					array(
						'title'          => 'Marketing',
						'description'    => 'Gebruikt om campagnes te meten en om u relevante advertenties te tonen op deze en andere websites. Hierbij kunnen gegevens worden gedeeld met advertentiepartners.',
						'linkedCategory' => 'marketing',
					),
					array(
						'title'       => 'Uw toestemming intrekken',
						'description' => 'U kunt dit venster altijd opnieuw openen via de link “Cookie-instellingen” in de voettekst en uw toestemming met één klik wijzigen of intrekken — even eenvoudig als u ze gaf.',
					),
				),
			),
		);
	}

	/**
	 * French copy.
	 *
	 * @param string $policy_url Policy URL.
	 * @return array<string,mixed>
	 */
	private static function french( $policy_url ) {
		return array(
			'consentModal'     => array(
				'title'              => 'Nous utilisons des cookies',
				'description'        => 'Nous utilisons des cookies strictement nécessaires au fonctionnement du site. Avec votre accord, nous utilisons également des cookies de fonctionnalité, de mesure d’audience et de marketing. Vous pouvez tout accepter, tout refuser à l’exception du nécessaire, ou choisir par finalité. Vous pouvez modifier ou retirer votre choix à tout moment.' . self::policy_link( $policy_url, 'Consulter notre politique cookies' ),
				'acceptAllBtn'       => 'Tout accepter',
				'acceptNecessaryBtn' => 'Tout refuser',
				'showPreferencesBtn' => 'Gérer mes préférences',
				'closeIconLabel'     => 'Tout refuser et fermer',
				'footer'             => '',
				'revisionMessage'    => ' Notre politique cookies a changé depuis votre dernière visite ; nous vous redemandons donc votre choix.',
			),
			'preferencesModal' => array(
				'title'               => 'Préférences cookies',
				'acceptAllBtn'        => 'Tout accepter',
				'acceptNecessaryBtn'  => 'Tout refuser',
				'savePreferencesBtn'  => 'Enregistrer mes choix',
				'closeIconLabel'      => 'Fermer',
				'serviceCounterLabel' => 'Service|Services',
				'sections'            => array(
					array(
						'title'       => 'Comment nous utilisons les cookies',
						'description' => 'Les cookies et techniques comparables (stockage local, pixels) ne sont déposés à des fins non nécessaires qu’après votre consentement. Rien n’est pré-coché. Choisissez ci-dessous par finalité, puis enregistrez.',
					),
					array(
						'title'          => 'Strictement nécessaires',
						'description'    => 'Indispensables au fonctionnement du site : sécurité, répartition de charge, mémorisation de votre choix cookies et maintien de votre session. Ils ne peuvent pas être désactivés et reposent sur une exemption légale et non sur le consentement.',
						'linkedCategory' => 'necessary',
					),
					array(
						'title'          => 'Fonctionnalité',
						'description'    => 'Mémorisent des préférences telles que la langue, la région ou un module de chat, afin que le site se comporte comme vous l’attendez. Sans eux, le site fonctionne, mais moins confortablement.',
						'linkedCategory' => 'functionality',
					),
					array(
						'title'          => 'Mesure d’audience',
						'description'    => 'Nous aident à comprendre l’utilisation du site — pages consultées, temps passé, erreurs — afin de l’améliorer. Les données sont agrégées et ne servent jamais au ciblage publicitaire.',
						'linkedCategory' => 'analytics',
					),
					array(
						'title'          => 'Marketing',
						'description'    => 'Servent à mesurer les campagnes et à vous présenter des publicités pertinentes sur ce site et sur d’autres. Cela peut impliquer un partage de données avec des partenaires publicitaires.',
						'linkedCategory' => 'marketing',
					),
					array(
						'title'       => 'Retirer votre consentement',
						'description' => 'Vous pouvez rouvrir cette fenêtre à tout moment via le lien « Paramètres cookies » en pied de page et modifier ou retirer votre consentement en un clic — aussi facilement que vous l’avez donné.',
					),
				),
			),
		);
	}

	/**
	 * Short label per category, used by the cookie-policy shortcode.
	 *
	 * @param string $lang Language code.
	 * @return array<string,string>
	 */
	public static function category_labels( $lang ) {
		$labels = array(
			'en' => array(
				'necessary'     => 'Strictly necessary',
				'functionality' => 'Functionality',
				'analytics'     => 'Statistics',
				'marketing'     => 'Marketing',
			),
			'nl' => array(
				'necessary'     => 'Strikt noodzakelijk',
				'functionality' => 'Functionaliteit',
				'analytics'     => 'Statistieken',
				'marketing'     => 'Marketing',
			),
			'fr' => array(
				'necessary'     => 'Strictement nécessaires',
				'functionality' => 'Fonctionnalité',
				'analytics'     => 'Mesure d’audience',
				'marketing'     => 'Marketing',
			),
		);

		return isset( $labels[ $lang ] ) ? $labels[ $lang ] : $labels['en'];
	}
}
