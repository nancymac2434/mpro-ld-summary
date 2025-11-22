<?php
/**
 * Quiz Answers Handler
 *
 * Provides shortcode to display user's answers to LearnDash quiz questions.
 * Shortcode: [ld_qanswer quiz_id="123" question_post_id="456" show="correct|selected" debug="0|1"]
 *
 * @package LearnDash_Course_Toolkit
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDCT_Quiz_Answers {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('ld_qanswer', array($this, 'render_answer_shortcode'));
    }

    /**
     * Render quiz answer shortcode
     */
    public function render_answer_shortcode($atts) {
        global $wpdb;

        $a = shortcode_atts(array(
            'quiz_id'          => 0,          // WP quiz post ID
            'question_post_id' => 0,          // WP question post ID
            'show'             => 'correct',  // for choice types: 'correct' or 'selected'
            'label'            => 'Your answer',
            'debug'            => '0',
        ), $atts, 'ld_qanswer');

        $quiz_post_id     = (int)$a['quiz_id'];
        $question_post_id = (int)$a['question_post_id'];
        $show_mode        = strtolower($a['show']) === 'selected' ? 'selected' : 'correct';
        $debug            = $a['debug'] === '1';

        if (!$quiz_post_id || !$question_post_id) {
            return '<em>quiz_id & question_post_id required.</em>';
        }

        if (!is_user_logged_in()) {
            return '<em>Log in to view your answer.</em>';
        }

        $uid   = get_current_user_id();
        $base  = $wpdb->prefix . 'learndash_pro_quiz_';
        $t_ref = $base . 'statistic_ref';
        $t_st  = $base . 'statistic';
        $t_q   = $base . 'question';

        // Robust decoder (serialize → JSON → entity/stripslashes JSON)
        $decode = function($raw) {
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
            return $raw; // raw string
        };

        // Latest attempt for this user/quiz
        $ref = $wpdb->get_row($wpdb->prepare(
            "SELECT statistic_ref_id, quiz_id FROM {$t_ref}
             WHERE user_id=%d AND quiz_post_id=%d
             ORDER BY create_time DESC, statistic_ref_id DESC LIMIT 1",
            $uid, $quiz_post_id
        ), ARRAY_A);

        if (!$ref) {
            return '<em>No attempts found yet for this quiz.</em>';
        }

        // Pull this question's recorded answer
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT question_id, answer_data
             FROM {$t_st}
             WHERE statistic_ref_id=%d AND question_post_id=%d
             LIMIT 1",
            (int)$ref['statistic_ref_id'], $question_post_id
        ), ARRAY_A);

        if (!$row) {
            return '<em>No recorded answer for this question yet.</em>';
        }

        $u_raw = $row['answer_data'];
        $u_val = $decode($u_raw);

        // Load the ProQuiz question (to get answer_type + options for mapping)
        $q = $wpdb->get_row($wpdb->prepare(
            "SELECT answer_type, answer_data FROM {$t_q} WHERE id=%d LIMIT 1",
            (int)$row['question_id']
        ), ARRAY_A);

        $answer_type = $q ? (string)$q['answer_type'] : '';
        $opts_raw    = $q ? $q['answer_data'] : '';

        // Extract option objects/arrays into [{text, correct}]
        $options = $this->parse_options($opts_raw, $decode);

        // Classify types
        $is_choice = in_array($answer_type, array('single', 'multiple', 'single_choice', 'multiple_choice', 'assessment_answer'), true);
        $is_sort   = in_array($answer_type, array('sort_answer', 'matrix_sort_answer'), true);
        $is_texty  = (!$is_choice && !$is_sort);

        // Parse user's selection
        list($chosen_idx, $chosen_txt) = $this->parse_user_answer($u_val, $u_raw, $is_choice, $is_sort, $options, $quiz_post_id, $question_post_id, $row, $uid);

        // Build display
        $parts = $this->build_display_parts($is_choice, $is_sort, $options, $chosen_idx, $chosen_txt, $show_mode);

        if (!$parts) {
            $parts = array('—');
        }

        // Output
        return $this->render_output($a['label'], $parts, $debug, $answer_type, $chosen_idx, $chosen_txt, $options);
    }

    /**
     * Parse answer options from raw data
     */
    private function parse_options($opts_raw, $decode) {
        $options = array();

        $push = function($text, $ok) use (&$options) {
            $text = wp_strip_all_tags(html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8'));
            $options[] = array('text' => $text, 'correct' => (bool)$ok);
        };

        $parsed = $decode($opts_raw);

        if (is_array($parsed)) {
            foreach ($parsed as $o) {
                if (is_object($o)) {
                    if (method_exists($o, 'getAnswer') || method_exists($o, 'isCorrect')) {
                        $push(
                            method_exists($o, 'getAnswer') ? $o->getAnswer() : '',
                            method_exists($o, 'isCorrect') ? $o->isCorrect() : false
                        );
                    } else {
                        $vars = (array)$o;
                        $ans = '';
                        $ok = false;
                        foreach ($vars as $k => $v) {
                            $ks = is_string($k) ? preg_replace('/^\0\*\0/', '', $k) : $k;
                            if (strpos((string)$ks, 'answer') !== false) $ans = $v;
                            if (strpos((string)$ks, 'correct') !== false) $ok = (bool)$v;
                        }
                        $push($ans, $ok);
                    }
                } elseif (is_array($o)) {
                    $push($o['answer'] ?? $o['html'] ?? $o['title'] ?? '', !empty($o['correct']));
                } elseif (is_string($o)) {
                    $push($o, false);
                }
            }
        }

        if (!$options && is_string($opts_raw) && $opts_raw !== '') {
            if (preg_match_all('/"answer";s:\d+:"(.*?)";.*?"correct";b:(0|1);/s', $opts_raw, $m, PREG_SET_ORDER)) {
                foreach ($m as $hit) {
                    $push(stripslashes($hit[1]), $hit[2] === '1');
                }
            } elseif (preg_match_all('/"answer";s:\d+:"(.*?)";/s', $opts_raw, $m2)) {
                foreach ($m2[1] as $txt) {
                    $push(stripslashes($txt), false);
                }
            }
        }

        return $options;
    }

    /**
     * Parse user's answer data
     */
    private function parse_user_answer($u_val, $u_raw, $is_choice, $is_sort, $options, $quiz_post_id, $question_post_id, $row, $uid) {
        $chosen_idx = array();
        $chosen_txt = array();

        if ($is_choice) {
            if (is_array($u_val)) {
                $looks_flags = $u_val && array_keys($u_val) === range(0, count($u_val) - 1) &&
                              empty(array_diff(array_unique(array_map('strval', $u_val)), array('0', '1')));

                if ($looks_flags) {
                    foreach ($u_val as $i => $f) {
                        if ((int)$f === 1) $chosen_idx[] = (int)$i;
                    }
                } else {
                    foreach ($u_val as $v) {
                        if (is_array($v) && isset($v['index'])) {
                            $chosen_idx[] = (int)$v['index'];
                        } elseif (is_numeric($v)) {
                            $chosen_idx[] = (int)$v;
                        } elseif (is_string($v) && $v !== '') {
                            $chosen_txt[] = wp_strip_all_tags($v);
                        }
                    }
                }
            } elseif (is_numeric($u_val)) {
                $chosen_idx[] = (int)$u_val;
            } elseif (is_string($u_val) && $u_val !== '') {
                $chosen_txt[] = wp_strip_all_tags($u_val);
            }
        } elseif ($is_sort) {
            $flatten = function($x) use (&$flatten) {
                return is_array($x) ? array_merge(...array_map($flatten, $x)) : array($x);
            };
            $flat = $flatten((array)$u_val);
            foreach ($flat as $v) {
                if (is_numeric($v)) $chosen_idx[] = (int)$v;
            }
        } else {
            // TEXTY — try to pull the real written text
            $collect_texts = function($v) use (&$collect_texts) {
                $out = array();
                if (is_array($v)) {
                    if (isset($v['text'])) {
                        $t = trim((string)$v['text']);
                        if ($t !== '') $out[] = $t;
                    }
                    foreach ($v as $k => $vv) {
                        if ($k !== 'text') $out = array_merge($out, $collect_texts($vv));
                    }
                } elseif (is_scalar($v)) {
                    $t = trim((string)$v);
                    if ($t !== '') $out[] = $t;
                }
                return $out;
            };

            $chosen_txt = $collect_texts($u_val ?: $u_raw);

            // If LD stored only indexes/flags for texty types, map to option texts
            $only_numbers = !$chosen_txt && (
                (is_scalar($u_val) && preg_match('/^\d+$/', (string)$u_val)) ||
                (is_array($u_val) && $u_val && count(array_filter($u_val, fn($x) => is_numeric($x))) === count($u_val))
            );

            if ($only_numbers && $options) {
                $idxs = is_array($u_val) ? array_map('intval', $u_val) : array((int)$u_val);
                foreach ($idxs as $i) {
                    if (isset($options[$i])) $chosen_txt[] = $options[$i]['text'];
                }
            }

            // Final fallback for ESSAY: look up the sfwd-essays CPT
            if (!$chosen_txt) {
                $essay_text = $this->fetch_essay_text($uid, $quiz_post_id, $question_post_id, $row);
                if ($essay_text !== '') $chosen_txt[] = $essay_text;
            }
        }

        return array($chosen_idx, $chosen_txt);
    }

    /**
     * Fetch essay text from CPT
     */
    private function fetch_essay_text($uid, $quiz_post_id, $question_post_id, $row) {
        $q1 = new WP_Query(array(
            'post_type'      => 'sfwd-essays',
            'posts_per_page' => 1,
            'author'         => $uid,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => 'question_post_id', 'value' => $question_post_id),
                array('key' => 'quiz_post_id', 'value' => $quiz_post_id),
            ),
            'post_status'    => array('publish', 'pending', 'draft', 'private'),
            'no_found_rows'  => true,
        ));

        if (!$q1->have_posts() && !empty($row['question_id'])) {
            $q1 = new WP_Query(array(
                'post_type'      => 'sfwd-essays',
                'posts_per_page' => 1,
                'author'         => $uid,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'meta_query'     => array(
                    'relation' => 'OR',
                    array('key' => 'question_pro_id', 'value' => (int)$row['question_id']),
                    array('key' => 'question_id', 'value' => (int)$row['question_id']),
                ),
                'post_status'    => array('publish', 'pending', 'draft', 'private'),
                'no_found_rows'  => true,
            ));
        }

        $essay_text = '';
        if ($q1->have_posts()) {
            $p = $q1->posts[0];
            $essay_text = trim(wp_strip_all_tags($p->post_content));
        }
        wp_reset_postdata();

        return $essay_text;
    }

    /**
     * Build display parts
     */
    private function build_display_parts($is_choice, $is_sort, $options, $chosen_idx, $chosen_txt, $show_mode) {
        $parts = array();

        if ($is_choice && $options) {
            if ($show_mode === 'correct') {
                foreach ($chosen_idx as $i) {
                    if (isset($options[$i]) && !empty($options[$i]['correct'])) {
                        $parts[] = $options[$i]['text'];
                    }
                }
                if ($chosen_txt) {
                    $correct_texts = array_map(
                        fn($o) => mb_strtolower(trim($o['text'])),
                        array_values(array_filter($options, fn($o) => !empty($o['correct'])))
                    );
                    foreach ($chosen_txt as $t) {
                        if (in_array(mb_strtolower(trim($t)), $correct_texts, true)) {
                            $parts[] = $t;
                        }
                    }
                }
                // If none were correct, at least show what they picked
                if (!$parts) {
                    foreach ($chosen_idx as $i) {
                        if (isset($options[$i])) $parts[] = $options[$i]['text'];
                    }
                    foreach ($chosen_txt as $t) {
                        $parts[] = $t;
                    }
                }
            } else {
                foreach ($chosen_idx as $i) {
                    if (isset($options[$i])) $parts[] = $options[$i]['text'];
                }
                foreach ($chosen_txt as $t) {
                    $parts[] = $t;
                }
            }
        } elseif ($is_sort && $options) {
            foreach ($chosen_idx as $i) {
                if (isset($options[$i])) $parts[] = $options[$i]['text'];
            }
            if ($parts) $parts = array(implode(' > ', $parts));
        } else {
            // TEXTY → just show user's text
            foreach ($chosen_txt as $t) {
                if ($t !== '') $parts[] = $t;
            }
        }

        return $parts;
    }

    /**
     * Render the output HTML
     */
    private function render_output($label, $parts, $debug, $answer_type, $chosen_idx, $chosen_txt, $options) {
        $html  = '<div class="ld-question-answer" style="margin:.5rem 0;padding:.75rem;border:1px solid #e5e7eb;border-radius:10px;">';
        $html .= '<strong>' . esc_html($label) . ':</strong> ' . esc_html(implode(' | ', array_unique($parts)));

        if ($debug) {
            $dbg = array(
                'answer_type' => $answer_type,
                'chosen_idx'  => $chosen_idx,
                'chosen_txt'  => $chosen_txt,
                'options'     => count($options),
            );
            $html .= '<pre style="margin-top:.5rem;background:#f9fafb;padding:.5rem;border:1px dashed #e5e7eb;white-space:pre-wrap;">'
                  .  esc_html(print_r($dbg, true)) . '</pre>';
        }

        $html .= '</div>';
        return $html;
    }
}
