<?php
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var int $pageNo @var int $pagesCount */
if ($pagesCount <= 1) {
    return;
}
$from = max(1, $pageNo - 3);
$to = min($pagesCount, $pageNo + 3);
?>
<nav class="pager">
  <?php if ($pageNo > 1): ?><a href="<?= Html::e(Html::query(['n' => $pageNo - 1])) ?>">→ قبلی</a><?php endif; ?>
  <?php for ($i = $from; $i <= $to; $i++): ?>
    <a href="<?= Html::e(Html::query(['n' => $i])) ?>" class="<?= $i === $pageNo ? 'on' : '' ?>"><?= Html::n($i) ?></a>
  <?php endfor; ?>
  <?php if ($pageNo < $pagesCount): ?><a href="<?= Html::e(Html::query(['n' => $pageNo + 1])) ?>">بعدی ←</a><?php endif; ?>
  <span class="muted">از <?= Html::n($pagesCount) ?></span>
</nav>
