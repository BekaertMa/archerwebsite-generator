<?php
if (!defined('ABSPATH')) exit;

/**
 * De opbouw van een verkooppagina als regel, niet als wens in een prompt.
 *
 * De vorm die werkt voor een kmo-site is bekend: een hero die zegt wat je krijgt en voor wie
 * (met de plaats erbij voor een lokale zaak), wat het de klant oplevert, bewijs, een volgende stap
 * na elk blok, en een FAQ die de twijfels wegneemt vlak voor de laatste oproep. Staat dat alleen in
 * de prompt, dan houdt het model zich er meestal aan en af en toe niet. Daarom staat het hier:
 * rules() legt het uit aan het model, apply() legt het daarna vast op het sectieplan dat terugkomt.
 *
 * Eén ding is geen vaste regel maar een keuze per sector: begin je bij het probleem of bij het
 * resultaat. Een kinesist of een loodgieter mag beginnen bij wat er scheelt; een bakker of een
 * kapper niet, want daar komt niemand met een probleem. Zie opening().
 */
class AISB_Archer_Composition {

  /** Aan/uit, met de filter archer_composition. */
  public static function on(): bool {
    return (bool) apply_filters('archer_composition', true);
  }

  /**
   * Sectoren waar de bezoeker met een probleem of een zorg aankomt: daar mag de pagina daarbij
   * beginnen. In alle andere sectoren komt de bezoeker met een wens, en werkt een probleemsectie
   * averechts (niemand zoekt een bakker omdat er iets mis is).
   */
  const PAIN = ['home-services', 'construction', 'medical', 'dental', 'vet', 'legal', 'accounting', 'insurance', 'automotive', 'senior-care', 'fitness', 'agency', 'childcare', 'education'];

  /** Soorten secties die als opening, bewijs of afsluiter tellen (sjabloonsoorten van AISB_Archer_Structure). */
  const OPENERS = ['hero', 'intro', 'single_post_hero', 'single_product', 'single_property', 'single_portfolio', 'single_event', 'single_team'];
  const PROOF   = ['testimonials', 'social_proof'];
  const CLOSERS = ['cta', 'contact', 'pricing'];

  /** Hoeveel secties een pagina tussen header en footer mag hebben (zoals de prompt ze vraagt). */
  const ROOM = ['Home' => 8, 'Service' => 5, 'Landing' => 7, 'About' => 5, 'Listing' => 6, 'Detail' => 5, 'Blog' => 5, 'Contact' => 3, 'Legal' => 1, 'Other' => 5];

  /** Pagina's waar de volledige verkooplijn hoort. Op de rest wordt niets bijgezet. */
  const SELLS = ['Home', 'Service', 'Landing'];

  /** Begint deze sector bij het probleem of bij het resultaat? */
  public static function opening(string $sector): string {
    return in_array($sector, self::PAIN, true) ? 'problem' : 'result';
  }

  /**
   * De compositieregels voor de prompt van het sectieplan.
   * Engels, zoals de rest van die prompt; de site zelf staat in haar eigen taal.
   */
  public static function rules(string $sector = '', bool $local = true): string {
    $problem = self::opening($sector) === 'problem';
    return "PAGE COMPOSITION (fixed shape for home, service and landing pages):\n"
      . "1. Hero: what the visitor gets and who it is for, in the heading or the line under it"
      . ($local ? ", with the town or region the brief names. A local business says where it works above the fold, always." : ".")
      . " The button is the page's primary action.\n"
      . ($problem
        ? "2. Then the situation the visitor is in: what is wrong, what it costs them, what they have already tried. People in this line of work are looked up because something hurts, breaks, leaks or worries, so naming it earns the rest of the page. Two or three sentences, in their words from the brief, never invented.\n"
        : "2. Then what they get, not what is wrong. Nobody looks for this business because something is broken: they want something. Open on the result, the choice or the experience. Do NOT plan a problem or pain section here; it would read as made up.\n")
      . "3. Benefits: what the work means for the customer (time, money, worry, result), in the customer's words, one idea per item. Features only as the reason a benefit is true.\n"
      . "4. Proof, after the benefits and before the decision, never at the top: reviews, references, certifications, years, guarantees. Only what the brief gives; without any, plan no proof section rather than an empty one.\n"
      . "5. A next step after every block: each section that has a button points at the primary action, with the same label as the hero. The visitor who is convinced halfway down must not have to scroll back up.\n"
      . "6. FAQ right before the last call to action, answering the doubts the brief names ('Twijfels van klanten', the FAQ topics). It is the last thing between the visitor and the button, so it handles the objection, not the trivia.\n"
      . "7. The close: the result in one line and the one action. No new information here.\n"
      . "Keep this order. A page may leave a step out when the brief has nothing for it; it never swaps the order of hero, benefits, proof, FAQ, close.\n";
  }

  /**
   * De regels vastleggen op het sectieplan dat de AI teruggaf. Verandert alleen wat aantoonbaar
   * mis is: de opening vooraan, bewijs niet bovenaan, een FAQ vlak voor het einde als er twijfels
   * zijn, en een afsluiter. Zet nooit meer secties bij dan de pagina ruimte heeft.
   *
   * @param array $sections Het volledige plan, header en footer inbegrepen.
   * @param array $ctx      [page_type, lang, objections (bool), sector]
   * @return array [sections, notes]
   */
  public static function apply(array $sections, array $ctx): array {
    if (!self::on() || !$sections) return [$sections, []];
    $type = (string) ($ctx['page_type'] ?? 'Other');
    $lang = (string) ($ctx['lang'] ?? 'nl');
    $notes = [];

    $head = []; $mid = []; $foot = [];
    foreach ($sections as $s) {
      if (!is_array($s)) continue;
      $st = (string) ($s['section_type'] ?? '');
      if ($st === 'Headers' || strpos($st, 'Megamenu') === 0) $head[] = $s;
      elseif ($st === 'Footers') $foot[] = $s;
      else $mid[] = $s;
    }
    if (count($mid) < 2) return [$sections, []];

    $tag = function (array $s): string {
      return class_exists('AISB_Archer_Structure') ? AISB_Archer_Structure::tag_for((string) ($s['section_type'] ?? '')) : 'content';
    };
    $tags = array_map($tag, $mid);

    // 1. De opening hoort vooraan. Staat ze verderop, dan schuift ze naar voren.
    $open = -1;
    foreach ($tags as $i => $t) if (in_array($t, self::OPENERS, true)) { $open = $i; break; }
    if ($open > 0) {
      $s = $mid[$open];
      array_splice($mid, $open, 1);
      array_unshift($mid, $s);
      $notes[] = 'opening vooraan gezet';
      $tags = array_map($tag, $mid);
    }

    // 2. Alleen de pagina's die verkopen, en alleen als er plaats is.
    if (in_array($type, self::SELLS, true)) {
      $room = (int) (self::ROOM[$type] ?? 5);

      // 3. Een FAQ vlak voor het einde, als de klant twijfels opgaf en de pagina er geen heeft.
      if (!empty($ctx['objections']) && !in_array('faq', $tags, true) && count($mid) < $room) {
        $at = count($mid);
        for ($j = count($mid) - 1; $j >= 0; $j--) {
          if (in_array($tag($mid[$j]), self::CLOSERS, true)) $at = $j; else break;
        }
        array_splice($mid, $at, 0, [[
          'section_name' => self::t('faq_name', $lang),
          'section_type' => class_exists('AISB_Archer_Structure') ? AISB_Archer_Structure::sitemap_type_for('faq') : 'FAQ Sections',
          'purpose' => self::t('faq_purpose', $lang),
          'key_content' => array_slice((array) ($ctx['objections'] ?? []), 0, 4),
        ]]);
        $notes[] = 'FAQ voor de laatste oproep bijgezet';
        $tags = array_map($tag, $mid);
      }

      // 4. De pagina eindigt met een volgende stap.
      if ($mid && !in_array($tag($mid[count($mid) - 1]), self::CLOSERS, true) && count($mid) < $room) {
        $mid[] = [
          'section_name' => self::t('cta_name', $lang),
          'section_type' => class_exists('AISB_Archer_Structure') ? AISB_Archer_Structure::sitemap_type_for('cta') : 'CTA Sections',
          'purpose' => self::t('cta_purpose', $lang),
          'key_content' => [],
        ];
        $notes[] = 'afsluitende oproep bijgezet';
        $tags = array_map($tag, $mid);
      }
    }

    // 3. Bewijs hoort bij de beslissing, niet bovenaan. Dit gaat als laatste, want het bewijs schuift
    // naar de afsluiter en die kan er net bijgezet zijn.
    $n = count($mid);
    if ($n >= 4) {
      foreach ($tags as $i => $t) {
        if (!in_array($t, self::PROOF, true) || $i === 0 || $i > (int) floor($n / 3)) continue;
        $s = $mid[$i];
        array_splice($mid, $i, 1);
        // Vlak voor de afsluiters achteraan, of achteraan als er geen zijn.
        $at = count($mid);
        for ($j = count($mid) - 1; $j >= 0; $j--) {
          if (in_array($tag($mid[$j]), self::CLOSERS, true) || $tag($mid[$j]) === 'faq') $at = $j; else break;
        }
        if ($at === $i) break; // stond al goed
        array_splice($mid, $at, 0, [$s]);
        $notes[] = 'bewijs naar de beslissing verplaatst';
        break;
      }
    }

    return [array_merge(array_slice($head, 0, 1), $mid, array_slice($foot, 0, 1)), $notes];
  }

  /** De namen en doelen van de secties die deze stap zelf bijzet, in de taal van de site. */
  private static function t(string $key, string $lang): string {
    $t = [
      'faq_name' => ['nl' => 'Veelgestelde vragen', 'fr' => 'Questions fréquentes', 'en' => 'Frequently asked questions', 'de' => 'Häufige Fragen'],
      'faq_purpose' => [
        'nl' => 'Beantwoordt de twijfels die bezoekers tegenhouden, vlak voor de laatste oproep. Elk antwoord begint met het antwoord zelf.',
        'fr' => 'Répond aux hésitations qui retiennent le visiteur, juste avant le dernier appel à l\'action. Chaque réponse commence par la réponse.',
        'en' => 'Answers the doubts that hold visitors back, right before the last call to action. Every answer starts with the answer itself.',
        'de' => 'Beantwortet die Zweifel, die Besucher zurückhalten, direkt vor dem letzten Handlungsaufruf. Jede Antwort beginnt mit der Antwort.',
      ],
      'cta_name' => ['nl' => 'Zet de stap', 'fr' => 'Passez à l\'action', 'en' => 'Take the next step', 'de' => 'Machen Sie den Schritt'],
      'cta_purpose' => [
        'nl' => 'Het resultaat in één zin en de hoofdactie, met dezelfde knoptekst als bovenaan. Geen nieuwe informatie.',
        'fr' => 'Le résultat en une phrase et l\'action principale, avec le même libellé de bouton qu\'en haut. Pas de nouvelle information.',
        'en' => 'The result in one line and the primary action, with the same button label as the hero. No new information.',
        'de' => 'Das Ergebnis in einem Satz und die Hauptaktion, mit demselben Button-Text wie oben. Keine neuen Informationen.',
      ],
    ];
    return $t[$key][$lang] ?? $t[$key]['nl'];
  }
}
