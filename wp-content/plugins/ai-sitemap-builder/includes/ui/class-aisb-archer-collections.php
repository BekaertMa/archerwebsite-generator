<?php
if (!defined('ABSPATH')) exit;

/**
 * Lijsten van items op de gepubliceerde site (occasies, realisaties, projecten, vacatures ...) en het privacybeleid.
 *
 * Een overzichtspagina met een detailpagina eronder in de sitemap ("Occasies" > "Wagen detail") wordt bij het
 * publiceren een inhoudstype in Archer Content Types (blueprint-plugin):
 * - het inhoudstype zelf, met velden uit een voorbeeld per sector en uit de [plaatshouders] van de detailpagina;
 * - een Bricks-sjabloon voor één item, gemaakt uit de detailpagina (titel, foto, korte tekst en velden dynamisch);
 * - de kaarten op de overzichtspagina worden een query loop over dat type: elke kaart linkt naar haar item;
 * - de kaarten van de wireframe worden de eerste items (de klant past ze aan of vult aan in WordPress);
 * - de detailpagina zelf gaat offline (concept): het sjabloon neemt haar plaats in.
 * Daarnaast een pagina "Privacybeleid" waar de formulieren naar linken (tenzij de sitemap er al een heeft).
 *
 * Volgorde bij het publiceren (zie class-aisb-instawp.php):
 *   prepare()  vóór de Bricks-injectie: overzicht ombouwen, items en sjabloon klaarzetten;
 *   extra()    na de injectie: type, sjabloon en privacypagina op de site, geeft hun Bricks-injecties terug;
 *   finish()   daarna: items aanmaken, detailpagina's naar concept.
 */
class AISB_Archer_Collections {

  /** @var array<int,array> Per project: wat prepare() klaarzette voor extra() en finish(). */
  private static $stash = [];

  /* ───────────────────────── Herkennen ───────────────────────── */

  /** Overzicht en detail uit de sitemap: [['overview' => slug, 'detail' => slug, 'def' => definitie], ...]. */
  public static function pairs(int $pid): array {
    if (!class_exists('AISB_Archer_Chrome')) return [];
    $pages = AISB_Archer_Chrome::pages(AISB_Archer_Chrome::sitemap($pid));
    $out = [];
    $seen = [];
    foreach ($pages as $slug => $p) {
      $is_detail = !empty($p['detail']) || preg_match('/(^|-)(detail|detailpagina)(-|$)/', $slug);
      if (!$is_detail || $p['parent'] === '' || $p['parent'] === 'home' || !isset($pages[$p['parent']])) continue;
      if (isset($seen[$p['parent']])) continue;
      $seen[$p['parent']] = true;
      $out[] = ['overview' => $p['parent'], 'detail' => $slug, 'def' => self::definition($pid, $pages[$p['parent']], $p)];
    }
    return $out;
  }

  /** Taal van het project: nl, fr of en. */
  public static function lang(int $pid): string {
    $langs = json_decode((string) get_post_meta($pid, 'aisb_project_languages', true), true);
    $first = strtolower((string) (is_array($langs) && $langs ? reset($langs) : 'Dutch'));
    return preg_match('/^(fr|french|fran)/', $first) ? 'fr' : (preg_match('/^(en|english|engels)/', $first) ? 'en' : 'nl');
  }

  private static function key_from(string $text, int $max): string {
    $k = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', remove_accents(str_replace(["'", '’'], '', $text))));
    return substr(trim($k, '_'), 0, $max);
  }

  /** Enkelvoud uit een meervoud (Occasies → Occasie, Projecten → Project, Realisaties → Realisatie). */
  private static function singular(string $plural): string {
    $p = trim($plural);
    if (preg_match('/ies$/u', $p)) return mb_substr($p, 0, -1);
    if (preg_match('/[^aeiou]en$/u', $p)) return mb_substr($p, 0, -2);
    if (preg_match('/s$/u', $p)) return mb_substr($p, 0, -1);
    return $p;
  }

  /** Velden per soort lijst, in de taal van de site. */
  private static function preset(string $kind, string $lang): array {
    $t = [
      'nl' => ['brand' => 'Merk', 'model' => 'Model', 'year' => 'Bouwjaar', 'km' => 'Kilometerstand', 'fuel' => 'Brandstof', 'gear' => 'Transmissie', 'power' => 'Vermogen (pk)', 'price' => 'Prijs', 'photos' => "Foto's", 'place' => 'Plaats', 'date' => 'Datum', 'client' => 'Klant', 'name' => 'Naam', 'company' => 'Bedrijf', 'role' => 'Functie', 'score' => 'Score', 'location' => 'Locatie', 'contract' => 'Contract', 'apply' => 'Link om te solliciteren', 'email' => 'E-mail', 'phone' => 'Telefoon', 'rooms' => 'Slaapkamers', 'area' => 'Bewoonbare oppervlakte (m²)', 'epc' => 'EPC-label', 'city' => 'Gemeente',
        'fuels' => ['Benzine', 'Diesel', 'Hybride', 'Elektrisch', 'LPG'], 'gears' => ['Manueel', 'Automaat'], 'contracts' => ['Voltijds', 'Deeltijds', 'Student', 'Freelance']],
      'fr' => ['brand' => 'Marque', 'model' => 'Modèle', 'year' => 'Année', 'km' => 'Kilométrage', 'fuel' => 'Carburant', 'gear' => 'Transmission', 'power' => 'Puissance (ch)', 'price' => 'Prix', 'photos' => 'Photos', 'place' => 'Lieu', 'date' => 'Date', 'client' => 'Client', 'name' => 'Nom', 'company' => 'Entreprise', 'role' => 'Fonction', 'score' => 'Note', 'location' => 'Lieu', 'contract' => 'Contrat', 'apply' => 'Lien pour postuler', 'email' => 'E-mail', 'phone' => 'Téléphone', 'rooms' => 'Chambres', 'area' => 'Surface habitable (m²)', 'epc' => 'PEB', 'city' => 'Commune',
        'fuels' => ['Essence', 'Diesel', 'Hybride', 'Électrique', 'LPG'], 'gears' => ['Manuelle', 'Automatique'], 'contracts' => ['Temps plein', 'Temps partiel', 'Étudiant', 'Freelance']],
    ];
    $w = $t[$lang] ?? $t['nl'];
    $f = fn($key, $label, $type = 'text', $options = []) => ['key' => $key, 'label' => $label, 'type' => $type, 'options' => $options];
    switch ($kind) {
      case 'wagen':
        return [$f('merk', $w['brand']), $f('model', $w['model']), $f('bouwjaar', $w['year'], 'number'), $f('kilometerstand', $w['km'], 'number'), $f('brandstof', $w['fuel'], 'select', $w['fuels']), $f('transmissie', $w['gear'], 'select', $w['gears']), $f('vermogen', $w['power'], 'number'), $f('prijs', $w['price'], 'price'), $f('galerij', $w['photos'], 'gallery')];
      case 'woning':
        return [$f('prijs', $w['price'], 'price'), $f('gemeente', $w['city']), $f('slaapkamers', $w['rooms'], 'number'), $f('oppervlakte', $w['area'], 'number'), $f('epc', $w['epc'], 'select', ['A+', 'A', 'B', 'C', 'D', 'E', 'F']), $f('galerij', $w['photos'], 'gallery')];
      case 'referentie':
        return [$f('naam', $w['name']), $f('bedrijf', $w['company']), $f('functie', $w['role']), $f('score', $w['score'], 'select', ['5', '4', '3', '2', '1']), $f('logo', 'Logo', 'image')];
      case 'vacature':
        return [$f('locatie', $w['location']), $f('contract', $w['contract'], 'select', $w['contracts']), $f('solliciteren', $w['apply'], 'url')];
      case 'team':
        return [$f('functie', $w['role']), $f('email', $w['email'], 'email'), $f('telefoon', $w['phone'], 'phone'), $f('linkedin', 'LinkedIn', 'url')];
      case 'realisatie':
        return [$f('plaats', $w['place']), $f('datum', $w['date'], 'date'), $f('klant', $w['client']), $f('galerij', $w['photos'], 'gallery')];
      default:
        return [$f('plaats', $w['place']), $f('datum', $w['date'], 'date'), $f('galerij', $w['photos'], 'gallery')];
    }
  }

  private static function kind(string $text): string {
    $t = mb_strtolower($text);
    $map = [
      'wagen' => '/wagen|auto|occasie|occasion|voertuig|vehicul|véhicul|\bcars?\b/u',
      'woning' => '/woning|huis|huizen|pand|immo|appartement|\bbien|maison/u',
      'referentie' => '/referentie|getuig|review|témoign|testimon|klantverha/u',
      'vacature' => '/vacature|\bjobs?\b|offre d.emploi|emploi|career|carrière/u',
      'team' => '/\bteam|medewerker|équipe/u',
      'realisatie' => '/realisatie|project|portfolio|ons werk|réalisation|projet|cases?\b/u',
    ];
    foreach ($map as $k => $re) if (preg_match($re, $t)) return $k;
    return '';
  }

  private static function icon(string $kind): string {
    return ['wagen' => 'dashicons-car', 'woning' => 'dashicons-admin-home', 'referentie' => 'dashicons-format-quote', 'vacature' => 'dashicons-businessman', 'team' => 'dashicons-groups', 'realisatie' => 'dashicons-portfolio'][$kind] ?? 'dashicons-portfolio';
  }

  /** Het inhoudstype voor een overzicht en zijn detailpagina. */
  private static function definition(int $pid, array $over, array $detail): array {
    $lang = self::lang($pid);
    $plural = trim((string) $over['title']);
    $singular = trim((string) preg_replace('/\b(detail|detailpagina|pagina|page|template|sjabloon|fiche)\b/iu', '', (string) ($detail['title'] ?: $detail['label'])));
    $singular = trim((string) preg_replace('/\s{2,}/', ' ', $singular), " -–:");
    if ($singular === '' || mb_strtolower($singular) === mb_strtolower($plural)) $singular = self::singular($plural);
    $kind = self::kind($plural . ' ' . $singular . ' ' . (string) $over['slug']);
    $key = self::key_from($singular, 20);
    if ($key === '' || in_array($key, ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'product', 'order', 'action', 'author', 'theme'], true)) $key = 'item_' . substr($key, 0, 15);
    return [
      'key' => $key,
      'singular' => $singular,
      'plural' => $plural,
      'slug' => (string) $over['slug'],
      'icon' => self::icon($kind),
      'archive' => false,
      'supports' => ['title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'revisions'],
      'fields' => self::preset($kind, $lang),
      'taxonomies' => [],
      'source' => 'archer',
      'kind' => $kind,
    ];
  }

  /* ───────────────────────── Bricks-knopen ───────────────────────── */

  private static function index(array $nodes): array {
    $ix = [];
    foreach ($nodes as $i => $n) if (is_array($n) && isset($n['id'])) $ix[(string) $n['id']] = $i;
    return $ix;
  }

  /** Ids in de volgorde op de pagina, vanaf $root (of alle wortels). */
  private static function order(array $nodes, ?string $root = null): array {
    $ix = self::index($nodes);
    $out = [];
    $walk = function (string $id) use (&$walk, &$out, $nodes, $ix) {
      if (!isset($ix[$id]) || in_array($id, $out, true)) return;
      $out[] = $id;
      foreach ((array) ($nodes[$ix[$id]]['children'] ?? []) as $c) $walk((string) $c);
    };
    if ($root !== null) { $walk($root); return $out; }
    foreach ($nodes as $n) {
      if (!is_array($n) || !isset($n['id'])) continue;
      $p = (string) ($n['parent'] ?? '0');
      if ($p === '0' || $p === '' || !isset($ix[$p])) $walk((string) $n['id']);
    }
    return $out;
  }

  /** Wortelsecties met inhoud (zonder de header en footer die de publicatie mee in de inhoud zet). */
  private static function content_roots(array $nodes): array {
    $roots = [];
    foreach ($nodes as $n) {
      if (!is_array($n) || (string) ($n['parent'] ?? '0') !== '0' || ($n['name'] ?? '') !== 'section') continue;
      $cls = ' ' . (string) ($n['settings']['_cssClasses'] ?? '') . ' ';
      if (strpos($cls, ' archer-header ') !== false || strpos($cls, ' archer-footer ') !== false) continue;
      $roots[] = (string) $n['id'];
    }
    return $roots;
  }

  /** Header en footer van een pagina: uit de aparte velden, anders de secties met archer-header/-footer. */
  private static function chrome_parts(array $payload): array {
    $header = (array) ($payload['header'] ?? []);
    $footer = (array) ($payload['footer'] ?? []);
    if ($header && $footer) return [$header, $footer];
    $nodes = (array) ($payload['content'] ?? []);
    foreach (['header' => 'archer-header', 'footer' => 'archer-footer'] as $part => $cls) {
      if (${$part}) continue;
      foreach ($nodes as $n) {
        if (!is_array($n) || (string) ($n['parent'] ?? '0') !== '0') continue;
        if (strpos(' ' . (string) ($n['settings']['_cssClasses'] ?? '') . ' ', ' ' . $cls . ' ') === false) continue;
        $ids = self::order($nodes, (string) $n['id']);
        $keep = array_flip($ids);
        ${$part} = array_values(array_filter($nodes, fn($x) => is_array($x) && isset($keep[(string) ($x['id'] ?? '')])));
        break;
      }
    }
    return [$header, $footer];
  }

  private static function plain($t): string {
    return trim(html_entity_decode(wp_strip_all_tags((string) $t), ENT_QUOTES, 'UTF-8'));
  }

  private static function is_text(array $n): bool {
    return in_array($n['name'] ?? '', ['text-basic', 'text', 'rich-text'], true);
  }

  private static function sig(array $n): string {
    $s = (array) ($n['settings'] ?? []);
    $cls = implode(',', (array) ($s['_cssGlobalClasses'] ?? [])) . '|' . (string) ($s['_cssClasses'] ?? '');
    return (string) ($n['name'] ?? '') . '|' . preg_replace('/\s+/', ' ', $cls);
  }

  private static function img_url(array $n): string {
    $img = $n['settings']['image'] ?? null;
    return is_array($img) ? trim((string) ($img['url'] ?? '')) : '';
  }

  /** Elementen en hun kinderen weghalen (ook uit de children van hun ouder). */
  private static function remove(array $nodes, array $ids): array {
    $drop = array_flip($ids);
    do {
      $more = false;
      foreach ($nodes as $n) {
        if (isset($n['id'], $n['parent']) && isset($drop[(string) $n['parent']]) && !isset($drop[(string) $n['id']])) { $drop[(string) $n['id']] = 1; $more = true; }
      }
    } while ($more);
    $out = [];
    foreach ($nodes as $n) {
      if (isset($drop[(string) ($n['id'] ?? '')])) continue;
      if (!empty($n['children'])) $n['children'] = array_values(array_filter((array) $n['children'], fn($c) => !isset($drop[(string) $c])));
      $out[] = $n;
    }
    return $out;
  }

  /**
   * Het kaartenraster van een overzicht: de ouder met de meeste gelijke kinderen die elk een titel hebben
   * (en meestal een foto). Niet in de eerste sectie (de hero).
   * @return array{0:string,1:string[]}|null [ouder-id, kaart-ids]
   */
  private static function find_cards(array $nodes): ?array {
    $ix = self::index($nodes);
    $roots = self::content_roots($nodes);
    $best = null;
    foreach (array_slice($roots, 1) as $root) {
      foreach (self::order($nodes, $root) as $pid) {
        $kids = array_values(array_filter(array_map('strval', (array) ($nodes[$ix[$pid]]['children'] ?? [])), fn($c) => isset($ix[$c])));
        if (count($kids) < 2) continue;
        $groups = [];
        foreach ($kids as $c) $groups[self::sig($nodes[$ix[$c]])][] = $c;
        foreach ($groups as $members) {
          if (count($members) < 2) continue;
          $ok = 0;
          $img = 0;
          foreach ($members as $m) {
            $h = false;
            foreach (self::order($nodes, $m) as $d) {
              $nm = (string) ($nodes[$ix[$d]]['name'] ?? '');
              if ($nm === 'heading') $h = true;
              if ($nm === 'image') $img++;
            }
            if ($h) $ok++;
          }
          if ($ok !== count($members)) continue;
          $score = count($members) * 10 + ($img >= count($members) ? 5 : 0);
          if (!$best || $score > $best[2]) $best = [$pid, $members, $score];
        }
      }
      if ($best) break; // de eerste sectie met een raster
    }
    return $best ? [$best[0], $best[1]] : null;
  }

  /** Overzicht ombouwen: de kaarten worden een query loop over $type; geeft [nodes, items]. */
  public static function to_loop(array $nodes, string $type, int $pid, string $overview, string $singular = '', string $plural = ''): array {
    $found = self::find_cards($nodes);
    if (!$found) return [$nodes, []];
    [$parent, $cards] = $found;
    $ix = self::index($nodes);
    $items = [];
    foreach ($cards as $n => $cid) {
      $title = '';
      $excerpt = '';
      $image = '';
      foreach (self::order($nodes, $cid) as $d) {
        $node = $nodes[$ix[$d]];
        $nm = (string) ($node['name'] ?? '');
        if ($nm === 'heading' && $title === '') $title = self::plain($node['settings']['text'] ?? '');
        elseif (self::is_text($node) && $excerpt === '') $excerpt = self::plain($node['settings']['text'] ?? '');
        elseif ($nm === 'image' && $image === '') $image = self::img_url($node);
      }
      if ($title === '') continue;
      // Een plaatshouder als titel ("[Merk en model]"): een duidelijk voorbeelditem ("Wagen 1") dat de klant hernoemt.
      if (preg_match('/^\s*\[[^\]]+\]/u', $title) || $title === '') $title = ($singular !== '' ? $singular : ucfirst($type)) . ' ' . ($n + 1);
      if (preg_match('/^\s*\[[^\]]+\]\s*$/u', $excerpt)) $excerpt = '';
      $items[] = ['uid' => $pid . '-' . $overview . '-' . ($n + 1), 'title' => $title, 'excerpt' => $excerpt, 'image' => preg_match('#^https?://#', $image) ? $image : '', 'status' => 'publish', 'menu_order' => $n];
    }
    // De eerste kaart wordt het voorbeeld voor elk item; de andere gaan weg.
    $first = $cards[0];
    $nodes = self::remove($nodes, array_slice($cards, 1));
    $ix = self::index($nodes);
    $nodes[$ix[$first]]['settings']['hasLoop'] = true;
    $nodes[$ix[$first]]['settings']['query'] = ['objectType' => 'post', 'post_type' => [$type], 'posts_per_page' => 24, 'orderby' => 'menu_order', 'order' => 'ASC'];
    $cls = trim((string) ($nodes[$ix[$first]]['settings']['_cssClasses'] ?? ''));
    $nodes[$ix[$first]]['settings']['_cssClasses'] = trim($cls . ' archer-keep archer-loop-item');
    $nodes = self::bind($nodes, $first, true);
    // Plaatshouders buiten de lijst ("[Merk en model]" als titel boven de lijst): de naam van de lijst,
    // of de tekst zonder haakjes. Een bezoeker mag geen [plaatshouder] zien.
    // Een tweede, gelijke groep kaarten naast de groep met de lijst (nog eens "[Merk en model]"-kaarten):
    // weg, de lijst toont alle items al.
    $ix = self::index($nodes);
    $drop = [];
    $up = $parent;
    while ($up !== '' && isset($ix[$up])) {
      $par = (string) ($nodes[$ix[$up]]['parent'] ?? '');
      if ($par === '' || $par === '0' || !isset($ix[$par])) break;
      $sig = self::sig($nodes[$ix[$up]]);
      foreach ((array) ($nodes[$ix[$par]]['children'] ?? []) as $sib) {
        $sib = (string) $sib;
        if ($sib === $up || !isset($ix[$sib]) || self::sig($nodes[$ix[$sib]]) !== $sig) continue;
        foreach (self::order($nodes, $sib) as $d) {
          if (preg_match('/\[[^\]]{2,40}\]/u', (string) ($nodes[$ix[$d]]['settings']['text'] ?? ''))) { $drop[] = $sib; break; }
        }
      }
      if ($drop) break;
      $up = $par;
    }
    if ($drop) {
      $nodes = self::remove($nodes, $drop);
      // De groep die blijft, neemt de hele breedte van het raster (anders een lege kolom ernaast).
      $ix = self::index($nodes);
      // Vaste id: Bricks geeft bij het publiceren nieuwe element-id's en vervangt %root% hier niet.
      if (isset($ix[$up])) {
        $css_id = trim((string) ($nodes[$ix[$up]]['settings']['_cssId'] ?? '')) ?: 'archer-lijst-' . $type;
        $nodes[$ix[$up]]['settings']['_cssId'] = $css_id;
        $nodes[$ix[$up]]['settings']['_cssCustom'] = trim((string) ($nodes[$ix[$up]]['settings']['_cssCustom'] ?? '') . "\n#" . $css_id . " {grid-column: 1 / -1; max-width: none;}");
      }
    }
    $loop = array_flip(self::order($nodes, $first));
    $used = [];
    foreach ($nodes as $node) if (($node['name'] ?? '') === 'heading') $used[mb_strtolower(self::plain($node['settings']['text'] ?? ''))] = 1;
    $gone = [];
    foreach ($nodes as $i => $node) {
      if (isset($loop[(string) ($node['id'] ?? '')])) continue;
      $t = $node['settings']['text'] ?? null;
      if (!is_string($t) || !preg_match('/\[[^\]]{2,40}\]/u', $t)) continue;
      if (($node['name'] ?? '') === 'heading' && preg_match('/^\s*\[[^\]]+\]\s*$/u', self::plain($t)) && $plural !== '') {
        // Staat de naam van de lijst al als titel op de pagina, dan geen tweede keer dezelfde titel.
        if (isset($used[mb_strtolower($plural)])) { $gone[] = (string) $node['id']; continue; }
        $t = $plural;
        $used[mb_strtolower($plural)] = 1;
      } else {
        $t = preg_replace('/\[([^\]]{2,40})\]/u', '$1', $t);
      }
      $nodes[$i]['settings']['text'] = $t;
    }
    if ($gone) $nodes = self::remove($nodes, $gone);
    return [$nodes, $items];
  }

  /**
   * Dynamische gegevens in een kaart of sjabloon: de eerste titel wordt de naam van het item, de eerste tekst de
   * korte omschrijving, de eerste foto de uitgelichte afbeelding, knoppen linken naar het item ($link) en
   * [plaatshouders] worden de velden van het type ($fields: plaatshouder => veldsleutel).
   */
  private static function bind(array $nodes, string $root, bool $link, array $fields = []): array {
    $ix = self::index($nodes);
    $title = $text = $image = false;
    $has_link = false;
    $drop = [];
    $url = ['type' => 'meta', 'useDynamicData' => '{post_url}'];
    foreach (self::order($nodes, $root) as $d) {
      $i = $ix[$d];
      $n = $nodes[$i];
      $nm = (string) ($n['name'] ?? '');
      $s = (array) ($n['settings'] ?? []);
      if ($nm === 'heading' && !$title) {
        $s['text'] = '{post_title}';
        $title = true;
        if ($link && isset($s['link'])) { $s['link'] = $url; $has_link = true; }
      } elseif (self::is_text($n) && !$text && empty($n['children'])) {
        // Een tekst met een [plaatshouder] is een veld, geen omschrijving.
        if (!preg_match('/\[[^\]]+\]/u', (string) ($s['text'] ?? ''))) {
          $s['text'] = '{post_excerpt}';
          $text = true;
        }
      } elseif (self::is_text($n) && $link && empty($n['children']) && !preg_match('/\[[^\]]+\]/u', (string) ($s['text'] ?? ''))) {
        $drop[] = $d; // in een kaart: geen vaste tweede tekst die bij elk item herhaald wordt
      } elseif ($nm === 'image') {
        if (!$image) {
          $s['image'] = ['useDynamicData' => '{featured_image}', 'size' => 'large'];
          $s['_cssClasses'] = trim((string) ($s['_cssClasses'] ?? '') . ' archer-keep');
          $image = true;
        } elseif ($link) {
          $drop[] = $d;
        }
      } elseif (in_array($nm, ['button', 'text-link'], true) && $link) {
        $s['link'] = $url;
        $has_link = true;
      }
      if (isset($s['text']) && is_string($s['text']) && $fields) {
        $s['text'] = preg_replace_callback('/\[([^\]]{2,40})\]/u', function ($m) use ($fields) {
          $k = self::key_from($m[1], 40);
          return isset($fields[$k]) ? '{act_' . $fields[$k] . '}' : $m[0];
        }, $s['text']);
      }
      $nodes[$i]['settings'] = $s;
    }
    if ($link && !$has_link && isset($ix[$root])) {
      // Geen knop in de kaart: de hele kaart wordt de link.
      $nodes[$ix[$root]]['settings']['tag'] = 'a';
      $nodes[$ix[$root]]['settings']['link'] = $url;
    }
    return $drop ? self::remove($nodes, $drop) : $nodes;
  }

  /* ───────────────────────── Publiceren ───────────────────────── */

  /** Vóór de Bricks-injectie: overzichten ombouwen, sjablonen en items klaarzetten. */
  public static function prepare(int $pid, array $injections): array {
    self::$stash[$pid] = ['collections' => [], 'home' => null];
    $by = [];
    foreach ($injections as $k => $inj) $by[(string) ($inj['slug'] ?? '')] = $k;
    if (isset($by['home'])) self::$stash[$pid]['home'] = $injections[$by['home']]['payload'] ?? null;
    foreach (self::pairs($pid) as $pair) {
      $def = $pair['def'];
      $o = $by[$pair['overview']] ?? null;
      $d = $by[$pair['detail']] ?? null;
      if ($o === null || $d === null) continue;
      $orig = (array) ($injections[$o]['payload']['content'] ?? []);
      [$content, $items] = self::to_loop($orig, $def['key'], $pid, $pair['overview'], (string) ($def['singular'] ?? ''), (string) ($def['plural'] ?? ''));
      $injections[$o]['payload']['content'] = $content;
      $dp = (array) $injections[$d]['payload'];
      // Voorbeelditems zonder foto: de foto uit de hero van de detailpagina (anders zakt die hero in elkaar).
      $sample = '';
      $droots = self::content_roots((array) ($dp['content'] ?? []));
      if ($droots) {
        $dix = self::index((array) $dp['content']);
        foreach (self::order((array) $dp['content'], $droots[0]) as $did) {
          $u = self::img_url($dp['content'][$dix[$did]]);
          if (($dp['content'][$dix[$did]]['name'] ?? '') === 'image' && preg_match('#^https?://#', $u)) { $sample = $u; break; }
        }
      }
      if ($sample !== '') foreach ($items as &$it) if (empty($it['image'])) $it['image'] = $sample;
      unset($it);
      $tpl = self::to_template_sections((array) ($dp['content'] ?? []), $def);
      $template = array_merge((array) ($dp['header'] ?? []), $tpl, (array) ($dp['footer'] ?? []));
      self::$stash[$pid]['collections'][] = ['def' => $def, 'items' => $items, 'template' => $template, 'overview' => $pair['overview'], 'detail' => $pair['detail'], 'orig' => $orig, 'inj' => $o];
      error_log('[AISB] Archer: lijst "' . $def['plural'] . '" (' . $def['key'] . ') met ' . count($items) . ' item(s) en ' . count($def['fields']) . ' velden.');
    }
    return $injections;
  }

  /** Sjabloon uit de detailpagina: titel, tekst en foto dynamisch in de hero; [plaatshouders] overal velden. */
  private static function to_template_sections(array $nodes, array &$def): array {
    $roots = self::content_roots($nodes);
    if (!$roots) return $nodes;
    $ix = self::index($nodes);
    $skip = '';
    foreach (self::order($nodes, $roots[0]) as $d) if (($nodes[$ix[$d]]['name'] ?? '') === 'heading') { $skip = $d; break; }
    $have = [];
    foreach ($def['fields'] as $f) { $have[$f['key']] = $f['key']; $have[self::key_from($f['label'], 40)] = $f['key']; }
    foreach ($nodes as $n) {
      if (!is_array($n) || (string) ($n['id'] ?? '') === $skip) continue;
      $t = (string) ($n['settings']['text'] ?? '');
      if (!preg_match_all('/\[([^\]]{2,40})\]/u', $t, $m)) continue;
      foreach ($m[1] as $ph) {
        $k = self::key_from($ph, 40);
        if ($k === '' || isset($have[$k])) continue;
        $match = '';
        foreach ($def['fields'] as $f) if (strpos($k, $f['key']) !== false || strpos($f['key'], $k) !== false) { $match = $f['key']; break; }
        if ($match !== '') { $have[$k] = $match; continue; }
        $label = trim($ph);
        $def['fields'][] = ['key' => $k, 'label' => mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1), 'type' => 'text', 'options' => []];
        $have[$k] = $k;
      }
    }
    // Hero: naam, omschrijving en foto van het item.
    $nodes = self::bind($nodes, $roots[0], false, $have);
    // Andere secties: enkel de velden.
    $ix = self::index($nodes);
    foreach (array_slice($roots, 1) as $r) {
      foreach (self::order($nodes, $r) as $d) {
        $i = $ix[$d];
        $t = $nodes[$i]['settings']['text'] ?? null;
        if (!is_string($t) || strpos($t, '[') === false) continue;
        $nodes[$i]['settings']['text'] = preg_replace_callback('/\[([^\]]{2,40})\]/u', function ($m) use ($have) {
          $k = self::key_from($m[1], 40);
          return isset($have[$k]) ? '{act_' . $have[$k] . '}' : $m[0];
        }, $t);
      }
    }
    // Een tekst met velden ("{act_merk} · {act_bouwjaar}") verbergen zolang al die velden leeg zijn,
    // anders staan er losse puntjes en komma's. Bricks-voorwaarde: één set per veld (sets zijn OF).
    foreach ($nodes as $i => $n) {
      $t = $n['settings']['text'] ?? null;
      if (!is_string($t) || !preg_match_all('/\{act_([a-z0-9_]+)\}/', $t, $m)) continue;
      $sets = [];
      foreach (array_unique($m[1]) as $f) {
        $sets[] = [['id' => substr(md5($n['id'] . $f), 0, 6), 'key' => 'dynamic_data', 'compare' => 'empty_not', 'dynamic_data' => '{act_' . $f . '}']];
      }
      $nodes[$i]['settings']['_conditions'] = $sets;
    }
    return $nodes;
  }

  /** Slug van het privacybeleid: een juridische pagina uit de sitemap, anders de standaardpagina. */
  public static function privacy_slug(int $pid): string {
    if (class_exists('AISB_Archer_Chrome')) {
      foreach (AISB_Archer_Chrome::pages(AISB_Archer_Chrome::sitemap($pid)) as $slug => $p) {
        if (preg_match('/privacy|confidentialit|gdpr|avg/i', $slug)) return $slug;
      }
    }
    return self::lang($pid) === 'fr' ? 'politique-de-confidentialite' : (self::lang($pid) === 'en' ? 'privacy-policy' : 'privacybeleid');
  }

  /** Bestaat de privacypagina al in de sitemap (dan maakt de gewone publicatie ze)? */
  private static function privacy_in_sitemap(int $pid): bool {
    if (!class_exists('AISB_Archer_Chrome')) return false;
    return isset(AISB_Archer_Chrome::pages(AISB_Archer_Chrome::sitemap($pid))[self::privacy_slug($pid)]);
  }

  /** Bricks-inhoud van het privacybeleid: een sectie met titel, tekst en de cookietabel. */
  private static function privacy_nodes(int $pid): array {
    $lang = self::lang($pid);
    $c = class_exists('AISB_Archer_Site') ? AISB_Archer_Site::contact($pid) : ['address' => '', 'phone' => '', 'email' => ''];
    $name = esc_html((string) get_the_title($pid));
    $addr = esc_html((string) ($c['address'] ?? ''));
    $mail = esc_html((string) ($c['email'] ?? ''));
    $tel = esc_html((string) ($c['phone'] ?? ''));
    $who = $name . ($addr !== '' ? ', ' . $addr : '') . ($mail !== '' ? ', <a href="mailto:' . $mail . '">' . $mail . '</a>' : '') . ($tel !== '' ? ', ' . $tel : '');
    if ($lang === 'fr') {
      $title = 'Politique de confidentialité';
      $html = '<p>Cette politique explique quelles données personnelles nous traitons via ce site, pourquoi et quels sont vos droits.</p>'
        . '<h2>Responsable du traitement</h2><p>' . $who . '.</p>'
        . '<h2>Quelles données et pourquoi</h2><p>Lorsque vous remplissez un formulaire ou nous contactez, nous traitons les données que vous nous donnez (par exemple votre nom, votre adresse e-mail, votre numéro de téléphone et votre message) pour répondre à votre demande et, le cas échéant, préparer ou exécuter un contrat. La base juridique est votre demande (mesures précontractuelles ou contrat) et notre intérêt légitime à répondre aux questions.</p>'
        . '<h2>Durée de conservation</h2><p>Nous conservons vos données aussi longtemps que nécessaire pour traiter votre demande et, en cas de collaboration, pendant la durée imposée par la loi (par exemple pour la comptabilité).</p>'
        . '<h2>Partage</h2><p>Nous ne vendons pas vos données. Nous les partageons uniquement avec des sous-traitants qui nous aident à faire fonctionner ce site (hébergement, e-mail) et uniquement dans le cadre de ces services.</p>'
        . '<h2>Vos droits</h2><p>Vous pouvez à tout moment demander l’accès, la rectification ou la suppression de vos données, vous opposer à leur traitement ou demander leur transfert. Contactez-nous' . ($mail !== '' ? ' à <a href="mailto:' . $mail . '">' . $mail . '</a>' : '') . '. Vous pouvez aussi introduire une plainte auprès de l’Autorité de protection des données (www.autoriteprotectiondonnees.be).</p>'
        . '<h2>Cookies</h2><p>Ce site utilise uniquement les cookies strictement nécessaires, sauf si vous acceptez d’autres cookies. Vous pouvez modifier votre choix à tout moment via « Paramètres cookies ».</p>';
    } elseif ($lang === 'en') {
      $title = 'Privacy policy';
      $html = '<p>This policy explains which personal data we process through this website, why, and what your rights are.</p>'
        . '<h2>Controller</h2><p>' . $who . '.</p>'
        . '<h2>Which data and why</h2><p>When you fill in a form or contact us, we process the data you give us (such as your name, email address, phone number and message) to answer your request and, where relevant, to prepare or perform a contract. The legal basis is your request (pre-contractual steps or a contract) and our legitimate interest in answering questions.</p>'
        . '<h2>Retention</h2><p>We keep your data for as long as needed to handle your request and, if we work together, for as long as the law requires (for example for accounting).</p>'
        . '<h2>Sharing</h2><p>We do not sell your data. We only share it with processors that help us run this website (hosting, email), and only for those services.</p>'
        . '<h2>Your rights</h2><p>You can ask at any time for access to, correction or deletion of your data, object to its processing or ask for a copy. Contact us' . ($mail !== '' ? ' at <a href="mailto:' . $mail . '">' . $mail . '</a>' : '') . '. You can also lodge a complaint with the Data Protection Authority.</p>'
        . '<h2>Cookies</h2><p>This website only uses strictly necessary cookies unless you accept others. You can change your choice at any time through "Cookie settings".</p>';
    } else {
      $title = 'Privacybeleid';
      $html = '<p>Dit privacybeleid legt uit welke persoonsgegevens wij via deze website verwerken, waarom, en welke rechten u hebt.</p>'
        . '<h2>Verantwoordelijke</h2><p>' . $who . '.</p>'
        . '<h2>Welke gegevens en waarom</h2><p>Als u een formulier invult of contact met ons opneemt, verwerken wij de gegevens die u ons geeft (zoals uw naam, e-mailadres, telefoonnummer en bericht) om uw vraag te beantwoorden en, als dat van toepassing is, een overeenkomst voor te bereiden of uit te voeren. De rechtsgrond is uw vraag (precontractuele maatregelen of een overeenkomst) en ons gerechtvaardigd belang om vragen te beantwoorden.</p>'
        . '<h2>Hoe lang</h2><p>Wij bewaren uw gegevens zolang dat nodig is om uw vraag af te handelen en, als we samenwerken, zolang de wet dat vraagt (bijvoorbeeld voor de boekhouding).</p>'
        . '<h2>Delen</h2><p>Wij verkopen uw gegevens niet. Wij delen ze enkel met verwerkers die ons helpen om deze website te laten werken (hosting, e-mail), en enkel voor die diensten.</p>'
        . '<h2>Uw rechten</h2><p>U kunt altijd vragen om uw gegevens in te zien, te verbeteren of te wissen, u verzetten tegen de verwerking of een kopie vragen. Neem daarvoor contact met ons op' . ($mail !== '' ? ' via <a href="mailto:' . $mail . '">' . $mail . '</a>' : '') . '. U kunt ook een klacht indienen bij de Gegevensbeschermingsautoriteit (www.gegevensbeschermingsautoriteit.be).</p>'
        . '<h2>Cookies</h2><p>Deze website gebruikt enkel strikt noodzakelijke cookies, tenzij u andere cookies aanvaardt. U kunt uw keuze altijd wijzigen via "Cookie-instellingen".</p>';
    }
    $sid = 'apv' . substr(md5('s' . $pid), 0, 3);
    $cid = 'apv' . substr(md5('c' . $pid), 0, 3);
    $hid = 'apv' . substr(md5('h' . $pid), 0, 3);
    $tid = 'apv' . substr(md5('t' . $pid), 0, 3);
    $kid = 'apv' . substr(md5('k' . $pid), 0, 3);
    return [
      ['id' => $sid, 'name' => 'section', 'parent' => 0, 'children' => [$cid], 'settings' => ['_padding' => ['top' => '120', 'bottom' => '100'], '_cssClasses' => 'archer-legal'], 'label' => 'Privacybeleid'],
      ['id' => $cid, 'name' => 'container', 'parent' => $sid, 'children' => [$hid, $tid, $kid], 'settings' => ['_widthMax' => '760', '_rowGap' => '24']],
      ['id' => $hid, 'name' => 'heading', 'parent' => $cid, 'children' => [], 'settings' => ['text' => $title, 'tag' => 'h1']],
      ['id' => $tid, 'name' => 'text', 'parent' => $cid, 'children' => [], 'settings' => ['text' => $html]],
      ['id' => $kid, 'name' => 'shortcode', 'parent' => $cid, 'children' => [], 'settings' => ['shortcode' => '[archer_cc_cookie_policy]']],
    ];
  }

  /** De runtime van de startpagina (huisstijl, logo, beweging), voor pagina's die de publicatie zelf niet maakt. */
  private static function runtime_node(?array $home): array {
    foreach ((array) ($home['content'] ?? []) as $n) {
      if (is_array($n) && ($n['name'] ?? '') === 'code' && ($n['label'] ?? '') === 'AISB Publish Runtime') {
        $n['id'] = 'apvrun';
        return [$n];
      }
    }
    return [];
  }

  private static function rest(callable $request, string $method, string $url, array $cookies, string $nonce, $body = null): array {
    $args = ['method' => $method, 'timeout' => 60, 'cookies' => $cookies, 'headers' => ['X-WP-Nonce' => $nonce, 'Content-Type' => 'application/json']];
    if ($body !== null) $args['body'] = wp_json_encode($body);
    $r = $request($url, $args);
    if (is_wp_error($r)) return [0, null];
    return [(int) ($r['code'] ?? 0), json_decode((string) ($r['body'] ?? ''), true)];
  }

  /**
   * Vóór de Bricks-injectie, na prepare(): inhoudstypes, sjablonen en het privacybeleid op de site aanmaken.
   * Geeft de Bricks-injecties voor de sjablonen en de privacypagina terug (zelfde vorm als die van de pagina's).
   * Lukt het sjabloon niet (oudere Archer Content Types zonder /template), dan krijgt het overzicht zijn
   * oorspronkelijke kaarten terug in $injections: liever vaste kaarten dan een lijst zonder itempagina's.
   */
  public static function extra(int $pid, array &$injections, string $site_url, array $cookies, string $nonce, callable $request): array {
    $st = self::$stash[$pid] ?? ['collections' => [], 'home' => null];
    $site_url = untrailingslashit($site_url);
    $out = [];
    $home = is_array($st['home']) ? $st['home'] : [];
    $runtime = self::runtime_node($home);

    if ($st['collections']) {
      $defs = array_map(fn($c) => array_diff_key($c['def'], ['kind' => 1]), $st['collections']);
      [$code, $res] = self::rest($request, 'POST', $site_url . '/wp-json/archer-ct/v1/types', $cookies, $nonce, ['types' => $defs, 'replace' => true]);
      if ($code !== 200) {
        error_log('[AISB] Archer: inhoudstypes niet aangemaakt (HTTP ' . $code . '); staat Archer Content Types op de site?');
        foreach (self::$stash[$pid]['collections'] as $c) $injections[$c['inj']]['payload']['content'] = class_exists('AISB_Archer_Site') ? AISB_Archer_Site::unbracket($c['orig']) : $c['orig'];
        self::$stash[$pid]['collections'] = [];
      } else {
        foreach (self::$stash[$pid]['collections'] as $k => $c) {
          [$code, $tpl] = self::rest($request, 'POST', $site_url . '/wp-json/archer-ct/v1/template', $cookies, $nonce, ['type' => $c['def']['key'], 'title' => $c['def']['singular']]);
          $tid = (int) ($tpl['id'] ?? 0);
          if ($code !== 200 || !$tid) {
            error_log('[AISB] Archer: sjabloon voor ' . $c['def']['key'] . ' niet aangemaakt (HTTP ' . $code . '); het overzicht houdt zijn vaste kaarten.');
            $injections[$c['inj']]['payload']['content'] = class_exists('AISB_Archer_Site') ? AISB_Archer_Site::unbracket($c['orig']) : $c['orig'];
            continue;
          }
          self::$stash[$pid]['collections'][$k]['template_id'] = $tid;
          $out[] = ['post_id' => $tid, 'slug' => 'sjabloon-' . $c['def']['key'], 'payload' => ['content' => $c['template'], 'templateType' => 'content']];
        }
      }
    }

    // Privacybeleid (niet als de sitemap er zelf een heeft: dan publiceert de gewone publicatie die pagina).
    if (!self::privacy_in_sitemap($pid) && $home) {
      $slug = self::privacy_slug($pid);
      [$code, $found] = self::rest($request, 'GET', $site_url . '/wp-json/wp/v2/pages?slug=' . rawurlencode($slug) . '&status=publish,draft&context=edit', $cookies, $nonce);
      $id = ($code === 200 && is_array($found) && !empty($found[0]['id'])) ? (int) $found[0]['id'] : 0;
      $title = ['fr' => 'Politique de confidentialité', 'en' => 'Privacy policy'][self::lang($pid)] ?? 'Privacybeleid';
      if (!$id) {
        [$code, $made] = self::rest($request, 'POST', $site_url . '/wp-json/wp/v2/pages', $cookies, $nonce, ['title' => $title, 'slug' => $slug, 'status' => 'publish']);
        $id = (int) ($made['id'] ?? 0);
      }
      if ($id) {
        [$hdr, $ftr] = self::chrome_parts($home);
        $out[] = ['post_id' => $id, 'slug' => $slug, 'payload' => ['content' => array_merge($hdr, self::privacy_nodes($pid), $ftr, $runtime)]];
      } else {
        error_log('[AISB] Archer: privacypagina niet aangemaakt (HTTP ' . $code . ').');
      }
    }
    return $out;
  }

  /** Daarna: de items van elke lijst, en de detailpagina's offline (het sjabloon toont de items). */
  public static function finish(int $pid, array $published_pages, string $site_url, array $cookies, string $nonce, callable $request): void {
    $st = self::$stash[$pid] ?? ['collections' => []];
    $site_url = untrailingslashit($site_url);
    $ids = [];
    foreach ($published_pages as $p) $ids[(string) ($p['slug'] ?? '')] = (int) ($p['post_id'] ?? 0);
    foreach ($st['collections'] as $c) {
      if (empty($c['template_id'])) continue;
      if ($c['items']) {
        [$code, $res] = self::rest($request, 'POST', $site_url . '/wp-json/archer-ct/v1/items', $cookies, $nonce, ['type' => $c['def']['key'], 'items' => $c['items']]);
        error_log('[AISB] Archer: ' . count($c['items']) . ' item(s) voor ' . $c['def']['key'] . ' → HTTP ' . $code . (is_array($res) ? ' ' . wp_json_encode(array_count_values(array_column((array) ($res['items'] ?? []), 'result'))) : ''));
      }
      if (!empty($ids[$c['detail']])) {
        self::rest($request, 'POST', $site_url . '/wp-json/wp/v2/pages/' . $ids[$c['detail']], $cookies, $nonce, ['status' => 'draft']);
      }
    }
    unset(self::$stash[$pid]);
  }
}
