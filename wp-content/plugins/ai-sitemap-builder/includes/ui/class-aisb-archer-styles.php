<?php
if (!defined('ABSPATH')) exit;

/**
 * Archer-stijlen: kant-en-klare ontwerpsystemen voor een hele website (styles/library.json).
 *
 * Een stijl zet de Brixies-variabelen in één keer: ruimte tussen en in secties, breedte, het
 * letterkorps, hoeken, de grijzen en de kleuren, en schakelt schaduwen uit. Zo volgen alle
 * sjablonen, ook die van verschillende reeksen, hetzelfde systeem. Achtergronden: één grond
 * voor alle secties; de tweede kleur alleen voor kaarten en voor hoogstens één bewuste band
 * (zie bands), plus de footer.
 *
 * De keuze staat in de stijlgids als guide.style (id). css() wordt via AISB_Archer_Taste::house_css
 * gebruikt in het voorbeeld én op de gepubliceerde site.
 */
class AISB_Archer_Styles {

  private static $lib = null;

  public function init(): void {
    add_action('wp_ajax_aisb_archer_styles', [$this, 'ajax_styles']);
    // De scripts en stijlen van de plugin dragen een vaste versie (1.8.0): browsers hielden zo oude
    // bestanden vast na een wijziging. Voortaan de datum van het bestand als versie.
    add_filter('script_loader_src', [$this, 'bust_cache'], 20);
    add_filter('style_loader_src', [$this, 'bust_cache'], 20);
  }

  public function bust_cache($src) {
    if (!is_string($src) || strpos($src, '/plugins/ai-sitemap-builder/') === false) return $src;
    $path = wp_parse_url($src, PHP_URL_PATH);
    if (!$path) return $src;
    $file = WP_PLUGIN_DIR . substr($path, strpos($path, '/plugins/') + strlen('/plugins'));
    if (!is_file($file)) return $src;
    return add_query_arg('ver', (string) filemtime($file), $src);
  }

  public static function library(): array {
    if (self::$lib === null) {
      $raw = @file_get_contents(__DIR__ . '/styles/library.json');
      $lib = $raw ? json_decode($raw, true) : null;
      self::$lib = is_array($lib) && !empty($lib['styles']) ? $lib['styles'] : [];
    }
    return self::$lib;
  }

  public static function get(string $id): ?array {
    foreach (self::library() as $s) if (($s['id'] ?? '') === $id) return $s;
    return null;
  }

  public static function of($guide): ?array {
    $id = is_array($guide) ? (string) ($guide['style'] ?? '') : '';
    return $id !== '' ? self::get($id) : null;
  }

  /* ───────────────────────── Maten ───────────────────────── */

  /** Vloeiende maat van $min px (bij 360 px breed) tot $max px (bij 1440 px). */
  public static function fluid($range): string {
    if (!is_array($range)) return (int) $range . 'px';
    [$min, $max] = [(float) $range[0], (float) $range[1]];
    if ($min === $max) return $min . 'px';
    $slope = ($max - $min) / 1080;
    return sprintf('clamp(%spx, calc(%spx + %s * (100vw - 360px)), %spx)', self::n($min), self::n($min), self::n($slope), self::n($max));
  }

  private static function n(float $v): string {
    return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
  }

  private static function mix(string $a, string $b, int $pct): string {
    return 'color-mix(in srgb, ' . $a . ' ' . $pct . '%, ' . $b . ')';
  }

  /* ───────────────────────── Stijlgids ───────────────────────── */

  /**
   * Kleurrollen voor de stijlgids (de rest van de plugin werkt met deze zes namen).
   * Light/Neutral zijn de kaart- en bandkleuren; de grond zelf staat in sectionBg1/sectionBg2.
   */
  public static function roles(array $s): array {
    $c = $s['colours'];
    return [
      ['name' => 'Primary', 'hex' => $c['accent']],
      ['name' => 'Secondary', 'hex' => $c['dark']],
      ['name' => 'Accent', 'hex' => $c['accent']],
      ['name' => 'Dark', 'hex' => $c['ink']],
      ['name' => 'Light', 'hex' => $c['surface']],
      ['name' => 'Neutral', 'hex' => $c['surface2']],
    ];
  }

  /** Letterkorps voor de stijlgids (zelfde structuur als de stap Stijl gebruikt). */
  public static function typography(array $s): array {
    $h = $s['fonts']['heading']['family'];
    $b = $s['fonts']['body']['family'];
    $t = $s['type'];
    return [
      ['cls' => 'h1', 'label' => 'Titel (H1)', 'fontFamily' => $h, 'fontSize' => $t['display'][1] . 'px', 'lineHeight' => (string) $s['fonts']['heading']['lineHeight'], 'fontWeight' => (string) $s['fonts']['heading']['weight'], 'sample' => 'Een titel in twee regels'],
      ['cls' => 'h2', 'label' => 'Sectietitel (H2)', 'fontFamily' => $h, 'fontSize' => $t['h2'][1] . 'px', 'lineHeight' => '1.08', 'fontWeight' => (string) $s['fonts']['heading']['weight'], 'sample' => 'Wat we doen'],
      ['cls' => 'h3', 'label' => 'Tussentitel (H3)', 'fontFamily' => $h, 'fontSize' => $t['h3'][1] . 'px', 'lineHeight' => '1.2', 'fontWeight' => (string) $s['fonts']['heading']['weight'], 'sample' => 'Onderhoud en herstellingen'],
      ['cls' => 'body', 'label' => 'Lopende tekst', 'fontFamily' => $b, 'fontSize' => $t['body'][1] . 'px', 'lineHeight' => (string) $s['fonts']['body']['lineHeight'], 'fontWeight' => (string) $s['fonts']['body']['weight'], 'sample' => 'Gewone tekst leest rustig, met genoeg ruimte tussen de regels.'],
      ['cls' => 'small', 'label' => 'Kleine tekst', 'fontFamily' => $b, 'fontSize' => $t['small'][1] . 'px', 'lineHeight' => '1.5', 'fontWeight' => (string) $s['fonts']['body']['weight'], 'sample' => 'Kleine tekst voor details.'],
    ];
  }

  /** Wat de stap Stijl in de stijlgids zet bij het kiezen van een stijl. */
  public static function guide_patch(array $s): array {
    return [
      'style' => $s['id'],
      'colours' => self::roles($s),
      'headingFont' => $s['fonts']['heading']['family'],
      'bodyFont' => $s['fonts']['body']['family'],
      'typography' => self::typography($s),
      'radius' => $s['radiusBucket'] ?? 'soft',
      // Leeg: geen afwisselende achtergronden meer, de sjablonen volgen de variabelen van de stijl.
      'sectionBg1' => '',
      'sectionBg2' => '',
      'darkMode' => !empty($s['dark']),
      'groundsLight' => 1,
    ];
  }

  /* ───────────────────────── CSS ───────────────────────── */

  public static function font_url(array $s): string {
    $fam = [];
    foreach (['heading', 'body'] as $k) {
      $f = $s['fonts'][$k];
      $fam[$f['family']] = array_unique(array_merge($fam[$f['family']] ?? [], array_map('intval', (array) $f['weights'])));
    }
    $q = [];
    foreach ($fam as $name => $w) {
      sort($w);
      $q[] = 'family=' . str_replace(' ', '+', $name) . ':wght@' . implode(';', $w);
    }
    return 'https://fonts.googleapis.com/css2?' . implode('&', $q) . '&display=swap';
  }

  /**
   * De CSS van een stijl. Begint met de @import van de letters (moet de eerste regel van de stylesheet zijn).
   * Achtergrondkleuren van secties: de grond; kaarten en bewuste banden komen uit de variabelen.
   */
  public static function css(array $s, $guide = null): string {
    $c = $s['colours'];
    $f = $s['fonts'];
    // Wat de gebruiker na het kiezen nog aanpaste (kleuren, letters) gaat voor op de stijl.
    if (is_array($guide)) {
      $map = ['Primary' => 'accent', 'Secondary' => 'dark', 'Dark' => 'ink', 'Light' => 'surface', 'Neutral' => 'surface2'];
      foreach ((array) ($guide['colours'] ?? []) as $col) {
        $n = is_array($col) ? (string) ($col['name'] ?? '') : '';
        $hex = is_array($col) ? (string) ($col['hex'] ?? '') : '';
        if (isset($map[$n]) && preg_match('/^#[0-9a-f]{6}$/i', $hex)) $c[$map[$n]] = $hex;
      }
      $c['accentInk'] = self::contrast('#ffffff', $c['accent']) >= 4.5 ? '#FFFFFF' : $c['ink'];
      foreach (['heading' => 'headingFont', 'body' => 'bodyFont'] as $k => $gk) {
        $fam = trim((string) ($guide[$gk] ?? ''));
        if ($fam !== '' && preg_match('/^[A-Za-z0-9 ]{2,40}$/', $fam)) $f[$k]['family'] = $fam;
      }
      $s['fonts'] = $f;
    }
    $t = $s['type'];
    $sp = $s['space'];
    $r = $s['radius'];
    $px = fn($v) => (int) $v . 'px';
    $link = self::contrast($c['accent'], $c['base']) >= 4.5 ? $c['accent'] : $c['ink'];
    $hf = "'" . $f['heading']['family'] . "', ui-sans-serif, system-ui, sans-serif";
    $bf = "'" . $f['body']['family'] . "', ui-sans-serif, system-ui, sans-serif";
    if (preg_match('/serif|garamond|cormorant|fraunces/i', $f['heading']['family']) && !preg_match('/sans/i', $f['heading']['family'])) {
      $hf = "'" . $f['heading']['family'] . "', ui-serif, Georgia, serif";
    }

    $v = [
      // Archer-tokens
      '--a-base' => $c['base'], '--a-surface' => $c['surface'], '--a-surface2' => $c['surface2'],
      '--a-ink' => $c['ink'], '--a-muted' => $c['muted'], '--a-accent' => $c['accent'], '--a-accent-ink' => $c['accentInk'],
      '--a-line' => $c['line'], '--a-dark' => $c['dark'], '--a-dark-ink' => $c['darkInk'], '--a-link' => $link,
      '--a-display' => self::fluid($t['display']), '--a-h2' => self::fluid($t['h2']), '--a-h3' => self::fluid($t['h3']),
      '--a-lead' => self::fluid($t['lead']), '--a-body' => self::fluid($t['body']), '--a-small' => self::fluid($t['small']),
      '--a-font-heading' => $hf, '--a-font-body' => $bf,
      '--a-radius-button' => $px($r['button']), '--a-radius-card' => $px($r['card']), '--a-radius-image' => $px($r['image']), '--a-radius-field' => $px($r['field']),
      '--archer-radius' => $px($r['button']),
      // Brixies: ruimte en breedte
      '--brxw-section-space-vertical' => self::fluid($sp['section']),
      '--brxw-section-space-horizontal' => self::fluid([20, 40]),
      '--brxw-container-gap' => self::fluid($sp['heading']),
      '--brxw-content-gap' => $px($sp['content']),
      '--brxw-grid-gap' => self::fluid($sp['grid']),
      '--brxw-container-width' => $px($sp['container']),
      // Brixies: letters
      '--brxw-text-5xl' => 'var(--a-display)', '--brxw-text-4xl' => 'var(--a-display)', '--brxw-text-3xl' => 'var(--a-h2)',
      // De sjablonen gebruiken 2xl voor de meeste sectietitels en xl voor titels in banden: mee met de stijl.
      '--brxw-text-2xl' => self::fluid([(int) round($t['h2'][0] * 0.9), (int) round($t['h2'][1] * 0.88)]),
      '--brxw-text-xl' => self::fluid([(int) $t['h3'][1], (int) round($t['h2'][1] * 0.68)]), '--brxw-text-l' => 'var(--a-h3)',
      '--brxw-text-m' => 'var(--a-body)', '--brxw-text-s' => 'var(--a-small)', '--brxw-text-xs' => $px($t['small'][0]),
      '--brxw-line-height-heading' => (string) $f['heading']['lineHeight'], '--brxw-line-height-body' => (string) $f['body']['lineHeight'],
      '--brxw-card-title-size' => 'var(--a-h3)',
      // Brixies: hoeken en schaduwen
      '--brxw-radius-xs' => $px(min($r['card'], 6)), '--brxw-radius-s' => $px(min($r['card'], 10)), '--brxw-radius-m' => $px($r['card']),
      '--brxw-radius-l' => $px($r['card']), '--brxw-radius-xl' => $px($r['card']),
      '--brxw-shadow-xs' => 'none', '--brxw-shadow-s' => 'none', '--brxw-shadow-m' => 'none', '--brxw-shadow-l' => 'none', '--brxw-shadow-xl' => 'none',
      // Brixies: kleuren (wit = grond, de grijzen = getint van grond naar inkt)
      '--brxw-color-white' => $c['base'],
      '--brxw-color-neutral-25' => $c['surface'],
      '--brxw-color-neutral-50' => $c['line'],
      '--brxw-color-primary' => $c['accent'],
      '--brxw-color-secondary' => $c['dark'],
      '--brxw-color-overlay' => self::mix($c['dark'], 'transparent', 55),
      '--light' => $c['surface'], '--dark' => $c['ink'], '--primary' => $c['accent'], '--secondary' => $c['dark'],
      '--bricks-color-primary' => $c['accent'], '--bricks-color-light' => $c['base'],
    ];
    foreach ([100 => 18, 200 => 30, 300 => 42, 400 => 54, 500 => 64, 600 => 74, 700 => 84, 800 => 90, 900 => 95, 950 => 100] as $step => $pct) {
      $v['--brxw-color-neutral-' . $step] = $pct >= 100 ? $c['ink'] : self::mix($c['ink'], $c['base'], $pct);
    }
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90] as $pct) {
      $v['--brxw-color-neutral-trans-' . $pct] = self::mix($c['ink'], 'transparent', $pct);
      $v['--brxw-color-white-trans-' . $pct] = self::mix($c['base'], 'transparent', $pct);
      $v['--brxw-color-primary-trans-' . $pct] = self::mix($c['accent'], 'transparent', $pct);
      $v['--primary-' . $pct] = self::mix($c['accent'], 'transparent', $pct);
      $v['--dark-' . $pct] = self::mix($c['ink'], 'transparent', $pct);
    }
    $v['--dark-5'] = self::mix($c['ink'], 'transparent', 5);

    $root = '';
    foreach ($v as $k => $val) $root .= $k . ':' . $val . ';';

    $notChrome = ':not(:is(.archer-header,.archer-footer) *)';
    $btn = 'html body :is(.brxe-button.bricks-button.bricks-button,.brxe-form.brxe-form.brxe-form button[type=submit].bricks-button.bricks-button)';
    $b = $s['button'];
    $card = (string) ($s['card'] ?? 'flat');
    $cardSel = 'html body :is([class*="__card"]:not([class*="__card-"]),.brxe-block[class*="card"]:not([class*="card-"]))';

    $css = "@import url('" . self::font_url($s) . "');"
      . ':root{' . $root . '}'
      . 'html body{background-color:var(--a-base);color:var(--a-ink);font-family:var(--a-font-body);font-size:var(--a-body);line-height:' . $f['body']['lineHeight'] . ';-webkit-font-smoothing:antialiased;}'
      // Letters
      . 'html body :is(h1,h2,h3,h4,h5,h6,.brxe-heading){font-family:var(--a-font-heading);font-weight:' . (int) $f['heading']['weight'] . ';letter-spacing:' . $f['heading']['tracking'] . ';line-height:' . $f['heading']['lineHeight'] . ';text-transform:' . $f['heading']['case'] . ';}'
      . 'html body :is(h1,h1.brxe-heading)' . $notChrome . ',html body .brxe-section[class*="hero-"] :is(h1,h2).brxe-heading{font-size:var(--a-display) !important;}'
      . 'html body :is(h2.brxe-heading,h2)' . $notChrome . ':not(.brxe-section[class*="hero-"] *){font-size:var(--a-h2);line-height:1.08;}'
      . 'html body :is(h3.brxe-heading,h3)' . $notChrome . '{font-size:var(--a-h3);line-height:1.2;letter-spacing:' . self::half($f['heading']['tracking']) . ';}'
      . 'html body :is(p,li,.brxe-text,.brxe-text-basic,.brxe-rich-text){font-family:var(--a-font-body);}'
      // Secties zonder eigen achtergrond staan op de grond (in het voorbeeld is body doorzichtig).
      . 'html body .brxe-section{background-color:var(--a-base);}'
      . 'html body .brxe-section[class*="hero-"] :is(p,.brxe-text-basic,.brxe-text){font-size:var(--a-lead);}'
      . 'html body :is(.brxe-text-basic,.brxe-text p,p){max-width:min(100%,68ch);}'
      // Sjablonen met een vaste breedte (540px) mogen op een smal scherm niet buiten beeld lopen.
      . 'html body .brxe-section :is(.brxe-heading,.brxe-button){max-width:100%;}'
      // Tellers: bij publiceren wordt hun kleur als vaste lichte tint ingebakken; volg de tekstkleur van het blok.
      . 'html body .brxe-counter :is(.count,.prefix,.suffix){color:inherit !important;font-family:var(--a-font-heading);letter-spacing:' . $f['heading']['tracking'] . ';}'
      . 'html body a:not(.bricks-button):not(.brxe-button):not(.brxe-logo)' . $notChrome . '{color:var(--a-link);}'
      // Secties: de grond komt uit de variabelen (wit en --light) en uit sectionBg1/sectionBg2 (geen zebra meer).
      // Knoppen
      . $btn . '{border-radius:var(--a-radius-button);font-family:var(--a-font-body);font-size:' . (int) $b['size'] . 'px;font-weight:' . (int) $b['weight'] . ';letter-spacing:' . $b['tracking'] . ';text-transform:' . $b['case'] . ';padding:1em 1.6em;line-height:1.1;}'
      . 'html body .brxe-button.bricks-button:not(.outline):not([class*="outline"]):not([class*="link"])' . $notChrome . '{background-color:var(--a-accent);color:var(--a-accent-ink);border-color:var(--a-accent);}'
      . 'html body .brxe-button.bricks-button:is(.outline,[class*="outline"]){background-color:transparent;color:var(--a-ink);border:1px solid currentColor;}'
      // Twee knoppen naast elkaar: de tweede is de rustige (omlijnde) variant.
      . 'html body .brxe-button.bricks-button ~ .brxe-button.bricks-button:not(.archer-header *):not(.archer-footer *){background-color:transparent !important;color:var(--a-ink) !important;border:1px solid var(--a-ink) !important;}'
      // Hero: minder ruimte boven (de header staat er al), de rest van de sectie volgt het ritme.
      . 'html body .brxe-section[class*="hero-"]:not(.archer-cover){padding-top:calc(var(--brxw-section-space-vertical) * .55) !important;}'
      // Donkere stijl: de grijze transparanten zijn daar licht, dus een foto-overlay zou de foto wit waas geven.
      . (!empty($s['dark']) ? 'html body [class*="__overlay"]:not(.brxe-button){background-color:' . self::mix($c['dark'], 'transparent', 55) . ' !important;}' : '')
      . '@layer bricks{html body .bricks-button.circle.circle{border-radius:var(--a-radius-button) !important;}}'
      // Velden
      . 'html body .brxe-form.brxe-form :is(input:not([type=checkbox]):not([type=radio]),select,textarea){border-radius:var(--a-radius-field);border:1px solid var(--a-line);background-color:var(--a-base);color:var(--a-ink);}'
      // Beelden
      . 'html body .brxe-image:not(.archer-cover *):not(.brxe-logo *),html body .brxe-image:not(.archer-cover *) img,html body figure:not(.archer-cover *) img{border-radius:var(--a-radius-image);}'
      . 'html body .brxe-image img{object-fit:cover;}'
      // Kaarten
      . ($card === 'flat' ? $cardSel . '{background-color:var(--a-surface);border:0;border-radius:var(--a-radius-card);}' : '')
      . ($card === 'hairline' ? $cardSel . '{background-color:transparent;border:1px solid var(--a-line);border-radius:var(--a-radius-card);}' : '')
      . ($card === 'none' ? $cardSel . '{background-color:transparent;border:0;border-radius:0;}' : '')
      // Header: altijd op de grond met donkere tekst. Bij publiceren wordt --light als vaste kleur ingebakken,
      // dus de variabele alleen volstaat niet. Het uitklapmenu (offcanvas) blijft zoals het sjabloon het wil.
      . 'html body .archer-header{background-color:var(--a-base) !important;--light:var(--a-base);--brxw-color-neutral-25:var(--a-base);}'
      . 'html body .archer-header > :is(.brxe-container,.brxe-block,.brxe-div){background-color:transparent !important;}'
      . 'html body .archer-header :is(.brxe-nav-menu > nav > ul > li > a,.brxe-nav-menu .brx-submenu-toggle > *,.brxe-logo,.brxe-text-basic,.bricks-mobile-menu-toggle){color:var(--a-ink) !important;}'
      // Header 1 maakt van het laatste menu-item een knop (achtergrond --dark, tekst --light): als stijlknop tonen.
      . 'html body .archer-header .header-1__menu .bricks-nav-menu > .menu-item:last-child > a{background-color:var(--a-accent) !important;color:var(--a-accent-ink) !important;border-radius:var(--a-radius-button) !important;padding:.75em 1.3em !important;}'
      // Smal scherm: de naam krijgt de ruimte (geen vier regels), de knop wordt compacter.
      . '@media (max-width:767px){html body .archer-header .brxe-logo{flex:1 1 auto !important;min-width:0;font-size:17px !important;line-height:1.15 !important;}'
      . 'html body .archer-header .brxe-button.bricks-button{padding:10px 16px !important;font-size:14px !important;white-space:nowrap;}}'
      // Footer: donker, zoals de stijl het wil
      . 'html body .archer-footer,html body .archer-footer :is(.brxe-container,.brxe-block){background-color:var(--a-footer-bg) !important;}'
      . 'html body .archer-footer :is(h1,h2,h3,h4,h5,h6,.brxe-heading,p,li,a,.brxe-text,.brxe-text-basic,.brxe-nav-menu a,.brxe-logo){color:var(--a-footer-ink) !important;}'
      . 'html body .archer-footer .archer-credit{opacity:.72;}'
      . ':root{--a-footer-bg:' . (($s['bands']['footer'] ?? 'ink') === 'field' ? 'var(--a-dark)' : (($s['bands']['footer'] ?? 'ink') === 'ground' ? 'var(--a-surface)' : 'var(--a-dark)')) . ';--a-footer-ink:' . (($s['bands']['footer'] ?? 'ink') === 'ground' ? 'var(--a-ink)' : 'var(--a-dark-ink)') . ';}';
    return $css;
  }

  private static function half(string $tracking): string {
    $v = (float) $tracking;
    return self::n($v / 2) . 'em';
  }

  private static function lum(string $hex): float {
    $h = ltrim($hex, '#');
    $f = function ($c) { $c /= 255; return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * $f(hexdec(substr($h, 0, 2))) + 0.7152 * $f(hexdec(substr($h, 2, 2))) + 0.0722 * $f(hexdec(substr($h, 4, 2)));
  }

  public static function contrast(string $a, string $b): float {
    $la = self::lum($a);
    $lb = self::lum($b);
    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
  }

  /* ───────────────────────── Keuze ───────────────────────── */

  /** Alle stijlen, de stijlen die bij de sector passen eerst. */
  public static function for_project(int $project_id): array {
    $brief = (string) get_post_meta($project_id, 'aisb_project_brief', true) . ' ' . get_the_title($project_id);
    $sector = class_exists('AISB_Archer_Taste') ? AISB_Archer_Taste::sector_for($brief) : 'other';
    $out = [];
    foreach (self::library() as $i => $s) {
      $fit = in_array($sector, (array) ($s['sectors'] ?? []), true);
      $out[] = [
        'id' => $s['id'],
        'name' => $s['name'],
        'pitch' => $s['pitch'],
        'fit' => $fit,
        'order' => ($fit ? 0 : 100) + $i,
        'colours' => $s['colours'],
        'headingFont' => $s['fonts']['heading']['family'],
        'headingWeight' => (int) $s['fonts']['heading']['weight'],
        'headingTracking' => $s['fonts']['heading']['tracking'],
        'bodyFont' => $s['fonts']['body']['family'],
        'radius' => $s['radius'],
        'bands' => $s['bands'],
        'fontUrl' => self::font_url($s),
        'css' => self::css($s),
        'guide' => self::guide_patch($s),
      ];
    }
    usort($out, fn($a, $b) => $a['order'] <=> $b['order']);
    return ['sector' => $sector, 'styles' => $out];
  }

  public function ajax_styles(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $ok = false;
    foreach (['aisb_sg_nonce', 'aisb_wf_nonce', 'aisb_nonce_action', 'aisb_style_guide_nonce'] as $action) {
      if ($nonce && wp_verify_nonce($nonce, $action)) $ok = true;
    }
    if (!$ok) wp_send_json_error(['message' => 'Ongeldige sessie, herlaad de pagina'], 403);
    $pid = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
    $p = $pid ? get_post($pid) : null;
    if (!$p || $p->post_type !== 'aisb_project') wp_send_json_error(['message' => 'Project niet gevonden'], 404);
    if ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options')) wp_send_json_error(['message' => 'Geen toegang'], 403);
    wp_send_json_success(self::for_project($pid));
  }
}
