<?php

if (!defined('ABSPATH')) exit;

/**
 * Koppelt wireframes aan Bricks templates.
 *
 * De klasse zoekt gepubliceerde Bricks templates, groepeert ze per type/tag en
 * kiest passende templates voor wireframe-secties.
 */
class AISB_Wireframes_Bricks {

  /**
   * Haalt alle gepubliceerde Bricks templates op en groepeert ze per tag slug.
   * Zonder tags valt de methode terug op de `_bricks_template_type` meta.
   * Het resultaat wordt per request gecached.
   *
   * @return array<string, list<array{id:int,title:string,ttype:string,tags:string[],shortcode:string}>>
   */
  public function get_bricks_templates_by_type(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    if (!post_type_exists('bricks_template')) {
      $cache = [];
      return $cache;
    }

    $posts = get_posts([
      'post_type'      => 'bricks_template',
      'post_status'    => 'publish',
      // Archer: alle sjablonen (er zijn er meer dan 500; anders vielen hele types weg, zoals panden).
      'posts_per_page' => -1,
      'orderby'        => 'title',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ]);

    $by_type = [];

    foreach ($posts as $post) {
      $id    = (int) $post->ID;

      $bricks_data_meta = get_post_meta($id, '_bricks_data', true);
      $content_2 = get_post_meta($id, '_bricks_page_content_2', true);
      $header_2  = get_post_meta($id, '_bricks_page_header_2', true);
      $footer_2  = get_post_meta($id, '_bricks_page_footer_2', true);

      if (empty($bricks_data_meta) && empty($content_2) && empty($header_2) && empty($footer_2)) {
        continue;
      }

      $title = (string) $post->post_title;
      if (strpos($title, '[AI]') === 0) {
        continue;
      }

      $ttype = (string) (get_post_meta($id, '_bricks_template_type', true) ?: '');
        $tags_raw  = get_the_terms($id, 'template_tag');
        $tags = [];
        if (!empty($tags_raw) && !is_wp_error($tags_raw)) {
            $tags = wp_list_pluck($tags_raw, 'slug');
        }
      $type_keys = array_map('strtolower', (array) $tags);

      if (empty($type_keys) && $ttype !== '') {
        $type_keys = [strtolower($ttype)];
      }

      $entry = [
        'id'        => $id,
        'title'     => $title,
        'ttype'     => $ttype,
        'tags'      => $type_keys,
        'shortcode' => '[bricks_template id="' . $id . '"]',
      ];

      foreach ($type_keys as $key) {
        if (!isset($by_type[$key])) $by_type[$key] = [];
        $by_type[$key][] = $entry;
      }
    }

    $cache = $by_type;
    return $cache;
  }

  /**
   * Het aantal kaarten dat dit sjabloon echt toont, of 0 als er geen kaartenrij in zit.
   *
   * Geteld op de elementen zoals ze er na de huisregels uitzien (geen label boven de titel, geen
   * verzonnen tellers), want dat is wat de bezoeker krijgt. Zonder die stap tel je kaarten die bij
   * het opslaan alsnog verdwijnen.
   */
  public static function card_count(int $template_id): int {
    static $cache = [];
    if (isset($cache[$template_id])) return $cache[$template_id];
    $aantal = 0;
    $nodes = get_post_meta($template_id, '_bricks_page_content_2', true);
    if (is_array($nodes) && $nodes && class_exists('AISB_Archer_Section_Edit')) {
      if (class_exists('AISB_Archer_Taste')) $nodes = AISB_Archer_Taste::house_nodes(array_values($nodes));
      if (class_exists('AISB_Archer_Site')) $nodes = AISB_Archer_Site::proof(array_values($nodes));
      [$ouder, $leden] = AISB_Archer_Section_Edit::card_row($nodes);
      $aantal = count($leden);
    }
    return $cache[$template_id] = $aantal;
  }

  /**
   * Pick a Bricks template for $section_type, respecting exclusions.
   * Falls back to configured aliases when no direct match exists.
   *
   * Somt de sitemap dingen op in deze sectie (drie medewerkers, vier stappen), dan kiest hij het
   * sjabloon met precies zo veel kaarten. Het snoeien achteraf kan er alleen afhalen, dus beginnen
   * met een sjabloon dat te weinig kaarten heeft kost een kaart die er hoort te staan. Bij een
   * gelijke stand blijft het toeval beslissen, zodat twee sites er niet hetzelfde uitzien.
   *
   * @param array<string,list<array>> $by_type     Output of get_bricks_templates_by_type()
   * @param int[]                     $exclude_ids Template IDs already used on this page
   * @param int                       $items       Aantal dingen dat deze sectie opsomt (0 = onbekend)
   */
  public function pick_bricks_template(string $section_type, array $by_type, array $exclude_ids = [], int $items = 0): ?array {
    $kies = function(array $pool) use ($items, $section_type): ?array {
      if (!$pool) return null;
      if ($items < 2) return $pool[array_rand($pool)];
      $beste = null; $gelijk = [];
      foreach ($pool as $t) {
        $kaarten = self::card_count((int) $t['id']);
        // Precies goed (0) boven één te veel of te weinig (-2 per kaart verschil). Een sjabloon
        // zonder kaartenrij kan de dingen niet naast elkaar tonen: dat gaat helemaal achteraan.
        $score = $kaarten > 0 ? -2 * abs($kaarten - $items) : -100;
        if ($beste === null || $score > $beste) { $beste = $score; $gelijk = [$t]; }
        elseif ($score === $beste) { $gelijk[] = $t; }
      }
      $keuze = $gelijk[array_rand($gelijk)];
      error_log('[AISB] Sjabloon voor ' . $section_type . ': ' . $keuze['title'] . ' (#' . $keuze['id'] . ', '
        . self::card_count((int) $keuze['id']) . ' kaarten) bij ' . $items . ' item(s), uit ' . count($pool)
        . ' sjablonen, score ' . $beste . '.');
      return $keuze;
    };
    $try = function(string $key) use ($by_type, $exclude_ids, $kies): ?array {
      $pool = $by_type[strtolower($key)] ?? [];
      // Archer: sjablonen weglaten die niet passen bij wat al op de pagina staat (bv. een tweede formulier).
      $pool = (array) apply_filters('aisb_bricks_template_pool', $pool, strtolower($key), $exclude_ids);
      if ($exclude_ids) {
        $pool = array_values(array_filter($pool, function($t) use ($exclude_ids) {
          return !in_array((int) $t['id'], $exclude_ids, true);
        }));
      }
      return $kies(array_values($pool));
    };

    $tpl = $try($section_type);
    if ($tpl) return $tpl;

    foreach ($this->section_type_aliases()[$section_type] ?? [] as $alt) {
      $tpl = $try($alt);
      if ($tpl) return $tpl;
    }

      foreach (['content', 'features', 'generic'] as $alt) {
        $tpl = $try($alt);
        if ($tpl) return $tpl;
      }

      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          if (!in_array((int) $t['id'], $exclude_ids, true)) {
            $all_pool[$t['id']] = $t;
          }
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      $all_pool = [];
      foreach ($by_type as $k => $templates) {
        if (in_array(strtolower($k), ['header', 'footer'])) continue;
        foreach ($templates as $t) {
          $all_pool[$t['id']] = $t;
        }
      }
      if ($all_pool) {
        return $all_pool[array_rand($all_pool)];
      }

      return null;
    }

  /** Tag aliases so e.g. "features" also matches "feature", "services" etc. */
  public function section_type_aliases(): array {
    return [
      'features'     => ['feature', 'benefits', 'benefit', 'services', 'service'],
      'testimonials' => ['testimonial', 'reviews', 'review'],
      'cta'          => ['call-to-action', 'call_to_action'],
      'faq'          => ['faqs', 'questions'],
      'pricing'      => ['plans', 'plan', 'packages'],
      'social_proof' => ['logos', 'clients', 'brands', 'partners', 'trust'],
      'team'         => ['staff', 'people'],
      'contact_form' => ['contact', 'form', 'contact-form'],
      'header'       => ['nav', 'navigation', 'navbar', 'menu'],
      'footer'       => ['foot', 'bottom'],
    ];
  }

  /**
   * All unique section-type keys: built-ins merged with available Bricks tags.
   * @return string[]
   */
  public function get_all_section_types(): array {
    $builtin = ['header','hero','features','process','testimonials','pricing','faq','cta','content','team','story','values','contact_form','locations','social_proof','footer','generic'];
    $bricks  = array_keys($this->get_bricks_templates_by_type());
    $merged  = array_unique(array_merge($builtin, $bricks));
    sort($merged);
    return array_values($merged);
  }
}
