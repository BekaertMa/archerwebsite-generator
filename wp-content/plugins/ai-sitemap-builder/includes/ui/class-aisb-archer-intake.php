<?php
if (!defined('ABSPATH')) exit;

/**
 * Intake-agent: het startscherm als gesprek in plaats van een formulier.
 *
 * De agent stelt één vraag per beurt, met knoppen voor de gangbare antwoorden en altijd
 * ruimte voor eigen tekst. Wat de klant vertelt, komt in een gestructureerde brief;
 * correcties ("eigenlijk is het Lokeren") passen het veld aan. Wat al op een bestaande
 * website staat, vraagt de agent niet opnieuw. Als de verplichte velden ingevuld zijn,
 * toont de agent een samenvatting en start de klant de generatie (sitemap, wireframes,
 * stijl en foto's) vanuit die brief.
 *
 * AJAX:
 * - aisb_archer_intake_turn: één beurt (gesprek + brief in, antwoord + bijgewerkte brief uit);
 * - aisb_archer_intake_save: bewaart de bevestigde brief bij het nieuwe project.
 */
class AISB_Archer_Intake {

  /** Velden van de brief, met label voor de samenvatting en de setupbalk. */
  public const FIELDS = [
    'business_name' => 'Naam',
    'existing_site' => 'Huidige website',
    'activity'      => 'Activiteit',
    'services'      => 'Diensten',
    'audience'      => 'Doelgroep',
    'region'        => 'Regio',
    'main_goal'     => 'Doel van de website',
    'booking'       => 'Online boeken',
    'usp'           => 'Wat jullie onderscheidt',
    'tone'          => 'Toon',
    'languages'     => 'Talen',
    'page_count'    => "Aantal pagina's",
    'must_pages'    => "Pagina's die zeker moeten",
    'style_mood'    => 'Stijl',
    'brand_colors'  => 'Kleuren',
    'examples'      => 'Voorbeeldsites',
    'photos'        => "Foto's",
    'contact'       => 'Contact',
  ];

  /** Zonder deze velden start de generatie niet. */
  public const REQUIRED = ['business_name', 'activity', 'audience', 'region', 'main_goal', 'tone', 'languages', 'style_mood'];

  /** Na zoveel antwoorden rondt de agent af met wat hij heeft. */
  public const MAX_TURNS = 14;

  public function init(): void {
    add_action('wp_ajax_aisb_archer_intake_turn', [$this, 'ajax_turn']);
    add_action('wp_ajax_aisb_archer_intake_save', [$this, 'ajax_save']);
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  public function ajax_turn(): void {
    $this->check();
    @set_time_limit(90);

    $messages = json_decode((string) wp_unslash($_POST['messages'] ?? '[]'), true);
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    $messages = self::clean_messages(is_array($messages) ? $messages : []);
    $brief = self::clean_brief(is_array($brief) ? $brief : []);
    if (!$messages) wp_send_json_error(['message' => 'Er is nog geen bericht.'], 400);

    // Een bestaande website lezen we één keer (homepagina), zodat de agent niet vraagt wat er al staat.
    $site_excerpt = '';
    if (!empty($brief['existing_site'])) $site_excerpt = self::site_excerpt((string) $brief['existing_site']);

    $user_turns = count(array_filter($messages, function ($m) { return $m['role'] === 'user'; }));
    $out = $this->ai_turn($messages, $brief, $site_excerpt, $user_turns);
    if (is_wp_error($out)) wp_send_json_error(['message' => $out->get_error_message()], 502);

    $new_brief = self::clean_brief(is_array($out['brief'] ?? null) ? array_merge($brief, $out['brief']) : $brief);
    $missing = self::missing($new_brief);
    $done = !empty($out['done']) && !$missing;
    // Te lang bezig: afronden met wat er is, ontbrekende verplichte velden krijgen een standaard.
    if (!$done && $user_turns >= self::MAX_TURNS) {
      $new_brief = self::with_defaults($new_brief);
      $missing = self::missing($new_brief);
      $done = !$missing;
    }

    $options = array_values(array_filter(array_map(function ($o) {
      return mb_substr(trim(wp_strip_all_tags((string) $o)), 0, 60);
    }, (array) ($out['options'] ?? [])), 'strlen'));

    wp_send_json_success([
      'reply'    => mb_substr(trim(wp_strip_all_tags((string) ($out['reply'] ?? ''))), 0, 600),
      'options'  => $done ? [] : array_slice($options, 0, 6),
      'multi'    => !$done && !empty($out['multi']),
      'input'    => in_array(($out['input'] ?? ''), ['text', 'url', 'none'], true) ? $out['input'] : 'text',
      'brief'    => $new_brief,
      'done'     => $done,
      'missing'  => $missing,
      'summary'  => $done ? self::summary($new_brief) : [],
      'progress' => ['have' => count(self::REQUIRED) - count($missing), 'need' => count(self::REQUIRED)],
    ]);
  }

  public function ajax_save(): void {
    $this->check();
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $p = $project_id ? get_post($project_id) : null;
    if (!$p || $p->post_type !== 'aisb_project' || ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options'))) {
      wp_send_json_error(['message' => 'Project niet gevonden.'], 404);
    }
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    $brief = self::clean_brief(is_array($brief) ? $brief : []);
    update_post_meta($project_id, 'archer_intake_brief', wp_slash(wp_json_encode($brief)));
    wp_send_json_success(['saved' => true]);
  }

  /** De bevestigde brief van een project (leeg als het project zonder intake gemaakt is). */
  public static function brief_for(int $project_id): array {
    $b = json_decode((string) get_post_meta($project_id, 'archer_intake_brief', true), true);
    return is_array($b) ? $b : [];
  }

  /* ───────────────────────── AI ───────────────────────── */

  private function ai_turn(array $messages, array $brief, string $site_excerpt, int $user_turns) {
    $settings = class_exists('AISB_Settings') ? (array) (new AISB_Settings())->get_settings() : [];
    if (empty($settings['api_key'])) return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $endpoint = !empty($settings['endpoint']) ? $settings['endpoint'] : 'https://api.openai.com/v1/chat/completions';

    $context = "CURRENT BRIEF (JSON):\n" . wp_json_encode($brief, JSON_UNESCAPED_UNICODE)
      . "\n\nMISSING REQUIRED FIELDS: " . implode(', ', self::missing($brief))
      . "\nUSER ANSWERS SO FAR: " . $user_turns . ' (wrap up at ' . self::MAX_TURNS . ')';
    if ($site_excerpt !== '') $context .= "\n\nTEXT FROM THE CURRENT WEBSITE (homepage, use it to fill fields; do not ask what it already answers, but you may ask to confirm one key fact):\n" . $site_excerpt;
    if (class_exists('AISB_Archer_Sector') && method_exists('AISB_Archer_Sector', 'intake_hints')) {
      $context .= "\n\n" . AISB_Archer_Sector::intake_hints($brief);
    }

    $chat = [['role' => 'system', 'content' => self::system_prompt()], ['role' => 'system', 'content' => $context]];
    foreach ($messages as $m) $chat[] = ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => $m['text']];

    $models = [
      apply_filters('archer_ai_intake_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']),
      ['model' => (string) ($settings['model'] ?? 'gpt-4o-mini') ?: 'gpt-4o-mini', 'effort' => ''],
    ];
    $last = new WP_Error('aisb_ai_http', 'De agent reageerde niet. Probeer opnieuw.');
    foreach ($models as $model) {
      $body = ['model' => $model['model'], 'messages' => $chat, 'response_format' => ['type' => 'json_object']];
      if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
        if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
      } else {
        $body['temperature'] = 0.3;
      }
      $res = wp_remote_post($endpoint, [
        'timeout' => 45,
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $settings['api_key']],
        'body'    => wp_json_encode($body),
      ]);
      if (is_wp_error($res)) { $last = $res; continue; }
      $code = (int) wp_remote_retrieve_response_code($res);
      $j = json_decode((string) wp_remote_retrieve_body($res), true);
      if ($code < 200 || $code >= 300) { $last = new WP_Error('aisb_ai_' . $code, (string) ($j['error']['message'] ?? 'AI-fout ' . $code)); continue; }
      $content = json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
      if (is_array($content) && isset($content['reply'])) return $content;
      $last = new WP_Error('aisb_bad_json', 'De agent gaf geen geldig antwoord.');
    }
    return $last;
  }

  private static function system_prompt(): string {
    return <<<'TXT'
You are Archer's onboarding agent. You set up a website for a small local business (hairdresser, baker, restaurant, physiotherapist, contractor, ...) through a short conversation, instead of a form. Write in the language the user writes in; default to Belgian Dutch (Flemish), informal "je".

HOW YOU TALK
- One question per turn. Keep it to one or two short sentences. Briefly acknowledge the previous answer only when it adds something ("Genoteerd.", "Mooi, dan zet ik een reservatieknop prominent.").
- When the answer is a choice, give 2 to 5 short options (1 to 4 words each) in "options". Add "Iets anders" as last option when the list may not cover it. For open answers (name, address, region, services, what sets them apart) give no options or a few examples.
- Set "multi": true only when several options can be picked at once (languages, services, must-have pages).
- If the user corrects something ("eigenlijk...", "nee, het is..."), update that field, confirm in a few words, and continue with the next missing item.
- Never ask for something the brief or the current website already answers. Never ask two things at once.
- If the user asks a question, answer it in one sentence, then continue.

WHAT TO COLLECT (brief fields), in roughly this order, skipping what is known:
1. business_name, and existing_site if they have one (a URL). The first message is usually the name or a URL.
2. activity: what the business does, in one line. services: list of main services or products.
3. audience: "B2C", "B2B" or "B2B en B2C"; plus who exactly, if they say.
4. region: town and the area they serve.
5. main_goal: what a visitor must do: "Bellen", "Offerte aanvragen", "Afspraak boeken", "Reserveren", "Langskomen", "Bestellen", "Contactformulier".
6. booking: only if main_goal is booking or reservation: "Calendly", "Eigen boekingssysteem (link)", "Nog geen", or the tool name. Archer links to an external tool; it does not sync calendars.
7. usp: what sets them apart (one or two sentences).
8. tone: "Formeel (u)", "Vriendelijk (je)" or "Speels (je)".
9. languages: array of "Dutch", "French", "English", "German" (show options as Nederlands, Frans, Engels, Duits; store the English names).
10. must_pages: pages they know they need (for example Prijzen, Team, Realisaties, Vacatures, Blog). page_count: one of "1", "2-5", "5-10", "10-15", "15+"; infer it, ask only if unclear.
11. style_mood: offer these options: "Licht en rustig", "Warm en ambachtelijk", "Strak en modern", "Krachtig en uitgesproken". brand_colors: only if they have colours or a logo; otherwise leave empty (the logo is reused from the current website when there is one).
12. photos: "Eigen foto's", "Stockfoto's", "Mix".
13. contact: object with address, phone, email, socials. Ask once, in one turn ("Welk adres, telefoonnummer en e-mail mogen op de website?"); the user may skip. If they skip, set contact to {"skipped": true}.
examples (websites they like) is optional: ask only if the user mentions style references.

FINISHING
- Required: business_name, activity, audience, region, main_goal, tone, languages, style_mood. When these are filled and you asked about contact once, set "done": true and reply with one sentence like "Dat is alles wat ik nodig heb. Hier is je samenvatting." Do not list the summary yourself.
- When you are near the turn limit, make sensible choices yourself for optional fields and finish.

OUTPUT: only a JSON object:
{"reply": string, "options": [string], "multi": boolean, "input": "text"|"url"|"none", "brief": {only the fields you add or change in this turn}, "done": boolean}
Field types: strings, except services, must_pages, languages (arrays of strings) and contact (object with address, phone, email, socials as strings). Keep values short and factual, in the user's words. Never invent facts the user did not give or the website does not show.
TXT;
  }

  /* ───────────────────────── Brief ───────────────────────── */

  public static function clean_brief(array $b): array {
    $out = [];
    foreach (array_keys(self::FIELDS) as $k) {
      if (!array_key_exists($k, $b)) continue;
      $v = $b[$k];
      if (in_array($k, ['services', 'must_pages', 'languages'], true)) {
        $list = is_array($v) ? $v : preg_split('/\s*[,;]\s*/', (string) $v);
        $list = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim(wp_strip_all_tags((string) $x)), 0, 80); }, $list), 'strlen')));
        if ($k === 'languages') $list = array_values(array_intersect($list, ['Dutch', 'French', 'English', 'German']));
        if ($list) $out[$k] = array_slice($list, 0, 20);
      } elseif ($k === 'contact') {
        $c = is_array($v) ? $v : [];
        $cc = [];
        foreach (['address', 'phone', 'email', 'socials'] as $ck) {
          $cv = is_array($c[$ck] ?? null) ? implode(', ', $c[$ck]) : (string) ($c[$ck] ?? '');
          $cv = mb_substr(trim(wp_strip_all_tags($cv)), 0, 200);
          if ($ck === 'email' && $cv !== '' && !is_email($cv)) $cv = '';
          if ($cv !== '') $cc[$ck] = $cv;
        }
        // "Overgeslagen" telt als gevraagd: de agent vraagt het dan niet opnieuw.
        if ($cc || !empty($c['skipped'])) $out[$k] = $cc ?: ['skipped' => '1'];
      } elseif ($k === 'existing_site') {
        $u = trim((string) $v);
        if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
        $u = esc_url_raw($u);
        if ($u && wp_http_validate_url($u)) $out[$k] = $u;
      } elseif ($k === 'page_count') {
        $s = (string) $v;
        if (in_array($s, ['1', '2-5', '5-10', '10-15', '15+'], true)) $out[$k] = $s;
      } else {
        $s = mb_substr(trim(wp_strip_all_tags(is_array($v) ? implode(', ', $v) : (string) $v)), 0, 400);
        if ($s !== '') $out[$k] = $s;
      }
    }
    return $out;
  }

  public static function missing(array $b): array {
    return array_values(array_filter(self::REQUIRED, function ($k) use ($b) { return empty($b[$k]); }));
  }

  /** Standaarden als de klant te lang bezig is; enkel voor velden waar een gok geen schade doet. */
  private static function with_defaults(array $b): array {
    $defaults = ['audience' => 'B2C', 'main_goal' => 'Contactformulier', 'tone' => 'Vriendelijk (je)', 'languages' => ['Dutch'], 'style_mood' => 'Licht en rustig'];
    foreach ($defaults as $k => $v) if (empty($b[$k])) $b[$k] = $v;
    return $b;
  }

  /** Rijen voor de samenvattingstabel. */
  public static function summary(array $b): array {
    $lang = ['Dutch' => 'Nederlands', 'French' => 'Frans', 'English' => 'Engels', 'German' => 'Duits'];
    $rows = [];
    foreach (self::FIELDS as $k => $label) {
      if (empty($b[$k])) continue;
      $v = $b[$k];
      if ($k === 'languages') $v = implode(', ', array_map(function ($l) use ($lang) { return $lang[$l] ?? $l; }, $v));
      elseif ($k === 'contact') { if (!empty($v['skipped'])) continue; $v = implode(' · ', array_values($v)); }
      elseif (is_array($v)) $v = implode(', ', $v);
      $rows[] = ['key' => $k, 'label' => $label, 'value' => (string) $v];
    }
    return $rows;
  }

  /** Homepaginatekst van een bestaande site, één keer per dag per adres opgehaald. */
  private static function site_excerpt(string $url): string {
    $key = 'archer_intake_site_' . md5($url);
    $cached = get_transient($key);
    if (is_string($cached)) return $cached;
    $text = '';
    $res = wp_safe_remote_get($url, [
      'timeout' => 12, 'redirection' => 3, 'limit_response_size' => 2 * 1024 * 1024,
      'user-agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)',
    ]);
    if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 400 && class_exists('AISB_Archer_UI')) {
      $brief = AISB_Archer_UI::extract_brief((string) wp_remote_retrieve_body($res), $url);
      $text = mb_substr(trim(($brief['title'] ?? '') . "\n" . ($brief['text'] ?? '')), 0, 4000);
    }
    set_transient($key, $text, DAY_IN_SECONDS);
    return $text;
  }

  /* ───────────────────────── Hulp ───────────────────────── */

  private static function clean_messages(array $messages): array {
    $out = [];
    foreach (array_slice($messages, -40) as $m) {
      if (!is_array($m)) continue;
      $role = ($m['role'] ?? '') === 'user' ? 'user' : 'agent';
      $text = mb_substr(trim(wp_strip_all_tags((string) ($m['text'] ?? ''))), 0, 2000);
      if ($text !== '') $out[] = ['role' => $role, 'text' => $text];
    }
    return $out;
  }

  private function check(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
  }
}
