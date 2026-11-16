<?php
if (!defined('ABSPATH')) exit;

/**
 * Controle voor publiceren, naar de UX-regels van UI/UX Pro Max (ux-guidelines.csv, landing.csv: contrast 4.5:1,
 * klikvlakken van 44px, contact zichtbaar) en het sectoradvies (AISB_Archer_Sector).
 * De server controleert wat uit de gegevens volgt (kleuren, contact, sector); archer.js voegt toe wat alleen
 * in de voorbeelden te zien is (alt-teksten, lege fotokaders, één H1 per pagina, afspraakknop).
 */
class AISB_Archer_Checks {

  /** Sectoren waar een telefoonnummer op de website hoort (nood, dringend, ter plaatse). */
  const NEEDS_PHONE = ['home-services', 'garden', 'vet', 'automotive', 'construction'];
  /** Sectoren waar de startpagina een afspraak-, reserveer- of boekknop nodig heeft. */
  const NEEDS_BOOKING = ['beauty', 'dental', 'medical', 'vet', 'restaurant', 'hotel', 'fitness', 'photography'];

  /** @return array<int,array{id:string,status:string,label:string,detail:string}> */
  public static function server(int $pid): array {
    $out = [];
    $guide = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    $style = class_exists('AISB_Archer_Styles') ? AISB_Archer_Styles::of($guide) : null;
    $sector = class_exists('AISB_Archer_Sector') ? AISB_Archer_Sector::get(AISB_Archer_Sector::for_project($pid)) : null;

    if ($style) {
      $c = AISB_Archer_Styles::effective_colours($style, $guide);
      $pairs = [
        ['contrast-text', 'Tekst op de achtergrond', $c['ink'], $c['base']],
        ['contrast-muted', 'Lichtere tekst op de achtergrond', $c['muted'], $c['base']],
        ['contrast-button', 'Tekst op de knoppen', $c['accentInk'], $c['accent']],
      ];
      foreach ($pairs as [$id, $label, $fg, $bg]) {
        $r = AISB_Archer_Styles::contrast($fg, $bg);
        $out[] = [
          'id' => $id,
          'status' => $r >= 4.5 ? 'ok' : 'warn',
          'label' => $label . ': contrast ' . number_format($r, 1, ',', '') . ':1',
          'detail' => $r >= 4.5 ? 'Leesbaar (minstens 4,5:1).' : 'Onder 4,5:1: kies in de stap Stijl een donkerdere tekstkleur of een ander accent.',
        ];
      }
      $size = (int) ($style['button']['size'] ?? 16);
      $h = max(44, (int) round($size * 1.1 + 2 * $size)); // de stijl zet min-height:44px op knoppen
      $out[] = [
        'id' => 'touch-target', 'status' => $h >= 44 ? 'ok' : 'warn',
        'label' => 'Knoppen groot genoeg om aan te tikken (' . $h . 'px hoog)',
        'detail' => $h >= 44 ? 'Minstens 44px, ook op een gsm.' : 'Kleiner dan 44px: moeilijk aan te tikken op een gsm.',
      ];
      if ($sector && !empty($sector['avoid_dark']) && !empty($style['dark'])) {
        $out[] = ['id' => 'sector-dark', 'status' => 'warn', 'label' => 'Donkere stijl voor ' . mb_strtolower($sector['label']), 'detail' => 'Voor deze sector raadt UI/UX Pro Max een lichte stijl aan.'];
      }
    } else {
      $out[] = ['id' => 'style', 'status' => 'warn', 'label' => 'Nog geen stijl gekozen', 'detail' => 'Kies een stijl in de stap Stijl.'];
    }

    // Lege fotokaders (stap Foto's): die blijven op de site een grijs vlak.
    $imgs = is_array($guide['images'] ?? null) ? $guide['images'] : [];
    $empty = count(array_filter($imgs, fn($i) => !is_array($i) || !empty($i['archerEmpty']) || empty($i['full'] ?? $i['thumb'] ?? '')));
    if ($imgs) {
      $out[] = $empty
        ? ['id' => 'photos', 'status' => 'warn', 'label' => $empty . ' lege fotokaders', 'detail' => 'Kies in de stap Foto\'s eigen of stockfoto\'s voor deze kaders.']
        : ['id' => 'photos', 'status' => 'ok', 'label' => 'Alle fotokaders gevuld', 'detail' => count($imgs) . ' foto\'s.'];
    }

    // Ontwerpcoach (AISB_Archer_Design_Coach): de opbouw van de pagina's, gemeten tijdens het bouwen.
    if (class_exists('AISB_Archer_Design_Coach') && AISB_Archer_Design_Coach::on()) {
      $d = AISB_Archer_Design_Coach::summary($pid);
      if ($d['average'] !== null) {
        $weak = array_values(array_filter($d['pages'], fn($p) => $p['total'] !== null && $p['total'] < AISB_Archer_Design_Coach::WEAK_BELOW));
        if ($weak) {
          $w = $weak[0];
          $note = (string) ($w['weak'][0] ?? ($w['findings'][0]['note'] ?? ''));
          $out[] = [
            'id' => 'design', 'status' => 'warn',
            'label' => count($weak) === 1 ? '1 pagina met een zwakke opbouw: ' . $w['slug'] : count($weak) . ' pagina\'s met een zwakke opbouw',
            'detail' => trim(ucfirst($w['slug']) . ' scoort ' . (int) $w['total'] . '/100. ' . $note),
          ];
        } else {
          $out[] = ['id' => 'design', 'status' => 'ok', 'label' => 'Opbouw van de pagina\'s nagekeken (' . (int) $d['average'] . '/100 gemiddeld)', 'detail' => 'Volgorde, afwisseling en stijl van de secties.'];
        }
      }
      if ($d['findings'] > 0) {
        $notes = [];
        foreach ($d['pages'] as $p) foreach ((array) $p['findings'] as $fn) $notes[] = $p['slug'] . ': ' . $fn['note'];
        $out[] = [
          'id' => 'design-findings', 'status' => 'warn',
          'label' => $d['findings'] === 1 ? '1 ding om na te kijken in de opbouw' : $d['findings'] . ' dingen om na te kijken in de opbouw',
          'detail' => mb_substr(implode(' ', array_slice($notes, 0, 3)), 0, 300),
        ];
      }
    }

    // Klinkt het nog als AI? (AISB_Archer_Dutch) De copycoach bewaart per pagina wat hij niet
    // opgeruimd kreeg: Nederlands uit Nederland, ambtelijke taal, verzonnen enthousiasme, vormtics.
    if (class_exists('AISB_Archer_Dutch') && AISB_Archer_Dutch::on()) {
      $tells = [];
      foreach ((array) get_post_meta($pid) as $mk => $mv) {
        if (strpos($mk, '_archer_copy_score_') !== 0) continue;
        $score = maybe_unserialize($mv[0] ?? '');
        if (!is_array($score) || empty($score['tells'])) continue;
        $slug = substr($mk, strlen('_archer_copy_score_'));
        foreach ((array) $score['tells'] as $t) $tells[] = ['slug' => $slug] + (array) $t;
      }
      if ($tells) {
        $groups = [];
        foreach ($tells as $t) $groups[(string) ($t['group'] ?? '')] = true;
        $names = array_values(array_filter(array_map(fn($g) => AISB_Archer_Dutch::GROUPS[$g] ?? '', array_keys($groups))));
        $first = $tells[0];
        $out[] = [
          'id' => 'ai-tells', 'status' => 'warn',
          'label' => count($tells) === 1 ? '1 zin die als AI klinkt' : count($tells) . ' zinnen die als AI klinken',
          'detail' => mb_substr(($names ? implode(', ', $names) . '. ' : '') . ucfirst((string) $first['slug']) . ': "' . $first['hit'] . '" - ' . $first['note'] . '.', 0, 300),
        ];
      }
    }

    // Stemprofiel (AISB_Archer_Voice): zonder dat schrijft elke stap in haar eigen toon.
    if (class_exists('AISB_Archer_Voice') && AISB_Archer_Voice::on()) {
      $v = AISB_Archer_Voice::summary($pid);
      $out[] = empty($v['has'])
        ? ['id' => 'voice', 'status' => 'warn', 'label' => 'Nog geen stemprofiel', 'detail' => 'De teksten kiezen dan zelf hun toon. Doorloop de intake opnieuw, of laat de eigenaar zijn zaak inspreken.']
        : ['id' => 'voice', 'status' => 'ok',
           'label' => 'Stemprofiel klaar' . (!empty($v['spoken']) ? ' (uit het verhaal van de eigenaar)' : ''),
           'detail' => trim(($v['who'] !== '' ? $v['who'] . '. ' : '') . (int) $v['objections'] . ' twijfel(s), ' . (int) $v['knows'] . ' eigen detail(s).')];
    }

    $contact = class_exists('AISB_Archer_Site') ? AISB_Archer_Site::contact($pid) : ['phone' => '', 'email' => '', 'address' => ''];
    $need_phone = $sector && in_array($sector['key'], self::NEEDS_PHONE, true);
    if ($contact['phone'] === '' && $contact['email'] === '') {
      $out[] = ['id' => 'contact', 'status' => 'warn', 'label' => 'Geen telefoon of e-mail', 'detail' => 'Bezoekers kunnen je niet bereiken; vul de contactgegevens in.'];
    } elseif ($need_phone && $contact['phone'] === '') {
      $out[] = ['id' => 'contact', 'status' => 'warn', 'label' => 'Geen telefoonnummer', 'detail' => 'Voor ' . mb_strtolower($sector['label']) . ' bellen klanten liefst meteen.'];
    } else {
      $out[] = ['id' => 'contact', 'status' => 'ok', 'label' => 'Contactgegevens ingevuld', 'detail' => 'Komen in de footer, op de contactpagina en achter de knoppen.'];
    }
    if ($contact['address'] === '' && $sector && in_array($sector['key'], ['restaurant', 'bakery', 'hotel', 'medical', 'dental', 'vet', 'beauty'], true)) {
      $out[] = ['id' => 'address', 'status' => 'warn', 'label' => 'Geen adres', 'detail' => 'Voor een zaak waar klanten langskomen hoort het adres (en een kaart) op de site.'];
    }
    return $out;
  }

  /** Wat de browser nakijkt in de voorbeelden van stap 5. */
  public static function client_config(int $pid): array {
    $key = class_exists('AISB_Archer_Sector') ? AISB_Archer_Sector::for_project($pid) : '';
    $s = $key !== '' && class_exists('AISB_Archer_Sector') ? AISB_Archer_Sector::get($key) : null;
    return [
      'booking' => in_array($key, self::NEEDS_BOOKING, true),
      'sector' => $s ? $s['label'] : '',
      'moet' => $s ? $s['advice']['moet'] : [],
    ];
  }

  public static function render(int $pid): string {
    $items = self::server($pid);
    $cfg = self::client_config($pid);
    $warn = count(array_filter($items, fn($i) => $i['status'] === 'warn'));
    ob_start(); ?>
    <section class="archer-checks" data-archer-checks data-config="<?php echo esc_attr(wp_json_encode($cfg)); ?>" aria-labelledby="archer-checks-title">
      <div class="archer-checks-head">
        <h3 id="archer-checks-title">Controle voor publiceren</h3>
        <span class="archer-checks-count" data-archer-checks-count><?php echo $warn ? (int) $warn . ' om na te kijken' : 'Alles in orde'; ?></span>
      </div>
      <ul class="archer-checks-list" data-archer-checks-list>
        <?php foreach ($items as $i) : ?>
          <li class="is-<?php echo esc_attr($i['status']); ?>" data-check="<?php echo esc_attr($i['id']); ?>"><span class="archer-check-mark" aria-hidden="true"></span><span><strong><?php echo esc_html($i['label']); ?></strong> <span class="archer-muted"><?php echo esc_html($i['detail']); ?></span></span></li>
        <?php endforeach; ?>
        <li class="is-pending" data-check="preview"><span class="archer-check-mark" aria-hidden="true"></span><span><strong>Pagina's nakijken…</strong> <span class="archer-muted">Alt-teksten, titels en knoppen in het voorbeeld.</span></span></li>
      </ul>
      <?php if ($cfg['moet']) : ?>
        <details class="archer-checks-sector"><summary>Zelf nakijken voor <?php echo esc_html(mb_strtolower($cfg['sector'])); ?></summary>
          <ul><?php foreach ($cfg['moet'] as $m) : ?><li><?php echo esc_html($m); ?></li><?php endforeach; ?></ul>
        </details>
      <?php endif; ?>
    </section>
    <?php
    return (string) ob_get_clean();
  }
}
