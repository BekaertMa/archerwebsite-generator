<?php

if (!defined('ABSPATH')) exit;

/**
 * Laadt en rendert de frontend van de AI Sitemap Builder.
 *
 * Deze klasse heeft drie hoofdtaken:
 * - shortcodes tonen voor de builder en de projectlijst;
 * - de juiste HTML per stap opbouwen;
 * - CSS en JavaScript alleen laden wanneer ze nodig zijn.
 */
class AISB_Assets {

  /**
   * Houdt bij of de assets in deze request al zijn toegevoegd.
   */
  private static bool $assets_enqueued = false;

  /**
   * Rendert de shortcode [my-projects].
   *
   * Wat deze shortcode doet:
   * - toont de projecten van de ingelogde gebruiker;
   * - toont per project de opgeslagen sitemapversies;
   * - maakt links naar de builder of wireframes met de juiste GET-parameters.
   *
   * Shortcode-attributen:
   * - builder_url: pagina met [ai_sitemap_builder]. Leeg betekent huidige pagina.
   * - wireframes_url: pagina met wireframes. Leeg gebruikt dezelfde pagina als builder_url.
   * - title: titel boven de projectlijst.
   * - target_step: optionele stap waarnaar een projectlink direct moet openen.
   */
  public function render_my_projects_shortcode($atts = [], $content = null) {
    // Alleen ingelogde gebruikers mogen hun eigen projecten bekijken.
    if (!is_user_logged_in()) {
      return '<div class="aisb-wrap"><div class="aisb-card"><p>You must be logged in to view your projects.</p></div></div>';
    }

    // Lees de shortcode-instellingen en vul eenvoudige standaardwaarden in.
    $atts = shortcode_atts([
      'builder_url'  => '',
      'wireframes_url' => '',
      'title'        => 'My Projects',
      'target_step'  => 0,
    ], $atts);

    // Bepaal de URL van de huidige pagina. Die gebruiken we als er geen URL is meegegeven.
    $current_url = '';
    if (function_exists('home_url')) {
      $scheme = is_ssl() ? 'https' : 'http';
      $current_url = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? ''), $scheme);
    }

    // Zonder expliciete builder_url wijst de projectlink terug naar deze pagina.
    $builder_url = trim((string) $atts['builder_url']);
    if ($builder_url === '') {
      $builder_url = $current_url;
    }

    $wireframes_url = trim((string) $atts['wireframes_url']);
    if ($wireframes_url === '') {
      // Standaard gebruiken wireframes dezelfde pagina als de builder.
      $wireframes_url = $builder_url;
    }

    // Verwijder oude AISB-parameters, zodat links niet steeds langer worden.
    $builder_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $builder_url);
    $wireframes_url = remove_query_arg(['aisb_project', 'aisb_sitemap', 'aisb_version', 'aisb_step'], $wireframes_url);


    // Haal alleen de projecten op van de huidige gebruiker.
    $projects_q = new WP_Query([
      'post_type'      => 'aisb_project',
      'post_status'    => 'publish',
      'posts_per_page' => 100,
      'orderby'        => 'date',
      'order'          => 'DESC',
      'author'         => get_current_user_id(),
      'fields'         => 'ids',
    ]);

    $project_ids = $projects_q->posts;

    // Haal alle sitemapversies in een query op. Dat is sneller dan een query per project.
    $versions_by_project = [];
    if (!empty($project_ids)) {
      $sitemaps_q = new WP_Query([
        'post_type'      => 'aisb_sitemap',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'orderby'        => 'meta_value_num',
        'order'          => 'DESC',
        'meta_key'       => 'aisb_sitemap_version',
        'meta_query'     => [
          [
            'key'     => 'aisb_project_id',
            'value'   => array_map('intval', $project_ids),
            'compare' => 'IN',
          ],
        ],
        'fields' => 'ids',
      ]);

      foreach ($sitemaps_q->posts as $sid) {
        $pid = (int) get_post_meta($sid, 'aisb_project_id', true);
        if (!$pid) continue;

        if (!isset($versions_by_project[$pid])) {
          $versions_by_project[$pid] = [];
        }

        $versions_by_project[$pid][] = [
          'id'        => (int) $sid,
          'version'   => (int) get_post_meta($sid, 'aisb_sitemap_version', true),
          'label'     => (string) get_post_meta($sid, 'aisb_sitemap_label', true),
          'status'    => (string) get_post_meta($sid, 'aisb_sitemap_status', true),
          'current'   => (int) get_post_meta($sid, 'aisb_sitemap_is_current', true) === 1,
          'createdAt' => get_post_time('U', true, $sid),
        ];
      }

      // Sorteer elke projectlijst opnieuw, zodat de nieuwste versie altijd eerst staat.
      foreach ($versions_by_project as $pid => $list) {
        usort($list, function($a, $b) {
          // Gebruik eerst het versienummer en daarna de aanmaakdatum als reserve.
          if ((int)$a['version'] !== (int)$b['version']) {
            return ((int)$b['version'] <=> (int)$a['version']);
          }
          return ((int)$b['createdAt'] <=> (int)$a['createdAt']);
        });
        $versions_by_project[$pid] = $list;
      }
    }

    // Bouw de projectkaarten en geef de HTML als shortcode-output terug.
    ob_start();
    ?>
    <div class="aisb-wrap">
      <div class="aisb-card">
        <?php if (!empty($atts['title'])) : ?>
          <h3 class="aisb-projects-title"><?php echo esc_html($atts['title']); ?></h3>
        <?php endif; ?>

        <?php if (empty($project_ids)) : ?>
          <p class="aisb-projects-empty">You don't have any projects yet. Generate a sitemap first to create your first project.</p>
        <?php else : ?>
          <div class="aisb-projects-grid">
            <?php foreach ($project_ids as $pid) : ?>
              <?php
                // Verzamel de gegevens die nodig zijn om een projectkaart te tonen.
                $title = get_the_title($pid);
                $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
                $versions = $versions_by_project[$pid] ?? [];
                $latest = $versions[0]['id'] ?? 0;
              ?>
              <div class="aisb-project-card">
                <div class="aisb-project-card-head">
                  <div>
                    <div class="aisb-project-card-title"><?php echo esc_html($title ?: ('Project #' . (int)$pid)); ?></div>
                    <?php if (!empty($brief)) : ?>
                      <div class="aisb-project-card-brief"><?php echo esc_html($brief); ?></div>
                    <?php endif; ?>
                  </div>
                  <?php if ($latest) : ?>
                    <?php
                      // Maak de knop naar de nieuwste sitemapversie of naar een vaste stap.
                      $target_step = (int) $atts['target_step'];
                      if ($target_step > 0) {
                        $latest_url = add_query_arg([
                          'aisb_project' => (int) $pid,
                          'aisb_sitemap' => (int) $latest,
                          'aisb_step'    => $target_step,
                        ], $builder_url);
                      } else {
                        $latest_url = add_query_arg([
                          'aisb_project' => (int) $pid,
                          'aisb_sitemap' => (int) $latest,
                        ], $builder_url);
                      }

                      $latest_wf_url = add_query_arg([
                        'aisb_project' => (int) $pid,
                        'aisb_sitemap' => (int) $latest,
                        'aisb_step'    => 2,
                      ], $wireframes_url);
                    ?>
                    <div class="aisb-project-card-actions">
                      <?php if ($target_step > 0) : ?>
                        <a class="aisb-btn" href="<?php echo esc_url($latest_url); ?>">Select &rarr;</a>
                      <?php else : ?>
                        <a class="aisb-btn-secondary" href="<?php echo esc_url($latest_url); ?>">Open sitemap</a>
                        <a class="aisb-btn" href="<?php echo esc_url($latest_wf_url); ?>">Wireframes</a>
                      <?php endif; ?>
                      <button class="aisb-btn-secondary aisb-delete-project-btn" data-project-id="<?php echo esc_attr($pid); ?>" title="Delete Project">🗑️</button>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="aisb-project-versions">
                  <div class="aisb-project-versions-label">Versions</div>
                  <?php if (empty($versions)) : ?>
                    <div class="aisb-project-versions-empty">No versions yet.</div>
                  <?php else : ?>
                    <div class="aisb-project-versions-list">
                      <?php foreach ($versions as $v) : ?>
                        <?php
                          // Bouw per versie een leesbaar label en de juiste openingslink.
                          $v_label = 'v' . (int) $v['version'];
                          if (!empty($v['label'])) $v_label .= ' · ' . $v['label'];
                          if (!empty($v['current'])) $v_label .= ' · current';
                          $target_step_v = (int) $atts['target_step'];
                          if ($target_step_v > 0) {
                            $v_url = add_query_arg([
                              'aisb_project' => (int) $pid,
                              'aisb_sitemap' => (int) $v['id'],
                              'aisb_step'    => $target_step_v,
                            ], $builder_url);
                          } else {
                            $v_url = add_query_arg([
                              'aisb_project' => (int) $pid,
                              'aisb_sitemap' => (int) $v['id'],
                            ], $builder_url);
                          }

                          $v_wf_url = add_query_arg([
                            'aisb_project' => (int) $pid,
                            'aisb_sitemap' => (int) $v['id'],
                            'aisb_step'    => 2,
                          ], $wireframes_url);
                        ?>
                        <span class="aisb-project-version-group">
                          <a href="<?php echo esc_url($v_url); ?>" class="aisb-project-version-link">
                            <?php echo esc_html($v_label); ?>
                          </a>
                          <?php if (!$target_step_v) : ?>
                            <a href="<?php echo esc_url($v_wf_url); ?>" title="Wireframes" class="aisb-project-version-wf">WF</a>
                          <?php endif; ?>
                        </span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php

    return ob_get_clean();
  }

  /**
   * Rendert de hoofdshortcode [ai_sitemap_builder].
   *
   * De shortcode toont een stappenbalk en daarna precies een actieve stap:
   * 0. Mijn projecten
   * 1. Sitemap genereren
   * 2. Wireframes
  * 3. Stijlgids
  * 4. Designvoorbeeld
   */
  public function render_shortcode($atts = [], $content = null) {
    // Lees de teksten die via shortcode-attributen overschreven kunnen worden.
    $atts = shortcode_atts([
      'title' => 'Sitemap Builder',
      'placeholder' => "Bijvoorbeeld: Peeters HVAC uit Deinze plaatst en onderhoudt warmtepompen en ventilatie voor particulieren. We willen meer offerteaanvragen en een pagina per dienst.",
      'button' => 'Genereer de sitemap',
    ], $atts);

    // De hoofdshortcode heeft altijd de frontend-assets nodig.
    $this->enqueue_assets_for_shortcode();
    if (class_exists('AISB_Archer_UI')) AISB_Archer_UI::enqueue();

    // De actieve stap komt uit de URL. Buiten bereik valt terug naar stap 1.
    $step = isset($_GET['aisb_step']) ? (int) $_GET['aisb_step'] : 1;
    if ($step < 0 || $step > 4) $step = 1;
    $view = isset($_GET['aisb_view']) ? sanitize_key(wp_unslash($_GET['aisb_view'])) : '';

    // Project en sitemap worden via de URL gedeeld tussen de stappen.
    $project_id = isset($_GET['aisb_project']) ? (int) $_GET['aisb_project'] : 0;
    $sitemap_id = isset($_GET['aisb_sitemap']) ? (int) $_GET['aisb_sitemap'] : 0;
    if ($project_id && !$sitemap_id) {
      $sitemap_id = (int) get_post_meta($project_id, 'aisb_latest_sitemap_id', true);
    }

    $base_url = AISB_Archer_UI::base_url();
    $ctx_url = add_query_arg(array_filter(['aisb_project' => $project_id, 'aisb_sitemap' => $sitemap_id]), $base_url);
    $dash_url = add_query_arg(['aisb_step' => 0], $base_url);
    $key = AISB_Archer_UI::current_step_key($step, $view);
    $is_staff = current_user_can('manage_options');

    ob_start(); ?>
      <div class="aisb-wrap archer-shell" data-aisb data-aisb-step="<?php echo esc_attr($step); ?>"
           data-archer-key="<?php echo esc_attr($key); ?>"
           <?php echo $project_id ? 'data-archer-has-project' : ''; ?>>

        <?php echo AISB_Archer_UI::render_topbar($project_id); ?>
        <?php echo AISB_Archer_UI::render_stepbar($step, $view, $project_id, $sitemap_id); ?>

        <div class="archer-main">

        <?php if ($step === 0) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="0">
          <?php echo AISB_Archer_UI::render_dashboard(); ?>
        </div><!-- /Stap 0-paneel -->
        <?php endif; ?>

        <div class="aisb-step-panel" data-aisb-step-panel="1" style="<?php echo $step === 1 ? '' : 'display:none;'; ?>">

        <div class="archer-start" data-archer-start>
          <div class="archer-start-intro">
            <span class="archer-eyebrow">Nieuwe website</span>
            <h1 class="archer-h1 archer-h1-xl">Beschrijf de zaak. De website staat er in enkele minuten.</h1>
            <p class="archer-lead">Typ wat de zaak doet of plak het adres van de huidige website. AI maakt eerst de sitemap. Layouts, teksten, stijl en foto's volgen per stap.</p>
          </div>

          <div class="aisb-card aisb-input-card archer-start-card">
            <div class="archer-seg" role="group" aria-label="Hoe wil je starten?">
              <button type="button" class="archer-seg-btn is-active" data-archer-mode="desc" aria-pressed="true">Beschrijf de website</button>
              <button type="button" class="archer-seg-btn" data-archer-mode="url" aria-pressed="false">Er is al een website</button>
            </div>

            <div class="archer-mode-panel" data-archer-mode-panel="desc">
              <label class="archer-sr-only" for="aisb-prompt">Beschrijving van de website</label>
              <textarea id="aisb-prompt" class="aisb-textarea archer-input" rows="6" maxlength="10000"
                placeholder="<?php echo esc_attr($atts['placeholder']); ?>"></textarea>
              <div class="archer-field-foot">
                <span class="archer-muted archer-small">Hoe meer je vertelt (diensten, regio, doelgroep, wat de website moet opleveren), hoe beter het voorstel.</span>
                <span data-aisb-counter class="aisb-counter archer-small">0 / 10000</span>
              </div>
            </div>

            <div class="archer-mode-panel" data-archer-mode-panel="url" hidden>
              <label class="aisb-label" for="archer-site-url">Adres van de huidige website</label>
              <input type="url" id="archer-site-url" class="archer-input" placeholder="https://www.voorbeeld.be" autocomplete="url">
              <p class="archer-muted archer-small">We lezen de huidige website en bouwen er een nieuwe versie van, met de eigen teksten, diensten en contactgegevens.</p>
              <label class="aisb-label" for="archer-site-extra">Wat moet er anders of beter? <span class="archer-muted">(optioneel)</span></label>
              <textarea id="archer-site-extra" class="archer-input" rows="3" placeholder="Bijvoorbeeld: meer offerteaanvragen, een pagina per dienst, een vacaturepagina."></textarea>
            </div>

            <details class="archer-more">
              <summary>Meer opties</summary>
              <div class="aisb-brief-grid">
                <div class="aisb-field">
                  <label class="aisb-label" for="aisb-languages">Taal van de website</label>
                  <select id="aisb-languages" class="aisb-select archer-input">
                    <option value="Dutch" selected>Nederlands</option>
                    <option value="French">Frans</option>
                    <option value="English">Engels</option>
                    <option value="German">Duits</option>
                  </select>
                </div>
                <div class="aisb-field">
                  <label class="aisb-label" for="aisb-pagecount">Aantal pagina's</label>
                  <select id="aisb-pagecount" class="aisb-select archer-input">
                    <option value="1">1</option>
                    <option value="2-5">2 tot 5</option>
                    <option value="5-10" selected>5 tot 10</option>
                    <option value="10-15">10 tot 15</option>
                    <option value="15+">Meer dan 15</option>
                  </select>
                </div>
              </div>
              <div class="aisb-field">
                <label class="aisb-label" for="aisb-pdf-upload">Briefing als pdf <span class="archer-muted">(de tekst komt bij de beschrijving)</span></label>
                <input type="file" id="aisb-pdf-upload" accept="application/pdf" class="aisb-file-input archer-input">
                <div id="aisb-pdf-status" class="archer-small" style="display:none;"></div>
              </div>
            </details>

            <button class="aisb-btn archer-btn archer-btn-primary archer-btn-block" type="button" data-aisb-generate>
              <?php echo esc_html($atts['button']); ?> →
            </button>
            <span data-aisb-demo-note class="aisb-demo-note archer-small" style="display:none;">
              Demomodus: stel een API-sleutel in onder Instellingen → AI Sitemap Builder.
            </span>
            <div class="aisb-status archer-status" data-aisb-status aria-live="polite"></div>
          </div>
        </div>

        <div class="aisb-card aisb-output-card archer-sitemap-card" data-aisb-output style="display:none;">
          <?php echo AISB_Archer_UI::render_page_head('sitemap'); ?>

          <div class="archer-toolbar">
            <div class="archer-seg archer-seg-sm" role="group" aria-label="Weergave">
              <button type="button" class="archer-seg-btn is-active" data-archer-sitemap-view="list" aria-pressed="true">Pagina's</button>
              <button type="button" class="archer-seg-btn" data-archer-sitemap-view="tree" aria-pressed="false">Boomstructuur</button>
            </div>
            <div class="aisb-actions archer-toolbar-actions">
              <button class="aisb-btn archer-btn archer-btn-primary" type="button" data-aisb-approve style="display:none;">
                Ziet er goed uit? Maak de secties →
              </button>
              <button class="aisb-btn-secondary archer-btn archer-btn-outline archer-tree-only" type="button" data-aisb-add-page>+ Pagina</button>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-tree-only" type="button" data-aisb-fit>Passend maken</button>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-tree-only" type="button" data-aisb-zoomout aria-label="Uitzoomen">−</button>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-tree-only" type="button" data-aisb-zoomin aria-label="Inzoomen">+</button>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-copy>JSON kopiëren</button>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-save>Nieuwe versie</button>
              <a class="aisb-btn" data-aisb-go-wireframes href="#" style="display:none;" hidden>Wireframes</a>
              <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-reset>Leegmaken</button>
            </div>
          </div>

          <div class="aisb-summary archer-staff-only" data-aisb-summary></div>

          <div class="archer-sitemap-list" data-archer-sitemap-list></div>

          <div class="aisb-workspace archer-tree-view" hidden>
            <div class="aisb-detail-panel" data-aisb-detail-panel>
              <div class="aisb-detail-head">
                <div class="aisb-detail-title" data-aisb-detail-title>Kies een pagina</div>
                <div class="aisb-detail-sub" data-aisb-detail-sub>Je ziet de secties en SEO van die pagina.</div>
              </div>
              <div class="aisb-detail-body" data-aisb-detail-body></div>
            </div>

            <div class="aisb-canvas-wrap">
              <div class="aisb-canvas" data-aisb-canvas>
                <div class="aisb-viewport" data-aisb-viewport>
                  <svg class="aisb-edges" data-aisb-edges aria-hidden="true"></svg>
                  <div class="aisb-nodes" data-aisb-nodes></div>
                </div>
                <div class="aisb-canvas-hint">Sleep de achtergrond om te verschuiven · scroll om te zoomen · sleep kaarten om ze te verplaatsen</div>
              </div>
            </div>
          </div>

          <details class="aisb-raw archer-staff-only">
            <summary>Ruwe JSON (Archer-team)</summary>
            <pre class="aisb-pre" data-aisb-raw></pre>
          </details>

          <?php echo AISB_Archer_UI::render_step_footer('sitemap', $project_id, $sitemap_id); ?>
        </div>
        </div><!-- /Stap 1-paneel -->

        <?php if ($step === 2) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="2">
          <?php echo AISB_Archer_UI::render_page_head('wireframes'); ?>
          <div class="aisb-card aisb-wireframes-card archer-wf-card" data-aisb-wireframes
               data-project-id="<?php echo esc_attr($project_id); ?>"
               data-sitemap-id="<?php echo esc_attr($sitemap_id); ?>">

            <?php if (!is_user_logged_in()) : ?>
              <p>Log in om de wireframes te gebruiken.</p>
            <?php elseif (!$project_id || !$sitemap_id) : ?>
              <div class="archer-empty">
                <p>Er is nog geen website gekozen. <a href="<?php echo esc_url($dash_url); ?>">Kies er een bij Mijn websites.</a></p>
              </div>
            <?php else : ?>

              <!-- Werkbalk -->
              <div class="aisb-wf-toolbar archer-toolbar">
                <div class="aisb-wf-toolbar-left archer-wf-tabs" data-archer-wf-tabs role="tablist" aria-label="Pagina's"></div>
                <div class="aisb-wf-toolbar-right archer-toolbar-actions">
                  <button class="aisb-btn archer-btn archer-btn-outline generate-wireframe__all" type="button" data-aisb-wf-generate-all>Alle pagina's opnieuw maken</button>
                  <button class="aisb-btn archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-wf-save-all>Alles bewaren</button>
                  <a href="<?php echo esc_url(add_query_arg(['aisb_step' => 3], $ctx_url)); ?>" class="aisb-btn styleguide-link__button" data-aisb-wf-style hidden>Stijl</a>
                </div>
              </div>

              <div class="aisb-wf-status archer-status" data-aisb-wf-status aria-live="polite"></div>

              <!-- Overzicht van alle pagina's -->
              <div class="aisb-wf-whiteboard" data-aisb-wf-whiteboard></div>

              <!-- Een pagina in detail -->
              <div class="aisb-wf-expanded archer-wf-expanded" data-aisb-wf-expanded>
                <div class="aisb-wf-expanded-head archer-wf-page-head">
                  <div>
                    <div class="aisb-wf-canvas-title archer-h2" data-aisb-wf-page-title></div>
                    <div class="aisb-wf-muted archer-muted" data-aisb-wf-page-sub></div>
                  </div>
                  <div class="aisb-wf-actions archer-toolbar-actions">
                    <button class="aisb-btn-secondary archer-btn archer-btn-outline" type="button" data-aisb-wf-generate>Pagina opnieuw maken</button>
                    <button class="aisb-btn-secondary archer-btn archer-btn-outline" type="button" data-aisb-wf-shuffle-page>Andere voorbeelden</button>
                    <button class="aisb-btn archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-wf-save>Bewaren</button>
                    <button class="aisb-btn-secondary archer-btn archer-btn-ghost archer-staff-only" type="button" data-aisb-wf-compile>JSON compileren</button>
                    <button class="aisb-btn-secondary archer-btn archer-btn-ghost" type="button" data-aisb-wf-close-expanded>Alle pagina's</button>
                  </div>
                </div>
                <div class="aisb-wf-sections" data-aisb-wf-sections></div>
                <div class="archer-wf-pager" data-archer-wf-pager></div>
                <details class="aisb-wf-raw archer-staff-only">
                  <summary>Gecompileerde Bricks-JSON (Archer-team)</summary>
                  <pre class="aisb-pre" data-aisb-wf-compiled></pre>
                </details>
              </div>

              <!-- Verborgen oude elementen voor bestaande JavaScript-koppelingen -->
              <div class="aisb-wf-legacy-pages" data-aisb-wf-pages></div>

              <!-- Templates die JavaScript kloont bij het opbouwen van de wireframe-ui -->
              <template data-tpl="page-card">
                <div class="aisb-wf-page-card" data-wb-page>
                  <div class="aisb-wf-page-card-head">
                    <div>
                      <div class="aisb-wf-page-card-title"></div>
                      <div class="aisb-wf-page-card-slug"></div>
                    </div>
                    <span class="aisb-wf-page-card-badge"></span>
                  </div>
                  <div class="aisb-wf-page-card-body">
                    <div class="aisb-wf-page-card-sections"></div>
                  </div>
                </div>
              </template>

              <template data-tpl="section-card">
                <div class="aisb-wf-section" data-uuid>
                  <div class="aisb-wf-section-toolbar">
                    <button type="button" class="aisb-wf-tbtn" data-act="up" title="Naar boven" aria-label="Sectie naar boven">↑</button>
                    <button type="button" class="aisb-wf-tbtn" data-act="down" title="Naar beneden" aria-label="Sectie naar beneden">↓</button>
                    <button type="button" class="aisb-wf-tbtn" data-act="shuffle" title="Ander voorbeeld">Ander voorbeeld</button>
                    <button type="button" class="aisb-wf-tbtn" data-act="lock" title="Vastzetten">Vastzetten</button>
                    <button type="button" class="aisb-wf-tbtn" data-act="edit" title="Tekst aanpassen">Tekst aanpassen</button>
                    <button type="button" class="aisb-wf-tbtn" data-act="dup" title="Dupliceren">Dupliceren</button>
                    <button type="button" class="aisb-wf-tbtn aisb-wf-tbtn-del" data-act="del" title="Verwijderen" aria-label="Sectie verwijderen">Verwijderen</button>
                  </div>
                  <div class="aisb-wf-body"></div>
                </div>
              </template>

              <template data-tpl="section-label">
                <div class="aisb-wf-section-label">
                  <span class="aisb-wf-section-label-type"></span>
                  <span class="aisb-wf-section-label-badge"></span>
                </div>
              </template>

            <?php endif; ?>
          </div>
          <?php if ($project_id && $sitemap_id) echo AISB_Archer_UI::render_step_footer('wireframes', $project_id, $sitemap_id, 'Tevreden over de opbouw? Kies nu kleuren en lettertypes.'); ?>
        </div><!-- /Stap 2-paneel -->
        <?php endif; ?>

        <?php if ($step === 3) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="3" data-archer-sg-view="<?php echo $view === 'photos' ? 'photos' : 'style'; ?>">
          <?php echo AISB_Archer_UI::render_page_head($view === 'photos' ? 'photos' : 'style'); ?>
          <div class="aisb-card archer-sg-card" data-styleguide
               data-styleguide-project="<?php echo esc_attr($project_id); ?>">
            <?php if (!is_user_logged_in()) : ?>
              <p>Log in om de stijl te kiezen.</p>
            <?php elseif (!$project_id) : ?>
              <div class="archer-empty">
                <p>Er is nog geen website gekozen. <a href="<?php echo esc_url($dash_url); ?>">Kies er een bij Mijn websites.</a></p>
              </div>
            <?php else : ?>
              <?php AISB_Style_Guide::render_style_guide_html($project_id); ?>
            <?php endif; ?>
          </div>
          <?php if ($project_id) echo AISB_Archer_UI::render_step_footer($view === 'photos' ? 'photos' : 'style', $project_id, $sitemap_id, $view === 'photos' ? "Geen eigen foto's bij de hand? Publiceer gerust met de stockfoto's." : ''); ?>
        </div><!-- /Stap 3-paneel -->
        <?php endif; ?>

        <?php if ($step === 4) : ?>
        <div class="aisb-step-panel" data-aisb-step-panel="4">
          <?php echo AISB_Archer_UI::render_page_head('publish'); ?>
          <?php if ($project_id) echo AISB_Archer_UI::render_publish_summary($project_id, $sitemap_id); ?>
          <div class="aisb-card archer-publish-card">
            <?php if (!is_user_logged_in()) : ?>
              <p>Log in om te publiceren.</p>
            <?php elseif (!$project_id) : ?>
              <div class="archer-empty">
                <p>Er is nog geen website gekozen. <a href="<?php echo esc_url($dash_url); ?>">Kies er een bij Mijn websites.</a></p>
              </div>
            <?php else : ?>
              <?php AISB_Design::render_design_html($project_id); ?>
            <?php endif; ?>
          </div>
          <?php if ($project_id) echo AISB_Archer_UI::render_step_footer('publish', $project_id, $sitemap_id); ?>
        </div><!-- /Stap 4-paneel -->
        <?php endif; ?>

        </div><!-- /.archer-main -->
      </div>
    <?php
    return ob_get_clean();
  }

  /**
  * Laadt bestanden op pagina's waar een frontend-shortcode staat.
   *
   * WordPress kan deze methode op elke pagina aanroepen. Daarom controleren we
   * eerst of de huidige pagina echt een AISB-shortcode bevat.
   */
  public function enqueue_assets() {
    if (!$this->current_page_has_shortcode('ai_sitemap_builder') && !$this->current_page_has_shortcode('my-projects')) return;
    $this->enqueue_assets_for_shortcode();
  }

  /**
   * Leest de plugininstellingen.
   *
   * Eerst proberen we de centrale settings-klasse. Bestaat die niet, dan lezen
   * we rechtstreeks uit de WordPress-opties.
   */
  private function get_settings(): array{
    // Gebruik de centrale settings-klasse wanneer die beschikbaar is.
    if (class_exists('AISB_Settings') && method_exists('AISB_Settings', 'get_settings')) {
        $settings = AISB_Settings::get_settings();
        return is_array($settings) ? $settings : [];
    }

    // Val terug op wp_options wanneer de settings-klasse niet geladen is.
    $settings = get_option(AISB_Plugin::OPT_KEY, []);
    return is_array($settings) ? $settings : [];
}


  /**
   * Registreert alle CSS en JavaScript voor de builder.
   *
   * De methode is idempotent: bij meerdere shortcodes op dezelfde pagina worden
  * de bestanden maar een keer toegevoegd.
   */
  private function enqueue_assets_for_shortcode() {
    if (self::$assets_enqueued) return;
    self::$assets_enqueued = true;

    $settings = $this->get_settings();

    // Laad de fonts die de frontend gebruikt.
    wp_enqueue_style(
      'aisb-google-fonts',
      'https://fonts.googleapis.com/css2?family=Archivo:ital,wdth,wght@0,62..125,100..900;1,62..125,100..900&family=Hind+Siliguri:wght@300;400;500;600;700&display=swap',
      [],
      null
    );

    // Laad de CSS-bestanden. Ze hebben onderling geen afhankelijkheden.
    $css_files = [
      'aisb-base'       => 'css/base.css',
      'aisb-forms'      => 'css/forms.css',
      'aisb-buttons'    => 'css/buttons.css',
      'aisb-output'     => 'css/output.css',
      'aisb-canvas'     => 'css/canvas.css',
      'aisb-node-cards' => 'css/node-cards.css',
      'aisb-sections'   => 'css/sections.css',
      'aisb-frontend'   => 'frontend.css',
    ];
    foreach ($css_files as $handle => $file) {
      wp_enqueue_style($handle, AISB_PLUGIN_URL . 'assets/' . $file, [], AISB_VERSION);
    }

    // Laad de JavaScript-bestanden in volgorde van afhankelijkheid.
    wp_enqueue_script(
      'aisb-app-init',
      AISB_PLUGIN_URL . 'assets/js/app-init.js',
      [],
      AISB_VERSION,
      true
    );

    // Geef PHP-data door aan JavaScript voordat de andere scripts starten.
    wp_localize_script('aisb-app-init', 'AISB', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(AISB_Plugin::NONCE_ACTION),
      'action'  => AISB_Plugin::AJAX_ACTION,
      'actionAddPage' => AISB_Plugin::AJAX_ADD_PAGE,
      'actionFillSections' => AISB_Plugin::AJAX_FILL_SECTIONS,
      'actionGetLatestSitemap' => AISB_Plugin::AJAX_GET_LATEST_SITEMAP,
      'actionGetSitemapById'   => AISB_Plugin::AJAX_GET_SITEMAP_BY_ID,
      'maxPromptChars' => 10000,
      'demoMode' => empty($settings['api_key']) ? 1 : 0,
      'sectionTypes' => $this->section_types(),
      'actionSaveVersion' => AISB_Plugin::AJAX_SAVE_SITEMAP_VERSION,
    ]);

    wp_enqueue_script(
      'aisb-app-utils',
      AISB_PLUGIN_URL . 'assets/js/app-utils.js',
      ['aisb-app-init'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-canvas',
      AISB_PLUGIN_URL . 'assets/js/app-canvas.js',
      ['aisb-app-utils'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-ui',
      AISB_PLUGIN_URL . 'assets/js/app-ui.js',
      ['aisb-app-canvas'],
      AISB_VERSION,
      true
    );

    wp_enqueue_script(
      'aisb-app-main',
      AISB_PLUGIN_URL . 'assets/js/app-main.js',
      ['aisb-app-ui'],
      AISB_VERSION,
      true
    );
  }

  /**
   * Controleert of de huidige WordPress-pagina een bepaalde shortcode bevat.
   */
  private function current_page_has_shortcode($shortcode) {
    if (!is_singular()) return false;
    global $post;
    if (!$post || empty($post->post_content)) return false;
    return has_shortcode($post->post_content, $shortcode);
  }

  /**
   * Geeft de toegestane sectietypes terug voor sitemap- en wireframegeneratie.
   */
  public function section_types():array {
    return AISB_Enforcer::section_types();
  }
}
