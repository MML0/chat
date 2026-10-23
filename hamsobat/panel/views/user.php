<?php
use Hamsobat\Charts;
use Hamsobat\Html;
use Hamsobat\Text;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var Hamsobat\PanelData $data @var string $csrf */
$u = $data->user((int) ($_GET['id'] ?? 0));
if (!$u) {
    echo '<div class="card empty-state">کاربر پیدا نشد. <a href="?p=users">برگشت به کاربران</a></div>';
    return;
}
$id = (int) $u['id'];
$q = $u['quota'];
$today = $app->today();
$online = $u['last_seen_at'] && Html::time($u['last_seen_at']) >= time() - 300;
$isAdmin = $app->isAdmin((int) $u['bale_user_id']);
$activity = [];
for ($i = 13; $i >= 0; $i--) {
    $activity[] = (int) ($u['activity'][$app->today($app->now() - $i * 86400)] ?? 0);
}
$reasons = Hamsobat\Moderation::REASONS;
$ledgerReasons = ['chat' => '💬 چت', 'referral' => '🎁 دعوت', 'admin' => '👑 هدیه مدیر', 'refund' => '↩️ برگشت'];
?>
<section class="profile-head card">
  <div class="ph-avatar"><?= Html::avatar($u, 'xl', $online) ?></div>
  <div class="ph-main">
    <h2><?= Html::e($u['nickname'] ?? 'بدون لقب') ?> <small class="muted">#<?= Html::n($id) ?></small></h2>
    <p class="ph-account"><?= Html::account($u['account']) ?></p>
    <p><?= Html::e($u['display_name'] ?? '—') ?> · <?= Html::gender($u['gender']) ?> · <?= $u['birth_date'] ? Html::n(Text::age($u['birth_date'], $today)) . ' ساله' : 'سن نامشخص' ?><?= $u['city'] ? ' · 📍 ' . Html::e($u['city']) : '' ?></p>
    <div class="badges">
      <?php if ($online): ?><span class="badge ok">🟢 آنلاین</span><?php endif; ?>
      <?php if ($u['active_chat']): ?><a class="badge live" href="?p=chat&id=<?= (int) $u['active_chat']['chat_id'] ?>">● الان در چت</a><?php endif; ?>
      <?php if ($u['searching']): ?><span class="badge amber">🔎 در جست‌وجو</span><?php endif; ?>
      <?php if ($u['status'] === 'suspended'): ?><span class="badge bad">⛔ معلق<?= $u['suspended_until'] ? ' تا ' . Html::date($u['suspended_until']) : ' (دائم)' ?></span><?php endif; ?>
      <?php if ($q['unlimited']): ?><span class="badge violet">💎 نامحدود<?= $q['unlimited_until'] ? ' تا ' . Html::date($q['unlimited_until'], false) : '' ?></span><?php endif; ?>
      <?php if ($u['referral_waived']): ?><span class="badge ok">🔓 بدون دعوت</span><?php endif; ?>
      <?php if ($isAdmin): ?><span class="badge violet">👑 ادمین</span><?php endif; ?>
      <?php if (!$u['profile_completed']): ?><span class="badge">📝 پروفایل ناقص</span><?php endif; ?>
    </div>
  </div>
  <div class="ph-stats">
    <div><b><?= Html::n(count($u['chats'])) ?></b><span>چت</span></div>
    <div><b><?= Html::n($u['messages']) ?></b><span>پیام</span></div>
    <div><b><?= Html::n(count(array_filter($u['invited'], fn ($r) => $r['ref_status'] === 'qualified'))) ?></b><span>دعوت</span></div>
    <div><b><?= Html::n($u['views']) ?></b><span>بازدید پروفایل</span></div>
  </div>
</section>

<section class="grid g-2-1">
  <div>
    <div class="card">
      <header><h3>🪪 مشخصات</h3></header>
      <dl class="info">
        <dt>حساب بله</dt><dd><?= Html::account($u['account'], true) ?> <?= Html::action($csrf, 'fetch_account', ['user_id' => $id], '🔄 دریافت از بله', 'btn sm ghost') ?></dd>
        <?php if (!empty($u['account']['previous_names'])): ?><dt>نام‌های قبلی</dt><dd class="muted"><?= implode(' ← ', array_map(fn ($n) => '<bdi>' . Html::e($n) . '</bdi>', explode(' | ', $u['account']['previous_names']))) ?></dd><?php endif; ?>
        <dt>آیدی عددی بله</dt><dd><code><?= Html::e($u['bale_user_id']) ?></code> <button class="link" data-copy="<?= Html::e($u['bale_user_id']) ?>">کپی</button></dd>
        <dt>اسم نمایشی</dt><dd><?= Html::e($u['display_name'] ?? '—') ?></dd>
        <dt>لقب</dt><dd><?= Html::e($u['nickname'] ?? '—') ?></dd>
        <dt>تاریخ تولد</dt><dd><?= $u['birth_date'] ? Html::dayName($u['birth_date']) . ' <span class="muted">(' . Html::e($u['birth_date']) . ')</span>' : '—' ?></dd>
        <dt>دنبال</dt><dd><?= Html::gender($u['preferred_gender']) ?></dd>
        <dt>درباره</dt><dd><?= $u['bio'] ? nl2br(Html::e($u['bio'])) : '—' ?></dd>
        <dt>علاقه‌مندی‌ها</dt><dd><?= $u['interests'] ? implode(' ', array_map(fn ($i) => Html::badge($i), $u['interests'])) : '—' ?></dd>
        <dt>حریم خصوصی</dt><dd><?= ($u['show_city'] ? '📍✅' : '📍❌') . ' ' . ($u['show_bio'] ? '📝✅' : '📝❌') . ' ' . ($u['show_photo'] ? '🖼✅' : '🖼❌') . ' ' . ($u['notify_profile_views'] ? '👀✅' : '👀❌') ?></dd>
        <dt>عضویت</dt><dd><?= Html::date($u['created_at']) ?> <span class="muted">(<?= Html::ago($u['created_at']) ?>)</span></dd>
        <dt>آخرین بازدید</dt><dd><?= Html::date($u['last_seen_at']) ?> <span class="muted">(<?= Html::ago($u['last_seen_at']) ?>)</span></dd>
        <dt>دعوت‌کننده</dt><dd><?= $u['inviter'] ? Html::who($u['inviter']) : '—' ?></dd>
        <dt>لینک دعوت</dt><dd><code dir="ltr"><?= Html::e($app->deepLink('ref' . $u['referral_code'])) ?></code></dd>
        <dt>لینک ناشناس</dt><dd><code dir="ltr"><?= Html::e($app->deepLink('msg' . $u['inbox_code'])) ?></code></dd>
        <dt>گزارش</dt><dd><?= Html::n(count($u['reports_against'])) ?> علیه او · <?= Html::n($u['reports_by']) ?> از طرف او · مسدود کرده: <?= Html::n($u['blocked']) ?> · مسدود شده: <?= Html::n($u['blocked_by']) ?></dd>
        <dt>صندوق ناشناس</dt><dd><?= Html::n($u['inbox_in']) ?> دریافتی · <?= Html::n($u['inbox_out']) ?> ارسالی</dd>
      </dl>
    </div>

    <div class="card">
      <header><h3>📊 پیام‌های ۱۴ روز اخیر</h3></header>
      <?= Charts::area($activity, array_map(fn ($i) => Html::dayName($app->today($app->now() - $i * 86400)), range(13, 0)), 'پیام', 90) ?>
    </div>

    <div class="card">
      <header><h3>💬 گفت‌وگوها</h3><a class="muted" href="?p=chats&user=<?= $id ?>">همه ←</a></header>
      <?= panel_view('_chat_rows', ['rows' => array_slice($u['chats'], 0, 15)]) ?>
    </div>

    <?php if ($u['reports_against']): ?>
    <div class="card">
      <header><h3>🚩 گزارش‌ها علیه این کاربر</h3></header>
      <ul class="simple">
        <?php foreach ($u['reports_against'] as $r): ?>
          <li><?= Html::badge($reasons[$r['reason']] ?? $r['reason'], 'bad') ?> از <b><?= Html::e($r['reporter']) ?></b> · <?= Html::ago($r['created_at']) ?>
            <?= $r['details'] ? '· «' . Html::e($r['details']) . '»' : '' ?>
            <?= $r['chat_id'] ? '<a href="?p=chat&id=' . (int) $r['chat_id'] . '">دیدن چت</a>' : '' ?> <?= Html::reportStatus($r['status']) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>

    <div class="grid g-1-1">
      <div class="card">
        <header><h3>👥 دعوت‌شده‌ها</h3></header>
        <?php if (!$u['invited']): ?><div class="empty">کسی را دعوت نکرده.</div><?php endif; ?>
        <ul class="simple">
          <?php foreach ($u['invited'] as $r): ?>
            <li><?= Html::who($r) ?> <?= $r['ref_status'] === 'qualified' ? Html::badge('✅ حساب شد' . ($r['reward_credits'] ? ' +' . Text::fa($r['reward_credits']) : ''), 'ok') : Html::badge('⏳ در انتظار') ?> <small class="muted"><?= Html::ago($r['ref_at']) ?></small></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="card">
        <header><h3>🧾 تاریخچه اعتبار</h3></header>
        <?php if (!$u['ledger']): ?><div class="empty">تراکنشی نیست.</div><?php endif; ?>
        <ul class="simple ledger">
          <?php foreach ($u['ledger'] as $l): ?>
            <li><b class="<?= $l['delta'] > 0 ? 'pos' : 'neg' ?>"><?= ($l['delta'] > 0 ? '+' : '−') . Html::n(abs((int) $l['delta'])) ?></b> <?= Html::e($ledgerReasons[$l['reason']] ?? $l['reason']) ?> <small class="muted">· موجودی <?= Html::n($l['balance_after']) ?> · <?= Html::ago($l['created_at']) ?></small></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <aside class="stack">
    <div class="card quota-card">
      <header><h3>🎟 سهمیه امروز</h3></header>
      <?php if ($q['unlimited']): ?>
        <div class="unlimited">💎 نامحدود<?= $q['unlimited_until'] ? '<small>تا ' . Html::date($q['unlimited_until']) . '</small>' : '<small>همیشگی</small>' ?></div>
      <?php else: ?>
        <div class="meter"><span style="width:<?= $q['limit'] ? round(100 * min($q['limit'], $q['limit'] - $q['free_left']) / $q['limit']) : 0 ?>%"></span></div>
        <p><?= Html::n($q['limit'] - $q['free_left']) ?> از <?= Html::n($q['limit']) ?> چت رایگان استفاده شده</p>
      <?php endif; ?>
      <p class="credits">🎁 اعتبار اضافه: <b><?= Html::n($q['credits']) ?></b></p>
    </div>

    <div class="card actions-card">
      <header><h3>🎁 هدیه اعتبار</h3></header>
      <div class="btn-row">
        <?php foreach ([10, 50, 100, 500, 1000] as $n): ?>
          <?= Html::action($csrf, 'credit', ['user_id' => $id, 'amount' => $n, 'notify' => 1], '+' . Text::fa($n), 'btn sm') ?>
        <?php endforeach; ?>
      </div>
      <form method="post" class="row-form"><?= Html::hidden($csrf) ?>
        <input type="hidden" name="action" value="credit"><input type="hidden" name="user_id" value="<?= $id ?>">
        <input type="number" name="amount" min="1" max="100000" placeholder="تعداد دلخواه" required>
        <label class="check"><input type="checkbox" name="notify" value="1" checked> خبرش کن</label>
        <button class="btn primary sm">بده</button>
      </form>
    </div>

    <div class="card actions-card">
      <header><h3>💎 چت نامحدود</h3></header>
      <div class="btn-row">
        <?php foreach ([1 => '۱ روز', 7 => '۷ روز', 30 => '۳۰ روز', 0 => 'همیشه'] as $days => $label): ?>
          <?= Html::action($csrf, 'unlimited', ['user_id' => $id, 'days' => $days, 'notify' => 1], $label, 'btn sm ' . ($days === 0 ? 'violet' : '')) ?>
        <?php endforeach; ?>
        <?php if ($q['unlimited']): ?><?= Html::action($csrf, 'unlimited', ['user_id' => $id, 'days' => -1], 'برداشتن', 'btn sm ghost') ?><?php endif; ?>
      </div>
      <div class="btn-row">
        <?= Html::action($csrf, 'unlock', ['user_id' => $id, 'on' => $u['referral_waived'] ? 0 : 1], $u['referral_waived'] ? '🔒 دوباره دعوت لازم باشد' : '🔓 باز کردن بدون دعوت', 'btn sm ' . ($u['referral_waived'] ? 'ghost' : 'ok')) ?>
        <?= Html::action($csrf, 'reset_today', ['user_id' => $id], '🔄 پر کردن سهمیه امروز', 'btn sm ghost') ?>
      </div>
    </div>

    <div class="card actions-card">
      <header><h3>✉️ پیام به کاربر</h3></header>
      <form method="post"><?= Html::hidden($csrf) ?>
        <input type="hidden" name="action" value="message"><input type="hidden" name="user_id" value="<?= $id ?>">
        <textarea name="text" rows="3" maxlength="3000" placeholder="از طرف ربات برایش فرستاده می‌شود…" required></textarea>
        <button class="btn primary sm">ارسال</button>
      </form>
    </div>

    <div class="card actions-card danger-zone">
      <header><h3>🛡 مدیریت</h3></header>
      <?php if ($u['active_chat']): ?>
        <div class="btn-row"><?= Html::action($csrf, 'end_user_chat', ['user_id' => $id], '🏁 پایان چت فعلی', 'btn sm warn', 'چت فعلی این کاربر تمام شود؟') ?></div>
      <?php endif; ?>
      <?php if ($u['status'] === 'suspended'): ?>
        <div class="btn-row"><?= Html::action($csrf, 'unsuspend', ['user_id' => $id], '✅ آزاد کردن', 'btn sm ok') ?></div>
      <?php elseif (!$isAdmin): ?>
        <div class="btn-row">
          <?php foreach ([1 => '۱ ساعت', 24 => '۲۴ ساعت', 168 => '۷ روز', 0 => 'دائم'] as $h => $label): ?>
            <?= Html::action($csrf, 'suspend', ['user_id' => $id, 'hours' => $h], '⛔ ' . $label, 'btn sm bad', 'کاربر ' . ($u['nickname'] ?? '') . ' ' . $label . ' تعلیق شود؟') ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <form method="post" class="row-form"><?= Html::hidden($csrf) ?>
        <input type="hidden" name="action" value="nickname"><input type="hidden" name="user_id" value="<?= $id ?>">
        <input name="nickname" value="<?= Html::e($u['nickname'] ?? '') ?>" maxlength="20" required>
        <button class="btn sm">تغییر لقب</button>
      </form>
      <div class="btn-row">
        <?php if ($u['photo_file_id']): ?><?= Html::action($csrf, 'clear', ['user_id' => $id, 'field' => 'photo'], '🗑 عکس', 'btn sm ghost', 'عکس پروفایل پاک شود؟') ?><?php endif; ?>
        <?php if ($u['bio']): ?><?= Html::action($csrf, 'clear', ['user_id' => $id, 'field' => 'bio'], '🗑 درباره', 'btn sm ghost', 'متن درباره پاک شود؟') ?><?php endif; ?>
        <?php if ($u['city']): ?><?= Html::action($csrf, 'clear', ['user_id' => $id, 'field' => 'city'], '🗑 شهر', 'btn sm ghost') ?><?php endif; ?>
      </div>
    </div>
  </aside>
</section>
