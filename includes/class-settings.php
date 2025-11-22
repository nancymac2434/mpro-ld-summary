<?php
/**
 * Settings Page Handler
 *
 * Provides admin settings page for the LearnDash Course Toolkit plugin.
 *
 * @package LearnDash_Course_Toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDCT_Settings {

    private static $instance = null;

    private $option_name = 'ldct_settings';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            'LearnDash Course Toolkit',
            'LD Course Toolkit',
            'manage_options',
            'learndash-course-toolkit',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ldct_settings_group', $this->option_name, array($this, 'sanitize_settings'));

        add_settings_section(
            'ldct_main_section',
            'Feature Settings',
            array($this, 'render_section_description'),
            'learndash-course-toolkit'
        );

        add_settings_field(
            'enable_quiz_answers',
            'Quiz Answers',
            array($this, 'render_checkbox_field'),
            'learndash-course-toolkit',
            'ldct_main_section',
            array('field' => 'enable_quiz_answers', 'label' => 'Enable quiz answer display shortcodes')
        );

        add_settings_field(
            'enable_essay_answers',
            'Essay Answers',
            array($this, 'render_checkbox_field'),
            'learndash-course-toolkit',
            'ldct_main_section',
            array('field' => 'enable_essay_answers', 'label' => 'Enable essay answer display shortcodes')
        );

        add_settings_field(
            'enable_otter_forms',
            'Otter Forms',
            array($this, 'render_checkbox_field'),
            'learndash-course-toolkit',
            'ldct_main_section',
            array('field' => 'enable_otter_forms', 'label' => 'Enable Otter form capture and display')
        );

        add_settings_field(
            'enable_quiz_stats',
            'Quiz Statistics',
            array($this, 'render_checkbox_field'),
            'learndash-course-toolkit',
            'ldct_main_section',
            array('field' => 'enable_quiz_stats', 'label' => 'Enable quiz statistics admin tools')
        );
    }

    /**
     * Render section description
     */
    public function render_section_description() {
        echo '<p>Configure which features of the LearnDash Course Toolkit are enabled.</p>';
    }

    /**
     * Render checkbox field
     */
    public function render_checkbox_field($args) {
        $options = get_option($this->option_name, array());
        $field = $args['field'];
        $checked = isset($options[$field]) && $options[$field];
        ?>
        <label>
            <input type="checkbox"
                   name="<?php echo esc_attr($this->option_name); ?>[<?php echo esc_attr($field); ?>]"
                   value="1"
                   <?php checked($checked, true); ?> />
            <?php echo esc_html($args['label']); ?>
        </label>
        <?php
    }

    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        $fields = array('enable_quiz_answers', 'enable_essay_answers', 'enable_otter_forms', 'enable_quiz_stats');

        foreach ($fields as $field) {
            $sanitized[$field] = isset($input[$field]) && $input[$field] == '1';
        }

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="options.php" method="post">
                <?php
                settings_fields('ldct_settings_group');
                do_settings_sections('learndash-course-toolkit');
                submit_button('Save Settings');
                ?>
            </form>

            <hr>

            <h2>Available Shortcodes</h2>

            <div class="ldct-shortcode-docs">
                <h3>Course Summary</h3>
                <code>[ld_course_summary course_id="123"]</code>
                <p>Displays all quiz answers and Otter form responses for a specific course in one unified summary.</p>

                <h3>Individual Quiz Answer</h3>
                <code>[ld_qanswer quiz_id="123" question_post_id="456" show="correct" label="Your answer"]</code>
                <p>Displays a single quiz question answer. Use <code>show="selected"</code> to show what the user selected, or <code>show="correct"</code> to show only correct answers.</p>

                <h3>Essay Answers</h3>
                <code>[ld_essay_answer quiz_id="123" question_post_id="456"]</code>
                <p>Displays essay answer(s). Supports multiple questions with comma-separated IDs: <code>question_post_id="456,789,012"</code></p>

                <h3>Otter Form Data</h3>
                <code>[aotter_show field="Field Name" fallback="Not answered"]</code>
                <p>Show a single form field value.</p>

                <code>[aotter_all_latest]</code>
                <p>Show all latest form responses.</p>

                <code>[aotter_page field="Field Name" page_id="123"]</code>
                <p>Show a field value from a specific page.</p>

                <h3>Quiz Statistics Admin</h3>
                <p>To toggle quiz statistics: <code>wp-admin/?ld_stats_all=preview</code> (view status), <code>?ld_stats_all=on</code> (enable), or <code>?ld_stats_all=off</code> (disable)</p>
            </div>

            <style>
                .ldct-shortcode-docs { background: #f9f9f9; padding: 20px; border-radius: 5px; margin-top: 20px; }
                .ldct-shortcode-docs h3 { margin-top: 20px; margin-bottom: 10px; }
                .ldct-shortcode-docs code { background: #fff; padding: 5px 10px; border: 1px solid #ddd; border-radius: 3px; display: inline-block; margin: 5px 0; }
                .ldct-shortcode-docs p { margin: 10px 0; color: #666; }
            </style>
        </div>
        <?php
    }
}
