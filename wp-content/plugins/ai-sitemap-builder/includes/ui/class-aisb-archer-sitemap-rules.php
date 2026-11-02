<?php
if (!defined('ABSPATH')) exit;

/**
 * Archer-regels voor gegenereerde sitemaps en het overnemen van een bestaande website.
 *
 * Na elke AI-generatie (filter aisb_generated_sitemap):
 * - een bestaande website: elke pagina van die site komt in de nieuwe sitemap,
 *   met dezelfde ouder/kind-structuur;
 * - er is altijd een contactpagina;
 * - een overzichtspagina (diensten, producten, wagens, projecten…) heeft altijd
 *   minstens een subpagina;
 * - per pagina staat de header altijd eerst en de footer altijd laatst.
 *
 * Daarnaast leest crawl_site() de volledige paginalijst van een bestaande website
 * (sitemap.xml en interne links).
 */
class AISB_Archer_Sitemap_Rules {

  /** Hoe lang de paginalijst van een gelezen website klaarstaat voor de generatie. */
  private const SOURCE_TTL = 1800;

  /** Mappen met meer kinderen dan dit zijn een collectie (producten, blogberichten…). */
  private const COLLECTION_MIN_CHILDREN = 9;

  public function init(): void {
    add_filter('aisb_generated_sitemap', [$this, 'apply_rules'], 10, 2);
  }

  /* ───────────────────────── Overzichtspagina's ───────────────────────── */

  /**
   * Bekende overzichtspagina's: woord (zonder accenten, kleine letters) =>
   * [enkelvoud NL/EN, soort detailsectie].
   */
  public static function collections(): array {
    return [
      'diensten'      => ['Dienst', 'Feature Sections'],
      'services'      => ['Service', 'Feature Sections'],
      'onze-diensten' => ['Dienst', 'Feature Sections'],
      'behandelingen' => ['Behandeling', 'Feature Sections'],
      'treatments'    => ['Treatment', 'Feature Sections'],
      'producten'     => ['Product', 'Single Product Sections'],
      'products'      => ['Product', 'Single Product Sections'],
      'assortiment'   => ['Product', 'Single Product Sections'],
      'aanbod'        => ['Aanbod', 'Single Product Sections'],
      'collectie'     => ['Item', 'Single Product Sections'],
      'collection'    => ['Item', 'Single Product Sections'],
      'shop'          => ['Product', 'Single Product Sections'],
      'webshop'       => ['Product', 'Single Product Sections'],
      'wagens'        => ['Wagen', 'Single Product Sections'],
      'autos'         => ['Auto', 'Single Product Sections'],
      'auto-s'        => ['Auto', 'Single Product Sections'],
      'occasions'     => ['Occasion', 'Single Product Sections'],
      'voertuigen'    => ['Voertuig', 'Single Product Sections'],
      'cars'          => ['Car', 'Single Product Sections'],
      'vehicles'      => ['Vehicle', 'Single Product Sections'],
      'projecten'     => ['Project', 'Single Portfolio Sections'],
      'projects'      => ['Project', 'Single Portfolio Sections'],
      'realisaties'   => ['Realisatie', 'Single Portfolio Sections'],
      'cases'         => ['Case', 'Single Portfolio Sections'],
      'portfolio'     => ['Project', 'Single Portfolio Sections'],
      'referenties'   => ['Referentie', 'Single Portfolio Sections'],
      'vacatures'     => ['Vacature', 'Career Sections'],
      'jobs'          => ['Job', 'Career Sections'],
      'careers'       => ['Job', 'Career Sections'],
      'evenementen'   => ['Evenement', 'Single Event Sections'],
      'events'        => ['Event', 'Single Event Sections'],
      'agenda'        => ['Evenement', 'Single Event Sections'],
      'opleidingen'   => ['Opleiding', 'Feature Sections'],
      'cursussen'     => ['Cursus', 'Feature Sections'],
      'trainingen'    => ['Training', 'Feature Sections'],
      'workshops'     => ['Workshop', 'Feature Sections'],
      'courses'       => ['Course', 'Feature Sections'],
      'panden'        => ['Pand', 'Single Property Sections'],
      'woningen'      => ['Woning', 'Single Property Sections'],
      'te-koop'       => ['Woning', 'Single Property Sections'],
      'te-huur'       => ['Woning', 'Single Property Sections'],
      'properties'    => ['Property', 'Single Property Sections'],
      'blog'          => ['Blogbericht', 'Single Post Sections'],
      'nieuws'        => ['Nieuwsbericht', 'Single Post Sections'],
      'news'          => ['News article', 'Single Post Sections'],
      'artikels'      => ['Artikel', 'Single Post Sections'],
      'team'          => ['Teamlid', 'Single Team Sections'],
      'ons-team'      => ['Teamlid', 'Single Team Sections'],
      'merken'        => ['Merk', 'Content Sections'],
      'brands'        => ['Brand', 'Content Sections'],
    ];
  }

  public static function plain(string $s): string {
    $s = remove_accents(mb_strtolower(trim($s)));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
  }

  /** Geeft de collectie-info terug als deze pagina een overzichtspagina is. */
  public static function collection_for(array $page): ?array {
    $list = self::collections();
    foreach ([(string) ($page['slug'] ?? ''), (string) ($page['page_title'] ?? ''), (string) ($page['nav_label'] ?? '')] as $candidate) {
      $p = self::plain($candidate);
      if ($p === '') continue;
      if (isset($list[$p])) return $list[$p];
      // "onze-diensten", "diensten-en-tarieven": eerste herkend woord telt.
      foreach (explode('-', $p) as $word) {
        if (strlen($word) >= 4 && isset($list[$word])) return $list[$word];
      }
    }
    return null;
  }

  /* ───────────────────────── Regels na generatie ───────────────────────── */

  /**
   * @param array $data    De gegenereerde sitemap.
   * @param array $context ['prompt' => string, 'languages' => string[]]
   */
  public function apply_rules($data, $context = []) {
    if (!is_array($data) || !is_array($data['sitemap'] ?? null)) return $data;
    $lang = self::lang($context['languages'] ?? []);
    $prompt = (string) ($context['prompt'] ?? '');

    // 1. Bestaande website: alle pagina's overnemen.
    $source = get_transient(self::source_key());
    if (is_array($source) && !empty($source['url']) && !empty($source['pages'])
        && strpos($prompt, (string) $source['url']) !== false) {
      $data = $this->merge_source_pages($data, $source, $lang);
      delete_transient(self::source_key());
    }

    // 2. Altijd een contactpagina.
    $data = $this->ensure_contact($data, $lang);

    // 3. Overzichtspagina's krijgen altijd een subpagina.
    $data = $this->ensure_detail_pages($data, $lang);

    // 4. Header eerst, footer laatst.
    foreach ($data['sitemap'] as $i => $page) {
      $data['sitemap'][$i]['sections'] = self::order_sections((array) ($page['sections'] ?? []));
    }

    return $data;
  }

  public static function order_sections(array $sections): array {
    $head = []; $mid = []; $foot = [];
    foreach ($sections as $s) {
      if (!is_array($s)) continue;
      $type = (string) ($s['section_type'] ?? '');
      if ($type === 'Headers' || strpos($type, 'Megamenu') === 0) $head[] = $s;
      elseif ($type === 'Footers') $foot[] = $s;
      else $mid[] = $s;
    }
    return array_merge(array_slice($head, 0, 1), $mid, array_slice($foot, 0, 1));
  }

  private static function lang(array $languages): string {
    $l = strtolower((string) ($languages[0] ?? 'Dutch'));
    if (strpos($l, 'french') !== false) return 'fr';
    if (strpos($l, 'english') !== false) return 'en';
    if (strpos($l, 'german') !== false) return 'de';
    return 'nl';
  }

  private static function t(string $key, string $lang): string {
    $t = [
      'header'  => ['nl' => 'Navigatie', 'en' => 'Navigation', 'fr' => 'Navigation', 'de' => 'Navigation'],
      'footer'  => ['nl' => 'Footer', 'en' => 'Footer', 'fr' => 'Pied de page', 'de' => 'Fußzeile'],
      'hero'    => ['nl' => 'Hero', 'en' => 'Hero', 'fr' => 'Hero', 'de' => 'Hero'],
      'contact' => ['nl' => 'Contact', 'en' => 'Contact', 'fr' => 'Contact', 'de' => 'Kontakt'],
      'form'    => ['nl' => 'Contactformulier', 'en' => 'Contact form', 'fr' => 'Formulaire de contact', 'de' => 'Kontaktformular'],
      'details' => ['nl' => 'Contactgegevens en openingsuren', 'en' => 'Contact details and opening hours', 'fr' => 'Coordonnées et horaires', 'de' => 'Kontaktdaten und Öffnungszeiten'],
      'map'     => ['nl' => 'Locatie', 'en' => 'Location', 'fr' => 'Localisation', 'de' => 'Standort'],
      'cta'     => ['nl' => 'Oproep tot actie', 'en' => 'Call to action', 'fr' => 'Appel à l\'action', 'de' => 'Handlungsaufforderung'],
      'content' => ['nl' => 'Inhoud', 'en' => 'Content', 'fr' => 'Contenu', 'de' => 'Inhalt'],
      'detail'  => ['nl' => 'detailpagina', 'en' => 'detail page', 'fr' => 'page détail', 'de' => 'Detailseite'],
      'related' => ['nl' => 'Gerelateerd', 'en' => 'Related', 'fr' => 'Similaires', 'de' => 'Ähnliche'],
      'specs'   => ['nl' => 'Kenmerken', 'en' => 'Key features', 'fr' => 'Caractéristiques', 'de' => 'Merkmale'],
      'copied'  => ['nl' => 'Overgenomen van de bestaande website', 'en' => 'Taken over from the existing website', 'fr' => 'Repris du site existant', 'de' => 'Von der bestehenden Website übernommen'],
    ];
    return $t[$key][$lang] ?? $t[$key]['nl'];
  }

  private static function sec(string $name, string $type, string $purpose, array $kc = []): array {
    return ['section_name' => $name, 'section_type' => $type, 'purpose' => $purpose, 'key_content' => $kc];
  }

  private static function seo(string $title): array {
    return ['primary_keyword' => mb_strtolower($title), 'secondary_keywords' => [], 'meta_title' => $title, 'meta_description' => ''];
  }

  private static function slugs(array $data): array {
    $out = [];
    foreach ($data['sitemap'] as $p) $out[(string) ($p['slug'] ?? '')] = true;
    return $out;
  }

  private static function unique_slug(string $slug, array $taken): string {
    $slug = sanitize_title($slug) ?: 'pagina';
    $base = $slug; $n = 2;
    while (isset($taken[$slug])) $slug = $base . '-' . $n++;
    return $slug;
  }

  private function ensure_contact(array $data, string $lang): array {
    foreach ($data['sitemap'] as $p) {
      $type = strtolower((string) ($p['page_type'] ?? ''));
      $plain = self::plain((string) ($p['slug'] ?? '') . ' ' . (string) ($p['page_title'] ?? ''));
      if ($type === 'contact' || preg_match('/(^|-)(contact|kontakt|contacteer|contacteren|contactez)/', $plain)) return $data;
    }
    $title = self::t('contact', $lang);
    $data['sitemap'][] = [
      'page_title'  => $title,
      'nav_label'   => $title,
      'slug'        => self::unique_slug(self::plain($title), self::slugs($data)),
      'page_type'   => 'Contact',
      'priority'    => 'Core',
      'parent_slug' => 'home',
      'page_purpose' => '',
      'sections'    => [
        self::sec(self::t('header', $lang), 'Headers', '', []),
        self::sec(self::t('hero', $lang), 'Hero Sections', '', []),
        self::sec(self::t('form', $lang), 'Contact Sections', '', []),
        self::sec(self::t('details', $lang), 'Contact Sections', '', []),
        self::sec(self::t('map', $lang), 'Contact Sections', '', []),
        self::sec(self::t('footer', $lang), 'Footers', '', []),
      ],
      'seo' => self::seo($title),
      'archer_added' => 'contact',
    ];
    return $data;
  }

  private function ensure_detail_pages(array $data, string $lang): array {
    $by_slug = [];
    $children = [];
    foreach ($data['sitemap'] as $p) {
      $slug = (string) ($p['slug'] ?? '');
      $by_slug[$slug] = $p;
      $parent = (string) ($p['parent_slug'] ?? '');
      if ($parent !== '') $children[$parent][] = $slug;
    }
    $add = [];
    foreach ($data['sitemap'] as $p) {
      $slug = (string) ($p['slug'] ?? '');
      if ($slug === 'home') continue;
      $info = self::collection_for($p);
      if (!$info && !empty($p['archer_collection'])) {
        // Een map met veel pagina's op de bestaande site (bv. /occasions/…).
        $info = [preg_replace('/s$/', '', (string) ($p['nav_label'] ?? $p['page_title'] ?? 'Item')) ?: 'Item', 'Single Product Sections'];
      }
      if (!$info) continue;
      // Een kind van een overzichtspagina is zelf geen overzichtspagina.
      $parent = (string) ($p['parent_slug'] ?? '');
      if ($parent !== '' && $parent !== 'home' && isset($by_slug[$parent]) && self::collection_for($by_slug[$parent])) continue;
      if (!empty($children[$slug])) continue;

      [$singular, $type] = $info;
      $title = $singular . ' (' . self::t('detail', $lang) . ')';
      $taken = self::slugs($data);
      foreach ($add as $a) $taken[$a['slug']] = true;
      $sections = [self::sec(self::t('header', $lang), 'Headers', '')];
      if ($type === 'Single Post Sections') {
        $sections[] = self::sec($singular, 'Single Post Hero', '');
        $sections[] = self::sec(self::t('content', $lang), 'Single Post Sections', '');
      } else {
        $sections[] = self::sec($singular, $type === 'Feature Sections' || $type === 'Career Sections' ? 'Hero Sections' : $type, '');
        $sections[] = self::sec(self::t('content', $lang), 'Content Sections', '');
        $sections[] = self::sec(self::t('specs', $lang), 'Feature Sections', '');
      }
      $sections[] = self::sec(self::t('related', $lang), $type === 'Single Post Sections' ? 'Blog Sections' : 'Feature Sections', '');
      $sections[] = self::sec(self::t('cta', $lang), 'CTA Sections', '');
      $sections[] = self::sec(self::t('footer', $lang), 'Footers', '');

      $add[] = [
        'page_title'  => $title,
        'nav_label'   => $singular,
        'slug'        => self::unique_slug(self::plain($singular) . '-detail', $taken),
        'page_type'   => 'Other',
        'priority'    => 'Support',
        'parent_slug' => $slug,
        'page_purpose' => '',
        'sections'    => $sections,
        'seo'         => self::seo($singular),
        'archer_added' => 'detail',
      ];
    }
    foreach ($add as $a) $data['sitemap'][] = $a;
    return $data;
  }

  /** Voegt pagina's van de bestaande site toe die de AI heeft weggelaten. */
  private function merge_source_pages(array $data, array $source, string $lang): array {
    $present = [];
    foreach ($data['sitemap'] as $p) {
      $present['s:' . self::plain((string) ($p['slug'] ?? ''))] = (string) ($p['slug'] ?? '');
      $present['t:' . self::plain((string) ($p['page_title'] ?? ''))] = (string) ($p['slug'] ?? '');
      $present['t:' . self::plain((string) ($p['nav_label'] ?? ''))] = (string) ($p['slug'] ?? '');
    }
    // Bronslug => slug in de nieuwe sitemap.
    $map = ['home' => 'home'];
    $missing = [];
    foreach ($source['pages'] as $sp) {
      $sslug = (string) $sp['slug'];
      if ($sslug === 'home') continue;
      $hit = $present['s:' . self::plain($sslug)] ?? ($present['t:' . self::plain((string) $sp['title'])] ?? null);
      if ($hit) $map[$sslug] = $hit; else $missing[] = $sp;
    }
    $taken = self::slugs($data);
    foreach ($missing as $sp) {
      $slug = self::unique_slug((string) $sp['slug'], $taken);
      $taken[$slug] = true;
      $map[(string) $sp['slug']] = $slug;
    }
    foreach ($missing as $sp) {
      $title = (string) $sp['title'];
      $parent = $map[(string) ($sp['parent_slug'] ?? 'home')] ?? 'home';
      $note = self::t('copied', $lang) . ': ' . (string) ($sp['url'] ?? '');
      $data['sitemap'][] = [
        'page_title'  => $title,
        'nav_label'   => mb_substr($title, 0, 30),
        'slug'        => $map[(string) $sp['slug']],
        'page_type'   => 'Other',
        'priority'    => 'Support',
        'parent_slug' => $parent,
        'page_purpose' => $note,
        'sections'    => [
          self::sec(self::t('header', $lang), 'Headers', ''),
          self::sec($title, 'Hero Sections', $note),
          self::sec(self::t('content', $lang), 'Content Sections', $note),
          self::sec(self::t('cta', $lang), 'CTA Sections', ''),
          self::sec(self::t('footer', $lang), 'Footers', ''),
        ],
        'seo'         => self::seo($title),
        'archer_added' => 'source',
      ];
    }
    // Ouders die de AI anders koos, terugzetten naar de structuur van de bron.
    $source_parent = [];
    foreach ($source['pages'] as $sp) {
      $mine = $map[(string) $sp['slug']] ?? null;
      $par = $map[(string) ($sp['parent_slug'] ?? 'home')] ?? 'home';
      if ($mine && $mine !== 'home') $source_parent[$mine] = $par;
    }
    foreach ($data['sitemap'] as $i => $p) {
      $slug = (string) ($p['slug'] ?? '');
      if (isset($source_parent[$slug]) && $source_parent[$slug] !== $slug) {
        $data['sitemap'][$i]['parent_slug'] = $source_parent[$slug];
      }
    }
    // Mappen die op de bron veel pagina's hadden, zijn collecties.
    $collection_slugs = [];
    foreach ((array) ($source['collapsed'] ?? []) as $c) {
      if (!empty($c['slug']) && isset($map[$c['slug']])) $collection_slugs[$map[$c['slug']]] = (int) ($c['items'] ?? 0);
    }
    foreach ($data['sitemap'] as $i => $p) {
      $slug = (string) ($p['slug'] ?? '');
      if (isset($collection_slugs[$slug])) $data['sitemap'][$i]['archer_collection'] = $collection_slugs[$slug];
    }
    $data['archer_source'] = ['url' => $source['url'], 'pages' => count($source['pages']), 'added' => count($missing), 'collapsed' => $source['collapsed'] ?? []];
    return $data;
  }

  /* ───────────────────────── Bestaande website lezen ───────────────────────── */

  public static function source_key(): string {
    return 'aisb_archer_src_' . get_current_user_id();
  }

  public static function remember_source(string $url, array $pages, array $collapsed): void {
    set_transient(self::source_key(), ['url' => $url, 'pages' => $pages, 'collapsed' => $collapsed], self::SOURCE_TTL);
  }

  private static function get(string $url, int $timeout = 10) {
    return wp_safe_remote_get($url, [
      'timeout' => $timeout,
      'redirection' => 3,
      'user-agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)',
      'limit_response_size' => 3 * 1024 * 1024,
    ]);
  }

  /** Normaliseert een interne URL tot een pad, of null als het geen pagina is. */
  private static function page_path(string $href, string $base, string $host): ?string {
    $href = trim(html_entity_decode($href));
    if ($href === '' || $href[0] === '#' || preg_match('#^(mailto|tel|javascript|data):#i', $href)) return null;
    if (strpos($href, '//') === 0) $href = 'https:' . $href;
    if (!preg_match('#^https?://#i', $href)) {
      $href = rtrim($base, '/') . '/' . ltrim($href, '/');
    }
    $u = wp_parse_url($href);
    if (empty($u['host'])) return null;
    $h = preg_replace('/^www\./', '', strtolower($u['host']));
    if ($h !== $host) return null;
    $path = '/' . trim((string) ($u['path'] ?? '/'), '/');
    if (preg_match('#\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp4|mp3|xml|txt|css|js|ico)$#i', $path)) return null;
    if (preg_match('#^/(wp-(json|admin|content|includes|login)|feed|comments|xmlrpc|cart|winkelmand|checkout|afrekenen|my-account|mijn-account|cdn-cgi)(/|$)#i', $path)) return null;
    if (preg_match('#/(feed|page/\d+|amp)$#i', $path)) return null;
    if (preg_match('#^/(tag|category|categorie|author|auteur)/#i', $path)) return null;
    // Technische of persoonlijke pagina's van WordPress, page builders en webshops.
    if (preg_match('#(^|/)(sample-page|voorbeeld-pagina|hello-world|_wp_link_placeholder|templates?|elementor-[a-z0-9-]*|bricks_template|et_pb_layout|profiel|bewerk-profiel|registreer|register|registreren|login|inloggen|logout|account|wishlist|verlanglijst|buy-credits|lost-password|wachtwoord-vergeten)(/|$)#i', $path)) return null;
    return $path;
  }

  /** Haalt <loc>-adressen uit sitemap.xml (ook sitemap-indexen). */
  private static function sitemap_paths(string $base, string $host): array {
    $candidates = [];
    $robots = self::get($base . '/robots.txt', 6);
    if (!is_wp_error($robots) && (int) wp_remote_retrieve_response_code($robots) === 200) {
      if (preg_match_all('/^\s*sitemap:\s*(\S+)/im', (string) wp_remote_retrieve_body($robots), $m)) $candidates = $m[1];
    }
    $candidates = array_merge($candidates, [$base . '/sitemap_index.xml', $base . '/wp-sitemap.xml', $base . '/sitemap.xml', $base . '/page-sitemap.xml']);
    $queue = array_values(array_unique($candidates));
    $seen = []; $paths = []; $fetched = 0;
    while ($queue && $fetched < 12 && count($paths) < 800) {
      $sm = array_shift($queue);
      if (isset($seen[$sm])) continue;
      $seen[$sm] = true;
      $res = self::get($sm, 8);
      $fetched++;
      if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) !== 200) continue;
      $xml = (string) wp_remote_retrieve_body($res);
      if (stripos($xml, '<sitemapindex') !== false) {
        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
          foreach ($m[1] as $loc) {
            $loc = html_entity_decode($loc);
            // Bijlagen, tags en auteurs overslaan.
            if (preg_match('#(attachment|tag|author|category|taxonomies|users)#i', $loc)) continue;
            $queue[] = $loc;
          }
        }
        continue;
      }
      if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
        foreach ($m[1] as $loc) {
          $p = self::page_path($loc, $base, $host);
          if ($p !== null) $paths[$p] = true;
        }
      }
    }
    return array_keys($paths);
  }

  /** Interne links op een pagina, met de linktekst (menunaam) per pad. */
  private static function link_paths(string $html, string $base, string $host, ?array &$labels = null): array {
    $out = [];
    if (preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
      foreach ($m as $hit) {
        $p = self::page_path($hit[1], $base, $host);
        if ($p === null) continue;
        $out[$p] = true;
        if ($labels !== null) {
          $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($hit[2]), ENT_QUOTES)));
          // Korte linkteksten zijn menunamen; "Lees meer" en co. niet.
          if ($t !== '' && mb_strlen($t) >= 2 && mb_strlen($t) <= 40
              && !preg_match('/^(lees meer|meer info|read more|learn more|en savoir plus|ontdek|discover|klik hier|click here|meer|more|home|→|›)$/iu', $t)) {
            if (!isset($labels[$p]) || mb_strlen($t) < mb_strlen($labels[$p])) $labels[$p] = $t;
          }
        }
      }
    }
    return array_keys($out);
  }

  /** Korte paginanaam: titeltag (eerste deel) of h1, als die kort genoeg is. */
  private static function title_from(string $html): string {
    $cands = [];
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
      $t = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES));
      $cands[] = trim(preg_split('/\s+[\|\-–—·:]\s+/u', $t)[0] ?? $t);
    }
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
      $cands[] = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES)));
    }
    foreach ($cands as $t) {
      if ($t !== '' && mb_strlen($t) <= 45) return $t;
    }
    return '';
  }

  private static function humanize(string $segment): string {
    $s = str_replace(['-', '_'], ' ', urldecode($segment));
    $s = trim($s);
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
  }

  /** Taalmap aan het begin van een pad (nl, fr, en, nl-be…), of ''. */
  private static function lang_prefix(string $path): string {
    $first = explode('/', trim($path, '/'))[0] ?? '';
    return preg_match('/^[a-z]{2}(?:[-_][a-z]{2})?$/i', $first) ? strtolower($first) : '';
  }

  /**
   * Leest de volledige paginastructuur van een website.
   * Meertalige sites: alleen de taal van het opgegeven adres (anders Nederlands, anders de grootste).
   *
   * @return array{pages: array, collapsed: array}
   */
  public static function crawl_site(string $url, string $home_html): array {
    $u = wp_parse_url($url);
    $scheme = $u['scheme'] ?? 'https';
    $host = preg_replace('/^www\./', '', strtolower((string) ($u['host'] ?? '')));
    $base = $scheme . '://' . ($u['host'] ?? $host);
    $labels = [];

    $paths = array_flip(self::sitemap_paths($base, $host));
    foreach (self::link_paths($home_html, $base, $host, $labels) as $p) $paths[$p] = true;

    // Een niveau dieper via de menupagina's, als de sitemap.xml weinig opleverde.
    if (count($paths) < 15) {
      $level1 = array_slice(array_keys($paths), 0, 25);
      foreach (self::fetch_many(array_map(function ($p) use ($base) { return $base . $p; }, $level1)) as $html) {
        foreach (self::link_paths($html, $base, $host) as $p) $paths[$p] = true;
      }
    }
    unset($paths['/']);
    $paths = array_keys($paths);

    // Meertalig: een taal kiezen en de taalmap uit het pad halen.
    $by_lang = [];
    foreach ($paths as $p) {
      $l = self::lang_prefix($p);
      if ($l !== '') $by_lang[$l] = ($by_lang[$l] ?? 0) + 1;
    }
    $lang = '';
    $multi = count($by_lang) >= 2 || (count($by_lang) === 1 && reset($by_lang) >= 3);
    if ($multi) {
      $want = self::lang_prefix((string) ($u['path'] ?? ''));
      if ($want !== '' && isset($by_lang[$want])) $lang = $want;
      else {
        foreach (['nl', 'nl-be', 'nl_be', 'be-nl'] as $c) if (isset($by_lang[$c])) { $lang = $c; break; }
        if ($lang === '') { arsort($by_lang); $lang = (string) key($by_lang); }
      }
    }
    $real = []; // weergavepad => echt pad
    foreach ($paths as $p) {
      $l = self::lang_prefix($p);
      if ($multi && $l !== '' && $l !== $lang) continue;
      $display = ($multi && $l === $lang) ? '/' . trim(substr(trim($p, '/'), strlen($l)), '/') : $p;
      if ($display === '/' || $display === '') continue;
      if (!isset($real[$display])) $real[$display] = $p;
    }
    if ($multi && $lang !== '') {
      foreach ($labels as $p => $t) {
        if (self::lang_prefix($p) === $lang) $labels['/' . trim(substr(trim($p, '/'), strlen($lang)), '/')] = $t;
      }
    }

    // Tussenmappen zonder eigen pagina (bv. /diensten/ boven /diensten/x en /diensten/y) worden een groepspagina.
    $count_under = [];
    foreach (array_keys($real) as $p) {
      $segs = explode('/', trim($p, '/'));
      for ($k = 1; $k < count($segs); $k++) {
        $pp = '/' . implode('/', array_slice($segs, 0, $k));
        $count_under[$pp] = ($count_under[$pp] ?? 0) + 1;
      }
    }
    $virtual = [];
    foreach ($count_under as $pp => $n) {
      if (!isset($real[$pp]) && $n >= 2) { $real[$pp] = null; $virtual[$pp] = true; }
    }

    // Collecties met veel kinderen (producten, blogberichten) samenvatten.
    $kids = [];
    foreach (array_keys($real) as $p) {
      $parent = dirname($p);
      if ($parent === '\\' || $parent === '.') $parent = '/';
      $kids[$parent][] = $p;
    }
    $collapsed = [];
    $keep = [];
    foreach (array_keys($real) as $p) {
      $parent = dirname($p);
      if ($parent === '\\' || $parent === '.') $parent = '/';
      if ($parent !== '/' && count($kids[$parent]) >= self::COLLECTION_MIN_CHILDREN) {
        $collapsed[$parent] = count($kids[$parent]);
        continue;
      }
      $keep[] = $p;
    }
    // Ondiepe pagina's eerst; zeer grote sites: maximaal 60 pagina's.
    usort($keep, function ($a, $b) { return substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b); });
    $keep = array_slice($keep, 0, 60);
    foreach (array_keys($collapsed) as $parent) {
      if (!in_array($parent, $keep, true)) $keep[] = $parent;
    }
    usort($keep, function ($a, $b) { return substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b); });

    // Titels ophalen (parallel), alleen voor echte pagina's zonder menunaam.
    $titles = [];
    $fetch = [];
    foreach ($keep as $p) {
      if (!empty($real[$p]) && empty($labels[$p])) $fetch[$base . $real[$p]] = $p;
    }
    foreach (self::fetch_many(array_keys(array_slice($fetch, 0, 60, true))) as $full => $html) {
      if (isset($fetch[$full])) $titles[$fetch[$full]] = self::title_from($html);
    }
    $home_title = self::title_from($home_html);

    // Slugs en ouders uit het pad.
    $slug_of = ['/' => 'home'];
    $taken = ['home' => true];
    $pages = [];
    foreach ($keep as $p) {
      $segments = explode('/', trim($p, '/'));
      $last = end($segments);
      $slug = sanitize_title(urldecode($last)) ?: 'pagina';
      if (isset($taken[$slug])) $slug = sanitize_title(implode('-', $segments));
      $n = 2; $b = $slug;
      while (isset($taken[$slug])) $slug = $b . '-' . $n++;
      $taken[$slug] = true;
      $slug_of[$p] = $slug;
      $parent = 'home';
      for ($k = count($segments) - 1; $k >= 1; $k--) {
        $pp = '/' . implode('/', array_slice($segments, 0, $k));
        if (isset($slug_of[$pp])) { $parent = $slug_of[$pp]; break; }
      }
      $title = $labels[$p] ?? ($titles[$p] ?? '');
      if ($title === '' || mb_strtolower($title) === mb_strtolower($home_title)) $title = self::humanize($last);
      $pages[] = [
        'slug' => $slug,
        'title' => $title,
        'parent_slug' => $parent,
        'url' => $real[$p] ? $base . $real[$p] : '',
        'group' => isset($virtual[$p]),
      ];
    }

    $collapsed_out = [];
    foreach ($collapsed as $parent => $n) {
      $collapsed_out[] = ['path' => $parent, 'slug' => $slug_of[$parent] ?? '', 'items' => $n];
    }
    return ['pages' => $pages, 'collapsed' => $collapsed_out, 'language' => $lang];
  }

  /** Haalt meerdere pagina's tegelijk op. */
  private static function fetch_many(array $urls): array {
    $out = [];
    if (!$urls) return $out;
    if (class_exists('WpOrg\Requests\Requests')) {
      $requests = [];
      foreach (array_values($urls) as $i => $u) {
        if (!wp_http_validate_url($u)) continue;
        $requests[$i] = ['url' => $u, 'type' => 'GET', 'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0)']];
      }
      try {
        $responses = \WpOrg\Requests\Requests::request_multiple($requests, ['timeout' => 10, 'connect_timeout' => 6, 'redirects' => 3]);
        foreach ($responses as $i => $r) {
          if ($r instanceof \WpOrg\Requests\Response && $r->status_code === 200) $out[$requests[$i]['url']] = (string) $r->body;
        }
        return $out;
      } catch (\Throwable $e) {
        // Valt terug op een voor een.
      }
    }
    foreach (array_slice($urls, 0, 25) as $u) {
      $res = self::get($u, 8);
      if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) $out[$u] = (string) wp_remote_retrieve_body($res);
    }
    return $out;
  }
}
