<?php
// JSON for the panel's live parts (same login session as index.php, read-only).
//   ?a=live              counters for the top bar / dashboard
//   ?a=feed&after=ID     newest relayed messages (live page)
//   ?a=chat&id=N&after=ID new messages of one chat (open transcript)
require dirname(__DIR__) . '/bootstrap.php';

use Hamsobat\App;
use Hamsobat\Html;
use Hamsobat\Metrics;
use Hamsobat\PanelAuth;
use Hamsobat\PanelData;

$config = hamsobat_config();
date_default_timezone_set('UTC');
Html::setTimezone($config['timezone'] ?? 'Asia/Tehran');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$app = App::fromConfig($config);
PanelAuth::start();
$auth = new PanelAuth($app);
session_write_close(); // do not block other panel requests
if (!$auth->loggedIn()) {
    http_response_code(401);
    exit('{"error":"login"}');
}
$data = new PanelData($app);
$out = ['live' => $data->live()];
$out['live']['last_hook_ago'] = $out['live']['last_hook'] ? Html::ago($out['live']['last_hook'], $app->now()) : null;

switch ($_GET['a'] ?? 'live') {
    case 'feed':
        $out['live']['perf'] = Metrics::summary($app, 5);
        $out['items'] = array_map(fn ($m) => [
            'id'     => (int) $m['id'],
            'chat'   => (int) $m['chat_id'],
            'type'   => $m['message_type'],
            'icon'   => Html::typeIcon($m['message_type']),
            'text'   => mb_substr((string) $m['text_content'], 0, 300),
            'status' => $m['delivery_status'],
            'file'   => (bool) $m['has_file'],
            'time'   => Html::clock($m['created_at']),
            'from'   => ['id' => (int) $m['s_id'], 'nick' => $m['s_nick'], 'g' => $m['s_gender']],
            'to'     => ['id' => (int) $m['r_id'], 'nick' => $m['r_nick'], 'g' => $m['r_gender']],
        ], $data->feed(max(0, (int) ($_GET['after'] ?? 0))));
        break;

    case 'broadcast':
        $b = $app->broadcaster();
        $out['step'] = $b->running() ? $b->step(2.5, 15) : null;
        $out['items'] = array_map(fn ($r) => [
            'id' => (int) $r['id'], 'status' => $r['status'], 'total' => (int) $r['total'], 'sent' => (int) $r['sent'], 'failed' => (int) $r['failed'],
        ], $b->recent(15));
        break;

    case 'chat':
        $chat = $app->db->row('SELECT id, status, user_one_id FROM {chats} WHERE id = ?', [(int) ($_GET['id'] ?? 0)]);
        $out['status'] = $chat['status'] ?? null;
        $out['items'] = $chat ? array_map(fn ($m) => [
            'id'     => (int) $m['id'],
            'side'   => (int) $m['sender_user_id'] === (int) $chat['user_one_id'] ? 'one' : 'two',
            'type'   => $m['message_type'],
            'text'   => (string) $m['text_content'],
            'status' => $m['delivery_status'],
            'file'   => $m['bale_file_id'] !== null,
            'time'   => Html::clock($m['created_at']),
        ], $data->messages((int) $chat['id'], max(0, (int) ($_GET['after'] ?? 0)), 200)) : [];
        break;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
