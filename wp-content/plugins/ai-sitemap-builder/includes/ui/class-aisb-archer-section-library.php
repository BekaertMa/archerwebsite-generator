<?php
if (!defined('ABSPATH')) exit;

/**
 * Sectiebibliotheek in de wireframe-stap (zoals Relume): links een sectie toevoegen per type,
 * rechts een ander voorbeeld van hetzelfde type kiezen.
 *
 * De voorbeelden zijn de Bricks-sjablonen van de bibliotheek, gegroepeerd per template_tag
 * (AISB_Wireframes_Bricks). Een gekozen of toegevoegde sectie krijgt meteen AI-tekst, net zoals
 * "Ander voorbeeld". Schermafbeeldingen staan in uploads/archer-thumbs/<sjabloon-id>.jpg; zonder
 * schermafbeelding toont het paneel het voorbeeld zelf (iframe).
 */
class AISB_Archer_Section_Library {

  const THUMB_DIR = 'archer-thumbs';

  /** Volgorde en uitleg van de types die je kan toevoegen (header en footer staan er altijd al). */
  /** Uitleg per soort sectie (dezelfde soorten als in de sitemap, zie AISB_Archer_Structure). */
  const DESCS = [
    'hero' => 'De opening van een pagina: grote titel, korte uitleg, een knop en meestal een beeld.',
    'banner' => 'Een brede band met een korte boodschap en een knop, bijvoorbeeld een actie of aankondiging.',
    'intro' => 'Een korte inleiding bovenaan een pagina of lijst: titel en een paar zinnen, zonder grote foto.',
    'features' => 'Wat je aanbiedt of waarom klanten voor je kiezen, als kaarten of een lijst met iconen.',
    'content' => 'Een verhaal of uitleg naast een foto: over ons, een dienst in detail, een aanpak.',
    'process' => 'De stappen van eerste contact tot resultaat, genummerd.',
    'timeline' => 'Gebeurtenissen of mijlpalen op een tijdlijn, bijvoorbeeld de geschiedenis van de zaak.',
    'testimonials' => 'Wat klanten zeggen: citaten met naam, eventueel met sterren of foto.',
    'social_proof' => 'Vertrouwen in één oogopslag: logo\'s van klanten of partners, cijfers en scores.',
    'team' => 'De mensen achter de zaak, met foto, naam en functie.',
    'single_team' => 'Eén teamlid in detail: foto, functie en een korte voorstelling.',
    'pricing' => 'Pakketten of tarieven naast elkaar, met wat erin zit en een knop.',
    'faq' => 'Vragen en antwoorden, meestal om open te klikken.',
    'cta' => 'Een korte band die de bezoeker naar de volgende stap stuurt: bellen, afspraak, offerte.',
    'contact' => 'Contactgegevens, een formulier, openingsuren of een kaart.',
    'gallery' => 'Foto\'s in een raster of slider, zonder veel tekst.',
    'portfolio' => 'Realisaties of projecten als kaarten met foto, die elk naar hun eigen pagina leiden.',
    'single_portfolio' => 'Eén realisatie in detail: foto\'s, de opdracht en het resultaat.',
    'products' => 'Producten of wagens als kaarten met foto, naam en prijs.',
    'single_product' => 'Eén product of wagen in detail: foto\'s, kenmerken, prijs en een knop.',
    'property' => 'Panden als kaarten met foto, ligging, prijs en de belangrijkste kenmerken.',
    'single_property' => 'Eén pand in detail: foto\'s, kenmerken, prijs en een knop om contact op te nemen.',
    'blog' => 'Blogberichten of nieuws als kaarten met foto, titel en datum.',
    'single_post_hero' => 'De opening van een blogbericht: titel, datum en grote foto.',
    'single_post' => 'De tekst van een blogbericht, met tussentitels en beelden.',
    'event' => 'Evenementen als kaarten met datum, plaats en een knop.',
    'single_event' => 'Eén evenement in detail: datum, plaats, programma en inschrijven.',
    'career' => 'Vacatures als lijst of kaarten, met een knop om te solliciteren.',
  ];

  /** Soort => [naam, uitleg], in de volgorde en met de namen van de sitemap. */
  public static function types(): array {
    $labels = AISB_Archer_Structure::tag_labels();
    $out = [];
    foreach (AISB_Archer_Structure::available() as $st) {
      $tag = AISB_Archer_Structure::tag_for($st);
      if (isset($out[$tag])) continue;
      $out[$tag] = [$labels[$tag] ?? $st, self::DESCS[$tag] ?? ''];
    }
    return $out;
  }

  public function init(): void {
    add_action('wp_ajax_aisb_archer_section_types', [$this, 'ajax_types']);
    add_action('wp_ajax_aisb_archer_section_variants', [$this, 'ajax_variants']);
    add_action('wp_ajax_aisb_archer_section_add', [$this, 'ajax_add']);
    add_action('wp_ajax_aisb_archer_section_pick', [$this, 'ajax_pick']);
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

  /* ───────────────────────── Bibliotheek ───────────────────────── */

  private static function by_type(): array {
    if (!class_exists('AISB_Wireframes_Bricks')) return [];
    return (new AISB_Wireframes_Bricks())->get_bricks_templates_by_type();
  }

  /** Sjablonen van een type, zoals de wireframe ze kiest (zonder formulier in een hero, enz.). */
  private static function pool(string $type, array $used = []): array {
    $all = self::by_type();
    $pool = (array) ($all[$type] ?? []);
    $pool = (array) apply_filters('aisb_bricks_template_pool', $pool, $type, $used);
    usort($pool, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
    return array_values($pool);
  }

  public static function thumb_url(int $id): string {
    $up = wp_upload_dir();
    $file = trailingslashit($up['basedir']) . self::THUMB_DIR . '/' . $id . '.jpg';
    if (!file_exists($file)) return '';
    // Zonder schema en domein: de uploads-url staat soms op http, de app draait op https.
    return wp_make_link_relative(trailingslashit($up['baseurl']) . self::THUMB_DIR . '/' . $id . '.jpg') . '?v=' . filemtime($file);
  }

  private static function card(array $t): array {
    $id = (int) $t['id'];
    return ['id' => $id, 'title' => (string) $t['title'], 'thumb' => self::thumb_url($id)];
  }

  /** Label van een type in gewone taal. */
  public static function label(string $type): string {
    $labels = AISB_Archer_Structure::tag_labels();
    if (isset($labels[$type])) return $labels[$type];
    return ['header' => 'Header', 'footer' => 'Footer'][$type] ?? ucfirst(str_replace(['_', '-'], ' ', $type));
  }

  public function ajax_types(): void {
    $this->guard();
    $out = [];
    foreach (self::types() as $type => [$label, $desc]) {
      $pool = self::pool($type);
      if (!$pool) continue;
      // Voorbeeld van het type: het eerste sjabloon met een schermafbeelding.
      $sample = $pool[0];
      foreach ($pool as $t) if (self::thumb_url((int) $t['id']) !== '') { $sample = $t; break; }
      $out[] = ['type' => $type, 'label' => $label, 'desc' => $desc, 'count' => count($pool), 'sample' => self::card($sample)];
    }
    wp_send_json_success(['types' => $out]);
  }

  public function ajax_variants(): void {
    $this->guard();
    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    if ($type === '') wp_send_json_error(['message' => 'Geen type'], 400);
    $pool = self::pool($type);
    wp_send_json_success([
      'type' => $type,
      'label' => self::label($type),
      'desc' => self::DESCS[$type] ?? '',
      'variants' => array_map([__CLASS__, 'card'], $pool),
    ]);
  }

  /* ───────────────────────── Wireframe aanpassen ───────────────────────── */

  private static function table(): string {
    global $wpdb;
    return $wpdb->prefix . 'aisb_wireframes';
  }

  private static function load(int $pid, int $sid, string $slug): ?array {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT model_json FROM ' . self::table() . ' WHERE project_id=%d AND sitemap_version_id=%d AND page_slug=%s', $pid, $sid, $slug), ARRAY_A);
    if (!$row) return null;
    $m = json_decode((string) $row['model_json'], true);
    return is_array($m) ? $m : null;
  }

  private static function save(int $pid, int $sid, string $slug, array $model): void {
    global $wpdb;
    $wpdb->update(self::table(), [
      'model_json' => wp_json_encode($model, JSON_UNESCAPED_SLASHES),
      'compiled_bricks_json' => null,
      'updated_at' => current_time('mysql'),
    ], ['project_id' => $pid, 'sitemap_version_id' => $sid, 'page_slug' => $slug], ['%s', '%s', '%s'], ['%d', '%d', '%s']);
  }

  /** Het sjabloon met dit id, als het bij dit type hoort (anders null). Zonder id: een willekeurig ongebruikt. */
  private static function template(string $type, int $id, array $used): ?array {
    $all = self::by_type();
    if ($id > 0) {
      foreach ((array) ($all[$type] ?? []) as $t) if ((int) $t['id'] === $id) return $t;
      return null;
    }
    $pool = self::pool($type, $used);
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
    ]);
  }

  private function params(): array {
    $pid = $this->guard();
    $sid = isset($_POST['sitemap_version_id']) ? (int) $_POST['sitemap_version_id'] : 0;
    $slug = isset($_POST['page_slug']) ? sanitize_title(wp_unslash($_POST['page_slug'])) : '';
    if (!$sid || $slug === '') wp_send_json_error(['message' => 'Pagina ontbreekt'], 400);
    $model = self::load($pid, $sid, $slug);
    if (!$model) wp_send_json_error(['message' => 'Deze pagina heeft nog geen wireframe'], 404);
    if (!isset($model['sections']) || !is_array($model['sections'])) $model['sections'] = [];
    return [$pid, $sid, $slug, $model];
  }

  private static function used(array $model): array {
    $ids = [];
    foreach ($model['sections'] as $s) if (is_array($s) && !empty($s['bricks_template_id'])) $ids[] = (int) $s['bricks_template_id'];
    return $ids;
  }

  /** AI-tekst voor één sectie (zoals bij "Ander voorbeeld"). */
  private static function write_text(array $model, int $pid, int $sid, string $slug, string $uuid): array {
    if (!class_exists('AISB_Wireframes_AI')) return $model;
    @set_time_limit(180);
    try {
      return (new AISB_Wireframes_AI())->populate_bricks_content_with_ai($model, $pid, $sid, $slug, [$uuid]);
    } catch (Throwable $e) {
      error_log('[AISB] Archer sectiebibliotheek: AI-tekst mislukt: ' . $e->getMessage());
      return $model;
    }
  }

  /** Nieuwe sectie van een type, na een bepaalde sectie of onderaan (vóór de footer). */
  public function ajax_add(): void {
    ignore_user_abort(true);
    [$pid, $sid, $slug, $model] = $this->params();
    $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
    $tid = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
    $after = isset($_POST['after_uuid']) ? sanitize_text_field(wp_unslash($_POST['after_uuid'])) : '';
    if ($type === '' || !isset(self::types()[$type])) wp_send_json_error(['message' => 'Kies een type sectie'], 400);
    $t = self::template($type, $tid, self::used($model));
    if (!$t) wp_send_json_error(['message' => 'Geen voorbeeld gevonden voor dit type'], 404);

    $uuid = wp_generate_uuid4();
    $section = self::fill(['uuid' => $uuid, 'type' => $type, 'locked' => false], $t);
    // Positie: na de gekozen sectie; anders vóór de footer; anders achteraan.
    $pos = count($model['sections']);
    foreach ($model['sections'] as $i => $s) {
      if ($after !== '' && is_array($s) && ($s['uuid'] ?? '') === $after) { $pos = $i + 1; break; }
    }
    if ($after === '' || $pos === count($model['sections'])) {
      foreach ($model['sections'] as $i => $s) {
        if (is_array($s) && strtolower((string) ($s['type'] ?? '')) === 'footer') { $pos = $i; break; }
      }
    }
    // Nooit vóór de header.
    if ($pos === 0 && !empty($model['sections'][0]) && strtolower((string) ($model['sections'][0]['type'] ?? '')) === 'header') $pos = 1;
    array_splice($model['sections'], $pos, 0, [$section]);

    self::save($pid, $sid, $slug, $model);
    $model = self::write_text($model, $pid, $sid, $slug, $uuid);
    self::save($pid, $sid, $slug, $model);
    wp_send_json_success(['wireframe' => $model, 'uuid' => $uuid, 'page_slug' => $slug]);
  }

  /** Een ander voorbeeld van hetzelfde type, door de gebruiker gekozen. */
  public function ajax_pick(): void {
    ignore_user_abort(true);
    [$pid, $sid, $slug, $model] = $this->params();
    $uuid = isset($_POST['uuid']) ? sanitize_text_field(wp_unslash($_POST['uuid'])) : '';
    $tid = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;
    $idx = null;
    foreach ($model['sections'] as $i => $s) if (is_array($s) && ($s['uuid'] ?? '') === $uuid) { $idx = $i; break; }
    if ($idx === null) wp_send_json_error(['message' => 'Sectie niet gevonden'], 404);
    $s = $model['sections'][$idx];
    if (!empty($s['locked'])) wp_send_json_error(['message' => 'Deze sectie staat vast'], 400);
    $type = strtolower((string) ($s['type'] ?? ''));
    $t = self::template($type, $tid, []);
    if (!$t) wp_send_json_error(['message' => 'Dit voorbeeld hoort niet bij dit type sectie'], 400);

    $old = (int) ($s['ai_wireframe_id'] ?? 0);
    if ($old > 0 && get_post_type($old) === 'ai_wireframe') wp_trash_post($old);
    $s['ai_wireframe_id'] = null;
    $model['sections'][$idx] = self::fill($s, $t);

    self::save($pid, $sid, $slug, $model);
    $model = self::write_text($model, $pid, $sid, $slug, $uuid);
    self::save($pid, $sid, $slug, $model);
    wp_send_json_success(['wireframe' => $model, 'uuid' => $uuid, 'page_slug' => $slug]);
  }
}
