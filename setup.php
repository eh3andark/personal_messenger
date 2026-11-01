<?php
declare(strict_types=1);

/** First-run only. Never ships a password or an install secret in GitHub/ZIP. */
require_once __DIR__ . '/core/bootstrap.php';
require_once __DIR__ . '/core/CryptoEngine.php';
require_once __DIR__ . '/core/Auth.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; font-src 'self'; form-action 'self'; base-uri 'none'");

$userPath = DATA_DIR . '/users.json';
$tokenPath = DATA_DIR . '/.setup_token.php';
$error = '';

function setup_users_state(string $path): string
{
    if (!file_exists($path)) return 'empty';
    $raw = @file_get_contents($path);
    $users = $raw !== false ? json_decode($raw, true) : null;
    // A corrupt registry is never treated as a new installation.
    if (!is_array($users) || json_last_error() !== JSON_ERROR_NONE || $users !== array_values($users)) return 'corrupt';
    return count($users) === 0 ? 'empty' : 'installed';
}

try {
    $state = setup_users_state($userPath);
    if ($state === 'installed') {
        http_response_code(404);
        exit('404 Not Found');
    }
    if ($state === 'corrupt') throw new RuntimeException('فایل users.json نامعتبر است؛ ابتدا از داده‌ها نسخه پشتیبان بگیرید. نصب مجدد به‌طور خودکار مجاز نیست.');
    if (!function_exists('openssl_encrypt') || !extension_loaded('mbstring') || !extension_loaded('fileinfo')) {
        throw new RuntimeException('افزونه‌های PHP مورد نیاز: OpenSSL، mbstring و fileinfo باید فعال باشند.');
    }
    $setupToken = with_file_lock('setup', function () use ($tokenPath): string {
        if (file_exists($tokenPath)) {
            $current = @include $tokenPath;
            if (!is_string($current) || !preg_match('/^[a-f0-9]{64}$/D', $current)) {
                throw new RuntimeException('فایل کد نصب آسیب‌دیده است؛ آن را از بکاپ بازگردانید یا با رعایت امنیت حذف کرده و صفحه را دوباره باز کنید.');
            }
            return $current;
        }
        $value = bin2hex(random_bytes(32));
        $body = "<?php\nif (!defined('BASE_DIR')) { http_response_code(403); exit; }\nreturn '{$value}';\n";
        $tmp = $tokenPath . '.tmp.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $body, LOCK_EX) === false) throw new RuntimeException('ساخت کد نصب در پوشه محافظت‌شده data ممکن نشد.');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $tokenPath)) {
            @unlink($tmp);
            throw new RuntimeException('ثبت کد نصب ناموفق بود.');
        }
        return $value;
    });
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $submittedToken = trim((string)($_POST['setup_token'] ?? ''));
        $username = normalize_username((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirm'] ?? '');
        $name = mb_substr(trim((string)($_POST['display_name'] ?? 'مدیر سامانه')), 0, 50);
        if (!hash_equals($setupToken, $submittedToken)) {
            $error = 'کد نصب صحیح نیست. کد را از فایل محافظت‌شده data/.setup_token.php در File Manager بخوانید.';
        } elseif (strlen($username) < 3 || strlen($username) > 32 || strpos($username, '__') !== false ||
            !preg_match('/^[a-z0-9][a-z0-9_.-]*$/D', $username) ||
            in_array($username, ['ai', 'system', 'all', 'root'], true)) {
            $error = 'آیدی مدیر باید ۳ تا ۳۲ نویسه مجاز انگلیسی/عدد باشد.';
        } elseif (strlen($password) < 12 || strlen($password) > 72 || $password !== $confirm) {
            $error = 'رمز مدیر باید ۱۲ تا ۷۲ بایت باشد و تکرار آن یکسان وارد شود.';
        } elseif (Auth::hasLegacyDataForId($username)) {
            $error = 'این آیدی به داده‌های نصب قبلی متصل است؛ برای امنیت، آیدی دیگری انتخاب کنید یا از بکاپ درست بازیابی کنید.';
        } elseif (!CryptoEngine::isSupported()) {
            $error = 'هاست از AES-256-GCM پشتیبانی نمی‌کند؛ نصب ایمن متوقف شد.';
        } else {
            $created = with_file_lock('users', function () use ($userPath, $username, $password, $name): bool {
                if (setup_users_state($userPath) !== 'empty') return false;
                $record = [[
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_BCRYPT),
                    'display_name' => $name !== '' ? $name : $username,
                    'role' => 'admin',
                    'avatar_color' => '#2AABEE',
                    'avatar_url' => '',
                    'bio' => '',
                    'quota_mb' => 2048,
                    'active' => true,
                ]];
                Auth::recordCreatedAccounts([$username]);
                return write_json_atomic($userPath, $record, true);
            });
            if (!$created) throw new RuntimeException('حساب مدیر ذخیره نشد یا سامانه پیش‌تر راه‌اندازی شده است.');
            if (Auth::getPrimaryAdminId() !== $username) throw new RuntimeException('ثبت مدیر اصلی سامانه ناموفق بود.');
            @unlink($tokenPath);
            header('Location: index.php', true, 303);
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('Chat Amn setup: ' . $e->getMessage());
    $error = $e->getMessage();
    http_response_code(503);
}
function setup_escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>راه‌اندازی امن چت امن</title>
<style>@font-face{font-family:v;src:url('assets/fonts/Vazirmatn-Variable.woff2') format('woff2')}*{box-sizing:border-box}body{font:15px v,Tahoma,sans-serif;background:#0e1621;color:#f5f7fa;min-height:100dvh;margin:0;padding:20px;display:grid;place-items:center}main{width:min(100%,520px);background:#17212b;border:1px solid #334155;border-radius:20px;padding:clamp(20px,5vw,36px);box-shadow:0 24px 70px #0005}h1{font-size:23px;margin:0 0 8px}p,li{color:#b9c6d3;line-height:1.9}ol{padding-right:24px}code{direction:ltr;unicode-bidi:embed;display:inline-block;background:#233445;padding:2px 5px;border-radius:5px}label{display:block;margin:15px 0 4px}input{width:100%;height:43px;background:#202b36;color:white;border:1px solid #45566b;border-radius:9px;padding:0 12px;font:inherit}input:focus{outline:2px solid #2aabee}button{width:100%;margin-top:22px;background:#2aabee;border:0;border-radius:10px;padding:12px;color:#081b29;font:700 15px v,Tahoma;cursor:pointer}.error{color:#fecaca;background:#7f1d1d;padding:12px;border-radius:9px}small{color:#99aec2}a{color:#67c6fa}</style></head><body><main>
<h1>🔐 راه‌اندازی اولیهٔ مدیر</h1>
<p>این صفحه تنها پیش از ساخت اولین حساب کاربری کار می‌کند. هیچ رمز یا کد نصبی در مخزن گیت‌هاب/بسته وجود ندارد.</p>
<ol><li>در cPanel → File Manager گزینهٔ نمایش فایل‌های مخفی را روشن کنید.</li><li>فایل <code>data/.setup_token.php</code> را از همان File Manager باز کنید و مقدار بین علامت‌های نقل‌قول پس از <code>return</code> را کپی کنید. آن را برای دیگران نفرستید.</li><li>آیدی و رمز قوی مدیر را خودتان تعیین کنید. پس از نصب این کد حذف و صفحه غیرفعال می‌شود.</li></ol>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= setup_escape($error) ?></p><?php endif; ?>
<?php if (isset($setupToken) && $error === '' || (isset($setupToken) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')): ?>
<form method="post" autocomplete="off"><label for="setup_token">کد نصب مخفی از File Manager</label><input id="setup_token" name="setup_token" dir="ltr" spellcheck="false" maxlength="64" required><label for="username">آیدی مدیر</label><input id="username" name="username" dir="ltr" pattern="[a-zA-Z0-9_.-]{3,32}" value="<?= setup_escape((string)($_POST['username'] ?? 'admin')) ?>" required><label for="display_name">نام نمایشی</label><input id="display_name" name="display_name" value="<?= setup_escape((string)($_POST['display_name'] ?? 'مدیر سامانه')) ?>" maxlength="50"><label for="password">رمز جدید (حداقل ۱۲ کاراکتر)</label><input id="password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><label for="password_confirm">تکرار رمز</label><input id="password_confirm" type="password" name="password_confirm" minlength="12" maxlength="72" autocomplete="new-password" required><button type="submit">ساخت حساب مدیر و پایان نصب</button></form>
<?php endif; ?><p><small>پیش از نصب، HTTPS و مسدودبودن دسترسی وب به پوشهٔ data را بررسی کنید. راهنمای کامل در README-FA.md بسته است.</small></p>
</main></body></html>
