<?php
/**
 * Otter Forms Handler
 *
 * Captures and displays Otter Blocks form data.
 * Shortcodes:
 *   [aotter_show field="My Field" fallback=""]
 *   [aotter_all_latest]
 *   [aotter_page field="My Field" page_id="123" fallback=""]
 *   [aotter_dump]
 *
 * @package LearnDash_Course_Toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDCT_Otter_Forms {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'register_cpt'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_capture_script'));
        add_action('wp_ajax_nopriv_aotter_latest_save', array($this, 'ajax_save'));
        add_action('wp_ajax_aotter_latest_save', array($this, 'ajax_save'));
        add_action('template_redirect', array($this, 'post_fallback'));

        // Shortcodes
        add_shortcode('aotter_show', array($this, 'shortcode_show'));
        add_shortcode('aotter_all_latest', array($this, 'shortcode_all_latest'));
        add_shortcode('aotter_page', array($this, 'shortcode_page'));
        add_shortcode('aotter_dump', array($this, 'shortcode_dump'));
    }

    /**
     * Register custom post type for guest storage
     */
    public function register_cpt() {
        register_post_type('aotter_entry', array(
            'label'   => 'Aotter Entries',
            'public'  => false,
            'show_ui' => false,
            'supports' => array('title'),
        ));
    }

    /**
     * Normalize field key
     */
    private function norm_key($k) {
        $k = wp_strip_all_tags((string)$k);
        $k = html_entity_decode($k, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $k = trim($k);
        $k = preg_replace('~^<|>$~', '', $k);
        $k = preg_replace('~\s*[:*]\s*$~', '', $k);
        return $k;
    }

    /**
     * Get or create guest session ID
     */
    private function guest_sid() {
        $sid = isset($_COOKIE['aotter_sid']) && preg_match('~^[A-Za-z0-9]{12,64}$~', $_COOKIE['aotter_sid'])
            ? $_COOKIE['aotter_sid'] : wp_generate_password(24, false);

        if (!isset($_COOKIE['aotter_sid']) || $_COOKIE['aotter_sid'] !== $sid) {
            setcookie('aotter_sid', $sid, time() + YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
            $_COOKIE['aotter_sid'] = $sid;
        }

        return $sid;
    }

    /**
     * Merge associative arrays
     */
    private function merge_assoc($old, $new) {
        $out = is_array($old) ? $old : array();
        foreach ((array)$new as $k => $v) {
            $nk = $this->norm_key($k);
            if ($nk === '') continue;

            if (is_array($v)) {
                $v = array_values(array_filter(array_map('sanitize_text_field', $v), 'strlen'));
            } else {
                $v = sanitize_text_field($v);
            }
            $out[$nk] = $v;
        }
        return $out;
    }

    /**
     * Save form data
     */
    public function save_maps($page_id, $fields) {
        $page_id = intval($page_id);

        if (is_user_logged_in()) {
            $uid = get_current_user_id();

            // Save per page
            $existing = get_user_meta($uid, "aotter_answers_{$page_id}", true);
            $merged   = $this->merge_assoc($existing, $fields);
            update_user_meta($uid, "aotter_answers_{$page_id}", $merged);

            // Save global "latest"
            $latest = get_user_meta($uid, "aotter_latest_fields", true);
            $latest = $this->merge_assoc($latest, $fields);
            update_user_meta($uid, "aotter_latest_fields", $latest);

            return array('mode' => 'user', 'page_id' => $page_id, 'count' => count($merged));
        } else {
            $sid = $this->guest_sid();

            // Per page (guest) via CPT
            $posts = get_posts(array(
                'post_type'    => 'aotter_entry',
                'post_status'  => 'publish',
                'numberposts'  => 1,
                'meta_query'   => array(
                    array('key' => '_aotter_sid', 'value' => $sid, 'compare' => '='),
                    array('key' => '_aotter_page', 'value' => $page_id, 'compare' => '='),
                ),
                'orderby' => 'ID',
                'order'   => 'DESC',
            ));

            if ($posts) {
                $pid = $posts[0]->ID;
                $existing = get_post_meta($pid, '_aotter_fields', true);
                $merged   = $this->merge_assoc($existing, $fields);
                update_post_meta($pid, '_aotter_fields', $merged);
            } else {
                $pid = wp_insert_post(array(
                    'post_type'   => 'aotter_entry',
                    'post_title'  => 'AOTTER ' . wp_generate_password(6, false),
                    'post_status' => 'publish'
                ));
                if (!is_wp_error($pid) && $pid) {
                    update_post_meta($pid, '_aotter_sid', $sid);
                    update_post_meta($pid, '_aotter_page', $page_id);
                    update_post_meta($pid, '_aotter_fields', $this->merge_assoc(array(), $fields));
                }
            }

            // Global latest (guest) in transient
            $gkey   = 'aotter_latest_' . $sid;
            $latest = get_transient($gkey);
            $latest = $this->merge_assoc(is_array($latest) ? $latest : array(), $fields);
            set_transient($gkey, $latest, DAY_IN_SECONDS);

            return array('mode' => 'guest', 'page_id' => $page_id, 'count' => is_array($latest) ? count($latest) : 0);
        }
    }

    /**
     * Read data for a specific page
     */
    public function read_page($page_id) {
        $page_id = intval($page_id);

        if (is_user_logged_in()) {
            $data = get_user_meta(get_current_user_id(), "aotter_answers_{$page_id}", true);
            return is_array($data) ? $data : array();
        } else {
            $sid = isset($_COOKIE['aotter_sid']) ? sanitize_text_field($_COOKIE['aotter_sid']) : '';
            if ($sid === '') return array();

            $posts = get_posts(array(
                'post_type'    => 'aotter_entry',
                'post_status'  => 'publish',
                'numberposts'  => 1,
                'meta_query'   => array(
                    array('key' => '_aotter_sid', 'value' => $sid, 'compare' => '='),
                    array('key' => '_aotter_page', 'value' => $page_id, 'compare' => '='),
                ),
                'orderby' => 'ID',
                'order'   => 'DESC',
            ));

            if (!$posts) return array();
            $data = get_post_meta($posts[0]->ID, '_aotter_fields', true);
            return is_array($data) ? $data : array();
        }
    }

    /**
     * Read latest data across all pages
     */
    public function read_latest() {
        if (is_user_logged_in()) {
            $data = get_user_meta(get_current_user_id(), "aotter_latest_fields", true);
            return is_array($data) ? $data : array();
        } else {
            $sid = isset($_COOKIE['aotter_sid']) ? sanitize_text_field($_COOKIE['aotter_sid']) : '';
            if ($sid === '') return array();
            $data = get_transient('aotter_latest_' . $sid);
            return is_array($data) ? $data : array();
        }
    }

    /**
     * Read all form data for a user (for course summary)
     */
    public function read_all_user_data($user_id = 0) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        if (!$user_id) return array();

        return $this->read_latest();
    }

    /**
     * Enqueue capture JavaScript
     */
    public function enqueue_capture_script() {
        if (is_admin()) return;

        $this->guest_sid();

        wp_register_script('aotter-capture', false, array(), null, true);
        wp_enqueue_script('aotter-capture');

        $cfg = array(
            'ajax'   => admin_url('admin-ajax.php'),
            'nonce'  => wp_create_nonce('aotter_latest_save'),
            'pageID' => get_queried_object_id(),
        );
        wp_add_inline_script('aotter-capture', 'window.AOTTERCFG=' . wp_json_encode($cfg) . ';', 'before');

        $js = <<<'JS'
(function(){
  function decode(s){try{var d=new DOMParser().parseFromString(s||'','text/html');return(d.documentElement.textContent||'').trim()}catch(e){return s||''}}
  function normKey(k){ if(!k) return ''; k=k.replace(/[<>]/g,'').trim(); k=k.replace(/\s*[:*]\s*$/,'').trim(); return k; }
  function keyFrom(container, el){
    var label = container.querySelector('.otter-form-input-label .otter-form-input-label__label') || container.querySelector('.otter-form-input-label');
    var text  = label ? (label.textContent||'') : '';
    text = text ? text.replace(/\s+/g,' ').trim() : '';
    if (text){
      var strong = label.querySelector('strong');
      if (strong && strong.textContent) text = strong.textContent.trim();
      else text = text.split('\n')[0].trim();
    }
    if ((!text || text.length<1) && el){
      var ph = el.getAttribute('placeholder') || el.getAttribute('aria-label') || el.getAttribute('name') || '';
      text = decode(ph);
    }
    return normKey(text);
  }
  function readVal(container, el){
    if(!el) return '';
    var t=(el.type||'').toLowerCase(), tag=(el.tagName||'').toLowerCase();
    if(tag==='select'){
      if(el.multiple){ return Array.from(el.selectedOptions||[]).map(o=>o.value||o.text).filter(Boolean); }
      return el.value;
    }
    if(t==='checkbox'){
      var group=container.querySelectorAll('input[type="checkbox"]');
      if(group.length>1){ var arr=[]; group.forEach(ch=>{if(ch.checked) arr.push(ch.value||'on')}); return arr; }
      return el.checked ? (el.value||'on') : '';
    }
    if(t==='radio'){ var on=container.querySelector('input[type="radio"]:checked'); return on? on.value:''; }
    return el.value;
  }
  function collect(form){
    var out={}, blocks=form.querySelectorAll('.wp-block-themeisle-blocks-form-input');
    blocks.forEach(function(c){
      var el=c.querySelector('input,textarea,select'); if(!el) return;
      var key=keyFrom(c, el); if(!key) return;
      out[key]=readVal(c, el);
      if(!el.name) el.name='aotter_'+key.replace(/[^A-Za-z0-9]+/g,'_').toLowerCase();
    });
    return out;
  }

  document.addEventListener('submit', function(e){
    var form = e.target.closest('form.otter-form__container'); if(!form) return;
    var fields = collect(form);
    var hid=form.querySelector('input[name="aotter_payload_latest"]');
    if(!hid){ hid=document.createElement('input'); hid.type='hidden'; hid.name='aotter_payload_latest'; form.appendChild(hid); }
    hid.value = JSON.stringify({page_id:(window.AOTTERCFG||{}).pageID||0, fields: fields});

    try{
      var payload={action:'aotter_latest_save', _ajax_nonce:(window.AOTTERCFG||{}).nonce, page_id:(window.AOTTERCFG||{}).pageID||0, fields: JSON.stringify(fields)};
      fetch((window.AOTTERCFG||{}).ajax,{method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:new URLSearchParams(payload).toString()}).catch(function(){});
    }catch(ex){}
  }, true);
})();
JS;

        wp_add_inline_script('aotter-capture', $js);
    }

    /**
     * AJAX save handler
     */
    public function ajax_save() {
        check_ajax_referer('aotter_latest_save', '_ajax_nonce');
        $page_id = isset($_POST['page_id']) ? intval($_POST['page_id']) : get_queried_object_id();
        $raw     = isset($_POST['fields']) ? wp_unslash($_POST['fields']) : '{}';
        $fields  = json_decode($raw, true);
        if (!is_array($fields)) $fields = array();
        $res = $this->save_maps($page_id, $fields);
        wp_send_json_success($res);
    }

    /**
     * POST fallback handler
     */
    public function post_fallback() {
        if (empty($_POST['aotter_payload_latest'])) return;
        $raw = wp_unslash($_POST['aotter_payload_latest']);
        $obj = json_decode($raw, true);
        if (!is_array($obj)) return;
        $page_id = isset($obj['page_id']) ? intval($obj['page_id']) : get_queried_object_id();
        $fields  = isset($obj['fields']) && is_array($obj['fields']) ? $obj['fields'] : array();
        $this->guest_sid();
        $this->save_maps($page_id, $fields);
    }

    /**
     * Shortcode: Show single field
     */
    public function shortcode_show($atts) {
        $a = shortcode_atts(array(
            'field'    => '',
            'fallback' => ''
        ), $atts, 'aotter_show');

        $field = $this->norm_key($a['field']);
        if ($field === '') return esc_html($a['fallback']);

        $data = $this->read_latest();
        $val  = is_array($data) ? ($data[$field] ?? '') : '';

        if (is_array($val)) {
            $val = implode(', ', array_filter($val, 'strlen'));
        }

        return esc_html($val !== '' ? $val : $a['fallback']);
    }

    /**
     * Shortcode: Show all latest fields
     */
    public function shortcode_all_latest() {
        $data = $this->read_latest();
        if (empty($data)) return '';

        $html = '<dl class="aotter-list">';
        foreach ($data as $k => $v) {
            $val = is_array($v) ? implode(', ', array_filter($v, 'strlen')) : $v;
            $html .= '<dt><strong>' . esc_html($k) . '</strong></dt><dd>' . esc_html($val) . '</dd>';
        }
        return $html . '</dl>';
    }

    /**
     * Shortcode: Show field from specific page
     */
    public function shortcode_page($atts) {
        $a = shortcode_atts(array(
            'field'    => '',
            'page_id'  => get_queried_object_id(),
            'fallback' => ''
        ), $atts, 'aotter_page');

        $field = $this->norm_key($a['field']);
        $data  = $this->read_page($a['page_id']);
        $val   = is_array($data) ? ($data[$field] ?? '') : '';

        if (is_array($val)) {
            $val = implode(', ', array_filter($val, 'strlen'));
        }

        return esc_html($val !== '' ? $val : $a['fallback']);
    }

    /**
     * Shortcode: Debug dump
     */
    public function shortcode_dump() {
        $page = get_queried_object_id();
        $out = array(
            'latest'     => $this->read_latest(),
            'this_page'  => $this->read_page($page),
            'page_id'    => $page,
            'logged_in'  => is_user_logged_in(),
            'user_id'    => get_current_user_id(),
        );
        return '<pre style="white-space:pre-wrap;overflow:auto;background:#0b0d12;color:#eaeef2;padding:8px;border-radius:8px">'
             . esc_html(wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
             . '</pre>';
    }
}
