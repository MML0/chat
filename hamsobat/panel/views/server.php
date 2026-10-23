<?php
use Hamsobat\Charts;
use Hamsobat\Html;
use Hamsobat\Metrics;
use Hamsobat\ServerInfo;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var string $csrf */
$info = new ServerInfo($app);
$sys = $info->system();
$php = $info->php();
$db = $info->database();
$bale = $info->bale();
$logs = $info->logs();
$h1 = Metrics::summary($app, 60);
$h24 = Metrics::summary($app, 1440);
$hours = Metrics::series($app, 24, 60);
$minutes = Metrics::series($app, 60, 1);
$panel24 = Metrics::summary($app, 1440, 'panel');
$tz = new DateTimeZone($app->config['timezone'] ?? 'Asia/Tehran');
$label = fn (int $t, string $f) => Hamsobat\Text::fa((new DateTimeImmutable('@' . $t))->setTimezone($tz)->format($f));
$lastTick = (int) ($app->settings->raw('_tick') ?? 0);
$lastPurge = (int) ($app->settings->raw('_purge') ?? 0);
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/panel/')), '/');
$health = $h1['requests'] ? ($h1['avg_ms'] < 800 && $h1['errors'] === 0 ? ['ok', 'عالی'] : ($h1['avg_ms'] < 2000 ? ['warn', 'قابل قبول'] : ['bad', 'کند'])) : ['wait', 'بدون ترافیک'];
?>
<section class="gauges card">
  <?= Charts::gauge($sys['load_pct'], 'بار پردازنده' . ($sys['cpus'] ? ' (' . Html::n($sys['cpus']) . ' هسته)' : '')) ?>
  <?= Charts::gauge($sys['mem_pct'], 'رم کل سرور') ?>
  <?= $sys['cgroup_limit'] ? Charts::gauge(100 * $sys['cgroup_used'] / $sys['cgroup_limit'], 'رم حساب شما') : '' ?>
  <?= Charts::gauge($sys['disk_pct'], 'دیسک') ?>
  <?= $php['opcache'] ? Charts::gauge($php['opcache_used'], 'حافظه OPcache') : '' ?>
  <div class="health <?= $health[0] ?>"><b><?= $health[1] ?></b><span>سلامت ربات (۱ ساعت)</span></div>
</section>

<section class="kpis">
  <div class="kpi c-violet"><i>📨</i><span>درخواست بله (۱ ساعت)</span><b><?= Html::n($h1['requests']) ?></b><small><?= Html::n($h1['per_min'], 1) ?> در دقیقه · ۲۴ ساعت: <?= Html::n($h24['requests']) ?></small></div>
  <div class="kpi c-cyan"><i>⏱</i><span>میانگین زمان پاسخ</span><b><?= $h1['requests'] ? Html::ms($h1['avg_ms']) : '—' ?></b><small>بیشترین: <?= Html::ms($h1['max_ms']) ?> · ۲۴ ساعت: <?= Html::ms($h24['avg_ms']) ?></small></div>
  <div class="kpi c-blue"><i>🛰</i><span>تأخیر API بله</span><b><?= $h1['bale_calls'] ? Html::ms($h1['bale_avg_ms']) : '—' ?></b><small><?= Html::n($h1['bale_calls']) ?> فراخوانی · <?= Html::n($h1['bale_errors']) ?> خطا · پینگ الان <?= Html::ms($bale['ping_ms']) ?></small></div>
  <div class="kpi <?= $h24['slow'] || $h24['errors'] ? 'c-red' : 'c-green' ?>"><i>🐢</i><span>کند / خطا (۲۴ ساعت)</span><b><?= Html::n($h24['slow']) ?> / <?= Html::n($h24['errors']) ?></b><small>کند = بیش از ۱ ثانیه</small></div>
  <div class="kpi c-amber"><i>🗄</i><span>کوئری در هر درخواست</span><b><?= Html::n($h24['queries_per_req'], 1) ?></b><small>اوج حافظه هر درخواست: <?= Html::bytes($h24['max_memory_kb'] * 1024) ?></small></div>
  <div class="kpi c-slate"><i>🖥</i><span>بازدید پنل (۲۴ ساعت)</span><b><?= Html::n($panel24['requests']) ?></b><small>میانگین <?= Html::ms($panel24['avg_ms']) ?></small></div>
</section>

<section class="grid g-1-1">
  <div class="card">
    <header><h3>📊 درخواست‌ها در ۲۴ ساعت</h3><span class="legend-inline"><i class="c1"></i>درخواست <i class="c2"></i>میانگین ms</span></header>
    <?= Charts::bars(array_map(fn ($p) => ['label' => $label($p['t'], 'H:00'), 'axis' => $label($p['t'], 'H'), 'value' => $p['requests'], 'value2' => round($p['avg_ms'])], $hours), 'درخواست', 'ms', 160, 3) ?>
  </div>
  <div class="card">
    <header><h3>⚡ فشار ۶۰ دقیقه اخیر</h3><span class="muted">درخواست در هر دقیقه</span></header>
    <?= Charts::bars(array_map(fn ($p) => ['label' => $label($p['t'], 'H:i'), 'axis' => $label($p['t'], 'H:i'), 'value' => $p['requests'], 'value2' => round($p['avg_ms'])], $minutes), 'درخواست', 'ms', 160, 10) ?>
  </div>
</section>

<section class="grid g-1-1">
  <div class="card">
    <header><h3>🛰 بله و وب‌هوک</h3><?= $bale['ok'] ? Html::badge('✅ متصل', 'ok') : Html::badge('❌ قطع', 'bad') ?></header>
    <dl class="info">
      <dt>ربات</dt><dd><code dir="ltr">@<?= Html::e($bale['username'] ?? '?') ?></code></dd>
      <dt>پینگ از هاست</dt><dd><?= Html::ms($bale['ping_ms']) ?></dd>
      <dt>آدرس وب‌هوک</dt><dd><code dir="ltr" class="wrap"><?= Html::e($bale['url'] ?: '—') ?></code></dd>
      <dt>آپدیت در صف بله</dt><dd><?= $bale['pending'] === null ? '—' : Html::n($bale['pending']) ?></dd>
      <dt>آخرین خطای وب‌هوک</dt><dd><?= $bale['last_error'] ? Html::e($bale['last_error']) . ' <small class="muted">' . Html::ago($bale['last_error_at']) . '</small>' : '✅ ندارد' ?></dd>
      <?php if ($bale['error']): ?><dt>خطا</dt><dd class="bad-text"><?= Html::e($bale['error']) ?></dd><?php endif; ?>
    </dl>
    <div class="btn-row">
      <?= Html::action($csrf, 'webhook', [], '🔗 وصل کردن ربات به همین پوشه', 'btn sm', 'وب‌هوک ربات روی ' . $base . '/webhook.php تنظیم شود؟') ?>
      <?= Html::action($csrf, 'cleanup', [], '🧹 اجرای پاک‌سازی', 'btn sm ghost') ?>
    </div>
  </div>
  <div class="card">
    <header><h3>🐘 PHP و هاست</h3></header>
    <dl class="info">
      <dt>نسخه PHP</dt><dd><?= Html::e($php['version']) ?> <small class="muted">(<?= Html::e($php['sapi']) ?>, <?= Html::e($php['os']) ?>)</small></dd>
      <dt>وب‌سرور</dt><dd><?= Html::e($php['server'] ?: '—') ?></dd>
      <dt>memory_limit</dt><dd><?= Html::e($php['memory_limit']) ?> · این صفحه: <?= Html::bytes($php['request_memory']) ?></dd>
      <dt>max_execution_time</dt><dd><?= Html::e($php['max_execution']) ?> ثانیه</dd>
      <dt>OPcache</dt><dd><?= $php['opcache'] ? '✅ فعال' . ($php['opcache_hit'] !== null ? ' · نرخ برخورد ' . Html::n($php['opcache_hit'], 1) . '٪' : '') : '❌ غیرفعال (سرعت کمتر)' ?></dd>
      <dt>افزونه‌ها</dt><dd><?= implode(' ', array_map(fn ($e) => Html::badge(($e[1] ? '✔ ' : '✘ ') . $e[0], $e[1] ? 'ok' : 'bad'), $php['extensions'])) ?></dd>
      <dt>رم سرور</dt><dd><?= $sys['mem_total'] ? Html::bytes($sys['mem_total'] - $sys['mem_available']) . ' از ' . Html::bytes($sys['mem_total']) : 'هاست اجازه نمی‌دهد' ?></dd>
      <?php if ($sys['cgroup_limit']): ?><dt>رم حساب</dt><dd><?= Html::bytes($sys['cgroup_used']) . ' از ' . Html::bytes($sys['cgroup_limit']) ?></dd><?php endif; ?>
      <dt>بار CPU</dt><dd><?= $sys['load'] ? implode(' · ', array_map(fn ($l) => Html::n($l, 2), $sys['load'])) . ' <small class="muted">(۱، ۵ و ۱۵ دقیقه)</small>' : 'هاست اجازه نمی‌دهد' ?></dd>
      <dt>دیسک</dt><dd><?= $sys['disk_total'] ? Html::bytes($sys['disk_total'] - $sys['disk_free']) . ' از ' . Html::bytes($sys['disk_total']) : '—' ?></dd>
      <dt>روشن بودن سرور</dt><dd><?= $sys['uptime'] ? Html::duration($sys['uptime']) : '—' ?></dd>
    </dl>
  </div>
</section>

<section class="grid g-1-1">
  <div class="card">
    <header><h3>🗄 دیتابیس</h3><span class="muted"><?= Html::e($db['version']) ?> · <?= Html::bytes($db['size']) ?></span></header>
    <?php if ($db['status']): ?>
      <p class="muted small">اتصال‌ها: <?= Html::n($db['status']['Threads_connected'] ?? 0) ?> از <?= Html::n($db['status']['max_connections'] ?? 0) ?> · بیشترین همزمان: <?= Html::n($db['status']['Max_used_connections'] ?? 0) ?> · کوئری کند: <?= Html::n($db['status']['Slow_queries'] ?? 0) ?> · روشن: <?= Html::duration($db['status']['Uptime'] ?? 0) ?></p>
    <?php endif; ?>
    <?= Charts::hbars(array_map(fn ($t) => ['label' => $t['name'] . ' · ' . ($t['estimate'] ? '≈' : '') . Html::n($t['rows']) . ' ردیف', 'value' => round($t['bytes'] / 1024)], $db['tables']), 'KB') ?>
  </div>
  <div class="card">
    <header><h3>🧹 نگهداری</h3></header>
    <dl class="info">
      <dt>آخرین کار دوره‌ای</dt><dd><?= $lastTick ? Html::ago(gmdate('Y-m-d H:i:s', $lastTick)) : 'هنوز نه' ?></dd>
      <dt>آخرین پاک‌سازی</dt><dd><?= $lastPurge ? Html::ago(gmdate('Y-m-d H:i:s', $lastPurge)) : 'هنوز نه' ?></dd>
      <dt>فایل‌های لاگ</dt><dd><?= Html::n($logs['count']) ?> فایل · <?= Html::bytes($logs['bytes']) ?></dd>
      <dt>کرون (اختیاری)</dt><dd><code dir="ltr" class="wrap"><?= Html::e($base) ?>/cron/cleanup.php?k=…</code><br><small class="muted">هر دقیقه با یک سرویس کرون؛ k = cron_secret در config.php</small></dd>
    </dl>
  </div>
</section>
