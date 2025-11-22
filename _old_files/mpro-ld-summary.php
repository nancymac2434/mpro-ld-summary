<?php

// [ld_qanswer quiz_id="39748" question_post_id="39755" show="correct|selected" debug="0|1"]
add_shortcode('ld_qanswer', function($atts){
	global $wpdb;

	$a = shortcode_atts([
		'quiz_id'          => 0,          // WP quiz post ID
		'question_post_id' => 0,          // WP question post ID
		'show'             => 'correct',  // for choice types: 'correct' or 'selected'
		'label'            => 'Your answer',
		'debug'            => '0',
	], $atts, 'ld_qanswer');

	$quiz_post_id     = (int)$a['quiz_id'];
	$question_post_id = (int)$a['question_post_id'];
	$show_mode        = strtolower($a['show']) === 'selected' ? 'selected' : 'correct';
	$debug            = $a['debug'] === '1';
	if (!$quiz_post_id || !$question_post_id) return '<em>quiz_id & question_post_id required.</em>';
	if (!is_user_logged_in()) return '<em>Log in to view your answer.</em>';

	$uid   = get_current_user_id();
	$base  = $wpdb->prefix . 'learndash_pro_quiz_';
	$t_ref = $base.'statistic_ref';
	$t_st  = $base.'statistic';
	$t_q   = $base.'question';

	// Robust decoder (serialize → JSON → entity/stripslashes JSON)
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
		return $raw; // raw string
	};

	// Latest attempt for this user/quiz
	$ref = $wpdb->get_row($wpdb->prepare(
		"SELECT statistic_ref_id, quiz_id FROM {$t_ref}
		 WHERE user_id=%d AND quiz_post_id=%d
		 ORDER BY create_time DESC, statistic_ref_id DESC LIMIT 1",
		$uid, $quiz_post_id
	), ARRAY_A);
	if (!$ref) return '<em>No attempts found yet for this quiz.</em>';

	// Pull this question’s recorded answer
	$row = $wpdb->get_row($wpdb->prepare(
		"SELECT question_id, answer_data
		 FROM {$t_st}
		 WHERE statistic_ref_id=%d AND question_post_id=%d
		 LIMIT 1",
		(int)$ref['statistic_ref_id'], $question_post_id
	), ARRAY_A);
	if (!$row) return '<em>No recorded answer for this question yet.</em>';

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
	$options = [];
	$push = function($text,$ok) use (&$options){
		$text = wp_strip_all_tags(html_entity_decode((string)$text, ENT_QUOTES, 'UTF-8'));
		$options[] = ['text'=>$text, 'correct'=>(bool)$ok];
	};
	$parsed = $decode($opts_raw);
	if (is_array($parsed)) {
		foreach ($parsed as $o) {
			if (is_object($o)) {
				if (method_exists($o,'getAnswer') || method_exists($o,'isCorrect')) {
					$push(method_exists($o,'getAnswer')?$o->getAnswer():'', method_exists($o,'isCorrect')?$o->isCorrect():false);
				} else {
					$vars = (array)$o; $ans=''; $ok=false;
					foreach ($vars as $k=>$v) {
						$ks = is_string($k) ? preg_replace('/^\0\*\0/', '', $k) : $k;
						if (strpos((string)$ks,'answer') !== false)  $ans = $v;
						if (strpos((string)$ks,'correct') !== false) $ok  = (bool)$v;
					}
					$push($ans,$ok);
				}
			} elseif (is_array($o)) {
				$push($o['answer'] ?? $o['html'] ?? $o['title'] ?? '', !empty($o['correct']));
			} elseif (is_string($o)) {
				$push($o,false);
			}
		}
	}
	if (!$options && is_string($opts_raw) && $opts_raw!=='') {
		if (preg_match_all('/"answer";s:\d+:"(.*?)";.*?"correct";b:(0|1);/s', $opts_raw, $m, PREG_SET_ORDER)) {
			foreach ($m as $hit) $push(stripslashes($hit[1]), $hit[2]==='1');
		} elseif (preg_match_all('/"answer";s:\d+:"(.*?)";/s', $opts_raw, $m2)) {
			foreach ($m2[1] as $txt) $push(stripslashes($txt), false);
		}
	}

	// Classify types
	$is_choice = in_array($answer_type, ['single','multiple','single_choice','multiple_choice','assessment_answer'], true);
	$is_sort   = in_array($answer_type, ['sort_answer','matrix_sort_answer'], true);
	// Most "texty" types in ProQuiz:
	$is_texty  = (!$is_choice && !$is_sort); // free_answer, cloze_answer, essay, number, etc.

	$chosen_idx = [];  // for choice/sort
	$chosen_txt = [];  // for text-like or choice stored as text

	// Normalize user selection
	if ($is_choice) {
		if (is_array($u_val)) {
			$looks_flags = $u_val && array_keys($u_val)===range(0,count($u_val)-1) &&
						   empty(array_diff(array_unique(array_map('strval',$u_val)), ['0','1']));
			if ($looks_flags) {
				foreach ($u_val as $i=>$f) if ((int)$f===1) $chosen_idx[]=(int)$i;
			} else {
				foreach ($u_val as $v) {
					if (is_array($v) && isset($v['index'])) $chosen_idx[]=(int)$v['index'];
					elseif (is_numeric($v))                 $chosen_idx[]=(int)$v;
					elseif (is_string($v) && $v!=='')       $chosen_txt[] = wp_strip_all_tags($v);
				}
			}
		} elseif (is_numeric($u_val)) {
			$chosen_idx[] = (int)$u_val;
		} elseif (is_string($u_val) && $u_val!=='') {
			$chosen_txt[] = wp_strip_all_tags($u_val);
		}
	} elseif ($is_sort) {
		$flatten = function($x) use (&$flatten){ return is_array($x)? array_merge(...array_map($flatten,$x)) : [$x]; };
		$flat = $flatten((array)$u_val);
		foreach ($flat as $v) if (is_numeric($v)) $chosen_idx[]=(int)$v;
	} else {
		// TEXTY — try to pull the real written text
		$collect_texts = function($v) use (&$collect_texts){
			$out=[];
			if (is_array($v)) {
				if (isset($v['text'])) { $t=trim((string)$v['text']); if($t!=='') $out[]=$t; }
				foreach ($v as $k=>$vv) if ($k!=='text') $out=array_merge($out,$collect_texts($vv));
			} elseif (is_scalar($v)) {
				$t=trim((string)$v); if($t!=='') $out[]=$t;
			}
			return $out;
		};
		$chosen_txt = $collect_texts($u_val ?: $u_raw);

		// If LD stored only indexes/flags for texty types, map to option texts (canonical answers)
		$only_numbers = !$chosen_txt && (
			(is_scalar($u_val) && preg_match('/^\d+$/', (string)$u_val)) ||
			(is_array($u_val) && $u_val && count(array_filter($u_val, fn($x)=>is_numeric($x))) === count($u_val))
		);
		if ($only_numbers && $options) {
			$idxs = is_array($u_val) ? array_map('intval',$u_val) : [ (int)$u_val ];
			foreach ($idxs as $i) if (isset($options[$i])) $chosen_txt[] = $options[$i]['text'];
		}

		// Final fallback for ESSAY: look up the sfwd-essays CPT to get the exact typed text
		if (!$chosen_txt) {
			$essay_text = '';
			// Try matching by question_post_id + quiz_post_id
			$q1 = new WP_Query([
				'post_type'      => 'sfwd-essays',
				'posts_per_page' => 1,
				'author'         => $uid,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => [
					'relation' => 'AND',
					[ 'key' => 'question_post_id', 'value' => $question_post_id ],
					[ 'key' => 'quiz_post_id',     'value' => $quiz_post_id ],
				],
				'post_status'    => ['publish','pending','draft','private'],
				'no_found_rows'  => true,
			]);
			if (!$q1->have_posts() && !empty($row['question_id'])) {
				// Try by ProQuiz question_id (some installs use question_pro_id)
				$q1 = new WP_Query([
					'post_type'      => 'sfwd-essays',
					'posts_per_page' => 1,
					'author'         => $uid,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'meta_query'     => [
						'relation' => 'OR',
						[ 'key' => 'question_pro_id', 'value' => (int)$row['question_id'] ],
						[ 'key' => 'question_id',     'value' => (int)$row['question_id'] ],
					],
					'post_status'    => ['publish','pending','draft','private'],
					'no_found_rows'  => true,
				]);
			}
			if ($q1->have_posts()) {
				$p = $q1->posts[0];
				$essay_text = trim( wp_strip_all_tags($p->post_content) );
			}
			wp_reset_postdata();
			if ($essay_text !== '') $chosen_txt[] = $essay_text;
		}
	}

	// Build display
	$parts = [];
	if ($is_choice && $options) {
		if ($show_mode==='correct') {
			foreach ($chosen_idx as $i) {
				if (isset($options[$i]) && !empty($options[$i]['correct'])) $parts[]=$options[$i]['text'];
			}
			if ($chosen_txt) {
				$correct_texts = array_map(fn($o)=>mb_strtolower(trim($o['text'])),
								array_values(array_filter($options, fn($o)=>!empty($o['correct']))));
				foreach ($chosen_txt as $t) if (in_array(mb_strtolower(trim($t)), $correct_texts, true)) $parts[]=$t;
			}
			// If none were correct, at least show what they picked
			if (!$parts) {
				foreach ($chosen_idx as $i) if (isset($options[$i])) $parts[]=$options[$i]['text'];
				foreach ($chosen_txt as $t) $parts[]=$t;
			}
		} else {
			foreach ($chosen_idx as $i) if (isset($options[$i])) $parts[]=$options[$i]['text'];
			foreach ($chosen_txt as $t) $parts[]=$t;
		}
	} elseif ($is_sort && $options) {
		foreach ($chosen_idx as $i) if (isset($options[$i])) $parts[]=$options[$i]['text'];
		if ($parts) $parts = [implode(' > ', $parts)];
	} else {
		// TEXTY → just show user's text (or canonical mapping fallback above)
		foreach ($chosen_txt as $t) if ($t!=='') $parts[]=$t;
	}

	if (!$parts) $parts = ['—'];

	// Output
	$html  = '<div class="ld-question-answer" style="margin:.5rem 0;padding:.75rem;border:1px solid #e5e7eb;border-radius:10px;">';
	$html .= '<strong>'.esc_html($a['label']).':</strong> '.esc_html(implode(' | ', array_unique($parts)));
	if ($debug) {
		$dbg = [
			'answer_type'=>$answer_type,
			'chosen_idx'=>$chosen_idx,
			'chosen_txt'=>$chosen_txt,
			'options'=>count($options),
		];
		$html .= '<pre style="margin-top:.5rem;background:#f9fafb;padding:.5rem;border:1px dashed #e5e7eb;white-space:pre-wrap;">'
			  .  esc_html(print_r($dbg,true)) . '</pre>';
	}
	$html .= '</div>';
	return $html;
});
