<?php
if (!defined('ABSPATH')) exit;

/**
 * Sectiesjablonen importeren uit een Bricks-klembord (bv. "Copy" op brixies.co).
 *
 * Maakt een bricks_template post (type section) met de elementen, zet de template_tag
 * (hero, features, ...) en voegt de globale klassen en variabelen toe aan Bricks.
 * Een klasse met dezelfde naam maar andere instellingen krijgt een eigen naam
 * (prefix van het sjabloon), zodat sjablonen elkaars opmaak niet overschrijven.
 *
 * Toegang: alleen beheerders, met een eenmalige sleutel (transient aisb_archer_import_key).
 */
class AISB_Archer_Template_Import {

  public function init(): void {
    add_action('wp_ajax_aisb_archer_import_template', [$this, 'ajax_import']);
  }

  public function ajax_import(): void {
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
    $want = get_transient('aisb_archer_import_key');
    if (!$key || !$want || !hash_equals((string) $want, $key)) wp_send_json_error(['message' => 'Ongeldige sleutel'], 403);
    $items = json_decode(wp_unslash($_POST['items'] ?? ''), true);
    if (!is_array($items)) wp_send_json_error(['message' => 'Geen geldige JSON'], 400);
    $out = [];
    foreach ($items as $it) {
      $clip = is_array($it['clip'] ?? null) ? $it['clip'] : json_decode((string) ($it['clip'] ?? ''), true);
      $out[] = is_array($clip)
        ? self::import($clip, sanitize_key($it['type'] ?? 'hero'), sanitize_text_field($it['title'] ?? 'Sectie'), esc_url_raw($it['source'] ?? ''))
        : ['error' => 'Klembord onleesbaar', 'title' => $it['title'] ?? ''];
    }
    wp_send_json_success($out);
  }

  /** @return array{id?:int,title:string,classes_new?:int,classes_renamed?:int,error?:string} */
  public static function import(array $clip, string $type, string $title, string $source = ''): array {
    $content = $clip['content'] ?? null;
    if (!is_array($content) || !$content) return ['error' => 'Geen elementen', 'title' => $title];

    $existing = get_posts(['post_type' => 'bricks_template', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
    if ($existing) return ['error' => 'Bestaat al', 'id' => (int) $existing[0], 'title' => $title];

    $prefix = sanitize_title($title); // bv. hero-section-104

    /* Globale klassen samenvoegen. */
    $classes = get_option('bricks_global_classes', []);
    if (!is_array($classes)) $classes = [];
    $by_name = [];
    foreach ($classes as $i => $c) if (isset($c['name'])) $by_name[$c['name']] = $i;
    $taken_ids = array_flip(array_column($classes, 'id'));
    $id_map = [];   // klembord-id → id in deze site
    $renames = [];  // oude naam → nieuwe naam
    $new = 0;
    $added = [];
    foreach ((array) ($clip['globalClasses'] ?? []) as $gc) {
      if (empty($gc['id']) || empty($gc['name'])) continue;
      $name = $gc['name'];
      $settings = $gc['settings'] ?? [];
      if (isset($by_name[$name])) {
        $cur = $classes[$by_name[$name]];
        if (wp_json_encode($cur['settings'] ?? []) === wp_json_encode($settings)) {
          $id_map[$gc['id']] = $cur['id'];
          continue;
        }
        $renamed = $prefix . '__' . $name;
        $renames[$name] = $renamed;
        $name = $renamed;
        if (isset($by_name[$name])) { $id_map[$gc['id']] = $classes[$by_name[$name]]['id']; continue; }
      }
      $nid = isset($taken_ids[$gc['id']]) ? self::uid($taken_ids) : $gc['id'];
      $taken_ids[$nid] = 1;
      $id_map[$gc['id']] = $nid;
      $entry = ['id' => $nid, 'name' => $name, 'settings' => $settings];
      if (isset($gc['category'])) $entry['category'] = $gc['category'];
      $classes[] = $entry;
      $added[$nid] = 1;
      $by_name[$name] = count($classes) - 1;
      $new++;
    }
    // Eigen CSS in hernoemde klassen volgen de nieuwe naam.
    if ($renames) {
      foreach ($classes as &$c) {
        if (isset($c['settings']) && isset($added[$c['id']])) $c['settings'] = self::rename_css($c['settings'], $renames);
      }
      unset($c);
    }
    update_option('bricks_global_classes', $classes);

    /* Globale variabelen: alleen ontbrekende toevoegen. */
    if (!empty($clip['globalVariables']) && is_array($clip['globalVariables'])) {
      $vars = get_option('bricks_global_variables', []);
      if (!is_array($vars)) $vars = [];
      $have = array_flip(array_column($vars, 'name'));
      $vids = array_flip(array_column($vars, 'id'));
      foreach ($clip['globalVariables'] as $v) {
        if (empty($v['name']) || isset($have[$v['name']])) continue;
        $v['id'] = isset($vids[$v['id'] ?? '']) || empty($v['id']) ? self::uid($vids) : $v['id'];
        $vids[$v['id']] = 1;
        unset($v['category']);
        $vars[] = $v;
        $have[$v['name']] = 1;
      }
      update_option('bricks_global_variables', $vars);
    }

    /* Elementen: nieuwe id's (geen botsingen met andere secties), klassen omzetten. */
    $taken = [];
    $eid = [];
    foreach ($content as $n) if (isset($n['id'])) { $eid[$n['id']] = self::uid($taken); $taken[$eid[$n['id']]] = 1; }
    $nodes = [];
    foreach ($content as $n) {
      if (!isset($n['id'], $n['name'])) continue;
      $n['id'] = $eid[$n['id']];
      $n['parent'] = (!empty($n['parent']) && isset($eid[$n['parent']])) ? $eid[$n['parent']] : 0;
      $n['children'] = array_values(array_filter(array_map(fn($c) => $eid[$c] ?? null, (array) ($n['children'] ?? []))));
      $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
      if (!empty($s['_cssGlobalClasses'])) {
        $s['_cssGlobalClasses'] = array_values(array_unique(array_map(fn($c) => $id_map[$c] ?? $c, (array) $s['_cssGlobalClasses'])));
      }
      if ($renames) $s = self::rename_css($s, $renames);
      $n['settings'] = $s ?: [];
      $nodes[] = $n;
    }
    if (!$nodes) return ['error' => 'Geen elementen', 'title' => $title];
    $nodes = self::localise_images($nodes);
    $nodes = self::mark_cover($nodes, $classes);
    // Rootlabel zoals bij de andere sjablonen (de type-detectie leest dat ook).
    foreach ($nodes as &$n) { if (empty($n['parent']) && $n['name'] === 'section' && empty($n['label'])) $n['label'] = ucfirst(str_replace('_', ' ', $type)); }
    unset($n);

    $post_id = wp_insert_post(['post_type' => 'bricks_template', 'post_status' => 'publish', 'post_title' => $title], true);
    if (is_wp_error($post_id)) return ['error' => $post_id->get_error_message(), 'title' => $title];
    update_post_meta($post_id, '_bricks_template_type', 'section');
    update_post_meta($post_id, '_bricks_editor_mode', 'bricks');
    update_post_meta($post_id, '_bricks_page_content_2', $nodes);
    update_post_meta($post_id, '_bricks_data', $nodes);
    if ($source) update_post_meta($post_id, '_aisb_source_url', $source);
    wp_set_object_terms($post_id, [$type], 'template_tag', false);

    return ['id' => (int) $post_id, 'title' => $title, 'nodes' => count($nodes), 'classes_new' => $new, 'classes_renamed' => count($renames)];
  }


  /**
   * Sectie met een foto/video die de hele sectie vult (absoluut, 100%): klasse archer-cover op de sectie.
   * De huisregels houden de tekst daar wit, ook als de stijlstap de sectiekleur licht maakt.
   */
  public static function mark_cover(array $nodes, ?array $classes = null): array {
    if ($classes === null) $classes = (array) get_option('bricks_global_classes', []);
    $cls = array_column($classes, 'settings', 'id');
    $root = null;
    foreach ($nodes as $i => $n) if (empty($n['parent'])) { $root = $i; break; }
    if ($root === null || ($nodes[$root]['name'] ?? '') !== 'section') return $nodes;
    $rid = $nodes[$root]['id'];
    $cover = false;
    foreach ($nodes as $n) {
      if (($n['parent'] ?? '') !== $rid || !in_array($n['name'] ?? '', ['image', 'video'], true)) continue;
      $st = (array) ($n['settings'] ?? []);
      foreach ((array) ($st['_cssGlobalClasses'] ?? []) as $cid) $st += (array) ($cls[$cid] ?? []);
      if (($st['_position'] ?? '') === 'absolute' && in_array((string) ($st['_width'] ?? ''), ['100%'], true) && in_array((string) ($st['_height'] ?? ''), ['100%'], true)) { $cover = true; break; }
    }
    if ($cover) {
      $c = trim((string) ($nodes[$root]['settings']['_cssClasses'] ?? ''));
      if (strpos(' ' . $c . ' ', ' archer-cover ') === false) $nodes[$root]['settings']['_cssClasses'] = trim($c . ' archer-cover');
    }
    return $nodes;
  }

  /** Werkt op elke site met Bricks, ook na publicatie (zelfde placeholder als de andere sjablonen). */
  const PLACEHOLDER = '/wp-content/themes/bricks/assets/images/placeholder-image-800x600.jpg';

  /** Placeholderbeelden van een andere site (brixies.co, ...) vervangen door de lokale Bricks-placeholder. */
  public static function localise_images(array $nodes): array {
    $home = wp_parse_url(home_url(), PHP_URL_HOST);
    $fix = function ($img) use ($home) {
      if (!is_array($img) || empty($img['url']) || !empty($img['id'])) return $img;
      $host = wp_parse_url($img['url'], PHP_URL_HOST);
      if ($host && $host !== $home) {
        $img['url'] = self::PLACEHOLDER;
        $img['external'] = true;
      }
      return $img;
    };
    foreach ($nodes as &$n) {
      if (isset($n['settings']['image'])) $n['settings']['image'] = $fix($n['settings']['image']);
      if (isset($n['settings']['_background']['image'])) $n['settings']['_background']['image'] = $fix($n['settings']['_background']['image']);
    }
    unset($n);
    return $nodes;
  }

  /** Vervangt .oude-klasse door .nieuwe-klasse in eigen CSS (_cssCustom en dergelijke). */
  private static function rename_css(array $settings, array $renames): array {
    uksort($renames, fn($a, $b) => strlen($b) - strlen($a));
    array_walk_recursive($settings, function (&$v, $k) use ($renames) {
      if (!is_string($v) || strpos($v, '.') === false || (is_string($k) && stripos($k, 'css') === false)) return;
      foreach ($renames as $old => $newName) {
        $v = preg_replace('/\.' . preg_quote($old, '/') . '(?![\w-])/', '.' . $newName, $v);
      }
    });
    return $settings;
  }

  private static function uid(array $taken): string {
    do {
      $id = '';
      for ($i = 0; $i < 6; $i++) $id .= chr(97 + random_int(0, 25));
    } while (isset($taken[$id]));
    return $id;
  }
}
