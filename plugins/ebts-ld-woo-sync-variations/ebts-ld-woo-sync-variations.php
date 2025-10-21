<?php
/**
 * Plugin Name: EBTS – Woo ⟶ LearnDash Sync (Per-Variation Courses) + Sessions
 * Description: Crea/aggiorna automaticamente corsi LearnDash da prodotti WooCommerce (anche per variazione) con supporto a più sessioni (range di date) e sede. Gestisce l'iscrizione al corso esatto acquistato.
 * Version: 1.1.8-sessioned
 * Author: EBTS
 * License: GPLv2 or later
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('EBTS_WooLD_Sync_Variations')) {
final class EBTS_WooLD_Sync_Variations {

  // Legacy / base meta
  const META_LINKED_COURSE       = '_ebts_ld_course_id';      // su prodotto o su variazione
  const META_ENABLE_SYNC         = '_ebts_ld_enable_sync';    // yes/no
  const META_PER_VARIATION       = '_ebts_ld_per_variation';  // yes/no

  // New: Sessions
  const META_DATE_RANGES         = '_ebts_ld_date_ranges';     // array<range>
  const META_TITLE_TPL_RANGE     = '_ebts_ld_title_tpl_range'; // template titolo per sessione
  const ITEMMETA_RANGE_ID        = '_ebts_ld_range_id';        // cart/order item
  const ITEMMETA_COURSE_ID       = '_ebts_ld_course_id';       // cart/order item (corso della sessione)
  const COURSE_META_RANGE_ID     = '_ebts_ld_range_id';        // meta sul corso LD
  const COURSE_META_RANGE_START  = '_ebts_ld_start';
  const COURSE_META_RANGE_END    = '_ebts_ld_end';
  const COURSE_META_SEDE         = '_ebts_ld_sede';

  static function boot(){
    // Admin UI
    add_filter('woocommerce_product_data_tabs', [__CLASS__,'add_tab']);
    add_action('woocommerce_product_data_panels', [__CLASS__,'add_panel']);
    add_action('woocommerce_admin_process_product_object', [__CLASS__,'save_product_meta'], 20);
    add_action('woocommerce_product_after_variable_attributes', [__CLASS__,'render_variation_fields'], 20, 3);
    add_action('woocommerce_save_product_variation', [__CLASS__,'save_variation_meta'], 20, 2);
    add_action('admin_enqueue_scripts', [__CLASS__,'admin_assets']);

    // Generate courses on save
    add_action('woocommerce_after_product_object_save', [__CLASS__,'generate_courses_for_product'], 15, 1);
    add_action('woocommerce_ajax_save_product_variations', [__CLASS__,'generate_courses_for_product_from_ajax'], 20);

    // Product list column may exist in original plugin. Keep minimal.

    // Front-end
    add_action('woocommerce_before_add_to_cart_button', [__CLASS__,'render_frontend_picker']);
    add_action('wp_enqueue_scripts', [__CLASS__,'frontend_assets']);
    add_filter('woocommerce_available_variation', [__CLASS__,'expose_ranges_on_variation'], 10, 3);

    // Cart/Order meta
    add_filter('woocommerce_add_cart_item_data', [__CLASS__,'add_cart_item_data'], 10, 3);
    add_filter('woocommerce_get_item_data', [__CLASS__,'show_item_data'], 10, 2);
    add_action('woocommerce_checkout_create_order_line_item', [__CLASS__,'copy_to_order_item'], 10, 3);

    // Enrollment after purchase (multiple triggers to be safe)
    add_action('woocommerce_order_status_completed', [__CLASS__,'enroll_from_order'], 20);
    add_action('woocommerce_payment_complete', [__CLASS__,'enroll_from_order'], 20);
    add_action('woocommerce_thankyou', [__CLASS__,'enroll_from_order'], 20);

    // Safety: sanitize legacy wrong meta key if exists
    add_action('save_post_product', [__CLASS__,'cleanup_legacy_meta'], 10, 3);
  }

  /* =======================
   * Helpers (sessions, sede)
   * ======================= */

  static function sanitize_ranges($ranges, $product_id, $variation_id = 0){
    $out = [];
    if (!is_array($ranges)) return $out;
    foreach ($ranges as $r){
      $start = isset($r['start']) ? preg_replace('~[^0-9\-]~','', $r['start']) : '';
      $end   = isset($r['end'])   ? preg_replace('~[^0-9\-]~','', $r['end'])   : '';
      $id    = !empty($r['id']) ? sanitize_text_field($r['id']) : wp_generate_uuid4();
      $row   = ['id'=>$id,'start'=>$start,'end'=>$end];
      if (!$variation_id) { // prodotto semplice → sede esplicita
        $row['sede'] = isset($r['sede']) ? sanitize_title($r['sede']) : '';
      }
      if (!empty($r['course_id'])) $row['course_id'] = absint($r['course_id']);
      $out[] = $row;
    }
    return $out;
  }

  static function get_variation_sede_slug($variation){
    if (is_object($variation) && method_exists($variation,'get_attributes')){
      foreach ($variation->get_attributes() as $name=>$val){
        if ($name==='pa_sede' || stripos($name,'sede')!==false) return sanitize_title($val);
      }
    }
    return '';
  }

  static function title_for_range($product, $variation_or_null, $range){
    $tpl = get_post_meta($product->get_id(), self::META_TITLE_TPL_RANGE, true);
    if (!$tpl) $tpl = '{product} — {sede} — {start}→{end}';
    $sede = isset($range['sede']) ? $range['sede'] : ($variation_or_null ? self::get_variation_sede_slug($variation_or_null) : '');
    $repl = [
      '{product}' => $product->get_name(),
      '{sede}'    => $sede,
      '{start}'   => isset($range['start']) ? $range['start'] : '',
      '{end}'     => isset($range['end']) ? $range['end'] : '',
    ];
    return strtr($tpl, $repl);
  }

  /* =======================
   * Admin UI
   * ======================= */

  static function add_tab($tabs){
    $tabs['ebts_ld'] = [
      'label'    => __('LearnDash','ebts-ld-sync'),
      'target'   => 'ebts_ld_panel',
      'class'    => ['show_if_simple','show_if_variable'],
      'priority' => 60,
    ];
    return $tabs;
  }

  static function admin_assets($hook){
    if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
    $screen = get_current_screen();
    if (!$screen || $screen->post_type !== 'product') return;
    wp_enqueue_script('ebts-ld-admin-ranges', plugins_url('ebts-ld-admin-ranges.js', __FILE__), ['jquery'], '1.0', true);
  }

  static function add_panel(){
    global $post; $product = wc_get_product($post->ID);
    if (!$product) return;
    $is_var = $product->is_type('variable');
    $tpl = get_post_meta($product->get_id(), self::META_TITLE_TPL_RANGE, true);
    if (!$tpl) $tpl = '{product} — {sede} — {start}→{end}';

    // Available Sede terms assigned to product (for simple)
    $sede_terms = [];
    if (!$is_var){
      $attrs = $product->get_attributes();
      if (isset($attrs['pa_sede']) && $attrs['pa_sede']->is_taxonomy()){
        $sede_terms = array_filter(array_map(function($term_id){
          $t = get_term($term_id);
          return ($t && !is_wp_error($t)) ? ['slug'=>$t->slug, 'name'=>$t->name] : null;
        }, $attrs['pa_sede']->get_options()));
      }
    }

    ?>
    <div id="ebts_ld_panel" class="panel woocommerce_options_panel">
      <div class="options_group">
        <?php
        woocommerce_wp_text_input([
          'id'          => self::META_TITLE_TPL_RANGE,
          'label'       => __('Template titolo sessione','ebts-ld-sync'),
          'desc_tip'    => true,
          'description' => __('Segnaposto: {product}, {sede}, {start}, {end}','ebts-ld-sync'),
          'value'       => $tpl,
        ]);
        ?>
        <p><strong><?php _e('Sessioni (date di inizio/fine)','ebts-ld-sync'); ?></strong></p>

        <?php if (!$is_var): 
          $ranges = get_post_meta($product->get_id(), self::META_DATE_RANGES, true);
          if (!is_array($ranges)) $ranges = [];
        ?>
        <table class="widefat ebts-ld-ranges-table" data-simple="1">
          <thead><tr>
            <th><?php _e('Sede','ebts-ld-sync'); ?></th>
            <th><?php _e('Inizio','ebts-ld-sync'); ?></th>
            <th><?php _e('Fine','ebts-ld-sync'); ?></th>
            <th><?php _e('Corso LD','ebts-ld-sync'); ?></th>
            <th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($ranges as $row): ?>
            <tr>
              <td>
                <select class="ebts-sede-select">
                <?php foreach ($sede_terms as $t): ?>
                  <option value="<?php echo esc_attr($t['slug']); ?>" <?php selected(isset($row['sede'])?$row['sede']:'', $t['slug']); ?>><?php echo esc_html($t['name']); ?></option>
                <?php endforeach; ?>
                </select>
              </td>
              <td><input type="date" class="ebts-start" value="<?php echo esc_attr(isset($row['start'])?$row['start']:''); ?>"></td>
              <td><input type="date" class="ebts-end" value="<?php echo esc_attr(isset($row['end'])?$row['end']:''); ?>"></td>
              <td>
                <input type="text" class="ebts-course-id" value="<?php echo esc_attr(isset($row['course_id'])?$row['course_id']:''); ?>" size="6" placeholder="ID">
                <?php if (!empty($row['course_id']) && get_post_type((int)$row['course_id'])==='sfwd-courses'): ?>
                  <span class="description" style="margin-left:6px;white-space:nowrap;">
                    <a href="<?php echo esc_url(get_edit_post_link((int)$row['course_id'])); ?>" target="_blank"><?php _e('Modifica corso','ebts-ld-sync'); ?></a>
                    · <a href="<?php echo esc_url(get_permalink((int)$row['course_id'])); ?>" target="_blank"><?php _e('Apri','ebts-ld-sync'); ?></a>
                  </span>
                <?php endif; ?>
              </td>
              <td><a href="#" class="button ebts-remove-range">&times;</a><input type="hidden" class="ebts-id" value="<?php echo esc_attr(isset($row['id'])?$row['id']:''); ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p><a href="#" class="button" id="ebts-add-range">+ <?php _e('Aggiungi range','ebts-ld-sync'); ?></a></p>
        <input type="hidden" id="ebts_ld_ranges_json" name="ebts_ld_ranges_json" value='<?php echo esc_attr(wp_json_encode($ranges)); ?>'>
        <input type="hidden" id="ebts_ld_sede_terms_json" value='<?php echo esc_attr(wp_json_encode(array_values($sede_terms))); ?>'>
        <?php else: ?>
          <p class="description"><?php _e('Per i prodotti variabili, configura le sessioni direttamente nelle variazioni (vedi sotto).','ebts-ld-sync'); ?></p>
        <?php endif; ?>
      </div>
      <?php if ($is_var): ?>
        <div class="options_group">
          <p><strong><?php _e('Sessioni per variazione','ebts-ld-sync'); ?></strong></p>
          <p class="description"><?php _e('Apri ciascuna variazione per gestire le sue sessioni (la sede è quella della variazione).','ebts-ld-sync'); ?></p>
        </div>
      <?php endif; ?>
    </div>
    <?php
  }

  static function render_variation_fields($loop, $variation_data, $variation){
    $vid    = $variation->ID;
    $ranges = get_post_meta($vid, self::META_DATE_RANGES, true);
    if (!is_array($ranges)) $ranges = [];
    $sede_slug = self::get_variation_sede_slug($variation);
    ?>
    <div class="ebts-ld-var-ranges" data-variation-id="<?php echo esc_attr($vid); ?>">
      <p><strong><?php _e('Sessioni (date) per questa sede','ebts-ld-sync'); ?></strong>
         — <?php echo esc_html($sede_slug ? $sede_slug : __('(nessuna sede)','ebts-ld-sync')); ?></p>
      <table class="widefat ebts-ld-ranges-table">
        <thead><tr><th><?php _e('Inizio'); ?></th><th><?php _e('Fine'); ?></th><th><?php _e('Corso LD'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ranges as $row): ?>
          <tr>
            <td><input type="date" class="ebts-start" value="<?php echo esc_attr(isset($row['start'])?$row['start']:''); ?>"></td>
            <td><input type="date" class="ebts-end"   value="<?php echo esc_attr(isset($row['end'])?$row['end']:''); ?>"></td>
            <td>
              <input type="text" class="ebts-course-id" value="<?php echo esc_attr(isset($row['course_id'])?$row['course_id']:''); ?>" size="6" placeholder="ID">
              <?php if (!empty($row['course_id']) && get_post_type((int)$row['course_id'])==='sfwd-courses'): ?>
                <span class="description" style="margin-left:6px;white-space:nowrap;">
                  <a href="<?php echo esc_url(get_edit_post_link((int)$row['course_id'])); ?>" target="_blank"><?php _e('Modifica corso','ebts-ld-sync'); ?></a>
                  · <a href="<?php echo esc_url(get_permalink((int)$row['course_id'])); ?>" target="_blank"><?php _e('Apri','ebts-ld-sync'); ?></a>
                </span>
              <?php endif; ?>
            </td>
            <td><a href="#" class="button ebts-remove-range">&times;</a><input type="hidden" class="ebts-id" value="<?php echo esc_attr(isset($row['id'])?$row['id']:''); ?>"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p><a href="#" class="button ebts-add-range-var">+ <?php _e('Aggiungi range','ebts-ld-sync'); ?></a></p>
      <input type="hidden" name="ebts_ld_ranges_var[<?php echo esc_attr($vid); ?>]" class="ebts_ld_ranges_json_var" value='<?php echo esc_attr(wp_json_encode($ranges)); ?>'>
    </div>
    <?php
  }

  static function save_product_meta($product){
    if ($product->is_type('variable')) {
      if (isset($_POST[self::META_TITLE_TPL_RANGE])) {
        update_post_meta($product->get_id(), self::META_TITLE_TPL_RANGE, sanitize_text_field(wp_unslash($_POST[self::META_TITLE_TPL_RANGE])));
      }
      return;
    }
    if (isset($_POST[self::META_TITLE_TPL_RANGE])) {
      update_post_meta($product->get_id(), self::META_TITLE_TPL_RANGE, sanitize_text_field(wp_unslash($_POST[self::META_TITLE_TPL_RANGE])));
    }
    if (isset($_POST['ebts_ld_ranges_json'])) {
      $ranges = json_decode(stripslashes($_POST['ebts_ld_ranges_json']), true);
      $clean  = self::sanitize_ranges($ranges, $product->get_id(), 0);
      update_post_meta($product->get_id(), self::META_DATE_RANGES, $clean);
    }
  }

  static function save_variation_meta($variation_id, $i){
    if (isset($_POST['ebts_ld_ranges_var'][$variation_id])) {
      $ranges = json_decode(stripslashes($_POST['ebts_ld_ranges_var'][$variation_id]), true);
      $clean  = self::sanitize_ranges($ranges, wp_get_post_parent_id($variation_id), $variation_id);
      update_post_meta($variation_id, self::META_DATE_RANGES, $clean);
    }
  }

  /* =======================
   * Generation of LD courses
   * ======================= */

  static function ensure_course_for_range($product, $variation_or_null, &$range){
    $course_id = !empty($range['course_id']) ? absint($range['course_id']) : 0;
    if ($course_id && get_post_type($course_id) !== 'sfwd-courses') $course_id = 0;

    $title = self::title_for_range($product, $variation_or_null, $range);
    $args = [
      'post_type'   => 'sfwd-courses',
      'post_status' => 'publish',
      'post_title'  => $title,
      'post_content'=> $product->get_description(),
      'post_excerpt'=> $product->get_short_description(),
    ];
    if (!$course_id){
      $course_id = wp_insert_post($args);
    } else {
      $args['ID'] = $course_id;
      wp_update_post($args);
    }
    if ($thumb = $product->get_image_id()) set_post_thumbnail($course_id, $thumb);

    update_post_meta($course_id, '_ebts_wc_product_id', $product->get_id());
    update_post_meta($course_id, self::COURSE_META_RANGE_ID,    $range['id']);
    update_post_meta($course_id, self::COURSE_META_RANGE_START, isset($range['start'])?$range['start']:'');
    update_post_meta($course_id, self::COURSE_META_RANGE_END,   isset($range['end'])?$range['end']:'');
    $sede = isset($range['sede']) ? $range['sede'] : ($variation_or_null ? self::get_variation_sede_slug($variation_or_null) : '');
    update_post_meta($course_id, self::COURSE_META_SEDE, $sede);

    $range['course_id'] = $course_id;
    return $course_id;
  }

  static function generate_courses_for_product($product){
    if (!$product) return;
    if ($product->is_type('variable')){
      foreach ($product->get_children() as $vid){
        $variation = wc_get_product($vid);
        $ranges = get_post_meta($vid, self::META_DATE_RANGES, true);
        if (!is_array($ranges) || !count($ranges)) continue;
        foreach ($ranges as &$r){ self::ensure_course_for_range($product, $variation, $r); }
        update_post_meta($vid, self::META_DATE_RANGES, $ranges);
      }
    } else {
      $ranges = get_post_meta($product->get_id(), self::META_DATE_RANGES, true);
      if (!is_array($ranges) || !count($ranges)) return;
      foreach ($ranges as &$r){ self::ensure_course_for_range($product, null, $r); }
      update_post_meta($product->get_id(), self::META_DATE_RANGES, $ranges);
    }
  }

  static function generate_courses_for_product_from_ajax(){
    if (empty($_POST['product_id'])) return;
    $product = wc_get_product(absint($_POST['product_id']));
    if ($product) self::generate_courses_for_product($product);
  }

  /* =======================
   * Front-end UI
   * ======================= */

  static function frontend_assets(){
    if (!is_product()) return;
    wp_enqueue_script('ebts-ld-ranges-frontend', plugins_url('ebts-ld-ranges-frontend.js', __FILE__), ['jquery'], '1.0', true);
  }

  static function render_frontend_picker(){
    global $product; if (!$product) return;
    if ($product->is_type('variable')){
      echo '<div id="ebts-range-picker" style="display:none;margin:10px 0;">'
         . '<label>'.esc_html__('Seleziona sessione','ebts-ld-sync').'</label> '
         . '<select id="ebts_range_select" name="ebts_ld_range_id"></select>'
         . '</div>';
    } else {
      $ranges = get_post_meta($product->get_id(), self::META_DATE_RANGES, true);
      if ($ranges && is_array($ranges)){
        // filter future-only + sort by start
        $today = current_time('Y-m-d');
        $ranges = array_values(array_filter($ranges, function($r) use ($today){
          $s = isset($r['start'])?$r['start']:'';
          $e = isset($r['end'])?$r['end']:'';
          if ($e) return $e >= $today;
          if ($s) return $s >= $today;
          return false;
        }));
        usort($ranges, function($a,$b){
          $as = isset($a['start'])?$a['start']:'';
          $bs = isset($b['start'])?$b['start']:'';
          if ($as === $bs) return strcmp(isset($a['end'])?$a['end']:'', isset($b['end'])?$b['end']:'');
          return strcmp($as, $bs);
        });

        if ($ranges){
          echo '<div id="ebts-range-picker" style="margin:10px 0;">'
             . '<label>'.esc_html__('Seleziona sessione','ebts-ld-sync').'</label> '
             . '<select id="ebts_range_select" name="ebts_ld_range_id" required>';
          foreach ($ranges as $r){
            $label = esc_html( ( (!empty($r['sede'])) ? $r['sede'].' — ' : '' ) . (isset($r['start'])?$r['start']:'') . ' → ' . (isset($r['end'])?$r['end']:'') );
            printf('<option value="%s">%s</option>', esc_attr($r['id']), $label);
          }
          echo '</select></div>';
        }
      }
    }
  }

  static function expose_ranges_on_variation($data, $product, $variation){
    $ranges = get_post_meta($variation->get_id(), self::META_DATE_RANGES, true);
    $data['ebts_ld_ranges'] = is_array($ranges) ? array_values($ranges) : [];
    return $data;
  }

  /* =======================
   * Cart & Order meta
   * ======================= */

  static function add_cart_item_data($cart_item_data, $product_id, $variation_id){
    if (empty($_POST['ebts_ld_range_id'])) return $cart_item_data;
    $range_id = sanitize_text_field(wp_unslash($_POST['ebts_ld_range_id']));
    $owner_id = $variation_id ?: $product_id;
    $ranges   = get_post_meta($owner_id, self::META_DATE_RANGES, true);
    $course_id = 0;
    if (is_array($ranges)){
      foreach ($ranges as $r){
        if (!empty($r['id']) && $r['id']===$range_id){
          $course_id = absint(isset($r['course_id'])?$r['course_id']:0);
          break;
        }
      }
    }
    if ($course_id) $cart_item_data[self::ITEMMETA_COURSE_ID] = $course_id;
    $cart_item_data[self::ITEMMETA_RANGE_ID] = $range_id;
    return $cart_item_data;
  }

  static function show_item_data($data, $cart_item){
    if (!empty($cart_item[self::ITEMMETA_RANGE_ID])){
      $data[] = ['name'=>__('Sessione','ebts-ld-sync'), 'value'=>$cart_item[self::ITEMMETA_RANGE_ID]];
    }
    return $data;
  }

  static function copy_to_order_item($item, $cart_item_key, $values){
    if (!empty($values[self::ITEMMETA_RANGE_ID]))
      $item->add_meta_data(self::ITEMMETA_RANGE_ID, $values[self::ITEMMETA_RANGE_ID]);
    if (!empty($values[self::ITEMMETA_COURSE_ID]))
      $item->add_meta_data(self::ITEMMETA_COURSE_ID, $values[self::ITEMMETA_COURSE_ID]);
  }

  /* =======================
   * Enrollment after purchase
   * ======================= */

  static function enroll_from_order($order_id){
    $order = wc_get_order($order_id);
    if (!$order) return;

    foreach ($order->get_items() as $item){
      $course_id = (int) $item->get_meta(self::ITEMMETA_COURSE_ID);
      if (!$course_id){
        $range_id = (string) $item->get_meta(self::ITEMMETA_RANGE_ID);
        $owner_id = $item->get_variation_id() ?: $item->get_product_id();
        $ranges   = get_post_meta($owner_id, self::META_DATE_RANGES, true);
        if (is_array($ranges)){
          foreach ($ranges as $r){
            if (!empty($r['id']) && $r['id']===$range_id){
              $course_id = (int) (isset($r['course_id']) ? $r['course_id'] : 0);
              break;
            }
          }
        }
      }
      if ($course_id && get_post_type($course_id)==='sfwd-courses'){
        $user_id = $order->get_user_id();
        if (!$user_id) continue;
        if (function_exists('learndash_enroll_user')) {
          learndash_enroll_user($user_id, $course_id);
        } elseif (function_exists('ld_update_course_access')) {
          ld_update_course_access($user_id, $course_id, true);
        }
      }
    }
  }

  /* =======================
   * Misc
   * ======================= */

  static function cleanup_legacy_meta($post_id, $post, $update){
    // Handle weird legacy key if exists (example placeholder)
    delete_post_meta($post_id, '_ebts_ld_per_variATION');
  }
}
EBTS_WooLD_Sync_Variations::boot();

/* =========
 * JS assets
 * ========= */
add_action('admin_footer', function(){
  $screen = get_current_screen();
  if (!$screen || $screen->post_type!=='product') return;
  ?>
  <script id="ebts-ld-admin-ranges-js">
  jQuery(function($){
    function tableToJSON($table){
      var rows=[];
      $table.find('tbody tr').each(function(){
        var $tr=$(this), row={
          id: $tr.find('.ebts-id').val() || (window.crypto && crypto.randomUUID ? crypto.randomUUID() : (Date.now()+''+Math.random()).replace('.','')),
          start: $tr.find('.ebts-start').val(),
          end: $tr.find('.ebts-end').val(),
          course_id: $tr.find('.ebts-course-id').val()
        };
        var $sede=$tr.find('.ebts-sede-select'); if ($sede.length) row.sede=$sede.val();
        rows.push(row);
      });
      return rows;
    }
    function syncHidden(){
      var $simple=$('#ebts_ld_ranges_json');
      if ($simple.length){
        $simple.val(JSON.stringify(tableToJSON($('#ebts_ld_panel .ebts-ld-ranges-table'))));
      }
      $('.ebts_ld_ranges_json_var').each(function(){
        var $json=$(this), $table=$json.closest('.ebts-ld-var-ranges').find('table');
        $json.val(JSON.stringify(tableToJSON($table)));
      });
    }
    $(document).on('click','.ebts-remove-range',function(e){ e.preventDefault(); $(this).closest('tr').remove(); syncHidden(); });
    $(document).on('change','#ebts_ld_panel input, #ebts_ld_panel select, .ebts-ld-var-ranges input', syncHidden);
    $(document).on('click','#ebts-add-range, .ebts-add-range-var',function(e){
      e.preventDefault();
      var isVar=$(this).hasClass('ebts-add-range-var');
      var $table=isVar?$(this).closest('.ebts-ld-var-ranges').find('table'):$('#ebts_ld_panel .ebts-ld-ranges-table');
      var row='<tr>'+(isVar?'':'<td><select class="ebts-sede-select"></select></td>')+
        '<td><input type="date" class="ebts-start"></td>'+
        '<td><input type="date" class="ebts-end"></td>'+
        '<td><input type="text" class="ebts-course-id" size="6" placeholder="ID"></td>'+
        '<td><a href="#" class="button ebts-remove-range">&times;</a><input type="hidden" class="ebts-id" value=""></td></tr>';
      $table.find('tbody').append(row);
      if (!isVar){
        var terms=[];
        try { terms=JSON.parse($('#ebts_ld_sede_terms_json').val()||'[]'); } catch(e){}
        var $sel=$table.find('tbody tr:last .ebts-sede-select');
        terms.forEach(function(t){ $('<option>').val(t.slug).text(t.name).appendTo($sel); });
      }
      syncHidden();
    });
    syncHidden();
  });
  </script>
  <?php
});

add_action('wp_footer', function(){
  if (!is_product()) return; ?>
  <script id="ebts-ld-ranges-frontend-js">
  jQuery(function($){
    function todayISO(){
      var d=new Date(), off=d.getTimezoneOffset(), local=new Date(d.getTime()-off*60000);
      return local.toISOString().slice(0,10);
    }
    function isFutureRange(r,today){
      var s=r.start||'', e=r.end||'';
      if (e) return e>=today;
      if (s) return s>=today;
      return false;
    }
    function sortRanges(a,b){
      var as=a.start||'', bs=b.start||'';
      if (as===bs) return (a.end||'').localeCompare(b.end||'');
      return as.localeCompare(bs);
    }
    var $wrap=$('#ebts-range-picker'), $sel=$('#ebts_range_select');
    $(document.body).on('found_variation','form.variations_form',function(e,variation){
      var ranges=(variation.ebts_ld_ranges||[]).filter(function(r){ return isFutureRange(r,todayISO()); }).sort(sortRanges);
      $sel.empty();
      if (ranges.length){
        ranges.forEach(function(r){ $sel.append($('<option>').val(r.id).text((r.start||'')+' → '+(r.end||''))); });
        $sel.prop('required',true); $wrap.show();
      } else { $sel.prop('required',false); $wrap.hide(); }
    });
    $(document.body).on('reset_data','form.variations_form',function(){ $wrap.hide(); $sel.empty().prop('required',false); });
  });
  </script>
  <?php
});
}
