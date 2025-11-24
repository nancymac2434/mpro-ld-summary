<?php
/**
 * Course Summary Handler
 *
 * Provides unified shortcode to display essay answers and form responses for a course.
 * Shortcode: [ld_course_summary]
 * By default shows only essay-type questions. Use show_quizzes="all" to show all question types.
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
            'show_quizzes' => 'essays', // 'essays' (default), 'all', or 'no'
            'debug' => '0',
        ), $atts, 'ld_course_summary');

        $course_id = (int)$a['course_id'];
        $show_forms = strtolower($a['show_forms']) !== 'no';
        $show_quizzes = strtolower($a['show_quizzes']); // 'essays', 'all', or 'no'
        $debug = $a['debug'] === '1';

        // Auto-detect course_id if not provided
        if (!$course_id && function_exists('learndash_get_course_id')) {
            $course_id = learndash_get_course_id();
        }

        if (!$course_id) {
            return '<div class="ldct-error" style="padding:1rem;background:#fee;border:1px solid #fcc;border-radius:8px;color:#c33;">
                <strong>Error:</strong> Unable to determine course. Please add course_id parameter or place this shortcode within a LearnDash course context.
            </div>';
        }

        if (!is_user_logged_in()) {
            return '<div class="ldct-notice" style="padding:1rem;background:#fef9e7;border:1px solid:#fce5cd;border-radius:8px;">
                Please log in to view your course summary.
            </div>';
        }

        $user_id = get_current_user_id();

        // Initialize debug data
        $debug_info = array(
            'plugin_version' => 'LDCT 1.0.0',
            'timestamp' => current_time('mysql'),
            'user_id' => $user_id,
            'course_id' => $course_id,
            'show_forms' => $show_forms,
            'show_quizzes' => $show_quizzes,
            'course_exists' => (get_post($course_id) !== null),
            'course_post_type' => get_post_type($course_id),
        );

        // Gather all data
        $quiz_data = array();
        $form_data = array();

        if ($show_quizzes !== 'no') {
            $quiz_data = $this->get_course_quiz_data($course_id, $user_id, $show_quizzes, $debug_info);
        } else {
            $debug_info['skip_reason'] = 'show_quizzes is set to no';
        }

        if ($show_forms) {
            $form_data = $this->get_course_form_data($user_id);
            $debug_info['form_data_count'] = count($form_data);
        }

        $debug_info['final_quiz_data_count'] = count($quiz_data);
        $debug_info['final_form_data_count'] = count($form_data);

        // Render output
        return $this->render_summary_html($course_id, $quiz_data, $form_data, $debug, $debug_info);
    }

    /**
     * Get all quiz data for a course
     */
    private function get_course_quiz_data($course_id, $user_id, $quiz_mode, &$debug_info) {
        global $wpdb;

        // Get all quizzes in this course
        $quizzes = $this->get_course_quizzes($course_id);

        // Get the debug methods info
        global $ldct_quiz_debug;
        if (isset($ldct_quiz_debug)) {
            $debug_info['quiz_finding_methods'] = $ldct_quiz_debug;
        }

        $debug_info['quizzes_found'] = $quizzes;
        $debug_info['quiz_count'] = count($quizzes);

        if (empty($quizzes)) {
            $debug_info['error'] = 'No quizzes found in course - see quiz_finding_methods for details';
            return array();
        }

        $base = $wpdb->prefix . 'learndash_pro_quiz_';
        $t_ref = $base . 'statistic_ref';
        $t_stat = $base . 'statistic';
        $t_q = $base . 'question';

        $debug_info['tables'] = array(
            't_ref' => $t_ref,
            't_stat' => $t_stat,
            't_q' => $t_q,
        );

        $result = array();
        $debug_info['quiz_attempts'] = array();

        foreach ($quizzes as $quiz) {
            $quiz_id = $quiz['ID'];
            $quiz_title = $quiz['post_title'];

            // Get user's latest attempt for this quiz
            $ref_query = $wpdb->prepare(
                "SELECT statistic_ref_id, quiz_id, create_time
                 FROM {$t_ref}
                 WHERE user_id=%d AND quiz_post_id=%d
                 ORDER BY create_time DESC, statistic_ref_id DESC
                 LIMIT 1",
                $user_id, $quiz_id
            );

            $ref = $wpdb->get_row($ref_query, ARRAY_A);

            $debug_info['quiz_attempts'][$quiz_id] = array(
                'quiz_title' => $quiz_title,
                'query' => $ref_query,
                'ref_result' => $ref,
            );

            if (!$ref) {
                continue; // User hasn't attempted this quiz
            }

            $ref_id = (int)$ref['statistic_ref_id'];

            // Get questions from this quiz attempt
            // If quiz_mode is 'essays', only get essay-type questions
            if ($quiz_mode === 'essays') {
                $questions_query = $wpdb->prepare(
                    "SELECT s.question_post_id, s.question_id, s.answer_data, q.answer_type
                     FROM {$t_stat} s
                     INNER JOIN {$t_q} q ON s.question_id = q.id
                     WHERE s.statistic_ref_id = %d
                     AND q.answer_type IN ('essay', 'free_answer')
                     ORDER BY s.question_id ASC",
                    $ref_id
                );
            } else {
                // 'all' mode - get all questions
                $questions_query = $wpdb->prepare(
                    "SELECT s.question_post_id, s.question_id, s.answer_data, q.answer_type
                     FROM {$t_stat} s
                     INNER JOIN {$t_q} q ON s.question_id = q.id
                     WHERE s.statistic_ref_id = %d
                     ORDER BY s.question_id ASC",
                    $ref_id
                );
            }

            $stat_rows = $wpdb->get_results($questions_query, ARRAY_A);

            // Enrich each row with question text and answer content
            $questions = array();
            foreach ($stat_rows as $row) {
                $question_post_id = $row['question_post_id'];
                $answer_data = $row['answer_data'];
                $answer_type = $row['answer_type'] ?? 'essay';

                // Get question text from the question post
                $question_post = get_post($question_post_id);
                $question_text = $question_post ? $question_post->post_title : 'Question #' . $question_post_id;

                // Parse answer_data to get essay content (for essay questions)
                $answer_decoded = json_decode($answer_data, true);
                $essay_text = '';

                if (in_array($answer_type, array('essay', 'free_answer'), true)) {
                    if (is_array($answer_decoded) && isset($answer_decoded['graded_id'])) {
                        $essay_post = get_post((int)$answer_decoded['graded_id']);
                        if ($essay_post) {
                            $essay_text = wp_strip_all_tags($essay_post->post_content);
                        }
                    }
                }

                $questions[] = array(
                    'question_post_id' => $question_post_id,
                    'question' => $question_text,
                    'answer_type' => $answer_type,
                    'answer_data' => $answer_data,
                    'essay_text' => $essay_text,
                );
            }

            $debug_info['quiz_attempts'][$quiz_id]['questions_query'] = $questions_query;
            $debug_info['quiz_attempts'][$quiz_id]['questions_found'] = count($questions);
            $debug_info['quiz_attempts'][$quiz_id]['questions_sample'] = !empty($questions) ? array_slice($questions, 0, 2) : null;

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
        global $wpdb;

        $debug_methods = array();
        $quizzes = array();

        // Method 1: LearnDash function
        if (function_exists('learndash_get_course_quiz_list')) {
            $ld_quizzes = learndash_get_course_quiz_list($course_id);
            $debug_methods['method_1_learndash_function'] = array(
                'result' => $ld_quizzes,
                'count' => is_array($ld_quizzes) ? count($ld_quizzes) : 0,
            );

            if (!empty($ld_quizzes) && is_array($ld_quizzes)) {
                foreach ($ld_quizzes as $quiz) {
                    if (isset($quiz['post'])) {
                        $quizzes[] = array('ID' => $quiz['post']->ID, 'post_title' => $quiz['post']->post_title);
                    } elseif (is_object($quiz) && isset($quiz->ID)) {
                        $quizzes[] = array('ID' => $quiz->ID, 'post_title' => $quiz->post_title);
                    } elseif (is_array($quiz) && isset($quiz['ID'])) {
                        $quizzes[] = $quiz;
                    }
                }
            }
        } else {
            $debug_methods['method_1_learndash_function'] = 'Function does not exist';
        }

        // Method 2: Course steps meta
        if (empty($quizzes)) {
            $course_steps = get_post_meta($course_id, 'ld_course_steps', true);
            $debug_methods['method_2_course_steps'] = array(
                'raw' => $course_steps,
                'is_array' => is_array($course_steps),
            );

            if (!empty($course_steps) && is_array($course_steps) && isset($course_steps['sfwd-quiz'])) {
                $quiz_ids = $course_steps['sfwd-quiz'];
                foreach ($quiz_ids as $qid) {
                    $post = get_post($qid);
                    if ($post && $post->post_type === 'sfwd-quiz') {
                        $quizzes[] = array('ID' => $post->ID, 'post_title' => $post->post_title);
                    }
                }
            }
        }

        // Method 3: Direct query for quizzes with this course_id in meta
        if (empty($quizzes)) {
            $query_results = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                 WHERE p.post_type = 'sfwd-quiz'
                 AND p.post_status = 'publish'
                 AND pm.meta_key = 'course_id'
                 AND pm.meta_value = %d",
                $course_id
            ), ARRAY_A);

            $debug_methods['method_3_direct_query'] = array(
                'sql' => $wpdb->last_query,
                'count' => count($query_results),
            );

            if (!empty($query_results)) {
                $quizzes = $query_results;
            }
        }

        // Method 4: Find ALL quizzes and check their lesson/topic association
        if (empty($quizzes)) {
            $all_quizzes = get_posts(array(
                'post_type' => 'sfwd-quiz',
                'post_status' => 'publish',
                'numberposts' => -1,
            ));

            $debug_methods['method_4_all_quizzes_check'] = array(
                'total_quizzes_in_site' => count($all_quizzes),
                'matching' => array(),
            );

            foreach ($all_quizzes as $quiz) {
                // Check if this quiz's lessons/topics belong to our course
                $lesson_id = get_post_meta($quiz->ID, 'lesson_id', true);
                if ($lesson_id) {
                    $lesson_course = get_post_meta($lesson_id, 'course_id', true);
                    if ($lesson_course == $course_id) {
                        $quizzes[] = array('ID' => $quiz->ID, 'post_title' => $quiz->post_title);
                        $debug_methods['method_4_all_quizzes_check']['matching'][] = array(
                            'quiz_id' => $quiz->ID,
                            'quiz_title' => $quiz->post_title,
                            'lesson_id' => $lesson_id,
                        );
                    }
                }
            }
        }

        // Store debug info globally for retrieval
        global $ldct_quiz_debug;
        $ldct_quiz_debug = $debug_methods;

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
    private function render_summary_html($course_id, $quiz_data, $form_data, $debug, $debug_info) {
        $course = get_post($course_id);
        $course_title = $course ? $course->post_title : "Course #{$course_id}";

        $html = array();
        $html[] = '<div class="ldct-course-summary" style="margin:2rem 0;padding:2rem;background:#fff;border:2px solid #c5dde0;border-radius:12px;box-shadow:0 2px 8px rgba(43,77,89,0.08);">';

        // Header
        $html[] = '<div class="ldct-header" style="margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:2px solid #c5dde0;">';
        $html[] = '<h2 style="margin:0 0 0.5rem;font-size:1.75rem;color:#2b4d59;">' . esc_html($course_title) . '</h2>';
        $html[] = '<p style="margin:0;color:#5a7c88;">Your Course Summary</p>';
        $html[] = '</div>';

        // Form Data Section
        if (!empty($form_data)) {
            $html[] = '<div class="ldct-forms-section" style="margin-bottom:2.5rem;">';
            $html[] = '<h3 style="margin:0 0 1rem;font-size:1.35rem;color:#2b4d59;display:flex;align-items:center;">';
            $html[] = '<span style="display:inline-block;width:8px;height:8px;background:#278983;border-radius:50%;margin-right:0.75rem;"></span>';
            $html[] = 'Your Responses';
            $html[] = '</h3>';
            $html[] = '<div class="ldct-forms-list" style="background:#e8f2f4;padding:1.5rem;border-radius:8px;border:1px solid #c5dde0;">';
            $html[] = '<dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:0.75rem 1.5rem;">';

            foreach ($form_data as $field => $value) {
                $display_value = is_array($value) ? implode(', ', array_filter($value, 'strlen')) : $value;
                $html[] = '<dt style="font-weight:600;color:#2b4d59;">' . esc_html($field) . ':</dt>';
                $html[] = '<dd style="margin:0;color:#2b4d59;">' . esc_html($display_value) . '</dd>';
            }

            $html[] = '</dl>';
            $html[] = '</div>';
            $html[] = '</div>';
        }

        // Quiz Data Section
        if (!empty($quiz_data)) {
            $html[] = '<div class="ldct-quizzes-section">';
            $html[] = '<h3 style="margin:0 0 1.5rem;font-size:1.35rem;color:#2b4d59;display:flex;align-items:center;">';
            $html[] = '<span style="display:inline-block;width:8px;height:8px;background:#278983;border-radius:50%;margin-right:0.75rem;"></span>';
            $html[] = 'Essay Answers';
            $html[] = '</h3>';

            foreach ($quiz_data as $quiz) {
                $html[] = '<div class="ldct-quiz" style="margin-bottom:2rem;background:#e8f2f4;border:1px solid #c5dde0;border-radius:8px;overflow:hidden;">';
                $html[] = '<div class="ldct-quiz-header" style="padding:1rem 1.5rem;background:#c5dde0;border-bottom:1px solid #9dbfc5;">';
                $html[] = '<h4 style="margin:0;font-size:1.1rem;color:#2b4d59;">' . esc_html($quiz['quiz_title']) . '</h4>';
                $html[] = '<p style="margin:0.25rem 0 0;font-size:0.85rem;color:#5a7c88;">Completed: ' . esc_html(date('F j, Y g:i a', (int)$quiz['attempt_time'])) . '</p>';
                $html[] = '</div>';

                $html[] = '<div class="ldct-questions" style="padding:1.5rem;">';

                foreach ($quiz['questions'] as $index => $question) {
                    $answer_text = $this->format_answer($question);

                    $html[] = '<div class="ldct-question" style="margin-bottom:' . ($index < count($quiz['questions']) - 1 ? '1.5rem' : '0') . ';">';
                    $html[] = '<div class="ldct-question-text" style="font-weight:600;color:#2b4d59;margin-bottom:0.5rem;">';
                    $html[] = wp_strip_all_tags($question['question']);
                    $html[] = '</div>';
                    $html[] = '<div class="ldct-answer-text" style="padding:0.75rem 1rem;background:#fff;border-left:3px solid #278983;border-radius:4px;color:#2b4d59;">';
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
            $html[] = '<div class="ldct-empty" style="padding:3rem;text-align:center;color:#5a7c88;">';
            $html[] = '<p style="margin:0;font-size:1.1rem;">No quiz attempts or form responses found yet.</p>';
            $html[] = '<p style="margin:0.5rem 0 0;font-size:0.9rem;">Complete quizzes and submit forms to see your summary here.</p>';
            $html[] = '</div>';
        }

        // Debug info - Only show when explicitly enabled with debug="1"
        if ($debug) {
            $html[] = '<div class="ldct-debug" style="margin-top:2rem;padding:1.5rem;background:#0b0d12 !important;color:#eaeef2 !important;border-radius:8px;font-family:monospace;font-size:0.85rem;overflow:auto;max-height:600px;border:2px solid #2b4d59;">';
            $html[] = '<strong style="display:block;margin-bottom:1rem;font-size:1.1rem;color:#278983 !important;">🔍 Debug Information:</strong>';

            // Check if debug_info is empty or invalid
            if (empty($debug_info)) {
                $html[] = '<p style="color:#ef4444 !important;">⚠️ DEBUG INFO IS EMPTY! This is a critical error.</p>';
                $html[] = '<p style="color:#eaeef2 !important;">Debug array type: ' . esc_html(gettype($debug_info)) . '</p>';
                $html[] = '<p style="color:#eaeef2 !important;">Debug array count: ' . esc_html(is_array($debug_info) ? count($debug_info) : 'N/A') . '</p>';
            } else {
                $html[] = '<pre style="margin:0 !important;white-space:pre-wrap !important;line-height:1.5 !important;color:#eaeef2 !important;background:transparent !important;border:none !important;padding:0 !important;">';
                $debug_json = wp_json_encode($debug_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($debug_json === false) {
                    $html[] = '⚠️ JSON ENCODING FAILED: ' . esc_html(json_last_error_msg());
                    $html[] = "\nRaw debug_info:\n" . esc_html(print_r($debug_info, true));
                } else {
                    $html[] = esc_html($debug_json);
                }
                $html[] = '</pre>';
            }

            $html[] = '<div style="margin-top:1rem;padding-top:1rem;border-top:1px solid #2b4d59;color:#eaeef2 !important;">';
            $html[] = '<strong style="color:#278983 !important;">💡 Tips:</strong>';
            $html[] = '<ul style="margin:0.5rem 0 0 1.5rem;line-height:1.8;color:#eaeef2 !important;">';
            $html[] = '<li style="color:#eaeef2 !important;">Check if <code style="background:#1f2937;padding:2px 6px;border-radius:3px;color:#278983;">course_exists</code> is true</li>';
            $html[] = '<li style="color:#eaeef2 !important;">Check if <code style="background:#1f2937;padding:2px 6px;border-radius:3px;color:#278983;">quiz_count</code> shows quizzes were found</li>';
            $html[] = '<li style="color:#eaeef2 !important;">Check if <code style="background:#1f2937;padding:2px 6px;border-radius:3px;color:#278983;">quiz_attempts</code> shows any attempts for your user</li>';
            $html[] = '<li style="color:#eaeef2 !important;">Look for SQL queries that you can test directly in phpMyAdmin</li>';
            $html[] = '<li style="color:#eaeef2 !important;">Verify the <code style="background:#1f2937;padding:2px 6px;border-radius:3px;color:#278983;">user_id</code> matches your logged-in user</li>';
            $html[] = '</ul>';
            $html[] = '</div>';
            $html[] = '</div>';
        }

        $html[] = '</div>'; // .ldct-course-summary

        return implode("\n", $html);
    }

    /**
     * Format answer data for display
     */
    private function format_answer($question) {
        $answer_type = $question['answer_type'] ?? '';

        // Check if we already extracted essay text
        if (isset($question['essay_text']) && trim($question['essay_text']) !== '') {
            return nl2br(esc_html($question['essay_text']));
        }

        // Fallback: try to decode the answer_data
        $answer_data = $question['answer_data'];
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
            return '<em>No essay text found</em>';
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
