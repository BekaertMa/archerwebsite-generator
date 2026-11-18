<?php
if (!defined('ABSPATH')) exit;

/**
 * Stockfoto's uit Unsplash én Pexels.
 *
 * Neemt de twee AJAX-acties van de stijlgids over (aisb_get_unsplash_images en
 * aisb_search_similar_images) zodra er een Pexels-sleutel is: de foto's komen dan om en om
 * uit beide bronnen, en valt één bron weg, dan vult de andere aan. Zonder Pexels-sleutel
 * blijft alles zoals het was (alleen Unsplash).
 *
 * Sleutel: Instellingen → AI Sitemap Builder → Stockfoto's, of de constante AISB_PEXELS_KEY in wp-config.php.
 * De sleutel gaat nooit naar de browser.
 */
class AISB_Archer_Stock {

  const OPTION = 'archer_pexels_key';
  const OPTION_PIXABAY = 'archer_pixabay_key';

  public function init(): void {
    add_action('wp_ajax_aisb_archer_stock_page', [$this, 'ajax_page']);
    add_action('wp_ajax_aisb_get_unsplash_images', [$this, 'ajax_auto'], 1);
    add_action('wp_ajax_aisb_search_similar_images', [$this, 'ajax_search'], 1);
    add_action('admin_init', [$this, 'register'], 20);
  }

  public static function pexels_key(): string {
    if (defined('AISB_PEXELS_KEY') && AISB_PEXELS_KEY) return (string) AISB_PEXELS_KEY;
    return (string) get_option(self::OPTION, '');
  }

  public static function unsplash_key(): string {
    return defined('AISB_UNSPLASH_KEY') && AISB_UNSPLASH_KEY ? (string) AISB_UNSPLASH_KEY : '';
  }

  public static function pixabay_key(): string {
    if (defined('AISB_PIXABAY_KEY') && AISB_PIXABAY_KEY) return (string) AISB_PIXABAY_KEY;
    return (string) get_option(self::OPTION_PIXABAY, '');
  }

  /**
   * De bronnen die een sleutel hebben, in een vaste volgorde: naam => de methode die haar bevraagt.
   * Alles wat met bronnen werkt loopt hierlangs, zodat er een vierde bij kan zonder dat elke
   * zoekfunctie opnieuw aangepast moet worden.
   *
   * @return array<string,callable>
   */
  public static function readers(): array {
    $out = [];
    if (self::unsplash_key()) $out['Unsplash'] = [self::class, 'unsplash'];
    if (self::pexels_key()) $out['Pexels'] = [self::class, 'pexels'];
    if (self::pixabay_key()) $out['Pixabay'] = [self::class, 'pixabay'];
    return $out;
  }

  public static function sources(): array {
    return array_keys(self::readers());
  }

  /**
   * Elke ingestelde bron bevragen en de lijsten apart teruggeven, zodat mix() ze om beurten
   * kan nemen. Een bron die niet antwoordt, valt gewoon weg.
   *
   * @return array{lists: array<int,array>, pages: int}
   */
  private static function fetch(string $q, int $per, int $page): array {
    $lists = [];
    $pages = 1;
    foreach (self::readers() as $lees) {
      $r = $lees($q, $per, $page);
      if (is_wp_error($r)) continue;
      if (!empty($r['images'])) $lists[] = $r['images'];
      $pages = max($pages, (int) $r['total_pages']);
    }
    return ['lists' => $lists, 'pages' => $pages];
  }

  /* ───────────────────────── Instellingen ───────────────────────── */

  /**
   * Veld op de bestaande pagina Instellingen → AI Sitemap Builder. Het hoort bij dezelfde
   * settings-groep, zodat de knop "Wijzigingen opslaan" van die pagina het mee bewaart.
   */
  public function register(): void {
    $schoon = ['type' => 'string', 'sanitize_callback' => fn($v) => sanitize_text_field((string) $v), 'show_in_rest' => false, 'default' => ''];
    register_setting('aisb_settings_group', self::OPTION, $schoon);
    register_setting('aisb_settings_group', self::OPTION_PIXABAY, $schoon);
    add_settings_section('aisb_stock', "Stockfoto's", function () {
      echo '<p>Elke sleutel die je hier invult, komt erbij als bron in de stap Foto\'s: de foto\'s komen dan om beurten uit elke bron. Gratis sleutels vraag je aan op '
        . '<a href="https://www.pexels.com/api/" target="_blank" rel="noopener">pexels.com/api</a> en '
        . '<a href="https://pixabay.com/api/docs/" target="_blank" rel="noopener">pixabay.com/api/docs</a>. Leeg laten om die bron over te slaan.</p>';
      echo '<p class="description">Pixabay staat niet toe dat een gepubliceerde site rechtstreeks naar hun adressen verwijst, dus hun foto\'s worden naar de mediabibliotheek gehaald. Unsplash verplicht net het omgekeerde en blijft bij de bron.</p>';
    }, 'ai-sitemap-builder');
    add_settings_field('archer_pexels_key', 'Pexels API Key', [$this, 'field'], 'ai-sitemap-builder', 'aisb_stock', ['label_for' => 'archer_pexels_key']);
    add_settings_field('archer_pixabay_key', 'Pixabay API Key', [$this, 'field_pixabay'], 'ai-sitemap-builder', 'aisb_stock', ['label_for' => 'archer_pixabay_key']);
  }

  public function field(): void {
    if (defined('AISB_PEXELS_KEY') && AISB_PEXELS_KEY) {
      echo '<p><strong>Ingesteld in wp-config.php (AISB_PEXELS_KEY).</strong> Die waarde gaat voor.</p>';
      return;
    }
    echo '<input type="password" id="archer_pexels_key" name="' . esc_attr(self::OPTION) . '" value="' . esc_attr((string) get_option(self::OPTION, '')) . '" class="regular-text" autocomplete="off">';
    echo '<p class="description">' . (self::unsplash_key() ? 'Unsplash staat aan (AISB_UNSPLASH_KEY in wp-config.php).' : 'Er is geen Unsplash-sleutel ingesteld.') . '</p>';
  }

  public function field_pixabay(): void {
    if (defined('AISB_PIXABAY_KEY') && AISB_PIXABAY_KEY) {
      echo '<p><strong>Ingesteld in wp-config.php (AISB_PIXABAY_KEY).</strong> Die waarde gaat voor.</p>';
      return;
    }
    echo '<input type="password" id="archer_pixabay_key" name="' . esc_attr(self::OPTION_PIXABAY) . '" value="' . esc_attr((string) get_option(self::OPTION_PIXABAY, '')) . '" class="regular-text" autocomplete="off">';
    $nu = self::sources();
    echo '<p class="description">' . ($nu ? 'Bronnen die nu meedoen: ' . esc_html(implode(', ', $nu)) . '.' : 'Er doet nog geen enkele bron mee.') . '</p>';
  }

  /* ───────────────────────── Toegang ───────────────────────── */

  private function guard(bool $project = true): int {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Not logged in'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok = false;
    foreach (['aisb_sg_nonce', 'aisb_nonce_action'] as $a) if ($nonce && wp_verify_nonce($nonce, $a)) $ok = true;
    if (!$ok) wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    if (!$project) return 0;
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $pid ? get_post($pid) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    return $pid;
  }

  /* ───────────────────────── Bronnen ───────────────────────── */

  /** @return array{images: array, total_pages: int}|WP_Error */
  private static function unsplash(string $q, int $per, int $page) {
    $key = self::unsplash_key();
    if (!$key) return new WP_Error('no_key', 'Geen Unsplash-sleutel');
    $res = wp_remote_get(add_query_arg(['query' => $q, 'per_page' => min(30, $per), 'page' => $page, 'orientation' => 'landscape'], 'https://api.unsplash.com/search/photos'), [
      'headers' => ['Authorization' => 'Client-ID ' . $key, 'Accept-Version' => 'v1'], 'timeout' => 15]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('http', 'Unsplash gaf geen antwoord');
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    $out = [];
    foreach ((array) ($body['results'] ?? []) as $p) {
      $out[] = ['thumb' => $p['urls']['small'] ?? '', 'full' => $p['urls']['regular'] ?? '', 'alt' => $p['alt_description'] ?? $q,
        'photographer' => $p['user']['name'] ?? '', 'link' => $p['links']['html'] ?? '', 'source' => 'Unsplash'];
    }
    return ['images' => $out, 'total_pages' => (int) ($body['total_pages'] ?? 1)];
  }

  /** @return array{images: array, total_pages: int}|WP_Error */
  private static function pexels(string $q, int $per, int $page) {
    $key = self::pexels_key();
    if (!$key) return new WP_Error('no_key', 'Geen Pexels-sleutel');
    $per = min(80, max(1, $per));
    $res = wp_remote_get(add_query_arg(['query' => $q, 'per_page' => $per, 'page' => $page, 'orientation' => 'landscape'], 'https://api.pexels.com/v1/search'), [
      'headers' => ['Authorization' => $key], 'timeout' => 15]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('http', 'Pexels gaf geen antwoord');
    $body = json_decode((string) wp_remote_retrieve_body($res), true);
    $out = [];
    foreach ((array) ($body['photos'] ?? []) as $p) {
      $out[] = ['thumb' => $p['src']['medium'] ?? '', 'full' => $p['src']['large2x'] ?? ($p['src']['large'] ?? ''), 'alt' => $p['alt'] ?? $q,
        'photographer' => $p['photographer'] ?? '', 'link' => $p['url'] ?? '', 'source' => 'Pexels'];
    }
    $total = (int) ($body['total_results'] ?? 0);
    return ['images' => $out, 'total_pages' => max(1, (int) ceil($total / $per))];
  }

  /**
   * Pixabay. Twee dingen die hun API vraagt en de andere twee bronnen niet:
   * - het antwoord moet 24 uur bewaard worden, vandaar de transient;
   * - naar hun adressen mag een gepubliceerde site niet blijven verwijzen, dus komen hun foto's
   *   via AISB_Archer_Stock::HOSTING in de mediabibliotheek terecht.
   * largeImageURL is 1280px breed; fullHD en origineel vragen een goedgekeurd account.
   *
   * @return array{images: array, total_pages: int}|WP_Error
   */
  private static function pixabay(string $q, int $per, int $page) {
    $key = self::pixabay_key();
    if (!$key) return new WP_Error('no_key', 'Geen Pixabay-sleutel');
    $per = min(200, max(3, $per));
    $args = ['key' => $key, 'q' => $q, 'image_type' => 'photo', 'orientation' => 'horizontal',
      'per_page' => $per, 'page' => max(1, $page), 'safesearch' => 'true'];
    // De sleutel hoort niet in de naam van de transient.
    $cache = 'aisb_px_' . md5($q . '|' . $per . '|' . $page);
    $body = get_transient($cache);
    if (!is_array($body)) {
      $res = wp_remote_get(add_query_arg($args, 'https://pixabay.com/api/'), ['timeout' => 15]);
      if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return new WP_Error('http', 'Pixabay gaf geen antwoord');
      $body = json_decode((string) wp_remote_retrieve_body($res), true);
      if (!is_array($body)) return new WP_Error('http', 'Pixabay gaf geen bruikbaar antwoord');
      set_transient($cache, $body, DAY_IN_SECONDS);
    }
    $out = [];
    foreach ((array) ($body['hits'] ?? []) as $p) {
      $vol = (string) ($p['largeImageURL'] ?? '');
      if ($vol === '') continue;
      // tags is "brood, bakkerij, oven": de eerste twee lezen als alt-tekst beter dan alle zes.
      $tags = array_slice(array_filter(array_map('trim', explode(',', (string) ($p['tags'] ?? '')))), 0, 2);
      $out[] = ['thumb' => (string) ($p['webformatURL'] ?? $vol), 'full' => $vol,
        'alt' => $tags ? implode(', ', $tags) : $q,
        'photographer' => (string) ($p['user'] ?? ''), 'link' => (string) ($p['pageURL'] ?? ''), 'source' => 'Pixabay'];
    }
    $total = (int) ($body['totalHits'] ?? 0);
    return ['images' => $out, 'total_pages' => max(1, (int) ceil($total / $per))];
  }

  /**
   * Sleutel van een foto: Unsplash geeft dezelfde foto met andere parameters terug (ixid, crop),
   * dus vergelijken op "photo-…" of Pexels "photos/123", niet op het hele adres.
   */
  public static function pkey(string $url): string {
    // Unsplash en Pexels geven dezelfde foto met andere parameters terug (ixid, crop), en Pixabay
    // met een andere maat achteraan (…-1867459_1280.jpg), dus vergelijken op het nummer erin.
    if (preg_match('#photo-[0-9a-f]+-[0-9a-f]+|photos/\d+#i', $url, $m)) return strtolower($m[0]);
    if (preg_match('#[-_](\d{5,})_\d{3,4}\.(?:jpe?g|png)#i', $url, $m)) return 'pixabay/' . $m[1];
    return $url;
  }

  /* ───────────────────────── Waar een foto woont ───────────────────────── */

  /**
   * Hotlinken of een eigen kopie? De bibliotheken vragen daar iets anders in, en dat verschil is
   * geen detail maar een voorwaarde van hun API:
   * - Unsplash verplicht hotlinken ("All API uses must use the hotlinked image URLs returned by the
   *   API under the photo.urls properties", en dat geldt voor elk gebruik, niet enkel voor
   *   zoekresultaten). Hun foto's blijven dus bij hen staan.
   * - Pexels laat het vrij.
   * - Pixabay verbiedt permanent hotlinken en vraagt de foto naar de eigen server te halen.
   * Een bron die hier niet in staat, blijft hotlinken. Dat is de veilige kant: te veel kopiëren
   * breekt een voorwaarde, te weinig kopiëren breekt hoogstens een foto.
   */
  const HOSTING = ['Unsplash' => 'hotlink', 'Pexels' => 'kopie', 'Pixabay' => 'kopie'];

  public static function hosting(string $source): string {
    return self::HOSTING[$source] ?? 'hotlink';
  }

  /**
   * Een stockfoto naar de mediabibliotheek halen, als haar bron dat toelaat of vraagt.
   * Lukt het niet, dan blijft ze staan waar ze stond: een site die een foto elders ophaalt is
   * beter dan een site met een gat. De fotograaf, de link en de bron blijven hoe dan ook bewaard,
   * want de bronvermelding hangt daaraan en niet aan het adres van het bestand.
   */
  public static function localise(array $photo, int $pid = 0): array {
    if (self::hosting((string) ($photo['source'] ?? '')) !== 'kopie') return $photo;
    if (!empty($photo['attachment_id']) || !empty($photo['uploaded'])) return $photo;
    $full = (string) ($photo['full'] ?? '');
    if ($full === '' || self::is_local($full)) return $photo;

    $id = self::existing($full) ?: self::store($full, $photo, $pid);
    if (!$id) return $photo;
    $local = set_url_scheme((string) wp_get_attachment_url($id));
    if ($local === '') return $photo;

    $photo['attachment_id'] = $id;
    $photo['remote'] = $full;
    $photo['full'] = $local;
    $photo['thumb'] = set_url_scheme((string) (wp_get_attachment_image_url($id, 'medium_large') ?: $local));
    return $photo;
  }

  private static function is_local(string $url): bool {
    $h = (string) wp_parse_url($url, PHP_URL_HOST);
    return $h === '' || strcasecmp($h, (string) wp_parse_url(home_url(), PHP_URL_HOST)) === 0;
  }

  /** Dezelfde foto al eens binnengehaald? Dan die hergebruiken in plaats van opnieuw te downloaden. */
  private static function existing(string $url): int {
    $q = get_posts(['post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1,
      'fields' => 'ids', 'meta_key' => '_archer_stock_key', 'meta_value' => self::pkey($url)]);
    return $q ? (int) $q[0] : 0;
  }

  /** @return int attachment-id, of 0 als het niet lukte. */
  private static function store(string $url, array $photo, int $pid): int {
    $res = wp_remote_get($url, ['timeout' => 25, 'redirection' => 3]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) return 0;
    $bytes = (string) wp_remote_retrieve_body($res);
    if ($bytes === '' || strlen($bytes) > 12 * MB_IN_BYTES) return 0;
    // Wat binnenkomt is van een derde: eerst kijken of het werkelijk een beeld is, en welk.
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
    if ($ext === '') return 0;

    $alt = (string) ($photo['alt'] ?? '');
    $naam = 'archer-' . sanitize_title(strtolower((string) ($photo['source'] ?? 'stock'))) . '-'
      . sanitize_title(mb_substr($alt !== '' ? $alt : 'foto', 0, 40)) . '-' . wp_generate_password(6, false) . '.' . $ext;
    $up = wp_upload_bits($naam, null, $bytes);
    if (!empty($up['error']) || empty($up['file'])) return 0;

    $id = wp_insert_attachment(['post_mime_type' => $info['mime'], 'post_title' => $alt !== '' ? $alt : $naam,
      'post_status' => 'inherit', 'post_parent' => 0], $up['file']);
    if (is_wp_error($id) || !$id) { @unlink($up['file']); return 0; }
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $up['file']));
    if ($alt !== '') update_post_meta($id, '_wp_attachment_image_alt', $alt);
    // De herkomst blijft aan het beeld hangen, niet aan de stijlgids: zo weten we later nog
    // waar het vandaan komt, ook als het project opnieuw opgebouwd wordt.
    update_post_meta($id, '_archer_stock_key', self::pkey($url));
    update_post_meta($id, '_archer_stock_source', (string) ($photo['source'] ?? ''));
    update_post_meta($id, '_archer_stock_link', (string) ($photo['link'] ?? ''));
    update_post_meta($id, '_archer_stock_photographer', (string) ($photo['photographer'] ?? ''));
    if ($pid) update_post_meta($id, '_archer_project', $pid);
    return (int) $id;
  }

  /**
   * De foto's van een bestaand project alsnog binnenhalen. Geeft terug hoeveel er omgezet zijn,
   * hoeveel er bij hun bron blijven (Unsplash) en hoeveel er niet lukten.
   */
  public static function backfill(int $pid, bool $dry = false): array {
    $g = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    if (!is_array($g)) $g = [];
    $imgs = is_array($g['images'] ?? null) ? array_values($g['images']) : [];
    $n = ['om' => 0, 'blijft' => 0, 'mislukt' => 0, 'al_lokaal' => 0];
    foreach ($imgs as $i => $img) {
      if (!is_array($img) || empty($img['full'])) continue;
      if (!empty($img['attachment_id']) || self::is_local((string) $img['full'])) { $n['al_lokaal']++; continue; }
      if (self::hosting((string) ($img['source'] ?? '')) !== 'kopie') { $n['blijft']++; continue; }
      if ($dry) { $n['om']++; continue; }
      $nieuw = self::localise($img, $pid);
      if (empty($nieuw['attachment_id'])) { $n['mislukt']++; continue; }
      $imgs[$i] = $nieuw;
      $n['om']++;
    }
    if (!$dry && $n['om']) {
      $g['images'] = $imgs;
      update_post_meta($pid, 'aisb_style_guide', wp_slash(wp_json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
      update_post_meta($pid, 'aisb_project_updated_at', gmdate('c'));
    }
    return $n;
  }

  /**
   * Om beurten uit elke lijst, zonder dubbele foto's. Een lijst die eerder leeg raakt dan de
   * andere houdt de beurten niet tegen.
   *
   * @param array<int,array> $lists
   */
  private static function mix(array $lists, int $max): array {
    $lists = array_values(array_filter($lists, fn($l) => is_array($l) && $l));
    $out = [];
    $seen = [];
    $langste = 0;
    foreach ($lists as $l) $langste = max($langste, count($l));
    for ($i = 0; $i < $langste && count($out) < $max; $i++) {
      foreach ($lists as $l) {
        $img = $l[$i] ?? null;
        if (!$img || empty($img['full']) || isset($seen[self::pkey($img['full'])])) continue;
        $seen[self::pkey($img['full'])] = 1;
        $out[] = $img;
        if (count($out) >= $max) break;
      }
    }
    return $out;
  }

  /** Zoekwoord (Engels, 1–3 woorden) uit de beschrijving van het project; bewaard per project. */
  private static function keyword(int $pid): string {
    $kw = (string) get_post_meta($pid, 'archer_image_keyword', true);
    if ($kw !== '') return $kw;
    $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
    $context = $brief !== '' ? mb_substr($brief, 0, 3000) : (string) get_the_title($pid);
    $settings = get_option('aisb_settings', []);
    $kw = 'business';
    if (!empty($settings['api_key']) && class_exists('AISB_OpenAI')) {
      $prompt = "Website brief: \"{$context}\"\nGenerate a highly relevant search phrase (1 to 3 words) for stock photography for this website. Examples: \"hearing clinic\" -> \"hearing test\", \"car mechanic\" -> \"auto repair\", \"law firm\" -> \"lawyer office\". Return ONLY the phrase in English.";
      $r = (new AISB_OpenAI())->call_openai_chat_completions($prompt, $settings, 'You are a stock photo keyword extractor. Return ONLY 1 to 3 English words. Never a company name, person name or URL.');
      if (!is_wp_error($r)) {
        $c = trim(str_replace(['.', ',', '"', "'", ':', ';', '{', '}'], '', strtolower(wp_strip_all_tags((string) $r))));
        if ($c !== '' && strlen($c) < 60) $kw = $c;
      }
    }
    update_post_meta($pid, 'archer_image_keyword', $kw);
    return $kw;
  }

  /** Zoekwoord voor één pagina (Engels, 1–4 woorden), uit de beschrijving en het onderwerp van de pagina. */
  private static function page_keyword(int $pid, string $slug, string $title, string $sections): string {
    // Eén metasleutel per pagina: de pagina's worden tegelijk gevraagd.
    $mk = 'archer_image_kw_' . substr(md5($slug . '|' . $title . '|' . $sections), 0, 12);
    $cached = (string) get_post_meta($pid, $mk, true);
    if ($cached !== '') return $cached;
    $brief = mb_substr((string) get_post_meta($pid, 'aisb_project_brief', true), 0, 1500);
    $settings = get_option('aisb_settings', []);
    $kw = '';
    if (!empty($settings['api_key']) && class_exists('AISB_OpenAI')) {
      $prompt = "Business: \"{$brief}\"\nWebsite page: \"{$title}\"" . ($sections !== '' ? "\nSections on this page: {$sections}" : '')
        . (($mood = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::prompt_notes(json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true), 'photo') : '') !== '' ? "\nVisual mood of the website style: {$mood}. You may add one mood word (for example 'bright' or 'moody') if it fits." : '')
        . (($look = class_exists('AISB_Archer_Direction') ? AISB_Archer_Direction::prompt_block($pid, 'photo') : '') !== '' ? "\n{$look} Keep the phrase compatible with that look so all photos of the site look like one shoot." : '')
        . "\nGive one stock photo search phrase in English (2 to 4 words) that shows what THIS page is about for this business, concrete and visual (a place, an object, people at work). Examples: page \"Badkamerrenovatie\" of a plumber -> \"modern bathroom renovation\"; page \"Over ons\" of a bakery -> \"bakers in bakery\"; page \"Contact\" of a law firm -> \"law office reception\". Return ONLY the phrase.";
      $r = (new AISB_OpenAI())->call_openai_chat_completions($prompt, $settings, 'You return only a short English stock photo search phrase. Never a company name, a person name or a URL.');
      if (!is_wp_error($r)) {
        $c = trim(str_replace(['.', ',', '"', "'", ':', ';', '{', '}'], '', strtolower(wp_strip_all_tags((string) $r))));
        if ($c !== '' && strlen($c) < 60) $kw = $c;
      }
    }
    if ($kw === '') $kw = self::keyword($pid);
    update_post_meta($pid, $mk, $kw);
    return $kw;
  }

  /**
   * Stockfoto's voor één pagina (knop "Kies stockfoto's voor deze pagina"): een eigen zoekwoord per pagina,
   * zonder de foto's die al op andere pagina's staan.
   */
  public function ajax_page(): void {
    $pid = $this->guard(true);
    @set_time_limit(60);
    $slug = sanitize_title(wp_unslash($_POST['page_slug'] ?? ''));
    $title = sanitize_text_field(wp_unslash($_POST['page_title'] ?? ''));
    $sections = sanitize_text_field(wp_unslash($_POST['sections'] ?? ''));
    $need = max(1, min(40, (int) ($_POST['need'] ?? 1)));
    $exclude = json_decode((string) wp_unslash($_POST['exclude'] ?? '[]'), true);
    $r = self::for_page($pid, $slug, $title, $sections, $need, is_array($exclude) ? $exclude : []);
    if (is_wp_error($r)) wp_send_json_error(['message' => $r->get_error_message()]);
    wp_send_json_success($r);
  }

  /**
   * Stockfoto's voor één pagina: een eigen zoekwoord per pagina, zonder de foto's in $exclude.
   * Gebruikt door de knop in de stap Foto's en door het scherm Ontwerp.
   *
   * @return array{images: array, keyword: string, sources: array}|WP_Error
   */
  public static function for_page(int $pid, string $slug, string $title, string $sections, int $need, array $exclude) {
    $need = max(1, min(40, $need));
    $skip = array_flip(array_map([self::class, 'pkey'], array_filter(array_map('strval', $exclude))));
    $kw = self::page_keyword($pid, $slug, $title, $sections);
    $want = $need + count($skip) + 4;
    $eerste = self::fetch($kw, $want, 1);
    $pool = array_values(array_filter(self::mix($eerste['lists'], $want + 10), fn($img) => !isset($skip[self::pkey($img['full'])])));
    foreach ($pool as $img) $skip[self::pkey($img['full'])] = 1;
    // Te weinig (veel foto's staan al op andere pagina's): volgende resultaatpagina's, dan het algemene zoekwoord.
    $tries = [[$kw, 2], [$kw, 3], [self::keyword($pid), 1], [self::keyword($pid), 2]];
    foreach ($tries as [$q, $pg]) {
      if (count($pool) >= $need) break;
      $more = self::mix(self::fetch($q, 40, $pg)['lists'], 70);
      foreach ($more as $img) {
        if (count($pool) >= $need) break;
        if (isset($skip[self::pkey($img['full'])])) continue;
        $skip[self::pkey($img['full'])] = 1;
        $pool[] = $img;
      }
    }
    // Nog te weinig: aanvullen met beelden in de beeldtaal van de site (ADR-0033; standaard uit).
    if (count($pool) < $need && class_exists('AISB_Archer_Imagery') && AISB_Archer_Imagery::on()) {
      $pool = array_merge($pool, AISB_Archer_Imagery::fill($pid, $kw, $need - count($pool)));
    }
    if (!$pool) return new WP_Error('no_photos', 'Geen stockfoto\'s gevonden voor "' . $kw . '".');
    // Een paar reserves: pagina's worden tegelijk gevuld, de browser slaat dubbels over.
    return ['images' => array_slice($pool, 0, $need + 8), 'keyword' => $kw, 'sources' => self::sources()];
  }

  /**
   * Stockfoto's zoeken voor de fotokiezer in het scherm Ontwerp. Zonder zoekwoord: het zoekwoord van de pagina.
   *
   * @return array{images: array, keyword: string, page: int, total_pages: int}|WP_Error
   */
  public static function search_for(int $pid, string $q, string $slug, string $title, string $sections, int $page = 1) {
    $q = trim($q);
    if ($q === '') $q = $slug !== '' ? self::page_keyword($pid, $slug, $title, $sections) : self::keyword($pid);
    $page = max(1, min(50, $page));
    $r = self::fetch($q, 12, $page);
    if (!$r['lists']) return new WP_Error('no_photos', 'Geen foto\'s gevonden voor "' . $q . '".');
    return ['images' => self::mix($r['lists'], 24), 'keyword' => $q, 'page' => $page, 'total_pages' => min($r['pages'], 50)];
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  /** Alle foto's voor de wireframes in één keer (stap Foto's). */
  public function ajax_auto(): void {
    // Maar één bron? Dan doet de stijlgids het zelf, zoals vroeger.
    if (count(self::readers()) < 2) return;
    $pid = $this->guard(true);
    @set_time_limit(60);
    $need = isset($_POST['total_needed']) ? max(1, min(150, (int) $_POST['total_needed'])) : 30;
    $kw = self::keyword($pid);
    // Elke bron hoeft maar haar deel te leveren, met wat reserve voor de dubbels.
    $deel = (int) ceil($need / max(1, count(self::readers()))) + 4;
    $lists = [];
    foreach (self::readers() as $naam => $lees) {
      $eigen = [];
      for ($p = 1; count($eigen) < $deel && $p <= 5; $p++) {
        $r = $lees($kw, min(80, $deel), $p);
        if (is_wp_error($r) || !$r['images']) break;
        $eigen = array_merge($eigen, $r['images']);
      }
      if ($eigen) $lists[] = $eigen;
    }
    $images = self::mix($lists, $need);
    if (!$images) wp_send_json_error(['message' => 'Geen stockfoto\'s gevonden voor "' . $kw . '".']);
    wp_send_json_success(['images' => $images, 'keyword' => $kw, 'sources' => self::sources()]);
  }

  /** Andere foto zoeken (venster bij het wisselen van een foto). */
  public function ajax_search(): void {
    if (count(self::readers()) < 2) return;
    $this->guard(false);
    $q = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
    if ($q === '') wp_send_json_error(['message' => 'Geen zoekwoord.'], 400);
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per = isset($_POST['per_page']) ? max(2, min(30, (int) $_POST['per_page'])) : 12;
    // Elke bron vraagt het volle aantal: valt er één weg, dan vullen de andere de pagina.
    $r = self::fetch($q, $per, $page);
    wp_send_json_success(['images' => self::mix($r['lists'], $per), 'page' => $page, 'total_pages' => min($r['pages'], 50)]);
  }
}
