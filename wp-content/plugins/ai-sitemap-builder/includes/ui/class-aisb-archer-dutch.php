<?php
if (!defined('ABSPATH')) exit;

/**
 * Waaraan je in Vlaanderen ziet dat een tekst door een AI geschreven is.
 *
 * De huisregels (AISB_Archer_Taste::stock_phrase) vangen de Engelse marketingtaal en het
 * contrasttrucje ("niet alleen X, maar ook Y"). Wat ze niet vangen, is wat Vlaamse AI-tekst
 * verraadt, en dat is iets anders dan bij Engelse:
 *
 * - nl:            woorden uit Nederland. Het model is op Nederlands van boven de Moerdijk getraind,
 *                  dus het schrijft hovenier, prijsopgave en pinnen waar een Vlaming tuinaanleg,
 *                  offerte en Bancontact zegt.
 * - stijf:         ambtelijke u-taal (gelieve, middels, dient u, zo spoedig mogelijk). Een model dat
 *                  "u" moet schrijven, valt terug op de taal van een brief van de gemeente.
 * - enthousiasme:  warmte die nergens op steunt. Zonder feiten vult het model de ruimte met
 *                  "wij staan voor u klaar" en "u bent bij ons aan het juiste adres".
 * - ai:            de vormtics die overblijven na de huisregels (dubbelpuntonthulling, een vraag die
 *                  het model zelf beantwoordt, "of je nu ... of ...").
 *
 * De lijst is bewust kort en zeker: elke regel hier wordt hard toegepast, dus één valse treffer kost
 * een goede zin. Wat oordeel vraagt, staat in checklist() en gaat naar de copycoach.
 */
class AISB_Archer_Dutch {

  /** Aan/uit, met de filter archer_dutch_tells. */
  public static function on(): bool {
    return (bool) apply_filters('archer_dutch_tells', true);
  }

  /** Geldt alleen voor Nederlandstalige sites. */
  public static function applies(string $language): bool {
    return $language === '' || stripos($language, 'dutch') !== false || stripos($language, 'nederlands') !== false || stripos($language, 'vlaams') !== false;
  }

  /**
   * De harde lijst: patroon => [groep, wat eraan scheelt en wat er wel staat].
   * De uitleg is Nederlands, want die komt in de interface en in de prompt van de nalezing.
   */
  const TELLS = [
    // ── Nederlands uit Nederland ──────────────────────────────────────────
    '/\bhartstikke\b/u'                              => ['nl', 'hartstikke is Nederlands uit Nederland'],
    '/\bmobielt?je\b|\bmobiele telefoon\b/u'         => ['nl', 'in Vlaanderen is dat een gsm'],
    '/\bzzp(\x27er|-er|er)?\b/u'                     => ['nl', 'in Vlaanderen heet dat een zelfstandige'],
    '/\bpin(nen|betaling|automaat)\b/u'              => ['nl', 'in Vlaanderen betaal je met Bancontact of met de kaart'],
    '/\bhovenier(s|sbedrijf)?\b/u'                   => ['nl', 'in Vlaanderen is dat tuinaanleg of een tuinaannemer'],
    '/\bprijsopgave(s)?\b/u'                         => ['nl', 'in Vlaanderen heet dat een offerte'],
    '/\bzorgverzekering\b|\bzorgverzekeraar\b/u'     => ['nl', 'in Vlaanderen is dat het ziekenfonds of de mutualiteit'],
    '/\bklusbedrijf\b/u'                             => ['nl', 'in Vlaanderen is dat een klusjesdienst'],
    '/\blekker (snel|makkelijk|gemakkelijk|simpel|handig|rustig)\b/u' => ['nl', '"lekker" als versterker is Nederlands uit Nederland'],
    '/\bontzettend\b/u'                              => ['nl', 'ontzettend klinkt Nederlands; schrap het of zeg hoeveel'],

    // ── Ambtelijke u-taal ─────────────────────────────────────────────────
    '/\bgelieve\b/u'                                 => ['stijf', 'gelieve komt uit een brief van de gemeente'],
    '/\b(u dient|dient u)\b/u'                       => ['stijf', '"u dient" is ambtelijk; schrijf wat de bezoeker doet'],
    '/\bmiddels\b|\bdoor middel van\b|\bd\.m\.v\./u' => ['stijf', 'middels is ambtelijk; schrijf met of via'],
    '/\bteneinde\b|\bzulks\b|\bimmer\b|\bgaarne\b/u' => ['stijf', 'kanselarijtaal; zeg het gewoon'],
    '/\bzo spoedig mogelijk\b/u'                     => ['stijf', 'zo spoedig mogelijk is ambtelijk; zeg wanneer, of schrijf zo snel mogelijk'],
    '/\bwij (hanteren|verzoeken|adviseren u)\b/u'    => ['stijf', '"wij hanteren" is ambtelijk; zeg wat het voor de klant betekent'],
    '/\b(met betrekking tot|aangaande|hieromtrent|desgewenst|alsook|te allen tijde)\b/u' => ['stijf', 'ambtelijke koppeltaal; zeg het in gewone woorden'],

    // ── Verzonnen enthousiasme ────────────────────────────────────────────
    '/\b(wij|we) (staan|zijn er) (altijd |steeds )?(voor (u|je|jou)|klaar)/u' => ['enthousiasme', '"wij staan voor u klaar" zegt niets; zeg wat je doet en wanneer'],
    '/\b(wij|we) helpen (u|je|jou) (graag|met plezier)/u' => ['enthousiasme', '"wij helpen u graag" zegt niets; zeg waarmee'],
    '/\baarzel niet\b/u'                             => ['enthousiasme', '"aarzel niet" is een invulzin'],
    '/\b(neem|neemt) gerust contact\b/u'             => ['enthousiasme', '"gerust" voegt niets toe'],
    '/\baan het juiste adres\b/u'                    => ['enthousiasme', '"aan het juiste adres" is een invulzin'],
    '/\b(daar (kunt|kan) (u|je) op rekenen|in goede handen)\b/u' => ['enthousiasme', 'belofte zonder dekking; zeg welk feit het waarmaakt'],
    '/\bmet (trots|hart en ziel|veel liefde|liefde voor het vak)\b/u' => ['enthousiasme', 'verzonnen warmte; vervang door een feit uit de brief'],
    '/\b(uw|jouw) tevredenheid\b|\bde klant staat centraal\b/u' => ['enthousiasme', 'dit zegt elke concurrent ook'],
    '/\b(wij|we) (geloven|denken graag met (u|je) mee|gaan (ervoor|voor))\b/u' => ['enthousiasme', 'invulzin; zeg wat je doet, niet wat je gelooft'],
    '/\bniets is ons te veel\b|\bgeen moeite te veel\b/u' => ['enthousiasme', 'onhoudbare belofte'],

    // ── Vormtics die de huisregels niet vangen ────────────────────────────
    '/\b(het|de) (resultaat|gevolg|beste|mooiste|grootste voordeel)\s*[:?]/u' => ['ai', 'dubbelpuntonthulling of een vraag die je zelf beantwoordt; schrijf het gewoon'],
    '/\bof (u|je|jij) nu .{1,60}? of\b/u'            => ['ai', '"of je nu ... of ..." is een AI-opening'],
    '/\bin een (wereld|tijd) waar(in)?\b/u'          => ['ai', '"in een wereld waar" is een AI-opening'],
    '/\bvan a tot z\b/u'                             => ['ai', 'cliché; zeg wat je wel en niet doet'],
    '/\bhet beste van (twee|beide) werelden\b/u'     => ['ai', 'cliché'],
    '/\b(de kers op de taart|een fluitje van een cent|stuk voor stuk)\b/u' => ['ai', 'cliché'],
    '/\bkortom\b/u'                                  => ['ai', '"kortom" vat samen wat er al stond'],
  ];

  /** Wat elke groep betekent, voor de interface en voor de prompt. */
  const GROUPS = [
    'nl'           => 'Nederlands uit Nederland',
    'stijf'        => 'Ambtelijke taal',
    'enthousiasme' => 'Verzonnen enthousiasme',
    'ai'           => 'AI-vormtic',
  ];

  /**
   * De harde treffers in één tekst.
   *
   * @return array<int,array{group:string,hit:string,note:string}> hoogstens drie per tekst
   */
  public static function tells(string $text): array {
    if (!self::on()) return [];
    $t = mb_strtolower(wp_strip_all_tags($text));
    if (trim($t) === '') return [];
    $out = [];
    foreach (self::TELLS as $re => [$group, $note]) {
      if (!preg_match($re, $t, $m)) continue;
      $out[] = ['group' => $group, 'hit' => trim((string) $m[0]), 'note' => $note];
      if (count($out) >= 3) break;
    }
    return $out;
  }

  /** Staat er een harde treffer in? (snelle vorm voor de vangrails van de copycoach) */
  public static function has_tell(string $text): bool {
    return (bool) self::tells($text);
  }

  /**
   * Alle treffers van een pagina, per tekst-id, voor de nalezing.
   *
   * @param array $texts id => ['text' => ..., 'section' => ..., ...]
   * @return array<string,array> id => treffers
   */
  public static function scan(array $texts): array {
    $out = [];
    foreach ($texts as $id => $t) {
      $hits = self::tells((string) ($t['text'] ?? ''));
      if ($hits) $out[(string) $id] = $hits;
    }
    return $out;
  }

  /** De treffers van een pagina als regels voor de prompt van de nalezing. */
  public static function findings_block(array $scan, array $texts): string {
    if (!$scan) return '';
    $lines = [];
    foreach ($scan as $id => $hits) {
      $cur = mb_substr((string) ($texts[$id]['text'] ?? ''), 0, 120);
      foreach ($hits as $h) $lines[] = '- ' . $id . ' ("' . $cur . '"): "' . $h['hit'] . '" - ' . $h['note'];
      if (count($lines) >= 12) break;
    }
    return "FOUND IN THIS PAGE (checked mechanically, so these are certain). Rewrite every text listed here; the note says what is wrong and what a Fleming would write instead. A rewrite that keeps the flagged word is refused:\n"
      . implode("\n", $lines) . "\n";
  }

  /**
   * De checklist voor de nalezing: wat een mens hoort en een regel niet vangt.
   * Engels, zoals de rest van de prompts; de voorbeelden blijven Nederlands.
   */
  public static function checklist(string $address = ''): string {
    $u = $address === 'u';
    return "SOUNDS-LIKE-AI CHECK (Flemish). English rules about AI writing do not catch Dutch AI writing. Look for these four, in this order:\n"
      . "- Dutch from the Netherlands. The business is Flemish and writes Flemish. Not hovenier but tuinaanlegger, not prijsopgave but offerte, not mobieltje but gsm, not pinnen but betalen met Bancontact, not zzp'er but zelfstandige, not zorgverzekering but ziekenfonds. No 'lekker' or 'hartstikke' as an intensifier. Spelling and word order stay standard Dutch; the vocabulary is the Flemish one.\n"
      . ($u
        ? "- Stiff 'u'. Writing 'u' makes a model fall back on the language of a letter from the town hall: gelieve, u dient, middels, zo spoedig mogelijk, wij hanteren, met betrekking tot, te allen tijde. 'u' is polite, not formal: 'Bel ons voor 10 uur en we komen dezelfde dag', not 'Gelieve ons tijdig te contacteren'.\n"
        : "- Stiff phrasing. Even with 'je', watch for the civil-servant register: dient, middels, zo spoedig mogelijk, met betrekking tot. Say it the way someone says it at the counter.\n")
      . "- Invented enthusiasm. When a model has no facts, it fills the space with warmth: 'wij staan altijd voor u klaar', 'u bent bij ons aan het juiste adres', 'met passie en liefde voor het vak', 'uw tevredenheid staat voorop', 'daar kunt u op rekenen'. Every one of these is a sentence a competitor could print unchanged. Replace it with a fact from the brief, or cut it. Warmth that is earned is a concrete detail, not an adjective.\n"
      . "- Form tics. A colon or a question that reveals its own answer ('Het resultaat: ...', 'Het mooiste? ...'), an opener like 'Of je nu ... of ...' or 'In een wereld waar ...', a sentence that keeps trailing into comma clauses, and the clichés van A tot Z, de kers op de taart, het beste van twee werelden, stuk voor stuk, kortom.\n"
      . "A text that trips one of these is a weak text, whatever else it does well. Count them in the craft score.\n";
  }

  /** Eén regel per groep, met hoeveel treffers: voor de controle voor publiceren. */
  public static function group_counts(array $scan): array {
    $n = [];
    foreach ($scan as $hits) foreach ($hits as $h) $n[$h['group']] = ($n[$h['group']] ?? 0) + 1;
    return $n;
  }
}
