<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-aisb-archer-sitemap-rules.php';
require_once __DIR__ . '/class-aisb-archer-generate.php';

/**
 * Archer-interface voor de websitebouwer.
 *
 * Deze klasse geeft de builder de look en flow van het Archer SaaS-prototype:
 * - app-header met projectnaam en "automatisch bewaard"-status;
 * - stappenbalk Sitemap, Wireframes, Stijl, Foto's, Publiceren;
 * - dashboard "Mijn websites" (stap 0) en een startscherm (stap 1 zonder project);
 * - autosave van de sitemap, ophalen van een bestaande website als brief,
 *   en het bijhouden van het gepubliceerde webadres per project.
 *
 * De bestaande stapmodules (sitemap, wireframes, stijlgids, design) blijven
 * hun eigen data en AJAX-routes gebruiken; deze laag legt er de interface over.
 */
class AISB_Archer_UI {

  public const VERSION = '0.1.0';

  /** Stappen in de balk: key, label, aisb_step, optionele view. */
  public static function steps(): array {
    return [
      ['key' => 'sitemap',    'label' => 'Sitemap',    'step' => 1, 'view' => ''],
      ['key' => 'wireframes', 'label' => 'Wireframes', 'step' => 2, 'view' => ''],
      ['key' => 'style',      'label' => 'Stijl',      'step' => 3, 'view' => ''],
      ['key' => 'photos',     'label' => "Foto's",     'step' => 3, 'view' => 'photos'],
      ['key' => 'publish',    'label' => 'Publiceren', 'step' => 4, 'view' => ''],
    ];
  }

  public function init(): void {
    add_action('wp_enqueue_scripts', [$this, 'maybe_enqueue'], 20);
    add_filter('body_class', [$this, 'body_class']);

    add_action('wp_ajax_aisb_archer_autosave_sitemap', [$this, 'ajax_autosave_sitemap']);
    add_action('wp_ajax_aisb_archer_site_brief', [$this, 'ajax_site_brief']);

    (new AISB_Archer_Sitemap_Rules())->init();
    (new AISB_Archer_Generate())->init();
  }

  /* ───────────────────────── Detectie en assets ───────────────────────── */

  /** Staat de builder-shortcode op de huidige pagina (ook in Bricks-content)? */
  public static function is_builder_page(): bool {
    if (!is_singular()) return false;
    $id = (int) get_queried_object_id();
    if (!$id) return false;
    $post = get_post($id);
    if ($post && has_shortcode((string) $post->post_content, 'ai_sitemap_builder')) return true;
    $bricks = get_post_meta($id, '_bricks_page_content_2', true);
    if (!$bricks) return false;
    $json = is_string($bricks) ? $bricks : wp_json_encode($bricks);
    return is_string($json) && strpos($json, 'ai_sitemap_builder') !== false;
  }

  public function body_class(array $classes): array {
    if (self::is_builder_page()) {
      $classes[] = 'archer-app';
      if (current_user_can('manage_options')) $classes[] = 'archer-staff';
    }
    return $classes;
  }

  public function maybe_enqueue(): void {
    if (!self::is_builder_page()) return;
    self::enqueue();
  }

  /** Laadt fonts, CSS en de Archer-scripts. Idempotent. */
  public static function enqueue(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    wp_enqueue_style(
      'archer-fonts',
      'https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&family=Urbanist:wght@600;700;800&display=swap',
      [],
      null
    );
    $v = function ($f) { $p = AISB_PLUGIN_DIR . 'assets/archer/' . $f; return file_exists($p) ? (string) filemtime($p) : self::VERSION; };
    wp_enqueue_style('archer-ui', AISB_PLUGIN_URL . 'assets/archer/archer.css', [], $v('archer.css'));

    // In de footer, na de stapmodules: de init draait op DOMContentLoaded.
    wp_enqueue_script('archer-ui', AISB_PLUGIN_URL . 'assets/archer/archer.js', [], $v('archer.js'), true);
    wp_enqueue_script('archer-sitemap', AISB_PLUGIN_URL . 'assets/archer/archer-sitemap.js', ['archer-ui'], $v('archer-sitemap.js'), true);

    $step = isset($_GET['aisb_step']) ? (int) $_GET['aisb_step'] : 1;
    $view = isset($_GET['aisb_view']) ? sanitize_key(wp_unslash($_GET['aisb_view'])) : '';

    wp_localize_script('archer-ui', 'ARCHER', [
      'ajaxUrl'   => admin_url('admin-ajax.php'),
      'nonce'     => wp_create_nonce(AISB_Plugin::NONCE_ACTION),
      'step'      => $step,
      'view'      => $view,
      'projectId' => isset($_GET['aisb_project']) ? (int) $_GET['aisb_project'] : 0,
      'sitemapId' => isset($_GET['aisb_sitemap']) ? (int) $_GET['aisb_sitemap'] : 0,
      'isStaff'   => current_user_can('manage_options') ? 1 : 0,
      'sectionLabels' => self::section_labels(),
    ]);
  }

  /** Nederlandse labels voor de sectietypes uit AISB_Enforcer. */
  public static function section_labels(): array {
    return [
      'Banner Section'       => 'Banner',
      'Blog Sections'        => 'Blog',
      'Career Sections'      => 'Vacatures',
      'Category Filters'     => 'Filters',
      'Contact Sections'     => 'Contact',
      'Content Sections'     => 'Tekst en beeld',
      'CTA Sections'         => 'Oproep tot actie',
      'Event Sections'       => 'Evenementen',
      'FAQ Sections'         => 'Veelgestelde vragen',
      'Feature Sections'     => 'Diensten en troeven',
      'Footers'              => 'Footer',
      'Gallery Sections'     => 'Galerij',
      'Headers'              => 'Header',
      'Hero Sections'        => 'Hero',
      'Intro Sections'       => 'Intro',
      'Logo Sections'        => "Logo's",
      'Megamenu Sections - part of header section' => 'Megamenu',
      'Portfolio Sections'   => 'Realisaties',
      'Pricing Sections'     => 'Prijzen',
      'Process Sections'     => 'Werkwijze',
      'Products Sections'    => 'Producten',
      'Property Sections'    => 'Panden',
      'Single Event Sections'     => 'Evenement (detail)',
      'Single Portfolio Sections' => 'Realisatie (detail)',
      'Single Post Hero'          => 'Blogbericht (hero)',
      'Single Post Sections'      => 'Blogbericht (detail)',
      'Single Product Sections'   => 'Product (detail)',
      'Single Property Sections'  => 'Pand (detail)',
      'Single Team Sections'      => 'Teamlid (detail)',
      'Team Sections'        => 'Team',
      'Testimonial Sections' => 'Getuigenissen',
      'Timeline Sections'    => 'Tijdlijn',
    ];
  }

  /* ───────────────────────── URL's ───────────────────────── */

  /** Basis-URL van de builderpagina zonder AISB-parameters. */
  public static function base_url(): string {
    $id = (int) get_queried_object_id();
    $url = $id ? get_permalink($id) : home_url('/');
    return remove_query_arg(['aisb_step', 'aisb_view', 'aisb_project', 'aisb_sitemap', 'aisb_return', 'aisb_version'], $url);
  }

  public static function step_url(array $s, int $project_id, int $sitemap_id): string {
    $args = ['aisb_step' => $s['step']];
    if (!empty($s['view'])) $args['aisb_view'] = $s['view'];
    if ($project_id) $args['aisb_project'] = $project_id;
    if ($sitemap_id) $args['aisb_sitemap'] = $sitemap_id;
    return add_query_arg($args, self::base_url());
  }

  public static function current_step_key(int $step, string $view): string {
    if ($step === 0) return 'dashboard';
    if ($step === 3 && $view === 'photos') return 'photos';
    foreach (self::steps() as $s) {
      if ($s['step'] === $step && $s['view'] === '') return $s['key'];
    }
    return 'sitemap';
  }

  /* ───────────────────────── Header en stappenbalk ───────────────────────── */

  public static function render_topbar(int $project_id): string {
    $dash = add_query_arg(['aisb_step' => 0], self::base_url());
    $name = $project_id ? get_the_title($project_id) : '';
    $user = wp_get_current_user();
    $initials = '';
    if ($user && $user->exists()) {
      $parts = preg_split('/\s+/', trim($user->display_name ?: $user->user_login));
      foreach (array_slice($parts, 0, 2) as $p) $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }

    ob_start(); ?>
    <header class="archer-topbar">
      <a class="archer-brand" href="<?php echo esc_url($dash); ?>" aria-label="Archer Websites, naar mijn websites">
        <span class="archer-brand-mark" aria-hidden="true">A</span>
        <span class="archer-brand-name">Archer<span>Websites</span></span>
      </a>
      <div class="archer-topbar-right">
        <?php if ($name) : ?>
          <span class="archer-save-pill" data-archer-save-pill data-state="saved">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"></path></svg>
            <span class="archer-save-name"><?php echo esc_html($name); ?></span>
            <span class="archer-save-sep" aria-hidden="true">·</span>
            <span data-archer-save-text>automatisch bewaard</span>
          </span>
        <?php endif; ?>
        <a class="archer-toplink" href="<?php echo esc_url($dash); ?>">Mijn websites</a>
        <?php if ($initials) : ?>
          <span class="archer-avatar" title="<?php echo esc_attr($user->display_name); ?>" aria-hidden="true"><?php echo esc_html($initials); ?></span>
        <?php endif; ?>
      </div>
    </header>
    <?php
    return (string) ob_get_clean();
  }

  public static function render_stepbar(int $step, string $view, int $project_id, int $sitemap_id): string {
    $current = self::current_step_key($step, $view);
    if ($current === 'dashboard') return '';
    $steps = self::steps();
    $idx = 0;
    foreach ($steps as $i => $s) if ($s['key'] === $current) $idx = $i;

    ob_start(); ?>
    <nav class="archer-stepbar" aria-label="Stappen" data-archer-stepbar>
      <?php foreach ($steps as $i => $s) :
        $state = $i < $idx ? 'done' : ($i === $idx ? 'current' : 'open');
        $needs_project = $s['key'] !== 'sitemap';
        $disabled = $needs_project && (!$project_id || !$sitemap_id);
        $url = self::step_url($s, $project_id, $sitemap_id);
        ?>
        <?php if ($i > 0) : ?><span class="archer-step-line" aria-hidden="true"></span><?php endif; ?>
        <a class="archer-step is-<?php echo esc_attr($state); ?><?php echo $disabled ? ' is-disabled' : ''; ?>"
           href="<?php echo $disabled ? '#' : esc_url($url); ?>"
           data-archer-step="<?php echo esc_attr($s['key']); ?>"
           data-step="<?php echo (int) $s['step']; ?>"
           data-view="<?php echo esc_attr($s['view']); ?>"
           <?php echo $state === 'current' ? 'aria-current="step"' : ''; ?>
           <?php echo $disabled ? 'aria-disabled="true" tabindex="-1"' : ''; ?>>
          <span class="archer-step-dot" aria-hidden="true">
            <?php if ($state === 'done') : ?>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
            <?php else : echo (int) ($i + 1); endif; ?>
          </span>
          <span class="archer-step-label"><?php echo esc_html($s['label']); ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <?php
    return (string) ob_get_clean();
  }

  /** Paginatitel per stap, in de stijl van het prototype. */
  public static function render_page_head(string $key): string {
    $heads = [
      'sitemap'    => ['Je sitemap', "Dit zijn de pagina's van de website en de secties op elke pagina. Voeg secties of pagina's toe en schrap wat niet nodig is. Alles wordt automatisch bewaard."],
      'wireframes' => ['Je wireframes', "Zo komen de pagina's eruit te zien, nog zonder kleur of foto's. Kies per sectie een ander voorbeeld, zet vast wat goed is of pas de tekst aan. Alles wordt automatisch bewaard."],
      'style'      => ['Kies je stijl', 'De inhoud blijft dezelfde. Wat je hier kiest, zie je meteen op echte secties van de website.'],
      'photos'     => ["Je foto's", "We plaatsten al passende stockfoto's van Unsplash. Vervang ze door eigen foto's wanneer je wil, ook na het publiceren."],
      'publish'    => ['Bijna klaar: publiceer de website', 'Bekijk de website nog eens volledig. Publiceer daarna op een eigen webadres bij Archer.'],
    ];
    if (!isset($heads[$key])) return '';
    [$title, $text] = $heads[$key];
    $n = 0;
    foreach (self::steps() as $i => $s) if ($s['key'] === $key) $n = $i + 1;

    ob_start(); ?>
    <div class="archer-page-head">
      <div class="archer-page-head-text">
        <span class="archer-eyebrow">Stap <?php echo (int) $n; ?> van 5</span>
        <h1 class="archer-h1"><?php echo esc_html($title); ?></h1>
        <p class="archer-lead"><?php echo esc_html($text); ?></p>
      </div>
      <div class="archer-page-head-aside" data-archer-head-aside></div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** Knoppen onderaan een stap: terug en verder. */
  public static function render_step_footer(string $key, int $project_id, int $sitemap_id, string $hint = ''): string {
    $steps = self::steps();
    $idx = 0;
    foreach ($steps as $i => $s) if ($s['key'] === $key) $idx = $i;
    $prev = $steps[$idx - 1] ?? null;
    $next = $steps[$idx + 1] ?? null;
    $next_labels = [
      'wireframes' => 'Verder naar wireframes',
      'style'      => 'Verder naar stijl',
      'photos'     => "Verder naar foto's",
      'publish'    => 'Verder naar publiceren',
    ];

    ob_start(); ?>
    <div class="archer-step-footer" data-archer-step-footer="<?php echo esc_attr($key); ?>">
      <?php if ($prev) : ?>
        <a class="archer-link-back" href="<?php echo esc_url(self::step_url($prev, $project_id, $sitemap_id)); ?>" data-archer-nav>← Terug naar <?php echo esc_html(mb_strtolower($prev['label'])); ?></a>
      <?php else : ?>
        <span></span>
      <?php endif; ?>
      <div class="archer-step-footer-right">
        <?php if ($hint) : ?><span class="archer-muted"><?php echo esc_html($hint); ?></span><?php endif; ?>
        <?php if ($next) : ?>
          <a class="archer-btn archer-btn-primary" href="<?php echo esc_url(self::step_url($next, $project_id, $sitemap_id)); ?>" data-archer-next data-archer-nav><?php echo esc_html($next_labels[$next['key']] ?? 'Verder'); ?> →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /* ───────────────────────── Dashboard ───────────────────────── */

  /** Status per project voor het dashboard. */
  public static function project_status(int $pid, array $wf_counts): array {
    $sitemap_id = (int) get_post_meta($pid, 'aisb_latest_sitemap_id', true);
    $guide_raw = (string) get_post_meta($pid, 'aisb_style_guide', true);
    $guide = $guide_raw ? json_decode($guide_raw, true) : [];
    if (!is_array($guide)) $guide = [];
    $has_style = !empty($guide['colours']) || !empty($guide['headingFont']);
    $img_count = (is_array($guide['images'] ?? null) ? count($guide['images']) : 0)
               + (is_array($guide['uploadedImages'] ?? null) ? count($guide['uploadedImages']) : 0);
    $pages = 0;
    if ($sitemap_id) {
      $data = json_decode((string) get_post_meta($sitemap_id, 'aisb_sitemap_json', true), true);
      $pages = is_array($data['sitemap'] ?? null) ? count($data['sitemap']) : 0;
    }
    $published = (string) get_post_meta($pid, 'aisb_published_url', true);

    $done = [
      'sitemap'    => $sitemap_id > 0,
      'wireframes' => ($wf_counts[$pid] ?? 0) > 0,
      'style'      => $has_style,
      'photos'     => $img_count > 0,
      'publish'    => $published !== '',
    ];
    $next = 'publish';
    foreach (self::steps() as $s) {
      if (empty($done[$s['key']])) { $next = $s['key']; break; }
    }

    return [
      'sitemap_id' => $sitemap_id,
      'pages'      => $pages,
      'done'       => $done,
      'next'       => $next,
      'published'  => $published,
      'published_at' => (string) get_post_meta($pid, 'aisb_published_at', true),
    ];
  }

  public static function render_dashboard(): string {
    if (!is_user_logged_in()) {
      return '<div class="archer-empty"><p>Log in om je websites te zien.</p></div>';
    }

    $is_staff = current_user_can('manage_options');
    $q = new WP_Query([
      'post_type'      => 'aisb_project',
      'post_status'    => 'publish',
      'posts_per_page' => 100,
      'orderby'        => 'modified',
      'order'          => 'DESC',
      'author'         => get_current_user_id(),
      'fields'         => 'ids',
      'meta_query'     => [
        'relation' => 'OR',
        ['key' => 'aisb_project_soft_deleted', 'compare' => 'NOT EXISTS'],
        ['key' => 'aisb_project_soft_deleted', 'value' => '1', 'compare' => '!='],
      ],
    ]);
    $ids = array_map('intval', $q->posts);

    // Wireframes per project in een query.
    $wf_counts = [];
    if ($ids) {
      global $wpdb;
      $in = implode(',', $ids);
      $rows = $wpdb->get_results(
        "SELECT pm.meta_value AS pid, COUNT(*) AS n FROM {$wpdb->postmeta} pm
         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_aisb_project_id' AND p.post_type = 'ai_wireframe' AND pm.meta_value IN ($in)
         GROUP BY pm.meta_value"
      );
      foreach ($rows as $r) $wf_counts[(int) $r->pid] = (int) $r->n;
    }

    $new_url = add_query_arg(['aisb_step' => 1], self::base_url());
    $steps = self::steps();

    ob_start(); ?>
    <div class="archer-dashboard">
      <div class="archer-page-head">
        <div class="archer-page-head-text">
          <h1 class="archer-h1">Mijn websites</h1>
          <p class="archer-lead">Ga verder waar je gebleven was, of start een nieuwe website.</p>
        </div>
        <div class="archer-page-head-aside">
          <a class="archer-btn archer-btn-primary" href="<?php echo esc_url($new_url); ?>">+ Nieuwe website</a>
        </div>
      </div>

      <?php if (!$ids) : ?>
        <div class="archer-empty">
          <h2 class="archer-h2">Nog geen websites</h2>
          <p>Beschrijf een zaak of plak het adres van een bestaande website. De sitemap staat er in enkele minuten.</p>
          <a class="archer-btn archer-btn-primary" href="<?php echo esc_url($new_url); ?>">Start een website →</a>
        </div>
      <?php else : ?>
        <div class="archer-site-grid">
          <?php foreach ($ids as $pid) :
            $st = self::project_status($pid, $wf_counts);
            $title = get_the_title($pid) ?: ('Project #' . $pid);
            $brief = wp_strip_all_tags((string) get_post_meta($pid, 'aisb_project_brief', true));
            $brief = trim(preg_replace('/\s+/', ' ', $brief));
            if (mb_strlen($brief) > 160) $brief = mb_substr($brief, 0, 157) . '…';
            $next_step = null;
            foreach ($steps as $s) if ($s['key'] === $st['next']) $next_step = $s;
            $continue_url = self::step_url($next_step ?: $steps[0], $pid, $st['sitemap_id']);
            $done_count = count(array_filter($st['done']));
            $modified = get_post_modified_time('U', true, $pid);
            ?>
            <article class="archer-site-card" data-project-card="<?php echo (int) $pid; ?>">
              <div class="archer-site-card-top">
                <span class="archer-chip <?php echo $st['published'] ? 'is-online' : 'is-draft'; ?>">
                  <?php echo $st['published'] ? 'Online' : 'In opbouw'; ?>
                </span>
                <span class="archer-muted archer-small">Bewerkt <?php echo esc_html(self::time_ago((int) $modified)); ?></span>
              </div>
              <h2 class="archer-site-card-title"><?php echo esc_html($title); ?></h2>
              <?php if ($brief) : ?><p class="archer-site-card-brief"><?php echo esc_html($brief); ?></p><?php endif; ?>

              <ol class="archer-progress" aria-label="Voortgang: <?php echo (int) $done_count; ?> van 5 stappen klaar">
                <?php foreach ($steps as $s) : $ok = !empty($st['done'][$s['key']]); ?>
                  <li class="<?php echo $ok ? 'is-done' : ''; ?>">
                    <a href="<?php echo esc_url(self::step_url($s, $pid, $st['sitemap_id'])); ?>"><?php echo esc_html($s['label']); ?></a>
                  </li>
                <?php endforeach; ?>
              </ol>

              <?php if ($st['published']) : ?>
                <p class="archer-small archer-site-url">
                  <a href="<?php echo esc_url($st['published']); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', untrailingslashit($st['published']))); ?></a>
                </p>
              <?php endif; ?>

              <div class="archer-site-card-actions">
                <a class="archer-btn archer-btn-primary" href="<?php echo esc_url($continue_url); ?>">
                  <?php echo $st['published'] ? 'Website bewerken' : 'Verder bouwen'; ?> →
                </a>
                <?php if ($st['published']) : ?>
                  <a class="archer-btn archer-btn-outline" href="<?php echo esc_url($st['published']); ?>" target="_blank" rel="noopener">Bekijk website</a>
                <?php endif; ?>
                <button type="button" class="archer-icon-btn aisb-delete-project-btn" data-project-id="<?php echo (int) $pid; ?>" aria-label="Verwijder <?php echo esc_attr($title); ?>" title="Verwijderen">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"></path><path d="M8 6V4h8v2"></path><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                </button>
              </div>

              <?php if ($is_staff && $st['sitemap_id']) : ?>
                <details class="archer-staff-only archer-versions">
                  <summary>Sitemapversies (Archer-team)</summary>
                  <?php
                  $versions = get_posts([
                    'post_type' => 'aisb_sitemap', 'posts_per_page' => 20, 'fields' => 'ids',
                    'meta_key' => 'aisb_sitemap_version', 'orderby' => 'meta_value_num', 'order' => 'DESC',
                    'meta_query' => [['key' => 'aisb_project_id', 'value' => $pid]],
                  ]);
                  foreach ($versions as $vid) :
                    $v = (int) get_post_meta($vid, 'aisb_sitemap_version', true);
                    $label = (string) get_post_meta($vid, 'aisb_sitemap_label', true);
                    $vurl = add_query_arg(['aisb_step' => 1, 'aisb_project' => $pid, 'aisb_sitemap' => $vid], self::base_url());
                    ?>
                    <a href="<?php echo esc_url($vurl); ?>">v<?php echo $v; ?><?php echo $label ? ' · ' . esc_html($label) : ''; ?></a>
                  <?php endforeach; ?>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /** "3 uur geleden", los van de taal van de site. */
  public static function time_ago(int $ts): string {
    $d = max(0, time() - $ts);
    if ($d < 90) return 'net';
    $units = [[86400 * 30, 'maand', 'maanden'], [86400 * 7, 'week', 'weken'], [86400, 'dag', 'dagen'], [3600, 'uur', 'uur'], [60, 'minuut', 'minuten']];
    foreach ($units as [$sec, $one, $many]) {
      if ($d >= $sec) {
        $n = (int) floor($d / $sec);
        return $n . ' ' . ($n === 1 ? $one : $many) . ' geleden';
      }
    }
    return 'net';
  }

  /* ───────────────────────── Publiceren: samenvatting ───────────────────────── */

  public static function render_publish_summary(int $project_id, int $sitemap_id): string {
    $pages = 0; $sections = 0;
    if ($sitemap_id) {
      $data = json_decode((string) get_post_meta($sitemap_id, 'aisb_sitemap_json', true), true);
      foreach ((array) ($data['sitemap'] ?? []) as $p) {
        $pages++;
        $sections += is_array($p['sections'] ?? null) ? count($p['sections']) : 0;
      }
    }
    $guide = json_decode((string) get_post_meta($project_id, 'aisb_style_guide', true), true);
    if (!is_array($guide)) $guide = [];
    $font = (string) ($guide['headingFont'] ?? '');
    $colours = is_array($guide['colours'] ?? null) ? array_slice($guide['colours'], 0, 5) : [];
    $imgs = (is_array($guide['images'] ?? null) ? count($guide['images']) : 0);
    $own = (is_array($guide['uploadedImages'] ?? null) ? count($guide['uploadedImages']) : 0);
    $published = (string) get_post_meta($project_id, 'aisb_published_url', true);
    $steps = self::steps();

    ob_start(); ?>
    <div class="archer-summary-grid">
      <div class="archer-summary-card">
        <span class="archer-summary-label">Sitemap en wireframes</span>
        <strong><?php echo (int) $pages; ?> pagina's</strong>
        <span class="archer-muted"><?php echo (int) $sections; ?> secties</span>
        <a href="<?php echo esc_url(self::step_url($steps[1], $project_id, $sitemap_id)); ?>">Wijzigen</a>
      </div>
      <div class="archer-summary-card">
        <span class="archer-summary-label">Stijl</span>
        <strong><?php echo $font ? esc_html($font) : 'Nog niet gekozen'; ?></strong>
        <span class="archer-swatches" aria-label="Kleuren">
          <?php foreach ($colours as $c) : $hex = preg_replace('/[^#0-9a-fA-F]/', '', (string) ($c['hex'] ?? '')); if (!$hex) continue; ?>
            <span style="background: <?php echo esc_attr($hex); ?>" title="<?php echo esc_attr($hex); ?>"></span>
          <?php endforeach; ?>
        </span>
        <a href="<?php echo esc_url(self::step_url($steps[2], $project_id, $sitemap_id)); ?>">Wijzigen</a>
      </div>
      <div class="archer-summary-card">
        <span class="archer-summary-label">Foto's</span>
        <strong><?php echo (int) ($imgs + $own); ?> foto's</strong>
        <span class="archer-muted"><?php echo (int) $own; ?> eigen · <?php echo (int) $imgs; ?> stock</span>
        <a href="<?php echo esc_url(self::step_url($steps[3], $project_id, $sitemap_id)); ?>">Wijzigen</a>
      </div>
      <div class="archer-summary-card <?php echo $published ? 'is-online' : ''; ?>" data-archer-published>
        <span class="archer-summary-label">Webadres</span>
        <?php if ($published) : ?>
          <strong>Online</strong>
          <a href="<?php echo esc_url($published); ?>" target="_blank" rel="noopener"><?php echo esc_html(preg_replace('#^https?://#', '', untrailingslashit($published))); ?></a>
        <?php else : ?>
          <strong>Nog niet gepubliceerd</strong>
          <span class="archer-muted">Na publiceren staat het adres hier.</span>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  private function check(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Niet ingelogd.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
  }

  private function can_access_project(int $project_id): bool {
    $p = get_post($project_id);
    if (!$p || $p->post_type !== 'aisb_project') return false;
    if (current_user_can('manage_options')) return true;
    return (int) $p->post_author === (int) get_current_user_id();
  }

  /**
   * Bewaart wijzigingen aan de sitemap in de huidige versie, zonder telkens een
   * nieuwe versie aan te maken. Wireframes blijven zo aan dezelfde sitemap-ID hangen.
   */
  public function ajax_autosave_sitemap(): void {
    $this->check();
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $sitemap_id = isset($_POST['sitemap_id']) ? (int) $_POST['sitemap_id'] : 0;
    if (!$project_id || !$sitemap_id) wp_send_json_error(['message' => 'Project of sitemap ontbreekt.'], 400);
    if (!$this->can_access_project($project_id)) wp_send_json_error(['message' => 'Geen toegang.'], 403);

    $sitemap = get_post($sitemap_id);
    if (!$sitemap || $sitemap->post_type !== 'aisb_sitemap' || (int) get_post_meta($sitemap_id, 'aisb_project_id', true) !== $project_id) {
      wp_send_json_error(['message' => 'Sitemap hoort niet bij dit project.'], 400);
    }

    $raw = isset($_POST['sitemap_json']) ? wp_unslash($_POST['sitemap_json']) : '';
    $data = json_decode($raw, true);
    if (!is_array($data) || !is_array($data['sitemap'] ?? null)) wp_send_json_error(['message' => 'Ongeldige sitemap.'], 400);

    // Dezelfde regels als bij een gewone versie.
    if (class_exists('AISB_Enforcer') && class_exists('AISB_Prompts')) {
      $enforcer = new AISB_Enforcer(new AISB_Prompts());
      if (method_exists($enforcer, 'enforce_rules_on_data')) {
        $data = $enforcer->enforce_rules_on_data($data);
      }
    }

    // Header altijd eerst, footer altijd laatst.
    if (class_exists('AISB_Archer_Sitemap_Rules')) {
      foreach ($data['sitemap'] as $i => $page) {
        if (is_array($page) && is_array($page['sections'] ?? null)) {
          $data['sitemap'][$i]['sections'] = AISB_Archer_Sitemap_Rules::order_sections($page['sections']);
        }
      }
    }

    // wp_slash: update_post_meta haalt backslashes weg, wat \u00eb en \" in JSON kapotmaakt.
    update_post_meta($sitemap_id, 'aisb_sitemap_json', wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    update_post_meta($sitemap_id, 'aisb_sitemap_status', 'edited');
    update_post_meta($project_id, 'aisb_project_updated_at', gmdate('c'));
    if (!empty($data['website_name']) && is_string($data['website_name'])) {
      // Projectnaam volgt de naam van de website zolang die niet manueel is gewijzigd.
      $current = get_the_title($project_id);
      if ($current === '' || $current === 'New project') {
        wp_update_post(['ID' => $project_id, 'post_title' => sanitize_text_field($data['website_name'])]);
      }
    }
    wp_update_post(['ID' => $project_id]); // werkt de wijzigingsdatum bij voor het dashboard

    wp_send_json_success(['saved_at' => gmdate('c'), 'pages' => count($data['sitemap'])]);
  }

  /**
   * Leest een bestaande website en geeft de tekst terug als brief.
   * Alleen http(s), via wp_safe_remote_get (geen interne adressen).
   */
  public function ajax_site_brief(): void {
    $this->check();
    $url = isset($_POST['url']) ? esc_url_raw(trim(wp_unslash($_POST['url']))) : '';
    if ($url && !preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    if (!$url || !wp_http_validate_url($url)) wp_send_json_error(['message' => 'Dat adres kunnen we niet lezen. Controleer het en probeer opnieuw.'], 400);

    $res = wp_safe_remote_get($url, [
      'timeout'     => 15,
      'redirection' => 3,
      'user-agent'  => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)',
      'limit_response_size' => 2 * 1024 * 1024,
    ]);
    if (is_wp_error($res)) wp_send_json_error(['message' => 'De website reageert niet: ' . $res->get_error_message()], 502);
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code >= 400) wp_send_json_error(['message' => 'De website gaf foutcode ' . $code . '.'], 502);

    $html = (string) wp_remote_retrieve_body($res);
    $brief = self::extract_brief($html, $url);

    // Volledige paginastructuur van de site, voor het overnemen in de nieuwe sitemap.
    @set_time_limit(90);
    $crawl = AISB_Archer_Sitemap_Rules::crawl_site($url, $html);
    $brief['pages'] = $crawl['pages'];
    $brief['collapsed'] = $crawl['collapsed'];
    if ($crawl['pages']) {
      AISB_Archer_Sitemap_Rules::remember_source($url, $crawl['pages'], $crawl['collapsed'], ['brief' => $brief['text'], 'title' => $brief['title']]);
    }

    if (mb_strlen($brief['text']) < 80 && !$crawl['pages']) {
      wp_send_json_error(['message' => 'We vonden te weinig tekst op die pagina. Beschrijf de zaak liever zelf.'], 422);
    }
    wp_send_json_success($brief);
  }

  /** Haalt titel, beschrijving, menu, koppen en lopende tekst uit HTML. */
  public static function extract_brief(string $html, string $url): array {
    $title = ''; $desc = ''; $nav = []; $heads = []; $paras = [];
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $title = trim(html_entity_decode(wp_strip_all_tags($m[1])));
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)#i', $html, $m)) $desc = trim(html_entity_decode($m[1]));

    $clean = preg_replace('#<(script|style|noscript|svg|iframe)[^>]*>.*?</\1>#is', ' ', $html);

    if (preg_match_all('#<nav[^>]*>(.*?)</nav>#is', $clean, $navs)) {
      foreach ($navs[1] as $n) {
        if (preg_match_all('#<a[^>]*>(.*?)</a>#is', $n, $links)) {
          foreach ($links[1] as $l) {
            $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($l))));
            if ($t !== '' && mb_strlen($t) < 40) $nav[$t] = true;
          }
        }
      }
    }
    if (preg_match_all('#<h([1-3])[^>]*>(.*?)</h\1>#is', $clean, $hs)) {
      foreach ($hs[2] as $h) {
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($h))));
        if ($t !== '') $heads[$t] = true;
      }
    }
    if (preg_match_all('#<(p|li)[^>]*>(.*?)</\1>#is', $clean, $ps)) {
      foreach ($ps[2] as $p) {
        $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($p))));
        if (mb_strlen($t) >= 25) $paras[$t] = true;
      }
    }
    // Contactgegevens die vaak in de footer staan.
    $plain = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($clean))));
    $contact = [];
    if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $plain, $em)) $contact = array_merge($contact, array_slice(array_unique($em[0]), 0, 2));
    if (preg_match_all('/(?:\+32|0)[\s\d\/.]{8,14}\d/', $plain, $ph)) $contact = array_merge($contact, array_slice(array_unique(array_map('trim', $ph[0])), 0, 2));
    if (preg_match('/BE\s?0?\d{3}[.\s]?\d{3}[.\s]?\d{3}/i', $plain, $vat)) $contact[] = 'Btw: ' . $vat[0];

    $text = "Bestaande website: {$url}\n";
    if ($title) $text .= "Titel: {$title}\n";
    if ($desc) $text .= "Beschrijving: {$desc}\n";
    if ($nav) $text .= "Menu: " . implode(' | ', array_slice(array_keys($nav), 0, 20)) . "\n";
    if ($contact) $text .= "Contact: " . implode(' | ', $contact) . "\n";
    if ($heads) $text .= "\nKoppen:\n- " . implode("\n- ", array_slice(array_keys($heads), 0, 40)) . "\n";
    if ($paras) $text .= "\nTekst:\n" . implode("\n", array_slice(array_keys($paras), 0, 60)) . "\n";
    $text = mb_substr($text, 0, 7000);

    return ['url' => $url, 'title' => $title, 'text' => $text, 'pages_in_menu' => count($nav)];
  }

  /**
   * Bewaart het webadres van een geslaagde publicatie op het project.
   * Wordt aangeroepen vanuit AISB_InstaWP::ajax_publish_site.
   */
  public static function remember_publish(int $project_id, $result): void {
    if (!$project_id || !is_array($result)) return;
    $url = '';
    foreach (['wp_url', 'site_url', 'url'] as $k) {
      if (!empty($result[$k]) && is_string($result[$k])) { $url = $result[$k]; break; }
    }
    if (!$url && is_array($result['site'] ?? null) && !empty($result['site']['site_url'])) $url = (string) $result['site']['site_url'];
    if (!$url) return;
    // Nooit een login-URL bewaren, alleen de basis van de site.
    $parts = wp_parse_url($url);
    if (empty($parts['host'])) return;
    $base = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/';
    update_post_meta($project_id, 'aisb_published_url', esc_url_raw($base));
    update_post_meta($project_id, 'aisb_published_at', gmdate('c'));
  }
}
