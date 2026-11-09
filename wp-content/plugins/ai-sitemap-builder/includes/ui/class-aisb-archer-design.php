<?php
if (!defined('ABSPATH')) exit;

/**
 * Scherm Ontwerp: sitemap, wireframes, stijl en foto's in één stap.
 *
 * Het canvas met alle pagina's (de wireframes) staat in het midden. Links staan de pagina's
 * (de sitemap: toevoegen, hernoemen, schrappen), rechts de stijl en de foto's. Wat je links of
 * rechts verandert, zie je meteen op het canvas. De volledige editors (sitemapboom, stijlgids)
 * blijven bereikbaar als "meer opties", maar zijn geen aparte stappen meer.
 *
 * AJAX:
 * - aisb_archer_design_state: pagina's, stijlen, stijlgids-samenvatting en fotovakken;
 * - aisb_archer_design_page: pagina toevoegen (met secties uit aisb_archer_sections), hernoemen of schrappen;
 * - aisb_archer_design_style: stijl, hoeken, merkkleur, logo;
 * - aisb_archer_design_photos: fotovakken van een pagina vullen (eigen foto's eerst, dan stock),
 *   eigen foto's toevoegen of weghalen.
 *
 * Fotovakken: guide.images is één lijst over alle pagina's heen, in de volgorde van
 * AISB_Style_Guide::media_pages() (pagina's van de sitemap, secties van de wireframe, beelden per sectie).
 */
class AISB_Archer_Design {

  public function init(): void {
    add_action('wp_ajax_aisb_archer_design_state', [$this, 'ajax_state']);
    add_action('wp_ajax_aisb_archer_design_page', [$this, 'ajax_page']);
    add_action('wp_ajax_aisb_archer_design_style', [$this, 'ajax_style']);
    add_action('wp_ajax_aisb_archer_design_photos', [$this, 'ajax_photos']);
  }

  /* ───────────────────────── Toestand ───────────────────────── */

  public function ajax_state(): void {
    $pid = $this->guard();
    wp_send_json_success(self::state($pid));
  }

  public static function state(int $pid): array {
    $guide = self::aligned_guide($pid);
    $styles = [];
    if (class_exists('AISB_Archer_Styles')) {
      $all = AISB_Archer_Styles::for_project($pid);
      foreach ((array) ($all['styles'] ?? []) as $s) {
        $styles[] = [
          'id' => $s['id'], 'name' => $s['name'], 'pitch' => $s['pitch'], 'fit' => !empty($s['fit']),
          'colours' => $s['colours'], 'headingFont' => $s['headingFont'], 'headingWeight' => $s['headingWeight'],
          'bodyFont' => $s['bodyFont'], 'radius' => $s['radius'], 'fontUrl' => $s['fontUrl'],
        ];
      }
    }
    return [
      'pages'  => self::pages($pid),
      'styles' => $styles,
      'themes' => self::themes($guide),
      'theme'  => self::theme_now($guide),
      'guide'  => self::guide_summary($guide),
      'photos' => self::photo_state($pid, $guide),
    ];
  }

  /**
   * Het variabelenblok (#archer-theme in de voorbeelden) van elke stijl, zoals het zou zijn als de klant
   * die stijl nu kiest: met de merkkleur die al gekozen is. De browser wisselt hiermee meteen van stijl,
   * zonder de voorbeelden opnieuw te laden.
   *
   * @return array<string,string> stijl-id => CSS
   */
  public static function themes(array $g): array {
    if (!class_exists('AISB_Archer_Styles')) return [];
    $brand = (string) ($g['brand'] ?? '');
    $out = [];
    foreach (AISB_Archer_Styles::library() as $s) {
      $g2 = array_merge($g, AISB_Archer_Styles::guide_patch($s));
      if ($brand !== '' && preg_match('/^#[0-9A-F]{6}$/i', $brand)) $g2 = self::with_brand($g2, $brand);
      $out[$s['id']] = AISB_Archer_Styles::theme_css($s, $g2);
    }
    return $out;
  }

  /** Het variabelenblok van de stijlgids zoals hij nu is (leeg zonder Archer-stijl). */
  public static function theme_now(array $g): string {
    $s = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::of($g) : null;
    return $s ? AISB_Archer_Styles::theme_css($s, $g) : '';
  }

  /** Pagina's uit de sitemap: slug, titel, ouder. */
  public static function pages(int $pid): array {
    [, $data] = self::sitemap($pid);
    $out = [];
    foreach ((array) ($data['sitemap'] ?? []) as $p) {
      if (!is_array($p) || empty($p['slug'])) continue;
      $out[] = [
        'slug' => sanitize_title((string) $p['slug']),
        'title' => (string) ($p['page_title'] ?? $p['slug']),
        'nav_label' => (string) ($p['nav_label'] ?? ''),
        'parent' => (string) ($p['parent_slug'] ?? ''),
        'page_type' => (string) ($p['page_type'] ?? 'Other'),
        'sections' => is_array($p['sections'] ?? null) ? count($p['sections']) : 0,
      ];
    }
    return $out;
  }

  private static function guide_summary(array $g): array {
    $brand = '';
    foreach ((array) ($g['colours'] ?? []) as $c) if (($c['name'] ?? '') === 'Primary') $brand = (string) ($c['hex'] ?? '');
    return [
      'style' => (string) ($g['style'] ?? ''),
      'radius' => (string) ($g['radius'] ?? ''),
      'brand' => $brand,
      'headingFont' => (string) ($g['headingFont'] ?? ''),
      'bodyFont' => (string) ($g['bodyFont'] ?? ''),
      'logoUrl' => (string) ($g['logoUrl'] ?? ''),
      'photoPref' => (string) ($g['archerPhotoPref'] ?? 'mix'),
    ];
  }

  /** Fotovakken per sectie (uuid => [start, aantal]), de foto's zelf en de eigen foto's. */
  private static function photo_state(int $pid, array $guide): array {
    $slots = self::slots($pid);
    $images = is_array($guide['images'] ?? null) ? array_values($guide['images']) : [];
    $own = [];
    foreach ((array) ($guide['uploadedImages'] ?? []) as $img) {
      if (!is_array($img)) continue;
      $own[] = ['id' => (int) ($img['attachment_id'] ?? 0), 'thumb' => (string) ($img['thumb'] ?? $img['full'] ?? ''), 'full' => (string) ($img['full'] ?? '')];
    }
    $filled = 0;
    foreach (array_slice($images, 0, $slots['total']) as $img) if (!self::empty_img($img)) $filled++;
    return [
      'total' => $slots['total'],
      'filled' => $filled,
      'pages' => $slots['pages'],
      'sections' => $slots['sections'],
      'images' => array_map(fn($i) => self::empty_img($i) ? null : ['full' => (string) ($i['full'] ?? ''), 'thumb' => (string) ($i['thumb'] ?? ''), 'own' => !empty($i['uploaded'])], array_slice($images, 0, $slots['total'])),
      'own' => $own,
      'pref' => (string) ($guide['archerPhotoPref'] ?? 'mix'),
    ];
  }

  /** @return array{total:int, pages:array, sections:array} */
  private static function slots(int $pid): array {
    $mp = class_exists('AISB_Style_Guide') ? (new AISB_Style_Guide())->media_pages($pid) : ['pages' => []];
    $base = 0;
    $pages = [];
    $sections = [];
    foreach ((array) ($mp['pages'] ?? []) as $p) {
      $start = $base;
      $names = [];
      foreach ((array) ($p['sections'] ?? []) as $i => $s) {
        $n = (int) ($s['media_count'] ?? 0);
        if (!empty($s['uuid'])) $sections[$s['uuid']] = ['page' => $p['slug'], 'index' => $i, 'start' => $base, 'count' => $n];
        if (!empty($s['type'])) $names[] = (string) $s['type'];
        $base += $n;
      }
      $pages[] = ['slug' => $p['slug'], 'title' => (string) ($p['title'] ?? $p['slug']), 'start' => $start, 'count' => $base - $start, 'sections' => implode(', ', array_slice(array_unique($names), 0, 10))];
    }
    return ['total' => $base, 'pages' => $pages, 'sections' => (object) $sections];
  }

  /* ───────────────────────── Pagina's ───────────────────────── */

  public function ajax_page(): void {
    $pid = $this->guard();
    [$sid, $data] = self::sitemap($pid);
    if (!$sid || !is_array($data['sitemap'] ?? null)) wp_send_json_error(['message' => 'Er is nog geen sitemap.'], 400);
    $op = sanitize_key((string) ($_POST['op'] ?? ''));
    $slug = sanitize_title(wp_unslash((string) ($_POST['slug'] ?? '')));
    $title = trim(sanitize_text_field(wp_unslash((string) ($_POST['title'] ?? ''))));
    $pages = &$data['sitemap'];
    $idx = null;
    foreach ($pages as $i => $p) if (is_array($p) && sanitize_title((string) ($p['slug'] ?? '')) === $slug) $idx = $i;

    if ($op === 'rename') {
      if ($idx === null || $title === '') wp_send_json_error(['message' => 'Pagina of naam ontbreekt.'], 400);
      $pages[$idx]['page_title'] = mb_substr($title, 0, 80);
      $pages[$idx]['nav_label'] = mb_substr($title, 0, 40);
      self::menu_walk($data, function (&$item) use ($slug, $title) { if (($item['slug'] ?? '') === $slug) $item['label'] = mb_substr($title, 0, 40); });
    } elseif ($op === 'remove') {
      if ($idx === null) wp_send_json_error(['message' => 'Pagina niet gevonden.'], 404);
      if ($slug === 'home') wp_send_json_error(['message' => 'De startpagina kan je niet schrappen.'], 400);
      // De pagina en haar onderliggende pagina's.
      $drop = [$slug => 1];
      do {
        $n = count($drop);
        foreach ($pages as $p) if (is_array($p) && isset($drop[(string) ($p['parent_slug'] ?? '')])) $drop[sanitize_title((string) $p['slug'])] = 1;
      } while (count($drop) > $n);
      $pages = array_values(array_filter($pages, fn($p) => !is_array($p) || !isset($drop[sanitize_title((string) ($p['slug'] ?? ''))])));
      if (isset($data['archer_menu']['items']) && is_array($data['archer_menu']['items'])) {
        $data['archer_menu']['items'] = self::menu_without($data['archer_menu']['items'], $drop);
      }
    } elseif ($op === 'add') {
      if ($title === '') wp_send_json_error(['message' => 'Geef de pagina een naam.'], 400);
      $parent = sanitize_title(wp_unslash((string) ($_POST['parent'] ?? 'home'))) ?: 'home';
      $new = sanitize_title($slug ?: $title);
      $taken = array_map(fn($p) => sanitize_title((string) ($p['slug'] ?? '')), $pages);
      $base = $new; $n = 2;
      while (in_array($new, $taken, true)) $new = $base . '-' . $n++;
      $sections = json_decode((string) wp_unslash($_POST['sections'] ?? '[]'), true);
      $clean = [];
      foreach ((array) $sections as $s) {
        if (!is_array($s) || empty($s['section_type'])) continue;
        $clean[] = [
          'section_name' => sanitize_text_field((string) ($s['section_name'] ?? '')),
          'section_type' => sanitize_text_field((string) $s['section_type']),
          'purpose' => sanitize_textarea_field((string) ($s['purpose'] ?? '')),
          'key_content' => array_values(array_map('sanitize_text_field', array_filter((array) ($s['key_content'] ?? []), 'is_string'))),
        ];
      }
      if (!$clean) {
        $clean = [
          ['section_name' => 'Navigatie', 'section_type' => 'Headers', 'purpose' => '', 'key_content' => []],
          ['section_name' => $title, 'section_type' => 'Hero Sections', 'purpose' => '', 'key_content' => []],
          ['section_name' => 'Inhoud', 'section_type' => 'Content Sections', 'purpose' => '', 'key_content' => []],
          ['section_name' => 'Contact opnemen', 'section_type' => 'CTA Sections', 'purpose' => '', 'key_content' => []],
          ['section_name' => 'Footer', 'section_type' => 'Footers', 'purpose' => '', 'key_content' => []],
        ];
      }
      if (class_exists('AISB_Archer_Sitemap_Rules')) $clean = AISB_Archer_Sitemap_Rules::order_sections($clean);
      $page = [
        'page_title' => mb_substr($title, 0, 80), 'nav_label' => mb_substr($title, 0, 40), 'slug' => $new,
        'page_type' => sanitize_text_field((string) ($_POST['page_type'] ?? 'Other')) ?: 'Other', 'priority' => 'Supporting',
        'parent_slug' => $parent, 'page_purpose' => sanitize_text_field((string) ($_POST['purpose'] ?? '')), 'sections' => $clean,
      ];
      // Voor de contactpagina invoegen (die staat liefst achteraan), anders achteraan.
      $at = count($pages);
      foreach ($pages as $i => $p) if (is_array($p) && sanitize_title((string) ($p['slug'] ?? '')) === 'contact') { $at = $i; break; }
      array_splice($pages, $at, 0, [$page]);
      if (isset($data['archer_menu']['items']) && is_array($data['archer_menu']['items'])) {
        $item = ['slug' => $new, 'label' => mb_substr($title, 0, 40), 'children' => []];
        if ($parent === 'home') {
          $data['archer_menu']['items'][] = $item;
        } else {
          self::menu_walk($data, function (&$it) use ($parent, $item) {
            if (($it['slug'] ?? '') === $parent) { $it['children'] = is_array($it['children'] ?? null) ? $it['children'] : []; unset($item['children']); $it['children'][] = $item; }
          });
        }
      }
      $slug = $new;
    } else {
      wp_send_json_error(['message' => 'Onbekende actie.'], 400);
    }
    unset($pages);

    update_post_meta($sid, 'aisb_sitemap_json', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    update_post_meta($pid, 'aisb_project_updated_at', gmdate('c'));
    wp_send_json_success(['slug' => $slug, 'pages' => self::pages($pid)]);
  }

  private static function menu_walk(array &$data, callable $fn): void {
    if (!isset($data['archer_menu']['items']) || !is_array($data['archer_menu']['items'])) return;
    foreach ($data['archer_menu']['items'] as &$it) {
      if (!is_array($it)) continue;
      $fn($it);
      if (!empty($it['children']) && is_array($it['children'])) foreach ($it['children'] as &$ch) if (is_array($ch)) $fn($ch);
      unset($ch);
    }
    unset($it);
  }

  private static function menu_without(array $items, array $drop): array {
    $out = [];
    foreach ($items as $it) {
      if (!is_array($it) || isset($drop[(string) ($it['slug'] ?? '')])) continue;
      if (!empty($it['children']) && is_array($it['children'])) $it['children'] = self::menu_without($it['children'], $drop);
      $out[] = $it;
    }
    return $out;
  }

  /* ───────────────────────── Stijl ───────────────────────── */

  public function ajax_style(): void {
    $pid = $this->guard();
    $g = self::guide($pid);
    if (isset($_POST['style']) && class_exists('AISB_Archer_Styles')) {
      $s = AISB_Archer_Styles::get(sanitize_key(wp_unslash((string) $_POST['style'])));
      if (!$s) wp_send_json_error(['message' => 'Onbekende stijl.'], 400);
      $keep_brand = !empty($_POST['keep_brand']) ? self::brand_of($g) : '';
      $g = array_merge($g, AISB_Archer_Styles::guide_patch($s));
      if ($keep_brand) $g = self::with_brand($g, $keep_brand);
    }
    if (isset($_POST['radius'])) {
      $r = sanitize_key(wp_unslash((string) $_POST['radius']));
      if (in_array($r, ['sharp', 'soft', 'round'], true)) $g['radius'] = $r;
    }
    if (isset($_POST['brand'])) {
      $hex = strtoupper(trim((string) wp_unslash($_POST['brand'])));
      if ($hex === '' || $hex === 'RESET') {
        $s = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::of($g) : null;
        if ($s) $g['colours'] = AISB_Archer_Styles::roles($s);
        unset($g['brand']);
      } elseif (preg_match('/^#[0-9A-F]{6}$/', $hex)) {
        $g = self::with_brand($g, $hex);
      }
    }
    if (isset($_POST['logo_id'])) {
      $id = (int) $_POST['logo_id'];
      if ($id === 0) {
        unset($g['logoUrl'], $g['logoAttachmentId']);
      } elseif (self::own_attachment($id)) {
        $g['logoUrl'] = set_url_scheme((string) wp_get_attachment_url($id));
        $g['logoAttachmentId'] = $id;
      }
    }
    if (isset($_POST['photo_pref'])) {
      $p = sanitize_key(wp_unslash((string) $_POST['photo_pref']));
      if (in_array($p, ['own', 'stock', 'mix'], true)) $g['archerPhotoPref'] = $p;
    }
    self::save_guide($pid, $g);
    wp_send_json_success(['guide' => self::guide_summary($g), 'theme' => self::theme_now($g), 'themes' => self::themes($g)]);
  }

  private static function brand_of(array $g): string {
    return (string) ($g['brand'] ?? '');
  }

  private static function with_brand(array $g, string $hex): array {
    if (!empty($g['colours']) && is_array($g['colours'])) {
      foreach ($g['colours'] as &$c) if (in_array($c['name'] ?? '', ['Primary', 'Accent'], true)) $c['hex'] = $hex;
      unset($c);
    }
    $g['brand'] = $hex;
    return $g;
  }

  /* ───────────────────────── Foto's ───────────────────────── */

  public function ajax_photos(): void {
    $pid = $this->guard();
    @set_time_limit(90);
    $op = sanitize_key((string) ($_POST['op'] ?? ''));
    $g = self::aligned_guide($pid);
    $own = is_array($g['uploadedImages'] ?? null) ? array_values($g['uploadedImages']) : [];

    if ($op === 'own_add') {
      $ids = array_map('intval', (array) json_decode((string) wp_unslash($_POST['ids'] ?? '[]'), true));
      $have = array_flip(array_map(fn($i) => (int) ($i['attachment_id'] ?? 0), $own));
      foreach ($ids as $id) {
        if (!$id || isset($have[$id]) || !self::own_attachment($id) || !wp_attachment_is_image($id)) continue;
        $own[] = self::own_item($id);
      }
      $g['uploadedImages'] = $own;
      self::save_guide($pid, $g);
    } elseif ($op === 'own_remove') {
      $id = (int) ($_POST['id'] ?? 0);
      $g['uploadedImages'] = array_values(array_filter($own, fn($i) => (int) ($i['attachment_id'] ?? 0) !== $id));
      // Ook uit de fotovakken halen.
      if (is_array($g['images'] ?? null)) {
        foreach ($g['images'] as &$img) if (is_array($img) && (int) ($img['attachment_id'] ?? 0) === $id) $img = self::empty_slot();
        unset($img);
      }
      self::save_guide($pid, $g);
    } elseif ($op === 'fill') {
      $slug = sanitize_title(wp_unslash((string) ($_POST['page_slug'] ?? '')));
      $replace = !empty($_POST['replace']);
      $res = self::fill_page($pid, $g, $slug, $replace);
      if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()], 502);
      $g = self::remember_slots($res, self::slots($pid));
      self::save_guide($pid, $g);
    } elseif ($op !== 'state') {
      wp_send_json_error(['message' => 'Onbekende actie.'], 400);
    }
    wp_send_json_success(['photos' => self::photo_state($pid, $g)]);
  }

  /**
   * Vult de fotovakken van één pagina: eigen foto's eerst (verdeeld over de pagina's), dan stockfoto's
   * als de voorkeur dat toelaat. Met $replace worden ook bestaande stockfoto's vervangen.
   */
  private static function fill_page(int $pid, array $g, string $slug, bool $replace) {
    $slots = self::slots($pid);
    $page = null;
    foreach ($slots['pages'] as $p) if ($p['slug'] === $slug) $page = $p;
    if (!$page) return new WP_Error('no_page', 'Pagina niet gevonden.');
    $images = is_array($g['images'] ?? null) ? array_values($g['images']) : [];
    while (count($images) < $slots['total']) $images[] = self::empty_slot();
    if (count($images) > $slots['total']) $images = array_slice($images, 0, $slots['total']);
    if (!$page['count']) { $g['images'] = $images; return $g; }

    $idx = [];
    for ($i = $page['start']; $i < $page['start'] + $page['count']; $i++) {
      $img = $images[$i];
      if (self::empty_img($img) || ($replace && self::is_stock($img))) $idx[] = $i;
    }
    if (!$idx) { $g['images'] = $images; return $g; }

    $pref = (string) ($g['archerPhotoPref'] ?? 'mix');
    $own = is_array($g['uploadedImages'] ?? null) ? array_values($g['uploadedImages']) : [];
    // Eigen foto's: nog niet gebruikte eerst, eerlijk verdeeld over de pagina's met fotovakken.
    if ($own && !$replace) {
      $used = [];
      foreach ($images as $img) if (is_array($img) && !empty($img['attachment_id'])) $used[(int) $img['attachment_id']] = 1;
      $free = array_values(array_filter($own, fn($o) => !isset($used[(int) ($o['attachment_id'] ?? 0)])));
      $pages_with = max(1, count(array_filter($slots['pages'], fn($p) => $p['count'] > 0)));
      $share = $pref === 'own' ? count($idx) : max(1, (int) ceil(count($own) / $pages_with));
      // Alleen eigen foto's: te weinig? Dan mogen ze terugkomen.
      if ($pref === 'own' && count($free) < count($idx)) $free = array_merge($free, $own);
      $take = array_slice($free, 0, min($share, count($idx)));
      foreach ($take as $o) {
        $i = array_shift($idx);
        $images[$i] = $o;
      }
    }
    if ($idx && $pref !== 'own' && class_exists('AISB_Archer_Stock')) {
      $exclude = [];
      foreach ($images as $img) if (!self::empty_img($img)) $exclude[] = (string) ($img['full'] ?? '');
      $r = AISB_Archer_Stock::for_page($pid, $page['slug'], $page['title'], $page['sections'], count($idx), array_slice($exclude, -150));
      if (is_wp_error($r)) {
        $g['images'] = $images;
        return $r;
      }
      $taken = array_flip(array_map([AISB_Archer_Stock::class, 'pkey'], $exclude));
      foreach ((array) ($r['images'] ?? []) as $pick) {
        if (!$idx) break;
        if (empty($pick['full']) || isset($taken[AISB_Archer_Stock::pkey($pick['full'])])) continue;
        $taken[AISB_Archer_Stock::pkey($pick['full'])] = 1;
        $images[array_shift($idx)] = $pick;
      }
    }
    $g['images'] = $images;
    return $g;
  }

  /**
   * Foto's volgen hun sectie. guide.images is één lijst in de volgorde van de fotovakken; komt er een
   * pagina of sectie bij (of gaat er een weg), dan schuift alles op. Daarom onthouden we per sectie (uuid)
   * welke foto's erbij horen (archerSlotMap) en de indeling waarvoor guide.images klopt (archerSlotSig).
   * - Indeling ongewijzigd: guide.images is de waarheid (ook wissels uit de fotostap); de kaart volgt.
   * - Indeling gewijzigd: guide.images wordt opnieuw opgebouwd uit de kaart; nieuwe vakken blijven leeg.
   */
  public static function aligned_guide(int $pid): array {
    $g = self::guide($pid);
    $slots = self::slots($pid);
    $sig = self::slot_sig($slots);
    $before = wp_json_encode([$g['images'] ?? null, $g['archerSlotMap'] ?? null, $g['archerSlotSig'] ?? null]);
    if (!empty($g['archerSlotSig']) && $g['archerSlotSig'] !== $sig && is_array($g['archerSlotMap'] ?? null)) {
      $map = $g['archerSlotMap'];
      $images = [];
      foreach ((array) $slots['sections'] as $uuid => $sec) {
        $list = is_array($map[$uuid] ?? null) ? array_values($map[$uuid]) : [];
        for ($i = 0; $i < $sec['count']; $i++) $images[] = (isset($list[$i]) && is_array($list[$i])) ? $list[$i] : self::empty_slot();
      }
      // Secties zonder uuid (oude wireframes): opvullen tot het totaal.
      while (count($images) < $slots['total']) $images[] = self::empty_slot();
      $g['images'] = array_slice($images, 0, $slots['total']);
    }
    $g = self::remember_slots($g, $slots);
    if (wp_json_encode([$g['images'] ?? null, $g['archerSlotMap'] ?? null, $g['archerSlotSig'] ?? null]) !== $before) self::save_guide($pid, $g);
    return $g;
  }

  /** Kaart en indeling bijwerken vanuit de huidige guide.images. */
  private static function remember_slots(array $g, array $slots): array {
    if (!$slots['total']) return $g;
    $images = is_array($g['images'] ?? null) ? array_values($g['images']) : [];
    $map = is_array($g['archerSlotMap'] ?? null) ? $g['archerSlotMap'] : [];
    foreach ((array) $slots['sections'] as $uuid => $sec) {
      $map[$uuid] = array_slice(array_pad(array_slice($images, $sec['start'], $sec['count']), $sec['count'], self::empty_slot()), 0, $sec['count']);
    }
    // Secties die niet meer bestaan, vergeten.
    $map = array_intersect_key($map, (array) $slots['sections']);
    $g['archerSlotMap'] = $map;
    $g['archerSlotSig'] = self::slot_sig($slots);
    return $g;
  }

  private static function slot_sig(array $slots): string {
    $parts = [];
    foreach ((array) $slots['sections'] as $uuid => $sec) $parts[] = $uuid . ':' . $sec['count'];
    return md5(implode('|', $parts) . '#' . $slots['total']);
  }

  private static function own_item(int $id): array {
    // Staging kent zichzelf als http: adressen in het schema van de aanvraag (anders blokkeert de browser ze).
    $url = set_url_scheme((string) wp_get_attachment_url($id));
    return [
      'thumb' => set_url_scheme((string) (wp_get_attachment_image_url($id, 'medium') ?: $url)),
      'full' => set_url_scheme((string) (wp_get_attachment_image_url($id, 'large') ?: $url)),
      'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
      'photographer' => 'Uploaded', 'link' => '', 'uploaded' => true, 'attachment_id' => $id,
    ];
  }

  private static function empty_slot(): array {
    return ['full' => '', 'thumb' => '', 'alt' => '', 'archerEmpty' => true];
  }

  private static function empty_img($img): bool {
    return !is_array($img) || !empty($img['archerEmpty']) || (empty($img['full']) && empty($img['thumb']));
  }

  private static function is_stock($img): bool {
    return !self::empty_img($img) && empty($img['uploaded']) && (!empty($img['source']) || preg_match('/unsplash|pexels/i', (string) ($img['full'] ?? '')));
  }

  /* ───────────────────────── Hulp ───────────────────────── */

  /** @return array{0:int,1:array} */
  private static function sitemap(int $pid): array {
    $sid = (int) get_post_meta($pid, 'aisb_latest_sitemap_id', true);
    if (!$sid) return [0, []];
    $data = json_decode((string) get_post_meta($sid, 'aisb_sitemap_json', true), true);
    return [$sid, is_array($data) ? $data : []];
  }

  private static function guide(int $pid): array {
    $g = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    if (!is_array($g) || ($g && array_keys($g) === range(0, count($g) - 1))) $g = [];
    return $g;
  }

  private static function save_guide(int $pid, array $g): void {
    update_post_meta($pid, 'aisb_style_guide', wp_slash(wp_json_encode($g, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    update_post_meta($pid, 'aisb_project_updated_at', gmdate('c'));
  }

  private static function own_attachment(int $id): bool {
    if (!$id || get_post_type($id) !== 'attachment') return false;
    if (current_user_can('manage_options')) return true;
    $owner = (int) get_post_meta($id, '_archer_intake_owner', true);
    return $owner === get_current_user_id() || (int) get_post_field('post_author', $id) === get_current_user_id();
  }

  private function guard(): int {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
    $pid = (int) ($_POST['project_id'] ?? 0);
    $p = $pid ? get_post($pid) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden.'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang.'], 403);
    return $pid;
  }
}
