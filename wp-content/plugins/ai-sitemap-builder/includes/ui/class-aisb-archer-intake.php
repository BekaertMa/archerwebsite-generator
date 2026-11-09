<?php
if (!defined('ABSPATH')) exit;

/**
 * Intake-agent: het startscherm als gesprek in plaats van een formulier.
 *
 * De agent werkt een vaste agenda af in vijf blokken (de zaak, klanten en doel, vertrouwen,
 * pagina's, stijl en beeld). Eén vraag per beurt, met knoppen voor de gangbare antwoorden en
 * altijd ruimte voor eigen tekst. Voor sommige vragen toont de agent een kaart in het gesprek:
 * stijlen om uit te kiezen, een kleurpalet, een paginalijst om aan te vinken, of een vak om het
 * logo en eigen foto's op te laden. Naast het gesprek bouwt de browser een live voorbeeld op uit
 * de brief.
 *
 * Wat de klant vertelt, komt in een gestructureerde brief. Correcties ("eigenlijk is het Lokeren")
 * passen het veld aan. Wat al op een bestaande website staat, vraagt de agent niet opnieuw.
 * Als de verplichte velden er zijn, kan de klant altijd starten; de agent vraagt verder zolang
 * de klant wil. "Maak mijn website" maakt in één run de sitemap, zet de stijl, het logo en de
 * eigen foto's in de stijlgids (apply_design) en opent het scherm Ontwerp, dat de wireframes en
 * foto's afmaakt.
 *
 * AJAX:
 * - aisb_archer_intake_turn: één beurt (gesprek + brief in, antwoord + kaart + bijgewerkte brief uit);
 * - aisb_archer_intake_styles: de Archer-stijlen in volgorde van de brief (voor kaarten en voorbeeld);
 * - aisb_archer_intake_upload: logo of eigen foto's naar de mediabibliotheek;
 * - aisb_archer_intake_save: bewaart de bevestigde brief bij het nieuwe project en zet het ontwerp klaar.
 */
class AISB_Archer_Intake {

  /** Velden van de brief, met label voor de samenvatting en de chips. */
  public const FIELDS = [
    // 1. De zaak
    'business_name' => 'Naam',
    'existing_site' => 'Huidige website',
    'activity'      => 'Activiteit',
    'services'      => 'Diensten',
    'region'        => 'Regio',
    'service_area'  => 'Werkgebied',
    'founded'       => 'Sinds',
    'team'          => 'Team',
    'opening_hours' => 'Openingsuren',
    // 2. Klanten en doel
    'audience'      => 'Doelgroep',
    'main_goal'     => 'Doel van de website',
    'booking'       => 'Online boeken',
    'price_info'    => 'Prijzen',
    // 3. Vertrouwen
    'usp'           => 'Wat jullie onderscheidt',
    'reviews'       => 'Reviews',
    'references'    => 'Referenties',
    'certifications'=> 'Erkenningen',
    'guarantees'    => 'Garanties',
    // 4. Pagina's
    'must_pages'    => "Pagina's",
    'page_count'    => "Aantal pagina's",
    'page_notes'    => 'Per pagina',
    'faq_topics'    => 'Veelgestelde vragen',
    // 5. Stijl en beeld
    'tone'          => 'Toon',
    'languages'     => 'Talen',
    'style_id'      => 'Stijl',
    'style_mood'    => 'Sfeer',
    'brand_colors'  => 'Kleuren',
    'logo'          => 'Logo',
    'photos'        => "Foto's",
    'own_photos'    => "Eigen foto's",
    'examples'      => 'Voorbeeldsites',
    'dislikes'      => 'Liever niet',
    'contact'       => 'Contact',
  ];

  /** Blokken van de agenda (voor de voortgang in de interface). */
  public const BLOCKS = [
    'zaak'       => ['label' => 'De zaak',          'fields' => ['business_name', 'activity', 'services', 'region', 'founded', 'team', 'opening_hours']],
    'klant'      => ['label' => 'Klanten en doel',  'fields' => ['audience', 'main_goal', 'price_info']],
    'vertrouwen' => ['label' => 'Vertrouwen',       'fields' => ['usp', 'reviews', 'references', 'certifications']],
    'paginas'    => ['label' => "Pagina's",         'fields' => ['must_pages', 'page_notes']],
    'stijl'      => ['label' => 'Stijl en beeld',   'fields' => ['tone', 'languages', 'style_id', 'brand_colors', 'logo', 'photos', 'contact']],
  ];

  /** Lijstvelden. */
  private const LISTS = ['services', 'must_pages', 'languages', 'page_notes', 'faq_topics', 'references', 'certifications'];

  /** Zonder deze velden start de generatie niet. */
  public const REQUIRED = ['business_name', 'activity', 'audience', 'region', 'main_goal', 'tone', 'languages', 'style_id'];

  /** Na zoveel antwoorden rondt de agent af met wat hij heeft. */
  public const MAX_TURNS = 32;

  public function init(): void {
    add_action('wp_ajax_aisb_archer_intake_turn', [$this, 'ajax_turn']);
    add_action('wp_ajax_aisb_archer_intake_styles', [$this, 'ajax_styles']);
    add_action('wp_ajax_aisb_archer_intake_upload', [$this, 'ajax_upload']);
    add_action('wp_ajax_aisb_archer_intake_save', [$this, 'ajax_save']);
  }

  /* ───────────────────────── AJAX ───────────────────────── */

  public function ajax_turn(): void {
    $this->check();
    @set_time_limit(90);

    $messages = json_decode((string) wp_unslash($_POST['messages'] ?? '[]'), true);
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    // Keuzes uit een kaart (stijl, kleur, pagina's, upload) komen rechtstreeks in de brief.
    $set = json_decode((string) wp_unslash($_POST['set'] ?? '{}'), true);
    $messages = self::clean_messages(is_array($messages) ? $messages : []);
    $brief = self::clean_brief(is_array($brief) ? $brief : []);
    if (is_array($set) && $set) $brief = self::clean_brief(array_merge($brief, $set));
    if (!$messages) wp_send_json_error(['message' => 'Er is nog geen bericht.'], 400);

    // Een bestaande website lezen we één keer (homepagina), zodat de agent niet vraagt wat er al staat.
    $site_excerpt = '';
    if (!empty($brief['existing_site'])) $site_excerpt = self::site_excerpt((string) $brief['existing_site']);

    $user_turns = count(array_filter($messages, function ($m) { return $m['role'] === 'user'; }));
    $out = $this->ai_turn($messages, $brief, $site_excerpt, $user_turns);
    if (is_wp_error($out)) wp_send_json_error(['message' => $out->get_error_message()], 502);

    $new_brief = self::clean_brief(is_array($out['brief'] ?? null) ? array_merge($brief, $out['brief']) : $brief);
    // Vangnet: het antwoord op de vorige vraag komt altijd in de brief, ook als de AI het veld vergat.
    $answering = sanitize_key((string) wp_unslash($_POST['answering'] ?? ''));
    $last_user = '';
    for ($i = count($messages) - 1; $i >= 0; $i--) if ($messages[$i]['role'] === 'user') { $last_user = $messages[$i]['text']; break; }
    $new_brief = self::capture_answer($new_brief, $answering, $last_user, is_array($set) ? $set : []);
    // De sfeer volgt de gekozen stijl (voor de stijlen die bij de sfeer passen in de stap Ontwerp).
    if (!empty($new_brief['style_id']) && empty($new_brief['style_mood']) && class_exists('AISB_Archer_Styles')) {
      $s = AISB_Archer_Styles::get((string) $new_brief['style_id']);
      if ($s) $new_brief['style_mood'] = (string) $s['name'];
    }
    $missing = self::missing($new_brief);
    $done = !empty($out['done']) && !$missing;
    // Te lang bezig: afronden met wat er is, ontbrekende verplichte velden krijgen een standaard.
    if (!$done && $user_turns >= self::MAX_TURNS) {
      $new_brief = self::with_defaults($new_brief);
      $missing = self::missing($new_brief);
      $done = !$missing;
    }

    $options = array_values(array_filter(array_map(function ($o) {
      return mb_substr(trim(wp_strip_all_tags((string) $o)), 0, 60);
    }, (array) ($out['options'] ?? [])), 'strlen'));

    $ask = sanitize_key((string) ($out['ask'] ?? ''));
    $card = $done ? null : self::card_for($ask, $new_brief);

    wp_send_json_success([
      'reply'    => mb_substr(trim(wp_strip_all_tags((string) ($out['reply'] ?? ''))), 0, 700),
      'options'  => $done ? [] : array_slice($options, 0, 6),
      'multi'    => !$done && !empty($out['multi']),
      'input'    => in_array(($out['input'] ?? ''), ['text', 'url', 'none'], true) ? $out['input'] : 'text',
      'ask'      => $ask,
      'card'     => $card,
      'brief'    => $new_brief,
      'done'     => $done,
      // Verplichte velden zijn er: de klant mag starten, ook als de agent nog vragen heeft.
      'ready'    => !$missing,
      'missing'  => $missing,
      'summary'  => (!$missing) ? self::summary($new_brief) : [],
      'progress' => self::progress($new_brief),
    ]);
  }

  /**
   * Zet het laatste antwoord in het veld waarnaar de agent vroeg, als het daar nog niet staat.
   * Enkel voor gewone tekst- en lijstvelden; kaarten (stijl, logo, foto's) zetten hun veld zelf.
   */
  private static function capture_answer(array $brief, string $field, string $answer, array $set): array {
    $answer = trim($answer);
    if ($field === '' || $answer === '' || !isset(self::FIELDS[$field]) || isset($set[$field])) return $brief;
    if (in_array($field, ['logo', 'own_photos', 'style_id', 'style_mood', 'contact', 'existing_site', 'page_count'], true)) return $brief;
    if (!empty($brief[$field]) && $brief[$field] !== '-') return $brief;
    // Een wedervraag of correctie is geen antwoord.
    if (preg_match('/\?\s*$/u', $answer) || preg_match('/^(eigenlijk|nee|neen|wacht|sorry)\b/iu', $answer)) return $brief;
    if (preg_match('/^(sla over|overslaan|skip|geen idee|weet ik niet|nvt|n\.?v\.?t\.?)$/iu', $answer)) {
      $brief[$field] = '-';
      return $brief;
    }
    if ($field === 'languages') {
      $map = ['nederlands' => 'Dutch', 'frans' => 'French', 'engels' => 'English', 'duits' => 'German', 'dutch' => 'Dutch', 'french' => 'French', 'english' => 'English', 'german' => 'German'];
      $langs = [];
      foreach ($map as $w => $l) if (stripos($answer, $w) !== false) $langs[] = $l;
      if ($langs) $brief['languages'] = array_values(array_unique($langs));
      return $brief;
    }
    if (in_array($field, self::LISTS, true)) {
      $brief[$field] = $field === 'page_notes' ? [$answer] : $answer;
    } else {
      $brief[$field] = $answer;
    }
    return self::clean_brief($brief);
  }

  /** Stijlen in de volgorde van de brief: gekozen sfeer, dan de sector, dan de rest. */
  public function ajax_styles(): void {
    $this->check();
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    $brief = self::clean_brief(is_array($brief) ? $brief : []);
    wp_send_json_success(['styles' => self::styles_for_brief($brief)]);
  }

  /** Logo of eigen foto's uit het gesprek naar de mediabibliotheek. */
  public function ajax_upload(): void {
    $this->check();
    @set_time_limit(120);
    $kind = ($_POST['kind'] ?? '') === 'logo' ? 'logo' : 'photos';
    if (empty($_FILES['files'])) wp_send_json_error(['message' => 'Geen bestand ontvangen.'], 400);

    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Niet onbeperkt: een logo, of hoogstens 30 foto's per keer van elk 15 MB.
    $files = $_FILES['files'];
    $count = is_array($files['name']) ? count($files['name']) : 1;
    $count = min($count, $kind === 'logo' ? 1 : 30);
    $allowed = $kind === 'logo'
      ? ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/svg+xml']
      : ['image/png', 'image/jpeg', 'image/webp'];
    $items = [];
    $errors = [];
    for ($i = 0; $i < $count; $i++) {
      $single = [
        'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
        'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
        'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
        'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
        'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
      ];
      $check = wp_check_filetype((string) $single['name']);
      if (empty($check['type']) || !in_array($check['type'], $allowed, true)) { $errors[] = $single['name'] . ': geen geldig beeldbestand'; continue; }
      if ((int) $single['size'] > 15 * 1024 * 1024) { $errors[] = $single['name'] . ': groter dan 15 MB'; continue; }
      $_FILES['archer_intake_file'] = $single;
      $id = media_handle_upload('archer_intake_file', 0);
      if (is_wp_error($id)) { $errors[] = $single['name'] . ': ' . $id->get_error_message(); continue; }
      update_post_meta($id, '_archer_intake_owner', get_current_user_id());
      $items[] = self::media_item((int) $id);
    }
    if (!$items) wp_send_json_error(['message' => $errors ? implode(' · ', $errors) : 'Opladen lukte niet.'], 400);
    wp_send_json_success(['kind' => $kind, 'items' => $items, 'errors' => $errors]);
  }

  public function ajax_save(): void {
    $this->check();
    $project_id = (int) ($_POST['project_id'] ?? 0);
    $p = $project_id ? get_post($project_id) : null;
    if (!$p || $p->post_type !== 'aisb_project' || ((int) $p->post_author !== get_current_user_id() && !current_user_can('manage_options'))) {
      wp_send_json_error(['message' => 'Project niet gevonden.'], 404);
    }
    $brief = json_decode((string) wp_unslash($_POST['brief'] ?? '{}'), true);
    $brief = self::clean_brief(is_array($brief) ? $brief : []);
    update_post_meta($project_id, 'archer_intake_brief', wp_slash(wp_json_encode($brief, JSON_UNESCAPED_UNICODE)));
    // Contact uit het gesprek: zelfde plek als het startformulier (footer, contactblok, belknoppen).
    if (!empty($brief['contact']) && empty($brief['contact']['skipped'])) {
      $c = $brief['contact'];
      $old = json_decode((string) get_post_meta($project_id, 'archer_contact', true), true);
      $old = is_array($old) ? $old : [];
      $new = [
        'address' => (string) ($c['address'] ?? ($old['address'] ?? '')),
        'phone'   => (string) ($c['phone'] ?? ($old['phone'] ?? '')),
        'email'   => (string) ($c['email'] ?? ($old['email'] ?? '')),
        'socials' => !empty($c['socials']) ? array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $c['socials'])))) : (array) ($old['socials'] ?? []),
      ];
      update_post_meta($project_id, 'archer_contact', wp_slash(wp_json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
    }
    $design = self::apply_design($project_id, $brief);
    wp_send_json_success(['saved' => true, 'design' => $design]);
  }

  /** De bevestigde brief van een project (leeg als het project zonder intake gemaakt is). */
  public static function brief_for(int $project_id): array {
    $b = json_decode((string) get_post_meta($project_id, 'archer_intake_brief', true), true);
    return is_array($b) ? $b : [];
  }

  /* ───────────────────────── Ontwerp klaarzetten ───────────────────────── */

  /**
   * Zet stijl, kleuren, logo en eigen foto's uit de intake in de stijlgids van het project.
   * Zo staat het ontwerp er meteen bij de eerste wireframes, zonder aparte stap Stijl of Foto's.
   * Een logo dat al uit de bestaande website kwam, blijft staan tenzij de klant er zelf een oplaadde.
   */
  public static function apply_design(int $project_id, array $brief): array {
    if (!class_exists('AISB_Archer_Styles')) return [];
    $guide = json_decode((string) get_post_meta($project_id, 'aisb_style_guide', true), true);
    if (!is_array($guide) || array_keys($guide) === range(0, count($guide) - 1)) $guide = [];

    $style = !empty($brief['style_id']) ? AISB_Archer_Styles::get((string) $brief['style_id']) : null;
    if (!$style) {
      $ids = AISB_Archer_Styles::mood_styles($project_id);
      if (!$ids) {
        $all = AISB_Archer_Styles::for_project($project_id);
        $ids = array_map(fn($s) => $s['id'], (array) ($all['styles'] ?? []));
      }
      $style = $ids ? AISB_Archer_Styles::get((string) $ids[0]) : null;
    }
    if ($style) $guide = array_merge($guide, AISB_Archer_Styles::guide_patch($style));

    // Eigen merkkleur: wordt de accentkleur van de stijl.
    $hex = self::hex((string) ($brief['brand_colors'] ?? ''));
    if ($hex && !empty($guide['colours']) && is_array($guide['colours'])) {
      foreach ($guide['colours'] as &$c) {
        if (in_array($c['name'] ?? '', ['Primary', 'Accent'], true)) $c['hex'] = $hex;
      }
      unset($c);
      $guide['brand'] = $hex;
    }

    if (!empty($brief['logo']['id'])) {
      $url = set_url_scheme((string) wp_get_attachment_url((int) $brief['logo']['id']));
      if ($url) {
        $guide['logoUrl'] = $url;
        $guide['logoAttachmentId'] = (int) $brief['logo']['id'];
      }
    }

    if (!empty($brief['own_photos']) && is_array($brief['own_photos'])) {
      $have = [];
      foreach ((array) ($guide['uploadedImages'] ?? []) as $img) if (!empty($img['attachment_id'])) $have[(int) $img['attachment_id']] = 1;
      $uploaded = is_array($guide['uploadedImages'] ?? null) ? $guide['uploadedImages'] : [];
      foreach ($brief['own_photos'] as $ph) {
        $id = (int) ($ph['id'] ?? 0);
        if (!$id || isset($have[$id]) || !wp_attachment_is_image($id)) continue;
        $it = self::media_item($id);
        $uploaded[] = ['thumb' => $it['thumb'], 'full' => $it['url'], 'alt' => $it['alt'], 'photographer' => 'Uploaded', 'link' => '', 'uploaded' => true, 'attachment_id' => $id];
      }
      $guide['uploadedImages'] = $uploaded;
    }
    // Voorkeur voor foto's: in Ontwerp vult "Foto's" eerst de eigen foto's, stock enkel als het mag.
    $guide['archerPhotoPref'] = self::photo_pref((string) ($brief['photos'] ?? ''));

    update_post_meta($project_id, 'aisb_style_guide', wp_slash(wp_json_encode($guide, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
    return ['style' => $guide['style'] ?? '', 'logo' => !empty($guide['logoUrl']), 'own_photos' => count((array) ($guide['uploadedImages'] ?? []))];
  }

  /** "own", "stock" of "mix" uit het antwoord op de fotovraag. */
  public static function photo_pref(string $v): string {
    $v = mb_strtolower($v);
    if ($v === '') return 'mix';
    if (strpos($v, 'mix') !== false || strpos($v, 'beide') !== false) return 'mix';
    // Alleen eigen foto's als de klant geen stock wil; anders vullen stockfoto's aan wat ontbreekt.
    if (preg_match('/alleen eigen|enkel eigen|geen stock|only own/u', $v)) return 'own';
    if (strpos($v, 'stock') !== false) return 'stock';
    return 'mix';
  }

  /* ───────────────────────── Kaarten ───────────────────────── */

  /** Kaart bij de vraag die de agent stelt (of null). De inhoud komt van de server, niet van de AI. */
  public static function card_for(string $ask, array $brief): ?array {
    switch ($ask) {
      case 'style_id':
      case 'style_mood':
        $styles = self::styles_for_brief($brief);
        return ['type' => 'styles', 'field' => 'style_id', 'items' => array_slice($styles, 0, 4), 'more' => array_slice($styles, 4)];
      case 'brand_colors':
        return ['type' => 'palette', 'field' => 'brand_colors', 'items' => self::palette_for($brief)];
      case 'logo':
        return ['type' => 'upload', 'field' => 'logo', 'kind' => 'logo', 'label' => 'Sleep je logo hierheen of kies een bestand', 'hint' => 'PNG, SVG, JPG of WEBP. Liefst een versie zonder achtergrond.'];
      case 'photos':
      case 'own_photos':
        return ['type' => 'upload', 'field' => 'own_photos', 'kind' => 'photos', 'multi' => true, 'label' => "Sleep je foto's hierheen of kies bestanden", 'hint' => "Tot 30 foto's van je zaak, team of werk. Liggende foto's werken het best."];
      case 'must_pages':
      case 'page_count':
        return ['type' => 'pages', 'field' => 'must_pages', 'items' => self::pages_for($brief)];
    }
    return null;
  }

  /** Alle stijlen met wat de browser nodig heeft voor kaart en voorbeeld, best passend eerst. */
  public static function styles_for_brief(array $brief): array {
    if (!class_exists('AISB_Archer_Styles')) return [];
    $text = implode(' ', array_filter([
      (string) ($brief['business_name'] ?? ''), (string) ($brief['activity'] ?? ''),
      is_array($brief['services'] ?? null) ? implode(' ', $brief['services']) : '',
    ]));
    $order = [];
    // Sectoradvies (zelfde score als de stap Stijl).
    if (class_exists('AISB_Archer_Sector') && $text !== '') {
      $key = AISB_Archer_Sector::detect($text);
      $adv = $key ? AISB_Archer_Sector::get($key) : null;
      $sector = class_exists('AISB_Archer_Taste') ? AISB_Archer_Taste::sector_for($text) : 'other';
      if ($adv) foreach (AISB_Archer_Styles::library() as $s) $order[$s['id']] = AISB_Archer_Sector::score($s, $adv, $sector);
    }
    // Gekozen sfeer gaat voor.
    $mood = mb_strtolower((string) ($brief['style_mood'] ?? ''));
    $mood_map = [
      'licht' => ['nordic', 'atelier', 'helder'], 'rustig' => ['nordic', 'atelier', 'helder'],
      'warm' => ['ambacht', 'helder', 'fris'], 'ambacht' => ['ambacht', 'helder', 'fris'],
      'strak' => ['raster', 'fris', 'nordic'], 'modern' => ['raster', 'fris', 'nordic'],
      'krachtig' => ['studio', 'noir', 'raster'], 'uitgesproken' => ['studio', 'noir', 'raster'],
      'donker' => ['noir', 'studio'], 'exclusief' => ['noir', 'atelier'], 'speels' => ['fris', 'studio', 'helder'],
    ];
    foreach ($mood_map as $w => $ids) {
      if ($mood !== '' && strpos($mood, $w) !== false) {
        foreach ($ids as $i => $id) $order[$id] = 1000 - $i;
        break;
      }
    }
    $out = [];
    foreach (AISB_Archer_Styles::library() as $i => $s) {
      $out[] = [
        'id' => $s['id'],
        'name' => $s['name'],
        'pitch' => $s['pitch'],
        'colours' => $s['colours'],
        'headingFont' => $s['fonts']['heading']['family'],
        'headingWeight' => (int) $s['fonts']['heading']['weight'],
        'bodyFont' => $s['fonts']['body']['family'],
        'radius' => $s['radius'],
        'dark' => !empty($s['dark']),
        'fontUrl' => AISB_Archer_Styles::font_url($s),
        '_score' => ($order[$s['id']] ?? 0) - $i * 0.001,
      ];
    }
    usort($out, fn($a, $b) => $b['_score'] <=> $a['_score']);
    foreach ($out as $i => &$o) { $o['fit'] = $i < 3 && $o['_score'] > 0; unset($o['_score']); }
    unset($o);
    return $out;
  }

  /** Accentkleuren om uit te kiezen: die van de gekozen stijl eerst, dan een vaste reeks. */
  private static function palette_for(array $brief): array {
    $items = [];
    if (!empty($brief['style_id']) && class_exists('AISB_Archer_Styles')) {
      $s = AISB_Archer_Styles::get((string) $brief['style_id']);
      if ($s) $items[] = ['hex' => strtoupper($s['colours']['accent']), 'label' => 'Van de stijl ' . $s['name']];
    }
    $base = [
      '#1F3A5F' => 'Diepblauw', '#2152E8' => 'Kobalt', '#0E6E72' => 'Petrol', '#2F5A46' => 'Bosgroen',
      '#6B8F3E' => 'Olijf', '#C2410C' => 'Terracotta', '#D21F14' => 'Rood', '#B4235A' => 'Framboos',
      '#6D28D9' => 'Paars', '#CDB389' => 'Champagne', '#191917' => 'Zwart',
    ];
    foreach ($base as $hex => $label) {
      if ($items && strcasecmp($items[0]['hex'], $hex) === 0) continue;
      $items[] = ['hex' => $hex, 'label' => $label];
    }
    return $items;
  }

  /** Voorstel van pagina's om aan te vinken, uit de diensten, de sector en wat de klant al noemde. */
  private static function pages_for(array $brief): array {
    $text = mb_strtolower(implode(' ', array_filter([(string) ($brief['activity'] ?? ''), is_array($brief['services'] ?? null) ? implode(' ', $brief['services']) : ''])));
    $sector = class_exists('AISB_Archer_Taste') ? AISB_Archer_Taste::sector_for($text) : 'other';
    // Winkels met eigen productie (bakker, slager, traiteur...) zijn geen restaurant: geen menukaart of reservaties.
    if (preg_match('/\b(bakker|bakkerij|patisser|slager|beenhouw|traiteur|kaas|chocola|bloemen|bloemist|delicatesse|wijnhandel)/u', $text)) $sector = 'shop';
    $pages = [['title' => 'Home', 'on' => true, 'fixed' => true]];
    $services = is_array($brief['services'] ?? null) ? $brief['services'] : [];
    $pages[] = ['title' => $sector === 'restaurant' ? 'Menukaart' : ($sector === 'shop' ? 'Assortiment' : 'Diensten'), 'on' => true];
    if ($services && count($services) <= 6 && !in_array($sector, ['restaurant', 'ecommerce', 'shop'], true)) {
      foreach ($services as $sv) $pages[] = ['title' => mb_substr($sv, 0, 40), 'on' => count($services) <= 4, 'child' => true];
    }
    $extra = [
      'restaurant' => ['Reserveren', 'Over ons', 'Galerij'],
      'shop' => ['Bestellen', 'Over ons', 'Winkel en openingsuren'],
      'hospitality' => ['Kamers', 'Reserveren', 'Omgeving'],
      'health' => ['Behandelingen', 'Team', 'Tarieven', 'Afspraak maken'],
      'architecture' => ['Realisaties', 'Werkwijze', 'Over ons'],
      'finance' => ['Over ons', 'Team', 'Tarieven'],
      'fashion' => ['Prijzen', 'Team', 'Afspraak maken'],
      'education' => ['Aanbod', 'Inschrijven', 'Team'],
      'agency' => ['Cases', 'Over ons', 'Werkwijze'],
      'real-estate' => ['Aanbod', 'Verkopen', 'Over ons'],
    ][$sector] ?? ['Over ons', 'Realisaties', 'Werkwijze'];
    foreach ($extra as $i => $t) $pages[] = ['title' => $t, 'on' => $i < 2];
    $pages[] = ['title' => 'Veelgestelde vragen', 'on' => false];
    $pages[] = ['title' => 'Blog', 'on' => false];
    $pages[] = ['title' => 'Vacatures', 'on' => false];
    $pages[] = ['title' => 'Contact', 'on' => true, 'fixed' => true];
    // Wat de klant zelf al noemde, staat aangevinkt (en komt erbij als het nog niet in de lijst stond).
    $known = array_map('mb_strtolower', array_column($pages, 'title'));
    foreach ((array) ($brief['must_pages'] ?? []) as $mp) {
      $k = array_search(mb_strtolower($mp), $known, true);
      if ($k !== false) $pages[$k]['on'] = true;
      else { $pages[] = ['title' => $mp, 'on' => true]; $known[] = mb_strtolower($mp); }
    }
    // Dubbels eruit (bv. "Tarieven" en een dienst met dezelfde naam).
    $seen = [];
    return array_values(array_filter($pages, function ($p) use (&$seen) {
      $k = mb_strtolower($p['title']);
      if (isset($seen[$k])) return false;
      $seen[$k] = 1;
      return true;
    }));
  }

  /* ───────────────────────── AI ───────────────────────── */

  private function ai_turn(array $messages, array $brief, string $site_excerpt, int $user_turns) {
    $settings = class_exists('AISB_Settings') ? (array) (new AISB_Settings())->get_settings() : [];
    if (empty($settings['api_key'])) return new WP_Error('aisb_no_key', 'Er is geen AI-sleutel ingesteld.');
    $endpoint = !empty($settings['endpoint']) ? $settings['endpoint'] : 'https://api.openai.com/v1/chat/completions';

    $prog = self::progress($brief);
    $open = [];
    foreach ($prog['blocks'] as $b) if (!$b['done']) $open[] = $b['label'] . ' (nog: ' . implode(', ', $b['open']) . ')';
    $context = "CURRENT BRIEF (JSON):\n" . wp_json_encode(self::brief_for_ai($brief), JSON_UNESCAPED_UNICODE)
      . "\n\nMISSING REQUIRED FIELDS: " . (implode(', ', self::missing($brief)) ?: 'none')
      . "\nOPEN AGENDA BLOCKS: " . ($open ? implode(' | ', $open) : 'none')
      . "\nUSER ANSWERS SO FAR: " . $user_turns . ' (wrap up at ' . self::MAX_TURNS . ')';
    if ($site_excerpt !== '') $context .= "\n\nTEXT FROM THE CURRENT WEBSITE (homepage, use it to fill fields; do not ask what it already answers, but you may ask to confirm one key fact):\n" . $site_excerpt;
    if (class_exists('AISB_Archer_Sector') && method_exists('AISB_Archer_Sector', 'intake_hints')) {
      $context .= "\n\n" . AISB_Archer_Sector::intake_hints($brief);
    }

    $chat = [['role' => 'system', 'content' => self::system_prompt()], ['role' => 'system', 'content' => $context]];
    foreach ($messages as $m) $chat[] = ['role' => $m['role'] === 'user' ? 'user' : 'assistant', 'content' => $m['text']];

    $models = [
      apply_filters('archer_ai_intake_model', ['model' => 'gpt-5.4-mini', 'effort' => 'low']),
      ['model' => (string) ($settings['model'] ?? 'gpt-4o-mini') ?: 'gpt-4o-mini', 'effort' => ''],
    ];
    $last = new WP_Error('aisb_ai_http', 'De agent reageerde niet. Probeer opnieuw.');
    foreach ($models as $model) {
      $body = ['model' => $model['model'], 'messages' => $chat, 'response_format' => ['type' => 'json_object']];
      if (preg_match('/^(gpt-5|gpt-6|o\d)/', (string) $model['model'])) {
        if (!empty($model['effort'])) $body['reasoning_effort'] = $model['effort'];
      } else {
        $body['temperature'] = 0.3;
      }
      $res = wp_remote_post($endpoint, [
        'timeout' => 45,
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $settings['api_key']],
        'body'    => wp_json_encode($body),
      ]);
      if (is_wp_error($res)) { $last = $res; continue; }
      $code = (int) wp_remote_retrieve_response_code($res);
      $j = json_decode((string) wp_remote_retrieve_body($res), true);
      if ($code < 200 || $code >= 300) { $last = new WP_Error('aisb_ai_' . $code, (string) ($j['error']['message'] ?? 'AI-fout ' . $code)); continue; }
      $content = json_decode((string) ($j['choices'][0]['message']['content'] ?? ''), true);
      if (is_array($content) && isset($content['reply'])) return $content;
      $last = new WP_Error('aisb_bad_json', 'De agent gaf geen geldig antwoord.');
    }
    return $last;
  }

  /** Brief zoals de AI hem ziet: zonder adressen van bestanden. */
  private static function brief_for_ai(array $b): array {
    if (!empty($b['logo'])) $b['logo'] = 'uploaded';
    if (!empty($b['own_photos'])) $b['own_photos'] = count($b['own_photos']) . ' photos uploaded';
    return $b;
  }

  private static function system_prompt(): string {
    return <<<'TXT'
You are Archer's onboarding agent. You set up a website for a small local business (hairdresser, baker, restaurant, physiotherapist, contractor, accountant, ...) through a conversation instead of a form. You are thorough but quick: the better the brief, the better the website, and every answer must feel easy. Write in the language the user writes in; default to Belgian Dutch (Flemish), informal "je".

PERSONALITY: playful
- You are a cheerful, curious maker who clearly enjoys building this website with them. Warm, light and a bit cheeky, like a friendly local web designer chatting over a coffee. Never sarcastic, never childish, never at the user's expense.
- React to what they tell you with a short, specific reaction about THEIR business before the next question (3 to 10 words): "Zuurdesem en lokale bloem? Daar sta ik voor op.", "Sinds 1987, dat is een mooi verhaal voor je Over ons.", "Een kapsalon met koffie erbij, slim!". Make it about the content, not generic praise.
- Never start two replies the same way. Avoid the stock words "Genoteerd", "Top", "Super", "Perfect" and "Mooi" as openers; vary freely.
- Phrase questions with a light touch: "Vertel eens: sinds wanneer staan jullie achter de toonbank, en met hoeveel?", "Wie loopt er straks het liefst binnen via je website?". The quoted questions in the agenda below give the MEANING; always write them in your own playful words, adapted to their business (a baker gets bakery words, a plumber plumbing words).
- Show progress now and then in a fun way when a block is done: "Zo, de basis staat. Nu het leukste deel: hoe gaat het eruitzien?"
- At most one emoji in about every third message, only where it adds warmth (🥐 for a baker, ✂️ for a hairdresser, 🎨 at the style questions). Never in options, never two in a row.
- Options stay short and clear (1 to 4 words); a little wink is allowed but they must still be obvious answers ("Liever niet", "Doe maar stock").
- Playful never means slower: still one question per turn, still at most two short sentences plus the reaction.
- If the user writes formally ("u"), stay friendly but switch to "u" and tone the jokes down.

HOW YOU TALK
- One question per turn, one or two short sentences, after a short reaction to the previous answer.
- When the answer is a choice, give 2 to 5 short options (1 to 4 words each) in "options". Add "Iets anders" as last option when the list may not cover it. For optional questions, add "Sla over" as an option.
- Set "multi": true only when several options can be picked at once.
- Always set "ask" to the brief field your question is about. For these fields the interface shows a card under your message, so do NOT list the choices yourself and give no options:
  style_id (style cards), brand_colors (colour swatches), logo (upload box), own_photos (upload box), must_pages (page checklist).
- If the user corrects something ("eigenlijk...", "nee, het is..."), update that field, confirm in a few light words ("Oeps, aangepast: Lokeren dus.") and continue.
- Never ask for something the brief or the current website already answers. Never ask two things at once. Never ask for a field that has a value, unless you confirm a fact from the website.
- If the user asks a question, answer it in one sentence, then continue.
- ALWAYS write what the user just told you into "brief" (the field you asked about, plus any other facts in the answer). A turn without "brief" loses the answer.
- Messages like "Stijl gekozen: Ambacht" or "Logo opgeladen" or "Pagina's gekozen: ..." come from a card: the brief already has the value. React in a few playful words about the choice ("Ambacht, warm en eerlijk, dat past bij jullie brood.") and move on.

AGENDA, in this order. Skip what is known. Required fields are marked *.
1. DE ZAAK
   business_name* (and existing_site if they have one: a URL; the first message is usually the name or a URL)
   activity*: what the business does, one line. services: list of the main services or products (ask "Welke diensten of producten wil je zeker tonen?").
   region*: town, and service_area: how far they go (for example "Gent en 30 km rond").
   founded: since when (year), team: who works there (owner name, number of people) - one question for both is fine ("Sinds wanneer bestaan jullie en met hoeveel zijn jullie?").
   opening_hours: only for a shop, salon, practice, restaurant or other place people visit.
2. KLANTEN EN DOEL
   audience*: "B2C", "B2B" or "B2B en B2C", plus who exactly if they say.
   main_goal*: what a visitor must do: "Bellen", "Offerte aanvragen", "Afspraak boeken", "Reserveren", "Langskomen", "Bestellen", "Contactformulier".
   booking: only if main_goal is booking or reservation: "Calendly", "Eigen boekingssysteem (link)", "Nog geen", or the tool name. Archer links to an external tool; it does not sync calendars.
   price_info: show prices? "Vaste prijzen tonen", "Vanaf-prijzen", "Prijs op aanvraag".
3. VERTROUWEN
   usp: what sets them apart (one or two sentences).
   reviews: Google rating and number of reviews, or a short customer quote; "Sla over" allowed.
   references: known clients or projects they may show; certifications: labels, federations, diplomas; guarantees: warranty or promise. Ask references and certifications in one question if the business is B2B or a trade; skip guarantees unless it fits (trades, repairs, products).
4. PAGINA'S
   must_pages: ask "Welke pagina's wil je op je website?" with ask "must_pages" (the checklist card). page_count is inferred from it (one of "1", "2-5", "5-10", "10-15", "15+"); never ask it.
   page_notes: per-page depth. After must_pages, ask ONE OR TWO focused follow-ups about the most important pages, for example:
   - if there are several services: "Krijgt elke dienst een eigen pagina, of staan ze samen op één pagina?"
   - if there is a Team or Over ons page: "Wat wil je over jezelf of het team vertellen?"
   - if there is a FAQ page or the business gets many questions: "Welke vragen krijg je het vaakst?" -> faq_topics (list).
   - if there are Realisaties/Cases: "Welke 2 à 3 projecten wil je zeker tonen?"
   Store each answer as one short line in page_notes, prefixed with the page: "Diensten: elk een eigen pagina".
5. STIJL EN BEELD
   tone*: "Formeel (u)", "Vriendelijk (je)" or "Speels (je)".
   languages*: array of "Dutch", "French", "English", "German" (options: Nederlands, Frans, Engels, Duits; store the English names; multi).
   style_id*: ask "Welke stijl spreekt je het meest aan?" with ask "style_id" (style cards).
   brand_colors: ask "Heb je een vaste merkkleur?" with ask "brand_colors" (swatches; the user may also type a hex code or "geen").
   logo: ask "Heb je een logo? Laad het hier op." with ask "logo". Skip this if the brief says the logo comes from the current website. If they have none: store "geen".
   photos: ask "Heb je eigen foto's van je zaak, team of werk?" with ask "own_photos" and options "Ik laad ze nu op", "Gebruik stockfoto's", "Mix van beide". Store the choice in photos ("Eigen foto's", "Stockfoto's", "Mix").
   examples: websites they like (optional, one question that also asks what they do NOT want -> dislikes).
6. CONTACT
   contact: object with address, phone, email, socials. Ask once, in one turn ("Welk adres, telefoonnummer en e-mail mogen op de website?"); the user may skip. If they skip, set contact to {"skipped": true}.

FINISHING
- When all required fields are filled and the agenda is covered (or the user wants to stop: "genoeg", "maak de website", "start maar"), set "done": true and reply with one cheerful sentence like "Dat was het! Ik heb alles om iets moois te bouwen. Kijk nog even mee?" Do not list the summary yourself.
- When you are near the turn limit, make sensible choices yourself for optional fields and finish.

OUTPUT: only a JSON object:
{"reply": string, "ask": string, "options": [string], "multi": boolean, "input": "text"|"url"|"none", "brief": {only the fields you add or change in this turn}, "done": boolean}
Field types: strings, except services, must_pages, languages, page_notes, faq_topics, references, certifications (arrays of strings) and contact (object with address, phone, email, socials as strings). Never write the fields style_id, logo or own_photos yourself: the cards set them. Keep values short and factual, in the user's words. Never invent facts the user did not give or the website does not show. "Sla over" means: store nothing for that field and never ask it again (store "-" to mark it as asked).
TXT;
  }

  /* ───────────────────────── Brief ───────────────────────── */

  public static function clean_brief(array $b): array {
    $out = [];
    foreach (array_keys(self::FIELDS) as $k) {
      if (!array_key_exists($k, $b)) continue;
      $v = $b[$k];
      if (in_array($k, self::LISTS, true)) {
        $list = is_array($v) ? $v : preg_split('/\s*[,;]\s*/', (string) $v);
        $list = array_values(array_unique(array_filter(array_map(function ($x) { return mb_substr(trim(wp_strip_all_tags(is_array($x) ? implode(' ', $x) : (string) $x)), 0, 160); }, $list), 'strlen')));
        if ($k === 'languages') $list = array_values(array_intersect($list, ['Dutch', 'French', 'English', 'German']));
        if (count($list) > 1) $list = array_values(array_filter($list, fn($x) => $x !== '-'));
        if ($list) $out[$k] = array_slice($list, 0, 24);
      } elseif ($k === 'contact') {
        $c = is_array($v) ? $v : [];
        $cc = [];
        foreach (['address', 'phone', 'email', 'socials'] as $ck) {
          $cv = is_array($c[$ck] ?? null) ? implode(', ', $c[$ck]) : (string) ($c[$ck] ?? '');
          $cv = mb_substr(trim(wp_strip_all_tags($cv)), 0, 200);
          if ($ck === 'email' && $cv !== '' && !is_email($cv)) $cv = '';
          if ($cv !== '') $cc[$ck] = $cv;
        }
        // "Overgeslagen" telt als gevraagd: de agent vraagt het dan niet opnieuw.
        if ($cc || !empty($c['skipped'])) $out[$k] = $cc ?: ['skipped' => '1'];
      } elseif ($k === 'existing_site') {
        $u = trim((string) $v);
        if ($u !== '' && !preg_match('#^https?://#i', $u)) $u = 'https://' . ltrim($u, '/');
        $u = esc_url_raw($u);
        if ($u && wp_http_validate_url($u)) $out[$k] = $u;
      } elseif ($k === 'page_count') {
        $s = (string) $v;
        if (in_array($s, ['1', '2-5', '5-10', '10-15', '15+'], true)) $out[$k] = $s;
      } elseif ($k === 'style_id') {
        $s = sanitize_key((string) $v);
        if ($s !== '' && class_exists('AISB_Archer_Styles') && AISB_Archer_Styles::get($s)) $out[$k] = $s;
      } elseif ($k === 'brand_colors') {
        $s = trim(wp_strip_all_tags(is_array($v) ? implode(', ', $v) : (string) $v));
        if ($s !== '') $out[$k] = mb_substr($s, 0, 80);
      } elseif ($k === 'logo') {
        // Opgeladen bestand {id, url}, of "geen" / "website".
        if (is_array($v) && !empty($v['id']) && self::own_attachment((int) $v['id'])) {
          $out[$k] = self::media_item((int) $v['id']);
        } elseif (is_string($v) && trim($v) !== '') {
          $out[$k] = mb_substr(trim(wp_strip_all_tags($v)), 0, 60);
        }
      } elseif ($k === 'own_photos') {
        $list = [];
        foreach ((array) $v as $ph) {
          $id = is_array($ph) ? (int) ($ph['id'] ?? 0) : (int) $ph;
          if ($id && self::own_attachment($id)) $list[$id] = self::media_item($id);
        }
        if ($list) $out[$k] = array_values(array_slice($list, 0, 60, true));
      } else {
        $s = mb_substr(trim(wp_strip_all_tags(is_array($v) ? implode(', ', $v) : (string) $v)), 0, 500);
        if ($s !== '') $out[$k] = $s;
      }
    }
    // Aantal pagina's volgt uit de aangevinkte pagina's.
    if (empty($out['page_count']) && !empty($out['must_pages'])) {
      $n = count($out['must_pages']);
      $out['page_count'] = $n <= 1 ? '1' : ($n <= 5 ? '2-5' : ($n <= 10 ? '5-10' : ($n <= 15 ? '10-15' : '15+')));
    }
    return $out;
  }

  public static function missing(array $b): array {
    return array_values(array_filter(self::REQUIRED, function ($k) use ($b) { return empty($b[$k]); }));
  }

  /** Voortgang per blok van de agenda. */
  public static function progress(array $b): array {
    $blocks = [];
    $have = 0; $need = 0;
    foreach (self::BLOCKS as $key => $blk) {
      $open = [];
      foreach ($blk['fields'] as $f) {
        $need++;
        if (!empty($b[$f])) $have++;
        else $open[] = $f;
      }
      $blocks[] = ['key' => $key, 'label' => $blk['label'], 'done' => !$open, 'open' => $open, 'have' => count($blk['fields']) - count($open), 'need' => count($blk['fields'])];
    }
    $req = count(self::REQUIRED);
    return ['have' => $req - count(self::missing($b)), 'need' => $req, 'agenda' => ['have' => $have, 'need' => $need], 'blocks' => $blocks];
  }

  /** Standaarden als de klant te lang bezig is; enkel voor velden waar een gok geen schade doet. */
  private static function with_defaults(array $b): array {
    $defaults = ['audience' => 'B2C', 'main_goal' => 'Contactformulier', 'tone' => 'Vriendelijk (je)', 'languages' => ['Dutch']];
    foreach ($defaults as $k => $v) if (empty($b[$k])) $b[$k] = $v;
    if (empty($b['style_id'])) {
      $styles = self::styles_for_brief($b);
      if ($styles) $b['style_id'] = $styles[0]['id'];
    }
    return $b;
  }

  /** Rijen voor de samenvattingstabel. */
  public static function summary(array $b): array {
    $lang = ['Dutch' => 'Nederlands', 'French' => 'Frans', 'English' => 'Engels', 'German' => 'Duits'];
    $rows = [];
    foreach (self::FIELDS as $k => $label) {
      if (empty($b[$k]) || $b[$k] === '-' || $b[$k] === ['-']) continue;
      $v = $b[$k];
      if ($k === 'style_mood' && !empty($b['style_id'])) continue;
      if ($k === 'languages') $v = implode(', ', array_map(function ($l) use ($lang) { return $lang[$l] ?? $l; }, $v));
      elseif ($k === 'contact') { if (!empty($v['skipped'])) continue; $v = implode(' · ', array_values($v)); }
      elseif ($k === 'style_id') { $s = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::get((string) $v) : null; $v = $s ? $s['name'] : $v; }
      elseif ($k === 'logo') $v = is_array($v) ? 'Opgeladen' : $v;
      elseif ($k === 'own_photos') $v = count($v) . ' opgeladen';
      elseif (is_array($v)) $v = implode(', ', $v);
      $rows[] = ['key' => $k, 'label' => $label, 'value' => (string) $v];
    }
    return $rows;
  }

  /** De brief als tekst voor de sitemap- en tekstgeneratie (komt in aisb_project_brief). */
  public static function prompt_text(array $b): string {
    $line = function (string $label, string $k) use ($b): string {
      if (empty($b[$k]) || $b[$k] === '-') return '';
      $v = $b[$k];
      if (is_array($v)) $v = implode(', ', array_map(fn($x) => is_array($x) ? implode(' ', $x) : (string) $x, $v));
      return $label . ': ' . $v . '.';
    };
    return implode("\n", array_filter([
      $line('Naam van de zaak', 'business_name'),
      $line('Activiteit', 'activity'),
      $line('Diensten of producten', 'services'),
      $line('Regio', 'region'),
      $line('Werkgebied', 'service_area'),
      $line('Actief sinds', 'founded'),
      $line('Team', 'team'),
      $line('Openingsuren', 'opening_hours'),
      $line('Doelgroep', 'audience'),
      $line('Wat een bezoeker moet doen (hoofddoel, de knop in de header)', 'main_goal'),
      !empty($b['booking']) && $b['booking'] !== '-' ? 'Online boeken via: ' . $b['booking'] . ' (externe link of widget, geen agendakoppeling).' : '',
      $line('Prijzen', 'price_info'),
      $line('Wat de zaak onderscheidt', 'usp'),
      $line('Reviews (gebruik exact dit, verzin geen andere)', 'reviews'),
      $line('Referenties', 'references'),
      $line('Erkenningen en labels', 'certifications'),
      $line('Garanties', 'guarantees'),
      $line('Toon van de teksten', 'tone'),
      $line("Pagina's die zeker moeten", 'must_pages'),
      $line('Afspraken per pagina', 'page_notes'),
      $line('Veelgestelde vragen', 'faq_topics'),
      $line('Gewenste sfeer', 'style_mood'),
      $line('Voorbeeldsites', 'examples'),
      $line('Liever niet', 'dislikes'),
    ]));
  }

  /** Homepaginatekst van een bestaande site, één keer per dag per adres opgehaald. */
  private static function site_excerpt(string $url): string {
    $key = 'archer_intake_site_' . md5($url);
    $cached = get_transient($key);
    if (is_string($cached)) return $cached;
    $text = '';
    $res = wp_safe_remote_get($url, [
      'timeout' => 12, 'redirection' => 3, 'limit_response_size' => 2 * 1024 * 1024,
      'user-agent' => 'Mozilla/5.0 (compatible; ArcherWebsitesBot/1.0; +https://archerwebsites.be)',
    ]);
    if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) < 400 && class_exists('AISB_Archer_UI')) {
      $brief = AISB_Archer_UI::extract_brief((string) wp_remote_retrieve_body($res), $url);
      $text = mb_substr(trim(($brief['title'] ?? '') . "\n" . ($brief['text'] ?? '')), 0, 4000);
    }
    set_transient($key, $text, DAY_IN_SECONDS);
    return $text;
  }

  /* ───────────────────────── Hulp ───────────────────────── */

  private static function hex(string $v): string {
    return preg_match('/#[0-9a-f]{6}\b/i', $v, $m) ? strtoupper($m[0]) : '';
  }

  /** Een bijlage die deze gebruiker in de intake oplaadde (of een beheerder). */
  private static function own_attachment(int $id): bool {
    if (!$id || get_post_type($id) !== 'attachment') return false;
    if (current_user_can('manage_options')) return true;
    return (int) get_post_meta($id, '_archer_intake_owner', true) === get_current_user_id();
  }

  private static function media_item(int $id): array {
    $url = set_url_scheme((string) wp_get_attachment_url($id));
    $thumb = set_url_scheme((string) (wp_get_attachment_image_url($id, 'medium') ?: $url));
    return ['id' => $id, 'url' => $url, 'thumb' => $thumb, 'alt' => (string) get_post_meta($id, '_wp_attachment_image_alt', true)];
  }

  private static function clean_messages(array $messages): array {
    $out = [];
    foreach (array_slice($messages, -60) as $m) {
      if (!is_array($m)) continue;
      $role = ($m['role'] ?? '') === 'user' ? 'user' : 'agent';
      $text = mb_substr(trim(wp_strip_all_tags((string) ($m['text'] ?? ''))), 0, 2000);
      if ($text !== '') $out[] = ['role' => $role, 'text' => $text];
    }
    return $out;
  }

  private function check(): void {
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Meld je eerst aan.'], 401);
    check_ajax_referer(AISB_Plugin::NONCE_ACTION, 'nonce');
  }
}
