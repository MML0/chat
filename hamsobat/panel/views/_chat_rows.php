<?php
use Hamsobat\Html;
use Hamsobat\PanelData;

defined('HAMSOBAT') || exit;
/** @var array $rows */
if (!$rows): ?>
  <div class="empty">هنوز گفت‌وگویی نیست.</div>
<?php return; endif; ?>
<ul class="chat-list">
<?php foreach ($rows as $c):
    $a = ['id' => $c['user_one_id'], 'nickname' => $c['a_nick'], 'photo_file_id' => $c['a_photo']];
    $b = ['id' => $c['user_two_id'], 'nickname' => $c['b_nick'], 'photo_file_id' => $c['b_photo']];
    $end = $c['ended_at'] ? Html::time($c['ended_at']) : time();
    $preview = $c['last_type'] === null ? 'بدون پیام' : ($c['last_type'] === 'text' ? (string) $c['last_text'] : Html::typeIcon($c['last_type']) . ' ' . ($c['last_text'] ?: 'رسانه'));
?>
  <li>
    <a class="chat-row" href="?p=chat&id=<?= (int) $c['id'] ?>">
      <span class="pair"><?= Html::avatar($a, 'sm') ?><?= Html::avatar($b, 'sm') ?></span>
      <span class="names"><span class="nm"><b><?= Html::e($c['a_nick']) ?></b> <i>⇄</i> <b><?= Html::e($c['b_nick']) ?></b></span>
        <small><?= Html::e(mb_strimwidth($preview, 0, 90, '…')) ?></small></span>
      <span class="meta">
        <?php if ($c['status'] === 'active'): ?><span class="badge live">● در حال گفت‌وگو</span>
        <?php else: ?><span class="badge"><?= Html::e(PanelData::END_REASONS[$c['end_reason']] ?? 'پایان') ?></span><?php endif; ?>
        <?php if ($c['reports']): ?><span class="badge bad">🚩 <?= Html::n($c['reports']) ?></span><?php endif; ?>
        <small><?= Html::n($c['message_count']) ?> پیام · <?= Html::duration(max(0, $end - Html::time($c['started_at']))) ?> · <?= Html::ago($c['started_at']) ?></small>
      </span>
    </a>
  </li>
<?php endforeach; ?>
</ul>
