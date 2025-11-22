<?php
/**
 * Course Summary Handler
 *
 * Provides unified shortcode to display all quiz answers and form responses for a course.
 * Shortcode: [ld_course_summary course_id="123"]
 *
 * @package LearnDash_Course_Toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDCT_Course_Summary {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('ld_course_summary', array($this, 'render_course_summary'));
    }

    /**
     * Render course summary shortcode
     */
    public function render_course_summary($atts) {
        $a = shortcode_atts(array(
            'course_id' => 0,
            'show_forms' => 'yes',
            'show_quizzes' => 'yes',
            'debug' => '0',
        ), $atts, 'ld_course_summary');

        $course_id = (int)$a['course_id'];
        $show_forms = strtolower($a['show_forms']) !== 'no';
        $show_quizzes = strtolower($a['show_quizzes']) !== 'no';
        $debug = $a['debug'] === '1';

        if (!$course_id) {
            return '<div class="ldct-error" style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:8px;color:#c33;">
                <strong>Error:</strong> course_id is required for [ld_course_summary].
            </div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="ldct-notice" style="padding:1rem;background:#fef9e7;border:1px solid:#fce5cd;border-radius:8px;">
                Please log in to view your course summary.
            </div>';
        }

        $user_id = get_current_user_id();

        // Gather all data
        $quiz_data = array();
        $form_data = array();

        if ($show_quizzes) {
            $quiz_data = $this->get_course_quiz_data($course_id, $user_id);
        }

        if ($show_forms) {
            $form_data = $this->get_course_form_data($user_id);
        }

        // Render output
        return $this->render_summary_html($course_id, $quiz_data, $form_data, $debug);
    }

    /**
     * Get all quiz data for a course
     */
    private function get_course_quiz_data($course_id, $user_id) {
        global $wpdb;

        // Get all quizzes in this course
        $quizzes = $this->get_course_quizzes($course_id);

        if (empty($quizzes)) {
            return array();
        }

        $base = $wpdb->prefix . 'learndash_pro_quiz_';
        $t_ref = $base . 'statistic_ref';
        $t_stat = $base . 'statistic';
        $t_q = $base . 'question';

        $result = array();

        foreach ($quizzes as $quiz) {
            $quiz_id = $quiz['ID'];
            $quiz_title = $quiz['post_title'];

            // Get user's latest attempt for this quiz
            $ref = $wpdb->get_row($wpdb->prepare(
                "SELECT statistic_ref_id, quiz_id, create_time
                 FROM {$t_ref}
                 WHERE user_id=%d AND quiz_post_id=%d
                 ORDER BY create_time DESC, statistic_ref_id DESC
                 LIMIT 1",
                $user_id, $quiz_id
            ), ARRAY_A);

            if (!$ref) {
                continue; // User hasn't attempted this quiz
            }

            $ref_id = (int)$ref['statistic_ref_id'];

            // Get all questions from this quiz attempt
            $questions = $wpdb->get_results($wpdb->prepare(
                "SELECT s.question_post_id, s.question_id, s.answer_data, q.question, q.answer_type
                 FROM {$t_stat} s
                 LEFT JOIN {$t_q} q ON q.id = s.question_id
                 WHERE s.statistic_ref_id = %d
                 ORDER BY s.statistic_id ASC",
                $ref_id
            ), ARRAY_A);

            if (!empty($questions)) {
                $result[] = array(
                    'quiz_id' => $quiz_id,
                    'quiz_title' => $quiz_title,
                    'attempt_time' => $ref['create_time'],
                    'questions' => $questions,
                );
            }
        }

        return $result;
    }

    /**
     * Get all quizzes in a course
     */
    private function get_course_quizzes($course_id) {
        // Get course steps
        $quizzes = learndash_get_course_quiz_list($course_id);

        if (empty($quizzes)) {
            // Fallback: query directly
            $quiz_ids = get_post_meta($course_id, 'ld_course_steps', true);
            if (!empty($quiz_ids) && is_array($quiz_ids) && isset($quiz_ids['sfwd-quiz'])) {
                $quiz_ids = $quiz_ids['sfwd-quiz'];
            } else {
                $quiz_ids = array();
            }

            if (empty($quiz_ids)) {
                return array();
            }

            $quizzes = array();
            foreach ($quiz_ids as $qid) {
                $post = get_post($qid);
                if ($post && $post->post_type === 'sfwd-quiz') {
                    $quizzes[] = array('ID' => $post->ID, 'post_title' => $post->post_title);
                }
            }
        }

        return $quizzes;
    }

    /**
     * Get form data for user
     */
    private function get_course_form_data($user_id) {
        $otter_forms = LDCT_Otter_Forms::get_instance();
        return $otter_forms->read_all_user_data($user_id);
    }

    /**
     * Render the summary HTML
     */
    private function render_summary_html($course_id, $quiz_data, $form_data, $debug) {
        $course = get_post($course_id);
        $course_title = $course ? $course->post_title : "Course #{$course_id}";

        $html = array();
        $html[] = '<div class="ldct-course-summary" style="margin:2rem 0;padding:2rem;background:#fff;border:2px solid #e8edf5;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">';

        // Header
        $html[] = '<div class="ldct-header" style="margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:2px solid #e8edf5;">';
        $html[] = '<h2 style="margin:0 0 0.5rem;font-size:1.75rem;color:#1e3a8a;">' . esc_html($course_title) . '</h2>';
        $html[] = '<p style="margin:0;color:#64748b;">Your Course Summary</p>';
        $html[] = '</div>';

        // Form Data Section
        if (!empty($form_data)) {
            $html[] = '<div class="ldct-forms-section" style="margin-bottom:2.5rem;">';
            $html[] = '<h3 style="margin:0 0 1rem;font-size:1.35rem;color:#1e3a8a;display:flex;align-items:center;">';
            $html[] = '<span style="display:inline-block;width:8px;height:8px;background:#3b82f6;border-radius:50%;margin-right:0.75rem;"></span>';
            $html[] = 'Your Responses';
            $html[] = '</h3>';
            $html[] = '<div class="ldct-forms-list" style="background:#f8fafc;padding:1.5rem;border-radius:8px;border:1px solid #e2e8f0;">';
            $html[] = '<dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.75rem 1.5rem;">';

            foreach ($form_data as $field => $value) {
                $display_value = is_array($value) ? implode(', ', array_filter($value, 'strlen')) : $value;
                $html[] = '<dt style="font-weight:600;color:#475569;">' . esc_html($field) . ':</dt>';
                $html[] = '<dd style="margin:0;color:#1e293b;">' . esc_html($display_value) . '</dd>';
            }

            $html[] = '</dl>';
            $html[] = '</div>';
            $html[] = '</div>';
        }

        // Quiz Data Section
        if (!empty($quiz_data)) {
            $html[] = '<div class="ldct-quizzes-section">';
            $html[] = '<h3 style="margin:0 0 1.5rem;font-size:1.35rem;color:#1e3a8a;display:flex;align-items:center;">';
            $html[] = '<span style="display:inline-block;width:8px;height:8px;background:#10b981;border-radius:50%;margin-right:0.75rem;"></span>';
            $html[] = 'Quiz Answers';
            $html[] = '</h3>';

            foreach ($quiz_data as $quiz) {
                $html[] = '<div class="ldct-quiz" style="margin-bottom:2rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">';
                $html[] = '<div class="ldct-quiz-header" style="padding:1rem 1.5rem;background:#e8edf5;border-bottom:1px solid #cbd5e1;">';
                $html[] = '<h4 style="margin:0;font-size:1.1rem;color:#1e3a8a;">' . esc_html($quiz['quiz_title']) . '</h4>';
                $html[] = '<p style="margin:0.25rem 0 0;font-size:0.85rem;color:#64748b;">Completed: ' . esc_html(date('F j, Y g:i a', strtotime($quiz['attempt_time']))) . '</p>';
                $html[] = '</div>';

                $html[] = '<div class="ldct-questions" style="padding:1.5rem;">';

                foreach ($quiz['questions'] as $index => $question) {
                    $answer_text = $this->format_answer($question);

                    $html[] = '<div class="ldct-question" style="margin-bottom:' . ($index < count($quiz['questions']) - 1 ? '1.5rem' : '0') . ';">';
                    $html[] = '<div class="ldct-question-text" style="font-weight:600;color:#475569;margin-bottom:0.5rem;">';
                    $html[] = wp_strip_all_tags($question['question']);
                    $html[] = '</div>';
                    $html[] = '<div class="ldct-answer-text" style="padding:0.75rem 1rem;background:#fff;border-left:3px solid #3b82f6;border-radius:4px;color:#1e293b;">';
                    $html[] = $answer_text;
                    $html[] = '</div>';
                    $html[] = '</div>';
                }

                $html[] = '</div>'; // .ldct-questions
                $html[] = '</div>'; // .ldct-quiz
            }

            $html[] = '</div>'; // .ldct-quizzes-section
        }

        // Empty state
        if (empty($quiz_data) && empty($form_data)) {
            $html[] = '<div class="ldct-empty" style="padding:3rem;text-align:center;color:#94a3b8;">';
            $html[] = '<p style="margin:0;font-size:1.1rem;">No quiz attempts or form responses found yet.</p>';
            $html[] = '<p style="margin:0.5rem 0 0;font-size:0.9rem;">Complete quizzes and submit forms to see your summary here.</p>';
            $html[] = '</div>';
        }

        // Debug info
        if ($debug) {
            $html[] = '<div class="ldct-debug" style="margin-top:2rem;padding:1rem;background:#0b0d12;color:#eaeef2;border-radius:8px;font-family:monospace;font-size:0.85rem;overflow:auto;">';
            $html[] = '<strong style="display:block;margin-bottom:0.5rem;">Debug Info:</strong>';
            $html[] = '<pre style="margin:0;white-space:pre-wrap;">';
            $html[] = esc_html(wp_json_encode(array(
                'course_id' => $course_id,
                'quiz_count' => count($quiz_data),
                'form_field_count' => count($form_data),
            ), JSON_PRETTY_PRINT));
            $html[] = '</pre>';
            $html[] = '</div>';
        }

        $html[] = '</div>'; // .ldct-course-summary

        return implode("\n", $html);
    }

    /**
     * Format answer data for display
     */
    private function format_answer($question) {
        $answer_data = $question['answer_data'];
        $answer_type = $question['answer_type'] ?? '';

        // Try to decode the answer
        $decoded = $this->decode_answer($answer_data);

        // Handle different answer types
        if (in_array($answer_type, array('essay', 'free_answer'), true)) {
            // Essay/text answers
            if (is_array($decoded)) {
                // Look for graded_id or essay text
                if (isset($decoded['graded_id'])) {
                    $essay_post = get_post((int)$decoded['graded_id']);
                    if ($essay_post) {
                        return nl2br(esc_html(wp_strip_all_tags($essay_post->post_content)));
                    }
                }
                // Look for text field
                foreach ($decoded as $value) {
                    if (is_string($value) && trim($value) !== '' && !is_numeric($value)) {
                        return nl2br(esc_html($value));
                    }
                }
            }
            if (is_string($decoded) && trim($decoded) !== '') {
                return nl2br(esc_html($decoded));
            }
            return '<em>Essay response</em>';
        }

        // For choice questions, just show the raw answer
        if (is_array($decoded)) {
            $parts = array();
            foreach ($decoded as $key => $value) {
                if (is_scalar($value) && trim((string)$value) !== '') {
                    $parts[] = esc_html($value);
                }
            }
            if (!empty($parts)) {
                return implode(', ', $parts);
            }
        }

        if (is_string($decoded) && trim($decoded) !== '') {
            return esc_html($decoded);
        }

        return '<em>—</em>';
    }

    /**
     * Decode answer data
     */
    private function decode_answer($raw) {
        if ($raw === '' || $raw === null) return null;

        $v = @unserialize($raw);
        if ($v !== false || $raw === 'b:0;') return $v;

        $j = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) return $j;

        $raw2 = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
        $j = json_decode($raw2, true);
        if (json_last_error() === JSON_ERROR_NONE) return $j;

        $raw3 = stripslashes($raw2);
        $j = json_decode($raw3, true);
        if (json_last_error() === JSON_ERROR_NONE) return $j;

        return $raw;
    }
}
