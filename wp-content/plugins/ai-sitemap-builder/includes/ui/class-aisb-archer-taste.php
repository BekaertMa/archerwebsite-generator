<?php
if (!defined('ABSPATH')) exit;

/**
 * Ontwerpregels uit tasteskill v2 (github.com/leonxlnx/taste-skill, MIT-licentie, © 2026 Leonxlnx).
 *
 * Archer bouwt pagina's uit Brixies-sjablonen, dus de skill zelf (scripts, browsercontrole) draait
 * hier niet. Wat we overnemen:
 * - Stijl: stijlrichtingen per sector, elk met een palet (achtergrond, tekst, accent, vlakken) en
 *   een lettertypeset (alleen Google Fonts). Bron: taste/library.json, ingekort uit de bibliotheek.
 * - Teksten: de copy-regels en de lijst met stockzinnen, voor de AI-teksten van de wireframes.
 * - Secties: de huisregels die op sjabloonniveau kunnen (geen labels boven titels, geen dunne
 *   lijnen, geen icoon bovenaan kaarten, geen 01/02/03, geen schaduwen op wat stil staat).
 *
 * Aangevuld met regels uit de "craft floor" van impeccable (github.com/pbakaus/impeccable,
 * Apache-2.0): browseroppervlakken (selectie, cursor, focus, links) in de kleuren van de site,
 * getinte in plaats van grijze tekst op gekleurde achtergronden (archer.js), cijfers in kolommen.
 */
class AISB_Archer_Taste {

  private static $lib = null;

  public function init(): void {
    add_action('wp_ajax_aisb_archer_taste_deal', [$this, 'ajax_deal']);
    add_action('wp_ajax_aisb_archer_taste_shuffle', [$this, 'ajax_shuffle']);
    add_action('wp_head', [$this, 'preview_house_css'], 99);
  }

  public static function library(): array {
    if (self::$lib === null) {
      $raw = @file_get_contents(__DIR__ . '/taste/library.json');
      $lib = $raw ? json_decode($raw, true) : null;
      self::$lib = is_array($lib) ? $lib : ['directions' => [], 'palettes' => [], 'types' => []];
    }
    return self::$lib;
  }

  /* ───────────────────────── Stijlrichtingen ───────────────────────── */

  /** Sector uit de projectomschrijving (de sectoren van de taste-bibliotheek). */
  public static function sector_for(string $text): string {
    $t = mb_strtolower($text);
    // Woorden met een woordgrens vooraan (Nederlandse samenstellingen tellen mee: "garagebedrijf").
    $map = [
      'real-estate' => 'vastgoed|immo|makelaar|te koop|te huur|appartementen|woningen|real estate|immobili',
      'restaurant' => 'restaurant|brasserie|bistro|eetcaf|pizzeria|frituur|traiteur|bakker|slager|menukaart|reservat|reserveren',
      'hospitality' => 'hotel|b&b|bed and breakfast|gastenkamer|vakantiewoning|camping|horeca|koffiebar|logies',
      'ecommerce' => 'webshop|e-commerce|ecommerce|online winkel|online shop|boutique en ligne',
      'health' => 'praktijk|kinesi|fysiotherap|osteopa|tandarts|dokter|huisarts|psycholo|therapeut|thuiszorg|zorgverlen|apothe|dierenarts|vroedvrouw|diëtist|logoped|wellness|massage',
      'architecture' => 'architect|bouwbedrijf|aannemer|renovatie|interieur|schrijnwerk|dakwerk|tuinaanleg|nieuwbouw',
      'finance' => 'boekhoud|accountan|fiscal|verzeker|financ|bank\b|notaris|advoca|jurid',
      'education' => 'school|opleiding|cursus|academie|bijles|rijschool',
      'event' => 'evenement|events?\b|festival|beurs\b|concert|congres|huwelijk',
      'agency' => 'agency|marketingbureau|marketingagency|reclamebureau|communicatiebureau|webdesign|webbureau|webagency|creatief bureau|ontwerpbureau|branding',
      'saas' => 'software|saas|platform|applicatie',
      'fashion' => 'mode\b|kleding|kapsalon|kapper|beauty|schoonheid|juwel|fashion',
      'nonprofit' => 'vzw|vereniging|stichting|goed doel|jeugdbeweging|sportclub',
      'culture' => 'museum|theater|kunst|galerie|cultuur|bibliotheek',
      'music' => 'muziek|muzikant|\bband\b|dj\b|artiest|koor\b',
      'physical-product' => 'fabrikant|meubel|ambacht|brouwerij|atelier',
    ];
    $best = 'other';
    $bestScore = 0;
    foreach ($map as $sector => $re) {
      $n = preg_match_all('/\b(' . $re . ')/u', $t);
      if ($n > $bestScore) { $best = $sector; $bestScore = $n; }
    }
    return $best;
  }

  private static function rnd(int &$state, int $n): int {
    $state = (int) ((($state * 1103515245) + 12345) & 0x7fffffff);
    return $n > 0 ? $state % $n : 0;
  }

  /** Drie stijlrichtingen voor dit project; `round` geeft een andere trekking. */
  public static function deal(int $project_id, int $round = 0, int $count = 3): array {
    $lib = self::library();
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true) . ' ' . get_the_title($project_id);
    $sector = self::sector_for($brief);
    $dirs = array_values(array_filter($lib['directions'], fn($d) => in_array($sector, $d['sectors'], true)));
    if (count($dirs) < $count) {
      $extra = array_values(array_filter($lib['directions'], fn($d) => in_array('other', $d['sectors'], true) && !in_array($d, $dirs, true)));
      $dirs = array_merge($dirs, $extra);
    }
    if (count($dirs) < $count) $dirs = $lib['directions'];
    $state = (int) (crc32($project_id . '|' . $round) & 0x7fffffff);
    // Schudden met een vaste trekking per project en ronde.
    for ($i = count($dirs) - 1; $i > 0; $i--) {
      $j = self::rnd($state, $i + 1);
      [$dirs[$i], $dirs[$j]] = [$dirs[$j], $dirs[$i]];
    }
    $out = [];
    $used_p = [];
    $used_t = [];
    foreach (array_slice($dirs, 0, $count) as $d) {
      $pp = array_values(array_filter($lib['palettes'], fn($p) => !isset($used_p[$p['id']]) && (in_array($p['id'], $d['palettes'], true) || array_intersect($p['tags'], $d['paletteTags']))));
      $tp = array_values(array_filter($lib['types'], fn($t) => !isset($used_t[$t['id']]) && (in_array($t['id'], $d['type'], true) || in_array($t['class'], $d['typeClasses'], true))));
      if (!$pp) $pp = $lib['palettes'];
      if (!$tp) $tp = $lib['types'];
      $p = $pp[self::rnd($state, count($pp))];
      $t = $tp[self::rnd($state, count($tp))];
      $used_p[$p['id']] = 1;
      $used_t[$t['id']] = 1;
      $out[] = [
        'id' => $d['id'],
        // De naam beschrijft wat je krijgt (het palet); de richting zelf gaat over compositie,
        // en die komt in Archer uit de sjablonen.
        'name' => $p['name_nl'] ?? $p['name'],
        'direction' => $d['name'],
        'typeNote' => self::type_label($t),
        'palette' => $p,
        'colourNote' => self::palette_label($p),
        'type' => $t,
        'colours' => self::roles($p),
        'headingFont' => $t['display']['family'],
        'bodyFont' => $t['text']['family'],
      ];
    }
    return ['sector' => $sector, 'round' => $round, 'directions' => $out];
  }

  private static function type_label(array $t): string {
    $labels = [
      'grotesk-tight' => 'strak schreefloos', 'grotesk-light' => 'licht schreefloos', 'condensed-heavy' => 'smal en krachtig',
      'extended-heavy' => 'breed en krachtig', 'heavy-black' => 'zwaar en luid', 'serif-display' => 'klassieke schreef',
      'serif-light-large' => 'fijne schreef', 'serif-text' => 'leesbare schreef', 'slab' => 'blokschreef',
      'geometric' => 'geometrisch', 'rounded-friendly' => 'rond en vriendelijk',
    ];
    return $labels[$t['class']] ?? 'eigen letter';
  }

  private static function palette_label(array $p): string {
    $labels = [
      'light-neutral' => 'licht en neutraal', 'dark-neutral' => 'donker', 'two-tone' => 'twee kleuren',
      'mono-accent' => 'neutraal met één accent', 'earth' => 'aardetinten', 'flat-loud' => 'fel en vlak',
      'single-field' => 'één groot kleurvlak', 'photographic-neutral' => "rustig rond foto's", 'pastel-fields' => 'pastelvlakken',
    ];
    $t = $p['tags'][0] ?? '';
    return ucfirst($labels[$t] ?? 'eigen palet');
  }

  /**
   * Paletrollen van taste → de kleurnamen van de stijlgids.
   * Primary: knoppen en links (het accent), Light/Neutral: de sectieachtergronden (grond en
   * een stap in toon), Dark: de tekst, Secondary: het contrastvlak (bv. een donkere footer).
   */
  public static function roles(array $p): array {
    // Lichte gronden: Light en Neutral zijn altijd licht, Dark altijd het donkerste. De sterke kleur gaat naar Secondary.
    $accent = $p['accent'];
    $bg = $p['bg'];
    $surface = $p['surface'] ?? $bg;
    $ink = $p['ink'];
    $lbg = self::lum($bg);
    $strong = $p['fields'][0]['bg'] ?? ($lbg < 0.72 ? $bg : $ink);
    if ($lbg < 0.72 && ($lbg > 0.08 || ($lbg > 0.03 && self::sat($bg) > 0.5))) $strong = $bg; // het palet is zelf een kleurvlak
    $dark = self::lum($ink) <= $lbg ? $ink : $bg;
    if (self::lum($dark) > 0.05) $dark = self::tone($dark, 0.14, 0.5);
    $src = $lbg >= 0.4 ? $bg : (self::lum($ink) >= 0.75 ? $ink : $accent);
    $light = $lbg >= 0.75 ? $bg : self::tone($src, 0.965, 0.3);
    $neutral = $lbg >= 0.75 && self::lum($surface) >= 0.62 ? $surface : self::tone($src, 0.925, 0.3);
    $primary = $accent;
    $ratio = fn($a, $b) => (max(self::lum($a), self::lum($b)) + 0.05) / (min(self::lum($a), self::lum($b)) + 0.05);
    if ($ratio($accent, $light) < 3 && $ratio($strong, $light) >= 3) { $primary = $strong; $strong = $accent; }
    return [
      ['name' => 'Primary', 'hex' => $primary],
      ['name' => 'Secondary', 'hex' => $strong],
      ['name' => 'Accent', 'hex' => $accent],
      ['name' => 'Dark', 'hex' => $dark],
      ['name' => 'Light', 'hex' => $light],
      ['name' => 'Neutral', 'hex' => $neutral],
    ];
  }

  private static function rgb(string $hex): array {
    $h = ltrim($hex, '#');
    if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
    return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
  }

  private static function sat(string $hex): float {
    [$r, $g, $b] = array_map(fn($v) => $v / 255, self::rgb($hex));
    $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2;
    return $max === $min ? 0.0 : ($max - $min) / (1 - abs(2 * $l - 1));
  }

  public static function lum(string $hex): float {
    $f = function ($c) { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
    [$r, $g, $b] = self::rgb($hex);
    return 0.2126 * $f($r) + 0.7152 * $f($g) + 0.0722 * $f($b);
  }

  /** Zelfde tint, vaste lichtheid (HSL), verzadiging begrensd. */
  public static function tone(string $hex, float $L, float $smax): string {
    [$r, $g, $b] = array_map(fn($v) => $v / 255, self::rgb($hex));
    $max = max($r, $g, $b); $min = min($r, $g, $b); $d = $max - $min; $l = ($max + $min) / 2;
    $h = 0; $s = 0;
    if ($d > 0) {
      $s = $d / (1 - abs(2 * $l - 1));
      if ($max === $r) $h = fmod(($g - $b) / $d, 6);
      elseif ($max === $g) $h = ($b - $r) / $d + 2;
      else $h = ($r - $g) / $d + 4;
      $h *= 60; if ($h < 0) $h += 360;
    }
    $s = min($s, $smax);
    $c = (1 - abs(2 * $L - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $L - $c / 2;
    [$r, $g, $b] = $h < 60 ? [$c, $x, 0] : ($h < 120 ? [$x, $c, 0] : ($h < 180 ? [0, $c, $x] : ($h < 240 ? [0, $x, $c] : ($h < 300 ? [$x, 0, $c] : [$c, 0, $x]))));
    return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
  }

  /** Een ander palet of een andere lettertypeset binnen dezelfde richting (het andere blijft staan). */
  public static function shuffle(string $direction_id, string $kind, array $exclude, int $seed): ?array {
    $lib = self::library();
    $d = null;
    foreach ($lib['directions'] as $x) if ($x['id'] === $direction_id) $d = $x;
    $state = $seed & 0x7fffffff;
    if ($kind === 'palette') {
      $pool = $d ? array_values(array_filter($lib['palettes'], fn($p) => in_array($p['id'], $d['palettes'], true) || array_intersect($p['tags'], $d['paletteTags']))) : $lib['palettes'];
      $left = array_values(array_filter($pool, fn($p) => !in_array($p['id'], $exclude, true)));
      if (!$left) $left = array_values(array_filter($lib['palettes'], fn($p) => !in_array($p['id'], $exclude, true)));
      if (!$left) return null;
      $p = $left[self::rnd($state, count($left))];
      return ['palette' => $p, 'name' => $p['name_nl'] ?? $p['name'], 'colourNote' => self::palette_label($p), 'colours' => self::roles($p)];
    }
    $pool = $d ? array_values(array_filter($lib['types'], fn($t) => in_array($t['id'], $d['type'], true) || in_array($t['class'], $d['typeClasses'], true))) : $lib['types'];
    $left = array_values(array_filter($pool, fn($t) => !in_array($t['id'], $exclude, true)));
    if (!$left) $left = array_values(array_filter($lib['types'], fn($t) => !in_array($t['id'], $exclude, true)));
    if (!$left) return null;
    $t = $left[self::rnd($state, count($left))];
    return ['type' => $t, 'typeNote' => self::type_label($t), 'headingFont' => $t['display']['family'], 'bodyFont' => $t['text']['family']];
  }

  private function guard_sg(): int {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok = false;
    foreach (['aisb_sg_nonce', 'aisb_wf_nonce', 'aisb_nonce_action'] as $action) {
      if ($nonce && wp_verify_nonce($nonce, $action)) $ok = true;
    }
    if (!$ok) wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $project_id ? get_post($project_id) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    return $project_id;
  }

  public function ajax_shuffle(): void {
    $this->guard_sg();
    $kind = (isset($_POST['kind']) && $_POST['kind'] === 'type') ? 'type' : 'palette';
    $dir = sanitize_key(wp_unslash($_POST['direction'] ?? ''));
    $exclude = array_map('sanitize_key', (array) json_decode(wp_unslash($_POST['exclude'] ?? '[]'), true));
    $out = self::shuffle($dir, $kind, $exclude, random_int(1, PHP_INT_MAX >> 1));
    if (!$out) wp_send_json_error(['message' => 'Geen andere keuze meer'], 404);
    wp_send_json_success($out);
  }

  /* ───────────────────────── Kleurschema per sectie ───────────────────────── */

  /** Schema-id → kleurrol in de stijlgids (zelfde lijst als in archer.js). */
  const SCHEME_ROLE = ['ground' => 'Light', 'tone' => 'Neutral', 'field' => 'Secondary', 'accent' => 'Primary', 'ink' => 'Dark'];

  /**
   * Zet de gekozen kleurschema's (stijlgids → sectionSchemes) als bg_color op de secties van de
   * export, zodat de gepubliceerde site dezelfde achtergronden krijgt als het voorbeeld.
   */
  public static function apply_schemes_to_export(array $pages, array $guide): array {
    $map = isset($guide['sectionSchemes']) && is_array($guide['sectionSchemes']) ? $guide['sectionSchemes'] : [];
    if (!$map) return $pages;
    $colour = function (string $role) use ($guide): string {
      foreach ((array) ($guide['colours'] ?? []) as $c) {
        if (is_array($c) && ($c['name'] ?? '') === $role && preg_match('/^#[0-9a-f]{6}$/i', (string) ($c['hex'] ?? ''))) return (string) $c['hex'];
      }
      return '';
    };
    foreach ($pages as &$page) {
      $slug = (string) ($page['slug'] ?? '');
      foreach ($page['sections'] as &$sec) {
        if (!is_array($sec)) continue;
        $type = strtolower((string) ($sec['type'] ?? ''));
        $id = '';
        if (in_array($type, ['header', 'footer'], true)) $id = (string) ($map['global'][$type] ?? '');
        elseif (!empty($sec['uuid'])) $id = (string) ($map['pages'][$slug][(string) $sec['uuid']] ?? '');
        if ($id === '' || !isset(self::SCHEME_ROLE[$id])) continue;
        $hex = $colour(self::SCHEME_ROLE[$id]);
        if ($hex !== '') $sec['bg_color'] = $hex;
      }
      unset($sec);
    }
    unset($page);
    return $pages;
  }

  public function ajax_deal(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok = false;
    foreach (['aisb_sg_nonce', 'aisb_wf_nonce', 'aisb_nonce_action', 'aisb_style_guide_nonce'] as $action) {
      if ($nonce && wp_verify_nonce($nonce, $action)) $ok = true;
    }
    if (!$ok) wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $project_id ? get_post($project_id) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    $round = isset($_POST['round']) ? max(0, (int) $_POST['round']) : 0;
    wp_send_json_success(self::deal($project_id, $round));
  }

  /* ───────────────────────── Teksten ───────────────────────── */

  /** Copy-regels voor de AI (references/copy.md), kort. */
  public static function copy_rules(): string {
    return "COPY RULES (house style):\n"
      . "- Headlines are titles, not sentences: two lines at most, about 32 characters for a hero headline, 2 to 5 words for a section heading. Sentence case, no full stop.\n"
      . "- Pick the plainest true shape: the name, what it is, a claim a competitor could not sign, an instruction, or the place. Never: 'Welcome to ...', 'Discover ...', 'X, reimagined', 'More than a ...', 'It's not just ..., it's ...', tricolons like 'Fast. Simple. Reliable.', a question the reader did not ask, or 'Name: tagline'.\n"
      . "- The text under a headline is one concrete sentence (max about 22 words) saying what it is and for whom. It never repeats the headline in other words.\n"
      . "- Prefer the specific noun and the real number from the brief ('open on Saturday until 17:00', 'all brands') over adjectives ('fast', 'quality', 'passionate'). If another company could print the sentence unchanged, make it specific.\n"
      . "- Buttons name the action or the thing in 1 to 3 words ('Plan een afspraak', 'Bekijk de occasions'), never 'Lees meer', 'Meer info', 'Ontdek meer', 'Klik hier', 'Learn more', 'Get started'. The main action keeps the same name everywhere on the page.\n"
      . "- Address the reader the same way in every section. Dutch: formal 'u' and 'uw' everywhere, unless the project brief itself addresses the reader with 'je', 'jij' or 'jouw'. French: 'vous'.\n"
      . "- Never invent proof: no customer names, quotes, reviews, ratings, user counts, years in business, awards or press. Use a neutral placeholder in square brackets instead.\n"
      . "- No stock marketing words: unlock, elevate, seamless, empower, cutting-edge, world-class, all-in-one, effortless, streamline, robust, holistic, 'take ... to the next level', 'in today's world'. In Dutch also: ontzorgen, naadloos, op maat van jouw/uw noden, uw partner in, kwaliteit staat centraal, met passie, één aanspreekpunt, oplossingen op maat, de volgende stap zetten, in een handomdraai. In French also: sur mesure pour vos besoins, votre partenaire, en toute sérénité.\n"
      . "- No labels pretending to be editorial ('Onze filosofie', 'Waarom wij?'): a section heading names what is in it. No 'Stap 1', '01', '02'. No em dashes; use a full stop. No emoji. No three parallel items just because there are three slots; use what the content has.\n";
  }

  /** Stockzinnen (lint.mjs + Nederlands en Frans): zulke teksten worden herschreven. */
  public static function stock_phrase(string $text): bool {
    $t = mb_strtolower(wp_strip_all_tags($text));
    $re = '/\b(unlock|unleash|supercharge|seamless(ly)?|elevate|empower(s|ing)?|revolutioni[sz]e|game[- ]chang(er|ing)|cutting[- ]edge|next[- ]gen(eration)?|world[- ]class|best[- ]in[- ]class|all[- ]in[- ]one|effortless(ly)?|blazing(ly)?[- ]fast|lightning[- ]fast|reimagin(e|ed|ing)|delve|tapestry|leverage|synergy|streamline|robust|holistic|turbocharge|transform the way|take your .{1,30} to the next level|say goodbye to|in today\'?s|the future of|built for the modern|more than (just )?a'
      . '|ontzorg\w*|naadlo\w*|op maat van (jouw|uw) (noden|behoeften)|(jouw|uw) partner (in|voor)|kwaliteit staat centraal|met (veel )?passie|één aanspreekpunt|oplossingen op maat|de volgende stap|in een handomdraai|zorgeloos|welkom bij|ontdek (onze|ons|meer|het)|meer dan (alleen|zomaar|een)|lees meer|meer info(rmatie)?|klik hier'
      . '|sur mesure pour vos besoins|votre partenaire|en toute sérénité|bienvenue (chez|sur)|découvrez (nos|notre)|en savoir plus'
      // AI-tics uit Marketing Skills (copywriting: contrast reveals): "niet alleen X, maar ook Y".
      . '|not (just|only) [^.]{1,40}?,? but|niet (alleen|enkel|zomaar) [^.]{1,40}?,? maar|non seulement [^.]{1,40}? mais|pas (seulement|juste) [^.]{1,40}?,? mais)\b/u';
    return (bool) preg_match($re, $t) || strpos($t, '—') !== false;
  }

  /* ───────────────────────── Secties: huisregels ───────────────────────── */

  /**
   * Past de huisregels toe op de Bricks-elementen van één sectie:
   * geen dunne lijnen (divider), geen label boven een titel (eyebrow/tagline), geen icoon
   * bovenaan een kaart, geen decoratieve nummering (01, 02).
   */
  public static function house_nodes(array $nodes): array {
    $ix = [];
    foreach ($nodes as $i => $n) if (is_array($n) && isset($n['id'])) $ix[(string) $n['id']] = $i;
    $kids = fn($id) => isset($ix[$id]) ? array_values(array_filter(array_map('strval', (array) ($nodes[$ix[$id]]['children'] ?? [])), fn($c) => isset($ix[$c]))) : [];
    $node = fn($id) => $nodes[$ix[$id]] ?? null;
    $text = function ($n) {
      foreach (['text', 'title', 'content'] as $k) if (isset($n['settings'][$k]) && is_string($n['settings'][$k])) return trim(html_entity_decode(wp_strip_all_tags($n['settings'][$k]), ENT_QUOTES, 'UTF-8'));
      return null;
    };
    $ancestorLabel = function ($id, $re) use ($node) {
      $n = $node($id);
      for ($d = 0; $n && $d < 6; $d++) {
        $p = $node((string) ($n['parent'] ?? ''));
        if (!$p) return false;
        if (preg_match($re, (string) ($p['label'] ?? '')) || preg_match($re, implode(' ', (array) ($p['settings']['_cssClasses'] ?? [])))) return true;
        $n = $p;
      }
      return false;
    };
    $remove = [];
    foreach ($nodes as $n) {
      if (!is_array($n) || !isset($n['id'])) continue;
      $id = (string) $n['id'];
      $name = (string) ($n['name'] ?? '');
      $label = (string) ($n['label'] ?? '');
      $parent = (string) ($n['parent'] ?? '');
      // Dunne lijnen
      if ($name === 'divider') { $remove[$id] = 1; continue; }
      // Label boven een titel: een korte tekst vlak vóór een heading in dezelfde groep.
      if (in_array($name, ['text-basic', 'text'], true)) {
        $siblings = $kids($parent);
        $pos = array_search($id, $siblings, true);
        $next = ($pos !== false && isset($siblings[$pos + 1])) ? $node($siblings[$pos + 1]) : null;
        $t = $text($n);
        $labelled = (bool) preg_match('/tagline|eyebrow|kicker|overline|pre-?title|subtitle-top|badge/i', $label);
        if ($next && ($next['name'] ?? '') === 'heading' && $t !== null && mb_strlen($t) <= 60 && ($labelled || $pos === 0)) {
          $remove[$id] = 1;
          continue;
        }
        // Decoratieve nummering: 01, (02), 03.
        if ($t !== null && preg_match('/^[(\[]?0[1-9][)\]]?\.?$/', $t)) { $remove[$id] = 1; continue; }
      }
      // Icoon bovenaan een kaart (icon wrapper in een kaart of item).
      if ($name === 'block' && preg_match('/^icon( wrapper)?$/i', trim($label)) && $ancestorLabel($id, '/card|item|feature|service/i')) {
        $remove[$id] = 1;
        continue;
      }
    }
    if (!$remove) return $nodes;
    // Met kinderen en al verwijderen, en uit de lijst van de ouder halen.
    $all = $remove;
    $stack = array_keys($remove);
    while ($stack) {
      $id = array_pop($stack);
      foreach ($kids($id) as $c) if (!isset($all[$c])) { $all[$c] = 1; $stack[] = $c; }
    }
    $out = [];
    foreach ($nodes as $n) {
      if (!is_array($n) || isset($all[(string) ($n['id'] ?? '')])) continue;
      if (!empty($n['children'])) $n['children'] = array_values(array_filter(array_map('strval', (array) $n['children']), fn($c) => !isset($all[$c])));
      $out[] = $n;
    }
    return $out;
  }

  /** Hoeken van knoppen en velden, voor de hele website gelijk (stijlstap: guide.radius). */
  const RADIUS = ['sharp' => '0px', 'soft' => '6px', 'round' => '999px'];

  public static function radius_of($guide): string {
    $r = is_array($guide) ? (string) ($guide['radius'] ?? '') : '';
    return self::RADIUS[$r] ?? self::RADIUS['soft'];
  }

  /**
   * Eén ontwerp voor de hele website, al in de wireframes: sjablonen uit verschillende bronnen
   * krijgen dezelfde knoppen (hoeken, grootte, gewicht), velden en titelgroottes.
   */
  public static function system_css($guide = null): string {
    $r = self::radius_of($guide);
    // Hoge specificiteit maar geen !important: een keuze per element (stap Ontwerp, #brxe-...) blijft winnen.
    $btn = 'html body :is(.brxe-button.bricks-button.bricks-button,.brxe-form.brxe-form.brxe-form button[type=submit].bricks-button.bricks-button)';
    $not = ':not(:is(.archer-header,.archer-footer) *)';
    return ':root{--archer-radius:' . $r . ';}'
      // px in plaats van rem: de Brixies-sjablonen zetten de basis op 62,5% (1rem = 10px).
      . $btn . '{border-radius:var(--archer-radius);font-size:16px;line-height:1.2;padding:.85em 1.5em;font-weight:600;text-transform:none;letter-spacing:normal;}'
      // Bricks-optie "rond" (circle) zet 100px met !important in de laag "bricks": daar overschrijven.
      . '@layer bricks{html body .bricks-button.circle.circle{border-radius:var(--archer-radius) !important;}}'
      . 'html body .brxe-form.brxe-form :is(input:not([type=checkbox]):not([type=radio]),select,textarea){border-radius:min(var(--archer-radius),10px);}'
      . 'html body :is(h1.brxe-heading.brxe-heading,h1)' . $not . '{font-size:clamp(40px,26px + 2.4vw,56px);line-height:1.08;}'
      . 'html body :is(h2.brxe-heading.brxe-heading,h2)' . $not . '{font-size:clamp(32px,24px + 1.3vw,42px);line-height:1.15;}';
  }

  /** CSS-huisregels voor voorbeeld en gepubliceerde site: geen schaduwen op wat stil staat. */
  public static function house_css($guide = null): string {
    // Archer-stijl gekozen: haar ontwerpsysteem komt eerst (de @import van de letters moet vooraan staan)
    // en vervangt de algemene knop- en titelregels van system_css.
    $style = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::of($guide) : null;
    return ($style ? AISB_Archer_Styles::css($style, $guide) : '') . self::house_rules($guide, (bool) $style)
      . (class_exists('AISB_Archer_Motion') ? AISB_Archer_Motion::css() : '');
  }

  private static function house_rules($guide, bool $style): string {
    $float = '.brx-dropdown-content,.brx-submenu-toggle,.brxe-offcanvas .brx-offcanvas-inner,.bricks-mobile-menu-wrapper,.brx-popup-content,[role="dialog"],[role="menu"]';
    $accent = 'var(--bricks-color-primary, currentColor)';
    return 'body :not(' . $float . '){box-shadow:none !important;text-shadow:none !important;}'
      . 'h1,h2,h3,.brxe-heading{text-wrap:balance;}p,.brxe-text-basic{text-wrap:pretty;}'
      // impeccable: browseroppervlakken (selectie, cursor, focus, links) in de stijl van de site, cijfers in kolommen.
      . '::selection{background:' . $accent . ';color:var(--bricks-color-light, #fff);}'
      . 'body{caret-color:' . $accent . ';}'
      . ':focus-visible{outline:2px solid ' . $accent . ' !important;outline-offset:3px;}'
      . 'a{text-underline-offset:0.18em;text-decoration-thickness:from-font;}'
      . '.brxe-counter,table,.brxe-pricing-tables{font-variant-numeric:tabular-nums;}'
      // Tekst over een foto die de hele sectie vult: altijd wit (de overlay van het ontwerp zorgt voor contrast).
      . '.archer-cover.brxe-section :is(h1,h2,h3,h4,h5,h6,.brxe-heading,p,li,.brxe-text,.brxe-text-basic,.brxe-rich-text){color:#fff !important;}'
      . '.archer-cover.brxe-section :is(.brxe-container,.brxe-block){background-color:transparent !important;}'
      . '.archer-credit a{color:inherit;text-decoration:underline;text-underline-offset:.18em;}'
      . ($style ? '' : self::system_css($guide));
  }

  public function preview_house_css(): void {
    if (empty($_GET['aisb_bricks_preview'])) return;
    $guide = null;
    $pid = (int) ($_GET['aisb_project'] ?? 0);
    if ($pid && is_user_logged_in()) {
      $p = get_post($pid);
      if ($p && $p->post_type === 'aisb_project' && ((int) $p->post_author === get_current_user_id() || current_user_can('manage_options'))) {
        $guide = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
      }
    }
    echo '<style id="archer-house">' . self::house_css($guide) . '</style>';
  }
}
