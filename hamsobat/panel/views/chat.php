<?php
use Hamsobat\Html;
use Hamsobat\PanelData;
use Hamsobat\Text;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data @var string $csrf */
$c = $data->chat((int) ($_GET['id'] ?? 0));
if (!$c) {
    echo '<div class="card empty-state">این گفت‌وگو پیدا نشد. <a href="?p=chats">برگشت</a></div>';
    return;
}
$messages = $data->messages((int) $c['id']);
$today = $app->today();
$end = $c['ended_at'] ? Html::time($c['ended_at']) : time();
$retention = $app->settings->int('message_retention_days');
$person = function (?array $u, string $side) use ($today): string {
    if (!$u) {
        return '<div class="person">—</div>';
    }
    return '<a class="person side-' . $side . '" href="?p=user&id=' . (int) $u['id'] . '">' . Html::avatar($u, 'lg') . '<span><b>' . Html::e($u['nickname'] ?? '#' . $u['id']) . '</b><small>'
        . Html::gender($u['gender']) . ($u['birth_date'] ? ' · ' . Text::fa(Text::age($u['birth_date'], $today)) . ' ساله' : '') . ($u['city'] ? ' · ' . Html::e($u['city']) : '') . '</small></span></a>';
};
?>
<section class="card chat-head">
  <?= $person($c['one'], 'one') ?>
  <div class="chat-meta">
    <?php if ($c['status'] === 'active'): ?><span class="badge live">● در حال گفت‌وگو</span><?php else: ?><span class="badge"><?= Html::e(PanelData::END_REASONS[$c['end_reason']] ?? 'پایان') ?></span><?php endif; ?>
    <div><?= Html::n($c['message_count']) ?> پیام · <?= Html::duration(max(0, $end - Html::time($c['started_at']))) ?></div>
    <small class="muted">شروع <?= Html::date($c['started_at']) ?></small>
    <?php if ($c['status'] === 'active'): ?><?= Html::action($csrf, 'end_chat', ['chat_id' => $c['id']], '🏁 پایان این چت', 'btn sm warn', 'این گفت‌وگو تمام شود؟ هر دو نفر خبردار می‌شوند.') ?><?php endif; ?>
  </div>
  <?= $person($c['two'], 'two') ?>
</section>

<?php foreach ($c['reports'] as $r): ?>
  <div class="flash bad">🚩 گزارش #<?= Html::n($r['id']) ?> از <b><?= Html::e($r['reporter']) ?></b>: <?= Html::e(Hamsobat\Moderation::REASONS[$r['reason']] ?? $r['reason']) ?><?= $r['details'] ? ' — «' . Html::e($r['details']) . '»' : '' ?> · <?= Html::reportStatus($r['status']) ?> · <a href="?p=reports">رسیدگی</a></div>
<?php endforeach; ?>

<section class="card transcript-card">
  <div class="transcript" data-transcript data-chat="<?= (int) $c['id'] ?>" data-active="<?= $c['status'] === 'active' ? 1 : 0 ?>" data-after="<?= (int) (end($messages)['id'] ?? 0) ?>">
    <?php if (!$messages): ?>
      <div class="empty" data-transcript-empty><?= $c['message_count'] ? '🧹 پیام‌های این گفت‌وگو طبق تنظیمات (' . Text::fa($retention) . ' روز) پاک شده‌اند.' : 'هنوز پیامی رد و بدل نشده.' ?></div>
    <?php endif; ?>
    <?php $lastDay = null; foreach ($messages as $m):
        $day = Html::date($m['created_at'], false);
        if ($day !== $lastDay): $lastDay = $day; ?><div class="day-sep"><span><?= $day ?></span></div><?php endif;
        $side = (int) $m['sender_user_id'] === (int) $c['user_one_id'] ? 'one' : 'two'; ?>
      <?= panel_view('_bubble', ['m' => $m, 'side' => $side, 'src' => 'm']) ?>
    <?php endforeach; ?>
  </div>
  <p class="muted small center">پیام‌ها <?= Text::fa($retention) ?> روز نگه داشته می‌شوند (تنظیمات → نگهداری پیام). پیام‌های قرمز به طرف مقابل نرسیده‌اند.</p>
</section>
