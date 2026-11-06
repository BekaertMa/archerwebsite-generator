<?php
if (!defined('ABSPATH')) exit;

/**
 * Megamenu in de header.
 *
 * Heeft een item van het hoofdmenu minstens MIN_CHILDREN subpagina's, dan krijgt dat item bij het publiceren
 * een Bricks-megamenu in plaats van een gewoon uitklapmenu:
 * - een Brixies-megamenu uit de bibliotheek (template_tag "megamenu"), ingevuld met de subpagina's van het item;
 * - op de site maakt Archer Content Types (POST /archer-ct/v1/megamenu, vanaf 1.2.0) het sjabloon aan en
 *   koppelt het aan het menu-item (_bricks_mega_menu_template_id); de inhoud gaat mee met de Bricks-injectie;
 * - het nav-menu in de header krijgt megaMenu aan, met de breedte van .archer-header.
 * Zit het hoofdmenu in een offcanvas-paneel (een header met alleen een hamburger), dan komt er geen megamenu:
 * er is geen menubalk om het onder te hangen. Een header met zowel een balk als een offcanvas krijgt het
 * alleen in de balk.
 * Opnieuw publiceren met een kleiner menu ruimt het megamenu weer op (de route wist eerst de oude).
 * Zonder de route (oudere Archer Content Types) blijft het gewone uitklapmenu: niets breekt.
 */
class AISB_Archer_Megamenu {

  const MIN_CHILDREN = 6;

  /** Hoofdmenu-items met minstens MIN_CHILDREN gepubliceerde subpagina's: [['title','url','children'=>[['title','url']]]]. */
  public static function big_items(int $pid, array $published): array {
    $ctx = class_exists('AISB_Archer_Chrome') ? AISB_Archer_Chrome::context($pid) : null;
    if (!$ctx) return [];
    $by = [];
    foreach ($published as $p) $by[(string) ($p['slug'] ?? '')] = (string) ($p['url'] ?? '');
    $out = [];
    foreach ((array) ($ctx['menu']['items'] ?? []) as $it) {
      $kids = [];
      foreach ((array) ($it['children'] ?? []) as $c) {
        $slug = (string) ($c['slug'] ?? '');
        if ($slug !== '' && !empty($by[$slug])) $kids[] = ['title' => (string) $c['label'], 'url' => $by[$slug]];
      }
      if (count($kids) < self::MIN_CHILDREN) continue;
      $slug = (string) ($it['slug'] ?? '');
      $out[] = ['title' => (string) $it['label'], 'url' => $slug !== '' ? (string) ($by[$slug] ?? '') : '', 'children' => $kids];
    }
    return $out;
  }

  /** Bibliotheeksjabloon: zonder afbeeldingen en met genoeg links; anders het zonder afbeeldingen met de meeste links. */
  private static function pick(int $need): ?array {
    $best = null;
    $posts = get_posts(['post_type' => 'bricks_template', 'post_status' => 'publish', 'posts_per_page' => -1,
      'tax_query' => [['taxonomy' => 'template_tag', 'field' => 'slug', 'terms' => 'megamenu']], 'orderby' => 'title', 'order' => 'ASC']);
    foreach ($posts as $p) {
      $nodes = get_post_meta($p->ID, '_bricks_page_content_2', true);
      if (!is_array($nodes) || !$nodes) continue;
      $names = array_column($nodes, 'name');
      if (array_intersect($names, ['image', 'nav-menu', 'tabs-nested', 'code'])) continue;
      $units = count(self::units($nodes));
      $score = ($units >= $need ? 1000 - $units : $units);
      if (!$best || $score > $best['score']) $best = ['nodes' => $nodes, 'score' => $score];
    }
    return $best ? $best['nodes'] : null;
  }

  /** Zit deze node (of een voorouder) in een offcanvas-paneel? */
  private static function in_offcanvas(array $nodes, array $ix, string $id): bool {
    $seen = 0;
    while ($id !== '' && isset($ix[$id]) && $seen < 50) {
      $n = $nodes[$ix[$id]];
      if (($n['name'] ?? '') === 'offcanvas') return true;
      $id = (string) ($n['parent'] ?? '');
      $seen++;
    }
    return false;
  }

  /**
   * Heeft de header een hoofdmenu in een echte menubalk (dus niet in een offcanvas-paneel)?
   * Alleen 'main' en 'main_cta' tellen: die rollen zet AISB_Archer_Chrome uitsluitend op een menu in de header.
   * Een voetmenu zonder bijpassende groep houdt een lege rol en mag hier niet voor doorgaan.
   */
  public static function has_desktop_nav(array $nodes): bool {
    $ix = self::ix($nodes);
    foreach ($nodes as $n) {
      if (($n['name'] ?? '') !== 'nav-menu') continue;
      if (!in_array((string) ($n['settings']['archerMenu'] ?? ''), ['main', 'main_cta'], true)) continue;
      if (!self::in_offcanvas($nodes, $ix, (string) ($n['parent'] ?? ''))) return true;
    }
    return false;
  }

  /**
   * Zet de enable()-lus hieronder straks überhaupt ergens megaMenu aan? Dezelfde regio's, dezelfde uitzondering.
   * De header staat bij Archer als eerste sectie in de pagina-inhoud; Bricks' eigen header-zone kan ook.
   */
  private static function injections_have_desktop_nav(array $injections): bool {
    foreach ($injections as $inj) {
      if (($inj['payload']['templateType'] ?? '') === 'section') continue;
      foreach (['content', 'header'] as $region) {
        $nodes = $inj['payload'][$region] ?? null;
        if (is_array($nodes) && $nodes && self::has_desktop_nav($nodes)) return true;
      }
    }
    return false;
  }

  private static function ix(array $nodes): array {
    $ix = [];
    foreach ($nodes as $k => $n) $ix[(string) $n['id']] = $k;
    return $ix;
  }

  /** Linkblokken in documentvolgorde (een element met een link dat geen knop is). */
  private static function units(array $nodes): array {
    $ix = self::ix($nodes);
    $out = [];
    $walk = function (string $id) use (&$walk, &$out, $nodes, $ix) {
      if (!isset($ix[$id])) return;
      $n = $nodes[$ix[$id]];
      if (!empty($n['settings']['link']) && !in_array($n['name'], ['button', 'text-link'], true)) { $out[] = $id; return; }
      foreach ((array) ($n['children'] ?? []) as $c) $walk((string) $c);
    };
    foreach ($nodes as $n) if (empty($n['parent'])) $walk((string) $n['id']);
    return $out;
  }

  private static function descendants(array $nodes, string $id): array {
    $ix = self::ix($nodes);
    $out = [];
    $stack = [$id];
    while ($stack) {
      $cur = array_pop($stack);
      if (!isset($ix[$cur])) continue;
      $out[] = $cur;
      foreach ((array) ($nodes[$ix[$cur]]['children'] ?? []) as $c) $stack[] = (string) $c;
    }
    return $out;
  }

  private static function remove(array $nodes, array $ids): array {
    $gone = [];
    foreach ($ids as $id) foreach (self::descendants($nodes, (string) $id) as $d) $gone[$d] = true;
    $keep = [];
    foreach ($nodes as $n) {
      if (isset($gone[(string) $n['id']])) continue;
      if (!empty($n['children'])) $n['children'] = array_values(array_filter($n['children'], fn($c) => !isset($gone[(string) $c])));
      $keep[] = $n;
    }
    return $keep;
  }

  private static function is_text(array $n): bool {
    return in_array($n['name'], ['heading', 'text-basic', 'text', 'text-link'], true);
  }

  /** Het sjabloon ingevuld voor één menu-item, of null. */
  public static function fill(array $item): ?array {
    $kids = $item['children'];
    $nodes = self::pick(count($kids));
    if (!$nodes) return null;
    $units = self::units($nodes);
    if (!$units) return null;
    $kids = array_slice($kids, 0, count($units));
    $ix = self::ix($nodes);
    $in_unit = [];
    $drop = [];
    foreach ($units as $u => $uid) {
      $desc = self::descendants($nodes, $uid);
      foreach ($desc as $d) $in_unit[$d] = true;
      if (!isset($kids[$u])) { $drop[] = $uid; continue; }
      $k = $ix[$uid];
      $nodes[$k]['settings']['link'] = ['type' => 'external', 'url' => $kids[$u]['url']];
      if (self::is_text($nodes[$k])) { $nodes[$k]['settings']['text'] = esc_html($kids[$u]['title']); continue; }
      $titled = false;
      foreach ($desc as $d) {
        if ($d === $uid) continue;
        $dk = $ix[$d];
        if (!self::is_text($nodes[$dk])) continue;
        if (!$titled) { $nodes[$dk]['settings']['text'] = esc_html($kids[$u]['title']); $titled = true; }
        else $drop[] = $d;
      }
    }
    // Buiten de links: kopjes, voorbeeldtekst en scheidingslijnen weg; de eerste knop wordt "Alles over ...".
    $cta = false;
    foreach ($nodes as $n) {
      $id = (string) $n['id'];
      if (isset($in_unit[$id]) || empty($n['parent'])) continue;
      if (in_array($n['name'], ['button', 'text-link'], true)) {
        if (!$cta && $item['url'] !== '') {
          $nodes[$ix[$id]]['settings']['text'] = esc_html(sprintf('Alles over %s', $item['title']));
          $nodes[$ix[$id]]['settings']['link'] = ['type' => 'external', 'url' => $item['url']];
          $cta = true;
        } else $drop[] = $id;
      } elseif (self::is_text($n) || in_array($n['name'], ['divider', 'image', 'icon'], true)) {
        $drop[] = $id;
      }
    }
    $nodes = self::remove($nodes, $drop);
    // Lege blokken opruimen (van binnen naar buiten).
    for ($round = 0; $round < 10; $round++) {
      $empty = [];
      foreach ($nodes as $n) {
        if (in_array($n['name'], ['block', 'div', 'container'], true) && empty($n['children']) && empty($n['settings']['link'])) $empty[] = (string) $n['id'];
      }
      if (!$empty) break;
      $nodes = self::remove($nodes, $empty);
    }
    if (class_exists('AISB_Archer_Site')) {
      try {
        $nodes = AISB_Archer_Site::icons($nodes);
        $nodes = AISB_Archer_Site::iconoir($nodes);
      } catch (Throwable $e) { /* iconen zijn een extra */ }
    }
    return ['nodes' => array_values($nodes), 'classes' => self::classes($nodes)];
  }

  /** De globale klassen die de nodes gebruiken, uit de bibliotheek. */
  private static function classes(array $nodes): array {
    $want = [];
    foreach ($nodes as $n) foreach ((array) ($n['settings']['_cssGlobalClasses'] ?? []) as $c) $want[(string) $c] = true;
    if (!$want) return [];
    $out = [];
    foreach ((array) get_option('bricks_global_classes', []) as $gc) {
      if (is_array($gc) && isset($want[(string) ($gc['id'] ?? '')])) $out[] = $gc;
    }
    return $out;
  }

  /** Megamenu aan op de nav-menu's van het hoofdmenu in de header. */
  public static function enable(array $nodes): array {
    $ix = self::ix($nodes);
    foreach ($nodes as $i => $n) {
      if (($n['name'] ?? '') !== 'nav-menu') continue;
      $role = (string) ($n['settings']['archerMenu'] ?? '');
      if (!in_array($role, ['', 'main', 'main_cta'], true)) continue;
      // Een menu in een offcanvas-paneel krijgt geen megamenu: daar is geen menubalk om het onder te hangen.
      if (self::in_offcanvas($nodes, $ix, (string) ($n['parent'] ?? ''))) continue;
      $nodes[$i]['settings']['megaMenu'] = true;
      if (empty($nodes[$i]['settings']['megaMenuSelector'])) $nodes[$i]['settings']['megaMenuSelector'] = '.archer-header'; // de header is een sectie in de pagina-inhoud, niet #brx-header
    }
    return $nodes;
  }

  /**
   * Bricks laat het gewone uitklapmenu staan naast het megamenu: walker_nav_menu_start_el voegt alleen
   * .brx-has-megamenu en .menu-item-has-children toe, en wp_nav_menu zet de echte subpagina's er nog eens
   * als <ul class="sub-menu"> naast. Allebei open is een smal wit blokje over het megamenu heen.
   * Het menu-item houdt zijn subpagina's (het mobiele menu heeft ze nodig), dus we verbergen alleen
   * het uitklapmenu daar waar Bricks ook echt een megamenu tekent.
   */
  public static function css(): string {
    return 'html body .brxe-nav-menu .bricks-nav-menu > li.brx-has-megamenu > .sub-menu{display:none !important;}';
  }

  private static function path(string $u): string {
    return untrailingslashit((string) wp_parse_url($u, PHP_URL_PATH));
  }

  /**
   * Na het aanmaken van de menu's, vóór de Bricks-injectie.
   * @return array ['made' => int, 'classes' => array] (de klassen moeten mee met de injectie)
   */
  public static function apply(int $pid, array $published, array $menu_ids, array &$injections, string $site_url, array $cookies, string $nonce, callable $request): array {
    $big = self::big_items($pid, $published);
    // Zit het hoofdmenu in een offcanvas-paneel (een header met alleen een hamburger), dan is er geen
    // menubalk om een megamenu onder te hangen. De route wordt wel met een lege lijst aangeroepen,
    // zodat een megamenu van een vorige publicatie opgeruimd wordt.
    if ($big && !self::injections_have_desktop_nav($injections)) {
      error_log('[AISB] Archer: geen megamenu, het hoofdmenu zit niet in een menubalk.');
      $big = [];
    }
    $filled = [];
    $classes = [];
    $made = 0;
    foreach (['main', 'main_cta'] as $role) {
      $mid = (int) ($menu_ids[$role] ?? 0);
      if (!$mid) continue;
      $body = ['menu' => $mid, 'items' => array_map(fn($b) => ['url' => $b['url'], 'title' => $b['title']], $big)];
      $r = $request(untrailingslashit($site_url) . '/wp-json/archer-ct/v1/megamenu', ['method' => 'POST', 'timeout' => 60, 'cookies' => $cookies,
        'headers' => ['X-WP-Nonce' => $nonce, 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body)]);
      $code = is_wp_error($r) ? 0 : (int) ($r['code'] ?? 0);
      if ($code !== 200) {
        if ($big) error_log('[AISB] Archer: megamenu niet aangemaakt (HTTP ' . $code . '); staat Archer Content Types 1.2.0 op de site?');
        continue;
      }
      foreach ((array) json_decode((string) ($r['body'] ?? ''), true) as $row) {
        $tid = (int) ($row['template'] ?? 0);
        if (!$tid) continue;
        $b = null;
        foreach ($big as $x) if (self::path($x['url']) === self::path((string) ($row['url'] ?? ''))) { $b = $x; break; }
        if (!$b) continue;
        $key = self::path($b['url']);
        if (!array_key_exists($key, $filled)) $filled[$key] = self::fill($b);
        if (!$filled[$key]) continue;
        foreach ($filled[$key]['classes'] as $gc) $classes[(string) $gc['id']] = $gc;
        $injections[] = ['post_id' => $tid, 'slug' => 'megamenu-' . $role . '-' . sanitize_title($b['title']), 'payload' => ['content' => $filled[$key]['nodes'], 'templateType' => 'section']];
        $made++;
      }
    }
    if ($made) {
      foreach ($injections as &$inj) {
        // De header staat bij Archer als eerste sectie in de pagina-inhoud; Bricks' eigen header-zone kan ook.
        foreach (['content', 'header'] as $region) {
          if (!empty($inj['payload'][$region]) && is_array($inj['payload'][$region]) && ($inj['payload']['templateType'] ?? '') !== 'section') $inj['payload'][$region] = self::enable($inj['payload'][$region]);
        }
      }
      unset($inj);
    }
    return ['made' => $made, 'classes' => array_values($classes)];
  }
}