<?php

if (!defined('ABSPATH')) exit;

/**
 * Sitemap en wireframes houden dezelfde secties bij: hetzelfde type, in dezelfde volgorde (zoals Relume).
 *
 * - Elk sectietype van de sitemap hoort bij precies één soort sjabloon in de bibliotheek (template_tag).
 * - Elke sitemapsectie onthoudt welke wireframesectie erbij hoort (wf_uuid).
 * - Wie de sitemap aanpast, ziet dat in de wireframes zodra die stap opent (de sitemap is de leidraad).
 * - Wie in de wireframes een sectie toevoegt, verwijdert, verschuift of van type verandert,
 *   ziet dat meteen in de sitemap.
 */
class AISB_Archer_Structure {

  /** Sectietype in de sitemap => soort sjabloon (template_tag) in de bibliotheek. */
  const MAP = [
    'Headers'                    => 'header',
    'Megamenu Sections - part of header section' => 'header',
    'Footers'                    => 'footer',
    'Hero Sections'              => 'hero',
    'Category Filters'           => 'filters',
    'Banner Section'             => 'banner',
    'Intro Sections'             => 'intro',
    'Feature Sections'           => 'features',
    'Content Sections'           => 'content',
    'Process Sections'           => 'process',
    'Timeline Sections'          => 'timeline',
    'Testimonial Sections'       => 'testimonials',
    'Logo Sections'              => 'social_proof',
    'Team Sections'              => 'team',
    'Single Team Sections'       => 'single_team',
    'Pricing Sections'           => 'pricing',
    'FAQ Sections'               => 'faq',
    'CTA Sections'               => 'cta',
    'Contact Sections'           => 'contact',
    'Gallery Sections'           => 'gallery',
    'Portfolio Sections'         => 'portfolio',
    'Single Portfolio Sections'  => 'single_portfolio',
    'Products Sections'          => 'products',
    'Single Product Sections'    => 'single_product',
    'Property Sections'          => 'property',
    'Single Property Sections'   => 'single_property',
    'Blog Sections'              => 'blog',
    'Single Post Hero'           => 'single_post_hero',
    'Single Post Sections'       => 'single_post',
    'Event Sections'             => 'event',
    'Single Event Sections'      => 'single_event',
    'Career Sections'            => 'career',
  ];

  /** Types die de sitemap niet meer aanbiedt (geen sjablonen) => waar ze naartoe gaan. Nu geen. */
  const RETIRED = [];

  public function init(): void {
    add_filter('aisb_section_types', [__CLASS__, 'filter_section_types']);
    add_action('wp_ajax_aisb_archer_sync_structure', [$this, 'ajax_sync']);
    add_action('wp_ajax_aisb_archer_sync_text', [$this, 'ajax_sync_text']);
    // Elke bewaring in de wireframes: de sitemap volgt (na het antwoord van de plugin).
    foreach (['aisb_update_wireframe_page', 'aisb_generate_wireframe_page', 'aisb_replace_section_type', 'aisb_archer_section_add'] as $a) {
      add_action('wp_ajax_' . $a, [$this, 'after_wireframe_save'], 1);
    }
    // In de wireframes alleen de types die ook in de sitemap bestaan.
    add_action('wp_ajax_aisb_get_bricks_section_types', [$this, 'ajax_wf_types'], 1);
    // Sjablonen die op een Archer-site niets tonen (webshopelementen, lussen over producten of categorieën) niet kiezen.
    add_filter('aisb_bricks_template_pool', [__CLASS__, 'static_pool'], 5, 3);
  }

  /**
   * Waarom een sjabloon niet bruikbaar is op een Archer-site: 'shop' (SureCart- of WooCommerce-elementen),
   * 'loop' (een lus over berichten, producten of categorieën: in de wireframe leeg of vreemde inhoud, en de
   * lijst van een inhoudstype wordt op de site uit gewone kaarten gemaakt), of '' (bruikbaar).
   */
  public static function template_issue(int $id): string {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    $data = get_post_meta($id, '_bricks_page_content_2', true);
    $why = '';
    foreach (is_array($data) ? $data : [] as $n) {
      $name = (string) ($n['name'] ?? '');
      if (strpos($name, 'surecart') === 0 || strpos($name, 'woocommerce') === 0) { $why = 'shop'; break; }
      if (!empty($n['settings']['hasLoop'])) $why = 'loop';
    }
    return $cache[$id] = $why;
  }

  public static function static_pool($pool, $type = '', $used = []) {
    if (!is_array($pool) || in_array($type, ['header', 'footer', 'megamenu'], true)) return $pool;
    $keep = array_values(array_filter($pool, fn($t) => self::template_issue((int) ($t['id'] ?? 0)) === ''));
    return $keep ?: $pool;
  }

  /* ───────────────────────── Types ───────────────────────── */

  public static function filter_section_types($types) {
    if (!is_array($types)) return $types;
    return array_values(array_filter($types, fn($t) => !isset(self::RETIRED[$t])));
  }

  public static function is_global(string $stype): bool {
    return $stype === 'Headers' || $stype === 'Footers' || strpos($stype, 'Megamenu') === 0;
  }

  public static function retire(string $stype): string {
    return self::RETIRED[$stype] ?? $stype;
  }

  /** Sitemaptype => sjabloonsoort. */
  public static function tag_for(string $stype): string {
    $stype = self::retire(trim($stype));
    if (isset(self::MAP[$stype])) return self::MAP[$stype];
    // Onbekend type (oude sitemap, eigen type): op naam.
    $t = strtolower($stype);
    foreach (self::MAP as $k => $v) {
      if (strpos($t, strtolower(preg_replace('/ Sections?$/', '', $k))) === 0) return $v;
    }
    return 'content';
  }

  /** Sjabloonsoort => sitemaptype (het eerste dat erbij hoort). */
  public static function sitemap_type_for(string $tag): string {
    $tag = strtolower(trim($tag));
    $alias = ['feature' => 'features', 'testimonial' => 'testimonials', 'logos' => 'social_proof', 'logo' => 'social_proof', 'generic' => 'content', 'contact_form' => 'contact'];
    $tag = $alias[$tag] ?? $tag;
    foreach (self::MAP as $s => $t) if ($t === $tag && strpos($s, 'Megamenu') !== 0) return $s;
    return 'Content Sections';
  }

  /** Zoals de wireframes de types vroeger samenvoegden: om bestaande pagina's te koppelen. */
  private static function legacy_tag(string $stype): string {
    $t = strtolower($stype);
    $map = [
      'headers' => 'header', 'megamenu' => 'header', 'footers' => 'footer',
      'hero' => 'hero', 'single post hero' => 'hero', 'banner' => 'hero',
      'testimonial' => 'testimonials', 'logo' => 'social_proof',
      'faq' => 'faq', 'pricing' => 'pricing', 'process' => 'process', 'timeline' => 'process',
      'team' => 'team', 'single team' => 'team',
      'cta' => 'cta', 'contact' => 'contact',
      'feature' => 'features', 'products' => 'features', 'single product' => 'features',
      'property' => 'features', 'single property' => 'features', 'portfolio' => 'features',
      'single portfolio' => 'features', 'gallery' => 'features', 'blog' => 'features', 'event' => 'features',
      'single event' => 'features', 'career' => 'features',
      'content' => 'content', 'intro' => 'content', 'category filters' => 'content', 'single post' => 'content',
    ];
    foreach ($map as $k => $v) if (strpos($t, $k) === 0) return $v;
    return 'content';
  }

  /** Sitemaptypes met minstens één sjabloon (zonder header en footer), in de volgorde van MAP. */
  public static function available(): array {
    static $out = null;
    if ($out !== null) return $out;
    $by = class_exists('AISB_Wireframes_Bricks') ? (new AISB_Wireframes_Bricks())->get_bricks_templates_by_type() : [];
    $out = [];
    foreach (self::MAP as $s => $tag) {
      if (self::is_global($s)) continue;
      if (!$by || !empty($by[$tag])) $out[] = $s;
    }
    return $out;
  }

  /** Nederlandse naam per sjabloonsoort: dezelfde als in de sitemap. */
  public static function tag_labels(): array {
    $labels = class_exists('AISB_Archer_UI') ? AISB_Archer_UI::section_labels() : [];
    $out = [];
    foreach (self::MAP as $s => $tag) {
      if (isset($out[$tag]) || strpos($s, 'Megamenu') === 0) continue;
      $out[$tag] = $labels[$s] ?? $s;
    }
    return $out;
  }

  public function ajax_wf_types(): void {
    if (!is_user_logged_in()) return;
    $by = class_exists('AISB_Wireframes_Bricks') ? (new AISB_Wireframes_Bricks())->get_bricks_templates_by_type() : [];
    $out = [];
    foreach (array_unique(array_merge(['header', 'footer'], array_map([__CLASS__, 'tag_for'], self::available()))) as $tag) {
      if (!empty($by[$tag])) $out[] = ['type' => $tag, 'count' => count($by[$tag])];
    }
    wp_send_json_success(['types' => $out]);
  }

  /* ───────────────────────── Opslag ───────────────────────── */

  private static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'aisb_wireframes';
  }

  private static function load_model(int $pid, int $sid, string $slug): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT model_json FROM ' . self::table() . ' WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s', $pid, $sid, $slug), ARRAY_A);
    if (!$row) return null;
    $m = json_decode((string) $row['model_json'], true);
    return is_array($m) ? $m : null;
  }

  private static function save_model(int $pid, int $sid, string $slug, array $model): void {
    global $wpdb;
    $wpdb->update(self::table(), [
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'compiled_bricks_json' => null,
      'updated_at' => current_time('mysql'),
    ], ['project_id' => $pid, 'sitemap_version_id' => $sid, 'page_slug' => $slug], ['%s', '%s', '%s'], ['%d', '%d', '%s']);
  }

  private static function load_sitemap(int $sid): ?array {
    $d = json_decode((string) get_post_meta($sid, 'aisb_sitemap_json', true), true);
    return is_array($d) && is_array($d['sitemap'] ?? null) ? $d : null;
  }

  private static function save_sitemap(int $pid, int $sid, array $data): void {
    update_post_meta($sid, 'aisb_sitemap_json', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    update_post_meta($pid, 'aisb_project_updated_at', gmdate('c'));
  }

  private static function page_index(array $data, string $slug): int {
    foreach ($data['sitemap'] as $i => $p) {
      if (is_array($p) && sanitize_title((string) ($p['slug'] ?? '')) === $slug) return (int) $i;
    }
    return -1;
  }

  private static function m_fixed(array $s): bool {
    return in_array(strtolower((string) ($s['type'] ?? '')), ['header', 'footer'], true);
  }

  /* ───────────────────────── Koppelen ───────────────────────── */

  /**
   * Koppelt sitemapsecties (midden) aan wireframesecties (midden) op een pagina zonder koppelingen:
   * de langste gemeenschappelijke reeks op type (oude of nieuwe indeling).
   * @return array<int,int> index sitemap-midden => index wireframe-midden
   */
  private static function align(array $S, array $M): array {
    $n = count($S); $m = count($M);
    $eq = function ($s, $w) {
      $wt = strtolower((string) ($w['type'] ?? ''));
      $st = (string) ($s['section_type'] ?? '');
      return $wt === self::tag_for($st) || $wt === self::legacy_tag($st);
    };
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
      for ($j = $m - 1; $j >= 0; $j--) {
        $L[$i][$j] = $eq($S[$i], $M[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
      }
    }
    $pairs = [];
    $i = 0; $j = 0;
    while ($i < $n && $j < $m) {
      if ($eq($S[$i], $M[$j])) { $pairs[$i] = $j; $i++; $j++; }
      elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) $i++;
      else $j++;
    }
    // Wat overblijft tussen twee koppelingen: op volgorde koppelen (het type wordt daarna rechtgezet).
    $si = array_values(array_diff(range(0, max(0, $n - 1)), array_keys($pairs)));
    $mj = array_values(array_diff(range(0, max(0, $m - 1)), array_values($pairs)));
    if ($n === 0) $si = [];
    if ($m === 0) $mj = [];
    foreach ($si as $a) {
      // Een vrije wireframesectie tussen dezelfde buren.
      $prevM = -1; $nextM = $m;
      foreach ($pairs as $x => $y) { if ($x < $a) $prevM = max($prevM, $y); if ($x > $a) $nextM = min($nextM, $y); }
      foreach ($mj as $k => $b) {
        if ($b > $prevM && $b < $nextM) { $pairs[$a] = $b; unset($mj[$k]); break; }
      }
    }
    ksort($pairs);
    return $pairs;
  }

  /** De middelste secties met hun plaats in de volledige lijst. */
  private static function middle_s(array $sections): array {
    $out = [];
    foreach ($sections as $i => $s) if (is_array($s) && !self::is_global((string) ($s['section_type'] ?? ''))) $out[] = $i;
    return $out;
  }

  private static function middle_m(array $sections): array {
    $out = [];
    foreach ($sections as $i => $s) if (is_array($s) && !self::m_fixed($s)) $out[] = $i;
    return $out;
  }

  /** Minstens één middelste sitemapsectie wijst naar een bestaande wireframesectie. */
  private static function has_links(array $page, array $model): bool {
    $uuids = [];
    foreach ((array) ($model['sections'] ?? []) as $w) if (is_array($w) && !empty($w['uuid'])) $uuids[(string) $w['uuid']] = true;
    foreach ((array) ($page['sections'] ?? []) as $s) {
      if (is_array($s) && !empty($s['wf_uuid']) && isset($uuids[(string) $s['wf_uuid']]) && !self::is_global((string) ($s['section_type'] ?? ''))) return true;
    }
    return false;
  }

  /**
   * Eerste koppeling van een bestaande pagina: wireframesecties zonder sitemapsectie (in de wireframes
   * toegevoegd) komen op hun plaats in de sitemap, in plaats van te verdwijnen.
   */
  private static function adopt_extra(array &$page, array $model): void {
    $labels = class_exists('AISB_Archer_UI') ? AISB_Archer_UI::section_labels() : [];
    $secs = (array) ($page['sections'] ?? []);
    $head = []; $foot = []; $S = [];
    foreach ($secs as $s) {
      if (!is_array($s)) continue;
      $t = (string) ($s['section_type'] ?? '');
      if ($t === 'Headers' || strpos($t, 'Megamenu') === 0) $head[] = $s;
      elseif ($t === 'Footers') $foot[] = $s;
      else $S[] = $s;
    }
    $pos = [];
    foreach ($S as $k => $s) if (!empty($s['wf_uuid'])) $pos[(string) $s['wf_uuid']] = $k;
    $out = [];
    $next = 0; // eerste sitemapsectie die nog niet in $out staat
    foreach ((array) $model['sections'] as $w) {
      if (!is_array($w) || self::m_fixed($w) || empty($w['uuid'])) continue;
      $u = (string) $w['uuid'];
      if (isset($pos[$u])) {
        for (; $next <= $pos[$u]; $next++) $out[] = $S[$next];
        continue;
      }
      $st = self::sitemap_type_for((string) ($w['type'] ?? 'content'));
      $out[] = ['section_name' => $labels[$st] ?? $st, 'section_type' => $st, 'purpose' => '', 'key_content' => [], 'wf_uuid' => $u];
    }
    for (; $next < count($S); $next++) $out[] = $S[$next];
    $page['sections'] = array_merge(array_slice($head, 0, 1), $out, array_slice($foot, 0, 1));
  }

  /** Zorgt dat elke sitemapsectie van de pagina een wf_uuid heeft als er een wireframesectie bij hoort. */
  private static function link(array &$page, array $model): void {
    $secs = (array) ($page['sections'] ?? []);
    $uuids = [];
    foreach ($model['sections'] as $w) if (is_array($w) && !empty($w['uuid'])) $uuids[(string) $w['uuid']] = true;
    $linked = 0;
    foreach ($secs as $k => $s) {
      if (!empty($s['wf_uuid']) && isset($uuids[(string) $s['wf_uuid']])) $linked++;
      elseif (isset($secs[$k]['wf_uuid'])) unset($secs[$k]['wf_uuid']);
    }
    if ($linked === 0) {
      $si = self::middle_s($secs);
      $mi = self::middle_m($model['sections']);
      $S = array_map(fn($i) => $secs[$i], $si);
      $M = array_map(fn($i) => $model['sections'][$i], $mi);
      foreach (self::align($S, $M) as $a => $b) $secs[$si[$a]]['wf_uuid'] = (string) $model['sections'][$mi[$b]]['uuid'];
    }
    // Header en footer: altijd die van de wireframe.
    foreach ($secs as $k => $s) {
      if (!is_array($s) || !self::is_global((string) ($s['section_type'] ?? '')) || strpos((string) $s['section_type'], 'Megamenu') === 0) continue;
      $want = $s['section_type'] === 'Headers' ? 'header' : 'footer';
      foreach ($model['sections'] as $w) {
        if (is_array($w) && strtolower((string) ($w['type'] ?? '')) === $want) { $secs[$k]['wf_uuid'] = (string) $w['uuid']; break; }
      }
    }
    $page['sections'] = array_values($secs);
  }

  /* ───────────────────────── Wireframes => sitemap ───────────────────────── */

  /** Na elke bewaring in de wireframes: de pagina in de sitemap volgt (als de koppeling er al is). */
  public function after_wireframe_save(): void {
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $sid = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id'] : 0;
    $slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
    if (!$pid || !$sid || $slug === '' || !is_user_logged_in()) return;
    // Pas na het antwoord van de plugin: dan staat het model in de database.
    register_shutdown_function(function () use ($pid, $sid, $slug, $action) {
      try {
        self::label_filters($pid, $sid, $slug);
        self::push_to_sitemap($pid, $sid, $slug, $action === 'aisb_generate_wireframe_page');
      } catch (\Throwable $e) {
        error_log('[Archer] sitemap volgen mislukt: ' . $e->getMessage());
      }
    });
  }

  /**
   * De sitemap van deze pagina krijgt de secties van de wireframe (type en volgorde).
   * Een pagina zonder koppeling wordt alleen gekoppeld na een nieuwe wireframe ($fresh);
   * anders wacht ze op sync_page (sitemap is dan de leidraad).
   */
  public static function push_to_sitemap(int $pid, int $sid, string $slug, bool $fresh = false): bool {
    $model = self::load_model($pid, $sid, $slug);
    $data = self::load_sitemap($sid);
    if (!$model || !$data || !is_array($model['sections'] ?? null)) return false;
    $pi = self::page_index($data, $slug);
    if ($pi < 0) return false;
    $page = $data['sitemap'][$pi];
    if (!self::has_links($page, $model)) {
      // Nog niet gekoppeld: alleen na een nieuwe wireframe (die volgt de sitemap); anders koppelt sync_page.
      if (!$fresh) return false;
      self::link($page, $model);
    }
    $secs = (array) $page['sections'];

    $by_uuid = [];
    foreach ($secs as $s) if (is_array($s) && !empty($s['wf_uuid'])) $by_uuid[(string) $s['wf_uuid']] = $s;
    $labels = class_exists('AISB_Archer_UI') ? AISB_Archer_UI::section_labels() : [];
    $head = []; $foot = [];
    foreach ($secs as $s) {
      if (!is_array($s)) continue;
      $t = (string) ($s['section_type'] ?? '');
      if ($t === 'Headers' || strpos($t, 'Megamenu') === 0) $head[] = $s;
      elseif ($t === 'Footers') $foot[] = $s;
    }
    $mid = [];
    foreach ($model['sections'] as $w) {
      if (!is_array($w) || self::m_fixed($w) || empty($w['uuid'])) continue;
      $tag = strtolower((string) ($w['type'] ?? 'content'));
      $u = (string) $w['uuid'];
      if (isset($by_uuid[$u])) {
        $s = $by_uuid[$u];
        $st = self::retire((string) ($s['section_type'] ?? ''));
        if (self::tag_for($st) !== $tag) $st = self::sitemap_type_for($tag);
        $s['section_type'] = $st;
        $mid[] = $s;
      } else {
        $st = self::sitemap_type_for($tag);
        $mid[] = [
          'section_name' => $labels[$st] ?? $st,
          'section_type' => $st,
          'purpose' => '',
          'key_content' => [],
          'wf_uuid' => $u,
        ];
      }
    }
    $new = array_merge(array_slice($head, 0, 1), $mid, array_slice($foot, 0, 1));
    if (wp_json_encode($new) === wp_json_encode($page['sections'] ?? [])) return false;
    $data['sitemap'][$pi]['sections'] = $new;
    self::save_sitemap($pid, $sid, $data);
    return true;
  }

  /* ───────────────────────── Sitemap => wireframes ───────────────────────── */

  private static function pick(string $tag, array $used): ?array {
    $by = (new AISB_Wireframes_Bricks())->get_bricks_templates_by_type();
    $pool = (array) ($by[$tag] ?? []);
    $pool = (array) apply_filters('aisb_bricks_template_pool', $pool, $tag, $used);
    $free = array_values(array_filter($pool, fn($t) => !in_array((int) $t['id'], $used, true)));
    $list = $free ?: $pool;
    return $list ? $list[array_rand($list)] : null;
  }

  private static function fill(array $section, array $t): array {
    return array_merge($section, [
      'layout_key' => 'bricks_' . (int) $t['id'],
      'bricks_template_id' => (int) $t['id'],
      'bricks_template_title' => (string) $t['title'],
      'bricks_template_ttype' => (string) $t['ttype'],
      'bricks_shortcode' => (string) $t['shortcode'],
      'preview_schema' => null,
      'match_score' => null,
      'match_tags' => implode(', ', (array) $t['tags']),
      'ai_wireframe_id' => null,
    ]);
  }

  /**
   * De wireframe van een pagina volgt de sitemap: ontbrekende secties erbij, verwijderde eruit,
   * ander type => ander sjabloon, en dezelfde volgorde. Zonder AI-tekst (zie fill_text).
   * @return array{changed:bool, model:?array, pending:string[]}
   */
  public static function sync_page(int $pid, int $sid, string $slug): array {
    $none = ['changed' => false, 'model' => null, 'pending' => []];
    $model = self::load_model($pid, $sid, $slug);
    $data = self::load_sitemap($sid);
    if (!$model || !$data || empty($model['sections']) || !is_array($model['sections'])) return $none;
    $pi = self::page_index($data, $slug);
    if ($pi < 0) return $none;
    $page = $data['sitemap'][$pi];
    $before_sm = wp_json_encode($page['sections'] ?? []);
    $before_wf = wp_json_encode($model['sections']);

    // Oude types die geen sjablonen hebben.
    foreach ((array) ($page['sections'] ?? []) as $k => $s) {
      if (is_array($s) && isset(self::RETIRED[(string) ($s['section_type'] ?? '')])) $page['sections'][$k]['section_type'] = self::RETIRED[$s['section_type']];
    }
    $legacy = !self::has_links($page, $model);
    self::link($page, $model);
    if ($legacy) self::adopt_extra($page, $model);

    $by_uuid = [];
    foreach ($model['sections'] as $w) if (is_array($w) && !empty($w['uuid'])) $by_uuid[(string) $w['uuid']] = $w;
    $used = [];
    foreach ($model['sections'] as $w) if (is_array($w) && !empty($w['bricks_template_id'])) $used[] = (int) $w['bricks_template_id'];

    $pending = [];
    $mid = [];
    $kept = [];
    foreach ($page['sections'] as $k => $s) {
      if (!is_array($s) || self::is_global((string) ($s['section_type'] ?? ''))) continue;
      $tag = self::tag_for((string) ($s['section_type'] ?? ''));
      $u = (string) ($s['wf_uuid'] ?? '');
      if ($u !== '' && isset($by_uuid[$u])) {
        $w = $by_uuid[$u];
        if (strtolower((string) ($w['type'] ?? '')) !== $tag && empty($w['locked'])) {
          $t = self::pick($tag, $used);
          if ($t) {
            $old = (int) ($w['ai_wireframe_id'] ?? 0);
            if ($old > 0 && get_post_type($old) === 'ai_wireframe') wp_trash_post($old);
            $w = self::fill(array_merge($w, ['type' => $tag]), $t);
            $used[] = (int) $t['id'];
            $pending[] = $u;
          }
        }
        $mid[] = $w;
        $kept[$u] = true;
        continue;
      }
      // Nieuw in de sitemap: een sectie van dat type erbij.
      $t = self::pick($tag, $used);
      if (!$t) continue;
      $u = wp_generate_uuid4();
      $page['sections'][$k]['wf_uuid'] = $u;
      $mid[] = self::fill(['uuid' => $u, 'type' => $tag, 'locked' => false], $t);
      $used[] = (int) $t['id'];
      $pending[] = $u;
      $kept[$u] = true;
    }
    $head = []; $foot = [];
    foreach ($model['sections'] as $w) {
      if (!is_array($w)) continue;
      $t = strtolower((string) ($w['type'] ?? ''));
      if ($t === 'header' && !$head) $head[] = $w;
      elseif ($t === 'footer') $foot = [$w];
    }
    // Secties die niet meer in de sitemap staan: weg (hun AI-kopie ook).
    foreach ($model['sections'] as $w) {
      if (!is_array($w) || self::m_fixed($w) || isset($kept[(string) ($w['uuid'] ?? '')])) continue;
      $old = (int) ($w['ai_wireframe_id'] ?? 0);
      if ($old > 0 && get_post_type($old) === 'ai_wireframe') wp_trash_post($old);
    }
    $model['sections'] = array_merge($head, $mid, $foot);

    $changed_wf = wp_json_encode($model['sections']) !== $before_wf;
    if ($changed_wf) self::save_model($pid, $sid, $slug, $model);
    if (wp_json_encode($page['sections']) !== $before_sm) {
      $data['sitemap'][$pi] = $page;
      self::save_sitemap($pid, $sid, $data);
    }
    return ['changed' => $changed_wf, 'model' => $model, 'pending' => $pending];
  }

  /** AI-tekst voor de opgegeven secties, met de naam en het doel uit de sitemap. */
  public static function fill_text(int $pid, int $sid, string $slug, array $uuids): ?array {
    $model = self::load_model($pid, $sid, $slug);
    $data = self::load_sitemap($sid);
    if (!$model || !$uuids || !class_exists('AISB_Wireframes_AI')) return $model;
    $pi = $data ? self::page_index($data, $slug) : -1;
    $hint_by_uuid = [];
    if ($pi >= 0) {
      foreach ((array) ($data['sitemap'][$pi]['sections'] ?? []) as $s) {
        if (is_array($s) && !empty($s['wf_uuid'])) {
          $hint_by_uuid[(string) $s['wf_uuid']] = trim((string) ($s['section_name'] ?? '') . ': ' . (string) ($s['purpose'] ?? ''), ': ');
        }
      }
    }
    $hints = [];
    foreach ($model['sections'] as $i => $w) {
      if (!is_array($w)) continue;
      $h = $hint_by_uuid[(string) ($w['uuid'] ?? '')] ?? '';
      if (strtolower((string) ($w['type'] ?? '')) === 'filters' && class_exists('AISB_Archer_Collections')) $h = trim($h . ' ' . AISB_Archer_Collections::filter_hint($pid, $slug));
      if ($h !== '') $hints[$i] = $h;
    }
    if (class_exists('AISB_Archer_Generate')) AISB_Archer_Generate::set_hints($slug, $hints);
    @set_time_limit(240);
    try {
      $model = (new AISB_Wireframes_AI())->populate_bricks_content_with_ai($model, $pid, $sid, $slug, array_values($uuids));
    } catch (\Throwable $e) {
      error_log('[Archer] tekst na sitemapwijziging mislukt: ' . $e->getMessage());
      return $model;
    }
    self::save_model($pid, $sid, $slug, $model);
    self::label_filters($pid, $sid, $slug);
    return $model;
  }

  /** Filtersecties in de wireframe: titels en keuzes zoals op de site (zie AISB_Archer_Collections). */
  public static function label_filters(int $pid, int $sid, string $slug): void {
    if (!class_exists('AISB_Archer_Collections')) return;
    $model = self::load_model($pid, $sid, $slug);
    foreach ((array) ($model['sections'] ?? []) as $w) {
      if (!is_array($w) || strtolower((string) ($w['type'] ?? '')) !== 'filters' || empty($w['ai_wireframe_id'])) continue;
      $post = (int) $w['ai_wireframe_id'];
      $data = get_post_meta($post, '_bricks_page_content_2', true);
      if (!is_array($data) || !$data) continue;
      $new = AISB_Archer_Collections::preview_filters($data, $pid, $slug);
      if (wp_json_encode($new) !== wp_json_encode($data)) update_post_meta($post, '_bricks_page_content_2', wp_slash($new));
    }
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  private function guard(): array {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Niet aangemeld'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || (!wp_verify_nonce($nonce, 'aisb_wf_nonce') && !wp_verify_nonce($nonce, 'aisb_nonce_action'))) {
      wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    }
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $sid = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id'] : 0;
    $project = $pid ? get_post($pid) : null;
    if (!$project || $project->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $project->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    if (!$sid || (int) get_post_meta($sid, 'aisb_project_id', true) !== $pid) wp_send_json_error(['message' => 'Sitemap hoort niet bij dit project'], 400);
    return [$pid, $sid];
  }

  /** Alle pagina's met een wireframe: volgen de sitemap. Antwoordt met de gewijzigde modellen. */
  public function ajax_sync(): void {
    [$pid, $sid] = $this->guard();
    global $wpdb;
    $slugs = $wpdb->get_col($wpdb->prepare('SELECT page_slug FROM ' . self::table() . ' WHERE project_id=%d AND sitemap_version_id=%d', $pid, $sid));
    $pages = [];
    foreach ((array) $slugs as $slug) {
      $r = self::sync_page($pid, $sid, (string) $slug);
      if ($r['changed'] || $r['pending']) $pages[] = ['slug' => (string) $slug, 'wireframe' => $r['model'], 'pending' => $r['pending']];
    }
    wp_send_json_success(['pages' => $pages]);
  }

  public function ajax_sync_text(): void {
    ignore_user_abort(true);
    [$pid, $sid] = $this->guard();
    $slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuids = json_decode((string) wp_unslash($_POST['uuids'] ?? '[]'), true);
    $uuids = array_values(array_filter(array_map('strval', (array) $uuids)));
    if ($slug === '' || !$uuids) wp_send_json_error(['message' => 'Niets te schrijven'], 400);
    $model = self::fill_text($pid, $sid, $slug, array_slice($uuids, 0, 12));
    wp_send_json_success(['wireframe' => $model, 'page_slug' => $slug]);
  }

  /**
   * Bij het bewaren van de sitemap: de koppeling met de wireframes (wf_uuid) overnemen uit de bewaarde
   * versie, want de editor houdt alleen naam, type, doel en inhoud bij. Eerst op naam en type, dan op
   * naam (type gewijzigd), dan op type tussen dezelfde buren (naam gewijzigd).
   */
  public static function keep_links(array $stored, array $after): array {
    $prev = [];
    foreach ((array) ($stored['sitemap'] ?? []) as $p) {
      if (is_array($p)) $prev[sanitize_title((string) ($p['slug'] ?? ''))] = array_values(array_filter((array) ($p['sections'] ?? []), 'is_array'));
    }
    foreach ((array) ($after['sitemap'] ?? []) as $i => $p) {
      if (!is_array($p)) continue;
      $L = $prev[sanitize_title((string) ($p['slug'] ?? ''))] ?? [];
      if (!$L) continue;
      $I = (array) ($p['sections'] ?? []);
      $keys = array_keys($I);
      $used = [];
      $match = []; // positie in $I => index in $L
      $name = fn($s) => mb_strtolower(trim((string) ($s['section_name'] ?? '')));
      $type = fn($s) => self::retire((string) ($s['section_type'] ?? ''));
      $passes = [
        fn($a, $b) => $name($a) === $name($b) && $type($a) === $type($b),
        fn($a, $b) => $name($a) !== '' && $name($a) === $name($b),
      ];
      foreach ($passes as $eq) {
        foreach ($keys as $pos => $k) {
          if (isset($match[$pos]) || !is_array($I[$k])) continue;
          foreach ($L as $li => $old) {
            if (isset($used[$li]) || empty($old['wf_uuid'])) continue;
            if ($eq($I[$k], $old)) { $match[$pos] = $li; $used[$li] = true; break; }
          }
        }
      }
      // Hernoemd: zelfde type, tussen dezelfde gekoppelde buren.
      foreach ($keys as $pos => $k) {
        if (isset($match[$pos]) || !is_array($I[$k])) continue;
        $lo = -1; $hi = count($L);
        foreach ($match as $mp => $ml) { if ($mp < $pos) $lo = max($lo, $ml); if ($mp > $pos) $hi = min($hi, $ml); }
        foreach ($L as $li => $old) {
          if ($li <= $lo || $li >= $hi || isset($used[$li]) || empty($old['wf_uuid'])) continue;
          if ($type($old) === $type($I[$k])) { $match[$pos] = $li; $used[$li] = true; break; }
        }
      }
      foreach ($match as $pos => $li) $after['sitemap'][$i]['sections'][$keys[$pos]]['wf_uuid'] = (string) $L[$li]['wf_uuid'];
    }
    return $after;
  }

  private static function key(array $s): string {
    return trim((string) ($s['section_name'] ?? '')) . '|' . self::retire((string) ($s['section_type'] ?? ''));
  }
}
