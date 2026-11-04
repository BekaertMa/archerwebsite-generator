<?php
if (!defined('ABSPATH')) exit;

/**
 * Beweging op de gepubliceerde website, volgens Emil Kowalski's animatieregels (github.com/emilkowalski/skills):
 * weinig, kort en met een reden. Een website van een kmo bezoekt men af en toe, dus er is ruimte voor
 * één binnenkomst van de hero, maar niet voor een fade-in op elke sectie.
 *
 * - Knoppen: drukfeedback (scale .97, 160ms) en een kleurovergang bij hover (150ms, alleen met muis).
 * - Hero: titel, tekst, knoppen en beeld komen één keer binnen (opacity + 8px, 400ms, 60ms na elkaar),
 *   alleen als de pagina nog niet getekend is (anders zou de inhoud even verdwijnen).
 * - Beelden lager op de pagina: één keer onthuld bij het binnenscrollen (clip-path, 600ms). Geen tekst.
 * - Minder beweging gevraagd: geen verplaatsing of onthulling, alleen een korte fade van de hero.
 *
 * css() gaat mee in de huisregels (voorbeeld en site), js() alleen naar de gepubliceerde site.
 */
class AISB_Archer_Motion {

  const EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';
  const EASE_IN_OUT = 'cubic-bezier(0.77, 0, 0.175, 1)';

  public static function css(): string {
    $btn = 'html body :is(.brxe-button.bricks-button,.brxe-form button[type=submit].bricks-button)';
    return ':root{--a-ease-out:' . self::EASE_OUT . ';--a-ease-in-out:' . self::EASE_IN_OUT . ';}'
      // Niet tijdens de binnenkomst van de hero: die zet zelf opacity en transform in overgang.
      . str_replace(')', '):not(.archer-in)', $btn) . '{transition:transform 160ms var(--a-ease-out),background-color 150ms ease,color 150ms ease,border-color 150ms ease;}'
      . $btn . ':active{transform:scale(0.97);}'
      . '@media (prefers-reduced-motion:reduce){' . $btn . ':active{transform:none;}}'
      // Onthullen en binnenkomen: de begintoestand zet het script, de eindtoestand en de overgang staan hier.
      // Begintoestand zonder overgang: sommige sjablonen (knoppen) hebben een eigen transition op opacity.
      . 'html.archer-motion .archer-in{opacity:0;transform:translateY(8px);}'
      . 'html.archer-motion .archer-in:not(.is-in){transition:none !important;}'
      . 'html.archer-motion .archer-in.is-in{opacity:1;transform:none;transition:opacity 400ms var(--a-ease-out),transform 400ms var(--a-ease-out);transition-delay:var(--archer-delay,0ms);}'
      . 'html.archer-motion .archer-reveal{clip-path:inset(0 0 100% 0);}'
      . 'html.archer-motion .archer-reveal.is-in{clip-path:inset(0 0 0 0);transition:clip-path 600ms var(--a-ease-in-out);}'
      . '@media (prefers-reduced-motion:reduce){'
      . 'html.archer-motion .archer-in{transform:none;}'
      . 'html.archer-motion .archer-in.is-in{transition:opacity 200ms ease;transition-delay:0ms;}'
      . 'html.archer-motion .archer-reveal{clip-path:none;}}';
  }

  public static function js(): string {
    return <<<'JS'
(function () {
  if (window.__archerMotion) return;
  window.__archerMotion = 1;
  var doc = document.documentElement;
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var painted = (performance.getEntriesByType ? performance.getEntriesByType("paint") : []).some(function (p) {
    return p.name === "first-contentful-paint";
  });
  var vh = window.innerHeight || 800;
  var inView = function (el) { var r = el.getBoundingClientRect(); return r.top < vh && r.bottom > 0; };
  doc.classList.add("archer-motion");

  // Hero: één keer binnenkomen, alleen als er nog niets getekend is.
  var sections = Array.prototype.slice.call(document.querySelectorAll(".brxe-section")).filter(function (s) {
    return !s.closest(".archer-header,.archer-footer,header,footer") && s.offsetHeight > 0;
  });
  var hero = sections[0];
  if (hero && !painted) {
    var parts = [];
    hero.querySelectorAll("h1,h2,.brxe-heading,p,.brxe-text-basic,.brxe-button,.brxe-image,img").forEach(function (el) {
      if (parts.length >= 8 || !inView(el)) return;
      if (hero.classList.contains("archer-cover") && (el.matches(".brxe-image") || el.tagName === "IMG")) return; // achtergrondfoto blijft staan
      if (parts.some(function (p) { return p.contains(el); })) return;
      parts.push(el);
    });
    parts.forEach(function (el, i) {
      el.classList.add("archer-in");
      el.style.setProperty("--archer-delay", (reduce ? 0 : i * 60) + "ms");
    });
    // Na afloop alles opruimen: geen achterblijvende vertraging op knoppen, en nooit inhoud die verborgen
    // blijft als de animatie niet afspeelt (achtergrondtab, trage machine).
    var cleaned = false;
    var cleanup = function () {
      if (cleaned) return;
      cleaned = true;
      parts.forEach(function (el) {
        el.classList.remove("archer-in", "is-in");
        el.style.removeProperty("--archer-delay");
      });
    };
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        parts.forEach(function (el) { el.classList.add("is-in"); });
        setTimeout(cleanup, 1000);
      });
    });
    setTimeout(cleanup, 1600);
  }

  // Beelden lager op de pagina: één keer onthullen bij het binnenscrollen. Geen logo's, iconen of kleine beelden.
  if (reduce || !("IntersectionObserver" in window)) return;
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
