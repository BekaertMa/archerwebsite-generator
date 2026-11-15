<?php
if (!defined('ABSPATH')) exit;

/**
 * Ontwerprichting vóór de generatie (ADR-0033).
 *
 * Een ontwerper beslist eerst positionering, hoofdactie en wat de site benadrukt, en bouwt dan.
 * Deze klasse doet dat automatisch, zonder de intake op te houden:
 *
 * 1. Onderzoek (achtergrond): zodra naam, activiteit en regio bekend zijn, zoekt één AI-vraag met
 *    websearch 3 à 4 lokale zaken met dezelfde activiteit en wat hun websites beloven.
 * 2. Strategie: brief + onderzoek → positionering, belofte, hoofdactie, bewijs, principes, wat te
 *    vermijden, één beeldtaal voor alle foto's, en hoogstens 3 ontwerpvragen met een voorstel.
 * 3. Ontwerpvragen: de intake stelt die vragen op het einde, één per beurt, zonder extra AI-vraag.
 * 4. Eén ontwerpbrief per project (post meta archer_direction) die elke AI-stap leest via
 *    prompt_block($pid, $taak): plan, sections, copy, menu of photo.
 *
 * Tijdens de intake bestaat het project nog niet: alles staat dan in transients per gebruiker en
 * per intake (de "draft", een id uit de browser). aisb_archer_plan koppelt de draft aan het project.
 * Niets hiervan blokkeert: zonder onderzoek of strategie loopt de generatie zoals vroeger.
 *
 * AJAX: aisb_archer_direction_research en aisb_archer_direction_strategy (vuur-en-vergeet vanuit de intake).
 */
class AISB_Archer_Direction {

  public const VERSION = 1;
  public const META = 'archer_direction';
  /** Hoogstens zoveel vragen aan de eigenaar. */
  public const MAX_QUESTIONS = 4;
  /** Antwoord dat het voorstel kiest. */
  public const TAKE_PROPOSAL = 'Kies jij maar';

  public function init(): void {
    add_action('wp_ajax_aisb_archer_direction_research', [$this, 'ajax_research']);
    add_action('wp_ajax_aisb_archer_direction_strategy', [$this, 'ajax_strategy']);
  }

  /* ───────────────────────── Draft (tijdens de intake) ───────────────────────── */

  public static function clean_draft($draft): string {
    return substr(preg_replace('/[^a-z0-9]/', '', strtolower((string) $draft)), 0, 32);
  }

  private static function tkey(string $draft, string $part): string {
    return 'archer_dir_' . get_current_user_id() . '_' . $draft . '_' . $part;
  }

  public static function part(string $draft, string $part) {
    if ($draft === '') return null;
    $v = get_transient(self::tkey($draft, $part));
    return $v === false ? null : $v;
  }

  /** Zelfde als part(), maar vers uit de database: een ander verzoek kan de waarde intussen gezet hebben. */
  public static function fresh_part(string $draft, string $part) {
    if ($draft === '') return null;
    $k = self::tkey($draft, $part);
    if (!wp_using_ext_object_cache()) {
      wp_cache_delete('_transient_' . $k, 'options');
      wp_cache_delete('_transient_timeout_' . $k, 'options');
      wp_cache_delete('notoptions', 'options');
    } else {
      wp_cache_delete($k, 'transient');
    }
    return self::part($draft, $part);
  }

  private static function set_part(string $draft, string $part, $value): void {
    if ($draft === '') return;
    set_transient(self::tkey($draft, $part), $value, DAY_IN_SECONDS);
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  private function check(): array {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
    $draft = self::clean_draft(wp_unslash($_POST['draft'] ?? ''));
    if ($draft === '') wp_send_json_error(['message' => 'Geen intake.'], 400);
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    $brief = class_exists('AISB_Archer_Intake') ? AISB_Archer_Intake::clean_brief(is_array($brief) ? $brief : []) : [];
    return [$draft, $brief];
  }

  /** Onderzoek van de concurrentie (± 30 s). De browser wacht er niet op. */
  public function ajax_research(): void {
    [$draft, $brief] = $this->check();
    $subject = self::subject($brief);
    if ($subject === '') wp_send_json_error(['message' => 'Naam, activiteit en regio ontbreken nog.'], 400);
    $have = self::part($draft, 'research');
    if (is_array($have) && ($have['subject'] ?? '') === $subject && in_array($have['status'] ?? '', ['running', 'done'], true)) {
      wp_send_json_success(['status' => $have['status']]);
    }
    @set_time_limit(120);
    ignore_user_abort(true);
    self::set_part($draft, 'research', ['status' => 'running', 'subject' => $subject, 'started' => time()]);
    $r = self::research($brief);
    if (is_wp_error($r)) {
      self::set_part($draft, 'research', ['status' => 'failed', 'subject' => $subject, 'error' => $r->get_error_message()]);
      self::log('research', 'failed', ['error' => $r->get_error_message()]);
      wp_send_json_success(['status' => 'failed']);
    }
    self::set_part($draft, 'research', ['status' => 'done', 'subject' => $subject] + $r);
    wp_send_json_success(['status' => 'done', 'peers' => count($r['peers'] ?? [])]);
  }

  /** Strategie uit brief + onderzoek (± 10 s). Wacht hoogstens 25 s op een lopend onderzoek. */
  public function ajax_strategy(): void {
    [$draft, $brief] = $this->check();
    @set_time_limit(120);
    ignore_user_abort(true);
    // Loopt: de intake wacht op het einde even op de vragen in plaats van ze over te slaan.
    self::set_part($draft, 'strategy_running', time());
    $research = self::wait_research($draft, 25);
    $text = class_exists('AISB_Archer_Intake') ? AISB_Archer_Intake::prompt_text($brief) : '';
    if (mb_strlen($text) < 20) wp_send_json_error(['message' => 'De brief is nog te kort.'], 400);
    // De vragen gaan naar de eigenaar in de taal van het gesprek (Vlaams); de richting zelf is Engels.
    $s = self::strategy($text, $research, 'Dutch', self::style_hint((string) ($brief['style_id'] ?? '')));
    delete_transient(self::tkey($draft, 'strategy_running'));
    if (is_wp_error($s)) {
      self::log('strategy', 'failed', ['error' => $s->get_error_message()]);
      wp_send_json_success(['status' => 'failed']);
    }
    self::set_part($draft, 'strategy', $s);
    wp_send_json_success(['status' => 'done', 'questions' => count($s['questions'])]);
  }

  /** Onderzoek van deze draft; wacht tot $max seconden als het nog loopt. Leeg als er niets is. */
  private static function wait_research(string $draft, int $max): array {
    $end = time() + $max;
    do {
      $r = self::fresh_part($draft, 'research');
      if (!is_array($r) || ($r['status'] ?? '') !== 'running') break;
      if ((time() - (int) ($r['started'] ?? 0)) > 150) break; // vastgelopen
      sleep(1);
    } while (time() < $end);
    return is_array($r) && ($r['status'] ?? '') === 'done' ? $r : [];
  }

  /* ───────────────────────── AI ───────────────────────── */

  /** Wie we onderzoeken: activiteit en regio (zonder die twee geen onderzoek). */
  public static function subject(array $b): string {
    $a = trim((string) ($b['activity'] ?? ''));
    $r = trim((string) ($b['region'] ?? ''));
    if ($a === '' || $a === '-' || $r === '' || $r === '-' || empty($b['business_name'])) return '';
    return mb_strtolower($a . '|' . $r);
  }

  private static function api_key(): string {
    $s = class_exists('AISB_Settings') ? (array) (new AISB_Settings())->get_settings() : (array) get_option('aisb_settings', []);
    return (string) ($s['api_key'] ?? '');
  }

  /**
   * Lokale zaken met dezelfde activiteit en wat hun websites beloven (OpenAI Responses API + web_search).
   *
   * @return array{peers: array, common: array, gaps: array, model: string, tokens: int}|WP_Error
   */
  public static function research(array $b) {
    $key = self::api_key();
    if ($key === '') return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $model = apply_filters('archer_ai_research_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']);
    $own = trim((string) ($b['existing_site'] ?? ''));
    $services = is_array($b['services'] ?? null) ? implode(', ', array_slice($b['services'], 0, 8)) : '';
    $input = "A small local business needs a new website. Research its local competition.\n"
      . "Business: " . ($b['business_name'] ?? '') . "\nActivity: " . ($b['activity'] ?? '') . "\nTown/region: " . ($b['region'] ?? '') . " (Belgium)\n"
      . ($services !== '' ? "Services: {$services}\n" : '')
      . ($own !== '' ? "Its own current website (exclude it): {$own}\n" : '')
      . "\nFind 3 or 4 OTHER businesses with the same activity in or near that town that have a website. For each, read the homepage and note its 2 to 4 main claims (what it promises or stresses), in the language of that site, short.\n"
      . "Then list the claims most of them make (common), and 2 to 4 gaps: things customers care about that none of them says clearly.\n"
      . "Only report what you actually found on those sites. If you find fewer businesses, return fewer.\n"
      . "Answer with ONLY this JSON, no other text: {\"peers\":[{\"name\":\"\",\"url\":\"\",\"claims\":[\"\"]}],\"common\":[\"\"],\"gaps\":[\"\"]}";
    $body = ['model' => $model['model'], 'tools' => [['type' => 'web_search']], 'input' => $input];
    if (!empty($model['effort'])) $body['reasoning'] = ['effort' => $model['effort']];
    $t = microtime(true);
    $res = wp_remote_post('https://api.openai.com/v1/responses', [
      'timeout' => 100,
      'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
      'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($res)) return $res;
    $code = (int) wp_remote_retrieve_response_code($res);
    $j = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300) return new WP_Error('aisb_ai_' . $code, (string) ($j['error']['message'] ?? 'AI-fout ' . $code));
    $text = '';
    foreach ((array) ($j['output'] ?? []) as $o) {
      if (($o['type'] ?? '') !== 'message') continue;
      foreach ((array) ($o['content'] ?? []) as $c) $text .= (string) ($c['text'] ?? '');
    }
    $data = self::json_from($text);
    if (!$data) return new WP_Error('aisb_bad_json', 'Het onderzoek gaf geen geldig antwoord.');
    $own_host = $own !== '' ? preg_replace('/^www\./', '', (string) wp_parse_url($own, PHP_URL_HOST)) : '';
    $peers = [];
    foreach ((array) ($data['peers'] ?? []) as $p) {
      if (!is_array($p)) continue;
      $url = esc_url_raw((string) ($p['url'] ?? ''));
      $host = preg_replace('/^www\./', '', (string) wp_parse_url($url, PHP_URL_HOST));
      if ($url === '' || $host === '' || ($own_host !== '' && $host === $own_host)) continue;
      $peers[] = [
        'name' => self::txt($p['name'] ?? '', 80),
        'url' => $url,
        'claims' => self::items($p['claims'] ?? [], 4, 140),
      ];
      if (count($peers) >= 4) break;
    }
    $out = [
      'peers' => $peers,
      'common' => self::items($data['common'] ?? [], 5, 140),
      'gaps' => self::items($data['gaps'] ?? [], 4, 160),
      'model' => (string) ($j['model'] ?? $model['model']),
      'tokens' => (int) ($j['usage']['total_tokens'] ?? 0),
      'secs' => sprintf('%.1f', microtime(true) - $t),
    ];
    self::log('research', 'done', ['peers' => count($peers), 'tokens' => $out['tokens'], 'secs' => $out['secs'], 'model' => $out['model']]);
    return $out;
  }

  /**
   * De richting: positionering, belofte, hoofdactie, bewijs, principes, vermijden, beeldtaal, vragen.
   *
   * @return array|WP_Error
   */
  public static function strategy(string $brief_text, array $research, string $chat_language = 'Dutch', string $style_hint = '') {
    $key = self::api_key();
    if ($key === '') return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $model = apply_filters('archer_ai_strategy_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']);
    $chat = ['Dutch' => 'Belgian Dutch (Flemish)', 'French' => 'Belgian French', 'English' => 'English', 'German' => 'German'][$chat_language] ?? 'Belgian Dutch (Flemish)';
    // De richting is een instructie voor de AI, geen sitetekst: altijd in het Engels. Zo neemt geen enkele
    // stap Nederlandse zinnen over op een Engelstalige site. Enkel de vragen aan de klant zijn in zijn taal.
    $system = "You are the senior designer and strategist at Archer, a Belgian web studio for small local businesses. Before the site is built, you set its direction. Return ONLY valid JSON, in English.\n"
      . "Rules:\n"
      . "- Use only facts from the brief. Never invent numbers, years, names, reviews, prices or guarantees.\n"
      . "- proof: facts from the brief that make a visitor trust this business (years active, team, method, materials, own money or other structural differences, certifications, reviews, references, guarantees). Not the list of services, not the region. Copy the facts, don't reword them into claims.\n"
      . "- positioning: one sentence: for whom, what, and why this business rather than the others. Build it on the brief's differentiator (what sets them apart) and on the research gaps, not on what every competitor says. No hype words.\n"
      . "- promise: the meaning of the home page hero message in at most 10 words (it will be written in the site language later).\n"
      . "- primary_action: the one thing a visitor must do (from the brief's main goal); type is one of call, quote, book, visit, order, form; label is the meaning of the button text in English (2 to 4 words).\n"
      . "- principles: 3 to 5 short rules for this site's content, specific to this business. No generic web advice.\n"
      . "- avoid: 2 to 4 things not to say, including claims every competitor already makes (from the research) when they add nothing.\n"
      . "- imagery: one consistent photo look for the whole site (light, setting, framing, colour mood) in one line, 3 to 6 concrete subjects that fit this business, and 1 to 3 things photos must not show.\n"
      . "JSON: {\"positioning\":\"\",\"promise\":\"\",\"primary_action\":{\"type\":\"\",\"label\":\"\"},\"proof\":[\"\"],\"principles\":[\"\"],\"avoid\":[\"\"],\"imagery\":{\"look\":\"\",\"subjects\":[\"\"],\"avoid\":[\"\"]}}";
    $user = "BRIEF:\n" . mb_substr($brief_text, 0, 6000);
    if (!empty($research['peers']) || !empty($research['common'])) {
      $user .= "\n\nRESEARCH (local businesses with the same activity, from their websites):\n";
      foreach ((array) $research['peers'] as $p) $user .= '- ' . $p['name'] . ': ' . implode(' / ', (array) $p['claims']) . "\n";
      if (!empty($research['common'])) $user .= "Claims most of them make: " . implode('; ', $research['common']) . "\n";
      if (!empty($research['gaps'])) $user .= "Gaps (what none of them says clearly): " . implode('; ', $research['gaps']) . "\n";
    } else {
      $user .= "\n\nRESEARCH: none available. Base the direction on the brief only.";
    }
    if ($style_hint !== '') $user .= "\n\nCHOSEN VISUAL STYLE: " . $style_hint;
    $body = ['model' => $model['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'response_format' => ['type' => 'json_object']];
    if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
      if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
    } else {
      $body['temperature'] = 0.4;
    }
    $t = microtime(true);
    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'timeout' => 60,
      'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
      'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($res)) return $res;
    $code = (int) wp_remote_retrieve_response_code($res);
    $j = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300) return new WP_Error('aisb_ai_' . $code, (string) ($j['error']['message'] ?? 'AI-fout ' . $code));
    $data = self::json_from((string) ($j['choices'][0]['message']['content'] ?? ''));
    if (!$data) return new WP_Error('aisb_bad_json', 'De strategie gaf geen geldig antwoord.');
    $s = self::clean_strategy($data);
    if ($s['positioning'] === '' && !$s['principles']) return new WP_Error('aisb_bad_json', 'De strategie was leeg.');
    $s['model'] = (string) ($j['model'] ?? $model['model']);
    $s['tokens'] = (int) ($j['usage']['total_tokens'] ?? 0);
    $s['secs'] = sprintf('%.1f', microtime(true) - $t);
    $s['research_used'] = !empty($research['peers']);
    // Vragen aan de eigenaar: een aparte stap die pagina per pagina nagaat wat er ontbreekt.
    $q = self::questions($brief_text, $chat_language);
    $s['questions'] = is_wp_error($q) ? [] : $q;
    self::log('strategy', 'done', ['questions' => count($s['questions']), 'tokens' => $s['tokens'], 'secs' => $s['secs'], 'model' => $s['model'], 'research' => $s['research_used']]);
    return $s;
  }

  /**
   * Vragen aan de eigenaar: wat de gekozen pagina's nodig hebben en de brief niet geeft, plus waarden die
   * niet kloppen. Gaat eerst pagina per pagina na wat ontbreekt (pages[]), kiest dan de 3 à 4 belangrijkste.
   *
   * @return array|WP_Error
   */
  public static function questions(string $brief_text, string $chat_language = 'Dutch') {
    $key = self::api_key();
    if ($key === '') return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $model = apply_filters('archer_ai_questions_model', ['model' => 'gpt-5.4-mini', 'effort' => 'medium']);
    $chat = ['Dutch' => 'Belgian Dutch (Flemish), informal je', 'French' => 'Belgian French', 'English' => 'English', 'German' => 'German'][$chat_language] ?? 'Belgian Dutch (Flemish), informal je';
    $system = "Archer is building a website for a small business. The owner filled in a brief. Before the pages are written, you find the facts that are still missing to give every page real content, and you ask the owner for them. Return ONLY valid JSON.\n"
      . "Step 1, pages: for EVERY page the brief asks for, write in English what that page needs to tell and whether the brief gives the concrete facts for it (enough: true/false, missing: what is missing). Service pages need: for whom exactly, what the client gets (amounts, sizes, duration or price range), how it differs from the other services. A process page needs the steps and their timing. A cases or references page needs, per case, the situation, what was done and the result. A vacancies page needs the open roles. An about page needs the story and the people.\n"
      . "Step 2, confirm: brief values that look wrong or contradict the rest (a certification or label that does not fit the activity, a 'customer need' that is really a channel, services that do not fit the target group). Empty if none.\n"
      . "Step 3, questions: 3 or 4 questions, chosen from step 1 (biggest gaps on the most important pages first) and step 2 (at most one). Rules:\n"
      . "- Facts only the owner knows. Never about design, layout, order, sections or wording.\n"
      . "- One topic per question, everyday words, no jargon (no 'governance', 'propositie', 'USP', 'due diligence'), at most 22 words, in {$chat}. Name the page when it is for one page.\n"
      . "- A confirm question quotes the value and has options ['Ja, klopt', 'Nee, weglaten'] (in the conversation language).\n"
      . "- options only when the answer is a choice, otherwise an empty list. proposal: one of the options or empty.\n"
      . "- example: for an open question, a realistic example answer as the owner would type it, max 14 words, in {$chat}; never an instruction or a question.\n"
      . "- Never ask what the brief already answers.\n"
      . "JSON: {\"pages\":[{\"page\":\"\",\"enough\":false,\"missing\":\"\"}],\"confirm\":[\"\"],\"questions\":[{\"page\":\"\",\"question\":\"\",\"options\":[],\"proposal\":\"\",\"example\":\"\"}]}";
    $body = ['model' => $model['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => "BRIEF:\n" . mb_substr($brief_text, 0, 6000)]], 'response_format' => ['type' => 'json_object']];
    if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
      if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
    } else {
      $body['temperature'] = 0.3;
    }
    $t = microtime(true);
    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', ['timeout' => 90, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key], 'body' => wp_json_encode($body)]);
    if (is_wp_error($res)) return $res;
    $code = (int) wp_remote_retrieve_response_code($res);
    $j = json_decode((string) wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300) return new WP_Error('aisb_ai_' . $code, (string) ($j['error']['message'] ?? 'AI-fout ' . $code));
    $data = self::json_from((string) ($j['choices'][0]['message']['content'] ?? ''));
    if (!$data) return new WP_Error('aisb_bad_json', 'De vragen gaven geen geldig antwoord.');
    $q = self::clean_strategy(['questions' => (array) ($data['questions'] ?? [])])['questions'];
    self::log('questions', 'done', ['questions' => count($q), 'gaps' => count(array_filter((array) ($data['pages'] ?? []), fn($p) => is_array($p) && empty($p['enough']))), 'secs' => sprintf('%.1f', microtime(true) - $t), 'tokens' => (int) ($j['usage']['total_tokens'] ?? 0)]);
    return $q;
  }

  /** Strategie valideren: enkel de verwachte velden, korte teksten, vragen met een geldig voorstel. */
  public static function clean_strategy(array $d): array {
    $types = ['call', 'quote', 'book', 'visit', 'order', 'form'];
    $pa = is_array($d['primary_action'] ?? null) ? $d['primary_action'] : [];
    $img = is_array($d['imagery'] ?? null) ? $d['imagery'] : [];
    $questions = [];
    foreach ((array) ($d['questions'] ?? []) as $i => $q) {
      if (!is_array($q)) continue;
      $opts = self::items($q['options'] ?? [], 4, 40);
      $prop = self::txt($q['proposal'] ?? '', 40);
      $text = self::txt($q['question'] ?? '', 200);
      if ($text === '' || count($opts) === 1) continue;
      // Een voorstel kan enkel een van de opties zijn.
      $match = '';
      foreach ($opts as $o) if (mb_strtolower($o) === mb_strtolower($prop)) $match = $o;
      $questions[] = [
        'id' => 'q' . (count($questions) + 1),
        'page' => self::txt($q['page'] ?? '', 60),
        'question' => $text,
        'options' => $opts,
        'proposal' => $match,
        'example' => self::txt($q['example'] ?? '', 100),
      ];
      if (count($questions) >= self::MAX_QUESTIONS) break;
    }
    return [
      'positioning' => self::txt($d['positioning'] ?? '', 260),
      'promise' => self::txt($d['promise'] ?? '', 120),
      'primary_action' => [
        'type' => in_array(($pa['type'] ?? ''), $types, true) ? $pa['type'] : 'form',
        'label' => self::txt($pa['label'] ?? '', 40),
      ],
      'proof' => self::items($d['proof'] ?? [], 5, 140),
      'principles' => self::items($d['principles'] ?? [], 5, 160),
      'avoid' => self::items($d['avoid'] ?? [], 4, 160),
      'imagery' => [
        'look' => self::txt($img['look'] ?? '', 200),
        'subjects' => self::items($img['subjects'] ?? [], 6, 60),
        'avoid' => self::items($img['avoid'] ?? [], 3, 80),
      ],
      'questions' => $questions,
    ];
  }

  /* ───────────────────────── Ontwerpvragen ───────────────────────── */

  /**
   * De volgende open vraag van deze draft, of null. Loopt de strategie nog (onderzoek + vragen duren
   * samen tot ± 50 s), dan wacht dit tot $wait seconden: liever even wachten dan de vragen overslaan.
   */
  public static function next_question(string $draft, int $wait = 0): ?array {
    $s = self::part($draft, 'strategy');
    $end = time() + $wait;
    while (!is_array($s) && $wait > 0 && time() < $end) {
      $run = (int) self::fresh_part($draft, 'strategy_running');
      if (!$run || time() - $run > 120) break;
      sleep(1);
      $s = self::fresh_part($draft, 'strategy');
    }
    if (!is_array($s) || empty($s['questions'])) return null;
    $cp = self::checkpoint($draft);
    if (!empty($cp['skipped'])) return null;
    foreach ($s['questions'] as $q) {
      if (!array_key_exists($q['id'], $cp['answers'])) return $q + ['total' => count($s['questions']), 'index' => count($cp['answers'])];
    }
    return null;
  }

  public static function checkpoint(string $draft): array {
    $cp = self::part($draft, 'checkpoint');
    $cp = is_array($cp) ? $cp : [];
    return ['answers' => is_array($cp['answers'] ?? null) ? $cp['answers'] : [], 'skipped' => !empty($cp['skipped'])];
  }

  /**
   * Bewaart het antwoord op een vraag. "Kies jij maar" neemt het voorstel (als er een is),
   * "Sla over" bewaart niets bruikbaars (de pagina blijft dan algemeen op dat punt).
   */
  public static function answer(string $draft, string $id, string $answer): void {
    $s = self::part($draft, 'strategy');
    if (!is_array($s)) return;
    $q = null;
    foreach ((array) ($s['questions'] ?? []) as $x) if ($x['id'] === $id) $q = $x;
    if (!$q) return;
    $answer = self::txt($answer, 400);
    $skip = $answer === '' || preg_match('/^(sla over|overslaan|weet ik niet|geen idee|nvt|n\.?v\.?t\.?)\b/iu', $answer);
    $take = !$skip && preg_match('/^(kies jij maar|maakt niet uit|jij mag kiezen|geen voorkeur|doe maar)\b/iu', $answer);
    $cp = self::checkpoint($draft);
    if ($skip || ($take && ($q['proposal'] ?? '') === '')) {
      $cp['answers'][$id] = ['page' => $q['page'] ?? '', 'question' => $q['question'], 'answer' => '', 'by' => 'skipped'];
    } else {
      $cp['answers'][$id] = ['page' => $q['page'] ?? '', 'question' => $q['question'], 'answer' => $take ? $q['proposal'] : $answer, 'by' => $take ? 'archer' : 'client'];
    }
    self::set_part($draft, 'checkpoint', $cp);
  }

  /** Vraag zoals de intake hem toont: opties (voorstel eerst) of een open vraag met een voorbeeld. */
  public static function question_turn(array $q, bool $first, array $strategy): array {
    $opts = [];
    if (!empty($q['options'])) {
      $opts = $q['proposal'] !== '' ? array_values(array_unique(array_merge([$q['proposal']], $q['options']))) : $q['options'];
    }
    $opts[] = 'Sla over';
    $n = (int) ($q['total'] ?? 1);
    $intro = $first ? ($n > 1 ? "Nog {$n} vragen, zodat elke pagina iets concreets te vertellen heeft. " : 'Nog één vraag, zodat de pagina\'s iets concreets te vertellen hebben. ') : '';
    $tail = '';
    if (!empty($q['example']) && empty($q['options'])) $tail = ' Bijvoorbeeld: ' . rtrim($q['example'], '.') . '.';
    elseif ($q['proposal'] !== '') $tail = ' Mijn voorstel: ' . $q['proposal'] . '.';
    return [
      'reply' => $intro . $q['question'] . $tail,
      'options' => array_slice($opts, 0, 5),
      'ask' => 'checkpoint_' . $q['id'],
    ];
  }

  /* ───────────────────────── Project ───────────────────────── */

  /**
   * Zet de richting van de draft op het project. Zonder strategie en met een brieftekst: nu maken
   * (met het onderzoek als dat al klaar is). Geeft de richting terug (leeg als er niets is).
   */
  public static function attach(int $pid, string $draft, string $brief_text = '', string $site_language = 'Dutch'): array {
    $draft = self::clean_draft($draft);
    $research = $draft !== '' ? self::part($draft, 'research') : null;
    $research = is_array($research) && ($research['status'] ?? '') === 'done' ? $research : [];
    $strategy = $draft !== '' ? self::part($draft, 'strategy') : null;
    if (!is_array($strategy) && $brief_text !== '') {
      $s = self::strategy($brief_text, $research, 'Dutch', self::style_hint_for_project($pid));
      $strategy = is_wp_error($s) ? null : $s;
    }
    if (!is_array($strategy)) return [];
    $decisions = [];
    foreach (self::checkpoint($draft)['answers'] as $a) $decisions[] = $a;
    $dir = [
      'v' => self::VERSION,
      'built_at' => gmdate('c'),
      'research' => $research ? ['peers' => $research['peers'] ?? [], 'common' => $research['common'] ?? [], 'gaps' => $research['gaps'] ?? []] : [],
      'strategy' => $strategy,
      'decisions' => $decisions,
      'language' => $site_language,
      'pages' => [],
    ];
    $dir['hash'] = substr(md5(wp_json_encode([$dir['research'], $strategy['positioning'] ?? '', $strategy['principles'] ?? [], $decisions])), 0, 10);
    if ($pid) self::save($pid, $dir);
    return $dir;
  }

  public static function save(int $pid, array $dir): void {
    if (!$pid || empty($dir['strategy'])) return;
    update_post_meta($pid, self::META, wp_slash(wp_json_encode($dir, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    self::log('attach', 'done', ['project' => $pid, 'hash' => $dir['hash'] ?? '', 'decisions' => count((array) ($dir['decisions'] ?? [])), 'research' => !empty($dir['research'])]);
  }

  /**
   * Verhaallijn per pagina, nadat de paginastructuur vastligt: de taak van de pagina, de boodschap,
   * de stappen van het verhaal in leesvolgorde, welke feiten de pagina "bezit" en wat elders verteld wordt.
   * Zo vertelt elke pagina iets eigens in plaats van overal hetzelfde blok te herhalen.
   *
   * @return array slug => [job, message, beats[], owns[], not_here[]]
   */
  public static function storyline(array $pages, string $brief_text, array $dir): array {
    $key = self::api_key();
    if ($key === '' || !$pages) return [];
    $model = apply_filters('archer_ai_storyline_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']);
    $list = [];
    foreach (array_slice($pages, 0, 24) as $p) {
      if (empty($p['slug'])) continue;
      $list[] = ['slug' => (string) $p['slug'], 'title' => (string) ($p['page_title'] ?? ''), 'type' => (string) ($p['page_type'] ?? ''), 'parent' => (string) ($p['parent_slug'] ?? ''), 'purpose' => (string) ($p['page_purpose'] ?? ''), 'template_for_items' => !empty($p['archer_collection'])];
    }
    $s = (array) ($dir['strategy'] ?? []);
    $answers = [];
    foreach ((array) ($dir['decisions'] ?? []) as $d) if (($d['answer'] ?? '') !== '') $answers[] = (!empty($d['page']) ? '[' . $d['page'] . '] ' : '') . $d['question'] . ' → ' . $d['answer'];
    $system = "You plan the storyline of every page of a small-business website before its sections and texts are written. Return ONLY valid JSON, in English.\n"
      . "Rules:\n"
      . "- Every page has one job for the visitor who lands on it and tells its own story. A visitor who reads three pages must learn something new on each one.\n"
      . "- The home page carries the positioning, the promise and the differentiator in short and leads to the other pages. Other pages do not repeat the home page's differentiator; at most one sentence refers to it, and only where it matters for that page.\n"
      . "- Service page: the situation of the client it is for, what the client gets concretely, how it works for this service, proof that fits this service, the next step.\n"
      . "- Overview of services: help the visitor choose (difference between the services, for whom each is), then the next step. Do not tell each service in full.\n"
      . "- About: why the business exists, the people, how they work day to day. Process page: the steps in order with what happens and how long. Listing: what the items have in common and how to pick one. Case or detail: the situation, what was done, the result. Template for items: the parts every item page has. Vacancies: why work here and the roles. Contact: how to reach them and what happens after.\n"
      . "- beats: 3 to 5 parts in reading order; each beat says in one short line what that part of the page tells. No section types, no layout.\n"
      . "- owns: the facts from the brief and the owner's answers that are told mainly on this page. Give every fact to the page where it matters most; a fact appears on at most 2 pages.\n"
      . "- not_here: themes that are told on other pages and that this page must not repeat.\n"
      . "- Use only facts from the brief and the owner's answers. Where they give nothing for a beat, keep that beat short and general and say so (no facts given); never invent details.\n"
      . "JSON: {\"pages\":[{\"slug\":\"\",\"job\":\"\",\"message\":\"\",\"beats\":[\"\"],\"owns\":[\"\"],\"not_here\":[\"\"]}]}";
    $user = "PAGES:\n" . wp_json_encode($list, JSON_UNESCAPED_UNICODE)
      . "\n\nBRIEF:\n" . mb_substr($brief_text, 0, 6000)
      . "\n\nDIRECTION:\nPositioning: " . ($s['positioning'] ?? '') . "\nProof: " . implode('; ', (array) ($s['proof'] ?? []))
      . ($answers ? "\n\nOWNER'S ANSWERS:\n- " . implode("\n- ", $answers) : '');
    $body = ['model' => $model['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'response_format' => ['type' => 'json_object']];
    if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
      if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
    } else {
      $body['temperature'] = 0.4;
    }
    $t = microtime(true);
    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', ['timeout' => 60, 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key], 'body' => wp_json_encode($body)]);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) {
      self::log('storyline', 'failed', ['error' => is_wp_error($res) ? $res->get_error_message() : (string) wp_remote_retrieve_response_code($res)]);
      return [];
    }
    $j = json_decode((string) wp_remote_retrieve_body($res), true);
    $data = self::json_from((string) ($j['choices'][0]['message']['content'] ?? ''));
    $out = [];
    $valid = array_flip(array_column($list, 'slug'));
    foreach ((array) ($data['pages'] ?? []) as $p) {
      $slug = sanitize_title((string) ($p['slug'] ?? ''));
      if ($slug === '' || !isset($valid[$slug])) continue;
      $out[$slug] = [
        'job' => self::txt($p['job'] ?? '', 200),
        'message' => self::txt($p['message'] ?? '', 200),
        'beats' => self::items($p['beats'] ?? [], 5, 180),
        'owns' => self::items($p['owns'] ?? [], 6, 160),
        'not_here' => self::items($p['not_here'] ?? [], 5, 120),
      ];
    }
    self::log('storyline', 'done', ['pages' => count($out), 'of' => count($list), 'secs' => sprintf('%.1f', microtime(true) - $t), 'tokens' => (int) ($j['usage']['total_tokens'] ?? 0)]);
    return $out;
  }

  public static function for_project(int $pid): array {
    if (!$pid) return [];
    $d = json_decode((string) get_post_meta($pid, self::META, true), true);
    return is_array($d) && !empty($d['strategy']) ? $d : [];
  }

  /**
   * De ontwerpbrief voor één AI-taak (plan, sections, copy, menu, photo). Leeg zonder richting.
   * Kort gehouden: elke taak krijgt enkel wat ze nodig heeft.
   */
  public static function prompt_block(int $pid, string $task, ?array $dir = null, string $slug = ''): string {
    $dir = $dir ?? self::for_project($pid);
    $s = $dir['strategy'] ?? null;
    if (!is_array($s)) return '';
    if ($task === 'photo') {
      $img = (array) ($s['imagery'] ?? []);
      if (empty($img['look'])) return '';
      return 'One photo look for the whole site: ' . rtrim($img['look'], '. ') . '.' . (!empty($img['avoid']) ? ' Never show: ' . implode(', ', $img['avoid']) . '.' : '');
    }
    $lang = self::site_language($pid, $dir);
    $story = $slug !== '' ? ($dir['pages'][$slug] ?? null) : null;
    $home = $slug === '' || $slug === 'home';
    $lines = [];
    $add = function (string $label, $v) use (&$lines) {
      if (is_array($v)) { $v = array_values(array_filter($v)); if (!$v) return; $lines[] = $label . ":\n- " . implode("\n- ", $v); return; }
      if (trim((string) $v) !== '') $lines[] = $label . ': ' . $v;
    };
    $action = trim((string) ($s['primary_action']['label'] ?? ''));
    $add('Positioning', $s['positioning'] ?? '');
    if ($task !== 'menu' && ($home || !$story)) $add('Main promise (home hero, meaning)', $s['promise'] ?? '');
    $add('Primary action (meaning of the button)', $action !== '' ? $action . ' (' . ($s['primary_action']['type'] ?? '') . ')' : '');
    if ($task === 'menu') return "DESIGN DIRECTION (in English for you; menu labels stay in {$lang}):\n" . implode("\n", $lines) . "\nThe header button (cta) is the page for the primary action.\n";
    // Het bewijs hoort bij de pagina's die het bezitten (verhaallijn); zonder verhaallijn: de algemene lijst.
    if (in_array($task, ['sections', 'copy'], true) && !$story) $add('Proof to use (facts from the brief only)', $s['proof'] ?? []);
    $add('Principles', $s['principles'] ?? []);
    $avoid = (array) ($s['avoid'] ?? []);
    if ($task === 'copy' && !empty($dir['research']['common'])) $avoid[] = 'Claims every competitor makes, unless you make them concrete: ' . implode('; ', array_slice($dir['research']['common'], 0, 4));
    $add('Avoid', $avoid);
    $dec = [];
    foreach ((array) ($dir['decisions'] ?? []) as $d) {
      if (($d['answer'] ?? '') === '') continue;
      if ($story && !empty($d['page']) && !self::page_match((string) $d['page'], $slug)) continue;
      $dec[] = (!empty($d['page']) ? '[' . $d['page'] . '] ' : '') . $d['question'] . ' → ' . $d['answer'];
    }
    $add("Facts and choices from the owner (they win over anything above; translate them into {$lang})", $dec);
    if ($story) {
      $lines[] = 'THIS PAGE (' . $slug . ") tells its own story; follow it:\n"
        . 'Job: ' . $story['job'] . "\nMessage: " . $story['message']
        . ($story['beats'] ? "\nBeats in reading order:\n- " . implode("\n- ", $story['beats']) : '')
        . ($story['owns'] ? "\nFacts this page tells:\n- " . implode("\n- ", $story['owns']) : '')
        . ($story['not_here'] ? "\nTold on other pages, do not repeat here:\n- " . implode("\n- ", $story['not_here']) : '');
    }
    if ($task === 'sections') {
      $rules = self::style_rules($pid);
      if ($rules !== '') $lines[] = $rules;
      if ($story) $lines[] = 'Plan the sections along the beats above, in that order (one section per beat, plus header and footer). Do not add a section for a theme that is told on other pages.';
    }
    if (!$lines) return '';
    $out = "DESIGN DIRECTION (written in English for you; write every visible text in {$lang}; follow it for emphasis and order, never against the brief, the rules above or the facts):\n" . implode("\n", $lines) . "\n";
    return mb_substr($out, 0, 3200) . "\n";
  }

  /** Taal van de site (eerste projecttaal), als naam voor de prompt. */
  public static function site_language(int $pid, array $dir = []): string {
    $l = $pid ? json_decode((string) get_post_meta($pid, 'aisb_project_languages', true), true) : null;
    $first = is_array($l) && $l ? (string) $l[0] : (string) ($dir['language'] ?? 'Dutch');
    return ['Dutch' => 'Belgian Dutch', 'French' => 'French', 'English' => 'English', 'German' => 'German'][$first] ?? $first;
  }

  /** Hoort een antwoord bij deze pagina? (paginanaam uit de brief tegenover de slug) */
  private static function page_match(string $page, string $slug): bool {
    $a = sanitize_title($page);
    return $a === $slug || strpos($slug, $a) !== false || strpos($a, $slug) !== false;
  }

  /** Do's en don'ts van de gekozen stijl (design-notes.json), voor het sectieplan. */
  private static function style_rules(int $pid): string {
    if (!$pid || !class_exists('AISB_Archer_Styles')) return '';
    $guide = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    $st = AISB_Archer_Styles::of($guide);
    if (!$st) return '';
    $n = AISB_Archer_Styles::notes((string) $st['id']);
    if (!$n) return '';
    return 'Style "' . $st['name'] . '" layout rules. Do: ' . implode('; ', array_slice((array) ($n['do'] ?? []), 0, 4)) . '. Don\'t: ' . implode('; ', array_slice((array) ($n['dont'] ?? []), 0, 4)) . '.';
  }

  /** Korte beschrijving van een Archer-stijl voor de strategie. */
  private static function style_hint(string $id): string {
    if ($id === '' || !class_exists('AISB_Archer_Styles')) return '';
    $st = AISB_Archer_Styles::get($id);
    if (!$st) return '';
    $n = AISB_Archer_Styles::notes($id);
    return $st['name'] . ': ' . ($n['theme'] ?? ($st['pitch'] ?? '')) . (!empty($n['photo']) ? ' Photo direction: ' . $n['photo'] . '.' : '');
  }

  private static function style_hint_for_project(int $pid): string {
    if (!$pid || !class_exists('AISB_Archer_Styles')) return '';
    $st = AISB_Archer_Styles::of(json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true));
    return $st ? self::style_hint((string) $st['id']) : '';
  }

  /* ───────────────────────── Hulp ───────────────────────── */

  /** JSON uit een AI-antwoord (ook met ```json-hekken of tekst eromheen). */
  public static function json_from(string $text): ?array {
    $text = trim($text);
    $d = json_decode($text, true);
    if (is_array($d)) return $d;
    if (preg_match('/\{.*\}/s', $text, $m)) {
      $d = json_decode($m[0], true);
      if (is_array($d)) return $d;
    }
    return null;
  }

  private static function txt($v, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(is_array($v) ? implode(' ', array_filter($v, 'is_string')) : (string) $v)));
    // Bronverwijzingen van de websearch weg: (bakkerij.be) of [1].
    $s = trim(preg_replace('/\s*(\[\d+\]|\(\s*\[?[a-z0-9.-]+\.[a-z]{2,}[^)]*\))/iu', '', $s));
    // Aanhalingstekens rond een geciteerde claim weg.
    $s = trim((string) preg_replace('/^["\'“”„‘’«»]+|["\'“”„‘’«»]+$/u', '', $s));
    return mb_substr($s, 0, $max);
  }

  private static function items($v, int $n, int $max): array {
    $out = [];
    foreach ((array) $v as $x) {
      $t = self::txt($x, $max);
      if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
      if (count($out) >= $n) break;
    }
    return $out;
  }

  /** Eén regel per stap in het logboek van de plugin (debug.log als WP_DEBUG_LOG aan staat). */
  private static function log(string $step, string $status, array $data = []): void {
    $line = '[Archer direction] ' . $step . ' ' . $status . ' ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE);
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log($line);
    $log = get_option('archer_direction_log', []);
    $log = is_array($log) ? $log : [];
    $log[] = gmdate('c') . ' ' . $line;
    update_option('archer_direction_log', array_slice($log, -200), false);
  }
}
