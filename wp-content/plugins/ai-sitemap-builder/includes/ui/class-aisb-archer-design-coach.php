<?php
if (!defined('ABSPATH')) exit;

/**
 * Ontwerpcoach: de tegenhanger van de copycoach (AISB_Archer_Copy_Coach), maar dan voor hoe een
 * pagina eruitziet in plaats van wat er staat. Hij kijkt de opbouw van de secties na tegen de
 * regels van de gekozen Archer-stijl (styles/design-notes.json: do's en don'ts) en tegen wat uit
 * de secties zelf volgt (dubbele knoplabels, twee keer dezelfde sectie na elkaar, teksten die
 * niet in hun kader passen).
 *
 * - facts():        wat er feitelijk op de pagina staat, per sectie. Geen AI.
 * - findings():     de harde bevindingen die daaruit volgen. Geen AI.
 * - review_prompt(): één vraag per pagina met een score op 100 en korte notities.
 * - remember():     bewaart score en bevindingen als _archer_design_score_<slug>.
 * - summary():      alles samen voor de controle voor publiceren (AISB_Archer_Checks).
 *
 * De coach herschrijft niets. Hij meet en benoemt; de secties en de stijl blijven zoals ze zijn.
 */
class AISB_Archer_Design_Coach {

  const SOURCE = 'Archer style rules (DESIGN.md) and the Brixies section system';

  /** Rubriek op 100. */
  const RUBRIC = [
    'hierarchy' => 25,
    'rhythm'    => 20,
    'style_fit' => 20,
    'imagery'   => 15,
    'action'    => 10,
    'craft'     => 10,
  ];

  /** Onder deze score zetten we de pagina in de controle voor publiceren. */
  const WEAK_BELOW = 65;

  /** Aan/uit voor de hele coach, met de filter archer_design_coach. */
  public static function on(): bool {
    return (bool) apply_filters('archer_design_coach', true);
  }

  /* ───────────────────────── Wat er op de pagina staat ───────────────────────── */

  /**
   * De opbouw van de pagina, sectie per sectie, zoals ze na de copystap is.
   *
   * @param array $out   key => ['modules' => [mid => ['settings' => [...]]]]
   * @param array $plan  key => ['purpose' => ..., 'type' => ..., 'modules' => [mid => ['type' => ...]]]
   * @param array $limits key => [mid => max_chars]
   * @return array<int,array>
   */
  public static function facts(array $out, array $plan, array $limits = []): array {
    $facts = [];
    $i = 0;
    foreach ($out as $key => $sec) {
      $key = (string) $key;
      $p = (array) ($plan[$key] ?? []);
      $kinds = [];
      $headings = [];
      $buttons = [];
      $over = 0;
      foreach ((array) ($sec['modules'] ?? []) as $mid => $mod) {
        $type = (string) ($p['modules'][$mid]['type'] ?? ($mod['type'] ?? 'text'));
        $kinds[$type] = ($kinds[$type] ?? 0) + 1;
        $first = true;
        foreach ((array) ($mod['settings'] ?? []) as $val) {
          if (!is_string($val)) continue;
          $plain = trim(html_entity_decode(wp_strip_all_tags($val), ENT_QUOTES, 'UTF-8'));
          if ($plain === '') continue;
          if ($first) {
            $max = (int) ($limits[$key][$mid] ?? 0);
            if ($max > 0 && mb_strlen($plain) > $max) $over++;
            $first = false;
          }
          if ($type === 'heading' && mb_strlen($plain) >= 3) $headings[] = mb_substr($plain, 0, 90);
          if (in_array($type, ['button', 'text-link'], true)) $buttons[] = mb_substr($plain, 0, 40);
        }
      }
      // Beeldkaders tellen we uit het sectieplan: in $out staan alleen de modules met tekst.
      $images = 0;
      foreach ((array) ($p['modules'] ?? []) as $pm) {
        if (in_array((string) ($pm['type'] ?? ''), ['image', 'img', 'slider', 'gallery', 'video'], true)) $images++;
      }
      $facts[] = [
        'n'        => ++$i,
        'key'      => $key,
        'purpose'  => mb_substr((string) ($p['purpose'] ?? ''), 0, 120),
        'type'     => (string) ($p['type'] ?? ($p['section_type'] ?? '')),
        'modules'  => $kinds,
        'headings' => array_slice($headings, 0, 6),
        'buttons'  => array_slice($buttons, 0, 6),
        'images'   => $images,
        'over'     => $over,
      ];
    }
    // Header en footer zijn vaste chrome: ze tellen mee als context, maar de coach beoordeelt ze niet.
    $n = count($facts);
    if ($n >= 3) { $facts[0]['chrome'] = true; $facts[$n - 1]['chrome'] = true; }
    return $facts;
  }

  /**
   * De bevindingen die rechtstreeks uit de opbouw volgen. Die hebben geen AI nodig en zijn
   * daarom altijd waar: ze gaan mee in de vraag én in wat we bewaren.
   *
   * @return array<int,array{id:string,area:string,note:string}>
   */
  public static function findings(array $facts): array {
    $out = [];
    $n = count($facts);

    // Twee keer hetzelfde soort sectie na elkaar: de pagina gaat visueel stilstaan.
    for ($i = 1; $i < $n; $i++) {
      $a = (string) ($facts[$i - 1]['type'] ?? '');
      $b = (string) ($facts[$i]['type'] ?? '');
      if ($a !== '' && $a === $b) {
        $out[] = ['id' => 'repeat-type', 'area' => 'rhythm', 'note' => 'Secties ' . $facts[$i - 1]['n'] . ' en ' . $facts[$i]['n'] . ' zijn van hetzelfde type (' . $a . '): twee keer dezelfde vorm na elkaar.'];
      }
    }

    // Hetzelfde knoplabel meer dan twee keer: de pagina herhaalt zijn oproep.
    $labels = [];
    foreach ($facts as $f) foreach ((array) $f['buttons'] as $b) {
      $k = mb_strtolower(trim($b));
      if ($k !== '') $labels[$k] = ($labels[$k] ?? 0) + 1;
    }
    foreach ($labels as $label => $count) {
      if ($count > 2) $out[] = ['id' => 'repeat-cta', 'area' => 'action', 'note' => '"' . $label . '" staat ' . $count . ' keer op de pagina.'];
    }

    // Veel verschillende labels: de bezoeker weet niet meer wat de hoofdactie is. Een lange pagina
    // mag er meer hebben dan een korte, vandaar de drempel per aantal secties.
    if (count($labels) > max(3, (int) ceil($n / 2))) {
      $out[] = ['id' => 'mixed-cta', 'area' => 'action', 'note' => count($labels) . ' verschillende knoplabels op ' . $n . ' secties: de hoofdactie valt niet meer op.'];
    }

    // Twee secties met (bijna) dezelfde kop.
    $seen = [];
    foreach ($facts as $f) foreach ((array) $f['headings'] as $h) {
      $k = self::norm($h);
      if ($k === '' || mb_strlen($k) < 8) continue;
      if (isset($seen[$k]) && $seen[$k] !== $f['n']) {
        $out[] = ['id' => 'repeat-heading', 'area' => 'craft', 'note' => 'Secties ' . $seen[$k] . ' en ' . $f['n'] . ' hebben dezelfde kop: "' . $h . '".'];
      } elseif (!isset($seen[$k])) {
        $seen[$k] = $f['n'];
      }
    }

    // Teksten die niet in hun kader passen: die breken de opmaak of worden afgekapt.
    $over = array_sum(array_map(fn($f) => (int) $f['over'], $facts));
    if ($over > 0) $out[] = ['id' => 'overflow', 'area' => 'craft', 'note' => $over . ' tekst(en) zijn langer dan het kader waarin ze staan.'];

    // Beeld laten we aan de controle voor publiceren (lege fotokaders) en aan het oordeel van de AI:
    // in deze stap staat nog niet vast welke kaders gevuld raken.

    return array_slice($out, 0, 8);
  }

  /* ───────────────────────── De vraag aan de AI ───────────────────────── */

  public static function review_prompt(array $page, array $facts, array $findings, $guide = null): string {
    $style = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::of($guide) : null;
    $notes = $style && class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::notes((string) $style['id']) : [];
    $rub = [];
    foreach (self::RUBRIC as $k => $max) $rub[] = $k . ' ' . $max;

    $style_block = '';
    if ($style) {
      $style_block = "STYLE \"" . (string) ($style['name'] ?? $style['id']) . "\": " . (string) ($notes['theme'] ?? '') . "\n";
      foreach (['do' => 'DO', "don't" => "DON'T", 'dont' => "DON'T"] as $k => $label) {
        $list = array_slice((array) ($notes[$k] ?? []), 0, 6);
        if ($list) $style_block .= $label . ": " . implode('; ', array_map('strval', $list)) . "\n";
      }
    }

    $chrome = [];
    foreach ($facts as $f) if (!empty($f['chrome'])) $chrome[] = (int) $f['n'];

    return "You review the DESIGN of one page of a small-business website: how the page is put together, not what the texts say (a copy coach already did that). " . self::SOURCE . ".\n"
      . ($chrome ? "Section " . implode(' and ', $chrome) . " are the fixed header and footer, the same on every page: read them for context and never write a note about them.\n" : '')
      . "The page is built from fixed Brixies sections in a chosen Archer style. You cannot change the sections or the style, so judge the composition and name what holds it back.\n\n"
      . "RUBRIC (100 points: " . implode(', ', $rub) . "):\n"
      . "- hierarchy: one clear entry point at the top, then a readable order. Each section has one thing that leads; headings and the number of elements per section make the importance clear.\n"
      . "- rhythm: the page alternates in form and weight. No two sections in a row that look the same, no long run of the same kind of block, and the page does not stop abruptly.\n"
      . "- style_fit: the page follows the style's DO and DON'T above. Count a section that works against the style as a real problem.\n"
      . "- imagery: image slots sit where they help (the top, proof, the product or the work), and a long page is not a wall of text.\n"
      . "- action: the primary action is easy to find and is asked in one consistent way, not repeated in every section.\n"
      . "- craft: no two sections with the same heading, no texts that overflow their slot, no section that is only decoration.\n\n"
      . "RULES:\n"
      . "- Judge only what the SECTIONS below show. Never guess at colours, spacing, fonts or photos: the style and the photo step set those. A section with \"chrome\": true is the fixed header or footer: use it as context only and never name it as a problem.\n"
      . "- The HARD FINDINGS are already verified. Each one costs points in its area: the first costs at least 4 points, every next one in the same area at least 2. An area with a hard finding never keeps full marks.\n"
      . "- Score what is there, not what could be added. Full marks for an area mean you found nothing to say about it, so a page with notes cannot score 100.\n"
      . "- Write every note in Dutch, short, and about this page. Name the section number. No praise, no general design advice.\n\n"
      . "Return JSON: {\"scores\": {" . implode(', ', array_map(fn($k) => '"' . $k . '": int', array_keys(self::RUBRIC))) . "}, \"total\": int, \"weak\": [\"max 4 short notes in Dutch on what holds the page back\"]}\n\n"
      . $style_block . "\n"
      . "PAGE: " . wp_json_encode($page, JSON_UNESCAPED_UNICODE) . "\n\n"
      . ($findings ? "HARD FINDINGS (already verified):\n" . wp_json_encode(array_column($findings, 'note'), JSON_UNESCAPED_UNICODE) . "\n\n" : '')
      . "SECTIONS (in order: section number, purpose, section type, how many modules of each kind, the headings, the button labels, how many texts overflow):\n"
      . wp_json_encode($facts, JSON_UNESCAPED_UNICODE);
  }

  /* ───────────────────────── Bewaren en samenvatten ───────────────────────── */

  /** Bewaart de score per pagina. Zonder antwoord van de AI bewaren we alleen de harde bevindingen. */
  public static function remember(int $pid, string $slug, $res, array $findings, int $sections = 0): void {
    if (!$pid || $slug === '') return;
    $scores = [];
    $total = null;
    if (is_array($res)) {
      foreach (self::RUBRIC as $k => $max) $scores[$k] = max(0, min($max, (int) ($res['scores'][$k] ?? 0)));
      $total = array_sum($scores);
    }
    $weak = is_array($res)
      ? array_slice(array_values(array_filter(array_map(fn($w) => is_string($w) ? sanitize_text_field($w) : '', (array) ($res['weak'] ?? [])))), 0, 4)
      : [];
    update_post_meta($pid, '_archer_design_score_' . $slug, [
      'total'    => $total,
      'scores'   => $scores,
      'weak'     => $weak,
      'findings' => array_map(fn($f) => ['id' => (string) $f['id'], 'area' => (string) $f['area'], 'note' => sanitize_text_field((string) $f['note'])], array_slice($findings, 0, 8)),
      'sections' => $sections,
      'at'       => time(),
    ]);
  }

  /**
   * Alle ontwerpscores van een project samen.
   * @return array{pages:array<int,array>,average:?int,weakest:?array,findings:int}
   */
  public static function summary(int $pid): array {
    $pages = [];
    $sum = 0;
    $scored = 0;
    $findings = 0;
    foreach ((array) get_post_meta($pid) as $k => $v) {
      if (strpos((string) $k, '_archer_design_score_') !== 0) continue;
      $d = maybe_unserialize($v[0] ?? '');
      if (!is_array($d)) continue;
      $slug = substr((string) $k, strlen('_archer_design_score_'));
      $total = isset($d['total']) && $d['total'] !== null ? (int) $d['total'] : null;
      if ($total !== null) { $sum += $total; $scored++; }
      $findings += count((array) ($d['findings'] ?? []));
      $pages[] = [
        'slug'     => $slug,
        'total'    => $total,
        'weak'     => (array) ($d['weak'] ?? []),
        'findings' => (array) ($d['findings'] ?? []),
      ];
    }
    usort($pages, function ($a, $b) {
      return ($a['total'] ?? 101) <=> ($b['total'] ?? 101);
    });
    return [
      'pages'    => $pages,
      'average'  => $scored ? (int) round($sum / $scored) : null,
      'weakest'  => $pages && ($pages[0]['total'] ?? null) !== null ? $pages[0] : null,
      'findings' => $findings,
    ];
  }

  private static function norm(string $s): string {
    $s = mb_strtolower(trim(wp_strip_all_tags($s)));
    return trim(preg_replace('/[^a-z0-9\p{L}]+/u', ' ', $s));
  }
}
