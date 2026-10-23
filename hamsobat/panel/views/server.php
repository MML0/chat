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
$tz = new DateTimeZone($app->config['timezone'] ?? 'Asia/Tehran');
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

<?php
// ------------------------------------------------------------------ performance (one range for everything below)
$ranges = [
    '1h'  => ['label' => '۱ ساعت', 'buckets' => 60, 'size' => 1, 'unit' => 'دقیقه', 'per' => 'در هر دقیقه'],
    '24h' => ['label' => '۲۴ ساعت', 'buckets' => 24, 'size' => 60, 'unit' => 'ساعت', 'per' => 'در هر ساعت'],
    '7d'  => ['label' => '۷ روز', 'buckets' => 42, 'size' => 240, 'unit' => '۴ ساعت', 'per' => 'در هر ۴ ساعت'],
];
$rk = isset($ranges[$_GET['range'] ?? '']) ? $_GET['range'] : '24h';
$r = $ranges[$rk];
$sum = Metrics::summary($app, $r['buckets'] * $r['size']);
$panelSum = Metrics::summary($app, $r['buckets'] * $r['size'], 'panel');
$series = Metrics::series($app, $r['buckets'], $r['size']);
$pk = Metrics::peaks($series);
$weekdays = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'];
$local = fn (int $t) => (new DateTimeImmutable('@' . $t))->setTimezone($tz);
$when = function (int $i) use ($series, $r, $local, $weekdays): string {
    $from = $local($series[$i]['t']);
    $to = $local($series[$i]['t'] + 60 * $r['size']);
    return Hamsobat\Text::fa(match ($r['size']) {
        1       => 'ساعت ' . $from->format('H:i'),
        60      => $from->format('H:i') . ' تا ' . $to->format('H:i'),
        default => $weekdays[(int) $from->format('w')] . ' ' . $from->format('H:i') . ' تا ' . $to->format('H:i'),
    });
};
$axis = [];
foreach ($series as $i => $p) {
    $t = $local($p['t']);
    if ($r['size'] === 1 && (int) $t->format('i') % 10 === 0) {
        $axis[$i] = Hamsobat\Text::fa($t->format('H:i'));
    } elseif ($r['size'] === 60 && (int) $t->format('G') % 4 === 0) {
        $axis[$i] = Hamsobat\Text::fa($t->format('H:i'));
    } elseif ($r['size'] === 240 && (int) $t->format('G') >= 10 && (int) $t->format('G') < 14) {
        $axis[$i] = $weekdays[(int) $t->format('w')];
    }
}
$has = fn (array $p) => $p['requests'] > 0;
$tips = array_map(fn ($i) => ['t' => $when($i), 'r' => $series[$i]['requests'] ? array_values(array_filter([
    in_array($i, $pk['spikes'], true) ? ['اوج ترافیک', '⚠', 'warn'] : null,
    in_array($i, $pk['slow'], true) ? ['کند', '⚠', 'warn'] : null,
    ['درخواست', Html::n($series[$i]['requests']), 's1'],
    ['میانگین پاسخ', Html::ms($series[$i]['avg_ms']), 'l1'],
    $series[$i]['bale_avg_ms'] ? ['API بله', Html::ms($series[$i]['bale_avg_ms']), 'l2'] : null,
    ['کندترین درخواست', Html::ms($series[$i]['max_ms']), ''],
    $series[$i]['errors'] ? ['خطا', Html::n($series[$i]['errors']), 'bad'] : null,
])) : [['بدون درخواست', '—', '']]], array_keys($series));
$table = ['head' => ['زمان', 'درخواست', 'میانگین پاسخ', 'API بله', 'کندترین', 'خطا'], 'rows' => []];
foreach (array_reverse(array_keys(array_filter($series, $has)), false) as $i) {
    $p = $series[$i];
    $table['rows'][] = [$when($i), Html::n($p['requests']), Html::ms($p['avg_ms']), $p['bale_avg_ms'] ? Html::ms($p['bale_avg_ms']) : '—', Html::ms($p['max_ms']), Html::n($p['errors'])];
}
// Flagged buckets as time ranges: back-to-back ones merge ("ساعت ۲۱:۳۳ تا ۲۱:۳۹").
$times = function (array $idx) use ($series, $r, $local, $weekdays, $when): string {
    $runs = [];
    foreach ($idx as $i) {
        if ($runs && $runs[count($runs) - 1][1] === $i - 1) {
            $runs[count($runs) - 1][1] = $i;
        } else {
            $runs[] = [$i, $i];
        }
    }
    $at = fn (int $t) => ($r['size'] === 240 ? $weekdays[(int) $local($t)->format('w')] . ' ' : '') . $local($t)->format('H:i');
    $parts = array_map(fn ($run) => $run[0] === $run[1] ? $when($run[0])
        : Hamsobat\Text::fa(($r['size'] === 1 ? 'ساعت ' : '') . $at($series[$run[0]]['t']) . ' تا ' . $at($series[$run[1]]['t'] + 60 * $r['size'])), $runs);
    return implode('، ', array_slice($parts, 0, 3)) . (count($parts) > 3 ? ' و ' . Html::n(count($parts) - 3) . ' بازه دیگر' : '');
};
$peakAt = $pk['peak'];
$peakSlow = $peakAt !== null && $series[$peakAt]['avg_ms'] >= Metrics::SLOW_MS;
?>
<nav class="range-bar" aria-label="بازه زمانی">
  <span class="muted">بازه:</span>
  <span class="seg"><?php foreach ($ranges as $key => $opt): ?><a href="?p=server&amp;range=<?= $key ?>" class="<?= $key === $rk ? 'on' : '' ?>"><?= $opt['label'] ?></a><?php endforeach; ?></span>
  <small class="muted">همه اعداد و نمودارهای زیر برای همین بازه‌اند.</small>
</nav>

<section class="kpis">
  <div class="kpi c-violet"><i>📨</i><span>درخواست بله</span><b><?= Html::n($sum['requests']) ?></b><small>میانگین <?= Html::n($pk['mean'], $pk['mean'] < 10 ? 1 : 0) ?> <?= $r['per'] ?></small></div>
  <div class="kpi c-cyan"><i>⏱</i><span>میانگین زمان پاسخ</span><b><?= $sum['requests'] ? Html::ms($sum['avg_ms']) : '—' ?></b><small>کندترین درخواست: <?= Html::ms($sum['max_ms']) ?></small></div>
  <div class="kpi c-blue"><i>🛰</i><span>تأخیر API بله</span><b><?= $sum['bale_calls'] ? Html::ms($sum['bale_avg_ms']) : '—' ?></b><small><?= Html::n($sum['bale_calls']) ?> فراخوانی · <?= Html::n($sum['bale_errors']) ?> خطا · پینگ الان <?= Html::ms($bale['ping_ms']) ?></small></div>
  <div class="kpi <?= $sum['slow'] || $sum['errors'] ? 'c-red' : 'c-green' ?>"><i>🐢</i><span>کند / خطا</span><b><?= Html::n($sum['slow']) ?> / <?= Html::n($sum['errors']) ?></b><small>کند = بیش از ۱ ثانیه</small></div>
  <div class="kpi c-amber"><i>🗄</i><span>کوئری در هر درخواست</span><b><?= Html::n($sum['queries_per_req'], 1) ?></b><small>اوج حافظه هر درخواست: <?= Html::bytes($sum['max_memory_kb'] * 1024) ?></small></div>
  <div class="kpi c-slate"><i>🖥</i><span>بازدید پنل</span><b><?= Html::n($panelSum['requests']) ?></b><small>میانگین <?= Html::ms($panelSum['avg_ms']) ?></small></div>
</section>

<section class="card peaks">
  <header><h3>🔎 اوج داشته؟</h3><span class="muted small">اوج = بیش از <?= Html::n(Metrics::SPIKE_RATIO) ?> برابر میانگین (و حداقل <?= Html::n(Metrics::SPIKE_MIN) ?> درخواست) · کند = میانگین پاسخ ۱ ثانیه یا بیشتر</span></header>
  <?php if (!$pk['total']): ?>
    <p class="muted">در این بازه درخواستی ثبت نشده.</p>
  <?php else: ?>
  <div class="verdicts">
    <div class="verdict <?= $pk['spikes'] ? 'warn' : 'ok' ?>"><i><?= $pk['spikes'] ? '📈' : '✅' ?></i><div>
      <b><?= $pk['spikes'] ? 'اوج ترافیک داشته' : 'ترافیک یکنواخت، بدون اوج' ?></b>
      <span>بیشترین: <?= Html::n($series[$peakAt]['requests']) ?> درخواست · <?= Html::e($when($peakAt)) ?><?= $pk['mean'] > 0 ? ' (' . Html::n($series[$peakAt]['requests'] / $pk['mean'], 1) . ' برابر میانگین)' : '' ?></span>
      <?php if ($pk['spikes']): ?><span>زمان اوج: <?= Html::e($times($pk['spikes'])) ?></span><?php endif; ?>
    </div></div>
    <div class="verdict <?= $peakSlow ? 'bad' : 'ok' ?>"><i><?= $peakSlow ? '⚠️' : '✅' ?></i><div>
      <b><?= $peakSlow ? 'در اوج کند شده' : 'در اوج هم سریع مانده' ?></b>
      <span>پاسخ در شلوغ‌ترین بازه: <?= Html::ms($series[$peakAt]['avg_ms']) ?> · میانگین کل: <?= Html::ms($pk['avg_ms']) ?></span>
    </div></div>
    <div class="verdict <?= $pk['slow'] ? 'warn' : 'ok' ?>"><i><?= $pk['slow'] ? '🐢' : '✅' ?></i><div>
      <b><?= $pk['slow'] ? Html::n(count($pk['slow'])) . ' بازه کند' : 'هیچ بازه‌ای کند نبوده' ?></b>
      <span><?= $pk['slow'] ? Html::e($times($pk['slow'])) : 'میانگین پاسخ همه بازه‌ها زیر ۱ ثانیه' ?></span>
    </div></div>
    <div class="verdict <?= $pk['max_ms'] >= Metrics::SLOW_MS ? 'warn' : 'ok' ?>"><i>🔺</i><div>
      <b>کندترین درخواست: <?= Html::ms($pk['max_ms']) ?></b>
      <span><?= $pk['max_at'] !== null ? Html::e($when($pk['max_at'])) : '' ?><?= $sum['slow'] ? ' · ' . Html::n($sum['slow']) . ' درخواست بالای ۱ ثانیه' : '' ?></span>
    </div></div>
  </div>
  <?php endif; ?>
</section>

<section class="grid g-1-1">
  <div class="card">
    <header><h3>📨 تعداد درخواست</h3><span class="muted small"><?= $r['per'] ?></span></header>
    <?= Charts::time([
        'type' => 'bars', 'unitLabel' => 'درخواست', 'axis' => $axis, 'tips' => $tips, 'table' => $table,
        'series' => [['name' => 'درخواست', 'key' => 's1', 'values' => array_column($series, 'requests')]],
        'avg' => $pk['mean'], 'avgLabel' => 'میانگین: ' . Html::n($pk['mean'], $pk['mean'] < 10 ? 1 : 0),
        'peakLabel' => $peakAt !== null ? 'اوج: ' . Html::n($series[$peakAt]['requests']) : null,
        'flags' => $pk['spikes'],
    ]) ?>
    <?php if ($pk['spikes']): ?><p class="muted small tc-note"><i class="tc-key flag"></i> ⚠ ستون نارنجی = اوج (بیش از <?= Html::n(Metrics::SPIKE_RATIO) ?> برابر میانگین)</p><?php endif; ?>
  </div>
  <div class="card">
    <header><h3>⏱ زمان پاسخ</h3><span class="muted small">میانگین هر <?= $r['unit'] ?></span></header>
    <?= Charts::time([
        'type' => 'lines', 'unit' => 'ms', 'axis' => $axis, 'tips' => $tips,
        'series' => [
            ['name' => 'پاسخ ربات به هر آپدیت', 'key' => 's1', 'values' => array_map(fn ($p) => $p['requests'] ? round($p['avg_ms']) : null, $series)],
            ['name' => 'هر فراخوانی API بله', 'key' => 's2', 'values' => array_map(fn ($p) => $p['requests'] && $p['bale_avg_ms'] ? round($p['bale_avg_ms']) : null, $series)],
        ],
        'avg' => $pk['avg_ms'], 'avgLabel' => 'میانگین: ' . Html::ms($pk['avg_ms']),
        'threshold' => [Metrics::SLOW_MS, 'مرز کندی: ۱ ثانیه'],
        'peakLabel' => ($slowest = array_reduce(array_keys(array_filter($series, $has)), fn ($b, $i) => $b === null || $series[$i]['avg_ms'] > $series[$b]['avg_ms'] ? $i : $b)) !== null ? 'اوج: ' . Html::ms($series[$slowest]['avg_ms']) : null,
        'flags' => $pk['slow'],
    ]) ?>
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
