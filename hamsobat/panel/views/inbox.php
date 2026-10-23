<?php
use Hamsobat\Html;
use Hamsobat\PanelData;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data */
$sessionId = (int) ($_GET['session'] ?? 0);
if ($sessionId) {
    $t = $data->thread($sessionId);
    if (!$t) {
        echo '<div class="card empty-state">این گفت‌وگو پیدا نشد.</div>';
        return;
    }
    ?>
    <section class="card chat-head">
      <a class="person side-one" href="?p=user&id=<?= (int) $t['visitor']['id'] ?>"><?= Html::avatar($t['visitor'], 'lg') ?><span><b><?= Html::e($t['visitor']['nickname'] ?? '#' . $t['visitor']['id']) ?></b><small>فرستنده ناشناس (صاحب لینک او را نمی‌شناسد)</small></span></a>
      <div class="chat-meta"><span class="badge violet">📩 صندوق ناشناس</span><div><?= Html::n(count($t['messages'])) ?> پیام</div><small class="muted">شروع <?= Html::date($t['created_at']) ?></small><a class="link" href="?p=inbox">← همه پیام‌ها</a></div>
      <a class="person side-two" href="?p=user&id=<?= (int) $t['owner']['id'] ?>"><?= Html::avatar($t['owner'], 'lg') ?><span><b><?= Html::e($t['owner']['nickname'] ?? '#' . $t['owner']['id']) ?></b><small>صاحب لینک</small></span></a>
    </section>
    <section class="card transcript-card"><div class="transcript">
      <?php foreach ($t['messages'] as $m): ?>
        <?= panel_view('_bubble', ['m' => $m, 'side' => (int) $m['sender_user_id'] === (int) $t['visitor_user_id'] ? 'one' : 'two', 'src' => 'i']) ?>
      <?php endforeach; ?>
    </div></section>
    <?php
    return;
}
$pageNo = max(1, (int) ($_GET['n'] ?? 1));
[$rows, $total] = $data->inbox($pageNo);
?>
<section class="card">
  <header><h3>📩 پیام‌های ناشناس</h3><span class="muted"><?= Html::n($total) ?> پیام · فرستنده فقط برای شما معلوم است</span></header>
  <?php if (!$rows): ?><div class="empty">هنوز کسی پیام ناشناس نفرستاده.</div><?php endif; ?>
  <ul class="chat-list">
    <?php foreach ($rows as $m):
        $s = ['id' => $m['sender_user_id'], 'nickname' => $m['s_nick'], 'photo_file_id' => $m['s_photo']];
        $r = ['id' => $m['recipient_user_id'], 'nickname' => $m['r_nick'], 'photo_file_id' => $m['r_photo']]; ?>
      <li><a class="chat-row" href="?p=inbox&session=<?= (int) $m['session_id'] ?>">
        <span class="pair"><?= Html::avatar($s, 'sm') ?><?= Html::avatar($r, 'sm') ?></span>
        <span class="names"><span class="nm"><b><?= Html::e($m['s_nick'] ?? '#' . $m['sender_user_id']) ?></b> <i>➜</i> <b><?= Html::e($m['r_nick'] ?? '#' . $m['recipient_user_id']) ?></b></span>
          <small><?= Html::typeIcon($m['message_type']) ?> <?= Html::e(mb_strimwidth((string) $m['text_content'], 0, 100, '…')) ?></small></span>
        <span class="meta">
          <?= $m['reply_to_id'] ? Html::badge('↩️ پاسخ') : '' ?>
          <?= $m['status'] === 'blocked' ? Html::badge('🚫 مسدود', 'bad') : ($m['status'] === 'new' ? Html::badge('📬 نخوانده', 'amber') : '') ?>
          <small><?= Html::n($m['thread']) ?> پیام در این گفت‌وگو · <?= Html::ago($m['created_at']) ?></small>
        </span>
      </a></li>
    <?php endforeach; ?>
  </ul>
  <?= panel_view('_pager', ['pageNo' => $pageNo, 'pagesCount' => max(1, (int) ceil($total / PanelData::PER_PAGE))]) ?>
</section>
