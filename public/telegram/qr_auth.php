<?php
// qr_auth.php — QR авторизация для Telegram User профилей
// URL: /public/telegram/qr_auth.php?session_id=tg_xxxx
declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use BitrixTelegram\Database\Database;
use BitrixTelegram\Repositories\TokenRepository;
use BitrixTelegram\Repositories\ProfileRepository;
use BitrixTelegram\Helpers\Logger;
use BitrixTelegram\Services\MadelineProtoService;

$config = require __DIR__ . '/../../config/config.php';
$pdo    = Database::getInstance($config['database'])->getConnection();

$profileRepo = new ProfileRepository($pdo);
$tokenRepo   = new TokenRepository($pdo);
$logger      = new Logger($config['logging']);

// Конструктор принимает TokenRepository (обратная совместимость)
// ProfileRepository передаём через setter
$madelineService = new MadelineProtoService(
    $tokenRepo,
    $logger,
    $config['telegram']['api_id'],
    $config['telegram']['api_hash'],
    $config['sessions']['path'] ?? null
);
$madelineService->setProfileRepository($profileRepo);

// ── AJAX (POST) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action    = $_POST['action']     ?? '';
    $sessionId = trim($_POST['session_id'] ?? '');

    try {
        if (empty($sessionId)) {
            throw new \Exception('session_id не указан');
        }

        $sessionInfo = $profileRepo->getSessionBySessionId($sessionId);
        if (!$sessionInfo) {
            throw new \Exception('Сессия не найдена');
        }

        $profileId = (int) $sessionInfo['profile_id'];

        switch ($action) {

            // ── start_auth ───────────────────────────────────
            case 'start_auth':
                $instance = $madelineService->createOrGetInstanceByProfile($profileId, $sessionId);

                // Сначала проверяем — вдруг уже авторизован
                try {
                    $self = $instance->getSelf();
                    if ($self && isset($self['id'])) {
                        $madelineService->updateProfileSessionStatus(
                            $sessionId, 'authorized',
                            $self['id'] ?? null,
                            $self['username'] ?? null,
                            $self['first_name'] ?? null,
                            $self['last_name'] ?? null
                        );
                        $logger->info('Already authorized', ['user_id' => $self['id']]);
                        echo json_encode(['success' => true, 'authorized' => true, 'user' => $self]);
                        exit;
                    }
                } catch (\Throwable $e) {
                    $logger->debug('getSelf failed (not authorized)', ['error' => $e->getMessage()]);
                }

                // Получаем QR
                $qrLogin = $instance->qrLogin();
                $logger->info('qrLogin result', [
                    'type'     => get_debug_type($qrLogin),
                    'is_null'  => $qrLogin === null,
                    'is_fiber' => $qrLogin instanceof \Fiber,
                    'class'    => is_object($qrLogin) ? get_class($qrLogin) : 'n/a',
                ]);

                // qrLogin() вернул null — MadelineProto считает сессию авторизованной
                // Пробуем getSelf ещё раз
                if ($qrLogin === null) {
                    try {
                        $self = $instance->getSelf();
                        if ($self && isset($self['id'])) {
                            $madelineService->updateProfileSessionStatus(
                                $sessionId, 'authorized',
                                $self['id'] ?? null,
                                $self['username'] ?? null,
                                $self['first_name'] ?? null,
                                $self['last_name'] ?? null
                            );
                            $logger->info('Authorized after qrLogin null', ['user_id' => $self['id']]);
                            echo json_encode(['success' => true, 'authorized' => true, 'user' => $self]);
                            exit;
                        }
                    } catch (\Throwable $e) {
                        $logger->error('getSelf after qrLogin null failed', ['error' => $e->getMessage()]);
                    }

                    // Файл сессии повреждён или устарел — удаляем и пересоздаём
                    $logger->warning('qrLogin null and getSelf failed — resetting session file');
                    $madelineService->resetSessionFile($profileId, $sessionId);
                    $instance = $madelineService->createOrGetInstanceByProfile($profileId, $sessionId);
                    $qrLogin  = $instance->qrLogin();
                    $logger->info('qrLogin after reset', ['type' => get_debug_type($qrLogin)]);
                }

                $qrLink = extractQrLink($qrLogin, $logger);

                if (!$qrLink) {
                    throw new \Exception('MadelineProto не вернул QR-ссылку. Тип: ' . get_debug_type($qrLogin));
                }

                echo json_encode(['success' => true, 'authorized' => false, 'qr_link' => $qrLink]);
                exit;

            // ── check_auth ───────────────────────────────────
            case 'check_auth':
                $instance = $madelineService->createOrGetInstanceByProfile($profileId, $sessionId);

                try {
                    $user = $instance->getSelf();
                    if ($user && isset($user['id'])) {
                        $madelineService->updateProfileSessionStatus(
                            $sessionId, 'authorized',
                            $user['id'] ?? null,
                            $user['username'] ?? null,
                            $user['first_name'] ?? null,
                            $user['last_name'] ?? null
                        );

                        $logger->info('Telegram User authorized via QR', [
                            'session_id' => $sessionId,
                            'profile_id' => $profileId,
                            'user_id'    => $user['id'],
                        ]);

                        echo json_encode([
                            'success'    => true,
                            'authorized' => true,
                            'user'       => [
                                'id'         => $user['id'] ?? null,
                                'username'   => $user['username'] ?? null,
                                'first_name' => $user['first_name'] ?? null,
                                'last_name'  => $user['last_name'] ?? null,
                            ],
                        ]);
                        exit;
                    }
                } catch (\Exception $e) {
                    // Ещё не авторизован
                    $logger->debug('check_auth: not yet authorized', [
                        'session_id' => $sessionId,
                        'error'      => $e->getMessage(),
                    ]);
                }

                echo json_encode(['success' => true, 'authorized' => false]);
                exit;

            // ── refresh_qr ───────────────────────────────────
            case 'refresh_qr':
                $instance = $madelineService->createOrGetInstanceByProfile($profileId, $sessionId);
                $qrLogin  = $instance->qrLogin();
                $qrLink   = extractQrLink($qrLogin, $logger);

                if (!$qrLink) {
                    throw new \Exception('Не удалось получить новый QR-код');
                }

                echo json_encode(['success' => true, 'qr_link' => $qrLink]);
                exit;

            default:
                throw new \Exception('Неизвестное действие: ' . htmlspecialchars($action));
        }

    } catch (\Throwable $e) {
        $logger->error('qr_auth error', [
            'action'     => $action,
            'session_id' => $sessionId ?? '?',
            'error'      => $e->getMessage(),
            'file'       => basename($e->getFile()),
            'line'       => $e->getLine(),
        ]);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ── Вспомогательная функция ──────────────────────────────────
function extractQrLink(mixed $qrLogin, Logger $logger): ?string
{
    if ($qrLogin === null) {
        $logger->warning('qrLogin returned NULL');
        return null;
    }

    $type = get_debug_type($qrLogin);
    $logger->info('qrLogin raw result', ['type' => $type]);

    if (is_string($qrLogin)) {
        $logger->info('qrLogin is string', ['value' => substr($qrLogin, 0, 200)]);
        return $qrLogin;
    }

    if (is_array($qrLogin)) {
        $logger->info('qrLogin is array', ['keys' => array_keys($qrLogin)]);
        if (isset($qrLogin['link']))  return $qrLogin['link'];
        if (isset($qrLogin['token'])) return 'tg://login?token=' . base64_encode($qrLogin['token']);
        // Пробуем сериализовать для диагностики
        $logger->info('qrLogin array full', ['data' => json_encode($qrLogin)]);
        return null;
    }

    if (is_object($qrLogin)) {
        $props = [];
        try { $props = (array)$qrLogin; } catch (\Throwable $e) {}
        $logger->info('qrLogin is object', [
            'class'   => get_class($qrLogin),
            'props'   => array_keys($props),
            'has_link'     => isset($qrLogin->link),
            'has_token'    => isset($qrLogin->token),
            'has_getLink'  => method_exists($qrLogin, 'getLink'),
        ]);

        if (isset($qrLogin->link) && $qrLogin->link)   return $qrLogin->link;
        if (method_exists($qrLogin, 'getLink'))         return $qrLogin->getLink();
        if (isset($qrLogin->token) && $qrLogin->token)  return 'tg://login?token=' . base64_encode((string)$qrLogin->token);

        // Ищем по всем публичным свойствам
        foreach ($props as $k => $v) {
            $logger->info('qrLogin prop', ['key' => $k, 'type' => gettype($v), 'value' => is_scalar($v) ? substr((string)$v, 0, 100) : gettype($v)]);
        }

        return null;
    }

    $logger->warning('qrLogin unknown type', ['type' => $type]);
    return null;
}

// ── GET (HTML страница) ──────────────────────────────────────
$sessionId = trim($_GET['session_id'] ?? '');
if (empty($sessionId)) {
    http_response_code(400);
    die('Ошибка: не указан session_id');
}

$sessionInfo = $profileRepo->getSessionBySessionId($sessionId);
if (!$sessionInfo) {
    http_response_code(404);
    die('Ошибка: сессия не найдена. Возможно профиль был удалён.');
}

$profileName = htmlspecialchars($sessionInfo['session_name'] ?? $sessionInfo['profile_name'] ?? 'Telegram User');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Telegram авторизация — <?= $profileName ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
*,::before,::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#f5f4f0;--white:#fff;--ink:#18181b;--ink2:#3f3f46;--muted:#71717a;
  --border:#e4e4e7;--accent:#2563eb;
  --green:#16a34a;--green-l:#f0fdf4;
  --red:#dc2626;--red-l:#fef2f2;
  --amber:#d97706;--amber-l:#fffbeb;
}
html{font-family:'DM Sans',system-ui,sans-serif;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}

.wrap{width:440px;max-width:100%}
.card{background:var(--white);border:1px solid var(--border);border-radius:16px;padding:32px;box-shadow:0 4px 6px rgba(0,0,0,.07),0 20px 48px rgba(0,0,0,.1)}

.head{text-align:center;margin-bottom:24px}
.head-icon{font-size:40px;margin-bottom:10px}
.head h1{font-size:22px;font-weight:600;margin-bottom:4px}
.head p{font-size:13px;color:var(--muted)}

.info-row{display:flex;justify-content:space-between;align-items:center;padding:9px 12px;background:var(--bg);border-radius:8px;margin-bottom:6px;font-size:13px}
.info-row .lbl{color:var(--muted);font-size:12px}
.info-row .val{font-weight:500}

.divider{height:1px;background:var(--border);margin:20px 0}

.state{display:none;text-align:center}
.state.on{display:block}

.spinner{width:44px;height:44px;border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;margin:20px auto}
@keyframes spin{to{transform:rotate(360deg)}}

#qrcode{display:inline-block;padding:16px;background:#fff;border-radius:10px;border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,.06);margin:4px 0 16px}

.steps{background:var(--bg);border-radius:10px;padding:16px;text-align:left;font-size:13px;line-height:1.7;color:var(--ink2)}
.steps ol{padding-left:18px}
.steps li{margin:3px 0}
.steps strong{color:var(--ink)}

.timer-btn{display:inline-flex;align-items:center;gap:6px;background:var(--amber-l);color:var(--amber);border-radius:20px;padding:5px 14px;font-size:12px;font-weight:500;margin:10px 0 16px;cursor:pointer;border:none;font-family:inherit;transition:opacity .2s}
.timer-btn.urgent{animation:pulse 1s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

.success-icon{width:72px;height:72px;background:var(--green);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:32px;color:#fff;margin:0 auto 16px;animation:pop .35s ease}
@keyframes pop{0%{transform:scale(.5)}70%{transform:scale(1.1)}100%{transform:scale(1)}}
.user-card{background:var(--green-l);border:1px solid rgba(22,163,74,.2);border-radius:10px;padding:16px;margin:16px 0;text-align:left}
.user-card .uname{font-weight:600;font-size:15px;margin-bottom:3px}
.user-card .umeta{font-size:12px;color:var(--muted)}

.error-box{background:var(--red-l);border:1px solid rgba(220,38,38,.2);border-radius:10px;padding:14px;font-size:13px;color:var(--red);margin-top:14px;text-align:left;line-height:1.5}

.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:500;border:none;cursor:pointer;transition:all .13s;font-family:inherit;text-decoration:none}
.btn-primary{background:var(--ink);color:#fff}
.btn-primary:hover{background:#27272a}
.btn-ghost{background:transparent;border:1.5px solid var(--border);color:var(--ink2)}
.btn-ghost:hover{background:var(--bg)}

.check-hint{font-size:11px;color:var(--muted);margin-top:10px}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">

    <div class="head">
      <div class="head-icon">👤</div>
      <h1>Telegram авторизация</h1>
      <p>Отсканируйте QR-код в приложении Telegram</p>
    </div>

    <div class="info-row">
      <span class="lbl">Профиль</span>
      <span class="val"><?= $profileName ?></span>
    </div>
    <div class="info-row">
      <span class="lbl">Session ID</span>
      <span class="val" style="font-family:monospace;font-size:11px"><?= htmlspecialchars($sessionId) ?></span>
    </div>

    <div class="divider"></div>

    <!-- Загрузка -->
    <div class="state on" id="stLoading">
      <div class="spinner"></div>
      <p style="color:var(--muted);font-size:13px">Инициализация MadelineProto...</p>
    </div>

    <!-- QR -->
    <div class="state" id="stQR">
      <div id="qrcode"></div>
      <button class="timer-btn" id="timerBtn" onclick="refreshQR()">
        ↻ Обновить QR &nbsp;·&nbsp; <span id="qrCd">15</span>с
      </button>
      <div class="steps">
        <ol>
          <li>Откройте <strong>Telegram</strong> на телефоне</li>
          <li>Перейдите <strong>Настройки → Устройства → Подключить устройство</strong></li>
          <li>Отсканируйте QR-код</li>
          <li>Подтвердите вход на телефоне</li>
        </ol>
      </div>
      <p class="check-hint">Проверка каждые 3с · прошло: <span id="elapsed">0</span>с</p>
    </div>

    <!-- Успех -->
    <div class="state" id="stSuccess">
      <div class="success-icon">✓</div>
      <h2 style="font-size:20px;margin-bottom:6px">Авторизация успешна!</h2>
      <p style="font-size:13px;color:var(--muted)">Сессия активирована. Можете закрыть эту вкладку.</p>
      <div class="user-card" id="userCard"></div>
      <button class="btn btn-ghost" style="margin-top:4px" onclick="window.close()">Закрыть вкладку</button>
    </div>

    <!-- Ошибка -->
    <div class="state" id="stError">
      <p style="font-size:32px;margin-bottom:8px">⚠️</p>
      <h2 style="font-size:18px;margin-bottom:6px">Ошибка</h2>
      <div class="error-box" id="errMsg"></div>
      <div style="margin-top:16px;display:flex;gap:8px;justify-content:center">
        <button class="btn btn-primary" onclick="start()">Попробовать снова</button>
        <button class="btn btn-ghost" onclick="window.close()">Закрыть</button>
      </div>
    </div>

  </div>
</div>

<script>
const SID           = '<?= htmlspecialchars($sessionId) ?>';
const QR_REFRESH_MS = 15000;
const CHECK_MS      = 3000;

let checkT, qrT, cdT, elapsed = 0, qrElapsed = 0;

function show(id){
  document.querySelectorAll('.state').forEach(s => s.classList.remove('on'));
  document.getElementById(id)?.classList.add('on');
}

async function post(action){
  const fd = new FormData();
  fd.append('action', action);
  fd.append('session_id', SID);
  const r = await fetch(window.location.pathname + window.location.search, {method:'POST', body:fd});
  return r.json();
}

async function start(){
  show('stLoading');
  stop();
  try {
    const d = await post('start_auth');
    if (!d.success) throw new Error(d.error || 'Ошибка инициализации');
    if (d.authorized) { showSuccess(d.user); return; }
    if (!d.qr_link)   throw new Error('QR-ссылка не получена от сервера');
    drawQR(d.qr_link);
    startTimers();
  } catch(e){ showError(e.message); }
}

function drawQR(link){
  show('stQR');
  const el = document.getElementById('qrcode');
  el.innerHTML = '';
  new QRCode(el, {text:link, width:220, height:220, colorDark:'#18181b', colorLight:'#ffffff', correctLevel:QRCode.CorrectLevel.H});
  qrElapsed = 0;
  document.getElementById('qrCd').textContent = Math.round(QR_REFRESH_MS/1000);
  document.getElementById('timerBtn').classList.remove('urgent');
}

function showSuccess(user){
  stop();
  show('stSuccess');
  const name = [user.first_name, user.last_name].filter(Boolean).join(' ') || '—';
  document.getElementById('userCard').innerHTML =
    `<div class="uname">${esc(name)}</div>` +
    (user.username ? `<div class="umeta">@${esc(user.username)}</div>` : '') +
    (user.id       ? `<div class="umeta">ID: ${user.id}</div>` : '');
}

function showError(msg){
  stop();
  show('stError');
  document.getElementById('errMsg').textContent = msg;
}

async function checkAuth(){
console.log('Checking auth...');
  try {
    const d = await post('check_auth');
    console.log('check_auth response', d);
    if (d.success && d.authorized) showSuccess(d.user);
  } catch(e){
    console.log('check_auth error', e);
  }
}

async function refreshQR(){
  try {
    const d = await post('refresh_qr');
    if (d.success && d.qr_link) drawQR(d.qr_link);
  } catch(e){}
}

function startTimers(){
  elapsed = qrElapsed = 0;
  checkT = setInterval(checkAuth, CHECK_MS);
  qrT    = setInterval(refreshQR, QR_REFRESH_MS);
  cdT    = setInterval(() => {
    elapsed++; qrElapsed++;
    document.getElementById('elapsed').textContent = elapsed;
    const rem = Math.max(0, Math.round(QR_REFRESH_MS/1000) - qrElapsed);
    document.getElementById('qrCd').textContent = rem;
    document.getElementById('timerBtn').classList.toggle('urgent', rem <= 4);
  }, 1000);
}

function stop(){
  [checkT, qrT, cdT].forEach(t => t && clearInterval(t));
  checkT = qrT = cdT = null;
}

function esc(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

window.addEventListener('beforeunload', stop);
document.addEventListener('DOMContentLoaded', start);
</script>
</body>
</html>