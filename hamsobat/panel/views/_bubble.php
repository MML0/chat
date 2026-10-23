<?php
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var array $m one {messages} or {anonymous_inbox} row @var string $side one|two @var string $src m|i (media.php parameter) */
$type = $m['message_type'];
$file = !empty($m['bale_file_id']);
$url = 'media.php?' . $src . '=' . (int) $m['id'];
$status = $m['delivery_status'] ?? ($m['status'] ?? 'sent');
?>
<div class="bubble <?= $side ?> <?= $status === 'filtered' || $status === 'failed' || $status === 'blocked' ? 'blocked' : '' ?>" data-id="<?= (int) $m['id'] ?>">
  <?php if ($file && in_array($type, ['photo', 'sticker'], true)): ?>
    <a href="<?= $url ?>" target="_blank" rel="noopener"><img class="media <?= $type ?>" loading="lazy" src="<?= $url ?>" alt=""></a>
  <?php elseif ($file && in_array($type, ['voice', 'audio'], true)): ?>
    <audio controls preload="none" src="<?= $url ?>"></audio>
  <?php elseif ($file && in_array($type, ['video', 'animation', 'video_note'], true)): ?>
    <video controls preload="none" src="<?= $url ?>" <?= $type === 'animation' ? 'loop muted' : '' ?>></video>
  <?php elseif ($file): ?>
    <a class="file" href="<?= $url ?>" target="_blank" rel="noopener"><?= Html::typeIcon($type) ?> فایل</a>
  <?php elseif ($type !== 'text'): ?>
    <span class="file"><?= Html::typeIcon($type) ?> <?= Html::e($type) ?></span>
  <?php endif; ?>
  <?php if (($m['text_content'] ?? null) !== null && $m['text_content'] !== ''): ?><p><?= nl2br(Html::e($m['text_content'])) ?></p><?php endif; ?>
  <span class="when"><?= Html::clock($m['created_at']) ?>
    <?php if ($status === 'filtered'): ?> · 🚫 فیلتر شد<?php elseif ($status === 'failed'): ?> · ❌ نرسید<?php elseif ($status === 'blocked'): ?> · 🚫 مسدود<?php endif; ?></span>
</div>
