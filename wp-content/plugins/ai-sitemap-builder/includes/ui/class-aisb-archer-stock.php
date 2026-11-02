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
 * Sleutel: constante AISB_PEXELS_KEY in wp-config.php, of Instellingen → Archer stockfoto's.
 * De sleutel gaat nooit naar de browser.
 */
class AISB_Archer_Stock {

  const OPTION = 'archer_pexels_key';

  public function init(): void {
    add_action('wp_ajax_aisb_get_unsplash_images', [$this, 'ajax_auto'], 1);
    add_action('wp_ajax_aisb_search_similar_images', [$this, 'ajax_search'], 1);
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_init', [$this, 'register']);
  }

  public static function pexels_key(): string {
    if (defined('AISB_PEXELS_KEY') && AISB_PEXELS_KEY) return (string) AISB_PEXELS_KEY;
    return (string) get_option(self::OPTION, '');
  }

  public static function unsplash_key(): string {
    return defined('AISB_UNSPLASH_KEY') && AISB_UNSPLASH_KEY ? (string) AISB_UNSPLASH_KEY : '';
  }

  public static function sources(): array {
    return array_values(array_filter([self::unsplash_key() ? 'Unsplash' : '', self::pexels_key() ? 'Pexels' : '']));
  }

  /* ───────────────────────── Instellingen ───────────────────────── */

  public function register(): void {
    register_setting('archer_stock', self::OPTION, ['type' => 'string', 'sanitize_callback' => fn($v) => sanitize_text_field((string) $v), 'show_in_rest' => false]);
  }

  public function menu(): void {
    add_options_page('Archer stockfoto\'s', 'Archer stockfoto\'s', 'manage_options', 'archer-stock', [$this, 'page']);
  }

  public function page(): void {
    if (!current_user_can('manage_options')) return;
    $const = defined('AISB_PEXELS_KEY') && AISB_PEXELS_KEY;
    echo '<div class="wrap"><h1>Archer stockfoto\'s</h1>';
    echo '<p>Met een Pexels-sleutel komen de stockfoto\'s in de stap Foto\'s uit Unsplash én Pexels. Een gratis sleutel vraag je aan op <a href="https://www.pexels.com/api/" target="_blank" rel="noopener">pexels.com/api</a>.</p>';
    if ($const) {
      echo '<p><strong>De sleutel staat in wp-config.php (AISB_PEXELS_KEY).</strong></p></div>';
      return;
    }
    echo '<form method="post" action="options.php">';
    settings_fields('archer_stock');
    echo '<table class="form-table"><tr><th scope="row"><label for="archer_pexels_key">Pexels API-sleutel</label></th><td>';
    echo '<input type="password" id="archer_pexels_key" name="' . esc_attr(self::OPTION) . '" value="' . esc_attr((string) get_option(self::OPTION, '')) . '" class="regular-text" autocomplete="off">';
    echo '<p class="description">Leeg laten om alleen Unsplash te gebruiken.</p></td></tr></table>';
    submit_button('Bewaren');
    echo '</form></div>';
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

  /** Om en om uit beide lijsten, zonder dubbele foto's. */
  private static function mix(array $a, array $b, int $max): array {
    $out = [];
    $seen = [];
    for ($i = 0; count($out) < $max && ($i < count($a) || $i < count($b)); $i++) {
      foreach ([$a[$i] ?? null, $b[$i] ?? null] as $img) {
        if (!$img || empty($img['full']) || isset($seen[$img['full']])) continue;
        $seen[$img['full']] = 1;
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

  /* ───────────────────────── AJAX ───────────────────────── */

  /** Alle foto's voor de wireframes in één keer (stap Foto's). */
  public function ajax_auto(): void {
    if (!self::pexels_key()) return; // geen Pexels: de stijlgids doet het zelf
    $pid = $this->guard(true);
    @set_time_limit(60);
    $need = isset($_POST['total_needed']) ? max(1, min(150, (int) $_POST['total_needed'])) : 30;
    $kw = self::keyword($pid);
    $half = (int) ceil($need / 2) + 4;
    $u = [];
    for ($p = 1; count($u) < $half && $p <= 5; $p++) {
      $r = self::unsplash($kw, 30, $p);
      if (is_wp_error($r) || !$r['images']) break;
      $u = array_merge($u, $r['images']);
    }
    $x = [];
    for ($p = 1; count($x) < $need && $p <= 2; $p++) {
      $r = self::pexels($kw, 80, $p);
      if (is_wp_error($r) || !$r['images']) break;
      $x = array_merge($x, $r['images']);
    }
    $images = self::mix($u, $x, $need);
    if (!$images) wp_send_json_error(['message' => 'Geen stockfoto\'s gevonden voor "' . $kw . '".']);
    wp_send_json_success(['images' => $images, 'keyword' => $kw, 'sources' => self::sources()]);
  }

  /** Andere foto zoeken (venster bij het wisselen van een foto). */
  public function ajax_search(): void {
    if (!self::pexels_key()) return;
    $this->guard(false);
    $q = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
    if ($q === '') wp_send_json_error(['message' => 'Geen zoekwoord.'], 400);
    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;
    $per = isset($_POST['per_page']) ? max(2, min(30, (int) $_POST['per_page'])) : 12;
    $half = (int) ceil($per / 2);
    $u = self::unsplash($q, $half, $page);
    $x = self::pexels($q, $half, $page);
    $ui = is_wp_error($u) ? [] : $u['images'];
    $xi = is_wp_error($x) ? [] : $x['images'];
    // Valt één bron weg, dan vult de andere de hele pagina.
    if (!$ui && !is_wp_error($x)) { $r = self::pexels($q, $per, $page); $xi = is_wp_error($r) ? $xi : $r['images']; }
    if (!$xi && !is_wp_error($u)) { $r = self::unsplash($q, $per, $page); $ui = is_wp_error($r) ? $ui : $r['images']; }
    $pages = max(is_wp_error($u) ? 1 : $u['total_pages'], is_wp_error($x) ? 1 : $x['total_pages']);
    wp_send_json_success(['images' => self::mix($ui, $xi, $per), 'page' => $page, 'total_pages' => min($pages, 50)]);
  }
}
