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
    add_filter('aisb_wireframe_section_types', [$this, 'wireframe_types'], 10, 4);
    // Elke opgeslagen wireframe-sectie: geen kleuren, spans of style-attributen in de teksten.
    add_filter('sanitize_post_meta__bricks_page_content_2_for_ai_wireframe', [__CLASS__, 'clean_nodes']);
  }

  /** Kuist de teksten van alle Bricks-elementen van een wireframe-sectie op. */
  public static function clean_nodes($nodes) {
    if (!is_array($nodes)) return $nodes;
    foreach ($nodes as &$n) {
      if (!is_array($n) || empty($n['settings']) || !is_array($n['settings'])) continue;
      $name = (string) ($n['name'] ?? '');
      if (!in_array($name, ['heading', 'text-basic', 'text', 'button', 'text-link'], true)) continue;
      foreach (['text', 'title', 'subtitle', 'description', 'content'] as $k) {
        if (isset($n['settings'][$k]) && is_string($n['settings'][$k])) {
          $n['settings'][$k] = self::clean_markup($n['settings'][$k], self::mode_for($name));
        }
      }
    }
    unset($n);
    return $nodes;
  }

  /* ───────────────────────── Wireframes volgen de sitemap ───────────────────────── */

  /** Sectieomschrijving uit de sitemap per wireframe-sectie (pagina => index => tekst). */
  private static $hints = [];

  public static function section_hint(string $page_slug, int $idx): string {
    return (string) (self::$hints[$page_slug][$idx] ?? '');
  }

  /** Sectiesoort uit de sitemap → soort sjabloon in de wireframebibliotheek. */
  private static function wireframe_type(string $section_type): string {
    $t = strtolower($section_type);
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
    foreach ($map as $k => $v) {
      if (strpos($t, $k) === 0) return $v;
    }
    return 'content';
  }

  /**
   * Bouwt de wireframe op met dezelfde secties, in dezelfde volgorde, als de sitemap,
   * zodat het canvas van stap 1 en stap 2 overeenkomen (zoals Relume).
   */
  public function wireframe_types($types, $project_id, $sitemap_id, $page_slug) {
    $data = json_decode((string) get_post_meta((int) $sitemap_id, 'aisb_sitemap_json', true), true);
    if (!is_array($data)) return $types;
    $page = null;
    foreach ((array) ($data['sitemap'] ?? []) as $p) {
      if (is_array($p) && sanitize_title((string) ($p['slug'] ?? '')) === $page_slug) { $page = $p; break; }
    }
    $sections = is_array($page['sections'] ?? null) ? AISB_Archer_Sitemap_Rules::order_sections($page['sections']) : [];
    if (count($sections) < 3) return $types;
    $out = [];
    $hints = [];
    foreach ($sections as $s) {
      $wt = self::wireframe_type((string) ($s['section_type'] ?? ''));
      $out[] = $wt;
      $hints[count($out) - 1] = trim((string) ($s['section_name'] ?? '') . ': ' . (string) ($s['purpose'] ?? ''), ': ');
    }
    self::$hints[$page_slug] = $hints;
    return $out;
  }

  /* ───────────────────────── Wireframeteksten ───────────────────────── */

  /**
   * Schrijft de teksten van alle secties van een wireframe parallel (één AI-vraag per sectie)
   * in plaats van één grote vraag voor de hele pagina. Gebruikt door AISB_Wireframes_AI.
   *
   * @param string $prompt       De volledige prompt van de wireframe-AI (met "Target JSON:" op het einde).
   * @param array  $to_translate section_N => ['purpose' => ..., 'modules' => [...]]
   * @return array|null Zelfde vorm als het oude antwoord, of null als niets lukte.
   */
  public static function translate_sections_parallel(string $prompt, array $to_translate): ?array {
    if (!$to_translate || !class_exists('WpOrg\\Requests\\Requests')) return null;
    $self = new self();
    $base = preg_replace('/Target JSON:\n.*$/s', '', $prompt);
    $base .= "\nLENGTH: every text must fit the slot of the placeholder it replaces in the design. LIMITS gives the maximum number of words per module id. Write each text so it naturally fits within that many words: choose a shorter formulation instead of cutting a longer one. Every text is a complete phrase or sentence that stands on its own; never continue a sentence in the next module and never stop mid-sentence. Headings are short and punchy (a hero title is a few words, not a sentence about everything the company does). Short labels (stats, taglines, list items) are 1 to 4 words.\n"
      . "FORMAT: plain text only. No HTML tags, no <span>, no style attributes and no colours: colours and emphasis are chosen later in the style step.\n\n";
    $system = 'Return valid JSON only. No markdown. No explanation.';
    $calls = [];
    $limits = [];
    foreach ($to_translate as $key => $section) {
      $limits[$key] = self::limits_for($section);
      $calls[(string) $key] = ['system' => $system, 'user' => $base
        . "LIMITS (max words per module id):\n" . wp_json_encode(array_map(fn($c) => max(2, (int) floor($c / 7)), $limits[$key])) . "\n\n"
        . "Target JSON:\n" . wp_json_encode([$key => $section])];
    }
    $answers = $self->ai_multi($calls, 'copy', 90);
    $out = [];
    foreach ($answers as $key => $ans) {
      if (is_wp_error($ans) || !is_array($ans)) continue;
      if (isset($ans[$key]) && is_array($ans[$key])) $out[$key] = $ans[$key];
      elseif (isset($ans['modules'])) $out[$key] = $ans;
      elseif (count($ans) === 1 && is_array(reset($ans)) && isset(reset($ans)['modules'])) $out[$key] = reset($ans);
    }
    if (!$out) return null;

    // Opkuisen (geen opmaak of kleuren) en te lange teksten laten inkorten.
    $too_long = [];
    foreach ($out as $key => &$sec) {
      foreach ((array) ($sec['modules'] ?? []) as $mid => $mod) {
        $type = (string) ($to_translate[$key]['modules'][$mid]['type'] ?? ($mod['type'] ?? ''));
        $first = true;
        foreach ((array) ($mod['settings'] ?? []) as $sk => $val) {
          if (!is_string($val)) continue;
          $clean = self::clean_markup($val, self::mode_for($type));
          $sec['modules'][$mid]['settings'][$sk] = $clean;
          $max = $first ? ($limits[$key][$mid] ?? 0) : 0;
          $first = false;
          $plain = wp_strip_all_tags($clean);
          // Te lang, of afgebroken ("…herstellingen en", "airco-onder").
          $cut_off = (bool) preg_match('/(\s(en|of|het|de|een|uit|voor|van|met|op|in|aan|te|om|bij|and|or|the|to|for|with|et|ou|pour|y|und|oder|mit)|[-,:;])$/iu', trim($plain));
          if ($max && (mb_strlen($plain) > $max || $cut_off)) {
            // Modellen tellen niet exact: vraag wat korter dan nodig.
            $too_long[$key][$mid . '|' . $sk] = ['text' => wp_strip_all_tags($clean), 'max' => (int) floor($max * 0.85), 'limit' => $max];
          }
        }
      }
    }
    unset($sec);
    if ($too_long) {
      $calls = [];
      foreach ($too_long as $key => $items) {
        $calls[$key] = ['system' => $system, 'user' =>
          "Rewrite each text as a complete, natural phrase of at most 'max' characters (count spaces). Some texts are too long, some were cut off: finish the thought in fewer words. Keep the language, the meaning and the tone; never cut off mid-word, no ellipsis. Plain text, no HTML.\n"
          . "Return JSON: {\"<id>\": \"<shortened text>\"} with the same ids.\n\n" . wp_json_encode($items)];
      }
      $short = $self->ai_multi($calls, 'copy', 45);
      foreach ($too_long as $key => $items) {
        $ans = $short[$key] ?? null;
        foreach ($items as $ref => $item) {
          [$mid, $sk] = explode('|', $ref, 2);
          $new = (is_array($ans) && isset($ans[$ref]) && is_string($ans[$ref])) ? trim(wp_strip_all_tags($ans[$ref])) : $item['text'];
          if ($new === '' ) $new = $item['text'];
          // Iets te lang mag (beter dan een afgebroken zin); veel te lang wordt netjes ingekort.
          $out[$key]['modules'][$mid]['settings'][$sk] = mb_strlen($new) <= $item['limit'] * 1.1 ? $new : self::trim_to($new, $item['limit']);
        }
      }
    }
    return $out;
  }

  /* ───────────────────────── Tekstlengte en opmaak ───────────────────────── */

  /** Soort tekstveld: 'inline' (titel, knop: platte tekst), 'basic' (korte tekst), 'rich' (teksteditor). */
  public static function mode_for(string $name): string {
    if (in_array($name, ['heading', 'button', 'text-link', 'icon-box', 'counter'], true)) return 'inline';
    if ($name === 'text') return 'rich';
    return 'basic';
  }

  /**
   * Maximaal aantal tekens voor een tekst in de plaats van de placeholder van het sjabloon.
   * Zo blijft de tekst passen bij de opmaak (een grote hero-titel krijgt geen hele zin).
   */
  public static function text_limit(string $name, string $placeholder): int {
    $len = mb_strlen(trim(html_entity_decode(wp_strip_all_tags($placeholder), ENT_QUOTES, 'UTF-8')));
    if ($name === 'heading') return $len ? (int) max(24, min(80, round($len * 1.3))) : 60;
    if (in_array($name, ['button', 'text-link'], true)) return $len ? (int) max(16, min(28, round($len * 1.6))) : 24;
    if ($len === 0) return 0;
    if ($len <= 30) return (int) max(22, round($len * 1.6)); // labels, taglines
    return (int) min(600, round($len * 1.2));
  }

  /** Limieten per module (eerste teksteigenschap) voor één sectie van de wireframe-AI. */
  private static function limits_for(array $section): array {
    $out = [];
    foreach ((array) ($section['modules'] ?? []) as $mid => $mod) {
      foreach ((array) ($mod['settings'] ?? []) as $val) {
        if (!is_string($val)) continue;
        $max = self::text_limit((string) ($mod['type'] ?? ''), $val);
        if ($max) $out[$mid] = $max;
        break;
      }
    }
    return $out;
  }

  /** Inkorten op een zinseinde of woordgrens, zonder afgekapte woorden. */
  public static function trim_to(string $text, int $max): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($max <= 0 || mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    if (preg_match('/^(.*[.!?])\s/u', $cut . ' ', $m) && mb_strlen($m[1]) >= $max * 0.6) return $m[1];
    $sp = mb_strrpos($cut, ' ');
    $cut = $sp !== false && $sp > $max * 0.5 ? mb_substr($cut, 0, $sp) : $cut;
    // Geen losse verbindingswoorden op het einde ("…herstellingen en").
    $stop = '(en|of|het|de|een|uit|voor|van|met|op|in|aan|te|om|bij|and|or|the|a|to|of|for|with|et|ou|le|la|les|du|des|pour|y|o|el|los|las|und|oder|der|die|das|mit)';
    for ($i = 0; $i < 3; $i++) $cut = preg_replace('/[\s,;:–—-]+' . $stop . '$/iu', '', rtrim($cut, " ,;:-–—"));
    return rtrim($cut, " ,;:-–—");
  }

  /**
   * Opmaak opkuisen: geen style-attributen, kleuren of spans (dat komt pas in de stijlstap).
   * inline: platte tekst; basic: vet, cursief en regeleinden; rich: ook alinea's, lijsten en links.
   */
  public static function clean_markup(string $html, string $mode): string {
    $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
    $html = preg_replace('/<\s*b(\s[^>]*)?>/i', '<strong>', $html);
    $html = preg_replace('/<\s*\/\s*b\s*>/i', '</strong>', $html);
    $html = preg_replace('/<\s*i(\s[^>]*)?>/i', '<em>', $html);
    $html = preg_replace('/<\s*\/\s*i\s*>/i', '</em>', $html);
    if ($mode === 'inline') {
      $html = preg_replace('/<br\s*\/?>/i', ' ', $html);
      return trim(preg_replace('/[ \t]+/u', ' ', wp_strip_all_tags($html, false)));
    }
    if ($mode === 'basic') {
      $html = preg_replace('/<\s*(p|div)(\s[^>]*)?>/i', '<br>', $html);
      $html = preg_replace('/<\s*\/\s*(p|div)\s*>/i', '<br>', $html);
      $allowed = ['strong' => [], 'em' => [], 'br' => []];
    } else {
      $allowed = ['p' => [], 'br' => [], 'strong' => [], 'em' => [], 'ul' => [], 'ol' => [], 'li' => [], 'a' => ['href' => true]];
    }
    $html = wp_kses($html, $allowed);
    $html = preg_replace('/(<br>\s*){3,}/i', '<br><br>', $html);
    $html = preg_replace('/^(\s|<br>)+|(\s|<br>)+$/i', '', $html);
    return trim($html);
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

  /**
   * Model per taak. Structuur: wat meer nadenken (logica), secties: zo snel mogelijk.
   * Aan te passen met de filters archer_ai_plan_model en archer_ai_sections_model.
   */
  private function model_for(string $task): array {
    $defaults = [
      'plan' => ['model' => 'gpt-5.4-mini', 'effort' => 'low'],
      'sections' => ['model' => 'gpt-5.4-mini', 'effort' => 'none'],
      'copy' => ['model' => 'gpt-5.4-mini', 'effort' => 'none'],
    ];
    $m = apply_filters('archer_ai_' . $task . '_model', $defaults[$task] ?? $defaults['sections']);
    return is_array($m) ? $m : $defaults['sections'];
  }

  private function request_body(array $model, string $system, string $user): array {
    $body = [
      'model' => $model['model'],
      'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
      'response_format' => ['type' => 'json_object'],
    ];
    if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
      if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
    } else {
      $body['temperature'] = 0.4;
    }
    return $body;
  }

  /**
   * Meerdere AI-vragen tegelijk. Lukt een model niet (bv. niet beschikbaar voor deze sleutel),
   * dan volgt een tweede poging met het model uit de instellingen.
   * @param array $calls key => ['system' => string, 'user' => string]
   * @return array key => array|WP_Error
   */
  private function ai_multi(array $calls, string $task, int $timeout = 60): array {
    $settings = $this->settings();
    if (empty($settings['api_key'])) {
      return array_map(function () { return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.'); }, $calls);
    }
    $endpoint = !empty($settings['endpoint']) ? $settings['endpoint'] : 'https://api.openai.com/v1/chat/completions';
    $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $settings['api_key']];
    $models = [$this->model_for($task), ['model' => (string) ($settings['model'] ?? 'gpt-4o-mini') ?: 'gpt-4o-mini', 'effort' => '']];
    $out = [];
    $todo = $calls;
    foreach ($models as $model) {
      if (!$todo) break;
      $reqs = [];
      foreach ($todo as $k => $c) {
        $reqs[$k] = ['url' => $endpoint, 'type' => 'POST', 'headers' => $headers, 'data' => wp_json_encode($this->request_body($model, $c['system'], $c['user']))];
      }
      $responses = \WpOrg\Requests\Requests::request_multiple($reqs, ['timeout' => $timeout, 'connect_timeout' => 10]);
      $retry = [];
      foreach ($todo as $k => $c) {
        $r = $responses[$k] ?? null;
        if (!($r instanceof \WpOrg\Requests\Response)) { $retry[$k] = $c; $out[$k] = new WP_Error('aisb_ai_http', 'De AI reageerde niet.'); continue; }
        $j = json_decode((string) $r->body, true);
        if ($r->status_code < 200 || $r->status_code >= 300) {
          $out[$k] = new WP_Error('aisb_ai_' . $r->status_code, (string) ($j['error']['message'] ?? 'AI-fout ' . $r->status_code));
          if (in_array($r->status_code, [400, 404, 429, 500, 502, 503], true)) $retry[$k] = $c;
          continue;
        }
        $content = json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
        if (!is_array($content)) { $retry[$k] = $c; $out[$k] = new WP_Error('aisb_bad_json', 'De AI gaf geen geldig antwoord.'); continue; }
        $out[$k] = $content;
      }
      $todo = $retry;
    }
    return $out;
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
    $system = self::plan_system_prompt();
    $user = "Language: {$lang}\nDesired number of pages: {$page_count}\n\nBrief:\n" . mb_substr($prompt, 0, 9000);
    $json = $this->ai_multi(['plan' => ['system' => $system, 'user' => $user]], 'plan', 60)['plan'];
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

  private static function plan_system_prompt(): string {
    return <<<'PROMPT'
You are a senior information architect for small-business websites. Return ONLY valid JSON.
Create the page structure (no sections) from the brief.
JSON: {"website_name":"","website_goal":"","primary_audiences":[""],"sitemap":[{"page_title":"","nav_label":"","slug":"","page_type":"Home|Service|About|Contact|Blog|Landing|Legal|Listing|Detail|Other","priority":"Core|Support|Optional","parent_slug":"string|null","page_purpose":"one sentence"}]}

How to structure:
- Organise pages the way visitors look for things, not the way the business is organised internally.
- Search filters are NOT pages. If visitors filter on type, price, brand, location, size or rooms, that happens on ONE overview page with filters. Never make separate pages per filter value (no "Houses for sale" and "Apartments for sale"; no page per car brand; no page per price range).
- Every overview of items that change over time (listings, properties, cars, products, projects, cases, vacancies, events, blog posts, team members) gets exactly ONE detail template page as its child (for example "Pand (detailpagina)", "Wagen (detailpagina)", "Vacature (detailpagina)"). Do not invent individual items.
- Services the business names in the brief get one child page each under a services overview (max 6). Without named services: one services page, no children.
- Industry patterns (use when they fit; translate the titles to the requested language):
  - Real estate: Te koop (overview with filters) > Pand te koop (detail); Te huur (overview with filters) > Pand te huur (detail); Verkopen of verhuren (for owners: valuation request); Over ons; Contact.
  - Car dealer / garage: Occasions (overview with filters) > Wagen (detail); Onderhoud en herstellingen (with the named services as children); Afspraak maken; Over ons; Contact.
  - Restaurant / hospitality: Menukaart; Reserveren; Over ons; Contact (events or catering only if named).
  - Construction / installers: Diensten (named services as children); Realisaties > Realisatie (detail); Offerte aanvragen; Over ons; Contact.
  - Webshop: Shop (overview with filters) > Product (detail); Over ons; Klantenservice; Contact.
  - Practice / healthcare / professional services: Behandelingen or Diensten (named ones as children); Team; Afspraak maken; Tarieven (only if relevant); Contact.
- Add a conversion page when the brief's goal asks for it (appointment, quote, valuation, reservation), unless the contact page covers it.
- Always include a Contact page. Add a Blog only when the brief mentions content, news or SEO.
- Exactly one Home page: slug "home", parent_slug null. Every other page has a parent_slug ("home" for menu pages). Max 3 levels.
- Respect the desired number of pages as a guide; never pad with filler pages.
- Write titles and labels in the requested language. Slugs: lowercase, hyphenated, unique. nav_label: 1–3 words.
- Never invent facts (names, addresses, prices) that are not in the brief.
PROMPT;
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
    if (!is_array($pages) || !$pages || count($pages) > 6) wp_send_json_error(['message' => 'Ongeldige pagina’s.'], 400);

    $languages = json_decode((string) get_post_meta($project_id, 'aisb_project_languages', true), true);
    $lang = self::lang_name(is_array($languages) ? $languages : ['Dutch']);
    $brief = mb_substr((string) get_post_meta($project_id, 'aisb_project_brief', true), 0, 4500);
    $types = $this->section_types();

    $outline_lines = [];
    foreach ((array) $outline as $o) {
      if (!is_array($o)) continue;
      $outline_lines[] = '- ' . ($o['title'] ?? '') . ' (' . ($o['slug'] ?? '') . (isset($o['parent']) && $o['parent'] && $o['parent'] !== 'home' ? ', under ' . $o['parent'] : '') . ')';
    }
    $system = "You are a senior web strategist writing the section plan of one website page. Return ONLY valid JSON.\n"
      . "Write everything in {$lang}. Never mix languages. Never invent facts (names, numbers, prices, addresses) that are not in the brief.\n"
      . "Sections:\n"
      . "- The FIRST section is always section_type \"Headers\" and the LAST section is always section_type \"Footers\".\n"
      . "- Between them: Home 6–8 sections; overview pages 4–6 (on listing overviews include the search filters and the results grid; on other overviews a section that lists the child pages); detail, case, blog and service pages 3–5; Contact 2–3 (form, details, map); legal pages 1 (Content Sections).\n"
      . "- Order the sections the way a visitor reads the page: hero or intro right after the header, the main content next, proof and the call to action near the end.\n"
      . "- section_type MUST be one of: " . implode(' | ', $types) . " (for properties use Property or Single Property sections, for cars and products the product sections).\n"
      . "- section_name: short and specific to this business (2–5 words).\n"
      . "- purpose: 1–2 short sentences: what to design and what the visitor gets.\n"
      . "- key_content: 2–4 concrete items from the brief.\n"
      . "JSON: {\"page_purpose\":\"one sentence\",\"sections\":[{\"section_name\":\"\",\"section_type\":\"\",\"purpose\":\"\",\"key_content\":[\"\"]}],\"seo\":{\"primary_keyword\":\"\",\"secondary_keywords\":[\"\"],\"meta_title\":\"\",\"meta_description\":\"\"}}";
    $context = "Website: " . sanitize_text_field((string) ($site['name'] ?? '')) . "\n"
      . (!empty($site['goal']) ? "Goal: " . sanitize_text_field((string) $site['goal']) . "\n" : '')
      . "\nAll pages of the site:\n" . implode("\n", array_slice($outline_lines, 0, 70))
      . "\n\nBrief:\n" . $brief;

    $calls = [];
    foreach ($pages as $p) {
      if (!is_array($p) || empty($p['slug'])) continue;
      $slug = sanitize_title((string) $p['slug']);
      $children = array_filter((array) ($p['children'] ?? []), 'is_string');
      $page = "Write the sections for this page:\n- slug: " . $slug . "\n- title: " . sanitize_text_field((string) ($p['title'] ?? ''))
        . "\n- type: " . sanitize_text_field((string) ($p['page_type'] ?? 'Other'))
        . (!empty($p['parent']) && $p['parent'] !== 'home' ? "\n- parent: " . sanitize_text_field((string) $p['parent']) : '')
        . (!empty($p['purpose']) ? "\n- purpose: " . sanitize_text_field((string) $p['purpose']) : '')
        . ($children ? "\n- child pages: " . implode(', ', array_slice($children, 0, 12)) : '')
        . (!empty($p['collection']) ? "\n- this is a template for " . (int) $p['collection'] . " similar items" : '');
      $calls[$slug] = ['system' => $system, 'user' => $context . "\n\n" . $page];
    }
    $answers = $this->ai_multi($calls, 'sections', 60);

    $out = [];
    $errors = [];
    $lang_code = strtolower(substr((string) (is_array($languages) ? ($languages[0] ?? 'Dutch') : 'Dutch'), 0, 2));
    foreach ($answers as $slug => $p) {
      if (is_wp_error($p)) { $errors[$slug] = $p->get_error_message(); continue; }
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
    if (!$out && $errors) wp_send_json_error(['message' => reset($errors)], 502);
    wp_send_json_success(['pages' => $out, 'errors' => $errors]);
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
