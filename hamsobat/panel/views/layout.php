<?php
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var array $pages @var string $page @var array $title @var string $content @var ?array $flash @var array $live @var string $csrf */
$hookAge = $live['last_hook'] ? time() - (int) Html::time($live['last_hook']) : null;
$botState = $hookAge === null ? ['wait', 'هنوز پیامی از بله نرسیده'] : ($hookAge < 900 ? ['ok', 'ربات فعال · ' . Html::ago($live['last_hook'])] : ['idle', 'آخرین پیام بله · ' . Html::ago($live['last_hook'])]);
$openReports = (int) $app->db->value("SELECT COUNT(*) FROM {reports} WHERE status = 'open'");
?><!doctype html>
<html lang="fa" dir="rtl" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Html::e($title[0]) ?> · پنل هم‌صحبت</title>
<link rel="stylesheet" href="assets/panel.css?v=13">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💬</text></svg>">
<script src="assets/panel.js?v=13" defer></script>
</head>
<body data-page="<?= Html::e($page) ?>">
<div class="shell">
  <aside class="side" id="side">
    <a class="brand" href="./">
      <span class="logo">💬</span>
      <span><b>هم‌صحبت</b><small>پنل مدیریت</small></span>
    </a>
    <nav>
      <?php foreach ($pages as $key => [$label, $icon]): ?>
        <a href="?p=<?= $key ?>" class="<?= $page === $key || ($page === 'user' && $key === 'users') || ($page === 'chat' && $key === 'chats') ? 'on' : '' ?>">
          <i><?= $icon ?></i><span><?= Html::e($label) ?></span>
          <?php if ($key === 'reports' && $openReports): ?><em class="count"><?= Html::n($openReports) ?></em><?php endif; ?>
          <?php if ($key === 'live'): ?><em class="pulse" aria-hidden="true"></em><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <span class="bot-state <?= $botState[0] ?>"><i></i><?= Html::e($botState[1]) ?></span>
      <small class="muted">هم‌صحبت نسخه <?= Hamsobat\Text::fa(HAMSOBAT_VERSION) ?></small>
      <form method="post"><?= Html::hidden($csrf) ?><input type="hidden" name="action" value="logout"><button class="link">🚪 خروج</button></form>
    </div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="icon-btn only-mobile" data-toggle-nav aria-label="منو">☰</button>
      <h1><span><?= $title[1] ?></span> <?= Html::e($title[0]) ?></h1>
      <div class="live-strip" data-live-strip>
        <span title="آنلاین در ۵ دقیقه اخیر"><i class="dot green"></i><b data-live="online"><?= Html::n($live['online']) ?></b> آنلاین</span>
        <span title="در صف جست‌وجو"><i class="dot amber"></i><b data-live="searching"><?= Html::n($live['searching']) ?></b> در جست‌وجو</span>
        <span title="چت‌های فعال"><i class="dot violet"></i><b data-live="chatting"><?= Html::n($live['chatting']) ?></b> چت فعال</span>
        <span title="درخواست‌های بله در ۵ دقیقه اخیر" class="hide-sm"><i class="dot cyan"></i><b data-live="req_min"><?= Html::n($live['req_min']) ?></b> درخواست/۵ دقیقه</span>
      </div>
      <form class="search" action="./" method="get" role="search">
        <input type="hidden" name="p" value="users">
        <input type="search" name="q" placeholder="جست‌وجوی کاربر: لقب، اسم، آیدی…" aria-label="جست‌وجوی کاربر">
      </form>
      <button class="icon-btn" data-theme-toggle title="تم روشن / تیره">🌓</button>
    </header>
    <?php if ($flash): ?>
      <div class="flash <?= $flash[0] ? 'ok' : 'bad' ?>" data-flash><?= $flash[0] ? '✅' : '⚠️' ?> <?= Html::e($flash[1]) ?></div>
    <?php endif; ?>
    <main class="content"><?= $content ?></main>
  </div>
</div>
</body>
</html>
