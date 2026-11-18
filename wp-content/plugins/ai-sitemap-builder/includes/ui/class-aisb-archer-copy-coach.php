<?php
if (!defined('ABSPATH')) exit;

/**
 * Copycoach: de principes van Copytrained (copytrained.com/coach: het argument, de koptekstcheck,
 * bewijs bij twijfel, bezwaren, risico wegnemen, duidelijk aanbod, eerlijke afsluiter), herleid
 * tot wat past bij de website van een kmo. Geen lange salespagina's: geen valse urgentie,
 * geen verzonnen bewijs, en de huisregels (AISB_Archer_Taste) en de FACTS-regel winnen altijd.
 *
 * - plan_rules():   voor het sectieplan per pagina (het argument van boven naar onder).
 * - copy_rules():   voor het schrijven van elke sectie.
 * - section_hint(): wat de coach extra vraagt van een hero, FAQ, oproep of bewijs.
 * - review_prompt() en apply_review(): één nalezing per pagina met een score op 100 en
 *   herschrijvingen van de zwakste teksten, met vangrails (geen nieuwe cijfers, placeholders blijven).
 */
class AISB_Archer_Copy_Coach {

  const SOURCE = 'Copytrained principles, adapted for small-business websites';

  /** Rubriek op 100, herwogen voor kmo-sites (Copytrained: argument 30, craft 25, proof 12 ...). */
  const RUBRIC = [
    'promise'    => 25,
    'argument'   => 25,
    'proof'      => 15,
    'objections' => 15,
    'action'     => 10,
    'craft'      => 10,
  ];

  /** Aan/uit voor de hele coach (regels en nalezing), met de filter archer_copy_coach. */
  public static function on(): bool {
    return (bool) apply_filters('archer_copy_coach', true);
  }

  /**
   * Aanspreekvorm uit de toon van de intake ("Toon van de teksten: Vriendelijk (je)."): 'je', 'u' of ''.
   * Zonder deze regel koos elke sectie zelf en stond er u en je door elkaar op één pagina.
   */
  public static function address(string $brief): string {
    if (preg_match('/Toon van de teksten:[^\n]*\((je|u)\)/iu', $brief, $m)) return mb_strtolower($m[1]);
    return '';
  }

  /** De regel voor de aanspreekvorm, voor elke sectie en voor de nalezing. */
  public static function address_rule(string $brief): string {
    $a = self::address($brief);
    if ($a === 'je') return "ADDRESS (Dutch): address the reader with 'je' and 'jouw' in every text on every page, never 'u' or 'uw'. This overrides the house rule about 'u'.\n";
    if ($a === 'u') return "ADDRESS (Dutch): address the reader with 'u' and 'uw' in every text on every page, never 'je' or 'jouw'.\n";
    return '';
  }

  /** Hoeveel teksten de nalezing per pagina hooguit herschrijft. */
  const MAX_FIXES = 8;

  public static function plan_rules(): string {
    return "COPY ARGUMENT (" . self::SOURCE . "):\n"
      . "- Plan the home page and every service or product page as one argument from top to bottom: the visitor's situation or wish (use 'Waarmee klanten komen' from the brief when it is there), the result the business delivers, how it works, proof, the doubts answered, the action. About, contact and legal pages keep their own job.\n"
      . "- The promise belongs in the hero: the most specific, believable result the brief supports. Do not save it for further down the page.\n"
      . "- Plan proof (reviews, references, certifications, years in business, guarantees, only from the brief) next to the doubts and the final call to action, not in a block far from the decision.\n"
      . "- When the brief lists customer doubts ('Twijfels van klanten') or FAQ topics, plan a FAQ or reassurance section that answers them right before the final call to action.\n"
      . "- When the brief states a guarantee, a free quote, a trial or a no-obligation first visit, plan it next to the primary action. Never plan one the brief does not state.\n"
      . "- A reason to act now only when it is true and in the brief (season, waiting time, limited capacity, an opening date). Never plan countdowns, 'limited spots' or deadlines the brief does not give.\n";
  }

  public static function copy_rules(): string {
    return "COPY COACH (" . self::SOURCE . "; the copy rules and FACTS above win when they differ):\n"
      . "- The argument: every section moves the visitor one step, from their situation or wish, to the result, to why this business, to the action. Use the customer's own words from the brief ('Waarmee klanten komen', 'Twijfels van klanten', reviews) over company language.\n"
      . "- Paint the result: where the brief allows it, show the result as one concrete moment the customer recognises (the car ready on Friday, the cake on the table on Sunday) instead of a list of outcomes. One such picture per page is enough, and it stays true to the brief.\n"
      . "- Specific beats general: a number, a place, a time or a named service from the brief beats an adjective. The most specific true promise goes to the top of the page.\n"
      . "- Proof sits next to doubt: proof lines (reviews, references, labels, guarantees from the brief) go in the sections near the FAQ and the final action. Proof the brief does not give (a review, a customer name, a number) stays a [placeholder]; never fill other slots with placeholders.\n"
      . "- Objections: an FAQ answer handles the hesitation behind the question (price, time, hassle, 'is this for me'), starts with the answer, and where useful ends with what the visitor can do next.\n"
      . "- Risk reversal: when the brief states a guarantee, a free quote or no obligation, say what it means for the visitor, close to the button. Never invent one.\n"
      . "- Offer clarity: say what the visitor gets, how pricing works (only as the brief states it) and what happens after they get in touch.\n"
      . "- The close: the last call to action talks to the visitor who still hesitates: the result in one line, the one action, and a reason to act now only if the brief gives one. No fake urgency.\n"
      . "- Keep the reader moving: every section heading says what the reader gets from that section, so someone who only reads the headings understands the offer.\n";
  }

  /** Extra opdracht van de coach voor een sectie (komt ná die van AISB_Archer_Marketing). */
  public static function section_hint(string $purpose, string $type = ''): string {
    $t = mb_strtolower($purpose . ' ' . $type);
    $roles = [
      'hero|banner|intro' => 'Headline check, all eight: leads into the line under it; believable; leads with the customer\'s biggest wish or most pressing problem; specific and visual; clear for whom (headline or the line under it); gives a reason to keep reading (a specific promise, the place, a number from the brief); clear before clever; within the length limit.',
      'faq|question|vraag|vragen|questions' => 'Pick the questions that stop people from acting (price, time, area, hassle, what happens next) and answer the doubt behind each one. Put a proof point or guarantee from the brief next to the most common doubt.',
      'testimonial|review|getuig|klant(en)?verhal|social proof|logo' => 'Proof works best when it matches the reader: a customer like them, a result they want. Say in the heading what the proof is about; never write a quote yourself.',
      'cta|call to action|contact us|offerte|afspraak|booking|reserv|quote' => 'Speak to the visitor who is still hesitating: the result in one line, the one action, and only the reassurance and urgency the brief supports (guarantee, free quote, answer within a day, season).',
      'garant|guarantee|warranty|belofte|promise' => 'Sell the safety, not the terms: say in plain words what the guarantee from the brief means for the customer, and nothing the brief does not state.',
    ];
    foreach ($roles as $re => $hint) {
      if (preg_match('/(' . $re . ')/u', $t)) return 'COACH: ' . $hint . "\n";
    }
    return '';
  }

  /* ───────────────────────── Nalezing per pagina ───────────────────────── */

  /**
   * De vraag voor de nalezing van één pagina.
   * @param array $texts id => ['section' => purpose, 'type' => heading|button|text|..., 'text' => ..., 'max_chars' => int]
   */
  public static function review_prompt(string $brief, array $page, array $texts): string {
    $rub = [];
    foreach (self::RUBRIC as $k => $max) $rub[] = $k . ' ' . $max;
    return "You are a conversion copy coach. Review the copy of ONE page of a small-business website (" . self::SOURCE . "). Score it, then rewrite only the weakest texts.\n\n"
      . "RUBRIC (100 points: " . implode(', ', $rub) . "):\n"
      . "- promise: the hero gives the most specific, believable result the brief supports, for whom and where. Headline check: leads into the line under it, believable, leads with the biggest wish or problem, specific and visual, clear for whom, a reason to keep reading, clear before clever.\n"
      . "- argument: the page moves from the visitor's situation or wish to the result to why this business; benefits in the customer's words; every section adds something new.\n"
      . "- proof: only proof from the brief, placed near doubts and the action; [placeholders] where the brief has none.\n"
      . "- objections: the real hesitations are answered (price, time, hassle, what happens after contact); risk reversal only as the brief states it.\n"
      . "- action: one primary action, buttons with a verb and the same label for the same action, an honest close without fake urgency.\n"
      . "- craft: plain and specific, no stock phrases or AI tells, no repetition, headings that say what the reader gets.\n\n"
      . "FIX RULES:\n"
      . "- Rewrite at most " . self::MAX_FIXES . " texts, only where the change clearly raises the score. Leave good texts alone. A page that already scores well may need no fixes.\n"
      . "- Every fact comes from the BRIEF or from the current texts of this page. Never invent numbers, prices, guarantees, reviews, names, years, awards, timeframes, services or conditions. Keep every [placeholder] exactly as it is.\n"
      . "- Stay under max_chars, complete phrases, the same language, plain text without HTML. Keep the form of address of the ADDRESS rule below; if there is none, keep the one the page already uses.\n"
      . "- Never invent how things work (ordering by phone or e-mail, delivery, a confirmation, response times, payment) unless the brief says so.\n"
      . "- Headings stay short titles without a full stop. Buttons stay 1 to 4 words, start with a verb and are complete phrases ('Neem contact op', not 'Neem contact'). Do not move the page's closing call to action away from the primary action of the brief.\n"
      . "- Never write about the website itself (this page, section, click here).\n\n"
      . (class_exists('AISB_Archer_Taste') ? AISB_Archer_Taste::copy_rules() . "\n" : '')
      . "Return JSON: {\"scores\": {" . implode(', ', array_map(fn($k) => '"' . $k . '": int', array_keys(self::RUBRIC))) . "}, \"total\": int, \"weak\": [\"max 4 short notes on what holds the page back\"], \"fixes\": {\"<id>\": \"<new text>\"}}\n\n"
      . self::address_rule($brief)
      . "BRIEF:\n" . mb_substr($brief, 0, 4500) . "\n\n"
      . "PAGE: " . wp_json_encode($page, JSON_UNESCAPED_UNICODE) . "\n\n"
      . "TEXTS (id => section purpose, kind of text, current text, max_chars):\n" . wp_json_encode($texts, JSON_UNESCAPED_UNICODE);
  }

  /**
   * Controleert de herschrijvingen van de nalezing. Geeft id => nieuwe tekst terug, alleen voor
   * herschrijvingen die door de vangrails komen.
   */
  public static function accept_fixes(array $fixes, array $texts, string $brief, array $locked = []): array {
    // Sommige modellen geven een lijst [{id, text}] in plaats van {id: text}.
    if ($fixes && array_is_list($fixes)) {
      $map = [];
      foreach ($fixes as $f) {
        if (!is_array($f)) continue;
        $fid = $f['id'] ?? $f['key'] ?? null;
        $ftx = $f['text'] ?? $f['new_text'] ?? $f['new'] ?? $f['value'] ?? null;
        if (is_string($fid) && is_string($ftx)) $map[$fid] = $ftx;
      }
      $fixes = $map;
    }
    // Een id zonder instelling ("section_1|abc" in plaats van "section_1|abc|text"): koppelen als dat eenduidig is.
    $short = [];
    foreach (array_keys($texts) as $tid) {
      $p = explode('|', (string) $tid);
      if (count($p) === 3) $short[$p[0] . '|' . $p[1]][] = (string) $tid;
    }
    $norm = [];
    foreach ($fixes as $fid => $ftx) {
      $fid = (string) $fid;
      if (!isset($texts[$fid]) && isset($short[$fid]) && count($short[$fid]) === 1) $fid = $short[$fid][0];
      $norm[$fid] = $ftx;
    }
    $fixes = $norm;
    $known = mb_strtolower($brief);
    foreach ($texts as $t) $known .= ' ' . mb_strtolower((string) ($t['text'] ?? ''));
    $known_nums = self::numbers($known);
    $ok = [];
    foreach ($fixes as $id => $new) {
      if (count($ok) >= self::MAX_FIXES) break;
      $id = (string) $id;
      if (!isset($texts[$id]) || !is_string($new)) continue;
      $old = (string) $texts[$id]['text'];
      $kind = (string) ($texts[$id]['type'] ?? '');
      $new = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($new)));
      if ($new === '' || $new === $old) continue;
      // Een knoplabel dat elders op de pagina ook staat (header, andere oproep) blijft gelijk.
      if (in_array($kind, ['button', 'text-link'], true) && isset($locked[mb_strtolower($old)])) continue;
      // Geen nieuwe cijfers (prijzen, jaren, aantallen, termijnen) die nergens in de brief of op de pagina staan.
      if (array_diff(self::numbers(mb_strtolower($new)), $known_nums)) continue;
      // Placeholders blijven staan.
      preg_match_all('/\[[^\]]+\]/u', $old, $po);
      foreach ($po[0] as $ph) if (mb_strpos($new, $ph) === false) continue 2;
      if (preg_match('/\[[^\]]+\]/u', $new) && !$po[0]) continue;
      if (class_exists('AISB_Archer_Taste') && AISB_Archer_Taste::stock_phrase($new)) continue;
      // Een herschrijving moet de AI-tics (Nederlands uit Nederland, ambtelijk, verzonnen enthousiasme)
      // wegnemen, niet omruilen. "Wij staan voor u klaar" vervangen door "U bent bij ons aan het juiste
      // adres" is geen verbetering, dus zo'n voorstel gaat niet door: het aantal treffers moet dalen.
      if (class_exists('AISB_Archer_Dutch') && AISB_Archer_Dutch::on()) {
        $tells_new = count(AISB_Archer_Dutch::tells($new));
        if ($tells_new > 0 && $tells_new >= count(AISB_Archer_Dutch::tells($old))) continue;
      }
      if ($kind === 'heading') $new = rtrim($new, '.');
      if (in_array($kind, ['button', 'text-link'], true) && count(preg_split('/\s+/u', $new)) > 4) continue;
      $max = (int) ($texts[$id]['max_chars'] ?? 0);
      if ($max && mb_strlen($new) > $max * ($kind === 'heading' ? 1.3 : 1.1)) continue;
      $ok[$id] = $new;
    }
    return $ok;
  }

  /**
   * Bewaart de score per pagina (verborgen meta, voor opvolging en latere weergave).
   *
   * @param array $tells Wat er na de nalezing nog als AI klinkt (AISB_Archer_Dutch::scan op de
   *                     teksten zoals ze nu zijn): de treffers die de coach niet opgeruimd kreeg.
   */
  public static function remember(int $pid, string $slug, $res, int $fixed, array $changes = [], array $tells = []): void {
    if (!$pid || $slug === '' || !is_array($res)) return;
    $scores = [];
    foreach (self::RUBRIC as $k => $max) $scores[$k] = max(0, min($max, (int) ($res['scores'][$k] ?? 0)));
    $weak = array_slice(array_values(array_filter(array_map(fn($w) => is_string($w) ? sanitize_text_field($w) : '', (array) ($res['weak'] ?? [])))), 0, 4);
    $left = [];
    foreach ($tells as $hits) foreach ((array) $hits as $h) {
      $left[] = ['group' => (string) ($h['group'] ?? ''), 'hit' => sanitize_text_field((string) ($h['hit'] ?? '')), 'note' => sanitize_text_field((string) ($h['note'] ?? ''))];
      if (count($left) >= 8) break 2;
    }
    update_post_meta($pid, '_archer_copy_score_' . $slug, [
      'total' => array_sum($scores), 'scores' => $scores, 'weak' => $weak, 'fixed' => $fixed, 'changes' => array_slice($changes, 0, self::MAX_FIXES), 'tells' => $left, 'at' => time(),
    ]);
  }

  private static function numbers(string $t): array {
    preg_match_all('/\d+(?:[.,]\d+)?/u', $t, $m);
    return array_values(array_unique(array_map(fn($n) => str_replace(',', '.', $n), $m[0])));
  }
}
