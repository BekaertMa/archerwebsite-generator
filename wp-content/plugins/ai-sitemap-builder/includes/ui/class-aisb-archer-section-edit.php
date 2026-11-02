<?php
if (!defined('ABSPATH')) exit;

/**
 * Wireframe-secties bewerken vanop het canvas (zijpaneel).
 *
 * Elke sectie is een ai_wireframe post met een eigen kopie van de Bricks-elementen
 * (meta _bricks_page_content_2). Het paneel leest die elementen en stuurt kleine
 * bewerkingen terug: tekst, twee elementen van plaats wisselen (beeld links/rechts),
 * een element dupliceren of verwijderen (aantal knoppen of items) en het aantal kolommen.
 *
 * Een sectie die nog rechtstreeks naar een bibliotheeksjabloon wijst (zoals de header),
 * wordt eerst gekopieerd naar een ai_wireframe post: het sjabloon zelf blijft ongemoeid.
 */
class AISB_Archer_Section_Edit {

  const META = '_bricks_page_content_2';
  const TEXT_KEYS = ['text', 'title', 'subtitle', 'description', 'heading', 'content', 'label'];
  const MAX_NODES = 600;

  public function init(): void {
    add_action('wp_ajax_aisb_archer_section_schema', [$this, 'ajax_schema']);
    add_action('wp_ajax_aisb_archer_section_edit', [$this, 'ajax_edit']);
  }

  /* ───────────────────────── Toegang ───────────────────────── */

  private function guard(): int {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Niet aangemeld'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!$nonce || (!wp_verify_nonce($nonce, 'aisb_wf_nonce') && !wp_verify_nonce($nonce, 'aisb_nonce_action'))) {
      wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    }
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $project = $project_id ? get_post($project_id) : null;
    if (!$project || $project->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $project->post_author !== get_current_user_id() && !current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Geen toegang'], 403);
    }
    return $project_id;
  }

  /** Mag deze post gelezen worden binnen dit project? Geeft 'own' (eigen kopie) of 'template'. */
  private function kind(int $post_id, int $project_id): string {
    $post = $post_id ? get_post($post_id) : null;
    if (!$post) return '';
    if ($post->post_type === 'ai_wireframe' && (int) get_post_meta($post_id, '_aisb_project_id', true) === $project_id) return 'own';
    if ($post->post_type === 'bricks_template') return 'template';
    return '';
  }

  private function nodes(int $post_id): array {
    $n = get_post_meta($post_id, self::META, true);
    return is_array($n) ? array_values($n) : [];
  }

  /** Maakt een eigen kopie (ai_wireframe) van een sjabloon of van een andere sectie. */
  private function own_copy(int $source_id, int $project_id, string $page_slug, array $nodes): int {
    $id = wp_insert_post([
      'post_title'  => '[AI] ' . $page_slug . ' - ' . get_the_title($source_id),
      'post_type'   => 'ai_wireframe',
      'post_status' => 'publish',
    ]);
    if (is_wp_error($id) || !$id) return 0;
    $src_tpl = get_post_type($source_id) === 'bricks_template' ? $source_id : (int) get_post_meta($source_id, '_aisb_source_template_id', true);
    update_post_meta($id, self::META, wp_slash($nodes));
    update_post_meta($id, '_aisb_source_template_id', $src_tpl);
    update_post_meta($id, '_aisb_project_id', $project_id);
    update_post_meta($id, '_aisb_page_slug', $page_slug);
    return (int) $id;
  }

  /* ───────────────────────── Schema voor het paneel ───────────────────────── */

  public function ajax_schema(): void {
    $project_id = $this->guard();
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    if (!$this->kind($post_id, $project_id)) wp_send_json_error(['message' => 'Sectie niet gevonden'], 404);
    wp_send_json_success(['nodes' => $this->schema($this->nodes($post_id), $post_id)]);
  }

  /** Alleen wat het paneel nodig heeft: structuur, naam, label en teksten. */
  private function schema(array $nodes, int $post_id = 0): array {
    // Placeholdertekst van het sjabloon: bepaalt hoe lang een tekst mag zijn.
    $tpl = get_post_type($post_id) === 'bricks_template' ? $post_id : (int) get_post_meta($post_id, '_aisb_source_template_id', true);
    $orig = [];
    $by_label = [];
    foreach (($tpl ? $this->nodes($tpl) : []) as $t) {
      $txt = $t['settings']['text'] ?? null;
      if (!is_string($txt)) continue;
      $orig[(string) $t['id']] = $txt;
      $by_label[($t['name'] ?? '') . '|' . ($t['label'] ?? '')] = $by_label[($t['name'] ?? '') . '|' . ($t['label'] ?? '')] ?? $txt;
    }
    $out = [];
    foreach ($nodes as $n) {
      if (!is_array($n) || empty($n['id'])) continue;
      $s = isset($n['settings']) && is_array($n['settings']) ? $n['settings'] : [];
      $row = [
        'id'       => (string) $n['id'],
        'name'     => (string) ($n['name'] ?? ''),
        'parent'   => (string) ($n['parent'] ?? '0'),
        'children' => array_values(array_map('strval', (array) ($n['children'] ?? []))),
        'label'    => (string) ($n['label'] ?? ''),
        'tag'      => (string) ($s['tag'] ?? ''),
      ];
      foreach (self::TEXT_KEYS as $k) {
        if (isset($s[$k]) && is_string($s[$k]) && trim($s[$k]) !== '') {
          $row['key'] = $k;
          $row['text'] = $s[$k];
          $row['mode'] = AISB_Archer_Generate::mode_for($row['name']);
          $ph = $orig[$row['id']] ?? ($by_label[$row['name'] . '|' . $row['label']] ?? '');
          $row['max'] = $ph !== '' ? AISB_Archer_Generate::text_limit($row['name'], $ph) : 0;
          break;
        }
      }
      if (!empty($s['outline'])) $row['outline'] = true;
      $out[] = $row;
    }
    return $out;
  }

  /* ───────────────────────── Bewerken ───────────────────────── */

  public function ajax_edit(): void {
    $project_id = $this->guard();
    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $page_slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    $ops = json_decode(isset($_POST['ops']) ? wp_unslash($_POST['ops']) : '', true);
    $sync = json_decode(isset($_POST['sync']) ? wp_unslash($_POST['sync']) : '[]', true);
    $kind = $this->kind($post_id, $project_id);
    if (!$kind) wp_send_json_error(['message' => 'Sectie niet gevonden'], 404);
    if (!is_array($ops) || !$ops) wp_send_json_error(['message' => 'Geen wijzigingen'], 400);

    $nodes = $this->nodes($post_id);
    if (!$nodes) wp_send_json_error(['message' => 'Deze sectie heeft geen Bricks-elementen'], 400);
    $done = 0;
    foreach (array_slice($ops, 0, 50) as $op) {
      if (is_array($op) && $this->apply($nodes, $op)) $done++;
    }
    if (!$done) wp_send_json_error(['message' => 'Deze wijziging past niet bij deze sectie'], 400);
    if (count($nodes) > self::MAX_NODES) wp_send_json_error(['message' => 'Sectie wordt te groot'], 400);

    // Een sjabloon nooit zelf aanpassen: eerst een eigen kopie voor deze pagina.
    if ($kind === 'template') {
      $post_id = $this->own_copy($post_id, $project_id, $page_slug ?: 'pagina', $nodes);
      if (!$post_id) wp_send_json_error(['message' => 'Kopie maken mislukt'], 500);
    } else {
      update_post_meta($post_id, self::META, wp_slash($nodes));
    }

    // Header en footer: dezelfde inhoud op de andere pagina's.
    $synced = [];
    if (is_array($sync)) {
      foreach (array_slice($sync, 0, 60) as $t) {
        $slug = sanitize_title((string) ($t['page_slug'] ?? ''));
        $tid = (int) ($t['post_id'] ?? 0);
        $tk = $this->kind($tid, $project_id);
        if (!$slug || !$tk || $tid === $post_id) continue;
        if ($tk === 'template') {
          $new = $this->own_copy($tid, $project_id, $slug, $nodes);
          if ($new) $synced[$slug] = $new;
        } else {
          update_post_meta($tid, self::META, wp_slash($nodes));
          $synced[$slug] = $tid;
        }
      }
    }

    wp_send_json_success(['post_id' => $post_id, 'synced' => (object) $synced, 'nodes' => $this->schema($nodes, $post_id), 'applied' => $done]);
  }

  private function index(array $nodes): array {
    $ix = [];
    foreach ($nodes as $i => $n) if (isset($n['id'])) $ix[(string) $n['id']] = $i;
    return $ix;
  }

  private function new_id(array $taken): string {
    do {
      $id = '';
      for ($i = 0; $i < 6; $i++) $id .= chr(97 + random_int(0, 25));
    } while (isset($taken[$id]));
    return $id;
  }

  /** Alle ids van een element en zijn kinderen, in boomvolgorde. */
  private function subtree(array $nodes, array $ix, string $id): array {
    $out = [$id];
    $i = $ix[$id] ?? null;
    if ($i === null) return $out;
    foreach ((array) ($nodes[$i]['children'] ?? []) as $c) {
      if (isset($ix[(string) $c])) $out = array_merge($out, $this->subtree($nodes, $ix, (string) $c));
    }
    return $out;
  }

  private function apply(array &$nodes, array $op): bool {
    $ix = $this->index($nodes);
    $type = (string) ($op['op'] ?? '');
    $id = (string) ($op['id'] ?? '');

    if ($type === 'text') {
      if (!isset($ix[$id])) return false;
      $key = in_array($op['key'] ?? '', self::TEXT_KEYS, true) ? $op['key'] : 'text';
      $name = (string) ($nodes[$ix[$id]]['name'] ?? '');
      $nodes[$ix[$id]]['settings'][$key] = AISB_Archer_Generate::clean_markup((string) ($op['value'] ?? ''), AISB_Archer_Generate::mode_for($name));
      return true;
    }

    if ($type === 'swap') {
      $a = $id;
      $b = (string) ($op['with'] ?? '');
      if (!isset($ix[$a], $ix[$b])) return false;
      $pa = (string) ($nodes[$ix[$a]]['parent'] ?? '');
      if ($pa !== (string) ($nodes[$ix[$b]]['parent'] ?? '') || !isset($ix[$pa])) return false;
      $kids = array_map('strval', (array) $nodes[$ix[$pa]]['children']);
      $ia = array_search($a, $kids, true);
      $ib = array_search($b, $kids, true);
      if ($ia === false || $ib === false) return false;
      [$kids[$ia], $kids[$ib]] = [$kids[$ib], $kids[$ia]];
      $nodes[$ix[$pa]]['children'] = $kids;
      return true;
    }

    if ($type === 'remove') {
      if (!isset($ix[$id])) return false;
      $p = (string) ($nodes[$ix[$id]]['parent'] ?? '0');
      if (!isset($ix[$p])) return false; // de sectie zelf niet
      $gone = array_flip($this->subtree($nodes, $ix, $id));
      $nodes[$ix[$p]]['children'] = array_values(array_filter(array_map('strval', (array) $nodes[$ix[$p]]['children']), fn($c) => $c !== $id));
      $nodes = array_values(array_filter($nodes, fn($n) => !isset($gone[(string) ($n['id'] ?? '')])));
      return true;
    }

    if ($type === 'clone' || $type === 'button') {
      if (!isset($ix[$id])) return false;
      $p = (string) ($nodes[$ix[$id]]['parent'] ?? '0');
      if (!isset($ix[$p])) return false;
      $taken = $ix;

      // Een tweede knop naast een losse knop: beide in een rij zetten.
      if ($type === 'button') {
        $siblings = array_map('strval', (array) $nodes[$ix[$p]]['children']);
        $others = array_filter($siblings, fn($c) => isset($ix[$c]) && ($nodes[$ix[$c]]['name'] ?? '') !== 'button');
        if ($others) {
          $wrap = $this->new_id($taken);
          $taken[$wrap] = 1;
          $nodes[] = [
            'id' => $wrap, 'name' => 'block', 'parent' => $p, 'children' => [$id], 'label' => 'Knoppen',
            'settings' => ['_direction' => 'row', '_flexWrap' => 'wrap', '_columnGap' => '12px', '_rowGap' => '12px', '_alignItems' => 'center', '_width' => 'auto'],
          ];
          $nodes[$ix[$p]]['children'] = array_map(fn($c) => $c === $id ? $wrap : $c, $siblings);
          $nodes[$ix[$id]]['parent'] = $wrap;
          $p = $wrap;
          $ix = $this->index($nodes);
        }
      }

      $map = [];
      foreach ($this->subtree($nodes, $ix, $id) as $old) {
        $map[$old] = $this->new_id($taken);
        $taken[$map[$old]] = 1;
      }
      $copies = [];
      foreach ($map as $old => $new) {
        $n = $nodes[$ix[$old]];
        $n['id'] = $new;
        $n['parent'] = $old === $id ? $p : $map[(string) $n['parent']];
        $n['children'] = array_values(array_map(fn($c) => $map[(string) $c] ?? (string) $c, (array) ($n['children'] ?? [])));
        if ($type === 'button' && $old === $id) $n['settings']['outline'] = true;
        $copies[] = $n;
      }
      $nodes = array_merge($nodes, $copies);
      $ix = $this->index($nodes);
      $kids = array_map('strval', (array) $nodes[$ix[$p]]['children']);
      $at = array_search($id, $kids, true);
      array_splice($kids, $at === false ? count($kids) : $at + 1, 0, [$map[$id]]);
      $nodes[$ix[$p]]['children'] = $kids;
      return true;
    }

    if ($type === 'cols') {
      if (!isset($ix[$id])) return false;
      $n = max(1, min(6, (int) ($op['n'] ?? 0)));
      $s = &$nodes[$ix[$id]]['settings'];
      if (!is_array($s)) $s = [];
      $s['_gridTemplateColumns'] = "repeat($n, minmax(0, 1fr))";
      $s['_gridTemplateColumns:tablet_portrait'] = 'repeat(' . min($n, 2) . ', minmax(0, 1fr))';
      $s['_gridTemplateColumns:mobile_portrait'] = 'minmax(0, 1fr)';
      unset($s);
      return true;
    }

    return false;
  }
}
