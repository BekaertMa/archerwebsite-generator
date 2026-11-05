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
 * - Beelden lager op de pagina: één keer onthuld bij het binnenscrollen (clip-path, 600ms), alleen in secties met
 *   één of twee grote beelden en hoogstens drie per pagina. Geen tekst.
 * - Sectietitels lager op de pagina: woord per woord binnen (500ms, 40ms na elkaar, hoogstens zes per pagina), uit
 *   awwwards-sites (Meng To, MIT, via getedgehq/skills): de volledige titel blijft leesbaar voor schermlezers.
 * - Links en menu: kleurovergang 150ms; ankerlinks schuiven zacht (niet bij minder beweging).
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
      // Links en menu: kleurovergang bij hover (150ms), zoals de knoppen.
      . 'html body :is(.brxe-nav-menu a,.brxe-text-link,.brxe-text a,.brxe-text-basic a,.brxe-rich-text a){transition:color 150ms ease,opacity 150ms ease;}'
      // Ankerlinks schuiven zacht, behalve als de bezoeker minder beweging vraagt.
      . '@media (prefers-reduced-motion:no-preference){html{scroll-behavior:smooth;}}'
      . 'html.archer-motion .archer-reveal{clip-path:inset(0 0 100% 0);}'
      . 'html.archer-motion .archer-reveal.is-in{clip-path:inset(0 0 0 0);transition:clip-path 600ms var(--a-ease-in-out);}'
      . '@media (prefers-reduced-motion:reduce){html.archer-motion .archer-reveal{clip-path:none;}}'
      // Sectietitels woord per woord (js(): alleen met beweging en JavaScript; zonder blijft de titel gewoon staan).
      . '.archer-sr{position:absolute !important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}'
      . 'html.archer-motion .archer-words .archer-w{display:inline-block;opacity:0;transform:translateY(0.35em);}'
      . 'html.archer-motion .archer-words.is-in .archer-w{opacity:1;transform:none;transition:opacity 500ms var(--a-ease-out),transform 500ms var(--a-ease-out);transition-delay:calc(var(--i) * 40ms);}';
  }

  /** CSS voor de herosectie (klasse archer-hero; %root% wordt niet overal vervangen). */
  public static function hero_css(bool $cover): string {
    $a = 'animation:archer-hero-in 400ms ' . self::EASE_OUT . ' backwards;';
    $d = fn(int $ms) => 'animation-delay:' . $ms . 'ms;';
    return self::MARK
      // Een absoluut geplaatste foto (hero-89) mag niet over de volgende sectie lopen.
      . '.archer-hero [class*="img-wrapper"]{max-height:100%;}'
      . '@keyframes archer-hero-in{from{opacity:0;transform:translateY(8px);}}'
      . '@keyframes archer-hero-fade{from{opacity:0;}}'
      . '.archer-hero :is(h1,h2).brxe-heading{' . $a . $d(60) . '}'
      . '.archer-hero :is(.brxe-text-basic,.brxe-text,.brxe-rich-text):not(.brxe-heading){' . $a . $d(0) . '}'
      . '.archer-hero :is(h1,h2).brxe-heading ~ :is(.brxe-text-basic,.brxe-text,.brxe-rich-text){' . $d(120) . '}'
      . '.archer-hero .brxe-button{' . $a . $d(180) . '}'
      . '.archer-hero .brxe-button ~ .brxe-button{' . $d(240) . '}'
      // Het beeld komt in focus (blur → scherp); tekst krijgt geen blur, die moet meteen leesbaar zijn.
      . ($cover ? '' : '@keyframes archer-hero-img{from{opacity:0;transform:translateY(8px);filter:blur(4px);}}'
        . '.archer-hero .brxe-image{animation:archer-hero-img 500ms ' . self::EASE_OUT . ' backwards;' . $d(240) . '}')
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
  // Cookiemelding in de taal van de site. archer-cookie-consent 1.1 doet dit zelf; sites met 1.0 hebben een vaste
  // Engelse knop en een melding in de taal van de browser. Zelfde teksten als de plugin, zodat niets verspringt.
  (function () {
    var lang = (document.documentElement.getAttribute("lang") || "").slice(0, 2).toLowerCase();
    var labels = { nl: "Cookie-instellingen", fr: "Paramètres cookies", de: "Cookie-Einstellungen", en: "Cookie settings" };
    // Vaste Engelse Bricks-teksten voor schermlezers (overslaglink, "opent in nieuw tabblad", mobiel menu).
    var bricks = {
      nl: { skip: "Naar de inhoud", tab: "Opent in een nieuw tabblad", open: "Menu openen", close: "Menu sluiten" },
      fr: { skip: "Aller au contenu", tab: "S'ouvre dans un nouvel onglet", open: "Ouvrir le menu", close: "Fermer le menu" },
      de: { skip: "Zum Inhalt springen", tab: "Öffnet in einem neuen Tab", open: "Menü öffnen", close: "Menü schließen" }
    };
    var b = bricks[lang];
    if (b) {
      var sk = document.querySelector("a.skip-link"); if (sk) sk.textContent = b.skip;
      var nt = document.getElementById("brx-new-tab-link-description"); if (nt) nt.textContent = b.tab;
      document.querySelectorAll('[aria-label="Open mobile menu"],[aria-label="Open menu"]').forEach(function (e) { e.setAttribute("aria-label", b.open); });
      document.querySelectorAll('[aria-label="Close mobile menu"],[aria-label="Close menu"]').forEach(function (e) { e.setAttribute("aria-label", b.close); });
      if (window.bricksData && window.bricksData.i18n) {
        var i = window.bricksData.i18n;
        if (i.opensInNewTab) i.opensInNewTab = b.tab;
        if (i.openMobileMenu) i.openMobileMenu = b.open;
        if (i.closeMobileMenu) i.closeMobileMenu = b.close;
      }
    }
    var fix = function () {
      var t = document.querySelector(".archer-cc-floating-trigger");
      if (t && labels[lang] && t.textContent !== labels[lang]) t.textContent = labels[lang];
      var cc = window.CookieConsent;
      if (cc && cc.setLanguage && lang && labels[lang]) { try { cc.setLanguage(lang); } catch (e) {} }
    };
    fix();
    setTimeout(fix, 800);
  })();
  // Leesbaarheid: geen donkere tekst, iconen of logo's op een donkere achtergrond (en omgekeerd). Stijlen, logo's en
  // secties komen van verschillende plaatsen; een donker logo kwam zo op een zwarte footer (Garage De Kaproen).
  (function () {
    var rgb = function (v) {
      var m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/.exec(String(v || ""));
      return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null;
    };
    var lum = function (c) {
      var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
      return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]);
    };
    var ratio = function (a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    // De achtergrond waarop een element staat; null bij een foto of verloop (dan niet ingrijpen).
    var bgOf = function (el) {
      for (var p = el; p && p.nodeType === 1; p = p.parentElement) {
        var cs = getComputedStyle(p);
        if (cs.backgroundImage && cs.backgroundImage !== "none") return null;
        var c = rgb(cs.backgroundColor);
        if (c && c[3] >= 0.6) return c;
      }
      return [255, 255, 255, 1];
    };
    var fixText = function () {
      var els = document.querySelectorAll("h1,h2,h3,h4,h5,h6,p,li,a,span,label,blockquote,figcaption,.brxe-text-basic,.brxe-icon,i[class*='iconoir'],svg");
      var n = 0;
      for (var i = 0; i < els.length && n < 2500; i++) {
        var el = els[i];
        if (el.closest("#cc-main, .archer-cc-floating-trigger, script, style, noscript")) continue;
        var isIcon = el.tagName === "svg" || el.tagName === "I" || el.classList.contains("brxe-icon");
        if (!isIcon) {
          var own = false;
          for (var k = 0; k < el.childNodes.length; k++) if (el.childNodes[k].nodeType === 3 && el.childNodes[k].textContent.trim()) { own = true; break; }
          if (!own) continue;
        }
        n++;
        var cs = getComputedStyle(el);
        if (cs.visibility === "hidden" || cs.display === "none") continue;
        var fg = rgb(isIcon && el.tagName === "svg" ? (cs.stroke !== "none" ? cs.stroke : cs.color) : cs.color);
        if (!fg || fg[3] < 0.4) continue;
        var bg = bgOf(el.parentElement || el);
        if (!bg) continue;
        // Alleen echt slecht leesbare combinaties: een merkkleur als accent op wit (oranje: 3:1) blijft staan.
        var dark = lum(bg) < 0.2;
        if (ratio(fg, bg) >= (dark ? (isIcon ? 2.5 : 3) : 2)) continue;
        el.style.setProperty("color", dark ? "#ffffff" : "#141414", "important");
        if (el.tagName === "svg" && cs.stroke !== "none") el.style.setProperty("stroke", dark ? "#ffffff" : "#141414", "important");
      }
    };
    // Logo's: de gemiddelde lichtheid van het logo tegenover de achtergrond; te weinig verschil geeft een licht
    // (of donker) vlak achter het logo. Het logo zelf blijft in zijn kleuren.
    var fixLogo = function (img) {
      if (!img.complete || !img.naturalWidth || img.getAttribute("data-archer-contrast")) return;
      img.setAttribute("data-archer-contrast", "1");
      var bg = bgOf(img.parentElement || img);
      if (!bg) return;
      try {
        var w = Math.min(120, img.naturalWidth), h = Math.max(1, Math.round(w * img.naturalHeight / img.naturalWidth));
        var c = document.createElement("canvas"); c.width = w; c.height = h;
        var x = c.getContext("2d"); x.drawImage(img, 0, 0, w, h);
        var d = x.getImageData(0, 0, w, h).data, sum = 0, cnt = 0;
        for (var j = 0; j < d.length; j += 4) if (d[j + 3] > 128) { sum += lum([d[j], d[j + 1], d[j + 2]]); cnt++; }
        if (!cnt) return;
        var l = sum / cnt, lb = lum(bg);
        if ((Math.max(l, lb) + 0.05) / (Math.min(l, lb) + 0.05) >= 2.2) return;
        var lightChip = l < 0.4;
        img.style.setProperty("background", lightChip ? "#ffffff" : "#141414", "important");
        img.style.setProperty("padding", "6px 10px", "important");
        img.style.setProperty("border-radius", "8px", "important");
        img.style.setProperty("box-sizing", "content-box", "important");
      } catch (e) {}
    };
    var run = function () {
      fixText();
      Array.prototype.forEach.call(document.querySelectorAll("img.bricks-site-logo, .brxe-logo img"), function (img) {
        if (img.complete) fixLogo(img); else img.addEventListener("load", function () { fixLogo(img); }, { once: true });
      });
    };
    if (document.readyState === "complete") run(); else window.addEventListener("load", run);
    setTimeout(run, 1500);
  })();
  var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  // Alt-teksten (UI/UX Pro Max: elke foto een beschrijving): foto's zonder alt krijgen de titel van hun blok
  // of sectie, in de taal van de site. Logo's en versiering blijven zoals ze zijn.
  var heading = function (el) {
    for (var p = el.parentElement, d = 0; p && d < 6; p = p.parentElement, d++) {
      var h = p.querySelector("h1,h2,h3,h4,.brxe-heading");
      if (h && (h.textContent || "").trim()) return (h.textContent || "").trim().replace(/\s+/g, " ").slice(0, 120);
    }
    return "";
  };
  Array.prototype.forEach.call(document.querySelectorAll(".brxe-section img"), function (img) {
    if ((img.getAttribute("alt") || "").trim() || img.closest(".brxe-logo,[class*='logo'],header,footer") || img.getAttribute("role") === "presentation") return;
    var t = heading(img);
    if (t) img.setAttribute("alt", t);
  });
  if (reduce || !("IntersectionObserver" in window)) return;
  document.documentElement.classList.add("archer-motion");
  var vh = window.innerHeight || 800;
  var inView = function (el) { var r = el.getBoundingClientRect(); return r.top < vh && r.bottom > 0; };
  var sections = Array.prototype.slice.call(document.querySelectorAll(".brxe-section")).filter(function (s) {
    return !s.closest(".archer-header,.archer-footer,header,footer") && s.offsetHeight > 0;
  });
  var hero = sections[0];

  // Sectietitels woord per woord (awwwards-sites, Meng To, MIT): eenmalig bij het binnenscrollen, 40ms na elkaar.
  // Alleen titels zonder links of opmaak, 2 tot 12 woorden, lager dan het eerste scherm, hoogstens zes per pagina.
  // De volledige titel blijft voor schermlezers en zoekmachines; de losse woorden zijn versiering (aria-hidden).
  var titles = [];
  sections.forEach(function (sec) {
    if (sec === hero || titles.length >= 6) return;
    var h = sec.querySelector("h2.brxe-heading,h2");
    if (!h || h.children.length || h.closest(".archer-hero,.archer-cover,[class*='logo']")) return;
    var text = (h.textContent || "").trim().replace(/\s+/g, " ");
    var words = text.split(" ");
    if (words.length < 2 || words.length > 12 || inView(h)) return;
    titles.push([h, text, words]);
  });
  if (titles.length) {
    var tio = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (!e.isIntersecting) return;
        e.target.classList.add("is-in");
        tio.unobserve(e.target);
      });
    }, { rootMargin: "0px 0px -80px 0px" });
    titles.forEach(function (t) {
      var h = t[0], sr = document.createElement("span"), vis = document.createElement("span");
      sr.className = "archer-sr";
      sr.textContent = t[1];
      vis.setAttribute("aria-hidden", "true");
      t[2].forEach(function (w, i) {
        if (i) vis.appendChild(document.createTextNode(" "));
        var s = document.createElement("span");
        s.className = "archer-w";
        s.style.setProperty("--i", i);
        s.textContent = w;
        vis.appendChild(s);
      });
      h.textContent = "";
      h.appendChild(sr);
      h.appendChild(vis);
      h.classList.add("archer-words");
      tio.observe(h);
    });
  }

  // Beelden lager op de pagina: één keer onthullen bij het binnenscrollen. Geen logo's, iconen of kleine beelden.
  // Alleen het beeld van een sectie met één of twee grote beelden (geen galerijen of kaartrasters), hoogstens drie
  // per pagina: een onthulling per sectie wordt anders een patroon dat de inhoud ophoudt.
  var big = function (el) { var r = el.getBoundingClientRect(); return r.width >= 200 && r.height >= 120; };
  var imgs = [];
  sections.forEach(function (sec) {
    if (sec === hero || imgs.length >= 3 || sec.matches(".archer-cover,[class*='logo']")) return;
    var list = Array.prototype.slice.call(sec.querySelectorAll(".brxe-image")).filter(function (el) {
      return !el.closest(".brxe-logo,[class*='logo']") && big(el);
    });
    if (!list.length || list.length > 2) return;
    if (!inView(list[0])) imgs.push(list[0]);
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
