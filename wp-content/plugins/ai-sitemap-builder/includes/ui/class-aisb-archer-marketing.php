<?php
if (!defined('ABSPATH')) exit;

/**
 * Marketingregels voor het schrijven van de teksten, uit Marketing Skills van Corey Haines
 * (github.com/coreyhaines31/marketingskills, MIT, commit 88c79f6): copywriting, copy-editing (de zeven sweeps),
 * cro en ai-seo. Ingekort tot wat telt voor de website van een kmo en afgestemd op de huisregels
 * (AISB_Archer_Taste::copy_rules), die voorrang houden.
 *
 * - plan_rules(): voor het sectieplan per pagina (opbouw, één hoofdactie, bezwaren vóór de oproep, SEO-titel en -omschrijving).
 * - copy_rules(): voor de teksten zelf (controle per zin, "so what", geen AI-tics, knoppen, antwoord eerst).
 * - section_hint(): de taak van één sectie (hero, diensten, werkwijze, FAQ, oproep ...).
 * De prompts zijn Engels, zoals de rest; de site zelf staat in de taal van het project.
 */
class AISB_Archer_Marketing {

  const SOURCE = 'Marketing Skills (Corey Haines, MIT)';

  /** Regels voor het sectieplan van een pagina (copywriting: Page Structure; cro: CTA-hiërarchie en bezwaren; ai-seo). */
  public static function plan_rules(): string {
    return "PAGE STRATEGY (" . self::SOURCE . ": copywriting, cro, ai-seo):\n"
      . "- Every page has ONE primary action (call, quote, appointment, booking, visit). Name it in page_purpose. Secondary actions stay secondary.\n"
      . "- Home: the broadest value proposition first, then a clear path for each main visitor intent (the services, the proof, the next step). Service pages: what it is, what it means for the customer, how it works (3 or 4 steps), the questions or objections people have, then the action. About: why the business exists and what that means for the customer, still ending with the action. Contact: what happens after someone gets in touch.\n"
      . "- Put proof and objection handling (FAQ, how it works, guarantees the brief actually states) right before the final call to action, not at the top.\n"
      . "- Plan sections around what the visitor needs to decide, in the visitor's words, not around the company's internal structure.\n"
      . "- SEO: primary_keyword is what a customer would type into Google or ask an AI assistant (service + town or region when the brief names one). meta_title: at most 60 characters, starts with that service, ends with the business name. meta_description: at most 155 characters, one concrete benefit and the next step; no keyword lists.\n";
  }

  /** Regels voor de teksten zelf (copywriting, copy-editing, cro, ai-seo). Komt ná de huisregels. */
  public static function copy_rules(): string {
    return "MARKETING CHECKS (" . self::SOURCE . "; the copy rules above win when they differ):\n"
      . "- Test every line: can the reader picture it, could it be proven true or false, and could a competitor print it unchanged? Fails two of the three: rewrite it from the facts in the brief.\n"
      . "- So what: after a feature, say what it means for the customer (time, money, worry, result). Benefits over features, in the words a customer uses, not company language.\n"
      . "- Ration the punch: at most one or two striking lines per page (usually the hero). The rest is plain, specific and declarative.\n"
      . "- The hero reads as a small transformation: the situation the visitor is in, the better result, the action that gets them there.\n"
      . "- No AI tells: no contrast reveals ('not X, but Y', 'not just ..., but ...'), no lists of negations ('no hassle, no waiting, no surprises'), no sentence that trails into extra comma clauses, no question you answer yourself ('The result? ...'), no colon reveals ('The best part: ...'), no openers like 'Whether you are ...' or 'In a world where ...'.\n"
      . "- Buttons: an action verb plus what the visitor gets ('Vraag een offerte aan', 'Plan uw afspraak', 'Bekijk de realisaties'). Next to the main button, one short line that lowers the risk may follow only when the brief supports it (free quote, no obligation, answer within a day).\n"
      . "- Answer first: the first sentence of a section states the point directly, so it still makes sense when quoted on its own by Google or an AI assistant. FAQ answers start with the answer (yes, no, the number, the step) and stay within the LIMITS.\n"
      . "- Use the service and the place the brief names in the hero and the intro text where it reads naturally. Never stack keywords.\n"
      . "- Claims need support from the brief. Without it, write the closest true statement or a [placeholder]; never invent numbers, guarantees or results.\n";
  }

  /** De taak van één sectie, uit de copywriting-tabel "Core Sections" en de cro-checks. */
  public static function section_hint(string $purpose, string $type = ''): string {
    $t = mb_strtolower($purpose . ' ' . $type);
    $roles = [
      'hero|banner|intro' => 'Hero: the core value proposition in the headline; the line under it says for whom and what result; the button is the page\'s primary action.',
      'faq|question|vraag|vragen|questions' => 'FAQ: the real questions and objections a customer has before they act (price, timing, area, what happens next). Each answer starts with the answer itself.',
      'testimonial|review|getuig|klant(en)?verhal|social proof|logo' => 'Proof: only proof the brief gives. Without real reviews or names, keep the [placeholders]; never write a quote yourself.',
      'process|step|stap|werkwijze|how it works|hoe werkt|approach|aanpak' => 'How it works: 3 or 4 concrete steps from first contact to result, so the job feels smaller and clearer. Each step says what the customer does or gets.',
      'cta|call to action|contact us|offerte|afspraak|booking|reserv|quote' => 'Call to action: recap the main benefit in one line, repeat the primary action with the same button label, and lower the risk only with what the brief supports.',
      'pricing|price|prijs|tarie' => 'Pricing: help the visitor pick the right option and answer "which one is for me?"; only prices from the brief.',
      'about|over ons|story|verhaal|team|mission|missie' => 'About: why the business exists, told through facts from the brief, and what that means for the customer. Still point to the next step.',
      'service|dienst|feature|offer|aanbod|solution|product' => 'Services: per item, what it is and what it means for the customer (the "so what"). Name the services the brief names; one idea per item.',
      'stat|number|cijfer|kerncijfer' => 'Numbers: only numbers from the brief, each with what it means; otherwise [placeholders].',
      'contact|form|formulier|map|kaart|location|locatie' => 'Contact: make the next step easy; say what happens after the message (who answers, how fast) only if the brief says so.',
    ];
    foreach ($roles as $re => $hint) {
      if (preg_match('/(' . $re . ')/u', $t)) return 'SECTION JOB: ' . $hint . "\n";
    }
    return '';
  }
}
