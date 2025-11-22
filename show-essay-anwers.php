<?php

/**
 * LearnDash — show current user's latest ESSAY text for one or many questions.
 * Follows answer_data -> {"graded_id": <sfwd-essays post ID>} and prints that post_content.
 * Usage:
 *   [ld_essay_answer quiz_id="39664" question_post_id="39667"]
 *   [ld_essay_answer quiz_id="39664" question_post_id="39667,39670" debug="1"]
 */
add_shortcode('ld_essay_answer', function($atts){
	if (!is_user_logged_in()) return '<em>Log in to view your essay answers.</em>';

	$a = shortcode_atts([
		'quiz_id'          => 0,          // WP quiz post ID
		'question_post_id' => '',         // single or comma-separated
		'label'            => 'Your answer',
		'debug'            => '0',
	], $atts, 'ld_essay_answer');

	$quiz_post_id = (int)$a['quiz_id'];
	if (!$quiz_post_id) return '<em>quiz_id is required.</em>';

	$qpids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string)$a['question_post_id']))));
	if (!$qpids) return '<em>question_post_id is required (single or comma-separated).</em>';

	global $wpdb;
	$uid   = get_current_user_id();
	$debug = $a['debug'] === '1';

	$base   = $wpdb->prefix . 'learndash_pro_quiz_';
	$t_ref  = $base . 'statistic_ref';
	$t_stat = $base . 'statistic';

	// decode helper (serialize / json / entities)
	$decode = function($raw){
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
		return $raw; // raw fallback
	};

	// fetch essay text by sfwd-essays post id
	$get_essay_text_by_post = function($pid){
		$p = get_post((int)$pid);
		if (!$p) return '';
		// Prefer only current user's essay for safety
		if ((int)$p->post_author !== get_current_user_id()) {
			// Some setups store user_id in meta instead
			$meta_uid = (int) get_post_meta($p->ID, 'user_id', true);
			if ($meta_uid && $meta_uid !== get_current_user_id()) return '';
		}
		$text = trim(wp_strip_all_tags($p->post_content));
		if ($text === '') {
			foreach (['essay','essay_answer','ld_essay_answer','answer'] as $mk) {
				$mv = get_post_meta($p->ID, $mk, true);
				if (is_string($mv) && trim($mv) !== '') { $text = trim(wp_strip_all_tags($mv)); break; }
			}
		}
		return $text;
	};

	// latest attempt for this user on this quiz page
	$ref = $wpdb->get_row($wpdb->prepare(
		"SELECT statistic_ref_id, quiz_id, create_time
		 FROM {$t_ref}
		 WHERE user_id=%d AND quiz_post_id=%d
		 ORDER BY create_time DESC, statistic_ref_id DESC
		 LIMIT 1",
		$uid, $quiz_post_id
	), ARRAY_A);
	$ref_id = $ref ? (int)$ref['statistic_ref_id'] : 0;

	$answers = [];
	$dbg = ['ref'=>$ref ?: null, 'stat_rows'=>[], 'essay_posts'=>[]];

	foreach ($qpids as $qpid) {
		$text = '';

		// Try statistic row -> decode -> graded_id / essay_post_id
		if ($ref_id) {
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT question_id, answer_data
				 FROM {$t_stat}
				 WHERE statistic_ref_id=%d AND question_post_id=%d
				 LIMIT 1",
				$ref_id, $qpid
			), ARRAY_A);

			if ($row) {
				$val = $decode($row['answer_data']);
				$essay_id = 0;

				if (is_array($val)) {
					foreach (['graded_id','essay_post_id','post_id','essay_id'] as $k) {
						if (!empty($val[$k]) && is_numeric($val[$k])) { $essay_id = (int)$val[$k]; break; }
					}
				} elseif (is_numeric($val)) {
					// Some LD versions store just a number as the essay post id
					$essay_id = (int)$val;
				} elseif (is_string($val) && ctype_digit(trim($val))) {
					$essay_id = (int)trim($val);
				}

				if ($essay_id) {
					$text = $get_essay_text_by_post($essay_id);
					if ($debug) $dbg['essay_posts'][] = ['question_post_id'=>$qpid, 'essay_post_id'=>$essay_id, 'text_excerpt'=>mb_substr($text,0,160)];
				}

				if ($debug) $dbg['stat_rows'][] = ['question_post_id'=>$qpid, 'answer_data'=>$row['answer_data'], 'decoded'=>$val, 'essay_post_id'=>$essay_id];
			} else {
				if ($debug) $dbg['stat_rows'][] = ['question_post_id'=>$qpid, 'answer_data'=>null, 'decoded'=>null, 'essay_post_id'=>0];
			}
		}

		// Fallback: search latest sfwd-essays post tied to this quiz & question & user
		if ($text === '') {
			$posts = get_posts([
				'post_type'      => 'sfwd-essays',
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'post_status'    => 'any',
				'author'         => $uid,
				'meta_query'     => [
					'relation' => 'AND',
					[
						'relation' => 'OR',
						['key'=>'quiz_id','value'=>$quiz_post_id],
						['key'=>'ld_quiz_id','value'=>$quiz_post_id],
						['key'=>'ld_essay_quiz','value'=>$quiz_post_id],
					],
					[
						'relation' => 'OR',
						['key'=>'question_id','value'=>$qpid],
						['key'=>'question_post_id','value'=>$qpid],
						['key'=>'ld_essay_question_id','value'=>$qpid],
					],
				],
			]);
			if ($posts) {
				$p = $posts[0];
				$text = trim(wp_strip_all_tags($p->post_content));
				if ($text === '') {
					foreach (['essay','essay_answer','ld_essay_answer','answer'] as $mk) {
						$mv = get_post_meta($p->ID, $mk, true);
						if (is_string($mv) && trim($mv) !== '') { $text = trim(wp_strip_all_tags($mv)); break; }
					}
				}
				if ($debug) $dbg['essay_posts'][] = ['question_post_id'=>$qpid, 'essay_post_id'=>$p->ID, 'text_excerpt'=>mb_substr($text,0,160)];
			}
		}

		$answers[$qpid] = $text !== '' ? $text : '—';
	}

	// Render
	$out = [];
	$out[] = '<div class="ld-essay-answers" style="margin:1rem 0;padding:1rem;border:1px solid #e5e7eb;border-radius:12px;">';
	$out[] = '<h3 style="margin:0 0 .75rem;font-size:1.05rem;">' . esc_html($a['label']) . '</h3>';
	foreach ($qpids as $qpid) {
		$text = $answers[$qpid];
		$out[] = '<div style="margin:.5rem 0 .75rem;">';
		$out[] = '<div style="padding:.5rem .75rem;background:#f9fafb;border:1px solid #eef2f7;border-radius:10px;">' . nl2br(esc_html($text)) . '</div>';
		$out[] = '</div>';
	}
	if ($debug) {
		$out[] = '<pre style="margin-top:.75rem;background:#f9fafb;padding:.75rem;border:1px dashed #e5e7eb;white-space:pre-wrap;">'
			  .  esc_html(print_r($dbg, true))
			  . '</pre>';
	}
	$out[] = '</div>';

	return implode('', $out);
});
