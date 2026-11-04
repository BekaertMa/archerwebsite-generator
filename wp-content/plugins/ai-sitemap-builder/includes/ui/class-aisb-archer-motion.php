<?php
if (!defined('ABSPATH')) exit;

/**
 * Beweging op de gepubliceerde website, volgens Emil Kowalski's animatieregels (github.com/emilkowalski/skills):
 * weinig, kort en met een reden. Een website van een kmo bezoekt men af en toe, dus er is ruimte voor
 * één binnenkomst van de hero, maar niet voor een fade-in op elke sectie.
 *
 * - Knoppen: drukfeedback (scale .97, 160ms) en een kleurovergang bij hover (150ms).
 * - Hero: titel, tekst, knoppen en beeld komen één keer binnen (opacity + 8px, 400ms, 60ms na elkaar).
 *   CSS-keyframes in de eigen CSS van de herosectie (hero_nodes): die staat in de <head>, dus de animatie
 *   start bij de eerste weergave. Het runtime-script draait pas na DOMContentLoaded, als de pagina al getekend is.
 * - Beelden lager op de pagina: één keer onthuld bij het binnenscrollen (clip-path, 600ms). Geen tekst.
 * - Minder beweging gevraagd: geen verplaatsing of onthulling, alleen een korte fade van de hero.
 *
 * css() gaat mee in de huisregels (voorbeeld en site), js() en hero_nodes() alleen naar de gepubliceerde site.
 */
class AISB_Archer_Motion {

  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';
  const MARK = '/*archer-hero-in*/';

  public static function css(): string {
    $btn = 'html body :is(.brxe-button.bricks-button,.brxe-form button[type=submit].bricks-button)';
    return ':root{--a-ease-out:' . self::EASE_OUT . ';--a-ease-in-out:' . self::EASE_IN_OUT . ';}'
      . $btn . '{transition:transform 160ms var(--a-ease-out),background-color 150ms ease,color 150ms ease,border-color 150ms ease;}'
      . $btn . ':active{transform:scale(0.97);}'
      . '@media (prefers-reduced-motion:reduce){' . $btn . ':active{transform:none;}}'
      . 'html.archer-motion .archer-reveal{clip-path:inset(0 0 100% 0);}'
      . 'html.archer-motion .archer-reveal.is-in{clip-path:inset(0 0 0 0);transition:clip-path 600ms var(--a-ease-in-out);}'
      . '@media (prefers-reduced-motion:reduce){html.archer-motion .archer-reveal{clip-path:none;}}';
  }

  /** CSS voor de herosectie (klasse archer-hero; %root% wordt niet overal vervangen). */
  public static function hero_css(bool $cover): string {
    $a = 'animation:archer-hero-in 400ms ' . self::EASE_OUT . ' backwards;';
    $d = fn(int $ms) => 'animation-delay:' . $ms . 'ms;';
    return self::MARK
      . '@keyframes archer-hero-in{from{opacity:0;transform:translateY(8px);}}'
      . '@keyframes archer-hero-fade{from{opacity:0;}}'
      . '.archer-hero :is(h1,h2).brxe-heading{' . $a . $d(60) . '}'
      . '.archer-hero :is(.brxe-text-basic,.brxe-text,.brxe-rich-text):not(.brxe-heading){' . $a . $d(0) . '}'
      . '.archer-hero :is(h1,h2).brxe-heading ~ :is(.brxe-text-basic,.brxe-text,.brxe-rich-text){' . $d(120) . '}'
      . '.archer-hero .brxe-button{' . $a . $d(180) . '}'
      . '.archer-hero .brxe-button ~ .brxe-button{' . $d(240) . '}'
      . ($cover ? '' : '.archer-hero .brxe-image{' . $a . $d(240) . '}')
      . '@media (prefers-reduced-motion:reduce){.archer-hero :is(.brxe-heading,.brxe-text-basic,.brxe-text,.brxe-rich-text,.brxe-button,.brxe-image){animation-name:archer-hero-fade;animation-duration:200ms;animation-delay:0ms;}}'
      . self::MARK;
  }

  /** De eerste inhoudssectie van een pagina krijgt de binnenkomst (eenmalig, ook bij opnieuw publiceren). */
  public static function hero_nodes(array $nodes): array {
    foreach ($nodes as $i => $n) {
      if (!is_array($n) || ($n['name'] ?? '') !== 'section' || !empty($n['parent'])) continue;
      $s = is_array($n['settings'] ?? null) ? $n['settings'] : [];
      $cover = strpos(' ' . (string) ($s['_cssClasses'] ?? '') . ' ', ' archer-cover ') !== false;
      $q = preg_quote(self::MARK, '#');
      $cur = trim((string) preg_replace('#' . $q . '.*?' . $q . '#s', '', (string) ($s['_cssCustom'] ?? '')));
      $s['_cssCustom'] = trim($cur . "\n" . self::hero_css($cover));
      $cls = trim((string) ($s['_cssClasses'] ?? ''));
      if (strpos(' ' . $cls . ' ', ' archer-hero ') === false) $s['_cssClasses'] = trim($cls . ' archer-hero');
      $nodes[$i]['settings'] = $s;
      break;
    }
    return $nodes;
  }

  public static function js(): string {
    return <<<'JS'
(function () {
  if (window.__archerMotion) return;
  window.__archerMotion = 1;
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduce || !("IntersectionObserver" in window)) return;
  document.documentElement.classList.add("archer-motion");
  var vh = window.innerHeight || 800;
  var inView = function (el) { var r = el.getBoundingClientRect(); return r.top < vh && r.bottom > 0; };
  var sections = Array.prototype.slice.call(document.querySelectorAll(".brxe-section")).filter(function (s) {
    return !s.closest(".archer-header,.archer-footer,header,footer") && s.offsetHeight > 0;
  });
  var hero = sections[0];

  // Beelden lager op de pagina: één keer onthullen bij het binnenscrollen. Geen logo's, iconen of kleine beelden.
  var imgs = Array.prototype.slice.call(document.querySelectorAll(".brxe-section .brxe-image")).filter(function (el) {
    if (hero && hero.contains(el)) return false;
    if (el.closest(".archer-header,.archer-footer,.archer-cover,.brxe-logo,[class*='logo']")) return false;
    var r = el.getBoundingClientRect();
    return r.width >= 200 && r.height >= 120 && !inView(el);
  });
  if (!imgs.length) return;
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.add("is-in");
      io.unobserve(e.target);
    });
  }, { rootMargin: "0px 0px -100px 0px" });
  imgs.forEach(function (el) { el.classList.add("archer-reveal"); io.observe(el); });
})();
JS;
  }
}
