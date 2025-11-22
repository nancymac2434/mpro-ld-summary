<?php
/**
 * Plugin Name: LearnDash Course Toolkit
 * Plugin URI: https://github.com/yourusername/learndash-course-toolkit
 * Description: A comprehensive toolkit for LearnDash courses, providing shortcodes to display quiz answers, essay responses, and capture Otter form data across all your courses.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://yourwebsite.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: learndash-course-toolkit
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LDCT_VERSION', '1.0.0');
define('LDCT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LDCT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LDCT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main plugin class
 */
class LearnDash_Course_Toolkit {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once LDCT_PLUGIN_DIR . 'includes/class-quiz-answers.php';
        require_once LDCT_PLUGIN_DIR . 'includes/class-essay-answers.php';
        require_once LDCT_PLUGIN_DIR . 'includes/class-otter-forms.php';
        require_once LDCT_PLUGIN_DIR . 'includes/class-quiz-stats-admin.php';
        require_once LDCT_PLUGIN_DIR . 'includes/class-course-summary.php';
        require_once LDCT_PLUGIN_DIR . 'includes/class-settings.php';
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'init_components'));

        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Initialize each component
        LDCT_Quiz_Answers::get_instance();
        LDCT_Essay_Answers::get_instance();
        LDCT_Otter_Forms::get_instance();
        LDCT_Quiz_Stats_Admin::get_instance();
        LDCT_Course_Summary::get_instance();

        // Initialize settings page
        if (is_admin()) {
            LDCT_Settings::get_instance();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Flush rewrite rules if needed
        flush_rewrite_rules();

        // Set default options
        if (false === get_option('ldct_settings')) {
            add_option('ldct_settings', array(
                'enable_quiz_answers' => true,
                'enable_essay_answers' => true,
                'enable_otter_forms' => true,
                'enable_quiz_stats' => true,
            ));
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        flush_rewrite_rules();
    }
}

/**
 * Initialize the plugin
 */
function ldct_init() {
    return LearnDash_Course_Toolkit::get_instance();
}

// Start the plugin
ldct_init();
