<?php
if (!defined('ABSPATH')) exit;

/**
 * De taal van de plugin: waarin de uitleg bij een stijl staat, en waarin het voorbeeld bij de
 * stijlkeuze zijn plaatshouders schrijft.
 *
 * De vijftig stijlen komen uit hallmark en hun uitleg stond in het Engels, in de taal van een
 * drukker: "Butter-cream stock, brown-black ink, Ultra over Chivo, three-pixel ink rules". Dat
 * zegt een bakker niets. De Nederlandse uitleg (styles/pitch.nl.json) is daarom geen vertaling
 * van die zin, maar een nieuwe: wat de stijl doet en bij wie hij past.
 *
 * Een taal zonder eigen teksten valt terug op het Engels, en voor een stijl zonder eigen zin
 * blijft de oorspronkelijke pitch staan. Zo mist er nooit iets, hoogstens staat het in een
 * andere taal dan ingesteld.
 */
class AISB_Archer_Lang {

  const OPTION = 'aisb_archer_language';
  const FALLBACK = 'en';

  private static $pitch_cache = [];

  /** De talen die je kan kiezen; achter de naam staat of er al teksten voor zijn. */
  public static function languages(): array {
    return ['nl' => 'Nederlands', 'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch'];
  }

  /** De talen waarvoor er echt teksten zijn (de rest leest Engels). */
  public static function translated(): array {
    return ['nl', 'en'];
  }

  public static function current(): string {
    $c = sanitize_key((string) get_option(self::OPTION, 'nl'));
    return isset(self::languages()[$c]) ? $c : 'nl';
  }

  /* ───────────────────────── Instellingen ───────────────────────── */

  public function init(): void {
    add_action('admin_init', [$this, 'register']);
  }

  /** Veld op de bestaande pagina Instellingen → AI Sitemap Builder, in dezelfde settings-groep. */
  public function register(): void {
    register_setting('aisb_settings_group', self::OPTION, [
      'type' => 'string',
      'sanitize_callback' => function ($v) {
        $v = sanitize_key((string) $v);
        return isset(self::languages()[$v]) ? $v : 'nl';
      },
      'show_in_rest' => false,
      'default' => 'nl',
    ]);
    add_settings_section('aisb_lang', 'Taal', function () {
      echo '<p>De taal waarin de bouwer zijn uitleg geeft: de omschrijving onder elke stijl, en de '
        . 'plaatshoudende teksten in het voorbeeld bij de stijlkeuze. De taal van de site die je bouwt '
        . 'komt niet hiervandaan: die staat in de brief van de klant.</p>';
    }, 'ai-sitemap-builder');
    add_settings_field('archer_language', 'Taal van de uitleg', [$this, 'field'], 'ai-sitemap-builder', 'aisb_lang', ['label_for' => 'archer_language']);
  }

  public function field(): void {
    $nu = self::current();
    echo '<select id="archer_language" name="' . esc_attr(self::OPTION) . '">';
    foreach (self::languages() as $code => $naam) {
      $klaar = in_array($code, self::translated(), true);
      echo '<option value="' . esc_attr($code) . '"' . selected($nu, $code, false) . '>'
        . esc_html($naam . ($klaar ? '' : ' — nog geen teksten')) . '</option>';
    }
    echo '</select>';
    echo '<p class="description">Nederlands en English zijn uitgeschreven. Français en Deutsch lezen '
      . 'voorlopig Engels: kies ze pas als die teksten er zijn.</p>';
  }

  /* ───────────────────────── Uitleg bij een stijl ───────────────────────── */

  /** De uitleg bij een stijl in de ingestelde taal. */
  public static function pitch(array $s): string {
    $id = (string) ($s['id'] ?? '');
    $eigen = self::pitches(self::current());
    if ($id !== '' && !empty($eigen[$id])) return (string) $eigen[$id];
    return self::tidy((string) ($s['pitch'] ?? ''));
  }

  /** @return array<string,string> */
  private static function pitches(string $lang): array {
    if (!isset(self::$pitch_cache[$lang])) {
      $p = __DIR__ . '/styles/pitch.' . $lang . '.json';
      $raw = is_file($p) ? @file_get_contents($p) : '';
      $json = $raw ? json_decode($raw, true) : null;
      self::$pitch_cache[$lang] = is_array($json) ? $json : [];
    }
    return self::$pitch_cache[$lang];
  }

  /**
   * De Engelse pitches in library.json zijn afgekapt op 150 tekens en eindigen daardoor soms
   * midden in een opsomming ("...two rule weights and square "). Afmaken bij het laatste komma.
   */
  private static function tidy(string $p): string {
    $p = trim($p);
    if ($p === '' || preg_match('/[.!?]$/', $p)) return $p;
    $cut = strrpos($p, ',');
    if ($cut !== false && $cut > 40) $p = substr($p, 0, $cut);
    return rtrim($p, " ,;:-") . '.';
  }

  /* ───────────────────────── Woorden voor het voorbeeld ───────────────────────── */

  /**
   * De plaatshouders die archer-preview.js in de voorbeeldpagina zet. Engels is volledig; een
   * andere taal vult aan wat ze zelf heeft. %s is de naam, de regio of de doelgroep.
   */
  public static function words(?string $lang = null): array {
    $lang = $lang ?: self::current();
    $alle = self::dictionary();
    return array_merge($alle[self::FALLBACK], $alle[$lang] ?? []);
  }

  private static function dictionary(): array {
    return [
      'nl' => [
        'business' => 'Je zaak',
        'home' => 'Home',
        'nav_pages' => ['Diensten', 'Over ons', 'Werkwijze', 'Contact'],
        'title' => 'Hier komt je website',
        'lead' => 'De eerste zin van je website vertelt wat je doet en voor wie.',
        'in_region' => 'in %s',
        'since' => 'Sinds %s',
        'cta_default' => 'Neem contact op',
        'goals' => [
          ['bellen', 'Bel ons'],
          ['offerte', 'Vraag een offerte'],
          ['afspraak', 'Maak een afspraak'],
          ['reserv', 'Reserveer'],
          ['langs', 'Kom langs'],
          ['bestel', 'Bestel nu'],
          ['contact', 'Neem contact op'],
        ],
        'secondary' => 'Bekijk ons werk',
        'services_title' => 'Wat we doen',
        'services_for' => 'Voor %s.',
        'services_fallback' => ['Je eerste dienst', 'Je tweede dienst', 'Je derde dienst'],
        'more' => 'Meer hierover',
        'about' => 'Over %s',
        'checks' => ['Vast aanspreekpunt', 'Afspraak is afspraak', 'Werk dat blijft'],
        'fill' => [
          'Een paar zinnen over wat je hier doet en voor wie.',
          'Wat de klant krijgt, in gewone woorden.',
          'Hoe het werkt, van eerste vraag tot afgewerkt.',
        ],
        'fig_since' => 'In zaak sinds',
        'fig_reviews' => 'Wat klanten geven',
        'fig_team' => 'Ons team',
        'fig_services' => 'Diensten',
        'steps' => [
          ['Contact', 'Je vraag komt binnen en we bekijken ze samen.'],
          ['Voorstel', 'Je krijgt een duidelijk voorstel met een prijs.'],
          ['Uitvoering', 'We voeren uit en je blijft op de hoogte.'],
        ],
        'quote' => 'Hier komt straks een woord van een klant.',
        'quote_by' => 'Een klant',
        'cta_title' => 'Zin om te starten?',
        'cta_region' => 'We werken in %s en omstreken.',
        'pages' => "Pagina's",
        'contact' => 'Contact',
        'open' => 'Open',
        'your_details' => 'Je gegevens',
        'your_hours' => 'Je openingsuren',
      ],
      'en' => [
        'business' => 'Your business',
        'home' => 'Home',
        'nav_pages' => ['Services', 'About', 'How we work', 'Contact'],
        'title' => 'Your website goes here',
        'lead' => 'The first line of your website says what you do and who you do it for.',
        'in_region' => 'in %s',
        'since' => 'Since %s',
        'cta_default' => 'Get in touch',
        'goals' => [
          ['call', 'Call us'],
          ['quote', 'Request a quote'],
          ['appointment', 'Book an appointment'],
          ['book', 'Book now'],
          ['visit', 'Visit us'],
          ['order', 'Order now'],
          ['contact', 'Get in touch'],
        ],
        'secondary' => 'See our work',
        'services_title' => 'What we do',
        'services_for' => 'For %s.',
        'services_fallback' => ['Your first service', 'Your second service', 'Your third service'],
        'more' => 'More about this',
        'about' => 'About %s',
        'checks' => ['One point of contact', 'We keep our word', 'Work that lasts'],
        'fill' => [
          'A few lines about what you do here and who for.',
          'What the customer gets, in plain words.',
          'How it works, from first question to finished job.',
        ],
        'fig_since' => 'In business since',
        'fig_reviews' => 'What customers give us',
        'fig_team' => 'Our team',
        'fig_services' => 'Services',
        'steps' => [
          ['Contact', 'Your question comes in and we look at it together.'],
          ['Proposal', 'You get a clear proposal with a price.'],
          ['Delivery', 'We do the work and keep you posted.'],
        ],
        'quote' => 'A word from a customer will go here.',
        'quote_by' => 'A customer',
        'cta_title' => 'Ready to start?',
        'cta_region' => 'We work in %s and the surrounding area.',
        'pages' => 'Pages',
        'contact' => 'Contact',
        'open' => 'Open',
        'your_details' => 'Your details',
        'your_hours' => 'Your opening hours',
      ],
    ];
  }
}
