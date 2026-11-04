<?php
if (!defined('ABSPATH')) exit;

/**
 * Wat de website van de klant echt maakt:
 * - contactgegevens (adres, telefoon, e-mail, sociale media) per project;
 * - knoppen en links die naar de juiste pagina, tel: of mailto: gaan in plaats van "#";
 * - iconen die bij de tekst passen in plaats van de standaardkubus van het sjabloon;
 * - het favicon: het logo van de klant, anders het icoon van Archer Websites.
 */
class AISB_Archer_Site {

  const META = 'archer_contact';

  const SOCIALS = [
    'linkedin' => 'linkedin.com', 'facebook' => 'facebook.com', 'instagram' => 'instagram.com',
    'youtube' => 'youtube.com', 'tiktok' => 'tiktok.com', 'x' => 'x.com', 'pinterest' => 'pinterest.',
  ];

  public function init(): void {
    add_action('wp_ajax_aisb_archer_contact', [$this, 'ajax_contact']);
  }

  /* ───────────────────────── Contactgegevens ───────────────────────── */

  /** @return array{address:string, phone:string, email:string, socials:array<string,string>} */
  public static function contact(int $pid): array {
    $c = json_decode((string) get_post_meta($pid, self::META, true), true);
    $c = is_array($c) ? $c : [];
    return [
      'address' => (string) ($c['address'] ?? ''),
      'phone' => (string) ($c['phone'] ?? ''),
      'email' => (string) ($c['email'] ?? ''),
      'socials' => is_array($c['socials'] ?? null) ? $c['socials'] : [],
    ];
  }

  /** Invoer opkuisen. Sociale media: één veld met links, het platform volgt uit het adres. */
  public static function clean(array $raw): array {
    $socials = [];
    $links = $raw['socials'] ?? '';
    if (is_array($links)) $links = implode("\n", array_map('strval', $links));
    foreach (preg_split('/[\s,;]+/', (string) $links) as $u) {
      $u = trim($u);
      if ($u === '') continue;
      if (!preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
      $u = esc_url_raw($u);
      $host = strtolower((string) wp_parse_url($u, PHP_URL_HOST));
      foreach (self::SOCIALS as $k => $dom) {
        if ($host !== '' && (strpos($host, $dom) !== false || ($k === 'x' && strpos($host, 'twitter.com') !== false))) { $socials[$k] = $u; break; }
      }
    }
    $email = sanitize_email((string) ($raw['email'] ?? ''));
    return [
      'address' => sanitize_text_field((string) ($raw['address'] ?? '')),
      'phone' => preg_replace('/[^0-9+()\/\s.-]/', '', sanitize_text_field((string) ($raw['phone'] ?? ''))),
      'email' => is_email($email) ? $email : '',
      'socials' => $socials,
    ];
  }

  public static function save(int $pid, array $raw): array {
    $c = self::clean($raw);
    update_post_meta($pid, self::META, wp_json_encode($c));
    return $c;
  }

  /** Regels voor de beschrijving van het project, zodat de AI de echte gegevens gebruikt. */
  public static function brief_lines(array $c): string {
    $l = [];
    if ($c['address'] !== '') $l[] = 'Adres: ' . $c['address'];
    if ($c['phone'] !== '') $l[] = 'Telefoon: ' . $c['phone'];
    if ($c['email'] !== '') $l[] = 'E-mail: ' . $c['email'];
    foreach ($c['socials'] as $k => $u) $l[] = ucfirst($k) . ': ' . $u;
    return $l ? "Contactgegevens (gebruik exact deze, verzin geen andere):\n" . implode("\n", $l) : '';
  }

  /** Beschrijving van het project bijwerken: het blok met contactgegevens vervangen of toevoegen. */
  public static function update_brief(int $pid, array $c): void {
    $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
    $brief = trim((string) preg_replace('/\n*Contactgegevens \(gebruik exact deze[^\n]*\n(?:[A-Za-z-]+: [^\n]*\n?)*/u', "\n", $brief));
    $lines = self::brief_lines($c);
    if ($lines !== '') $brief .= "\n\n" . $lines;
    update_post_meta($pid, 'aisb_project_brief', wp_kses_post(mb_substr($brief, 0, 12000)));
  }

  public function ajax_contact(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $pid ? get_post($pid) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden.'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang.'], 403);
    $raw = json_decode((string) wp_unslash($_POST['contact'] ?? '{}'), true);
    $c = self::save($pid, is_array($raw) ? $raw : []);
    self::update_brief($pid, $c);
    wp_send_json_success(['contact' => $c]);
  }

  /* ───────────────────────── Hulp: knopen ───────────────────────── */

  private static function plain(string $s): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($s), ENT_QUOTES, 'UTF-8')));
  }

  private static function text_of(array $n): string {
    $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
    foreach (['text', 'title', 'content', 'label'] as $k) if (isset($s[$k]) && is_string($s[$k])) return self::plain($s[$k]);
    return '';
  }

  private static function index(array $nodes): array {
    $ix = [];
    foreach ($nodes as $i => $n) if (is_array($n) && isset($n['id'])) $ix[(string) $n['id']] = $i;
    return $ix;
  }

  /** Alle tekst onder een knoop (zonder de knoop zelf als die een icoon is). */
  private static function subtree_text(array $nodes, array $ix, string $id, int $max = 240): string {
    $out = [];
    $stack = [$id];
    $seen = 0;
    while ($stack && $seen < 60) {
      $cur = array_shift($stack);
      $seen++;
      if (!isset($ix[$cur])) continue;
      $n = $nodes[$ix[$cur]];
      $t = self::text_of($n);
      if ($t !== '') $out[] = $t;
      foreach ((array) ($n['children'] ?? []) as $c) $stack[] = (string) $c;
    }
    return mb_substr(implode(' · ', $out), 0, $max);
  }

  /** Dichtste voorouder met een bepaalde tag (li) of naam. */
  private static function ancestor(array $nodes, array $ix, string $id, callable $test, int $depth = 4): string {
    $cur = $id;
    for ($d = 0; $d < $depth; $d++) {
      $p = (string) ($nodes[$ix[$cur]]['parent'] ?? '');
      if ($p === '' || $p === '0' || !isset($ix[$p])) return '';
      if ($test($nodes[$ix[$p]])) return $p;
      $cur = $p;
    }
    return '';
  }

  private static function remove(array $nodes, array $ids): array {
    if (!$ids) return $nodes;
    $drop = array_flip($ids);
    $changed = true;
    while ($changed) {
      $changed = false;
      foreach ($nodes as $n) {
        if (isset($n['id'], $n['parent']) && isset($drop[(string) $n['parent']]) && !isset($drop[(string) $n['id']])) { $drop[(string) $n['id']] = 1; $changed = true; }
      }
    }
    $out = [];
    foreach ($nodes as $n) {
      if (isset($drop[(string) ($n['id'] ?? '')])) continue;
      if (!empty($n['children'])) $n['children'] = array_values(array_filter((array) $n['children'], fn($c) => !isset($drop[(string) $c])));
      $out[] = $n;
    }
    return $out;
  }

  /* ───────────────────────── Iconen ───────────────────────── */

  /**
   * Trefwoorden (nl, fr, en) → icoon (Font Awesome 6-naam, omgezet naar Iconoir via ICONOIR). De eerste die past wint; volgorde = van specifiek naar algemeen.
   */
  const ICONS = [
    'fire-flame-curved' => 'verwarm|ketel|cv-|condensatie|chauffage|chaudi|heating|boiler|warmte|kachel|houtkachel|pellet',
    'fan' => 'ventilat|airco|klimaat|climat|cooling|air ?con|lucht',
    'sun' => 'zonne|solar|zonneboiler|zon\b',
    'bolt' => 'elektric|elektri|laadpaal|stroom|électri|electric|energie|énergie|energy|batterij',
    'faucet' => 'sanitair|kraan|loodgiet|plomb|plumb|waterleiding|leidingwerk|lek|afvoer|water',
    'bath' => 'badkamer|douche|bad\b|salle de bain|bathroom|wellness|sauna',
    'kitchen-set' => 'keuken|cuisine|kitchen|koken',
    'couch' => 'interieur|meubel|inricht|zetel|salon|woonkamer|furniture|mobilier|décor|living',
    'ruler-combined' => 'ontwerp op plan|plan\b|plannen|maatvoering|opmeting|ontwerp|design|conception|architect',
    'swatchbook' => 'kleur|materia|stoffen|afwerking|couleur|colour|color|textiel',
    'helmet-safety' => 'renovat|verbouw|werf|aannem|bouw|rénov|chantier|construction|ruwbouw',
    'hammer' => 'herstel|reparat|onderhoud|réparation|repair|klus|timmer|schrijnwerk|maatwerk|sur mesure|custom',
    'screwdriver-wrench' => 'installatie|installat|montage|plaatsing|service|technie|storing|defect|panne',
    'house' => 'woning|huis|appartement|maison|home\b|house|vastgoed|immo|residenti',
    'store' => 'winkel|handelsza|retail|magasin|shop|zaak\b|zaken\b|horeca',
    'folder-open' => 'project|realisatie|portfolio|referentie|cases?\b|dossier',
    'images' => 'voor en na|before|galerij|gallery|beelden|sfeer',
    'building' => 'kantoor|bureau|office|bedrijfsruimte|cowork|gebouw|bâtiment',
    'car' => 'auto|wagen|voiture|garage|occasion|vehicle|rijles',
    'truck' => 'transport|thuislevering|bezorg|livraison|delivery|logistiek|verhuis',
    'utensils' => 'restaurant|menu|gerecht|eten|food|cuisine du|catering|traiteur',
    'mug-hot' => 'koffie|café|ontbijt|lunch|brunch|thee',
    'bread-slice' => 'bakker|brood|patisserie|boulang|taart',
    'tooth' => 'tand|dentist|dentaire|orthodon',
    'stethoscope' => 'dokter|arts|médec|praktijk|zorg|gezondheid|health|kinesi|therap|behandel|soins',
    'paw' => 'dier|hond|kat|vétérin|veterin|pet\b|huisdier',
    'scissors' => 'kapper|kapsalon|coiffure|haar|hair|beauty|schoonheid',
    'spa' => 'massage|ontspann|relax|bien-être',
    'dumbbell' => 'fitness|sport|training|coach|gym',
    'graduation-cap' => 'opleiding|cursus|training|formation|les\b|lessen|workshop|academy|school|leer',
    'scale-balanced' => 'advocaat|juridi|recht|avocat|legal|notaris',
    'calculator' => 'boekhoud|fiscal|comptab|accountan|belasting|budget|prijs|tarief|kost|devis|offerte',
    'chart-line' => 'groei|resultat|rapport|analyse|marketing|seo|strategie|stratég|omzet|data',
    'laptop-code' => 'software|website|webdesign|digita|app\b|ict|it-|informati|odoo|erp|cloud',
    'shield-halved' => 'veilig|beveilig|sécurité|security|garantie|verzeker|assurance|insurance|privacy|alarm',
    'tree' => 'snoei|boom|bomen|haag|hagen|vellen|tree|arbre|élag|taille',
    'seedling' => 'beplant|planten|plant\b|gazon|gras|zaai|seed|semis|border',
    'person-digging' => 'aanleg|grondwerk|graaf|terrass|oprit|bestrat|verhard|aménag|landscap',
    'trowel' => 'metsel|voeg|tegel|carrel|maçon',
    'paint-roller' => 'schilder|verf|behang|peint|paint',
    'broom' => 'schoonma|poets|reinig|nettoy|clean',
    'key' => 'sleutel|slotenmak|serrur|locksmith',
    'solar-panel' => 'zonnepane|fotovolt|photovolt',
    'snowflake' => 'koel|vries|froid|frigo',
    'leaf' => 'duurza|ecolog|écolog|groen|tuin|jardin|garden|natuur|bio\b',
    'recycle' => 'recycl|afval|circulair|déchet',
    'camera' => 'foto|fotograf|video|film|photo',
    'calendar-check' => 'afspraak|reserv|boek|agenda|planning|rendez-vous|kennismak|booking|termijn',
    'comments' => 'gesprek|advies|overleg|klankbord|conseil|consult|bespreek|luister|vraag',
    'handshake' => 'samenwerk|partner|vertrouw|begeleid|accompagn|persoonlijk|relatie|team',
    'clock' => 'snel|tijd|uur|openingsuren|horaire|24\/7|dringend|urgent|nood',
    'euro-sign' => 'premie|subsidie|financ|betaal|prix|price',
    'award' => 'kwaliteit|ervaring|vakman|vakmanschap|expert|qualité|quality|vakkennis|kennis|gecertific',
    'map-location-dot' => 'plaatsbezoek|ter plaatse|aan huis|à domicile|on site|huisbezoek',
    'location-dot' => 'regio|omgeving|locatie|adres|région|lokaal|in de buurt',
    'phone' => 'bel\b|bellen|telefoon|téléphone|appel',
    'envelope' => 'e-mail|mail|nieuwsbrief|newsletter',
    'users' => 'klant|gezin|familie|client|customer|particulier|bedrijven|ondernemer',
    'lightbulb' => 'idee|innovat|inspiratie|creati|concept',
    'list-check' => 'stap|aanpak|werkwijze|proces|méthode|process|traject|checklist',
  ];

  const FALLBACK_ICONS = ['circle-check', 'star', 'gem', 'thumbs-up', 'heart', 'compass'];

  const GENERIC_ICONS = ['helmet-safety', 'hammer', 'screwdriver-wrench', 'comments', 'handshake', 'calculator', 'list-check', 'award', 'clock', 'users', 'euro-sign', 'chart-line'];

  /** Font Awesome-naam → Iconoir-naam (https://iconoir.com). Wat hier ontbreekt, wordt 'sparks' (merken blijven Font Awesome). */
  const ICONOIR = [
    'fire-flame-curved' => 'fire-flame', 'fire' => 'fire-flame', 'fan' => 'air-conditioner', 'sun' => 'sun-light', 'bolt' => 'flash',
    'faucet' => 'droplet', 'droplet' => 'droplet', 'bath' => 'bathroom', 'kitchen-set' => 'cutlery', 'couch' => 'sofa',
    'ruler-combined' => 'ruler', 'swatchbook' => 'palette', 'helmet-safety' => 'tools', 'hammer' => 'hammer', 'screwdriver-wrench' => 'wrench',
    'wrench' => 'wrench', 'house' => 'home', 'home' => 'home', 'store' => 'shop', 'folder-open' => 'folder',
    'images' => 'media-image-list', 'image' => 'media-image', 'building' => 'building', 'car' => 'car', 'truck' => 'delivery-truck',
    'utensils' => 'cutlery', 'mug-hot' => 'coffee-cup', 'bread-slice' => 'bread-slice', 'tooth' => 'emoji', 'stethoscope' => 'healthcare',
    'paw' => 'heart', 'scissors' => 'scissor', 'spa' => 'yoga', 'dumbbell' => 'gym', 'graduation-cap' => 'graduation-cap',
    'scale-balanced' => 'suitcase', 'calculator' => 'calculator', 'chart-line' => 'graph-up', 'laptop-code' => 'laptop', 'laptop' => 'laptop',
    'shield-halved' => 'shield-check', 'shield' => 'shield', 'tree' => 'tree', 'seedling' => 'leaf', 'person-digging' => 'soil',
    'trowel' => 'tools', 'paint-roller' => 'fill-color', 'broom' => 'sparks', 'key' => 'key', 'solar-panel' => 'sun-light',
    'snowflake' => 'snow-flake', 'leaf' => 'leaf', 'recycle' => 'refresh-double', 'camera' => 'camera', 'calendar-check' => 'calendar-check',
    'calendar' => 'calendar', 'calendar-days' => 'calendar', 'comments' => 'chat-bubble', 'comment' => 'chat-bubble', 'handshake' => 'community',
    'clock' => 'clock', 'euro-sign' => 'euro', 'award' => 'medal', 'trophy' => 'trophy', 'medal' => 'medal',
    'map-location-dot' => 'map', 'map' => 'map', 'location-dot' => 'map-pin', 'location-pin' => 'map-pin', 'map-marker-alt' => 'map-pin',
    'phone' => 'phone', 'phone-volume' => 'phone', 'envelope' => 'mail', 'envelope-open' => 'mail-open', 'users' => 'group',
    'user' => 'user', 'user-group' => 'group', 'lightbulb' => 'light-bulb', 'list-check' => 'task-list', 'list' => 'list',
    'circle-check' => 'check-circle', 'check-circle' => 'check-circle', 'square-check' => 'check-square', 'check' => 'check', 'star' => 'star',
    'gem' => 'sparks', 'thumbs-up' => 'thumbs-up', 'heart' => 'heart', 'compass' => 'compass', 'arrow-right' => 'arrow-right',
    'arrow-left' => 'arrow-left', 'arrow-up-right-from-square' => 'open-new-window', 'up-right-from-square' => 'open-new-window', 'circle-minus' => 'minus-circle', 'minus' => 'minus',
    'plus' => 'plus', 'circle-plus' => 'plus-circle', 'xmark' => 'xmark', 'times' => 'xmark', 'bars' => 'menu',
    'chevron-down' => 'nav-arrow-down', 'chevron-right' => 'nav-arrow-right', 'chevron-left' => 'nav-arrow-left', 'chevron-up' => 'nav-arrow-up', 'angle-down' => 'nav-arrow-down',
    'angle-right' => 'nav-arrow-right', 'angle-left' => 'nav-arrow-left', 'angle-up' => 'nav-arrow-up', 'quote-left' => 'quote', 'quote-right' => 'quote',
    'circle-info' => 'info-circle', 'circle-question' => 'help-circle', 'cube' => 'box-3d-point', 'cubes' => 'box-3d-point', 'play' => 'play',
    'download' => 'download', 'magnifying-glass' => 'search', 'search' => 'search', 'globe' => 'globe', 'cart-shopping' => 'cart',
    'tag' => 'label', 'gift' => 'gift', 'headset' => 'headset', 'gear' => 'settings', 'rocket' => 'rocket',
    'bullseye' => 'compass', 'briefcase' => 'suitcase', 'money-bill' => 'wallet', 'temperature-high' => 'temperature-high', 'water' => 'droplet',
    'flask' => 'flask', 'book' => 'book', 'file' => 'page', 'file-lines' => 'page', 'link' => 'link',
    'share' => 'share-android', 'eye' => 'eye', 'lock' => 'lock', 'bed' => 'bed', 'wifi' => 'wifi',
    'instagram' => 'instagram', 'linkedin' => 'linkedin', 'x-twitter' => 'x', 'twitter' => 'x', 'youtube' => 'youtube',
    'tiktok' => 'tiktok', 'facebook' => 'facebook', 'pinterest' => 'pinterest', 'whatsapp' => 'whatsapp',
  ];

  const ICONOIR_BRANDS = ['instagram', 'linkedin', 'x', 'youtube', 'tiktok', 'facebook', 'pinterest', 'whatsapp'];

  private static $iconoir_svg = null;

  /** Iconoir-naam voor een Font Awesome-klasse ("fab fa-linkedin-in", "fas fa-circle-check"), of '' als er geen is. */
  public static function iconoir_name(string $fa): string {
    if (preg_match('/\biconoir-([a-z0-9-]+)/', $fa, $m)) return $m[1];
    if (!preg_match('/\bfa-([a-z0-9-]+)/', $fa, $m)) return '';
    $n = $m[1];
    if (isset(self::ICONOIR[$n])) return self::ICONOIR[$n];
    if (strpos($fa, 'fab ') === 0 || strpos($fa, 'fa-brands') !== false) {
      $b = preg_replace('/^square-|-square$|-in$|-f$|-official$/', '', $n);
      return isset(self::ICONOIR[$b]) ? self::ICONOIR[$b] : '';
    }
    return 'sparks';
  }

  /** CSS voor één icoon: masker met de SVG als data-URI, kleur volgt de tekst (currentColor). */
  public static function iconoir_css(string $name): string {
    if (self::$iconoir_svg === null) self::$iconoir_svg = (array) include __DIR__ . '/iconoir-icons.php';
    $svg = self::$iconoir_svg[$name] ?? '';
    if ($svg === '') return '';
    $uri = 'data:image/svg+xml,' . strtr(str_replace('"', "'", $svg), ['<' => '%3C', '>' => '%3E', '#' => '%23', "\n" => ' ']);
    $sel = '.iconoir-' . $name;
    // min-width/min-height: een leeg <i> krijgt elders soms width:auto (dan zou het icoon 0px breed zijn).
    return $sel . '{display:inline-block;width:1em;height:1em;min-width:1em;min-height:1em;line-height:1;vertical-align:-0.125em;flex-shrink:0;}'
      . $sel . '::before{content:"";display:block;width:1em;height:1em;background-color:currentColor;'
      . '-webkit-mask:url("' . $uri . '") center/contain no-repeat;mask:url("' . $uri . '") center/contain no-repeat;}';
  }

  /**
   * Alle Font Awesome-iconen van de sjablonen naar Iconoir. Bricks tekent een lettertype-icoon als <i class="...">;
   * de vorm komt uit CSS die bij het element zelf staat (_cssCustom), zodat voorbeeld en gepubliceerde site
   * niets extra moeten laden.
   */
  public static function iconoir(array $nodes): array {
    foreach ($nodes as $i => $n) {
      if (!is_array($n) || !is_array($n['settings'] ?? null)) continue;
      $used = [];
      $s = self::iconoir_walk($n['settings'], $used);
      $cur = preg_replace('#\s*/\*archer-iconoir\*/.*?/\*/archer-iconoir\*/#s', '', (string) ($s['_cssCustom'] ?? ''));
      if ($used) {
        $css = '';
        foreach (array_keys($used) as $name) $css .= self::iconoir_css($name);
        $cur = trim($cur . "\n/*archer-iconoir*/" . $css . '/*/archer-iconoir*/');
      }
      if ($cur !== '') $s['_cssCustom'] = $cur; else unset($s['_cssCustom']);
      $nodes[$i]['settings'] = $s;
    }
    return $nodes;
  }

  private static function iconoir_walk($v, array &$used) {
    if (!is_array($v)) return $v;
    if (isset($v['icon']) && is_string($v['icon']) && (isset($v['library']) || preg_match('/\b(fa[sbr]?|fa-solid|fa-brands|fa-regular)\b|iconoir-/', $v['icon']))) {
      if (!empty($v['svg']) || ($v['library'] ?? '') === 'svg') return $v;
      $name = self::iconoir_name($v['icon']);
      if ($name === '') return $v; // merk zonder Iconoir-variant: Font Awesome blijft
      $used[$name] = 1;
      return ['library' => 'iconoir', 'icon' => 'iconoir-' . $name] + array_diff_key($v, ['library' => 1, 'icon' => 1]);
    }
    foreach ($v as $k => $x) if (is_array($x)) $v[$k] = self::iconoir_walk($x, $used);
    return $v;
  }

  /** Treffers [icoon, positie]: trefwoorden van 6+ tekens ook midden in een samenstelling, kortere aan het begin van een woord. */
  private static function icon_hits(string $t): array {
    $hits = [];
    foreach (self::ICONS as $icon => $re) {
      $best = -1;
      foreach (explode('|', $re) as $alt) {
        $len = mb_strlen(preg_replace('/\\\\.|[?()\\[\\]]/u', '', $alt));
        $pat = $len >= 6 ? '/' . $alt . '/u' : '/(?<![\\p{L}])(?:' . $alt . ')/u';
        if (@preg_match($pat, $t, $m, PREG_OFFSET_CAPTURE)) $best = max($best, (int) $m[0][1]);
      }
      if ($best >= 0) $hits[] = [$icon, $best];
    }
    return $hits;
  }

  /**
   * Icoon voor een tekst. Eerst de titel: in een samenstelling telt het laatste deel ("tuinaanleg" → aanleg).
   * Liefst een icoon dat nog niet in de sectie staat; anders toch het best passende.
   */
  public static function icon_for(string $text, array $avoid = []): string {
    $parts = explode(' · ', mb_strtolower($text));
    $hits = self::icon_hits($parts[0]);
    if ($hits) {
      // Een voorwerp of vak ("badkamer") gaat voor een algemene activiteit ("renovatie").
      $generic = array_flip(self::GENERIC_ICONS);
      usort($hits, fn($a, $b) => [isset($generic[$a[0]]) ? 1 : 0, -$a[1]] <=> [isset($generic[$b[0]]) ? 1 : 0, -$b[1]]);
    } else {
      $hits = self::icon_hits(implode(' ', $parts));
      usort($hits, fn($a, $b) => $a[1] <=> $b[1]);
    }
    foreach ($hits as $h) if (!isset($avoid[$h[0]])) return $h[0];
    if ($hits) return $hits[0][0];
    foreach (self::FALLBACK_ICONS as $icon) if (!isset($avoid[$icon])) return $icon;
    return 'circle-check';
  }

  /**
   * De standaardkubus van de sjablonen vervangen door een icoon dat bij de tekst ernaast past.
   * Binnen één sectie zo weinig mogelijk hetzelfde icoon.
   */
  public static function icons(array $nodes): array {
    $ix = self::index($nodes);
    $used = [];
    foreach ($nodes as $i => $n) {
      $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
      $cur = (string) ($s['icon']['icon'] ?? '');
      if (($n['name'] ?? '') !== 'icon' && !isset($s['icon']['icon'])) continue;
      if (!preg_match('/\bfa-cubes?(\s|$)/', $cur) || strpos($cur, 'fab ') === 0) continue;
      // De kleinste voorouder met tekst bepaalt het onderwerp.
      $text = '';
      $id = (string) ($n['id'] ?? '');
      $own = self::text_of($n);
      if ($own !== '') $text = $own;
      for ($d = 0, $c = $id; $d < 4 && $text === '' && $c !== ''; $d++) {
        $p = (string) ($nodes[$ix[$c]]['parent'] ?? '');
        if ($p === '' || !isset($ix[$p])) break;
        $text = self::subtree_text($nodes, $ix, $p);
        $c = $p;
      }
      if ($text === '') continue;
      $icon = self::icon_for($text, $used);
      $used[$icon] = 1;
      $s['icon'] = ['library' => 'fontawesomeSolid', 'icon' => 'fas fa-' . $icon];
      $nodes[$i]['settings'] = $s;
    }
    return $nodes;
  }

  /**
   * Geen verzonnen bewijs: tellers ("100+", "50+") komen uit het sjabloon, niet uit de briefing.
   * Het tellerblok (teller + label) gaat weg, een lege tellerlijst ook.
   */
  public static function proof(array $nodes): array {
    $ix = self::index($nodes);
    $drop = [];
    foreach ($nodes as $n) {
      if (($n['name'] ?? '') !== 'counter') continue;
      $id = (string) ($n['id'] ?? '');
      $p = (string) ($n['parent'] ?? '');
      // Het blok van de teller (meestal een li met de teller en een label).
      $item = ($p !== '' && isset($ix[$p]) && count((array) ($nodes[$ix[$p]]['children'] ?? [])) <= 3) ? $p : $id;
      $drop[$item] = 1;
    }
    if (!$drop) return $nodes;
    // Lijsten die leeg worden: ook weg.
    foreach ($nodes as $n) {
      $kids = array_map('strval', (array) ($n['children'] ?? []));
      if ($kids && !array_diff($kids, array_keys($drop)) && !empty($n['parent'])) $drop[(string) $n['id']] = 1;
    }
    return self::remove($nodes, array_keys($drop));
  }

  /* ───────────────────────── Links en contact (publiceren en voorbeelden) ───────────────────────── */

  const STOP = ['de','het','een','en','of','van','voor','naar','met','op','in','uw','je','jouw','onze','ons','meer','bekijk','bekijken','ontdek','lees','alle','naar','plan','plannen','vraag','vragen','neem','maak','kies','start','word','doe','le','la','les','des','du','nos','notre','votre','vos','voir','plus','the','our','your','all','see','view','more','to','of','for','and','nu','hier','direct','gratis'];

  private static function tokens(string $s): array {
    $s = remove_accents(mb_strtolower($s));
    $out = [];
    foreach (preg_split('/[^a-z0-9]+/', $s) as $w) if (strlen($w) >= 3 && !in_array($w, self::STOP, true)) $out[] = $w;
    return $out;
  }

  private static function similar(string $a, string $b): bool {
    if ($a === $b) return true;
    $n = min(strlen($a), strlen($b));
    return $n >= 5 && substr($a, 0, 5) === substr($b, 0, 5);
  }

  /** Pagina die het best past bij een knoptekst, of ''. */
  public static function page_for(string $text, array $pages): string {
    $tw = self::tokens($text);
    if (!$tw) return '';
    $best = '';
    $score = 0;
    foreach ($pages as $p) {
      $pw = array_unique(array_merge(self::tokens((string) ($p['label'] ?? '')), self::tokens(str_replace('-', ' ', (string) ($p['slug'] ?? '')))));
      $hit = 0;
      foreach ($tw as $a) foreach ($pw as $b) if (self::similar($a, $b)) { $hit++; break; }
      if ($hit > $score) { $score = $hit; $best = (string) $p['slug']; }
    }
    if ($score <= 0) return '';
    // Een detailsjabloon (één realisatie, één wagen) is geen doel voor een knop: het overzicht erboven wel.
    foreach ($pages as $p) {
      if ((string) $p['slug'] === $best && !empty($p['detail']) && !empty($p['parent'])) return (string) $p['parent'];
    }
    return $best;
  }

  /** Context voor de links: pagina's, contactpagina, gegevens. */
  public static function ctx(int $pid): ?array {
    if (!class_exists('AISB_Archer_Chrome')) return null;
    $chrome = AISB_Archer_Chrome::context($pid);
    if (!$chrome) return null;
    $pages = [];
    $contact = '';
    $convert = '';
    foreach ($chrome['pages'] as $p) {
      $slug = (string) ($p['slug'] ?? '');
      if ($slug === '') continue;
      $label = AISB_Archer_Chrome::label($p);
      $pages[] = ['slug' => $slug, 'label' => $label . ' ' . (string) ($p['title'] ?? ''), 'parent' => (string) ($p['parent'] ?? ''),
        'detail' => !empty($p['detail']) || strcasecmp((string) ($p['type'] ?? ''), 'Detail') === 0];
      if ($contact === '' && AISB_Archer_Chrome::is_contact($p)) $contact = $slug;
      if ($convert === '' && preg_match('/offerte|afspraak|reserv|boek|aanvra|devis|rendez|booking|quote/i', $slug . ' ' . $label)) $convert = $slug;
    }
    $cta = (string) ($chrome['menu']['cta'] ?? '');
    return [
      'pages' => $pages,
      'contact' => $contact !== '' ? $contact : ($cta !== '' ? $cta : 'contact'),
      'convert' => $convert,
      'data' => self::contact($pid),
    ];
  }

  private static function tel(string $phone): string {
    $d = preg_replace('/[^0-9+]/', '', $phone);
    if ($d === '') return '';
    if (strpos($d, '00') === 0) $d = '+' . substr($d, 2);
    elseif ($d[0] === '0') $d = '+32' . substr($d, 1);
    return 'tel:' . $d;
  }

  private static function maps(string $address): string {
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
  }

  /** Doel van een knop of tekstlink. */
  private static function target(string $text, array $ctx, callable $link): string {
    $t = mb_strtolower($text);
    $d = $ctx['data'];
    if ($d['phone'] !== '' && preg_match('/\b(bel|bellen|call|appel|appelez|telefo|téléphon)/u', $t)) return self::tel($d['phone']);
    if ($d['email'] !== '' && preg_match('/(e-?mail|mail ons|schrijf)/u', $t)) return 'mailto:' . $d['email'];
    if ($d['address'] !== '' && preg_match('/(route|wegbeschrijving|kaart|itinéraire|plan d.accès|directions)/u', $t)) return self::maps($d['address']);
    // Contactvragen (offerte, afspraak, kennismaking): naar de aanvraagpagina of de contactpagina.
    if (preg_match('/(contact|offerte|afspraak|kennismak|gesprek|aanvra|reserv|boek|devis|rendez|quote|book|neem|contacteer|stuur)/u', $t)) {
      if ($ctx['convert'] !== '' && preg_match('/(offerte|afspraak|reserv|boek|aanvra|devis|rendez|quote|book)/u', $t)) return $link($ctx['convert']);
      return $link($ctx['contact']);
    }
    $page = self::page_for($text, $ctx['pages']);
    return $link($page !== '' ? $page : $ctx['contact']);
  }

  private static function is_dead_link($l): bool {
    if (!is_array($l)) return true;
    $type = (string) ($l['type'] ?? '');
    if ($type === 'internal' && !empty($l['postId'])) return false;
    $url = trim((string) ($l['url'] ?? ($l['useDynamicData'] ?? '')));
    return $url === '' || $url === '#' || $type === 'meta';
  }

  /** Waarde voor een plaatshouder als [telefoonnummer], of null als het geen contactplaatshouder is. */
  private static function contact_kind(string $text): string {
    if (preg_match('/\[(telefoon\w*|gsm\w*|phone\w*|t[ée]l[ée]phone\w*|tel)\]/iu', $text)) return 'phone';
    if (preg_match('/\[(e-?mail\w*|adresse e-?mail|courriel)\]/iu', $text)) return 'email';
    if (preg_match('/\[(adres\w*|address\w*|adresse|locatie|location)\]/iu', $text)) return 'address';
    return '';
  }

  /**
   * Links, contactgegevens en sociale media in de elementen van één sectie.
   * $link: slug → url ('#' in de voorbeelden). $region: header, footer of ''.
   */
  public static function apply_nodes(array $nodes, int $pid, callable $link, string $region = ''): array {
    $ctx = self::ctx($pid);
    if (!$ctx || !$nodes) return $nodes;
    $d = $ctx['data'];
    $ix = self::index($nodes);
    $remove = [];
    $is_li = fn($n) => (string) ($n['settings']['tag'] ?? '') === 'li';
    foreach ($nodes as $i => $n) {
      if (!is_array($n)) continue;
      $name = (string) ($n['name'] ?? '');
      $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
      $id = (string) ($n['id'] ?? '');

      // Contactplaatshouders: echte gegevens, of het lijstitem weg als ze ontbreken.
      if (in_array($name, ['text-basic', 'text', 'heading', 'button', 'text-link'], true) && isset($s['text']) && is_string($s['text'])) {
        $kind = self::contact_kind($s['text']);
        if ($kind !== '') {
          $val = $d[$kind];
          if ($val === '') {
            $li = self::ancestor($nodes, $ix, $id, $is_li, 3);
            if ($li !== '') $remove[] = $li;
            continue;
          }
          $rest = trim(preg_replace('/\[[^\]]+\]/u', '', self::plain($s['text'])));
          $s['text'] = str_word_count($rest) <= 3 ? esc_html($val) : preg_replace('/\[[^\]]+\]/u', esc_html($val), $s['text'], 1);
          $href = $kind === 'phone' ? self::tel($val) : ($kind === 'email' ? 'mailto:' . $val : self::maps($val));
          if (isset($s['link']) || $kind !== 'address') $s['link'] = ['type' => 'external', 'url' => $href] + ($kind === 'address' ? ['newTab' => true] : []);
          $nodes[$i]['settings'] = $s;
          continue;
        }
      }

      // Echte gegevens zonder werkende link (de AI schreef ze al uit): tel:, mailto: of een kaart.
      if (in_array($name, ['text-basic', 'text', 'heading'], true) && array_key_exists('link', $s) && self::is_dead_link($s['link'])) {
        $plain = self::plain((string) ($s['text'] ?? ''));
        $href = '';
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $plain, $em)) $href = 'mailto:' . $em[0];
        elseif (preg_match('/^\+?[\d\s\/().-]{8,}$/', $plain)) $href = self::tel($plain);
        elseif ($d['address'] !== '' && $plain !== '' && stripos($d['address'], explode(',', $plain)[0]) !== false) $href = self::maps($d['address']);
        if ($href !== '') {
          $s['link'] = ['type' => 'external', 'url' => $href] + (strpos($href, 'maps') !== false ? ['newTab' => true] : []);
        } else {
          unset($s['link']);
        }
        $nodes[$i]['settings'] = $s;
        continue;
      }

      // Sociale media: alleen de platformen met een adres.
      $icon = (string) ($s['icon']['icon'] ?? '');
      $brand = preg_match('/\biconoir-(' . implode('|', self::ICONOIR_BRANDS) . ')$/', $icon, $bm) ? $bm[1] : '';
      if ((strpos($icon, 'fab ') === 0 || $brand !== '') && array_key_exists('link', $s)) {
        $platform = $brand === 'whatsapp' ? '' : $brand;
        foreach (['linkedin', 'facebook', 'instagram', 'youtube', 'tiktok', 'pinterest'] as $k) if (strpos($icon, $k) !== false) $platform = $k;
        if (strpos($icon, 'x-twitter') !== false || strpos($icon, 'fa-twitter') !== false) $platform = 'x';
        // Buiten de header en footer (teamkaarten, getuigenissen): geen sociale iconen, die horen bij personen.
        if (!in_array($region, ['header', 'footer'], true)) $platform = '';
        if ($platform !== '' && !empty($d['socials'][$platform])) {
          $s['link'] = ['type' => 'external', 'url' => $d['socials'][$platform], 'newTab' => true, 'rel' => 'noopener', 'ariaLabel' => ucfirst($platform)];
          $nodes[$i]['settings'] = $s;
        } else {
          $li = self::ancestor($nodes, $ix, $id, $is_li, 2);
          $remove[] = $li !== '' ? $li : $id;
        }
        continue;
      }

      // Knoppen en tekstlinks: naar de juiste pagina (header: dat doet de Chrome-klasse al).
      if (in_array($name, ['button', 'text-link'], true)) {
        if ($region === 'header') continue;
        if (!self::is_dead_link($s['link'] ?? null)) continue;
        $text = self::text_of($n);
        if ($text === '') continue;
        $url = self::target($text, $ctx, $link);
        $s['link'] = ['type' => 'external', 'url' => $url] + (strpos($url, 'maps') !== false ? ['newTab' => true] : []);
        $nodes[$i]['settings'] = $s;
        continue;
      }

      // Kaarten en blokken met een lege link: naar de pagina die bij de titel past, anders geen link.
      if (array_key_exists('link', $s) && self::is_dead_link($s['link']) && in_array($name, ['block', 'div', 'container', 'image', 'heading', 'icon'], true)) {
        $text = $name === 'heading' ? self::text_of($n) : self::subtree_text($nodes, $ix, $id, 120);
        $page = $text !== '' ? self::page_for($text, $ctx['pages']) : '';
        if ($page !== '') $s['link'] = ['type' => 'external', 'url' => $link($page)];
        else unset($s['link']);
        $nodes[$i]['settings'] = $s;
      }
    }
    if ($remove) $nodes = self::remove($nodes, array_unique($remove));
    // Een lege lijst sociale media: de titel erboven ("Volg ons") mag ook weg.
    if ($region === 'footer') {
      $ix = self::index($nodes);
      $drop = [];
      foreach ($nodes as $n) {
        $lbl = strtolower((string) ($n['label'] ?? ''));
        if (strpos($lbl, 'social') !== false && empty($n['children']) && in_array($n['name'] ?? '', ['block', 'div'], true)) $drop[] = (string) $n['id'];
      }
      if ($drop) $nodes = self::remove($nodes, $drop);
    }
    return self::iconoir($nodes);
  }

  /** Alle secties van de export (header en footer doet AISB_Archer_Chrome::apply). */
  public static function apply_to_export(array $pages, int $pid, callable $link): array {
    foreach ($pages as &$page) {
      $first = true;
      foreach ((array) ($page['sections'] ?? []) as $k => $sec) {
        if (!is_array($sec)) continue;
        $type = strtolower((string) ($sec['type'] ?? ''));
        if (in_array($type, ['header', 'footer'], true)) continue;
        foreach (['bricks_elements_bricks', 'bricks_elements'] as $key) {
          if (empty($sec[$key]) || !is_array($sec[$key])) continue;
          $nodes = self::apply_nodes($sec[$key], $pid, $link, '');
          // Eerste inhoudssectie: de hero komt een keer binnen (AISB_Archer_Motion).
          if ($first && class_exists('AISB_Archer_Motion')) $nodes = AISB_Archer_Motion::hero_nodes($nodes);
          $page['sections'][$k][$key] = $nodes;
        }
        $first = false;
      }
      $page = self::one_h1($page);
    }
    unset($page);
    return $pages;
  }

  /**
   * Precies één H1 per pagina (UI/UX Pro Max, ux-guidelines: één hoofdtitel voor Google en schermlezers).
   * Geen H1: de eerste titel van de eerste inhoudssectie wordt H1. Meer dan één: de volgende worden H2.
   */
  public static function one_h1(array $page): array {
    $order = [];
    foreach ((array) ($page['sections'] ?? []) as $k => $sec) {
      if (!is_array($sec) || in_array(strtolower((string) ($sec['type'] ?? '')), ['header', 'footer'], true)) continue;
      $key = !empty($sec['bricks_elements_bricks']) ? 'bricks_elements_bricks' : (!empty($sec['bricks_elements']) ? 'bricks_elements' : '');
      if ($key === '') continue;
      foreach ((array) $sec[$key] as $n) {
        if (is_array($n) && ($n['name'] ?? '') === 'heading') $order[] = [$k, (string) ($n['id'] ?? ''), strtolower((string) ($n['settings']['tag'] ?? 'h3'))];
      }
    }
    if (!$order) return $page;
    $set = [];
    $h1 = array_values(array_filter($order, fn($o) => $o[2] === 'h1'));
    if (!$h1) $set[$order[0][1]] = 'h1';
    else foreach (array_slice($h1, 1) as $o) $set[$o[1]] = 'h2';
    if (!$set) return $page;
    foreach ($page['sections'] as $k => $sec) {
      foreach (['bricks_elements_bricks', 'bricks_elements'] as $key) {
        if (empty($sec[$key]) || !is_array($sec[$key])) continue;
        foreach ($sec[$key] as $i => $n) {
          $id = is_array($n) ? (string) ($n['id'] ?? '') : '';
          if ($id !== '' && isset($set[$id]) && ($n['name'] ?? '') === 'heading') $page['sections'][$k][$key][$i]['settings']['tag'] = $set[$id];
        }
      }
    }
    return $page;
  }

  /* ───────────────────────── Favicon ───────────────────────── */

  /** PNG van 512×512: het logo van de klant (vierkant gemaakt), anders het icoon van Archer Websites. */
  public static function favicon_png(int $pid): string {
    $guide = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    $logo = is_array($guide) ? trim((string) ($guide['logoUrl'] ?? '')) : '';
    $bytes = '';
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $logo, $m)) {
      $bytes = (string) base64_decode($m[2]);
    } elseif ($logo !== '' && preg_match('#^https?://#i', $logo)) {
      $local = '';
      $up = wp_upload_dir();
      if (!empty($up['baseurl']) && strpos($logo, $up['baseurl']) === 0) $local = $up['basedir'] . substr($logo, strlen($up['baseurl']));
      if ($local !== '' && is_readable($local)) $bytes = (string) file_get_contents($local);
      else {
        $r = wp_remote_get($logo, ['timeout' => 15]);
        if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) $bytes = (string) wp_remote_retrieve_body($r);
      }
    }
    if ($bytes !== '' && function_exists('imagecreatefromstring')) {
      $im = @imagecreatefromstring($bytes);
      if ($im) {
        $w = imagesx($im); $h = imagesy($im);
        $size = 512; $pad = 32;
        $scale = min(($size - 2 * $pad) / max(1, $w), ($size - 2 * $pad) / max(1, $h));
        $nw = (int) round($w * $scale); $nh = (int) round($h * $scale);
        $out = imagecreatetruecolor($size, $size);
        imagesavealpha($out, true);
        imagealphablending($out, false);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        imagealphablending($out, true);
        imagecopyresampled($out, $im, (int) (($size - $nw) / 2), (int) (($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($out);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        imagedestroy($out);
        if ($png !== '') return $png;
      }
    }
    $file = dirname(__DIR__, 2) . '/assets/archer/img/archer-favicon.png';
    return is_readable($file) ? (string) file_get_contents($file) : '';
  }

  /**
   * Op de gepubliceerde site: het favicon als mediabestand opladen en als site-icoon instellen.
   * $request: dezelfde transport als de rest van het publiceren (cookies, DNS-terugval).
   */
  public static function publish_favicon(int $pid, string $site_url, array $cookies, string $nonce, callable $request): bool {
    $png = self::favicon_png($pid);
    if ($png === '' || $nonce === '') return false;
    $site_url = untrailingslashit($site_url);
    $up = $request($site_url . '/wp-json/wp/v2/media', [
      'method' => 'POST', 'timeout' => 30, 'cookies' => $cookies,
      'headers' => ['X-WP-Nonce' => $nonce, 'Content-Type' => 'image/png', 'Content-Disposition' => 'attachment; filename="site-icon.png"'],
      'body' => $png,
    ]);
    if (is_wp_error($up) || (int) ($up['code'] ?? 0) >= 300) {
      error_log('[AISB] Archer: favicon opladen mislukt: ' . (is_wp_error($up) ? $up->get_error_message() : 'HTTP ' . ($up['code'] ?? 0) . ' ' . substr((string) ($up['body'] ?? ''), 0, 200)));
      return false;
    }
    $media = json_decode((string) ($up['body'] ?? ''), true);
    $mid = (int) ($media['id'] ?? 0);
    if (!$mid) return false;
    $set = $request($site_url . '/wp-json/wp/v2/settings', [
      'method' => 'POST', 'timeout' => 20, 'cookies' => $cookies,
      'headers' => ['X-WP-Nonce' => $nonce, 'Content-Type' => 'application/json'],
      'body' => wp_json_encode(['site_icon' => $mid]),
    ]);
    $ok = !is_wp_error($set) && (int) ($set['code'] ?? 0) === 200 && (int) (json_decode((string) ($set['body'] ?? ''), true)['site_icon'] ?? 0) === $mid;
    error_log('[AISB] Archer: favicon ' . ($ok ? 'ingesteld' : 'niet ingesteld (' . (is_wp_error($set) ? $set->get_error_message() : 'HTTP ' . ($set['code'] ?? 0) . ' ' . substr((string) ($set['body'] ?? ''), 0, 200)) . ')') . '.');
    return $ok;
  }
}
