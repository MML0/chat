<?php
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var Hamsobat\PanelData $data */
$level = in_array($_GET['level'] ?? '', ['error', 'warning'], true) ? $_GET['level'] : '';
$errors = $data->errors(80, $level);
$audit = $data->audit(60);
$actions = ['login' => '🔑 ورود', 'logout' => '🚪 خروج', 'login_failed' => '⛔ رمز اشتباه', 'credit' => '🎁 اعتبار', 'unlimited' => '💎 نامحدود', 'unlock' => '🔓 بدون دعوت',
    'reset_today' => '🔄 سهمیه', 'suspend' => '⛔ تعلیق', 'unsuspend' => '✅ آزاد', 'end_user_chat' => '🏁 پایان چت', 'end_chat' => '🏁 پایان چت', 'message' => '✉️ پیام',
    'nickname' => '✏️ لقب', 'clear' => '🗑 پاک کردن', 'report' => '🚩 گزارش', 'settings' => '⚙️ تنظیمات', 'cleanup' => '🧹 پاک‌سازی', 'webhook' => '🔗 وب‌هوک'];
?>
<section class="grid g-1-1">
  <div class="card">
    <header><h3>⚠️ خطاها</h3>
      <div class="seg small">
        <?php foreach (['' => 'همه', 'error' => 'خطا', 'warning' => 'هشدار'] as $v => $l): ?><a href="?p=logs&level=<?= $v ?>" class="<?= $level === $v ? 'on' : '' ?>"><?= $l ?></a><?php endforeach; ?>
      </div>
    </header>
    <?php if (!$errors): ?><div class="empty">✅ خطایی ثبت نشده.</div><?php endif; ?>
    <ul class="log">
      <?php foreach ($errors as $e): ?>
        <li class="<?= Html::e($e['level']) ?>"><span class="when"><?= Html::date($e['created_at']) ?></span><b><?= Html::e($e['message']) ?></b>
          <?php if ($e['context']): ?><code dir="ltr"><?= Html::e(mb_strimwidth($e['context'], 0, 400, '…')) ?></code><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card">
    <header><h3>🗂 کارهای انجام‌شده در پنل</h3></header>
    <?php if (!$audit): ?><div class="empty">هنوز کاری ثبت نشده.</div><?php endif; ?>
    <ul class="log audit">
      <?php foreach ($audit as $a): ?>
        <li><span class="when"><?= Html::date($a['created_at']) ?> · <span dir="ltr"><?= Html::e($a['ip']) ?></span></span>
          <b><?= Html::e($actions[$a['action']] ?? $a['action']) ?></b> <?= Html::e($a['target'] ?? '') ?> <small class="muted"><?= Html::e($a['details'] ?? '') ?></small></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
