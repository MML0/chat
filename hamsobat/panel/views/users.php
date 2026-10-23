<?php
use Hamsobat\Html;
use Hamsobat\PanelData;
use Hamsobat\Text;

defined('HAMSOBAT') || exit;
/** @var Hamsobat\App $app @var PanelData $data @var string $csrf */
$f = ['q' => (string) ($_GET['q'] ?? ''), 'status' => (string) ($_GET['status'] ?? ''), 'gender' => (string) ($_GET['gender'] ?? ''), 'sort' => (string) ($_GET['sort'] ?? '')];
$pageNo = max(1, (int) ($_GET['n'] ?? 1));
[$rows, $total] = $data->users($f, $pageNo);
$pagesCount = max(1, (int) ceil($total / PanelData::PER_PAGE));
$today = $app->today();
$onlineSince = time() - 300;
?>
<form class="filters card" method="get">
  <input type="hidden" name="p" value="users">
  <input type="search" name="q" value="<?= Html::e($f['q']) ?>" placeholder="🔍 لقب، اسم، شهر، آیدی بله یا #شماره">
  <select name="status" data-autosubmit>
    <?php foreach (['' => 'همه کاربران', 'online' => '🟢 آنلاین', 'incomplete' => '📝 پروفایل ناقص', 'suspended' => '⛔ معلق', 'unlocked' => '🔓 بدون دعوت', 'unlimited' => '💎 نامحدود'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $f['status'] === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select name="gender" data-autosubmit>
    <?php foreach (['' => 'هر جنسیت', 'f' => '👩 دختر', 'm' => '👨 پسر'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $f['gender'] === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <select name="sort" data-autosubmit>
    <?php foreach (['' => 'جدیدترین', 'seen' => 'آخرین بازدید', 'chats' => 'بیشترین چت', 'messages' => 'بیشترین پیام', 'invites' => 'بیشترین دعوت', 'credits' => 'بیشترین اعتبار', 'old' => 'قدیمی‌ترین'] as $v => $l): ?>
      <option value="<?= $v ?>" <?= $f['sort'] === $v ? 'selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn primary">اعمال</button>
  <span class="muted"><?= Html::n($total) ?> کاربر</span>
</form>

<section class="card">
  <?php if (!$rows): ?>
    <div class="empty">کاربری با این مشخصات پیدا نشد.</div>
  <?php else: ?>
  <div class="table-wrap"><table class="table users-table">
    <thead><tr><th>کاربر</th><th>سن / جنسیت</th><th>شهر</th><th>عضویت</th><th>آخرین بازدید</th><th>چت</th><th>پیام</th><th>دعوت</th><th>اعتبار</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u):
        $online = $u['last_seen_at'] && Html::time($u['last_seen_at']) >= $onlineSince; ?>
      <tr>
        <td>
          <a class="user-cell" href="?p=user&id=<?= (int) $u['id'] ?>">
            <?= Html::avatar($u, '', $online) ?>
            <span><b><?= Html::e($u['nickname'] ?? '— بدون پروفایل —') ?></b><small><?= Html::e($u['display_name'] ?? '') ?> · #<?= Html::n($u['id']) ?></small><small><?= Html::account($u['account']) ?></small></span>
          </a>
        </td>
        <td><?= $u['birth_date'] ? Html::n(Text::age($u['birth_date'], $today)) . ' ساله' : '—' ?> <span class="g-<?= Html::e($u['gender'] ?? '') ?>"><?= ['m' => '👨', 'f' => '👩'][$u['gender'] ?? ''] ?? '' ?></span></td>
        <td><?= Html::e($u['city'] ?? '—') ?></td>
        <td title="<?= Html::date($u['created_at']) ?>"><?= Html::date($u['created_at'], false) ?></td>
        <td><?= Html::ago($u['last_seen_at']) ?></td>
        <td><?= Html::n($u['chats']) ?></td>
        <td><?= Html::n($u['messages']) ?></td>
        <td><?= Html::n($u['invites']) ?></td>
        <td><?= Html::n($u['credits']) ?></td>
        <td><div class="badges">
          <?php if ($u['active_chat']): ?><a class="badge live" href="?p=chat&id=<?= (int) $u['active_chat'] ?>">● در چت</a><?php endif; ?>
          <?php if ($u['status'] === 'suspended'): ?><span class="badge bad">⛔ معلق</span><?php endif; ?>
          <?php if (!$u['profile_completed']): ?><span class="badge">📝 ناقص</span><?php endif; ?>
          <?php if ($u['referral_waived']): ?><span class="badge ok">🔓</span><?php endif; ?>
          <?php if ($app->isAdmin((int) $u['bale_user_id'])): ?><span class="badge violet">👑 ادمین</span><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= panel_view('_pager', ['pageNo' => $pageNo, 'pagesCount' => $pagesCount]) ?>
  <?php endif; ?>
</section>
