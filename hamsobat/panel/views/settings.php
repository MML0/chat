<?php
use Hamsobat\Html;
use Hamsobat\Settings;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var string $csrf */
$s = $app->settings;
$groups = [
    ['🔐 دسترسی و دعوت', 'چه چیزی برای شروع چت لازم است.', ['require_channel', 'required_channel', 'channel_link', 'channel_check_fail_open', 'required_referrals', 'referral_reward_credits', 'referral_requires_channel', 'referral_daily_reward_cap', 'referral_attach_minutes']],
    ['🎟 سهمیه و جست‌وجو', 'چت رایگان روزانه فقط وقتی کم می‌شود که دو نفر واقعاً وصل شوند.', ['daily_free_chats', 'search_timeout_seconds', 'rematch_cooldown_minutes', 'chat_idle_minutes']],
    ['👤 کاربران', 'سن زیر ۱۸ و بالای ۱۸ هیچ‌وقت به هم وصل نمی‌شوند.', ['min_age', 'max_age', 'profile_view_notify_minutes']],
    ['💬 پیام‌ها و رسانه', 'رسانه‌ها دانلود نمی‌شوند؛ ربات همان فایل بله را دوباره می‌فرستد.', ['allowed_types', 'block_links', 'store_message_text', 'message_retention_days']],
    ['🛡 ضد اسپم و گزارش', 'محدودیت‌ها برای هر کاربر جداگانه حساب می‌شوند.', ['msg_rate_per_minute', 'search_rate_per_minute', 'inbox_rate_per_hour', 'report_rate_per_day', 'auto_suspend_reports', 'notify_admin_reports']],
    ['✨ امکانات', 'خاموش کردن هر بخش فوراً اعمال می‌شود.', ['feature_inbox', 'feature_media', 'feature_profile_view_notify', 'maintenance']],
];
$units = [
    'referral_reward_credits' => 'چت', 'referral_daily_reward_cap' => 'دعوت', 'referral_attach_minutes' => 'دقیقه', 'required_referrals' => 'دعوت',
    'daily_free_chats' => 'چت در روز', 'search_timeout_seconds' => 'ثانیه', 'rematch_cooldown_minutes' => 'دقیقه', 'chat_idle_minutes' => 'دقیقه',
    'min_age' => 'سال', 'max_age' => 'سال', 'profile_view_notify_minutes' => 'دقیقه', 'message_retention_days' => 'روز',
    'msg_rate_per_minute' => 'در دقیقه', 'search_rate_per_minute' => 'در دقیقه', 'inbox_rate_per_hour' => 'در ساعت', 'report_rate_per_day' => 'در روز', 'auto_suspend_reports' => 'نفر',
];
$types = ['photo' => '🖼 عکس', 'voice' => '🎤 ویس', 'audio' => '🎵 آهنگ', 'video' => '🎬 ویدیو', 'animation' => '🎞 گیف', 'sticker' => '🙂 استیکر', 'video_note' => '⏺ ویدیو گرد', 'document' => '📎 فایل'];
$allowed = array_map('trim', explode(',', $s->str('allowed_types')));
?>
<form method="post" class="settings-form" data-settings>
  <?= Html::hidden($csrf) ?><input type="hidden" name="action" value="settings">
  <div class="settings-grid">
  <?php foreach ($groups as [$title, $hint, $keys]): ?>
    <section class="card">
      <header><h3><?= $title ?></h3></header>
      <p class="muted small"><?= Html::e($hint) ?></p>
      <?php foreach ($keys as $key):
          [$default, $type, $help] = Settings::DEFAULTS[$key];
          $value = $s->get($key);
          $changed = (string) $value !== (string) $default; ?>
        <div class="setting <?= $changed ? 'changed' : '' ?>">
          <div class="s-text">
            <label for="s-<?= $key ?>"><?= Html::e($help) ?></label>
            <small><code><?= $key ?></code><?= $changed && $key !== 'allowed_types' ? ' · پیش‌فرض: ' . Html::e($type === 'bool' ? ($default ? 'روشن' : 'خاموش') : ($default === '' ? 'خالی' : $default)) : '' ?></small>
          </div>
          <div class="s-input">
            <?php if ($type === 'bool'): ?>
              <label class="switch"><input id="s-<?= $key ?>" type="checkbox" name="s[<?= $key ?>]" value="1" <?= $value ? 'checked' : '' ?>><span></span></label>
            <?php elseif ($key === 'allowed_types'): ?>
              <input type="hidden" name="s[allowed_types][]" value="text">
              <div class="chips">
                <?php foreach ($types as $t => $l): ?>
                  <label class="chip"><input type="checkbox" name="s[allowed_types][]" value="<?= $t ?>" <?= in_array($t, $allowed, true) ? 'checked' : '' ?>><span><?= $l ?></span></label>
                <?php endforeach; ?>
              </div>
            <?php elseif ($type === 'int'): ?>
              <div class="num"><input id="s-<?= $key ?>" type="number" min="0" name="s[<?= $key ?>]" value="<?= (int) $value ?>"><?php if (isset($units[$key])): ?><span><?= $units[$key] ?></span><?php endif; ?></div>
            <?php else: ?>
              <input id="s-<?= $key ?>" type="text" dir="ltr" name="s[<?= $key ?>]" value="<?= Html::e($value) ?>" placeholder="<?= $key === 'required_channel' ? '@channel' : 'https://ble.ir/…' ?>">
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <div class="save-bar" data-save-bar>
    <span>✏️ تغییرات ذخیره نشده دارید</span>
    <button class="btn primary">💾 ذخیره همه</button>
    <button type="reset" class="btn ghost">لغو</button>
  </div>
</form>

<?php
$req = $app->requirements();
$diag = json_decode((string) ($s->raw('_channel_diag') ?? ''), true) ?: null;
$known = $req->knownChannels();
?>
<section class="card channel-card">
  <header><h3>📢 کانال اجباری: عیب‌یابی</h3><?= Html::action($csrf, 'channel_test', [], '🧪 تست الان', 'btn sm') ?></header>
  <dl class="info">
    <dt>تنظیم فعلی</dt><dd><code dir="ltr"><?= Html::e($s->str('required_channel') ?: '—') ?></code> <?= $s->bool('require_channel') ? '' : Html::badge('خاموش') ?></dd>
    <dt>آیدی که از بله پرسیده می‌شود</dt><dd><code dir="ltr"><?= Html::e($req->channelTarget() ?: '—') ?></code></dd>
    <dt>آخرین تست</dt><dd><?= $diag ? nl2br(Html::e($diag['message'])) . ' <small class="muted">(' . Html::ago(gmdate('Y-m-d H:i:s', (int) $diag['at'])) . ')</small>' : 'هنوز تست نشده' ?></dd>
  </dl>
  <?php if ($known): ?>
    <p class="muted small">کانال‌هایی که ربات دیده (اضافه شدن ربات یا پست فورواردشده):</p>
    <ul class="simple">
      <?php foreach ($known as $c): ?>
        <li><b><?= Html::e($c['title'] ?? $c['id']) ?></b> <code dir="ltr"><?= Html::e($c['id']) ?></code> <?= $c['username'] ? '<code dir="ltr">@' . Html::e($c['username']) . '</code>' : '' ?>
          <?= $c['bot_status'] ? Html::badge(in_array($c['bot_status'], ['administrator', 'creator'], true) ? '✅ ربات مدیر است' : '⚠️ ربات مدیر نیست', in_array($c['bot_status'], ['administrator', 'creator'], true) ? 'ok' : 'amber') : '' ?>
          <?= Html::action($csrf, 'set_channel', ['channel_id' => $c['id']], 'همین کانال اجباری شود', 'btn sm') ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p class="muted small">اگر خطای «no such group or user» می‌بینید: ۱) ربات را در کانال «مدیر» کنید؛ ۲) آیدی کانال باید آیدی عمومی خود کانال باشد (کانال خصوصی آیدی @ ندارد)؛ ۳) یک پست از کانال را برای ربات فوروارد کنید تا آیدی عددی‌اش را پیدا کند و با یک دکمه تنظیم کنید.</p>
</section>

<section class="card">
  <header><h3>🧩 از config.php (فقط خواندنی)</h3></header>
  <dl class="info">
    <dt>ربات</dt><dd><code dir="ltr">@<?= Html::e($app->botUsername()) ?></code></dd>
    <dt>ادمین‌ها (آیدی بله)</dt><dd><code dir="ltr"><?= Html::e(implode(', ', (array) ($app->config['admin']['ids'] ?? []))) ?></code></dd>
    <dt>منطقه زمانی</dt><dd><?= Html::e($app->config['timezone'] ?? 'Asia/Tehran') ?></dd>
    <dt>پیشوند جدول‌ها</dt><dd><code dir="ltr"><?= Html::e($app->db->prefix) ?></code></dd>
    <dt>ورود دو مرحله‌ای پنل</dt><dd><?= ($app->config['panel']['two_factor'] ?? true) ? '✅ روشن (کد از طریق بله)' : '❌ خاموش' ?></dd>
  </dl>
  <p class="muted small">رمزها و توکن‌ها فقط در config.php هستند و این‌جا نمایش داده نمی‌شوند.</p>
</section>
