<?php
/**
 * Plugin Name: ACF Option -> Post Migration (Dry-run first)
 * Description: Copy ACF values from 'option' to a target post by field_key with nested tree report. Delete after use.
 * Version:     1.0
 * Author:      satokupo web system
 *
 * 使い方:
 *   1) 下の「=== SETTINGS (edit here) ===」の3変数だけを手動編集
 *   2) 管理者でログインして管理画面にアクセスするとJSONレポートが表示されます
 *   3) まず DRY_RUN を true（既定）で確認 → 問題なければ false にして本番コピー
 *   4) 実行後は本ファイルを削除
 */

/* === SETTINGS (edit here) : 手動で書き換えるのはこの3つだけ ================== */
$DRY_RUN        = true;                     // true=試走（書き込みなし） / false=本番（書き込み）
$TARGET_POST_ID = 12345;                    // 移設先の投稿ID（固定/CPT）
$GROUP_SELECTOR = 'all';                    // 'all' もしくは ['group_abcd1234','group_efgh5678']
/* ============================================================================ */

add_action('admin_init', function () use ($DRY_RUN, $TARGET_POST_ID, $GROUP_SELECTOR) {
  // 実行ガード
  if ( !is_user_logged_in() || !current_user_can('manage_options') ) {
    return;
  }

  // ACF存在チェック
  if ( !function_exists('acf_get_field_groups') || !function_exists('acf_get_fields') ) {
    return;
  }

  // レポート器
  $report = [
    'dry_run'        => $DRY_RUN,
    'target_post_id' => $TARGET_POST_ID,
    'timestamp'      => gmdate('c'),
    'groups'         => [],
    'totals'         => [
      'groups'   => 0,
      'fields'   => 0,
      'non_empty'=> 0,
      'updated'  => 0,
      'failed'   => 0,
      'skipped'  => 0,
      'would_update' => 0,
    ],
    'notes' => [
      'selector' => is_array($GROUP_SELECTOR) ? 'ids' : (string)$GROUP_SELECTOR,
      'hint'     => "Delete this plugin after use.",
    ],
  ];

  // ターゲット投稿の存在確認（軽チェック）
  if ( !$TARGET_POST_ID || get_post_status($TARGET_POST_ID) === false ) {
    $report['error'] = "TARGET_POST_ID={$TARGET_POST_ID} is invalid or not found.";
    output_json_and_exit($report);
  }

  // 対象グループの決定
  $groups = acf_get_field_groups();
  if (!is_array($groups)) $groups = [];

  $target_groups = [];
  if ($GROUP_SELECTOR === 'all') {
    $target_groups = $groups;
  } elseif (is_array($GROUP_SELECTOR)) {
    $allowed = array_flip($GROUP_SELECTOR);
    foreach ($groups as $g) {
      if (!empty($g['key']) && isset($allowed[$g['key']])) {
        $target_groups[] = $g;
      }
    }
  } else {
    // 想定外 → 空
    $target_groups = [];
  }

  $report['totals']['groups'] = count($target_groups);

  // 各グループ処理
  foreach ($target_groups as $g) {
    $g_key   = $g['key']   ?? '';
    $g_title = $g['title'] ?? '';
    $g_loc   = $g['location'] ?? null;

    $group_node = [
      'group_key'   => $g_key,
      'group_title' => $g_title,
      'location'    => $g_loc,
      'tree'        => [],
    ];

    // グループ配下のフィールド定義を取得
    $fields = acf_get_fields($g_key);
    if (!is_array($fields)) $fields = [];

    // 再帰列挙してツリー構築 & 値コピー/レポート
    foreach ($fields as $field) {
      $group_node['tree'][] = enumerate_and_process_field($field, 'option', $TARGET_POST_ID, $DRY_RUN, $report['totals']);
    }

    $report['groups'][] = $group_node;
  }

  output_json_and_exit($report);
});

/**
 * 再帰的にフィールド定義をたどり、各ノードで 'option' の値を調べ
 * DRY_RUNに応じて update_field を実行 or 予定として記録。
 */
function enumerate_and_process_field(array $field, $source_post_id, $target_post_id, $dry_run, array &$totals, $path = []) {
  $node = [
    'label' => $field['label'] ?? '',
    'name'  => $field['name']  ?? '',
    'key'   => $field['key']   ?? '',
    'type'  => $field['type']  ?? '',
    'path'  => array_merge($path, [($field['name'] ?? '')]),
    'value' => null,
    'result'=> null,
    'children' => [],
  ];

  $key = $node['key'];
  if (!$key) {
    $node['result'] = 'skipped';
    $totals['skipped']++;
    return $node;
  }

  // 値を取得（'option'）
  $value = get_field($key, $source_post_id);
  $node['value'] = summarize_value($value);

  // ネスト子の列挙（定義に基づく）
  $type = $node['type'];

  if ($type === 'group' && !empty($field['sub_fields'])) {
    foreach ($field['sub_fields'] as $sub) {
      $node['children'][] = enumerate_and_process_field($sub, $source_post_id, $target_post_id, $dry_run, $totals, $node['path']);
    }
  }
  elseif ($type === 'repeater' && !empty($field['sub_fields'])) {
    // 定義上のサブフィールドをそのまま列挙（値の行ごと展開はレポート簡素化のため省略）
    foreach ($field['sub_fields'] as $sub) {
      $node['children'][] = enumerate_and_process_field($sub, $source_post_id, $target_post_id, $dry_run, $totals, $node['path']);
    }
  }
  elseif ($type === 'flexible_content' && !empty($field['layouts'])) {
    foreach ($field['layouts'] as $layout) {
      $layout_node = [
        'label' => $layout['label'] ?? '',
        'name'  => $layout['name']  ?? '',
        'key'   => $layout['key']   ?? '',
        'type'  => 'flex_layout',
        'path'  => array_merge($node['path'], [($layout['name'] ?? '')]),
        'value' => null,
        'result'=> 'skipped', // レイアウト自体は値ノードでない
        'children' => [],
      ];
      if (!empty($layout['sub_fields'])) {
        foreach ($layout['sub_fields'] as $sub) {
          $layout_node['children'][] = enumerate_and_process_field($sub, $source_post_id, $target_post_id, $dry_run, $totals, $layout_node['path']);
        }
      }
      $node['children'][] = $layout_node;
    }
  }
  elseif ($type === 'clone' && !empty($field['clone'])) {
    // クローン先の key/name が混在する可能性あり -> key から定義を取得して展開を試みる
    foreach ((array)$field['clone'] as $clone_ref) {
      $cloned = acf_get_field($clone_ref);
      if (is_array($cloned)) {
        $node['children'][] = enumerate_and_process_field($cloned, $source_post_id, $target_post_id, $dry_run, $totals, $node['path']);
      }
    }
  }

  // レポート集計
  $totals['fields']++;
  $is_non_empty = !is_null($value) && $value !== '' && $value !== [] && $value !== false;
  if ($is_non_empty) $totals['non_empty']++;

  // 書き込み/擬似書き込み
  if (!$is_non_empty) {
    $node['result'] = 'skipped';
    $totals['skipped']++;
  } else {
    if ($dry_run) {
      $node['result'] = 'would_update';
      $totals['would_update']++;
    } else {
      $ok = update_field($key, $value, $target_post_id);
      if ($ok) {
        $node['result'] = 'updated';
        $totals['updated']++;
      } else {
        $node['result'] = 'failed';
        $totals['failed']++;
      }
    }
  }

  return $node;
}

/**
 * 値の要約: 型/サイズ/先頭断片（長文は切り詰め）
 */
function summarize_value($v) {
  $type = get_debug_type($v); // PHP8
  if (is_array($v)) {
    $size = 'array(' . count($v) . ')';
    $preview = json_encode(sample_array($v), JSON_UNESCAPED_UNICODE);
    return ['type'=>$type, 'size'=>$size, 'preview'=>$preview];
  }
  if (is_string($v)) {
    $len = mb_strlen($v, 'UTF-8');
    $preview = mb_substr($v, 0, 120, 'UTF-8');
    if ($len > 120) $preview .= '…';
    return ['type'=>'string', 'size'=>"string({$len})", 'preview'=>$preview];
  }
  if (is_object($v)) {
    // オブジェクトはプロパティの先頭数件のみ
    $arr = [];
    foreach (get_object_vars($v) as $k => $vv) {
      $arr[$k] = is_scalar($vv) ? $vv : get_debug_type($vv);
      if (count($arr) >= 8) break;
    }
    return ['type'=>$type, 'size'=>'object', 'preview'=>json_encode($arr, JSON_UNESCAPED_UNICODE)];
  }
  if (is_bool($v)) {
    return ['type'=>'bool', 'size'=>'bool', 'preview'=>($v ? 'true' : 'false')];
  }
  if (is_int($v) || is_float($v)) {
    return ['type'=>$type, 'size'=>$type, 'preview'=>(string)$v];
  }
  if (is_null($v)) {
    return ['type'=>'null', 'size'=>'null', 'preview'=>'null'];
  }
  return ['type'=>$type, 'size'=>$type, 'preview'=>'(unprintable)'];
}

/**
 * 配列のサンプル（先頭数件のみ）
 */
function sample_array(array $a, $limit = 8) {
  $out = [];
  $i = 0;
  foreach ($a as $k => $v) {
    if ($i++ >= $limit) { $out['…'] = '(truncated)'; break; }
    $out[$k] = is_scalar($v) ? $v : get_debug_type($v);
  }
  return $out;
}

/**
 * JSON出力して即終了（管理画面での実行を想定）
 */
function output_json_and_exit($payload) {
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}
