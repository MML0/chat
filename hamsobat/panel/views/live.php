<?php
use Hamsobat\Html;
use Hamsobat\Metrics;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var Hamsobat\PanelData $data */
$perf = Metrics::summary($app, 5);
[$active] = $data->chats(['status' => 'active'], 1, 12);
?>
<section class="grid g-2-1">
  <div class="card live-card">
    <header>
      <h3>⚡ پیام‌ها همین حالا</h3>
      <div class="live-tools">
        <label class="switch-label"><input type="checkbox" data-feed-media checked> نمایش رسانه</label>
        <button class="btn sm" data-feed-pause>⏸ توقف</button>
      </div>
    </header>
    <p class="muted small">هر پیامی که بین دو هم‌صحبت جابه‌جا می‌شود، چند ثانیه بعد این‌جا ظاهر می‌شود. روی لقب‌ها بزنید تا پروفایل باز شود، روی پیام بزنید تا کل گفت‌وگو را ببینید.</p>
    <ol class="feed" data-feed data-after="<?= max(0, (int) $app->db->value('SELECT MAX(id) FROM {messages}') - 30) ?>">
      <li class="feed-empty" data-feed-empty>⏳ منتظر پیام‌های جدید…</li>
    </ol>
  </div>
  <div>
    <div class="card">
      <header><h3>📡 وضعیت لحظه‌ای</h3></header>
      <ul class="stat-list">
        <li><span>🟢 آنلاین (۵ دقیقه)</span><b data-live="online">—</b></li>
        <li><span>🔎 در جست‌وجو</span><b data-live="searching">—</b></li>
        <li><span>💬 چت فعال</span><b data-live="chatting">—</b></li>
        <li><span>📨 درخواست بله (۵ دقیقه)</span><b data-live="req_min">—</b></li>
        <li><span>⏱ میانگین پاسخ</span><b data-live="avg_ms"><?= $perf['requests'] ? Html::ms($perf['avg_ms']) : '—' ?></b></li>
        <li><span>🛰 آخرین پیام بله</span><b data-live="last_hook_ago">—</b></li>
      </ul>
    </div>
    <div class="card">
      <header><h3>🟣 چت‌های فعال</h3><a class="muted" href="?p=chats&status=active">همه ←</a></header>
      <?php if (!$active): ?><div class="empty">الان کسی در حال چت نیست.</div><?php endif; ?>
      <ul class="mini-chats">
        <?php foreach ($active as $c): ?>
          <li><a href="?p=chat&id=<?= (int) $c['id'] ?>"><span><b><?= Html::e($c['a_nick']) ?></b> ⇄ <b><?= Html::e($c['b_nick']) ?></b></span>
            <small><?= Html::n($c['message_count']) ?> پیام · از <?= Html::ago($c['started_at']) ?></small></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</section>
