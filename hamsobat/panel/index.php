<?php
// ============================================================================
// Admin panel — https://YOUR.SITE/hamsobat/panel/
// Login: admin password from config.php + a code the bot sends to your Bale account.
// Pages: ?p=dashboard|live|users|user|chats|chat|inbox|reports|settings|server|logs
// ============================================================================
$started = hrtime(true);
require dirname(__DIR__) . '/bootstrap.php';

use Hamsobat\App;
use Hamsobat\Html;
use Hamsobat\Metrics;
use Hamsobat\PanelActions;
use Hamsobat\PanelAuth;
use Hamsobat\PanelData;

$config = hamsobat_config();
date_default_timezone_set('UTC');
Html::setTimezone($config['timezone'] ?? 'Asia/Tehran');

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; media-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");

/** Renders panel/views/<name>.php with $vars and returns the HTML. */
function panel_view(string $name, array $vars): string
{
    extract($vars);
    ob_start();
    require __DIR__ . "/views/{$name}.php";
    return (string) ob_get_clean();
}

function panel_redirect(string $query = ''): never
{
    header('Location: ./' . ($query !== '' ? '?' . $query : ''), true, 303);
    exit;
}

try {
    $app = App::fromConfig($config);
} catch (Throwable $e) {
    http_response_code(500);
    exit('<!doctype html><meta charset="utf-8"><body style="font-family:Tahoma;direction:rtl;padding:40px">❌ اتصال به دیتابیس برقرار نشد. config.php را بررسی کنید.</body>');
}

PanelAuth::start();
$auth = new PanelAuth($app);
$page = (string) ($_GET['p'] ?? 'dashboard');

// ---------------------------------------------------------------- login
if (!$auth->loggedIn()) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['code']) && $auth->awaitingCode()) {
            $error = $auth->checkCode((string) $_POST['code']);
        } elseif (isset($_POST['password'])) {
            $error = $auth->checkPassword((string) $_POST['password']);
        } elseif (isset($_POST['restart'])) {
            unset($_SESSION['pending']);
        }
        if ($error === null && $auth->loggedIn()) {
            panel_redirect();
        }
    }
    echo panel_view('login', ['error' => $error, 'step' => $auth->awaitingCode() ? 'code' : 'password', 'twoFactor' => $auth->twoFactor()]);
    exit;
}

// ---------------------------------------------------------------- actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$auth->checkCsrf($_POST['_csrf'] ?? null)) {
        http_response_code(403);
        exit('❌ توکن امنیتی نامعتبر است. صفحه را تازه کنید.');
    }
    $back = (string) ($_POST['back'] ?? '');
    $back = preg_match('~^[A-Za-z0-9_=&%.\-]*$~', $back) ? $back : '';
    if (($_POST['action'] ?? '') === 'logout') {
        $auth->logout();
        panel_redirect();
    }
    try {
        $_SESSION['flash'] = (new PanelActions($app, $auth))->run((string) ($_POST['action'] ?? ''), $_POST);
    } catch (Throwable $e) {
        $app->log->error('panel action: ' . $e->getMessage(), ['at' => basename($e->getFile()) . ':' . $e->getLine()]);
        $_SESSION['flash'] = [false, 'خطا: ' . $e->getMessage()];
    }
    panel_redirect($back);
}

// ---------------------------------------------------------------- pages
$pages = [
    'dashboard' => ['داشبورد', '📊'],
    'live'      => ['زنده', '⚡'],
    'users'     => ['کاربران', '👥'],
    'chats'     => ['چت‌ها', '💬'],
    'inbox'     => ['پیام‌های ناشناس', '📩'],
    'reports'   => ['گزارش‌ها', '🚩'],
    'broadcast' => ['ارسال پیام', '📢'],
    'settings'  => ['تنظیمات', '⚙️'],
    'server'    => ['سرور', '🖥'],
    'logs'      => ['لاگ‌ها', '🧾'],
];
$titles = $pages + ['user' => ['کاربر', '👤'], 'chat' => ['گفت‌وگو', '💬']];
if (!isset($titles[$page])) {
    $page = 'dashboard';
}
$vars = [
    'app'   => $app,
    'data'  => new PanelData($app),
    'csrf'  => $auth->csrf(),
    'page'  => $page,
    'pages' => $pages,
];
try {
    $content = panel_view($page, $vars);
} catch (Throwable $e) {
    $app->log->error('panel page ' . $page . ': ' . $e->getMessage(), ['at' => basename($e->getFile()) . ':' . $e->getLine()]);
    $content = '<div class="card empty-state">⚠️ این صفحه خطا داد: ' . Html::e($e->getMessage()) . '<br><small>اگر تازه به‌روزرسانی کرده‌اید، migrate.php را یک بار اجرا کنید.</small></div>';
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
echo panel_view('layout', $vars + ['content' => $content, 'title' => $titles[$page], 'flash' => $flash, 'live' => (new PanelData($app))->live()]);
Metrics::record($app, 'panel', (hrtime(true) - $started) / 1e6);
