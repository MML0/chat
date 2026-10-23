<?php
use Hamsobat\Charts;
use Hamsobat\Html;
use Hamsobat\Metrics;
use Hamsobat\PanelData;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data @var string $csrf */
$k = $data->kpis();
$daily = $data->daily(30);
$demo = $data->demographics();
$top = $data->topUsers(30, 8);
[$recent] = $data->chats([], 1, 7);
$perf = Metrics::summary($app, 60);
$spark = fn (string $key) => Charts::spark(array_column(array_slice($daily, -14), $key));
$delta = function (int $today, int $yesterday): string {
    if ($yesterday === 0 && $today === 0) {
        return '';
    }
    $up = $today >= $yesterday;
    return '<span class="delta ' . ($up ? 'up' : 'down') . '">' . ($up ? '▲' : '▼') . ' ' . Html::n(abs($today - $yesterday)) . ' نسبت به دیروز</span>';
};
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone($app->config['timezone'] ?? 'Asia/Tehran')))->format('G');
$greet = $hour < 5 ? 'شب بخیر' : ($hour < 12 ? 'صبح بخیر' : ($hour < 17 ? 'روز بخیر' : 'عصر بخیر'));
?>
<section class="hello">
  <div>
    <h2><?= $greet ?> 👋</h2>
    <p class="muted">امروز <?= Html::date($app->ts(), false) ?> · <?= Html::n($k['active_today']) ?> نفر امروز سر زدند و <?= Html::n($k['chats_today']) ?> گفت‌وگو شکل گرفت.</p>
  </div>
  <div class="hello-actions">
    <a class="btn" href="?p=live">⚡ پیام‌های زنده</a>
    <?php if ($k['open_reports']): ?><a class="btn warn" href="?p=reports">🚩 <?= Html::n($k['open_reports']) ?> گزارش باز</a><?php endif; ?>
  </div>
</section>

<section class="kpis">
  <div class="kpi c-violet">
    <i>👥</i><span>کاربران</span><b><?= Html::n($k['users']) ?></b>
    <small>+<?= Html::n($k['new_today']) ?> امروز · <?= Html::n($k['completed']) ?> پروفایل کامل</small><?= $spark('users') ?>
  </div>
  <div class="kpi c-green">
    <i>🟢</i><span>آنلاین الان</span><b data-live="online"><?= Html::n($k['online']) ?></b>
    <small><?= Html::n($k['searching']) ?> در جست‌وجو · <?= Html::n($k['chatting']) ?> چت فعال</small>
  </div>
  <div class="kpi c-cyan">
    <i>🤝</i><span>چت‌های امروز</span><b><?= Html::n($k['chats_today']) ?></b>
    <small><?= $delta($k['chats_today'], $k['chats_yesterday']) ?></small><?= $spark('chats') ?>
  </div>
  <div class="kpi c-pink">
    <i>✉️</i><span>پیام‌های امروز</span><b><?= Html::n($k['messages_today']) ?></b>
    <small><?= Html::n($k['filtered_today']) ?> فیلتر شد · میانگین <?= Html::n($k['avg_chat_msgs'], 1) ?> پیام در هر چت</small><?= $spark('messages') ?>
  </div>
  <div class="kpi c-amber">
    <i>🎁</i><span>دعوت موفق امروز</span><b><?= Html::n($k['referrals_today']) ?></b>
    <small>کاربر جدید امروز: <?= Html::n($k['new_today']) ?> <?= $delta($k['new_today'], $k['new_yesterday']) ?></small><?= $spark('referrals') ?>
  </div>
  <div class="kpi c-blue">
    <i>⏱</i><span>میانگین طول چت</span><b><?= $k['avg_chat_sec'] ? Html::duration($k['avg_chat_sec']) : '—' ?></b>
    <small>۷ روز اخیر</small>
  </div>
  <div class="kpi c-teal">
    <i>📩</i><span>پیام ناشناس امروز</span><b><?= Html::n($k['inbox_today']) ?></b>
    <small><a href="?p=inbox">صندوق پیام‌ها ←</a></small>
  </div>
  <div class="kpi <?= $perf['avg_ms'] > 1500 ? 'c-red' : 'c-slate' ?>">
    <i>⚙️</i><span>پاسخ ربات (۱ ساعت)</span><b><?= $perf['requests'] ? Html::ms($perf['avg_ms']) : '—' ?></b>
    <small><?= Html::n($perf['requests']) ?> درخواست · بله <?= Html::ms($perf['bale_avg_ms']) ?> · <a href="?p=server">سرور ←</a></small>
  </div>
</section>

<section class="grid g-2-1">
  <div class="card">
    <header><h3>📈 روند ۳۰ روز</h3><span class="legend-inline"><i class="c1"></i>کاربر جدید <i class="c2"></i>چت</span></header>
    <?= Charts::bars(array_map(fn ($d) => ['label' => Html::dayName($d['date']), 'axis' => Html::n((int) substr($d['date'], 8)), 'value' => $d['users'], 'value2' => $d['chats']], $daily), 'کاربر', 'چت', 170, 3) ?>
  </div>
  <div class="card">
    <header><h3>⚧ جنسیت</h3></header>
    <?= Charts::donut([
        ['label' => 'پسر', 'value' => $demo['genders']['m'], 'class' => 'c-boy'],
        ['label' => 'دختر', 'value' => $demo['genders']['f'], 'class' => 'c-girl'],
    ], Html::n(array_sum($demo['genders']))) ?>
  </div>
</section>

<section class="grid g-2-1">
  <div class="card">
    <header><h3>🔥 ساعت‌های شلوغ</h3><span class="muted">پیام‌ها، ۲۸ روز اخیر</span></header>
    <?= Charts::heatmap($data->heatmap(28)) ?>
  </div>
  <div class="card">
    <header><h3>🎂 سن کاربران</h3></header>
    <?= Charts::hbars(array_map(fn ($k, $v) => ['label' => $k, 'value' => $v], array_keys($demo['ages']), $demo['ages']), 'نفر') ?>
  </div>
</section>

<section class="grid g-2-1">
  <div class="card">
    <header><h3>🏆 فعال‌ترین کاربران</h3><span class="muted">۳۰ روز اخیر · با یک کلیک هدیه بده</span></header>
    <?php if (!$top): ?>
      <div class="empty">هنوز چتی انجام نشده.</div>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>کاربر</th><th>چت</th><th>پیام</th><th>دعوت</th><th>هدیه</th></tr></thead>
      <tbody>
      <?php foreach ($top as $i => $u): ?>
        <tr>
          <td><span class="rank r<?= $i + 1 ?>"><?= Html::n($i + 1) ?></span><?= Html::who($u) ?></td>
          <td><b><?= Html::n($u['chats']) ?></b></td>
          <td><?= Html::n($u['messages']) ?></td>
          <td><?= Html::n($u['invites']) ?></td>
          <td class="actions">
            <?= Html::action($csrf, 'credit', ['user_id' => $u['id'], 'amount' => 50, 'notify' => 1], '🎁 +۵۰', 'btn sm') ?>
            <?= Html::action($csrf, 'unlimited', ['user_id' => $u['id'], 'days' => 7, 'notify' => 1], '💎 ۷ روز نامحدود', 'btn sm ghost') ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <header><h3>📍 شهرها</h3></header>
    <?= Charts::hbars($demo['cities'], 'نفر') ?>
    <header class="sub"><h3>🎯 علاقه‌مندی‌ها</h3></header>
    <?= Charts::hbars($demo['interests'], 'نفر') ?>
  </div>
</section>

<section class="card">
  <header><h3>💬 آخرین گفت‌وگوها</h3><a href="?p=chats" class="muted">همه ←</a></header>
  <?= panel_view('_chat_rows', ['rows' => $recent]) ?>
</section>
