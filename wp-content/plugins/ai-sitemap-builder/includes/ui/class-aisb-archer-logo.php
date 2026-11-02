<?php
if (!defined('ABSPATH')) exit;

/**
 * Logo van een bestaande website overnemen (startscherm met een webadres).
 *
 * find(): zoekt het logo in de HTML (JSON-LD "logo", een <img> met "logo" in de header of in een
 * logo-link, als laatste het apple-touch-icon). store(): zet het als data-URI in de stijlgids van
 * het project (guide.logoUrl), net zoals een logo dat je zelf oplaadt in de stap Stijl.
 */
class AISB_Archer_Logo {

  const MAX_BYTES = 400 * 1024;

  public static function find(string $html, string $base): string {
    $cands = [];
    $abs = function (string $u) use ($base): string {
      $u = trim(html_entity_decode($u, ENT_QUOTES));
      if ($u === '' || strpos($u, 'data:') === 0) return '';
      if (strpos($u, '//') === 0) $u = 'https:' . $u;
      if (!preg_match('#^https?://#i', $u)) $u = WP_Http::make_absolute_url($u, $base);
      return wp_http_validate_url($u) ? $u : '';
    };
    // Gestructureerde data van de organisatie.
    if (preg_match_all('#"logo"\s*:\s*(?:\{[^{}]*?"(?:url|contentUrl)"\s*:\s*)?"([^"]+)"#i', $html, $m)) {
      foreach ($m[1] as $u) if (($a = $abs(stripslashes($u))) !== '') $cands[] = [$a, 6];
    }
    $header_end = stripos($html, '</header>');
    if ($header_end === false) $header_end = (int) (strlen($html) * 0.25);
    if (preg_match_all('#<img\b[^>]*>#i', $html, $imgs, PREG_OFFSET_CAPTURE)) {
      foreach ($imgs[0] as $hit) {
        [$tag, $pos] = $hit;
        $src = '';
        foreach (['data-src', 'data-lazy-src', 'src'] as $attr) {
          if (preg_match('#\s' . $attr . '\s*=\s*["\']([^"\']+)#i', $tag, $a) && strpos($a[1], 'data:') !== 0) { $src = $a[1]; break; }
        }
        if ($src === '' && preg_match('#\ssrcset\s*=\s*["\']([^\s"\',]+)#i', $tag, $a)) $src = $a[1];
        if ($src === '') continue;
        $score = 0;
        if (preg_match('/logo/i', $tag)) $score += 5;
        if ($pos < $header_end) $score += 2;
        $before = substr($html, max(0, $pos - 400), min(400, $pos));
        if (preg_match('#<a[^>]+(class|id)=["\'][^"\']*(logo|brand|site-title|navbar-brand)[^"\']*["\'][^>]*>\s*(<[^>]+>\s*)*$#i', $before)) $score += 4;
        if (preg_match('/(sponsor|partner|payment|footer|icon-|ico-|avatar|flag|client|klant|referen)/i', $tag)) $score -= 4;
        if ($pos > $header_end) $score -= 3; // logo's verder op de pagina zijn meestal klanten of partners
        if ($score >= 6 && ($a = $abs($src)) !== '') $cands[] = [$a, $score];
      }
    }
    // Inline svg in de link naar de startpagina (bv. "Go to homepage").
    $head = substr($html, 0, max($header_end, 1));
    if (preg_match_all('#<a\b([^>]*)>(.*?)</a>#is', $head, $links, PREG_SET_ORDER)) {
      foreach ($links as $l) {
        if (!preg_match('#(href=["\'](/|https?://[^"\'/]+/?)["\']|aria-label=["\'][^"\']*(home|logo|start)|(class|id)=["\'][^"\']*(logo|brand))#i', $l[1])) continue;
        if (preg_match('#<svg\b.*?</svg>#is', $l[2], $svg) && strlen($svg[0]) < self::MAX_BYTES) {
          $clean = self::clean_svg($svg[0]);
          if (strpos($clean, 'xmlns=') === false) $clean = preg_replace('#<svg\b#i', '<svg xmlns="http://www.w3.org/2000/svg"', $clean, 1);
          $cands[] = ['data:image/svg+xml;base64,' . base64_encode($clean), 8]; // de link naar home is een sterk signaal
          break;
        }
      }
    }
    if (preg_match('#<link[^>]+rel=["\']apple-touch-icon[^"\']*["\'][^>]*href=["\']([^"\']+)#i', $html, $m) && ($a = $abs($m[1])) !== '') $cands[] = [$a, 1];
    usort($cands, fn($x, $y) => $y[1] <=> $x[1]);
    return $cands ? $cands[0][0] : '';
  }

  /** Geen scripts, event handlers of javascript-links in een overgenomen svg. */
  public static function clean_svg(string $body): string {
    $body = preg_replace('#<(script|foreignObject)\b.*?</\1\s*>#is', '', $body);
    $body = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $body);
    return (string) preg_replace('#((?:xlink:)?href\s*=\s*["\'])\s*javascript:[^"\']*#i', '$1#', $body);
  }

  /** Download als data-URI (png, jpg, webp, gif of svg), of '' als dat niet lukt. */
  public static function data_uri(string $url): string {
    if ($url === '') return '';
    if (strpos($url, 'data:image/svg+xml;base64,') === 0) return strlen($url) < self::MAX_BYTES * 1.4 ? $url : '';
    $res = wp_safe_remote_get($url, ['timeout' => 12, 'redirection' => 3, 'limit_response_size' => self::MAX_BYTES + 1,
      'user-agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)']);
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 400) return '';
    $body = (string) wp_remote_retrieve_body($res);
    if ($body === '' || strlen($body) > self::MAX_BYTES) return '';
    $type = strtolower(trim(explode(';', (string) wp_remote_retrieve_header($res, 'content-type'))[0]));
    if ($type === '' || $type === 'application/octet-stream' || $type === 'text/plain') {
      $ext = strtolower(pathinfo((string) wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
      $type = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? '';
    }
    if ($type === 'image/svg+xml' || (stripos($body, '<svg') !== false && $type === '')) {
      $body = self::clean_svg($body);
      if (stripos($body, '<svg') === false) return '';
      $type = 'image/svg+xml';
    } elseif (!in_array($type, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || !@getimagesizefromstring($body)) {
      return '';
    }
    return 'data:' . $type . ';base64,' . base64_encode($body);
  }

  /** Logo in de stijlgids van het project zetten, als er nog geen is. */
  public static function store(int $project_id, string $logo_url): bool {
    if (!$project_id || $logo_url === '') return false;
    $guide = json_decode((string) get_post_meta($project_id, 'aisb_style_guide', true), true);
    if (!is_array($guide)) $guide = [];
    if (!empty($guide['logoUrl'])) return false;
    $data = self::data_uri($logo_url);
    if ($data === '') return false;
    $guide['logoUrl'] = $data;
    $guide['logoSource'] = strpos($logo_url, 'data:') === 0 ? 'inline-svg' : esc_url_raw($logo_url);
    update_post_meta($project_id, 'aisb_style_guide', wp_slash(wp_json_encode($guide, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    return true;
  }
}
