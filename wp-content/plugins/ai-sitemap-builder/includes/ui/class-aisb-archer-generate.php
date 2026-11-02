<?php
if (!defined('ABSPATH')) exit;

/**
 * Snelle sitemapgeneratie, zoals Relume.
 *
 * In plaats van één grote AI-vraag (die minuten duurt) in twee stappen:
 * 1. aisb_archer_plan: de paginastructuur. Bij een bestaande website komt die uit de
 *    gelezen site (geen AI nodig); bij een beschrijving uit een kleine AI-vraag.
 *    Het project en de sitemapversie worden meteen aangemaakt, zodat het canvas
 *    alle pagina's toont terwijl de secties nog geschreven worden.
 * 2. aisb_archer_sections: de secties voor een paar pagina's tegelijk. De browser
 *    stuurt meerdere van deze vragen parallel en vult het canvas pagina per pagina.
 *    Deze route bewaart niets; de autosave van de sitemap doet dat (één schrijver).
 */
class AISB_Archer_Generate {

  public function init(): void {
    add_action('wp_ajax_aisb_archer_plan', [$this, 'ajax_plan']);
    add_action('wp_ajax_aisb_archer_sections', [$this, 'ajax_sections']);
  }

  /* ───────────────────────── Hulp ───────────────────────── */

  private function check(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
  }

  private function can_access(int $project_id): bool {
    $p = get_post($project_id);
    if (!$p || $p->post_type !== 'aisb_project') return false;
    return (int) $p->post_author === get_current_user_id() || current_user_can('manage_options');
  }

  private function settings(): array {
    if (class_exists('AISB_Settings')) {
      $s = (new AISB_Settings())->get_settings();
      if (is_array($s)) return $s;
    }
    return [];
  }

  /** Roept een private methode van AISB_Ajax aan (project en sitemapversie aanmaken). */
  private function ajax_call(string $method, array $args) {
    $ref = new ReflectionClass('AISB_Ajax');
    $obj = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($obj, $args);
  }

  private function section_types(): array {
    static $types = null;
    if ($types !== null) return $types;
    $types = [];
    if (class_exists('AISB_Prompts')) {
      try {
        $m = new ReflectionMethod('AISB_Prompts', 'section_types');
        $m->setAccessible(true);
        $types = (array) $m->invoke(new AISB_Prompts());
      } catch (\Throwable $e) {
        $types = [];
      }
    }
    if (!$types) $types = ['Headers', 'Hero Sections', 'Content Sections', 'Feature Sections', 'CTA Sections', 'Contact Sections', 'FAQ Sections', 'Testimonial Sections', 'Footers'];
    return $types;
  }

  private function ai_json(string $system, string $user, int $timeout = 60) {
    $settings = $this->settings();
    if (empty($settings['api_key']) || !class_exists('AISB_OpenAI')) return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $settings['timeout'] = $timeout;
    $res = (new AISB_OpenAI())->call_openai_chat_completions($user, $settings, $system);
    if (is_wp_error($res)) return $res;
    $json = json_decode((string) $res, true);
    if (!is_array($json)) return new WP_Error('aisb_bad_json', 'De AI gaf geen geldig antwoord.');
    return $json;
  }

  private static function lang_name(array $languages): string {
    $map = ['Dutch' => 'Dutch (Belgian Dutch, Flemish)', 'French' => 'French', 'English' => 'English', 'German' => 'German'];
    $l = (string) ($languages[0] ?? 'Dutch');
    return $map[$l] ?? $l;
  }

  private static function guess_type(string $slug, string $title): string {
    $p = AISB_Archer_Sitemap_Rules::plain($slug . ' ' . $title);
    if ($slug === 'home') return 'Home';
    if (preg_match('/(^|-)(contact|kontakt)/', $p)) return 'Contact';
    if (preg_match('/(privacy|cookie|voorwaarden|terms|disclaimer|legal|impressum|mentions)/', $p)) return 'Legal';
    if (preg_match('/(^|-)(over-ons|over|about|wie-zijn-wij|ons-verhaal|team)(-|$)/', $p)) return 'About';
    if (preg_match('/(^|-)(blog|nieuws|news|inzichten|insights|artikels|kennisbank)(-|$)/', $p)) return 'Blog';
    if (AISB_Archer_Sitemap_Rules::collection_for(['slug' => $slug, 'page_title' => $title])) return 'Service';
    return 'Other';
  }

  /* ───────────────────────── 1. Structuur ───────────────────────── */

  public function ajax_plan(): void {
    $this->check();
    @set_time_limit(120);
    $mode = isset($_POST['mode']) && $_POST['mode'] === 'url' ? 'url' : 'desc';
    $prompt = isset($_POST['prompt']) ? trim((string) wp_unslash($_POST['prompt'])) : '';
    $url = isset($_POST['url']) ? esc_url_raw(trim((string) wp_unslash($_POST['url']))) : '';
    $languages = json_decode((string) wp_unslash($_POST['languages'] ?? '[]'), true);
    $languages = array_values(array_intersect(is_array($languages) ? $languages : [], ['Dutch', 'French', 'English', 'German']));
    if (!$languages) $languages = ['Dutch'];
    $page_count = sanitize_text_field((string) wp_unslash($_POST['page_count'] ?? '5-10'));
    if (!in_array($page_count, ['1', '2-5', '5-10', '10-15', '15+'], true)) $page_count = '5-10';

    if ($mode === 'url') {
      $src = AISB_Archer_Sitemap_Rules::get_source();
      if (!$src || empty($src['pages'])) wp_send_json_error(['message' => 'De gelezen website is niet meer beschikbaar. Probeer opnieuw.'], 400);
      $data = $this->plan_from_source($src);
      $brief = "Bestaande website: " . $src['url'] . "\n" . ($prompt !== '' ? "Wensen van de klant: " . $prompt . "\n" : '') . "\n" . (string) ($src['brief'] ?? '');
      $context_prompt = $brief;
    } else {
      if (mb_strlen($prompt) < 10) wp_send_json_error(['message' => 'Beschrijf de zaak iets uitgebreider.'], 400);
      $data = $this->plan_from_prompt($prompt, $languages, $page_count);
      if (is_wp_error($data)) wp_send_json_error(['message' => $data->get_error_message()], 502);
      $brief = $prompt;
      $context_prompt = $prompt;
    }

    // Archer-regels: contactpagina, detailpagina's bij overzichtspagina's, header eerst en footer laatst.
    $data = (new AISB_Archer_Sitemap_Rules())->apply_rules($data, ['prompt' => $mode === 'url' ? '' : $context_prompt, 'languages' => $languages]);
    foreach ($data['sitemap'] as $i => $p) {
      // Alles wat de regels toevoegden, krijgt ook AI-secties: leeg laten.
      $data['sitemap'][$i]['sections'] = [];
      if (empty($data['sitemap'][$i]['page_type'])) $data['sitemap'][$i]['page_type'] = self::guess_type((string) $p['slug'], (string) ($p['page_title'] ?? ''));
    }

    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if ($project_id && !$this->can_access($project_id)) $project_id = 0;
    if (!$project_id) {
      $project_id = $this->ajax_call('aisb_create_project', [sanitize_text_field((string) ($data['website_name'] ?? 'Nieuwe website')), mb_substr($brief, 0, 12000), $languages, $page_count]);
      if (is_wp_error($project_id)) wp_send_json_error(['message' => $project_id->get_error_message()], 500);
    } else {
      update_post_meta($project_id, 'aisb_project_brief', wp_kses_post(mb_substr($brief, 0, 12000)));
      update_post_meta($project_id, 'aisb_project_languages', wp_json_encode($languages));
    }
    $settings = $this->settings();
    $save = $this->ajax_call('aisb_create_sitemap_version', [(int) $project_id, $data, [
      'source' => 'ai',
      'label' => 'Sitemap generated',
      'prompt' => mb_substr($brief, 0, 12000),
      'model' => $settings['model'] ?? '',
      'endpoint' => $settings['endpoint'] ?? '',
      'temperature' => 0.4,
      'status' => 'generating',
    ]]);
    if (is_wp_error($save)) wp_send_json_error(['message' => $save->get_error_message()], 500);

    wp_send_json_success([
      'project_id' => (int) $project_id,
      'sitemap_id' => (int) $save['sitemap_id'],
      'version' => (int) $save['version'],
      'data' => $data,
    ]);
  }

  /** Structuur uit een gelezen website: alle pagina's, met hun ouders. Geen AI. */
  private function plan_from_source(array $src): array {
    $brief = (string) ($src['brief'] ?? '');
    $title = (string) ($src['title'] ?? '');
    $name = trim(preg_split('/\s+[\|\-–—·:]\s+/u', $title)[0] ?? '');
    if ($name === '') $name = preg_replace('/^www\./', '', (string) wp_parse_url((string) $src['url'], PHP_URL_HOST));
    $pages = [[
      'page_title' => 'Home', 'nav_label' => 'Home', 'slug' => 'home', 'page_type' => 'Home',
      'priority' => 'Core', 'parent_slug' => null, 'page_purpose' => '', 'sections' => [],
      'seo' => ['primary_keyword' => '', 'secondary_keywords' => [], 'meta_title' => $title, 'meta_description' => ''],
    ]];
    $collection = [];
    foreach ((array) ($src['collapsed'] ?? []) as $c) if (!empty($c['slug'])) $collection[$c['slug']] = (int) ($c['items'] ?? 0);
    foreach ((array) $src['pages'] as $p) {
      $t = (string) $p['title'];
      $row = [
        'page_title' => $t,
        'nav_label' => mb_strlen($t) > 28 ? mb_substr($t, 0, 26) . '…' : $t,
        'slug' => (string) $p['slug'],
        'page_type' => self::guess_type((string) $p['slug'], $t),
        'priority' => !empty($p['in_menu']) ? 'Core' : 'Support',
        'parent_slug' => (string) ($p['parent_slug'] ?: 'home'),
        'page_purpose' => '',
        'sections' => [],
        'seo' => ['primary_keyword' => '', 'secondary_keywords' => [], 'meta_title' => $t, 'meta_description' => ''],
        'archer_source_url' => (string) ($p['url'] ?? ''),
      ];
      if (isset($collection[$p['slug']])) $row['archer_collection'] = $collection[$p['slug']];
      $pages[] = $row;
    }
    return [
      'website_name' => $name,
      'website_goal' => '',
      'primary_audiences' => [],
      'sitemap' => $pages,
      'notes' => [],
      'archer_source' => ['url' => $src['url'], 'pages' => count($src['pages']), 'added' => 0, 'collapsed' => $src['collapsed'] ?? []],
    ];
  }

  /** Structuur uit een beschrijving: één kleine AI-vraag (enkel pagina's, geen secties). */
  private function plan_from_prompt(string $prompt, array $languages, string $page_count) {
    $lang = self::lang_name($languages);
    $system = "You are a senior information architect. Return ONLY valid JSON.\n"
      . "Create the page structure (no sections) of a small-business website from the brief.\n"
      . "JSON: {\"website_name\":\"\",\"website_goal\":\"\",\"primary_audiences\":[\"\"],\"sitemap\":[{\"page_title\":\"\",\"nav_label\":\"\",\"slug\":\"\",\"page_type\":\"Home|Service|About|Contact|Blog|Landing|Legal|Other\",\"priority\":\"Core|Support|Optional\",\"parent_slug\":\"string|null\",\"page_purpose\":\"one sentence\"}]}\n"
      . "Rules:\n"
      . "- Write everything in {$lang}.\n"
      . "- Exactly one Home page: slug \"home\", parent_slug null. Every other page has a parent_slug (\"home\" for menu pages).\n"
      . "- Always include a Contact page.\n"
      . "- Every overview page that lists several items (services, products, cars, projects, cases, vacancies, events, courses, team, blog) MUST have child pages: one per item known from the brief (max 6), otherwise one detail template page (for example \"Dienst (detailpagina)\").\n"
      . "- Respect the desired number of pages: {$page_count} (child pages count). Never invent facts that are not in the brief.\n"
      . "- Slugs are lowercase, hyphenated, unique, without leading slash. nav_label is 1–3 words.";
    $user = "Desired number of pages: {$page_count}\n\nBrief:\n" . mb_substr($prompt, 0, 9000);
    $json = $this->ai_json($system, $user, 60);
    if (is_wp_error($json)) return $json;
    $pages = [];
    $seen = [];
    foreach ((array) ($json['sitemap'] ?? []) as $p) {
      if (!is_array($p)) continue;
      $slug = sanitize_title((string) ($p['slug'] ?? $p['page_title'] ?? ''));
      if ($slug === '' || isset($seen[$slug])) continue;
      $seen[$slug] = true;
      $t = sanitize_text_field((string) ($p['page_title'] ?? $slug));
      $pages[] = [
        'page_title' => $t,
        'nav_label' => sanitize_text_field((string) ($p['nav_label'] ?? $t)),
        'slug' => $slug,
        'page_type' => sanitize_text_field((string) ($p['page_type'] ?? self::guess_type($slug, $t))),
        'priority' => sanitize_text_field((string) ($p['priority'] ?? 'Support')),
        'parent_slug' => $slug === 'home' ? null : (sanitize_title((string) ($p['parent_slug'] ?? 'home')) ?: 'home'),
        'page_purpose' => sanitize_text_field((string) ($p['page_purpose'] ?? '')),
        'sections' => [],
        'seo' => ['primary_keyword' => '', 'secondary_keywords' => [], 'meta_title' => $t, 'meta_description' => ''],
      ];
    }
    if (!isset($seen['home'])) {
      array_unshift($pages, ['page_title' => 'Home', 'nav_label' => 'Home', 'slug' => 'home', 'page_type' => 'Home', 'priority' => 'Core', 'parent_slug' => null, 'page_purpose' => '', 'sections' => [], 'seo' => []]);
    }
    // Ouders die niet bestaan: onder Home.
    foreach ($pages as $i => $p) {
      if ($p['slug'] !== 'home' && !isset($seen[$p['parent_slug']]) && $p['parent_slug'] !== 'home') $pages[$i]['parent_slug'] = 'home';
    }
    return [
      'website_name' => sanitize_text_field((string) ($json['website_name'] ?? 'Nieuwe website')),
      'website_goal' => sanitize_text_field((string) ($json['website_goal'] ?? '')),
      'primary_audiences' => array_values(array_map('sanitize_text_field', array_filter((array) ($json['primary_audiences'] ?? []), 'is_string'))),
      'sitemap' => $pages,
      'notes' => [],
    ];
  }

  /* ───────────────────────── 2. Secties per pagina ───────────────────────── */

  public function ajax_sections(): void {
    $this->check();
    @set_time_limit(120);
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    if (!$project_id || !$this->can_access($project_id)) wp_send_json_error(['message' => 'Geen toegang tot dit project.'], 403);

    // Licht misbruikslot: hooguit 400 sectievragen per 10 minuten per gebruiker.
    $rl = 'aisb_archer_sec_rl_' . get_current_user_id();
    $n = (int) get_transient($rl);
    if ($n > 400) wp_send_json_error(['message' => 'Even geduld: te veel vragen na elkaar.'], 429);
    set_transient($rl, $n + 1, 600);

    $pages = json_decode((string) wp_unslash($_POST['pages'] ?? '[]'), true);
    $outline = json_decode((string) wp_unslash($_POST['outline'] ?? '[]'), true);
    $site = json_decode((string) wp_unslash($_POST['site'] ?? '{}'), true);
    if (!is_array($pages) || !$pages || count($pages) > 5) wp_send_json_error(['message' => 'Ongeldige pagina’s.'], 400);

    $languages = json_decode((string) get_post_meta($project_id, 'aisb_project_languages', true), true);
    $lang = self::lang_name(is_array($languages) ? $languages : ['Dutch']);
    $brief = mb_substr((string) get_post_meta($project_id, 'aisb_project_brief', true), 0, 4500);
    $types = $this->section_types();

    $outline_lines = [];
    foreach ((array) $outline as $o) {
      if (!is_array($o)) continue;
      $outline_lines[] = '- ' . ($o['title'] ?? '') . ' (' . ($o['slug'] ?? '') . (isset($o['parent']) && $o['parent'] && $o['parent'] !== 'home' ? ', under ' . $o['parent'] : '') . ')';
    }
    $page_lines = [];
    foreach ($pages as $p) {
      if (!is_array($p) || empty($p['slug'])) continue;
      $children = array_filter((array) ($p['children'] ?? []), 'is_string');
      $page_lines[] = "- slug: " . sanitize_title((string) $p['slug']) . "\n  title: " . sanitize_text_field((string) ($p['title'] ?? ''))
        . "\n  type: " . sanitize_text_field((string) ($p['page_type'] ?? 'Other'))
        . (!empty($p['parent']) && $p['parent'] !== 'home' ? "\n  parent: " . sanitize_text_field((string) $p['parent']) : '')
        . (!empty($p['purpose']) ? "\n  purpose: " . sanitize_text_field((string) $p['purpose']) : '')
        . ($children ? "\n  child pages: " . implode(', ', array_slice($children, 0, 12)) : '')
        . (!empty($p['collection']) ? "\n  this is a template for " . (int) $p['collection'] . " similar items" : '');
    }

    $system = "You are a senior web strategist writing the section plan of website pages. Return ONLY valid JSON.\n"
      . "Write everything in {$lang}. Never mix languages. Never invent facts (names, numbers, prices, addresses) that are not in the brief.\n"
      . "For each requested page return: page_purpose (one sentence), sections, seo.\n"
      . "Sections:\n"
      . "- The FIRST section is always section_type \"Headers\" and the LAST section is always section_type \"Footers\".\n"
      . "- Between them: Home 6–8 sections; overview pages 4–6 (include a section that lists or links the child pages); detail, case, blog and service pages 3–5; Contact 2–3 (form, details, map); legal pages 1 (Content Sections).\n"
      . "- section_type MUST be one of: " . implode(' | ', $types) . "\n"
      . "- section_name: short, specific to this business (2–5 words).\n"
      . "- purpose: 2 sentences that tell a web designer what to design and what the visitor gets.\n"
      . "- key_content: 2–5 concrete items taken from the brief.\n"
      . "JSON: {\"pages\":[{\"slug\":\"\",\"page_purpose\":\"\",\"sections\":[{\"section_name\":\"\",\"section_type\":\"\",\"purpose\":\"\",\"key_content\":[\"\"]}],\"seo\":{\"primary_keyword\":\"\",\"secondary_keywords\":[\"\"],\"meta_title\":\"\",\"meta_description\":\"\"}}]}";
    $user = "Website: " . sanitize_text_field((string) ($site['name'] ?? '')) . "\n"
      . (!empty($site['goal']) ? "Goal: " . sanitize_text_field((string) $site['goal']) . "\n" : '')
      . "\nAll pages of the site:\n" . implode("\n", array_slice($outline_lines, 0, 70))
      . "\n\nBrief:\n" . $brief
      . "\n\nWrite the sections for these pages:\n" . implode("\n", $page_lines);

    $json = $this->ai_json($system, $user, 75);
    if (is_wp_error($json)) wp_send_json_error(['message' => $json->get_error_message()], 502);

    $out = [];
    $by_slug = [];
    foreach ((array) ($json['pages'] ?? []) as $p) if (is_array($p) && !empty($p['slug'])) $by_slug[sanitize_title((string) $p['slug'])] = $p;
    $lang_code = strtolower(substr((string) (is_array($languages) ? ($languages[0] ?? 'Dutch') : 'Dutch'), 0, 2));
    foreach ($pages as $req) {
      $slug = sanitize_title((string) ($req['slug'] ?? ''));
      $p = $by_slug[$slug] ?? null;
      if (!$p) continue;
      $out[$slug] = [
        'page_purpose' => sanitize_text_field((string) ($p['page_purpose'] ?? '')),
        'sections' => $this->clean_sections((array) ($p['sections'] ?? []), $types, $lang_code),
        'seo' => [
          'primary_keyword' => sanitize_text_field((string) ($p['seo']['primary_keyword'] ?? '')),
          'secondary_keywords' => array_values(array_map('sanitize_text_field', array_filter((array) ($p['seo']['secondary_keywords'] ?? []), 'is_string'))),
          'meta_title' => sanitize_text_field((string) ($p['seo']['meta_title'] ?? '')),
          'meta_description' => sanitize_text_field((string) ($p['seo']['meta_description'] ?? '')),
        ],
      ];
    }
    wp_send_json_success(['pages' => $out]);
  }

  private function clean_sections(array $sections, array $types, string $lang): array {
    $clean = [];
    foreach ($sections as $s) {
      if (!is_array($s)) continue;
      $type = (string) ($s['section_type'] ?? '');
      if (!in_array($type, $types, true)) {
        // Dichtste geldige soort, anders inhoud.
        $match = '';
        foreach ($types as $t) if (stripos($t, trim(str_ireplace(['sections', 'section'], '', $type))) === 0) { $match = $t; break; }
        $type = $match !== '' ? $match : 'Content Sections';
      }
      $clean[] = [
        'section_name' => sanitize_text_field((string) ($s['section_name'] ?? '')),
        'section_type' => $type,
        'purpose' => sanitize_textarea_field((string) ($s['purpose'] ?? '')),
        'key_content' => array_values(array_map('sanitize_text_field', array_filter((array) ($s['key_content'] ?? []), 'is_string'))),
      ];
    }
    $nav = ['nl' => 'Navigatie', 'fr' => 'Navigation', 'en' => 'Navigation', 'ge' => 'Navigation'][$lang] ?? 'Navigatie';
    $foot = ['nl' => 'Footer', 'fr' => 'Pied de page', 'en' => 'Footer', 'ge' => 'Fußzeile'][$lang] ?? 'Footer';
    $has_head = false; $has_foot = false;
    foreach ($clean as $s) { if ($s['section_type'] === 'Headers') $has_head = true; if ($s['section_type'] === 'Footers') $has_foot = true; }
    if (!$has_head) array_unshift($clean, ['section_name' => $nav, 'section_type' => 'Headers', 'purpose' => '', 'key_content' => []]);
    if (!$has_foot) $clean[] = ['section_name' => $foot, 'section_type' => 'Footers', 'purpose' => '', 'key_content' => []];
    return AISB_Archer_Sitemap_Rules::order_sections($clean);
  }
}
