<?php
use Hamsobat\Broadcaster;
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var string $csrf */
$b = $app->broadcaster();
if (!$b->available()) {
    echo '<div class="card empty-state">برای ارسال پیام همگانی یک بار <b>migrate.php</b> را اجرا کنید (فقط یک جدول جدید اضافه می‌شود).</div>';
    return;
}
$recent = $b->recent(15);
$statusLabel = ['running' => ['● در حال ارسال', 'live'], 'paused' => ['⏸ متوقف', 'amber'], 'done' => ['✅ تمام شد', 'ok'], 'cancelled' => ['⛔ لغو شد', 'bad']];
?>
<section class="grid g-2-1">
  <form method="post" class="card compose" data-compose>
    <header><h3>📢 ارسال برای همه (تبلیغ، اطلاعیه، خبر)</h3></header>
    <?= Html::hidden($csrf) ?>
    <label class="field"><span>متن پیام</span>
      <textarea name="text" rows="7" maxlength="3900" required placeholder="سلام دوستان 👋&#10;…" data-preview-src></textarea>
    </label>
    <div class="row2">
      <label class="field"><span>دکمه زیر پیام (اختیاری)</span><input name="button_text" maxlength="64" placeholder="مثلاً: 🎁 دیدن پیشنهاد"></label>
      <label class="field"><span>لینک دکمه</span><input name="button_url" dir="ltr" placeholder="https://ble.ir/…"></label>
    </div>
    <label class="field"><span>به چه کسانی؟</span>
      <select name="audience">
        <?php foreach (Broadcaster::AUDIENCES as $key => $label): ?>
          <option value="<?= $key ?>"><?= Html::e($label) ?> — <?= Html::n($b->count($key)) ?> نفر</option>
        <?php endforeach; ?>
      </select>
    </label>
    <div class="btn-row">
      <button class="btn ghost" name="action" value="broadcast_test" formnovalidate>🧪 اول برای خودم بفرست</button>
      <button class="btn primary" name="action" value="broadcast" data-confirm-click="پیام برای گروه انتخاب‌شده فرستاده شود؟ بعد از شروع فقط می‌شود متوقف یا لغوش کرد.">🚀 ارسال</button>
    </div>
    <p class="muted small">پیام از طرف ربات و بدون حالت قالب‌بندی فرستاده می‌شود. کاربران معلق پیام نمی‌گیرند. ارسال در دسته‌های کوچک انجام می‌شود (حدود ۲۰ پیام در ثانیه)؛ وقتی این صفحه باز است سریع‌تر جلو می‌رود و در غیر این صورت همراه کار عادی ربات و کرون ادامه پیدا می‌کند.</p>
  </form>

  <div class="stack">
    <div class="card preview-card">
      <header><h3>👀 پیش‌نمایش</h3></header>
      <div class="bale-preview"><div class="bubble two" data-preview>متن پیام این‌جا دیده می‌شود…</div><div class="pv-button" data-preview-button hidden></div></div>
    </div>
    <form method="post" class="card compose">
      <header><h3>✉️ ارسال به یک نفر</h3></header>
      <?= Html::hidden($csrf) ?><input type="hidden" name="action" value="send_one">
      <label class="field"><span>گیرنده</span><input name="to" required placeholder="#12 یا لقب یا @یوزرنیم یا آیدی عددی بله"></label>
      <label class="field"><span>متن</span><textarea name="text" rows="4" maxlength="3900" required></textarea></label>
      <div class="row2">
        <label class="field"><span>دکمه (اختیاری)</span><input name="button_text" maxlength="64"></label>
        <label class="field"><span>لینک</span><input name="button_url" dir="ltr" placeholder="https://…"></label>
      </div>
      <button class="btn primary">ارسال</button>
    </form>
  </div>
</section>

<section class="card">
  <header><h3>🗂 ارسال‌های اخیر</h3></header>
  <?php if (!$recent): ?><div class="empty">هنوز پیامی برای همه فرستاده نشده.</div><?php endif; ?>
  <ul class="broadcasts" data-broadcasts>
    <?php foreach ($recent as $r):
        $pct = $r['total'] ? min(100, round(100 * ($r['sent'] + $r['failed']) / $r['total'])) : 100;
        [$label, $kind] = $statusLabel[$r['status']]; ?>
      <li data-bc="<?= (int) $r['id'] ?>" data-status="<?= Html::e($r['status']) ?>">
        <div class="bc-head">
          <?= Html::badge($label, $kind) ?>
          <span class="muted small"><?= Html::e(Broadcaster::AUDIENCES[$r['audience']] ?? $r['audience']) ?> · <?= Html::ago($r['created_at']) ?></span>
          <span class="bc-actions">
            <?php if ($r['status'] === 'running'): ?><?= Html::action($csrf, 'broadcast_status', ['id' => $r['id'], 'status' => 'paused'], '⏸ توقف', 'btn sm ghost') ?><?php endif; ?>
            <?php if ($r['status'] === 'paused'): ?><?= Html::action($csrf, 'broadcast_status', ['id' => $r['id'], 'status' => 'running'], '▶️ ادامه', 'btn sm') ?><?php endif; ?>
            <?php if (in_array($r['status'], ['running', 'paused'], true)): ?><?= Html::action($csrf, 'broadcast_status', ['id' => $r['id'], 'status' => 'cancelled'], '⛔ لغو', 'btn sm bad', 'ارسال لغو شود؟') ?><?php endif; ?>
          </span>
        </div>
        <p class="bc-text"><?= nl2br(Html::e(mb_strimwidth($r['text'], 0, 220, '…'))) ?><?= $r['button_text'] ? ' ' . Html::badge('🔗 ' . $r['button_text']) : '' ?></p>
        <div class="meter"><span style="width:<?= $pct ?>%"></span></div>
        <p class="small muted" data-bc-count><?= Html::n($r['sent']) ?> رسید · <?= Html::n($r['failed']) ?> نرسید (ربات را بسته‌اند) · از <?= Html::n($r['total']) ?></p>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
