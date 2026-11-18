<?php
if (!defined('ABSPATH')) exit;

/**
 * Sectoradvies uit UI/UX Pro Max (github.com/nextlevelbuilder/ui-ux-pro-max-skill, MIT) bovenop de Archer-stijlen:
 * - de stap Stijl sorteert de 8 stijlen op wat UI/UX Pro Max voor de sector aanraadt en toont waarom;
 * - de prompts voor sitemap en secties krijgen de opbouw, de must-haves en wat te vermijden;
 * - de controle voor publiceren (AISB_Archer_Checks) gebruikt de must-haves.
 * De kennis staat in sector/sectors.php.
 */
class AISB_Archer_Sector {

  /** Archer-stijl → stijlen in de namen van UI/UX Pro Max (styles.csv) die erop lijken, sterkste eerst. */
  const STYLE_TAGS = [
    'atelier' => ['Editorial Grid / Magazine', 'Exaggerated Minimalism', 'Minimalism & Swiss Style', 'Minimalist Monochrome', 'Storytelling-Driven'],
    'nordic' => ['Minimalism & Swiss Style', 'Soft UI Evolution', 'Accessible & Ethical', 'Flat Design', 'Trust & Authority'],
    'studio' => ['Vibrant & Block-based', 'Brutalism', 'Motion-Driven', 'Kinetic Typography', 'Bold Typography (Mobile Poster)'],
    'ambacht' => ['Organic Biophilic', 'Nature Distilled', 'Flat Design', 'Accessible & Ethical', 'Vintage Analog / Retro Film'],
    'helder' => ['Soft UI Evolution', 'Accessible & Ethical', 'Inclusive Design', 'Flat Design', 'Claymorphism'],
    'noir' => ['Dark Mode (OLED)', 'Exaggerated Minimalism', 'Liquid Glass', 'Glassmorphism', 'Editorial Grid / Magazine'],
    'raster' => ['Swiss Modernism 2.0', 'Minimalism & Swiss Style', 'Brutalism', 'Bauhaus (包豪斯)', 'Minimalist Monochrome'],
    'fris' => ['Flat Design', 'Vibrant & Block-based', 'Accessible & Ethical', 'Minimal & Direct', 'Micro-interactions'],
  ];

  private static $data = null;

  public static function all(): array {
    if (self::$data === null) self::$data = (array) include __DIR__ . '/sector/sectors.php';
    return self::$data;
  }

  /** Sleutel van de best passende sector, of '' als niets duidelijk past. */
  public static function detect(string $text): string {
    $t = mb_strtolower(wp_strip_all_tags($text));
    $best = '';
    $score = 0;
    foreach (self::all() as $key => $s) {
      $n = (int) @preg_match_all('/(?<![\p{L}])(?:' . $s['match'] . ')/u', $t);
      if ($n > $score) { $best = $key; $score = $n; }
    }
    return $best;
  }

  public static function for_project(int $pid): string {
    $cached = (string) get_post_meta($pid, 'archer_sector', true);
    $brief = (string) get_post_meta($pid, 'aisb_project_brief', true);
    $hash = md5($brief . '|' . get_the_title($pid));
    if ($cached !== '' && strpos($cached, $hash . ':') === 0) return (string) substr($cached, strlen($hash) + 1);
    $key = self::detect(get_the_title($pid) . "\n" . $brief);
    update_post_meta($pid, 'archer_sector', $hash . ':' . $key);
    return $key;
  }

  public static function get(string $key): ?array {
    $all = self::all();
    return isset($all[$key]) ? $all[$key] + ['key' => $key] : null;
  }

  /** Score van een Archer-stijl voor een sector: stijlverwantschap, kleurtoon, donker/licht en de eigen sectorlijst. */
  /**
   * De stijlnamen van UI/UX Pro Max vertaald naar het karakter van een hallmark-thema:
   * [genre, houding, donker?]. UUPM zegt per sector welke stijlen passen, hallmark beschrijft elk
   * thema met een genre (editorial, modern-minimal, playful, atmospheric) en een houding
   * (restrained, committed, full-palette, drenched). Zo blijft het sectoradvies werken zonder dat
   * er voor elke sector een lijst met vijftig thema's bijgehouden moet worden.
   */
  const UUPM_TRAITS = [
    'Minimalism & Swiss Style'    => ['modern-minimal', 'restrained', false],
    'Accessible & Ethical'        => ['modern-minimal', 'restrained', false],
    'Inclusive Design'            => ['modern-minimal', 'restrained', false],
    'Exaggerated Minimalism'      => ['modern-minimal', 'restrained', null],
    'Soft UI Evolution'           => ['modern-minimal', 'committed', false],
    'Flat Design'                 => ['modern-minimal', 'committed', false],
    'Bento Box Grid'              => ['modern-minimal', 'committed', null],
    'Swiss Modernism 2.0'         => ['editorial', 'restrained', null],
    'Editorial Grid / Magazine'   => ['editorial', 'restrained', null],
    'Nature Distilled'            => ['editorial', 'restrained', false],
    'Organic Biophilic'           => ['editorial', 'committed', false],
    'Vintage Analog / Retro Film' => ['editorial', 'committed', null],
    'Vibrant & Block-based'       => ['playful', 'full-palette', null],
    'Claymorphism'                => ['playful', 'committed', false],
    'Brutalism'                   => ['playful', 'full-palette', null],
    'Motion-Driven'               => ['atmospheric', 'committed', null],
    'Glassmorphism'               => ['atmospheric', 'committed', null],
    'Liquid Glass'                => ['atmospheric', 'drenched', null],
    '3D & Hyperrealism'           => ['atmospheric', 'drenched', null],
    'Dark Mode (OLED)'            => ['atmospheric', 'committed', true],
  ];

  public static function score(array $style, array $sector, string $archer_sector = ''): float {
    $score = 0.0;
    // Oude Archer-stijlen (eigen bibliotheek): de stijlnamen van UUPM stonden per id in STYLE_TAGS.
    $tags = self::STYLE_TAGS[$style['id']] ?? [];
    if ($tags) {
      foreach (array_values($sector['styles']) as $i => $want) {
        $pos = array_search($want, $tags, true);
        if ($pos === false) continue;
        $score += ($i < 2 ? 3.0 : 1.5) * (1 - 0.12 * $pos);
      }
    } elseif (!empty($style['genre'])) {
      // Hallmark-thema: het advies van UUPM via genre en houding.
      foreach (array_values($sector['styles']) as $i => $want) {
        $t = self::UUPM_TRAITS[$want] ?? null;
        if (!$t) continue;
        $w = $i < 2 ? 1.0 : 0.5;
        if ($t[0] === ($style['genre'] ?? '')) $score += 3.0 * $w;
        if ($t[1] === ($style['posture'] ?? '')) $score += 1.5 * $w;
        if ($t[2] !== null && $t[2] === !empty($style['dark'])) $score += 1.0 * $w;
      }
    }
    // De trefwoorden van het thema ("bakery, bread, pastry") tegen de sector: raak is een sterk signaal,
    // maar hallmark schrijft ze in het Engels en de sectorpatronen zijn Nederlands, dus het is een bonus.
    $kw = array_filter((array) ($style['keywords'] ?? []), 'is_string');
    if ($kw && !empty($sector['match'])) {
      $hits = 0;
      foreach ($kw as $k) {
        if (@preg_match('/(?:' . $sector['match'] . ')/iu', mb_strtolower($k))) $hits++;
        if ($hits >= 4) break;
      }
      $score += min(4, $hits) * 2.0;
    }
    // Kleurtoon: accent van de stijl tegenover de primaire kleur en het accent van de sector.
    $acc = (string) ($style['colours']['accent'] ?? '');
    $best = 0.0;
    foreach ((array) $sector['palette'] as $hex) $best = max($best, self::hue_match($acc, (string) $hex));
    $score += 1.5 * $best;
    if (!empty($style['dark']) && !empty($sector['avoid_dark'])) $score -= 5;
    // De sectorlijst van het thema zelf (uit de trefwoorden berekend) is het directste signaal.
    if ($archer_sector !== '' && in_array($archer_sector, (array) ($style['sectors'] ?? []), true)) $score += 4;
    return round($score, 2);
  }

  /** 0..1: hoe dicht twee kleuren bij elkaar liggen in toon (en of ze allebei neutraal zijn). */
  private static function hue_match(string $a, string $b): float {
    [$ha, $sa] = self::hs($a);
    [$hb, $sb] = self::hs($b);
    if ($sa < 0.15 || $sb < 0.15) return ($sa < 0.15 && $sb < 0.15) ? 1.0 : 0.0;
    $d = abs($ha - $hb);
    $d = min($d, 360 - $d);
    return $d <= 25 ? 1.0 : ($d <= 60 ? 0.5 : 0.0);
  }

  private static function hs(string $hex): array {
    $h = ltrim($hex, '#');
    if (!preg_match('/^[0-9a-f]{6}$/i', $h)) return [0, 0];
    $r = hexdec(substr($h, 0, 2)) / 255; $g = hexdec(substr($h, 2, 2)) / 255; $b = hexdec(substr($h, 4, 2)) / 255;
    $max = max($r, $g, $b); $min = min($r, $g, $b); $d = $max - $min;
    $l = ($max + $min) / 2;
    $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
    if ($d == 0) $hue = 0;
    elseif ($max === $r) $hue = 60 * fmod((($g - $b) / $d), 6);
    elseif ($max === $g) $hue = 60 * ((($b - $r) / $d) + 2);
    else $hue = 60 * ((($r - $g) / $d) + 4);
    return [fmod($hue + 360, 360), $s];
  }

  /** Advies voor de stap Stijl (Nederlands). */
  public static function advice(array $sector): array {
    return ['key' => $sector['key'], 'label' => $sector['label'], 'source' => $sector['uupm']] + $sector['advice'];
  }

  /** Regels voor de AI (sitemap en secties), in het Engels zoals de rest van die prompts. */
  public static function prompt_rules(int $pid): string {
    return self::prompt_rules_for(self::for_project($pid));
  }

  public static function prompt_rules_for(string $key): string {
    $s = self::get($key);
    if (!$s) return '';
    $a = $s['advice'];
    return "SECTOR GUIDANCE (UI/UX Pro Max, product type \"" . $s['uupm'] . "\"; write the site itself in the site's language):\n"
      . '- Recommended page flow: ' . $a['opbouw'] . "\n"
      . '- Suggested sections (adapt, do not copy blindly): ' . implode(' > ', $s['sections']) . "\n"
      . '- Must have: ' . implode('; ', $a['moet']) . "\n"
      . '- Avoid: ' . implode('; ', $a['vermijd']) . "\n";
  }
}
