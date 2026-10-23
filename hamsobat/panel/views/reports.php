<?php
use Hamsobat\Html;
use Hamsobat\Moderation;
use Hamsobat\PanelData;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data @var string $csrf */
$status = (string) ($_GET['status'] ?? 'open');
$pageNo = max(1, (int) ($_GET['n'] ?? 1));
[$rows, $total] = $data->reports($status, $pageNo);
?>
<div class="filters card">
  <div class="seg">
    <?php foreach (['open' => '🚩 باز', 'resolved' => '✅ رسیدگی‌شده', 'dismissed' => '❌ ردشده'] as $v => $l): ?>
      <a href="?p=reports&status=<?= $v ?>" class="<?= $status === $v ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <span class="muted"><?= Html::n($total) ?> گزارش</span>
</div>
<?php if (!$rows): ?><div class="card empty-state">🎉 گزارشی در این بخش نیست.</div><?php endif; ?>
<section class="report-grid">
<?php foreach ($rows as $r):
    $a = ['id' => $r['reporter_user_id'], 'nickname' => $r['a_nick'], 'photo_file_id' => $r['a_photo']];
    $b = ['id' => $r['reported_user_id'], 'nickname' => $r['b_nick'], 'photo_file_id' => $r['b_photo']]; ?>
  <article class="card report">
    <header>
      <h3><?= Html::badge(Moderation::REASONS[$r['reason']] ?? $r['reason'], 'bad') ?> <small class="muted">#<?= Html::n($r['id']) ?> · <?= Html::ago($r['created_at']) ?></small></h3>
      <?php if ($r['b_status'] === 'suspended'): ?><span class="badge bad">⛔ معلق است</span><?php endif; ?>
    </header>
    <p class="report-who"><?= Html::who($a) ?> <span class="muted">گزارش داد</span> <?= Html::who($b) ?>
      <span class="muted">(<?= Html::n($r['b_reports']) ?> گزارش از <?= Html::n($r['b_reporters']) ?> نفر)</span></p>
    <?php if ($r['details']): ?><blockquote>«<?= Html::e($r['details']) ?>»</blockquote><?php endif; ?>
    <?php if ($r['excerpt']): ?>
      <div class="excerpt">
        <?php foreach ($r['excerpt'] as $m): ?>
          <div class="<?= (int) $m['sender_user_id'] === (int) $r['reported_user_id'] ? 'reported' : 'reporter' ?>">
            <b><?= (int) $m['sender_user_id'] === (int) $r['reported_user_id'] ? Html::e($r['b_nick']) : Html::e($r['a_nick']) ?>:</b>
            <?= Html::typeIcon($m['message_type']) ?> <?= Html::e(mb_strimwidth((string) $m['text_content'], 0, 140, '…')) ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <footer class="btn-row">
      <?php if ($r['chat_id']): ?><a class="btn sm" href="?p=chat&id=<?= (int) $r['chat_id'] ?>">💬 کل گفت‌وگو</a><?php endif; ?>
      <?php if ($r['status'] === 'open'): ?>
        <?= Html::action($csrf, 'suspend', ['user_id' => $r['reported_user_id'], 'hours' => 24], '⛔ ۲۴ ساعت', 'btn sm bad', 'کاربر گزارش‌شده ۲۴ ساعت تعلیق شود؟') ?>
        <?= Html::action($csrf, 'suspend', ['user_id' => $r['reported_user_id'], 'hours' => 0], '⛔ دائم', 'btn sm bad', 'کاربر گزارش‌شده برای همیشه تعلیق شود؟') ?>
        <?= Html::action($csrf, 'report', ['report_id' => $r['id'], 'status' => 'resolved'], '✅ رسیدگی شد', 'btn sm ok') ?>
        <?= Html::action($csrf, 'report', ['report_id' => $r['id'], 'status' => 'dismissed'], '❌ رد', 'btn sm ghost') ?>
      <?php else: ?>
        <?= Html::action($csrf, 'report', ['report_id' => $r['id'], 'status' => 'open'], '↩️ باز کردن دوباره', 'btn sm ghost') ?>
      <?php endif; ?>
    </footer>
  </article>
<?php endforeach; ?>
</section>
<?= panel_view('_pager', ['pageNo' => $pageNo, 'pagesCount' => max(1, (int) ceil($total / PanelData::PER_PAGE))]) ?>
