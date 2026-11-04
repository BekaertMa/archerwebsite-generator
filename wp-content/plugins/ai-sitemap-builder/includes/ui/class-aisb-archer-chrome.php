<?php
if (!defined('ABSPATH')) exit;

/**
 * Header, footer en menu in het teken van de website die gemaakt wordt.
 *
 * - Menu: data.archer_menu in de sitemap ({source, cta, items: [{slug, label, children: [...]}]}),
 *   zelf opgebouwd in stap 1 of voorgesteld (AI of vaste regels). Zonder menu geldt het voorstel.
 * - Voorbeelden (stap 2 en 3): de header en footer krijgen de naam van de website, het menu
 *   (met submenu's), de knop naar de contactpagina, de footergroepen en het juiste jaartal.
 * - Publiceren: dezelfde aanpassing op de export, en per rol een eigen menu op de nieuwe site.
 */
class AISB_Archer_Chrome {

  const KEYS = ['_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2'];
  const MAX_TOP = 6;

  private static $menu_ids = [];
  private static $current_menu = 0;

  public function init(): void {
    add_action('template_redirect', [$this, 'preview'], 5);
    // Header en footer van elke pagina volgen die van Home.
    foreach (['aisb_get_wireframe_page', 'aisb_compile_wireframe_page', 'aisb_get_wireframe_sections'] as $a) {
      add_action('wp_ajax_' . $a, [$this, 'sync_before_read'], 1);
    }
    foreach (['aisb_generate_wireframe_page', 'aisb_update_wireframe_page', 'aisb_shuffle_section_layout', 'aisb_replace_section_type'] as $a) {
      add_action('wp_ajax_' . $a, [$this, 'sync_after_write'], 1);
    }
  }

  /* ───────────────────────── Header en footer zoals Home ───────────────────────── */

  /** Project uit het verzoek, als de gebruiker er recht op heeft en de nonce klopt; anders 0. */
  private static function request_project(): int {
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce) return 0;
    $ok = false;
    foreach ([AISB_Plugin::NONCE_ACTION, 'aisb_wf_nonce', 'aisb_sg_nonce'] as $action) if (wp_verify_nonce($nonce, $action)) $ok = true;
    if (!$ok) return 0;
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $pid ? get_post($pid) : null;
    if (!$p || $p->post_type !== 'aisb_project') return 0;
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) return 0;
    return $pid;
  }

  private static function request_sitemap(int $pid): int {
    $sid = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id'] : 0;
    return $sid ?: (int) get_post_meta($pid, 'aisb_latest_sitemap_id', true);
  }

  public function sync_before_read(): void {
    $pid = self::request_project();
    if (!$pid) return;
    $slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    // Eén pagina opvragen: alleen die pagina rechtzetten (de wireframes laden alle pagina's tegelijk).
    self::sync_chrome($pid, self::request_sitemap($pid), 'home', $slug !== '' ? [$slug] : null);
  }

  /** Na het bewaren door de plugin (wp_send_json stopt het verzoek, de shutdown-hook loopt nog). */
  public function sync_after_write(): void {
    $pid = self::request_project();
    if (!$pid) return;
    $sid = self::request_sitemap($pid);
    $slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
    if (!$sid || $slug === '') return;
    add_action('shutdown', function () use ($pid, $sid, $slug, $uuid, $action) {
      $source = 'home';
      $types = ['header', 'footer'];
      // Een ander voorbeeld voor de header of footer, op eender welke pagina: dat wordt de header of footer van alle pagina's.
      if ($uuid !== '' && in_array($action, ['aisb_shuffle_section_layout', 'aisb_replace_section_type'], true)) {
        $model = self::wireframe_rows($pid, $sid)[$slug]['model'] ?? [];
        foreach ((array) ($model['sections'] ?? []) as $sec) {
          $t = is_array($sec) ? strtolower((string) ($sec['type'] ?? '')) : '';
          if (($sec['uuid'] ?? '') === $uuid && in_array($t, ['header', 'footer'], true)) {
            $source = $slug;
            $types = [$t]; // alleen wat veranderde; het andere blijft dat van Home
          }
        }
      }
      if ($source === 'home' && $slug !== 'home') {
        self::sync_chrome($pid, $sid, 'home', [$slug]);
      } else {
        self::sync_chrome($pid, $sid, $source, null, $types);
      }
    });
  }

  /** @return array<string,array{id:int,json:string,model:array}> */
  private static function wireframe_rows(int $pid, int $sid): array {
    global $wpdb;
    $table = $wpdb->prefix . 'aisb_wireframes';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, page_slug, model_json FROM {$table} WHERE project_id = %d AND sitemap_version_id = %d", $pid, $sid), ARRAY_A);
    $out = [];
    foreach ((array) $rows as $r) {
      $m = json_decode((string) $r['model_json'], true);
      $out[(string) $r['page_slug']] = ['id' => (int) $r['id'], 'json' => (string) $r['model_json'], 'model' => is_array($m) ? $m : []];
    }
    return $out;
  }

  private static function chrome_sections(array $model): array {
    $found = [];
    foreach ((array) ($model['sections'] ?? []) as $sec) {
      if (!is_array($sec)) continue;
      $type = strtolower((string) ($sec['type'] ?? ''));
      if ($type === 'header' && !isset($found['header'])) $found['header'] = $sec;
      if ($type === 'footer') $found['footer'] = $sec; // de laatste footer
    }
    return $found;
  }

  /**
   * Zet de header en footer van de andere pagina's gelijk aan die van $source (standaard Home).
   * Elke pagina houdt haar eigen uuid. Schrijft alleen als de rij intussen niet veranderde,
   * zodat een generatie die tegelijk loopt nooit overschreven wordt. Geeft het aantal aangepaste pagina's.
   */
  public static function sync_chrome(int $pid, int $sid, string $source = 'home', ?array $only = null, array $types = ['header', 'footer']): int {
    global $wpdb;
    if (!$pid || !$sid) return 0;
    $rows = self::wireframe_rows($pid, $sid);
    if (!isset($rows[$source])) return 0;
    $src = self::chrome_sections($rows[$source]['model']);
    if (!$src) return 0;
    $strip = function (array $sec): string {
      unset($sec['uuid']);
      return (string) wp_json_encode($sec);
    };
    $table = $wpdb->prefix . 'aisb_wireframes';
    $changed = 0;
    foreach ($rows as $slug => $row) {
      if ($slug === $source || ($only !== null && !in_array($slug, $only, true))) continue;
      $model = $row['model'];
      if (empty($model['sections']) || !is_array($model['sections'])) continue;
      $dirty = false;
      $last_footer = null;
      foreach ($model['sections'] as $i => $sec) {
        if (is_array($sec) && strtolower((string) ($sec['type'] ?? '')) === 'footer') $last_footer = $i;
      }
      $first_header = null;
      foreach ($model['sections'] as $i => $sec) {
        if (is_array($sec) && strtolower((string) ($sec['type'] ?? '')) === 'header') { $first_header = $i; break; }
      }
      foreach (['header' => $first_header, 'footer' => $last_footer] as $type => $i) {
        if ($i === null || empty($src[$type]) || !in_array($type, $types, true)) continue;
        $cur = $model['sections'][$i];
        if ($strip($cur) === $strip($src[$type])) continue;
        $model['sections'][$i] = array_merge($src[$type], ['uuid' => $cur['uuid'] ?? wp_generate_uuid4()]);
        $dirty = true;
      }
      if (!$dirty) continue;
      $done = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET model_json = %s, compiled_bricks_json = NULL, updated_at = %s WHERE id = %d AND model_json = %s",
        wp_json_encode($model, JSON_UNESCAPED_SLASHES), current_time('mysql'), $row['id'], $row['json']
      ));
      if ($done) $changed++;
    }
    return $changed;
  }

  /* ───────────────────────── Gegevens ───────────────────────── */

  /** Sitemapdata: de opgegeven versie als die bij het project hoort, anders de laatste. */
  public static function sitemap(int $project_id, int $sitemap_id = 0): array {
    if (!$sitemap_id || (int) get_post_meta($sitemap_id, 'aisb_project_id', true) !== $project_id) {
      $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    }
    $raw = $sitemap_id ? get_post_meta($sitemap_id, 'aisb_sitemap_json', true) : '';
    $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
    return is_array($data) ? $data : [];
  }

  /** Pagina's per slug, met ouder ('' = hoofdniveau). */
  public static function pages(array $data): array {
    $out = [];
    foreach ((array) ($data['sitemap'] ?? $data['pages'] ?? []) as $p) {
      if (!is_array($p)) continue;
      $slug = sanitize_title((string) ($p['slug'] ?? ''));
      if ($slug === '' || isset($out[$slug])) continue;
      $par = sanitize_title((string) ($p['parent_slug'] ?? ''));
      if ($par === 'home' || $par === $slug) $par = '';
      $title = trim(wp_strip_all_tags((string) ($p['page_title'] ?? '')));
      $out[$slug] = [
        'slug' => $slug,
        'title' => $title !== '' ? $title : ucwords(str_replace('-', ' ', $slug)),
        'label' => trim(wp_strip_all_tags((string) ($p['nav_label'] ?? ''))),
        'parent' => $slug === 'home' ? '' : $par,
        'type' => (string) ($p['page_type'] ?? ''),
        'detail' => ($p['archer_added'] ?? '') === 'detail',
      ];
    }
    foreach ($out as &$p) if ($p['parent'] !== '' && !isset($out[$p['parent']])) $p['parent'] = '';
    unset($p);
    return $out;
  }

  public static function label(array $p): string {
    return $p['label'] !== '' ? $p['label'] : $p['title'];
  }

  public static function is_legal(array $p): bool {
    return strcasecmp($p['type'], 'legal') === 0
      || (bool) preg_match('/privacy|cookie|voorwaarden|terms|disclaimer|juridisch|gdpr|mentions-legales|impressum/i', $p['slug']);
  }

  public static function is_contact(array $p): bool {
    return strcasecmp($p['type'], 'contact') === 0 || (bool) preg_match('/(^|-)(contact|kontakt)(-|$)/i', $p['slug']);
  }

  /** Mag in het menu: geen home (het logo), geen juridische pagina's, geen sjabloon per item. */
  public static function menu_page(array $p): bool {
    return $p['slug'] !== 'home' && !$p['detail'] && !self::is_legal($p);
  }

  /** Vast voorstel (zonder AI): hoofdpagina's met hun subpagina's, contact als knop. */
  public static function proposal(array $pages): array {
    $cta = '';
    foreach ($pages as $p) if ($p['parent'] === '' && self::is_contact($p)) { $cta = $p['slug']; break; }
    $items = [];
    foreach ($pages as $p) {
      if ($p['parent'] !== '' || !self::menu_page($p) || $p['slug'] === $cta) continue;
      $kids = [];
      foreach ($pages as $c) if ($c['parent'] === $p['slug'] && self::menu_page($c)) $kids[] = ['slug' => $c['slug'], 'label' => ''];
      $items[] = ['slug' => $p['slug'], 'label' => '', 'children' => array_slice($kids, 0, 8)];
    }
    return ['source' => 'auto', 'cta' => $cta, 'items' => array_slice($items, 0, self::MAX_TOP)];
  }

  /** Menu opgeschoond: alleen bestaande pagina's, labels ingevuld, groepen zonder pagina toegelaten. */
  public static function menu(array $data, ?array $pages = null): array {
    if ($pages === null) $pages = self::pages($data);
    $m = isset($data['archer_menu']) && is_array($data['archer_menu']) && !empty($data['archer_menu']['items']) ? $data['archer_menu'] : self::proposal($pages);
    $seen = [];
    $row = function ($it, bool $top) use (&$row, $pages, &$seen) {
      if (!is_array($it)) return null;
      $slug = sanitize_title((string) ($it['slug'] ?? ''));
      $label = trim(wp_strip_all_tags((string) ($it['label'] ?? '')));
      if ($slug !== '') {
        if (!isset($pages[$slug]) || isset($seen[$slug])) return null;
        $seen[$slug] = 1;
        if ($label === '') $label = self::label($pages[$slug]);
      } elseif ($label === '' || !$top) {
        return null; // een groep zonder pagina kan alleen bovenaan staan
      }
      $out = ['slug' => $slug, 'label' => $label];
      if ($top) {
        $out['children'] = [];
        foreach ((array) ($it['children'] ?? []) as $c) {
          $r = $row($c, false);
          if ($r) $out['children'][] = $r;
        }
        if ($slug === '' && !$out['children']) return null;
      }
      return $out;
    };
    $items = [];
    foreach ((array) ($m['items'] ?? []) as $it) {
      $r = $row($it, true);
      if ($r) $items[] = $r;
    }
    $cta = sanitize_title((string) ($m['cta'] ?? ''));
    if ($cta !== '' && !isset($pages[$cta])) $cta = '';
    return [
      'items' => $items,
      'cta' => $cta,
      'cta_label' => $cta !== '' ? self::label($pages[$cta]) : '',
      'source' => (string) ($m['source'] ?? 'auto'),
    ];
  }

  /** Footer: navigatie, een groep per menu-item met subpagina's, juridische pagina's. */
  public static function footer(array $menu, array $pages, string $lang = 'Dutch'): array {
    $nav = [];
    foreach ($menu['items'] as $it) {
      if ($it['slug'] !== '') $nav[] = ['slug' => $it['slug'], 'label' => $it['label']];
      elseif (!empty($it['children'][0])) $nav[] = $it['children'][0];
    }
    if ($menu['cta'] !== '' && !in_array($menu['cta'], array_column($nav, 'slug'), true)) {
      $nav[] = ['slug' => $menu['cta'], 'label' => $menu['cta_label']];
    }
    $title = ['Dutch' => 'Navigatie', 'French' => 'Navigation', 'German' => 'Navigation', 'English' => 'Navigation'][$lang] ?? 'Navigatie';
    $groups = [['title' => $title, 'items' => $nav]];
    foreach ($menu['items'] as $it) {
      if (count($it['children']) >= 2) $groups[] = ['title' => $it['label'], 'items' => $it['children']];
    }
    $legal = [];
    foreach ($pages as $p) if (self::is_legal($p)) $legal[] = ['slug' => $p['slug'], 'label' => self::label($p)];
    return ['groups' => $groups, 'legal' => $legal];
  }

  /** Alles wat de header en footer nodig hebben. */
  public static function context(int $project_id, int $sitemap_id = 0): ?array {
    $data = self::sitemap($project_id, $sitemap_id);
    $pages = self::pages($data);
    if (!$pages) return null;
    $menu = self::menu($data, $pages);
    $name = trim(wp_strip_all_tags((string) ($data['website_name'] ?? '')));
    if ($name === '') $name = (string) get_the_title($project_id);
    $guide = json_decode((string) get_post_meta($project_id, 'aisb_style_guide', true), true);
    $guide = is_array($guide) ? $guide : [];
    $lang = (string) (get_post_meta($project_id, 'aisb_languages', true) ?: 'Dutch');
    if (is_array($data['languages'] ?? null) && !empty($data['languages'][0])) $lang = (string) $data['languages'][0];
    return [
      'project_id' => $project_id,
      'pages' => $pages,
      'menu' => $menu,
      'footer' => self::footer($menu, $pages, $lang),
      'name' => $name,
      'year' => wp_date('Y'),
      'logo' => trim((string) ($guide['logoUrl'] ?? '')),
      'guide' => $guide,
    ];
  }

  /**
   * Menu-items per rol (voor de voorbeelden en het publiceren).
   * @param callable $url slug => url
   * @return array role => list of ['title', 'url', 'slug', 'parent' => index|-1]
   */
  public static function menu_lists(array $ctx, callable $url): array {
    $main = [];
    foreach ($ctx['menu']['items'] as $it) {
      $main[] = ['title' => $it['label'], 'slug' => $it['slug'], 'url' => $it['slug'] !== '' ? $url($it['slug']) : '#', 'parent' => -1];
      $pi = count($main) - 1;
      foreach ($it['children'] as $c) $main[] = ['title' => $c['label'], 'slug' => $c['slug'], 'url' => $url($c['slug']), 'parent' => $pi];
    }
    $lists = [];
    foreach ($ctx['footer']['groups'] as $g => $group) {
      $lists['footer' . $g] = array_map(fn($x) => ['title' => $x['label'], 'slug' => $x['slug'], 'url' => $url($x['slug']), 'parent' => -1], $group['items']);
    }
    if ($ctx['footer']['legal']) {
      $lists['legal'] = array_map(fn($x) => ['title' => $x['label'], 'slug' => $x['slug'], 'url' => $url($x['slug']), 'parent' => -1], $ctx['footer']['legal']);
    }
    $cta = $ctx['menu']['cta'];
    $lists['main_cta'] = $cta !== '' ? array_merge($main, [['title' => $ctx['menu']['cta_label'], 'slug' => $cta, 'url' => $url($cta), 'parent' => -1]]) : $main;
    $lists['main'] = $main; // als laatste: het hoofdmenu krijgt de themalocaties
    return array_filter($lists);
  }

  /* ───────────────────────── Bricks-elementen ───────────────────────── */

  /** Header of footer? Volgt uit de metasleutel, het sjabloontype of het bronsjabloon. */
  public static function region_of(int $post_id, string $meta_key = ''): string {
    if ($meta_key === '_bricks_page_header_2') return 'header';
    if ($meta_key === '_bricks_page_footer_2') return 'footer';
    $tpl = get_post_type($post_id) === 'ai_wireframe' ? (int) get_post_meta($post_id, '_aisb_source_template_id', true) : $post_id;
    $type = $tpl ? (string) get_post_meta($tpl, '_bricks_template_type', true) : '';
    if (in_array($type, ['header', 'footer'], true)) return $type;
    $terms = $tpl ? wp_get_post_terms($tpl, 'template_tag', ['fields' => 'slugs']) : [];
    if (is_array($terms)) foreach (['header', 'footer'] as $r) if (in_array($r, $terms, true)) return $r;
    return '';
  }

  /**
   * Past header- of footerelementen aan de website aan.
   * $link: slug => url (in de voorbeelden '#').
   */
  public static function apply(array $nodes, array $ctx, string $region, ?callable $link = null): array {
    if (!in_array($region, ['header', 'footer'], true) || !$nodes) return $nodes;
    $link = $link ?: fn($slug) => '#';
    $idx = [];
    foreach ($nodes as $i => $n) if (isset($n['id'])) $idx[(string) $n['id']] = $i;
    $menu = $ctx['menu'];
    $groups = $ctx['footer']['groups'];

    $credited = false;
    $has_button = false;
    foreach ($nodes as $n) if (($n['name'] ?? '') === 'button') $has_button = true;

    $remove = [];
    $nav_no = 0;
    foreach ($nodes as $i => $n) {
      $name = (string) ($n['name'] ?? '');
      $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
      if (empty($n['parent'])) {
        $cls = trim((string) ($s['_cssClasses'] ?? ''));
        if (strpos(' ' . $cls . ' ', ' archer-' . $region . ' ') === false) $s['_cssClasses'] = trim($cls . ' archer-' . $region);
      }
      if ($name === 'logo') {
        // Het logo in een sectiesjabloon is altijd een voorbeeld (Brixies: "Logoipsum", "placeholder"):
        // het logo uit de stijlgids (ook een data-URI: Bricks toont die gewoon), anders de naam.
        if ($ctx['logo'] !== '') {
          $s['logo'] = ['url' => $ctx['logo'], 'external' => true];
          unset($s['logoInverse']);
        } else {
          unset($s['logo'], $s['logoInverse']);
          $s['logoText'] = $ctx['name'];
        }
      } elseif ($name === 'nav-menu') {
        $lbl = strtolower((string) ($n['label'] ?? ''));
        if ($region === 'header') {
          // Een echte knop in het sjabloon wordt de contactknop; anders komt contact als laatste in het menu
          // (bij sjablonen die het laatste item als knop opmaken, ziet dat er ook zo uit).
          $s['archerMenu'] = $has_button ? 'main' : 'main_cta';
        } elseif (preg_match('/legal|juridi|policy|privacy|bottom/', $lbl)) {
          if ($ctx['footer']['legal']) $s['archerMenu'] = 'legal';
          else $remove[] = (string) $n['id'];
        } else {
          $g = $nav_no++;
          if (isset($groups[$g]) && $groups[$g]['items']) {
            $s['archerMenu'] = 'footer' . $g;
            // De titel boven dit menu (het element ervoor in hetzelfde blok).
            $pid = (string) ($n['parent'] ?? '');
            if ($pid !== '' && isset($idx[$pid])) {
              $sib = (array) ($nodes[$idx[$pid]]['children'] ?? []);
              $pos = array_search((string) $n['id'], array_map('strval', $sib), true);
              for ($k = (int) $pos - 1; $pos !== false && $k >= 0; $k--) {
                $si = $idx[(string) $sib[$k]] ?? null;
                if ($si !== null && in_array($nodes[$si]['name'] ?? '', ['heading', 'text-basic'], true)) {
                  $nodes[$si]['settings']['text'] = $groups[$g]['title'];
                  break;
                }
              }
            }
          } else {
            // Meer menu's in het sjabloon dan groepen: het hele menublok weg.
            $pid = (string) ($n['parent'] ?? '');
            $pi = $idx[$pid] ?? null;
            $remove[] = ($pi !== null && count((array) ($nodes[$pi]['children'] ?? [])) <= 3 && !empty($nodes[$pi]['parent'])) ? $pid : (string) $n['id'];
          }
        }
      } elseif ($name === 'button' && $region === 'header') {
        if ($menu['cta'] !== '') {
          $s['text'] = $menu['cta_label'];
          $s['link'] = ['type' => 'external', 'url' => $link($menu['cta'])];
        } else {
          $remove[] = (string) $n['id'];
        }
      } elseif ($region === 'footer' && !$credited && in_array($name, ['text-basic', 'text'], true) && preg_match('/©|&copy;|copyright/i', (string) ($s['text'] ?? ''))) {
        $s['text'] = self::credit($ctx);
        $s['_cssClasses'] = trim((string) ($s['_cssClasses'] ?? '') . ' archer-credit');
        $credited = true;
      }
      $nodes[$i]['settings'] = $s;
    }
    $nodes = $remove ? self::remove($nodes, $remove) : $nodes;
    // Altijd onderaan: "Website door Archer Websites", ook als het sjabloon geen copyrightregel heeft.
    if ($region === 'footer' && !$credited) $nodes = self::add_credit($nodes, $ctx);
    // Contactgegevens, sociale media en links (adres, telefoon, e-mail; lege items weg).
    if (class_exists('AISB_Archer_Site')) $nodes = AISB_Archer_Site::apply_nodes($nodes, (int) $ctx['project_id'], $link, $region);
    return $nodes;
  }

  const CREDIT = 'Website door <a href="https://archerwebsites.be" target="_blank" rel="noopener">Archer Websites</a>';

  public static function credit(array $ctx): string {
    return '© ' . $ctx['year'] . ' ' . esc_html($ctx['name']) . ' · ' . self::CREDIT;
  }

  /** Een creditregel als laatste element van de footer (in de container van de bovenste sectie). */
  private static function add_credit(array $nodes, array $ctx): array {
    $root = null;
    foreach ($nodes as $i => $n) if (empty($n['parent'])) { $root = $i; break; }
    if ($root === null) return $nodes;
    $parent = $root;
    foreach ((array) ($nodes[$root]['children'] ?? []) as $cid) {
      foreach ($nodes as $i => $n) {
        if ((string) ($n['id'] ?? '') === (string) $cid && ($n['name'] ?? '') === 'container') $parent = $i;
      }
    }
    $taken = array_flip(array_map(fn($n) => (string) ($n['id'] ?? ''), $nodes));
    do { $id = 'arcr' . substr(md5((string) wp_rand()), 0, 2); } while (isset($taken[$id]));
    $nodes[$parent]['children'] = array_values(array_merge((array) ($nodes[$parent]['children'] ?? []), [$id]));
    $nodes[] = [
      'id' => $id,
      'name' => 'text-basic',
      'parent' => $nodes[$parent]['id'],
      'children' => [],
      'settings' => ['text' => self::credit($ctx), 'tag' => 'p', '_cssClasses' => 'archer-credit'],
      'label' => 'Archer credit',
    ];
    return $nodes;
  }

  /** Elementen en hun kinderen weghalen, ook uit de children van hun ouder. */
  private static function remove(array $nodes, array $ids): array {
    $drop = array_flip($ids);
    $changed = true;
    while ($changed) {
      $changed = false;
      foreach ($nodes as $n) {
        if (isset($n['id'], $n['parent']) && isset($drop[(string) $n['parent']]) && !isset($drop[(string) $n['id']])) {
          $drop[(string) $n['id']] = 1;
          $changed = true;
        }
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

  /** Na het publiceren: elk nav-menu naar het menu van zijn rol. */
  public static function set_menu_ids(array $nodes, array $ids): array {
    foreach ($nodes as $i => $n) {
      if (($n['name'] ?? '') !== 'nav-menu') continue;
      $role = (string) ($n['settings']['archerMenu'] ?? '');
      $id = $ids[$role] ?? ($ids['main'] ?? 0);
      if ($id) $nodes[$i]['settings']['menu'] = (string) $id;
    }
    return $nodes;
  }

  /* ───────────────────────── Voorbeelden ───────────────────────── */

  public function preview(): void {
    if (empty($_GET['aisb_bricks_preview']) || empty($_GET['aisb_project']) || !is_user_logged_in()) return;
    $id = (int) $_GET['aisb_bricks_preview'];
    $pid = (int) $_GET['aisb_project'];
    $project = get_post($pid);
    if (!$project || $project->post_type !== 'aisb_project') return;
    if ((int) $project->post_author !== get_current_user_id() && !current_user_can('manage_options')) return;
    $ctx = self::context($pid, (int) ($_GET['aisb_sitemap'] ?? 0));
    if (!$ctx) return;

    $region = self::region_of($id);
    if ($region === '' && get_post_meta($id, '_bricks_page_header_2', true)) $region = 'header';
    if ($region === '' && get_post_meta($id, '_bricks_page_footer_2', true)) $region = 'footer';
    if ($region === '') return;

    self::$menu_ids = self::local_menus($ctx);
    $busy = false;
    add_filter('get_post_metadata', function ($value, $object_id, $meta_key, $single) use ($id, $ctx, $region, &$busy) {
      if ((int) $object_id !== $id || !in_array($meta_key, self::KEYS, true) || $busy) return $value;
      $busy = true;
      $raw = get_post_meta($id, $meta_key, true);
      $busy = false;
      if (!is_array($raw) || !$raw) return $value;
      return [self::apply($raw, $ctx, $region)];
    }, 10, 4);
    add_filter('option_blogname', fn() => $ctx['name']);
    // Na de filters van de wireframe-preview (die één menu voor alles opleggen).
    add_filter('bricks/element/settings', function ($settings, $element) {
      $name = is_object($element) ? ($element->name ?? '') : (is_array($element) ? ($element['name'] ?? '') : '');
      if ($name === 'nav-menu' || $name === 'navigation') {
        $role = is_array($settings) ? (string) ($settings['archerMenu'] ?? '') : '';
        $mid = self::$menu_ids[$role] ?? (self::$menu_ids['main'] ?? 0);
        if ($mid) {
          $settings['menu'] = (string) $mid;
          self::$current_menu = $mid;
        }
      }
      return $settings;
    }, 50, 2);
    add_filter('wp_nav_menu_args', function ($args) {
      if (self::$current_menu) $args['menu'] = self::$current_menu;
      return $args;
    }, 10050);
  }

  /** Menu's op deze site voor de voorbeelden van één project (alleen opnieuw opbouwen als ze wijzigen). */
  private static function local_menus(array $ctx): array {
    $ids = [];
    foreach (self::menu_lists($ctx, fn($slug) => '#') as $role => $items) {
      $name = 'Archer ' . $ctx['project_id'] . ' ' . $role;
      $hash = md5(wp_json_encode($items));
      $menu = wp_get_nav_menu_object($name);
      $mid = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($name);
      if (!$mid || is_wp_error($mid)) continue;
      if (get_term_meta($mid, '_archer_hash', true) !== $hash) {
        // Alle voorbeelden van een pagina laden tegelijk: één verzoek bouwt het menu, de rest wacht niet.
        $lock = 'archer_menu_lock_' . $mid;
        $since = (int) get_option($lock, 0);
        if ($since && $since < time() - 30) delete_option($lock);
        if (!add_option($lock, time(), '', 'no')) { $ids[$role] = $mid; continue; }
        foreach ((array) wp_get_nav_menu_items($mid, ['post_status' => 'any']) as $old) if ($old) wp_delete_post($old->ID, true);
        $made = [];
        foreach ($items as $k => $it) {
          $made[$k] = (int) wp_update_nav_menu_item($mid, 0, [
            'menu-item-title' => $it['title'],
            'menu-item-url' => '#',
            'menu-item-status' => 'publish',
            'menu-item-position' => $k + 1,
            'menu-item-type' => 'custom',
            'menu-item-parent-id' => $it['parent'] >= 0 ? ($made[$it['parent']] ?? 0) : 0,
          ]);
        }
        update_term_meta($mid, '_archer_hash', $hash);
        delete_option($lock);
      }
      $ids[$role] = $mid;
    }
    return $ids;
  }

  /* ───────────────────────── Publiceren ───────────────────────── */

  /** Header en footer van de export aanpassen (voor ze gecompileerd worden). */
  public static function apply_to_export(array $pages, int $project_id): array {
    $ctx = self::context($project_id);
    if (!$ctx) return $pages;
    $link = fn($slug) => $slug === 'home' ? '/' : '/' . $slug . '/';
    // Header en footer van Home op elke pagina (ook als een pagina nog een eigen versie had).
    $home = null;
    foreach ($pages as $p) if (($p['slug'] ?? '') === 'home') $home = $p;
    $home_chrome = [];
    foreach ((array) ($home['sections'] ?? []) as $sec) {
      $type = strtolower((string) ($sec['type'] ?? ''));
      if ($type === 'header' && !isset($home_chrome['header'])) $home_chrome['header'] = $sec;
      if ($type === 'footer') $home_chrome['footer'] = $sec;
    }
    if ($home_chrome) {
      foreach ($pages as &$page) {
        if (($page['slug'] ?? '') === 'home') continue;
        foreach ((array) ($page['sections'] ?? []) as $k => $sec) {
          $type = strtolower((string) ($sec['type'] ?? ''));
          if (is_array($sec) && isset($home_chrome[$type])) $page['sections'][$k] = $home_chrome[$type];
        }
      }
      unset($page);
    }
    foreach ($pages as &$page) {
      foreach ((array) ($page['sections'] ?? []) as $k => $sec) {
        if (!is_array($sec)) continue;
        $type = strtolower((string) ($sec['type'] ?? ''));
        if (!in_array($type, ['header', 'footer'], true)) continue;
        foreach (['bricks_elements_bricks', 'bricks_elements'] as $key) {
          if (!empty($sec[$key]) && is_array($sec[$key])) $page['sections'][$k][$key] = self::apply($sec[$key], $ctx, $type, $link);
        }
      }
    }
    unset($page);
    // Knoppen en links in alle andere secties: naar de juiste pagina, tel: of mailto:.
    if (class_exists('AISB_Archer_Site')) $pages = AISB_Archer_Site::apply_to_export($pages, $project_id, $link);
    return $pages;
  }

  /**
   * Menu's voor de gepubliceerde site, per rol, met de pagina-id's van die site.
   * @param array $published [['post_id', 'slug', 'url'], ...]
   */
  public static function publish_menus(int $project_id, array $published): array {
    $ctx = self::context($project_id);
    if (!$ctx) return [];
    $by = [];
    foreach ($published as $p) $by[(string) ($p['slug'] ?? '')] = $p;
    $out = [];
    foreach (self::menu_lists($ctx, fn($slug) => (string) ($by[$slug]['url'] ?? '#')) as $role => $items) {
      $list = [];
      $map = [];
      foreach ($items as $k => $it) {
        if ($it['slug'] !== '' && !isset($by[$it['slug']])) continue; // niet gepubliceerd
        if ($it['parent'] >= 0 && !isset($map[$it['parent']])) continue;
        $map[$k] = count($list);
        $list[] = [
          'title' => $it['title'],
          'object_id' => $it['slug'] !== '' ? (int) ($by[$it['slug']]['post_id'] ?? 0) : 0,
          'url' => $it['slug'] !== '' ? (string) ($by[$it['slug']]['url'] ?? '#') : '#',
          'parent' => $it['parent'] >= 0 ? $map[$it['parent']] : -1,
        ];
      }
      if ($list) $out[$role] = $list;
    }
    return $out;
  }
}
