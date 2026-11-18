<?php
if (!defined('ABSPATH')) exit;

/**
 * Het stemprofiel van één klant: voor wie de zaak schrijft, hoe ze klinkt, welke twijfels er leven
 * en met welke woorden de eigenaar zelf over zijn zaak praat. Eén keer gemaakt bij de intake,
 * daarna gelezen door alles wat tekst maakt of nakijkt: het sectieplan, de teksten, de copycoach
 * en later de blog en de contentagent. Zonder dit profiel begint elk van die stappen van nul en
 * verzint elke stap opnieuw een toon.
 *
 * De strategie (AISB_Archer_Direction) zegt WAT er staat en waarom; dit profiel zegt TEGEN WIE het
 * gezegd wordt en HOE het klinkt. Ze overlappen niet: bewijs, positionering en de hoofdactie
 * blijven bij de strategie.
 *
 * Bewaard als archer_voice op het project, los van de richting, omdat het profiel de site overleeft:
 * de blog van volgende maand leest hetzelfde profiel als de site van vandaag.
 */
class AISB_Archer_Voice {

  const META = 'archer_voice';
  const VERSION = 1;

  /** Aan/uit voor het hele profiel, met de filter archer_voice. */
  public static function on(): bool {
    return (bool) apply_filters('archer_voice', true);
  }

  public static function for_project(int $pid): array {
    if (!$pid) return [];
    $v = json_decode((string) get_post_meta($pid, self::META, true), true);
    return is_array($v) && !empty($v['audience']['who']) ? $v : [];
  }

  public static function save(int $pid, array $v): void {
    if (!$pid || empty($v['audience']['who'])) return;
    update_post_meta($pid, self::META, wp_slash(wp_json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
  }

  /**
   * Het profiel opbouwen uit de brief en, als de eigenaar er een insprak, zijn eigen verhaal.
   * Eén AI-vraag, één keer per project: bestaat er al een profiel voor dezelfde invoer, dan blijft dat staan.
   *
   * @param array  $brief      De bevestigde brief van de intake (AISB_Archer_Intake::brief_for).
   * @param string $brief_text De brief als tekst, zoals de andere stappen hem krijgen.
   * @return array|WP_Error
   */
  public static function build(int $pid, array $brief, string $brief_text = '', string $language = 'Dutch') {
    if (!self::on()) return [];
    $spoken = trim((string) ($brief['voice_note'] ?? ''));
    $hash = substr(md5($brief_text . '|' . $spoken . '|' . self::VERSION), 0, 10);
    $old = self::for_project($pid);
    if (($old['hash'] ?? '') === $hash) return $old;

    $key = self::api_key();
    if ($key === '') return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $model = apply_filters('archer_ai_voice_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']);
    $lang = ['Dutch' => 'Belgian Dutch (Flemish)', 'French' => 'Belgian French', 'English' => 'English', 'German' => 'German'][$language] ?? 'Belgian Dutch (Flemish)';

    $system = "You build the voice profile of one small business, before its website is written. Everything you return is an instruction for a writer, so write it in English. The quoted words and sentences are the exception: those stay in the owner's own language, word for word.\n"
      . "Return ONLY valid JSON.\n"
      . "Rules:\n"
      . "- Use only what the brief and the owner's own story give you. Never invent a customer, a doubt, a number or a phrase. An empty list is a correct answer.\n"
      . "- audience.who: who actually buys, in one line (never 'everyone'; make the brief's target group concrete). audience.situation: what is going on with them the moment they look for a business like this.\n"
      . "- audience.words: 3 to 6 words or short phrases THEY use for their problem or wish, taken from the brief or the owner's story, in {$lang}. Customer language, never company language.\n"
      . "- tone.register: how this business sounds, in one line, built on the brief's tone and on the way the owner himself talks (short or roomy sentences, dry or warm, technical or plain). Not a list of adjectives.\n"
      . "- tone.rules: 3 to 5 concrete writing rules that follow from that register, each one a writer can obey or break ('name the price range out loud', 'one idea per sentence', 'name the brand of the machines'). No generic web advice.\n"
      . "- tone.avoid: 2 to 4 things that would sound wrong for THIS business.\n"
      . "- objections: the doubts that stop this customer from acting, from the brief ('Twijfels van klanten') and from the owner's story. Per doubt: doubt (what they think, in {$lang}, their words) and answer (what this business can honestly say back, from the brief; leave empty when the brief gives no answer).\n"
      . "- phrases: 2 to 6 sentences or expressions the owner used himself that are worth keeping on the site, copied word for word in {$lang}. Only from the owner's own story. Empty when there is none.\n"
      . "- knows: 3 to 6 concrete details only this owner could have told you (the story, how long, a habit, a speciality, a machine, a name). These are what make the site specific instead of generic. Only what is really in the input.\n"
      . "JSON: {\"audience\":{\"who\":\"\",\"situation\":\"\",\"words\":[\"\"]},\"tone\":{\"register\":\"\",\"rules\":[\"\"],\"avoid\":[\"\"]},\"objections\":[{\"doubt\":\"\",\"answer\":\"\"}],\"phrases\":[\"\"],\"knows\":[\"\"]}";

    $user = "BRIEF:\n" . mb_substr($brief_text, 0, 6000);
    if ($spoken !== '') {
      $user .= "\n\nTHE OWNER'S OWN STORY (he recorded it himself and it was transcribed; this is how he talks, and it holds details the brief does not have. The transcription may contain small errors; never copy an obvious mistake):\n"
        . mb_substr($spoken, 0, 6000);
    } else {
      $user .= "\n\nTHE OWNER'S OWN STORY: none. Leave phrases empty and build knows from the brief only.";
    }

    $body = ['model' => $model['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'response_format' => ['type' => 'json_object']];
    if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
      if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
    } else {
      $body['temperature'] = 0.3;
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
    $data = class_exists('AISB_Archer_Direction') ? AISB_Archer_Direction::json_from((string) ($j['choices'][0]['message']['content'] ?? '')) : json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($data)) return new WP_Error('aisb_bad_json', 'Het stemprofiel gaf geen geldig antwoord.');

    $v = self::clean($data);
    if ($v['audience']['who'] === '') return new WP_Error('aisb_bad_json', 'Het stemprofiel was leeg.');
    $v['hash'] = $hash;
    $v['v'] = self::VERSION;
    $v['spoken'] = $spoken !== '';
    $v['built_at'] = gmdate('c');
    $v['secs'] = sprintf('%.1f', microtime(true) - $t);
    if ($pid) self::save($pid, $v);
    error_log(sprintf('[AISB] Archer stemprofiel: %d twijfel(s), %d eigen zin(nen), %d detail(s)%s (%ss).', count($v['objections']), count($v['phrases']), count($v['knows']), $spoken !== '' ? ', uit het verhaal van de eigenaar' : '', $v['secs']));
    return $v;
  }

  /** Alleen de verwachte velden, korte teksten, geen lege rijen. */
  public static function clean(array $d): array {
    $a = is_array($d['audience'] ?? null) ? $d['audience'] : [];
    $t = is_array($d['tone'] ?? null) ? $d['tone'] : [];
    $obj = [];
    foreach ((array) ($d['objections'] ?? []) as $o) {
      if (!is_array($o)) continue;
      $doubt = self::txt($o['doubt'] ?? '', 160);
      if ($doubt === '') continue;
      $obj[] = ['doubt' => $doubt, 'answer' => self::txt($o['answer'] ?? '', 200)];
      if (count($obj) >= 6) break;
    }
    return [
      'audience' => [
        'who' => self::txt($a['who'] ?? '', 200),
        'situation' => self::txt($a['situation'] ?? '', 240),
        'words' => self::items($a['words'] ?? [], 6, 60),
      ],
      'tone' => [
        'register' => self::txt($t['register'] ?? '', 240),
        'rules' => self::items($t['rules'] ?? [], 5, 140),
        'avoid' => self::items($t['avoid'] ?? [], 4, 120),
      ],
      'objections' => $obj,
      'phrases' => self::items($d['phrases'] ?? [], 6, 180),
      'knows' => self::items($d['knows'] ?? [], 6, 180),
    ];
  }

  /**
   * Het profiel als instructie voor één AI-taak. Elke taak krijgt enkel wat ze nodig heeft:
   * het paginaplan de doelgroep, het sectieplan daarbij de twijfels, de teksten alles,
   * de nalezing de regels om op te toetsen, de blog het profiel zonder de paginadingen.
   *
   * @param string $task plan|sections|copy|review|blog
   */
  public static function prompt_block(int $pid, string $task, ?array $v = null): string {
    $v = $v ?? self::for_project($pid);
    if (!$v) return '';
    $lines = [];
    $add = function (string $label, $val) use (&$lines) {
      if (is_array($val)) { $val = array_values(array_filter($val)); if (!$val) return; $lines[] = $label . ":\n- " . implode("\n- ", $val); return; }
      if (trim((string) $val) !== '') $lines[] = $label . ': ' . $val;
    };
    $who = trim($v['audience']['who'] . ($v['audience']['situation'] !== '' ? '. ' . $v['audience']['situation'] : ''));
    $add('Who reads this', $who);
    if ($task !== 'plan') $add('Their own words for it (use these over company language)', $v['audience']['words']);
    $add('How this business sounds', $v['tone']['register']);
    if (in_array($task, ['copy', 'review', 'blog'], true)) {
      $add('Writing rules for this business', $v['tone']['rules']);
      $add('Would sound wrong here', $v['tone']['avoid']);
    }
    if ($task !== 'blog') {
      $doubts = [];
      foreach ($v['objections'] as $o) $doubts[] = $o['doubt'] . ($o['answer'] !== '' ? ' -> ' . $o['answer'] : ' -> (the brief gives no answer; do not invent one)');
      $add('Doubts to answer, with what may be said back', $doubts);
    }
    if (in_array($task, ['copy', 'blog'], true)) $add("The owner's own sentences (reuse them as they are where they fit)", $v['phrases']);
    if ($task !== 'plan') $add('Details only this owner knows (use them; they are what keeps this site from reading like every other one)', $v['knows']);
    if (!$lines) return '';
    return "VOICE PROFILE (this client's own voice, written in English for you; the quoted words and sentences stay exactly as they are):\n" . implode("\n", $lines) . "\n";
  }

  /** Korte samenvatting voor de interface en de controle voor publiceren. */
  public static function summary(int $pid): array {
    $v = self::for_project($pid);
    if (!$v) return ['has' => false];
    return [
      'has' => true,
      'spoken' => !empty($v['spoken']),
      'who' => $v['audience']['who'],
      'register' => $v['tone']['register'],
      'objections' => count($v['objections']),
      'phrases' => count($v['phrases']),
      'knows' => count($v['knows']),
    ];
  }

  private static function api_key(): string {
    $s = class_exists('AISB_Settings') ? (array) (new AISB_Settings())->get_settings() : (array) get_option('aisb_settings', []);
    return (string) ($s['api_key'] ?? '');
  }

  private static function txt($v, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags(is_array($v) ? implode(' ', array_filter($v, 'is_string')) : (string) $v)));
    return mb_substr(trim((string) preg_replace('/^["\x{201C}\x{201D}\x{201E}\x{2018}\x{2019}]+|["\x{201C}\x{201D}\x{201E}\x{2018}\x{2019}]+$/u', '', $s)), 0, $max);
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
}
