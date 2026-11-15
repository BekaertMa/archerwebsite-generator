<?php
if (!defined('ABSPATH')) exit;

/**
 * Beelden in één stijl (ADR-0033, punt 5).
 *
 * Volgorde voor foto's: eigen foto's van de klant → stockfoto's in de beeldtaal van de site
 * (AISB_Archer_Stock, met de "look" uit de ontwerprichting) → gegenereerde beelden, enkel als
 * de instelling aan staat en stock te weinig vindt.
 *
 * Gegenereerde beelden tonen enkel voorwerpen, materialen, details, texturen of ruimtes: nooit mensen,
 * nooit een winkel, product of team dat zich voordoet als dat van de klant, nooit tekst of logo's.
 * Ze komen in de mediabibliotheek met alt-tekst en bron "AI (Archer)", zodat ze te herkennen en te
 * vervangen zijn. Hoogstens MAX_PER_PROJECT per project (kosten).
 *
 * Instelling: Instellingen → AI Sitemap Builder → Stockfoto's → "AI-beelden als aanvulling" (standaard uit).
 */
class AISB_Archer_Imagery {

  public const OPTION = 'archer_ai_imagery';
  public const MAX_PER_PROJECT = 6;
  public const SOURCE = 'AI (Archer)';

  public function init(): void {
    add_action('admin_init', [$this, 'register'], 21);
  }

  public function register(): void {
    register_setting('aisb_settings_group', self::OPTION, ['type' => 'string', 'sanitize_callback' => fn($v) => $v === '1' ? '1' : '0', 'show_in_rest' => false, 'default' => '0']);
    add_settings_field(self::OPTION, 'AI-beelden als aanvulling', function () {
      echo '<label><input type="checkbox" id="' . esc_attr(self::OPTION) . '" name="' . esc_attr(self::OPTION) . '" value="1" ' . checked(self::on(), true, false) . '> '
        . 'Vind stock te weinig foto\'s voor een pagina, maak dan beelden in de beeldtaal van de site (voorwerpen, materialen, ruimtes; nooit mensen of tekst). Hoogstens ' . (int) self::MAX_PER_PROJECT . ' per project.</label>';
      echo '<p class="description">Kost per beeld. Controleer eerst de gebruiksvoorwaarden van het beeldmodel voor commercieel gebruik.</p>';
    }, 'ai-sitemap-builder', 'aisb_stock');
  }

  public static function on(): bool {
    return (bool) apply_filters('archer_ai_imagery_on', get_option(self::OPTION, '0') === '1');
  }

  /** Hoeveel beelden dit project al kreeg. */
  public static function used(int $pid): int {
    return (int) get_post_meta($pid, 'archer_ai_images', true);
  }

  /**
   * Prompt voor één beeld: onderwerp + de look van de site + de accentkleur, met vaste grenzen.
   */
  public static function prompt(int $pid, string $subject): string {
    $dir = class_exists('AISB_Archer_Direction') ? AISB_Archer_Direction::for_project($pid) : [];
    $img = (array) ($dir['strategy']['imagery'] ?? []);
    $look = (string) ($img['look'] ?? '');
    if ($look === '' && class_exists('AISB_Archer_Styles')) $look = AISB_Archer_Styles::prompt_notes(json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true), 'photo');
    $accent = '';
    $guide = json_decode((string) get_post_meta($pid, 'aisb_style_guide', true), true);
    if (is_array($guide) && !empty($guide['brand'])) $accent = (string) $guide['brand'];
    elseif (class_exists('AISB_Archer_Styles') && ($st = AISB_Archer_Styles::of($guide))) $accent = (string) ($st['colours']['accent'] ?? '');
    $avoid = array_merge(['people', 'faces', 'hands', 'text', 'letters', 'logos', 'signs', 'brand names', 'storefronts'], (array) ($img['avoid'] ?? []));
    return 'Editorial photograph for a small local business website. Subject: ' . $subject . '. '
      . ($look !== '' ? 'Look: ' . rtrim($look, '. ') . '. ' : '')
      . 'Show only objects, materials, tools, details, textures or an empty space; realistic, natural, calm composition with room for a headline. '
      . ($accent !== '' ? 'Let the colour ' . $accent . ' appear subtly somewhere in the scene. ' : '')
      . 'Do not show: ' . implode(', ', array_unique($avoid)) . '. No watermark.';
  }

  /**
   * Vult een tekort aan foto's aan met gegenereerde beelden (parallel, hoogstens 2 per keer).
   * Zelfde vorm als de stockfoto's: thumb, full, alt, photographer, link (+ attachment_id, ai).
   */
  public static function fill(int $pid, string $subject, int $need): array {
    if (!$pid || !self::on() || $need < 1) return [];
    $left = self::MAX_PER_PROJECT - self::used($pid);
    $n = min($need, 2, $left);
    if ($n < 1) return [];
    $s = (array) get_option('aisb_settings', []);
    $key = (string) ($s['api_key'] ?? '');
    if ($key === '' || !class_exists('WpOrg\\Requests\\Requests')) return [];
    $model = apply_filters('archer_ai_imagery_model', ['model' => 'gpt-image-2', 'size' => '1536x1024', 'quality' => 'medium']);
    $prompt = self::prompt($pid, $subject);
    $reqs = [];
    for ($i = 0; $i < $n; $i++) {
      $reqs[$i] = [
        'url' => 'https://api.openai.com/v1/images/generations',
        'type' => 'POST',
        'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $key],
        'data' => wp_json_encode(['model' => $model['model'], 'prompt' => $prompt . ($i ? ' Variation ' . ($i + 1) . ': a different detail of the same scene.' : ''), 'size' => $model['size'], 'quality' => $model['quality'], 'n' => 1]),
      ];
    }
    @set_time_limit(180);
    $t = microtime(true);
    $res = \WpOrg\Requests\Requests::request_multiple($reqs, ['timeout' => 150, 'connect_timeout' => 10]);
    $out = [];
    foreach ($res as $r) {
      if (!($r instanceof \WpOrg\Requests\Response) || $r->status_code !== 200) continue;
      $j = json_decode((string) $r->body, true);
      $b64 = (string) ($j['data'][0]['b64_json'] ?? '');
      if ($b64 === '') continue;
      $id = self::store($pid, base64_decode($b64), $subject);
      if (!$id) continue;
      $full = (string) wp_get_attachment_url($id);
      $thumb = (string) (wp_get_attachment_image_url($id, 'medium_large') ?: $full);
      $out[] = ['thumb' => $thumb, 'full' => $full, 'alt' => self::alt($subject), 'photographer' => self::SOURCE, 'link' => '', 'attachment_id' => $id, 'ai' => true];
    }
    if ($out) update_post_meta($pid, 'archer_ai_images', self::used($pid) + count($out));
    $msg = '[Archer imagery] project ' . $pid . ' asked ' . $n . ' got ' . count($out) . ' in ' . round(microtime(true) - $t, 1) . 's model ' . $model['model'];
    if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) error_log($msg);
    return $out;
  }

  private static function alt(string $subject): string {
    return mb_substr(ucfirst(trim($subject)), 0, 120);
  }

  /** Beeld in de mediabibliotheek, met alt-tekst en de bron in post meta. */
  private static function store(int $pid, string $bytes, string $subject): int {
    if ($bytes === '') return 0;
    $name = 'archer-ai-' . sanitize_title(mb_substr($subject, 0, 40)) . '-' . wp_generate_password(6, false) . '.png';
    $up = wp_upload_bits($name, null, $bytes);
    if (!empty($up['error'])) return 0;
    $file = $up['file'];
    $mime = 'image/png';
    // PNG van het model is zwaar (± 3 MB): als JPEG bewaren.
    $ed = wp_get_image_editor($file);
    if (!is_wp_error($ed)) {
      $ed->set_quality(82);
      $saved = $ed->save(preg_replace('/\.png$/i', '.jpg', $file), 'image/jpeg');
      if (!is_wp_error($saved) && !empty($saved['path'])) {
        @unlink($file);
        $file = $saved['path'];
        $mime = 'image/jpeg';
      }
    }
    $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => self::alt($subject), 'post_status' => 'inherit', 'post_parent' => 0], $file);
    if (is_wp_error($id) || !$id) return 0;
    if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $file));
    update_post_meta($id, '_wp_attachment_image_alt', self::alt($subject));
    update_post_meta($id, '_archer_source', self::SOURCE);
    update_post_meta($id, '_archer_project', $pid);
    return (int) $id;
  }
}
