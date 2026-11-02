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
  private const COLLECTION_MIN_CHILDREN = 25;

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
      'te-koop'       => ['Pand te koop', 'Single Property Sections'],
      'te-huur'       => ['Pand te huur', 'Single Property Sections'],
      'kopen'         => ['Pand te koop', 'Single Property Sections'],
      'huren'         => ['Pand te huur', 'Single Property Sections'],
      'vastgoed'      => ['Pand', 'Single Property Sections'],
      'huizen'        => ['Huis', 'Single Property Sections'],
      'appartementen' => ['Appartement', 'Single Property Sections'],
      'immo'          => ['Pand', 'Single Property Sections'],
      'a-vendre'      => ['Bien à vendre', 'Single Property Sections'],
      'a-louer'       => ['Bien à louer', 'Single Property Sections'],
      'for-sale'      => ['Property for sale', 'Single Property Sections'],
      'for-rent'      => ['Property for rent', 'Single Property Sections'],
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
      // Samengestelde sleutels ("huizen-te-koop" bevat "te-koop").
      foreach ($list as $key => $info) {
        if (strpos($key, '-') !== false && strpos('-' . $p . '-', '-' . $key . '-') !== false) return $info;
      }
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

  public static function remember_source(string $url, array $pages, array $collapsed, array $extra = []): void {
    set_transient(self::source_key(), array_merge(['url' => $url, 'pages' => $pages, 'collapsed' => $collapsed], $extra), self::SOURCE_TTL);
  }

  public static function get_source(): ?array {
    $s = get_transient(self::source_key());
    return is_array($s) ? $s : null;
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
    $path = rawurldecode($path);
    if (preg_match('#\.(pdf|jpe?g|png|gif|webp|svg|zip|docx?|xlsx?|mp4|mp3|xml|txt|css|js|ico)$#i', $path)) return null;
    if (preg_match('#^/(wp-(json|admin|content|includes|login)|feed|comments|xmlrpc|cart|winkelmand|checkout|afrekenen|my-account|mijn-account|cdn-cgi)(/|$)#i', $path)) return null;
    if (preg_match('#/(feed|page/\d+|amp)$#i', $path)) return null;
    if (preg_match('#^/(tag|category|categorie|author|auteur)/#i', $path)) return null;
    // Technische, persoonlijke of interne pagina's (formulieren voor klanten, bedankpagina's…).
    if (preg_match('#(^|/)(sample-page|voorbeeld-pagina|hello-world|_wp_link_placeholder|templates?|elementor-[a-z0-9-]*|bricks_template|et_pb_layout|profiel|bewerk-profiel|registreer|register|registreren|login|inloggen|logout|account|wishlist|verlanglijst|buy-credits|lost-password|wachtwoord-vergeten|bedankt|dank-je|thank-?you|thanks|merci|bevestiging|confirmation)(/|$)#i', $path)) return null;
    if (preg_match('#(intake|enquete|survey|tevredenheid)#i', $path)) return null;
    return $path;
  }

  /** Soort inhoud volgens de naam van het sitemapbestand: post, cases, product… ('' voor gewone pagina's). */
  private static function sitemap_type(string $sitemap_url): string {
    $file = strtolower(basename((string) wp_parse_url($sitemap_url, PHP_URL_PATH)));
    if (preg_match('/^wp-sitemap-posts-([a-z0-9_-]+?)-\d+\.xml$/', $file, $m)) $t = $m[1];
    elseif (preg_match('/^([a-z0-9_-]+?)[-_]sitemap\d*\.xml$/', $file, $m)) $t = $m[1];
    else return '';
    return in_array($t, ['page', 'pages', 'wp', 'main'], true) ? '' : $t;
  }

  /**
   * Haalt de adressen uit sitemap.xml (ook sitemap-indexen), parallel.
   * Extra adressen (bv. REST-routes) gaan mee in de eerste ronde; hun antwoord komt terug in $extra_out.
   * @return array pad => soort ('' = pagina)
   */
  private static function sitemap_paths(string $base, string $host, string $robots, array $extra = [], array &$extra_out = []): array {
    $candidates = [];
    if (preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robots, $m)) $candidates = $m[1];
    if (!$candidates) $candidates = [$base . '/sitemap_index.xml', $base . '/wp-sitemap.xml', $base . '/sitemap.xml'];
    $queue = array_values(array_unique($candidates));
    $seen = []; $paths = []; $rounds = 0;
    while (($queue || ($rounds === 0 && $extra)) && $rounds < 3 && count($paths) < 800) {
      $rounds++;
      $batch = array_slice(array_values(array_filter($queue, function ($u) use ($seen) { return !isset($seen[$u]); })), 0, 16);
      $queue = [];
      foreach ($batch as $u) $seen[$u] = true;
      $fetch = $rounds === 1 ? array_merge($batch, $extra) : $batch;
      foreach (self::fetch_many($fetch, 8) as $req => $res) {
        if ($rounds === 1 && in_array($req, $extra, true)) { $extra_out[$req] = $res['body']; continue; }
        $xml = $res['body'];
        if (stripos($xml, '<sitemapindex') !== false) {
          if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
            foreach ($m[1] as $loc) {
              $loc = html_entity_decode($loc);
              if (preg_match('#(attachment|post_tag|tag-sitemap|author|category|taxonom|users|locations?-sitemap)#i', $loc)) continue;
              $queue[] = $loc;
            }
          }
          continue;
        }
        $type = self::sitemap_type($req);
        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $m)) {
          foreach ($m[1] as $loc) {
            $p = self::page_path($loc, $base, $host);
            if ($p !== null && !isset($paths[$p])) $paths[$p] = $type;
          }
        }
      }
    }
    unset($paths['/']);
    return $paths;
  }

  /** Haalt WordPress-inhoud op via de REST-API: titels, ouders en soorten. */
  private static function wp_rest(string $base, string $host, array $first, array &$titles, array &$wp_parent): array {
    $types = [];
    $pages_json = json_decode((string) ($first[$base . '/wp-json/wp/v2/pages?per_page=100&_fields=id,link,title,parent'] ?? ''), true);
    if (!is_array($pages_json) || !$pages_json || !isset($pages_json[0]['link'])) return [];
    $id_path = [];
    $add = function (array $items, string $type) use (&$types, &$titles, &$id_path, $base, $host) {
      foreach ($items as $it) {
        if (!is_array($it) || empty($it['link'])) continue;
        $p = self::page_path((string) $it['link'], $base, $host);
        if ($p === null || $p === '/') continue;
        $types[$p] = $type;
        $t = trim(html_entity_decode(wp_strip_all_tags((string) ($it['title']['rendered'] ?? '')), ENT_QUOTES));
        if ($t !== '') $titles[$p] = $t;
        if (isset($it['id'])) $id_path[$type . ':' . (int) $it['id']] = $p;
      }
    };
    $add($pages_json, '');
    foreach ($pages_json as $it) {
      if (!empty($it['parent']) && isset($id_path[':' . (int) $it['id']], $id_path[':' . (int) $it['parent']])) {
        $wp_parent[$id_path[':' . (int) $it['id']]] = $id_path[':' . (int) $it['parent']];
      }
    }
    $posts = json_decode((string) ($first[$base . '/wp-json/wp/v2/posts?per_page=100&_fields=id,link,title'] ?? ''), true);
    if (is_array($posts)) $add($posts, 'post');
    // Eigen inhoudssoorten (cases, projecten, producten…).
    $all_types = json_decode((string) ($first[$base . '/wp-json/wp/v2/types'] ?? ''), true);
    $skip = ['page', 'post', 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_font_family', 'wp_font_face', 'wp_global_styles', 'bricks_template', 'elementor_library', 'ct_template', 'oxy_user_library', 'et_pb_layout', 'fl-builder-template', 'jet-engine', 'jet-theme-core'];
    $urls = [];
    if (is_array($all_types)) {
      foreach ($all_types as $slug => $def) {
        if (!is_array($def) || in_array($slug, $skip, true) || empty($def['rest_base'])) continue;
        if (isset($def['viewable']) && !$def['viewable']) continue;
        $urls[$slug] = $base . '/wp-json/wp/v2/' . rawurlencode((string) $def['rest_base']) . '?per_page=100&_fields=id,link,title';
      }
    }
    if ($urls) {
      $res = self::fetch_many(array_values(array_slice($urls, 0, 8)), 8);
      foreach (array_slice($urls, 0, 8, true) as $slug => $u) {
        $items = json_decode((string) ($res[$u]['body'] ?? ''), true);
        if (is_array($items)) $add($items, (string) $slug);
      }
    }
    return $types;
  }

  /** Eén kleine AI-vraag: onder welke menupagina hoort elke losse pagina? */
  private static function ai_parents(array $orphans, array $menu): array {
    if (!$orphans || !$menu || !class_exists('AISB_OpenAI')) return [];
    $settings = class_exists('AISB_Settings') ? (new AISB_Settings())->get_settings() : [];
    if (empty($settings['api_key'])) return [];
    $settings['timeout'] = min(25, max(10, (int) ($settings['timeout'] ?? 25)));
    $lines_m = []; foreach ($menu as $k => $t) $lines_m[] = '- ' . $k . ': ' . $t;
    $lines_o = []; foreach ($orphans as $k => $t) $lines_o[] = '- ' . $k . ': ' . $t;
    $system = 'You organise website pages into a sitemap. Return only JSON.';
    $user = "Menu pages (id: title):\n" . implode("\n", $lines_m) . "\n\nLoose pages (id: title):\n" . implode("\n", $lines_o)
      . "\n\nFor each loose page, choose the menu page it logically belongs under: a service, a free audit, analysis or quote offer under the services page, a case or project under the cases/projects page, a blog post or article under the blog/news/insights page, a team member under the about page, a product under the products page. If none fits clearly, use \"home\"."
      . "\nReturn JSON: {\"parents\": {\"<loose page id>\": \"<menu page id or home>\"}}";
    $res = (new AISB_OpenAI())->call_openai_chat_completions($user, $settings, $system);
    if (is_wp_error($res)) return [];
    $j = json_decode((string) $res, true);
    return is_array($j['parents'] ?? null) ? $j['parents'] : [];
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
          if ($t !== '' && mb_strlen($t) >= 2 && mb_strlen($t) <= 40
              && !preg_match('/^(lees meer|meer info|read more|learn more|en savoir plus|ontdek|discover|klik hier|click here|meer|more|home|→|›)$/iu', $t)) {
            if (!isset($labels[$p]) || mb_strlen($t) < mb_strlen($labels[$p])) $labels[$p] = $t;
          }
        }
      }
    }
    return array_keys($out);
  }

  private static function dom(string $html): ?DOMXPath {
    if (trim($html) === '' || !class_exists('DOMDocument')) return null;
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? new DOMXPath($doc) : null;
  }

  /**
   * Menustructuur van de site: welk menu-item staat onder welk ander item,
   * en welke links staan in header, menu of footer (op elke pagina).
   * @return array{parent: array, items: array, chrome: array}
   */
  private static function nav_structure(string $html, string $base, string $host): array {
    $out = ['parent' => [], 'items' => [], 'chrome' => []];
    $x = self::dom($html);
    if (!$x) return $out;
    foreach ($x->query('//header//a[@href] | //nav//a[@href] | //footer//a[@href] | //*[contains(@id,"header") or contains(@id,"footer") or contains(@class,"site-header") or contains(@class,"footer")]//a[@href]') as $a) {
      $p = self::page_path($a->getAttribute('href'), $base, $host);
      if ($p !== null) $out['chrome'][$p] = true;
    }
    $menus = $x->query('//header | //nav');
    foreach ($menus as $menu) {
      foreach ($x->query('.//li', $menu) as $li) {
        $own = null;
        foreach ($x->query('.//a[@href]', $li) as $a) {
          $p = self::page_path($a->getAttribute('href'), $base, $host);
          if ($p !== null) { $own = $p; break; }
          if (trim($a->getAttribute('href')) === '#' || trim($a->getAttribute('href')) === '') break;
        }
        if ($own === null || $own === '/') continue;
        $out['items'][$own] = true;
        // Alles in een submenu (of megamenu) van dit item hangt eronder.
        foreach ($x->query('.//ul//a[@href] | .//div[contains(@class,"sub") or contains(@class,"mega") or contains(@class,"dropdown")]//a[@href]', $li) as $a) {
          $p = self::page_path($a->getAttribute('href'), $base, $host);
          if ($p === null || $p === $own || $p === '/') continue;
          $out['parent'][$p] = $own; // diepere menu-items komen later en overschrijven dit
        }
      }
    }
    return $out;
  }

  /** Links in de inhoud van een pagina (zonder header, menu, zijbalk en footer). */
  private static function content_links(string $html, string $base, string $host): array {
    $x = self::dom($html);
    if (!$x) return [];
    foreach (['//header', '//nav', '//footer', '//aside', '//*[contains(@id,"header")]', '//*[contains(@id,"footer")]', '//*[contains(@class,"site-header")]', '//*[contains(@class,"footer")]'] as $q) {
      foreach (iterator_to_array($x->query($q)) as $n) {
        if ($n->parentNode) $n->parentNode->removeChild($n);
      }
    }
    $out = [];
    foreach ($x->query('//body//a[@href]') as $a) {
      $p = self::page_path($a->getAttribute('href'), $base, $host);
      if ($p !== null && $p !== '/') $out[$p] = true;
    }
    return array_keys($out);
  }

  /** Korte paginanaam: titeltag (eerste deel) of h1, als die kort genoeg is. */
  private static function title_from(string $html): string {
    $cands = [];
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
      $t = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES));
      $cands[] = trim(preg_split('/\s+[\|\-–—·]\s+/u', $t)[0] ?? $t);
    }
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $html, $m)) {
      $cands[] = trim(preg_replace('/\s+/', ' ', html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES)));
    }
    foreach ($cands as $t) {
      if ($t !== '' && mb_strlen($t) <= 80) return $t;
    }
    return '';
  }

  private static function is_noindex(string $html): bool {
    return (bool) preg_match('#<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex#i', $html);
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

  /** Woorden waaraan je een blog- of nieuwsoverzicht herkent. */
  private const BLOG_WORDS = ['blog', 'blogs', 'nieuws', 'news', 'inzichten', 'insights', 'artikels', 'artikelen', 'articles', 'kennisbank', 'actueel', 'magazine', 'tips', 'journal', 'actualites', 'actualite'];

  /**
   * Leest de volledige paginastructuur van een website.
   *
   * Ouders, in deze volgorde:
   * 1. het pad (/diensten/branding hangt onder /diensten);
   * 2. het menu (Branding staat in het submenu van Diensten);
   * 3. het soort inhoud uit sitemap.xml (blogberichten onder Blog/Inzichten, cases onder Cases);
   * 4. links in de inhoud van een menupagina (de casepagina linkt naar elke case);
   * anders hangt de pagina onder Home.
   * Meertalige sites: alleen de taal van het opgegeven adres (anders Nederlands, anders de grootste).
   *
   * @return array{pages: array, collapsed: array, language: string}
   */
  public static function crawl_site(string $url, string $home_html): array {
    $u = wp_parse_url($url);
    $scheme = $u['scheme'] ?? 'https';
    $host = preg_replace('/^www\./', '', strtolower((string) ($u['host'] ?? '')));
    $base = $scheme . '://' . ($u['host'] ?? $host);
    $labels = [];

    // Ronde 1 (parallel): robots.txt en de WordPress REST-API.
    $rest = [
      $base . '/wp-json/wp/v2/pages?per_page=100&_fields=id,link,title,parent',
      $base . '/wp-json/wp/v2/posts?per_page=100&_fields=id,link,title',
      $base . '/wp-json/wp/v2/types',
    ];
    $first = [];
    foreach (self::fetch_many(array_merge([$base . '/robots.txt'], $rest), 8) as $req => $res) $first[$req] = $res['body'];
    $known_titles = []; $wp_parent = [];
    $rest_types = self::wp_rest($base, $host, $first, $known_titles, $wp_parent);
    // sitemap.xml: welke pagina's de site zelf laat indexeren.
    $types = self::sitemap_paths($base, $host, (string) ($first[$base . '/robots.txt'] ?? ''));
    if ($rest_types) {
      // REST kent ook verborgen pagina's: alleen houden wat in sitemap.xml of op de homepage staat.
      $home_set = array_flip(self::link_paths($home_html, $base, $host));
      foreach ($rest_types as $p => $t) {
        if ($types && !isset($types[$p]) && !isset($home_set[$p])) continue;
        $types[$p] = $t !== '' ? $t : ($types[$p] ?? '');
      }
    }
    $home_links = self::link_paths($home_html, $base, $host, $labels);
    foreach ($home_links as $p) if (!isset($types[$p])) $types[$p] = '';
    $nav = self::nav_structure($home_html, $base, $host);
    unset($types['/']);

    // Weinig gevonden: een niveau dieper via de menupagina's.
    if (count($types) < 8) {
      foreach (self::fetch_many(array_map(function ($p) use ($base) { return $base . $p; }, array_slice(array_keys($types), 0, 20)), 8) as $res) {
        foreach (self::link_paths($res['body'], $base, $host) as $p) if (!isset($types[$p]) && $p !== '/') $types[$p] = '';
      }
    }

    // Meertalig: een taal kiezen en de taalmap uit het pad halen.
    $by_lang = [];
    foreach (array_keys($types) as $p) {
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
    $display = function (string $p) use ($multi, $lang): ?string {
      if (!$multi) return $p;
      $l = self::lang_prefix($p);
      if ($l !== '' && $l !== $lang) return null;
      if ($l === $lang) {
        $d = '/' . trim(substr(trim($p, '/'), strlen($l)), '/');
        return $d === '/' ? null : $d;
      }
      return $p;
    };
    $real = []; $type_of = [];
    foreach ($types as $p => $t) {
      $d = $display($p);
      if ($d === null || isset($real[$d])) continue;
      $real[$d] = $p;
      $type_of[$d] = $t;
    }
    $map_keys = function (array $arr) use ($display) {
      $o = [];
      foreach ($arr as $k => $v) { $d = $display((string) $k); if ($d !== null) $o[$d] = $v; }
      return $o;
    };
    $labels = $map_keys($labels);
    $known_titles = $map_keys($known_titles);
    $nav_items = $map_keys($nav['items']);
    $chrome = $map_keys($nav['chrome']);
    $nav_parent = [];
    foreach ($nav['parent'] as $c => $par) {
      $dc = $display((string) $c); $dp = $display((string) $par);
      if ($dc !== null && $dp !== null && $dc !== $dp) $nav_parent[$dc] = $dp;
    }

    // Grote verzamelingen (bv. 200 producten) samenvatten tot één sjabloonpagina.
    $type_count = [];
    foreach ($type_of as $d => $t) if ($t !== '') $type_count[$t] = ($type_count[$t] ?? 0) + 1;
    $collapsed_types = [];
    foreach ($type_count as $t => $n) {
      if ($n >= self::COLLECTION_MIN_CHILDREN) $collapsed_types[$t] = $n;
    }
    foreach ($type_of as $d => $t) {
      if (isset($collapsed_types[$t])) { unset($real[$d], $type_of[$d]); }
    }

    // Veiligheidsgrens (de echte grens van 60 volgt na het bepalen van de ouders).
    $order = array_keys($real);
    usort($order, function ($a, $b) use ($nav_items) {
      $na = isset($nav_items[$a]) ? 0 : 1; $nb = isset($nav_items[$b]) ? 0 : 1;
      return $na <=> $nb ?: (substr_count($a, '/') <=> substr_count($b, '/')) ?: strcmp($a, $b);
    });
    $order = array_slice($order, 0, 200);
    $real = array_intersect_key($real, array_flip($order));

    // Dubbele pagina's met dezelfde naam (bv. /smithii en /cases/smithii): de versie met een eigen soort houden.
    $by_base = [];
    foreach (array_keys($real) as $d) $by_base[basename($d)][] = $d;
    foreach ($by_base as $list) {
      if (count($list) < 2) continue;
      $typed = array_values(array_filter($list, function ($d) use ($type_of) { return ($type_of[$d] ?? '') !== ''; }));
      if (!$typed) continue;
      foreach ($list as $d) {
        if (!in_array($d, $typed, true) && !isset($nav_items[$d])) unset($real[$d], $type_of[$d]);
      }
    }

    // Ouders bepalen.
    $parent = [];
    $closest_real = function (string $d) use (&$real): ?string {
      $segs = explode('/', trim($d, '/'));
      for ($k = count($segs) - 1; $k >= 1; $k--) {
        $pp = '/' . implode('/', array_slice($segs, 0, $k));
        if (isset($real[$pp])) return $pp;
      }
      return null;
    };
    // Overzichtspagina per soort inhoud (posts → blog/inzichten, cases → cases…).
    $overview_for = function (string $type) use (&$real, $labels): ?string {
      $want = $type === 'post' ? self::BLOG_WORDS : array_unique([self::plain($type), rtrim(self::plain($type), 's'), self::plain($type) . 's', 'onze-' . self::plain($type), 'our-' . self::plain($type)]);
      foreach (array_keys($real) as $d) {
        if (substr_count(trim($d, '/'), '/') > 0) continue;
        $last = self::plain(basename($d));
        $lab = self::plain((string) ($labels[$d] ?? ''));
        foreach ($want as $w) {
          if ($last === $w || $lab === $w || $last === 'onze-' . $w || $last === 'our-' . $w) return $d;
        }
      }
      return null;
    };
    $virtual = [];
    $group_by_type = [];
    $wp_parent_d = [];
    foreach ($wp_parent as $c => $pp) { $dc = $display((string) $c); $dp = $display((string) $pp); if ($dc !== null && $dp !== null) $wp_parent_d[$dc] = $dp; }
    foreach (array_keys($real) as $d) {
      if (isset($wp_parent_d[$d]) && isset($real[$wp_parent_d[$d]]) && $wp_parent_d[$d] !== $d) { $parent[$d] = $wp_parent_d[$d]; continue; }
      if (($cp = $closest_real($d)) !== null) { $parent[$d] = $cp; continue; }
      if (isset($nav_parent[$d]) && isset($real[$nav_parent[$d]])) { $parent[$d] = $nav_parent[$d]; continue; }
      $t = $type_of[$d] ?? '';
      if ($t !== '') {
        $ov = $overview_for($t);
        if ($ov !== null && $ov !== $d) { $parent[$d] = $ov; continue; }
        $group_by_type[$t][] = $d;
      }
    }
    // Soort inhoud zonder overzichtspagina: een groepspagina maken.
    foreach ($group_by_type as $t => $list) {
      if (count($list) < 2) continue;
      $gp = '/' . ($t === 'post' ? 'blog' : sanitize_title($t));
      if (!isset($real[$gp])) { $real[$gp] = null; $virtual[$gp] = $t === 'post' ? 'Blog' : self::humanize($t); }
      foreach ($list as $d) $parent[$d] = $gp;
    }
    // Mappen zonder eigen pagina (/diensten/x en /diensten/y zonder /diensten): groepspagina.
    $under = [];
    foreach (array_keys($real) as $d) {
      if (isset($parent[$d])) continue;
      $segs = explode('/', trim($d, '/'));
      if (count($segs) > 1) $under['/' . $segs[0]][] = $d;
    }
    foreach ($under as $gp => $list) {
      if (count($list) < 2) continue;
      if (!isset($real[$gp])) { $real[$gp] = null; $virtual[$gp] = self::humanize(basename($gp)); }
      foreach ($list as $d) $parent[$d] = $gp;
    }
    // Ophalen (parallel): menupagina's (voor links in hun inhoud) en pagina's zonder gekende titel.
    $html_of = [];
    // Maximaal 60 pagina's: de grootste groepen (blogberichten, producten…) worden één sjabloonpagina.
    $collapsed_parents = [];
    if (count($real) > 60) {
      $kids = [];
      foreach ($parent as $c => $pp) if (!isset($nav_items[$c])) $kids[$pp][] = $c;
      uasort($kids, function ($a, $b) { return count($b) <=> count($a); });
      foreach ($kids as $pp => $list) {
        if (count($real) <= 60 || count($list) < 8) break;
        foreach ($list as $c) { unset($real[$c], $parent[$c]); }
        $collapsed_parents[$pp] = count($list);
      }
      if (count($real) > 60) {
        $order = array_keys($real);
        usort($order, function ($a, $b) use ($nav_items) {
          return ((isset($nav_items[$a]) ? 0 : 1) <=> (isset($nav_items[$b]) ? 0 : 1)) ?: (substr_count($a, '/') <=> substr_count($b, '/'));
        });
        $real = array_intersect_key($real, array_flip(array_slice($order, 0, 60)));
      }
    }

    $legal = '#(privacy|cookie|voorwaarden|terms|disclaimer|legal|contact|sitemap|impressum|mentions)#i';
    $has_orphans = false;
    foreach (array_keys($real) as $d) {
      if (!isset($parent[$d]) && !isset($nav_items[$d]) && !isset($chrome[$d]) && !preg_match($legal, $d)) { $has_orphans = true; break; }
    }
    $to_fetch = array_values(array_filter(array_keys($real), function ($d) use ($nav_items, $known_titles, $labels, $has_orphans, $real) {
      if ($real[$d] === null) return false;
      return ($has_orphans && isset($nav_items[$d])) || (!isset($known_titles[$d]) && !isset($labels[$d]));
    }));
    // Hooguit 30 pagina's ophalen (menu en ondiepe pagina's eerst); de rest krijgt een naam uit het adres.
    usort($to_fetch, function ($a, $b) use ($nav_items) {
      return ((isset($nav_items[$a]) ? 0 : 1) <=> (isset($nav_items[$b]) ? 0 : 1)) ?: (substr_count($a, '/') <=> substr_count($b, '/'));
    });
    $to_fetch = array_slice($to_fetch, 0, 30);
    $fetched = self::fetch_many(array_map(function ($d) use ($base, $real) { return $base . $real[$d]; }, $to_fetch), 7);
    foreach ($real as $d => $p) {
      if ($p === null) continue;
      $res = $fetched[$base . $p] ?? null;
      if (!$res) continue;
      // Doorverwezen naar een andere pagina die we al hebben: dubbel, weg.
      $final = self::page_path((string) $res['final'], $base, $host);
      $fd = $final !== null ? $display($final) : null;
      if ($fd !== null && $fd !== $d && (isset($real[$fd]) || $fd === '/')) { unset($real[$d]); continue; }
      if (self::is_noindex($res['body']) && !isset($nav_items[$d])) { unset($real[$d]); continue; }
      $html_of[$d] = $res['body'];
    }

    // Kinderen van weggevallen pagina's hangen onder Home.
    foreach ($parent as $d => $pp) { if (!array_key_exists($d, $real) || !array_key_exists($pp, $real)) unset($parent[$d]); }

    // Losse pagina's waar een menupagina naar linkt in haar inhoud (bv. Cases → elke case).
    $cands = array_values(array_filter(array_keys($nav_items), function ($d) use ($html_of) { return isset($html_of[$d]); }));
    usort($cands, function ($a, $b) use ($labels) {
      $ca = self::collection_for(['slug' => basename($a), 'page_title' => $labels[$a] ?? '']) ? 0 : 1;
      $cb = self::collection_for(['slug' => basename($b), 'page_title' => $labels[$b] ?? '']) ? 0 : 1;
      return $ca <=> $cb;
    });
    foreach ($cands as $pd) {
      foreach (self::content_links($html_of[$pd], $base, $host) as $lp) {
        $ld = $display($lp);
        if ($ld === null || $ld === $pd || !array_key_exists($ld, $real)) continue;
        if (isset($parent[$ld]) || isset($nav_items[$ld]) || isset($chrome[$ld]) || preg_match($legal, $ld)) continue;
        $parent[$ld] = $pd;
      }
    }
    // Wat dan nog los hangt (geen menu, geen header/footer, geen juridische pagina): de AI kiest de ouder.
    $menu = []; $orphans = [];
    foreach (array_keys($real) as $d) {
      $t = $labels[$d] ?? ($known_titles[$d] ?? self::humanize(basename($d)));
      if (isset($nav_items[$d]) && !isset($parent[$d]) && substr_count(trim($d, '/'), '/') === 0) $menu[$d] = $t;
      elseif (!isset($parent[$d]) && !isset($nav_items[$d]) && !isset($chrome[$d])
              && !preg_match($legal, $d)) $orphans[$d] = $t;
    }
    if ($orphans && $menu && count($orphans) <= 40) {
      foreach (self::ai_parents($orphans, $menu) as $od => $md) {
        if (isset($orphans[$od]) && isset($menu[$md]) && $od !== $md) $parent[$od] = $md;
      }
    }
    // Geen lussen.
    foreach (array_keys($parent) as $d) {
      $seen = [$d => true]; $c = $parent[$d];
      while ($c !== null && isset($parent[$c])) {
        if (isset($seen[$c])) { unset($parent[$d]); break; }
        $seen[$c] = true; $c = $parent[$c];
      }
    }

    // Titels.
    $home_title = self::title_from($home_html);
    $title_of = [];
    foreach (array_keys($real) as $d) {
      if (isset($virtual[$d])) { $title_of[$d] = $virtual[$d]; continue; }
      $t = $labels[$d] ?? ($known_titles[$d] ?? '');
      if ($t === '' && isset($html_of[$d])) $t = self::title_from($html_of[$d]);
      if ($t === '' || mb_strtolower($t) === mb_strtolower($home_title)) $t = self::humanize(basename($d));
      $title_of[$d] = $t;
    }

    // Volgorde: ouders voor kinderen, menu-items eerst.
    $depth = function ($d) use (&$parent) { $n = 0; while (isset($parent[$d]) && $n < 6) { $d = $parent[$d]; $n++; } return $n; };
    $list = array_keys($real);
    usort($list, function ($a, $b) use ($depth, $nav_items) {
      return $depth($a) <=> $depth($b) ?: ((isset($nav_items[$a]) ? 0 : 1) <=> (isset($nav_items[$b]) ? 0 : 1)) ?: strcmp($a, $b);
    });

    $slug_of = ['/' => 'home'];
    $taken = ['home' => true];
    $pages = [];
    foreach ($list as $d) {
      $segments = explode('/', trim($d, '/'));
      $last = end($segments);
      $slug = sanitize_title($last) ?: 'pagina';
      if (isset($taken[$slug])) $slug = sanitize_title(implode('-', $segments));
      $n = 2; $b = $slug;
      while (isset($taken[$slug])) $slug = $b . '-' . $n++;
      $taken[$slug] = true;
      $slug_of[$d] = $slug;
    }
    foreach ($list as $d) {
      $pages[] = [
        'slug' => $slug_of[$d],
        'title' => $title_of[$d],
        'parent_slug' => isset($parent[$d]) ? ($slug_of[$parent[$d]] ?? 'home') : 'home',
        'url' => $real[$d] ? $base . $real[$d] : '',
        'group' => isset($virtual[$d]),
        'type' => $type_of[$d] ?? '',
        'in_menu' => isset($nav_items[$d]),
      ];
    }

    $collapsed_out = [];
    foreach ($collapsed_parents as $pp => $n) {
      if (isset($slug_of[$pp])) $collapsed_out[] = ['path' => $pp, 'slug' => $slug_of[$pp], 'items' => $n, 'type' => ''];
    }
    foreach ($collapsed_types as $t => $n) {
      $ov = $overview_for($t);
      $collapsed_out[] = ['path' => $ov ?? '/' . $t, 'slug' => $ov ? ($slug_of[$ov] ?? '') : '', 'items' => $n, 'type' => $t];
    }
    return ['pages' => $pages, 'collapsed' => $collapsed_out, 'language' => $lang];
  }

  /**
   * Haalt meerdere adressen tegelijk op (in groepjes).
   * @return array gevraagde url => ['body' => string, 'final' => string]
   */
  private static function fetch_many(array $urls, int $timeout = 10): array {
    $out = [];
    $urls = array_values(array_unique(array_filter($urls, 'wp_http_validate_url')));
    if (!$urls) return $out;
    foreach (array_chunk($urls, 20) as $chunk) {
      $done = false;
      if (class_exists('WpOrg\Requests\Requests')) {
        $requests = [];
        foreach ($chunk as $i => $u) {
          $requests[$i] = ['url' => $u, 'type' => 'GET', 'headers' => ['User-Agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)']];
        }
        try {
          $responses = \WpOrg\Requests\Requests::request_multiple($requests, ['timeout' => $timeout, 'connect_timeout' => 6, 'redirects' => 3]);
          foreach ($responses as $i => $r) {
            if ($r instanceof \WpOrg\Requests\Response && $r->status_code === 200 && wp_http_validate_url((string) $r->url)) {
              $out[$requests[$i]['url']] = ['body' => (string) $r->body, 'final' => (string) $r->url];
            }
          }
          $done = true;
        } catch (\Throwable $e) {
          $done = false;
        }
      }
      if (!$done) {
        foreach ($chunk as $u) {
          $res = self::get($u, $timeout);
          if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200) $out[$u] = ['body' => (string) wp_remote_retrieve_body($res), 'final' => $u];
        }
      }
    }
    return $out;
  }
}
