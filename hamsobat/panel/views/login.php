<?php
use Hamsobat\Html;

defined('HAMSOBAT') || exit;
/** @var ?string $error @var string $step @var bool $twoFactor */
?><!doctype html>
<html lang="fa" dir="rtl" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>ورود · پنل هم‌صحبت</title>
<link rel="stylesheet" href="assets/panel.css?v=12">
<script src="assets/panel.js?v=13" defer></script>
</head>
<body class="login-page">
  <div class="login-glow" aria-hidden="true"></div>
  <form class="login-card" method="post" autocomplete="off">
    <div class="logo big">💬</div>
    <h1>پنل مدیریت هم‌صحبت</h1>
    <?php if ($step === 'code'): ?>
      <p class="muted">🔐 کد ۶ رقمی به حساب بله‌ی ادمین فرستاده شد.</p>
      <input class="code-input" name="code" inputmode="numeric" maxlength="6" placeholder="••••••" autofocus required>
      <button class="btn primary wide">ورود</button>
      <button class="link" name="restart" value="1" formnovalidate>↩️ برگشت</button>
    <?php else: ?>
      <p class="muted"><?= $twoFactor ? 'رمز ادمین را وارد کنید؛ بعد یک کد به بله‌تان می‌آید.' : 'رمز ادمین (config.php) را وارد کنید.' ?></p>
      <input type="password" name="password" placeholder="رمز ادمین" autofocus required>
      <button class="btn primary wide">ادامه</button>
    <?php endif; ?>
    <?php if ($error): ?><div class="flash bad"><?= Html::e($error) ?></div><?php endif; ?>
  </form>
</body>
</html>
