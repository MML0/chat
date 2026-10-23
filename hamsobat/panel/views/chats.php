<?php
use Hamsobat\Html;
use Hamsobat\PanelData;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data */
$f = ['status' => (string) ($_GET['status'] ?? ''), 'q' => (string) ($_GET['q'] ?? ''), 'reported' => !empty($_GET['reported']), 'user' => (int) ($_GET['user'] ?? 0)];
$pageNo = max(1, (int) ($_GET['n'] ?? 1));
[$rows, $total] = $data->chats($f, $pageNo);
$forUser = $f['user'] ? $app->users()->get($f['user']) : null;
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="chats">
  <?php if ($forUser): ?><input type="hidden" name="user" value="<?= (int) $forUser['id'] ?>"><span class="badge violet">فقط چت‌های <?= Html::e($forUser['nickname']) ?> <a href="?p=chats">✕</a></span><?php endif; ?>
  <input type="search" name="q" value="<?= Html::e($f['q']) ?>" placeholder="🔍 لقب یکی از دو نفر">
  <div class="seg">
    <?php foreach (['' => 'همه', 'active' => '● فعال', 'ended' => 'تمام‌شده'] as $v => $l): ?>
      <a href="<?= Html::e(Html::query(['status' => $v, 'n' => null])) ?>" class="<?= $f['status'] === $v ? 'on' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <label class="check"><input type="checkbox" name="reported" value="1" data-autosubmit <?= $f['reported'] ? 'checked' : '' ?>> فقط گزارش‌شده</label>
  <button class="btn primary">اعمال</button>
  <span class="muted"><?= Html::n($total) ?> گفت‌وگو</span>
</form>
<section class="card">
  <?= panel_view('_chat_rows', ['rows' => $rows]) ?>
  <?= panel_view('_pager', ['pageNo' => $pageNo, 'pagesCount' => max(1, (int) ceil($total / PanelData::PER_PAGE))]) ?>
</section>
