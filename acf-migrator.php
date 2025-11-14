<?php
/**
 * Plugin Name: ACF Option → Post Migration
 * Description: ACFフィールド値を移行する管理画面ツール。ドライラン機能付き。使用後は削除してください。
 * Version:     2.0
 * Author:      satokupo helper
 * Requires PHP: 8.0
 */

// 管理画面メニューの追加
add_action('admin_menu', 'acf_migrator_add_menu');

function acf_migrator_add_menu() {
  add_options_page(
    'ACF Migrator',              // ページタイトル
    'ACF Migrator',              // メニュータイトル
    'manage_options',            // 権限
    'acf-migrator',              // スラッグ
    'acf_migrator_settings_page' // コールバック関数
  );
}

/**
 * 設定画面の表示
 */
function acf_migrator_settings_page() {
  // 権限チェック
  if (!current_user_can('manage_options')) {
    wp_die('権限がありません');
  }

  // フォーム送信処理
  $report = null;
  if (isset($_POST['acf_migrator_submit'])) {
    check_admin_referer('acf_migrator_action', 'acf_migrator_nonce');

    $source_post_id = sanitize_text_field($_POST['source_post_id']);
    $target_post_id = intval($_POST['target_post_id']);
    $group_selector = sanitize_text_field($_POST['group_selector']);
    $dry_run = isset($_POST['dry_run']);

    // 移行処理を実行
    $report = acf_migrator_execute($source_post_id, $target_post_id, $group_selector, $dry_run);
  }

  // HTML出力
  ?>
  <div class="wrap">
    <h1><span class="dashicons dashicons-admin-settings"></span> ACF Migrator</h1>
    <p>ACFフィールド値を移行します。まずドライランで確認してから本番実行してください。</p>

    <form method="post" action="">
      <?php wp_nonce_field('acf_migrator_action', 'acf_migrator_nonce'); ?>

      <table class="form-table">
        <tr>
          <th scope="row"><label for="source_post_id">移行元ID</label></th>
          <td>
            <input type="text" name="source_post_id" id="source_post_id"
                   value="<?php echo esc_attr($_POST['source_post_id'] ?? 'option'); ?>"
                   class="regular-text">
            <p class="description">オプションページの場合は 'option'、投稿からの場合は投稿ID</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="target_post_id">移行先ID</label></th>
          <td>
            <input type="number" name="target_post_id" id="target_post_id"
                   value="<?php echo esc_attr($_POST['target_post_id'] ?? ''); ?>"
                   class="regular-text" required>
            <p class="description">コピー先の投稿ID（必須）</p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="group_selector">グループセレクター</label></th>
          <td>
            <input type="text" name="group_selector" id="group_selector"
                   value="<?php echo esc_attr($_POST['group_selector'] ?? 'group_xxxxxxxxxx'); ?>"
                   class="regular-text" required>
            <p class="description">単一: 'group_xxx'、複数: ["group_xxx","group_yyy"]、全て: 'all'（必須）</p>
          </td>
        </tr>
        <tr>
          <th scope="row">ドライランモード</th>
          <td>
            <label>
              <input type="checkbox" name="dry_run" value="1"
                     <?php checked(!isset($_POST['acf_migrator_submit']) || isset($_POST['dry_run'])); ?>>
              試走モード（書き込みなし、レポートのみ）
            </label>
          </td>
        </tr>
      </table>

      <?php submit_button('移行を実行', 'primary', 'acf_migrator_submit'); ?>
    </form>

    <?php if ($report): ?>
    <h2>実行レポート</h2>
    <textarea readonly style="width:100%;height:500px;font-family:monospace;font-size:12px;"><?php
      echo esc_textarea(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    ?></textarea>
    <?php endif; ?>
  </div>
  <?php
}

/**
 * ACFフィールド値の移行処理を実行
 */
function acf_migrator_execute($source_post_id, $target_post_id, $group_selector, $dry_run) {
  // ACF存在チェック
  if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
    return ['error' => 'ACFが有効化されていません'];
  }

  // レポート器
  $report = [
    'dry_run'        => $dry_run,
    'source_post_id' => $source_post_id,
    'target_post_id' => $target_post_id,
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
  ];

  // ターゲット投稿の存在確認（軽チェック）
  if (!$target_post_id || get_post_status($target_post_id) === false) {
    $report['error'] = "TARGET_POST_ID={$target_post_id} is invalid or not found.";
    return $report;
  }

  // 対象グループの決定
  $groups = acf_get_field_groups();
  if (!is_array($groups)) $groups = [];

  $target_groups = [];

  // グループセレクターの解析（フェーズ5）
  if ($group_selector === 'all') {
    // 全グループを対象
    $target_groups = $groups;
  } elseif (strpos($group_selector, '[') === 0) {
    // JSON配列形式として解析を試みる（例: ["group_xxx","group_yyy"]）
    $parsed = json_decode($group_selector, true);
    if (is_array($parsed)) {
      $allowed = array_flip($parsed);
      foreach ($groups as $g) {
        if (!empty($g['key']) && isset($allowed[$g['key']])) {
          $target_groups[] = $g;
        }
      }
    }
  } else {
    // 単一グループキー（例: "group_xxx"）
    foreach ($groups as $g) {
      if (!empty($g['key']) && $g['key'] === $group_selector) {
        $target_groups[] = $g;
        break;
      }
    }
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
      $group_node['tree'][] = enumerate_and_process_field($field, $source_post_id, $target_post_id, $dry_run, $report['totals']);
    }

    $report['groups'][] = $group_node;
  }

  return $report;
}

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

