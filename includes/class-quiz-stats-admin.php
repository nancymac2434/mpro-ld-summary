<?php
/**
 * Quiz Statistics Admin Handler
 *
 * Enables/disables ProQuiz statistics for LearnDash quizzes (multisite-safe).
 * Usage: wp-admin/?ld_stats_all=preview|on|off[&keep_ip=1]
 *
 * @package LearnDash_Course_Toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDCT_Quiz_Stats_Admin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'handle_stats_toggle'));
    }

    /**
     * Handle the stats toggle via URL parameter
     */
    public function handle_stats_toggle() {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['ld_stats_all'])) {
            return;
        }

        global $wpdb;

        $mode   = sanitize_key($_GET['ld_stats_all']); // preview|on|off|1|0
        $keepIP = isset($_GET['keep_ip']);
        $msgs   = array();

        // Find all quiz_pro_id used by this site's quizzes
        $ids = $wpdb->get_col("
            SELECT CAST(pm.meta_value AS UNSIGNED)
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'sfwd-quiz' AND pm.meta_key = 'quiz_pro_id' AND pm.meta_value <> ''
        ");

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (empty($ids)) {
            wp_die('No quiz_pro_id found on this site. Open a quiz and click Update once, or run LD Data Upgrades.');
        }

        // Candidate table names
        $cands = array(
            $wpdb->prefix . 'wp_pro_quiz_quiz',
            $wpdb->prefix . 'pro_quiz_quiz',
            $wpdb->base_prefix . 'wp_pro_quiz_quiz',
            $wpdb->base_prefix . 'pro_quiz_quiz',
        );
        $cands = array_values(array_unique($cands));

        $ids_sql = implode(',', $ids);
        $wpdb->suppress_errors(true);

        foreach ($cands as $t) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            if ($exists !== $t) {
                $msgs[] = "$t: not found (skipped)";
                continue;
            }

            if ($mode === 'preview') {
                $on  = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE id IN ($ids_sql) AND statistics_on = 1");
                $off = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t} WHERE id IN ($ids_sql) AND statistics_on = 0");
                $msgs[] = "$t: quizzes=" . count($ids) . " → ON={$on}, OFF={$off}";
                continue;
            }

            $turnOn = in_array($mode, array('on', '1'), true);
            $set    = "statistics_on = " . ($turnOn ? 1 : 0);
            if ($turnOn && !$keepIP) {
                $set .= ", statistics_ip_lock = 0";
            }

            $res = $wpdb->query("UPDATE {$t} SET {$set} WHERE id IN ($ids_sql)");
            $msgs[] = "$t: rows affected=" . ($res === false ? 0 : (int)$res);
        }

        $wpdb->suppress_errors(false);

        wp_die(implode('<br>', array_map('esc_html', $msgs)));
    }
}
