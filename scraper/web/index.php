<?php
/**
 * Web control panel for scrape.php.
 *
 * Open http://localhost/instapoke/scraper/web/ (XAMPP). Enter your PIA login,
 * the start URL and how many pages to copy per IP, then press Start. The copy
 * runs in the background; this page shows its progress and can stop it.
 *
 * Only answers requests from this computer, because it can start programs and
 * holds your VPN login.
 */

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This page only works from the computer it runs on (http://localhost/...).');
}

require_once __DIR__ . '/../lib/PiaVpn.php';

session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$isWindows  = DIRECTORY_SEPARATOR === '\\';
$scraperDir = dirname(__DIR__);
$rootDir    = dirname($scraperDir);
$jobDir     = $scraperDir . DIRECTORY_SEPARATOR . 'jobs';
$jobFile    = $jobDir . DIRECTORY_SEPARATOR . 'job.json';
$logFile    = $jobDir . DIRECTORY_SEPARATOR . 'log.txt';
$statusFile = $jobDir . DIRECTORY_SEPARATOR . 'status.json';
$stopFile   = $jobDir . DIRECTORY_SEPARATOR . 'stop.flag';
$authFile   = $jobDir . DIRECTORY_SEPARATOR . 'auth.json';
$outDir     = $rootDir . DIRECTORY_SEPARATOR . 'output';
$qDir       = $rootDir . DIRECTORY_SEPARATOR . 'questions';

if (!is_dir($jobDir)) mkdir($jobDir, 0700, true);
if (!is_file("$jobDir/.htaccess")) file_put_contents("$jobDir/.htaccess", "Require all denied\n");

// ---------------------------------------------------------------- helpers

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function phpBinary(): string
{
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server') return PHP_BINARY;
    $exe = DIRECTORY_SEPARATOR === '\\' ? 'php.exe' : 'php';
    foreach ([PHP_BINDIR . DIRECTORY_SEPARATOR . $exe, 'C:\\xampp\\php\\php.exe', '/usr/bin/php'] as $p) {
        if (is_file($p)) return $p;
    }
    return $exe;
}

function pidRunning(int $pid): bool
{
    if ($pid <= 0) return false;
    if (DIRECTORY_SEPARATOR === '\\') {
        exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL', $out);
        return str_contains(implode("\n", $out), (string)$pid);
    }
    return file_exists("/proc/$pid") || (function_exists('posix_kill') && posix_kill($pid, 0));
}

function readStatus(string $file): array
{
    $s = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($s)) return ['state' => 'idle'];
    if (in_array($s['state'] ?? '', ['starting', 'running'], true) && !pidRunning((int)($s['pid'] ?? 0))) {
        // Allow a few seconds for a just-launched job to write its first status.
        if (time() - (int)($s['updated'] ?? 0) > 5) {
            $s['state'] = 'crashed';
            $s['message'] = 'The background job ended unexpectedly. See the log.';
        }
    }
    return $s;
}

function isAdmin(): ?bool
{
    if (DIRECTORY_SEPARATOR !== '\\') return function_exists('posix_geteuid') ? posix_geteuid() === 0 : null;
    exec('net session 2>&1', $out, $rc);
    return $rc === 0;
}

// ---------------------------------------------------------------- status (polled by the page)
if (isset($_GET['status'])) {
    header('Content-Type: application/json');
    $log = is_file($logFile) ? (string)file_get_contents($logFile) : '';
    if (strlen($log) > 60000) $log = '...' . substr($log, -60000);
    $files = [];
    foreach (glob($qDir . DIRECTORY_SEPARATOR . '*.txt') ?: [] as $f) $files[] = basename($f);
    echo json_encode(['status' => readStatus($statusFile), 'log' => $log, 'questions' => $files]);
    exit;
}

// ---------------------------------------------------------------- actions
$flash = '';
$defaults = [
    'url' => '', 'vpn_every' => 10, 'delay' => 1, 'limit' => '', 'match' => '',
    'same_host' => false, 'regions' => ['us', 'ca'], 'shuffle' => false, 'extract' => true,
    'openvpn' => PiaVpn::defaultOpenVpnPath(),
];
$form = array_merge($defaults, $_SESSION['form'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Form expired, go back and reload the page.');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $u = trim((string)($_POST['pia_user'] ?? ''));
        $p = (string)($_POST['pia_pass'] ?? '');
        if ($u === '' || $p === '') {
            $flash = 'Enter both your PIA username and password.';
        } else {
            $_SESSION['pia_user'] = $u;
            $_SESSION['pia_pass'] = $p;
            $flash = 'Login saved for this browser session.';
        }
    } elseif ($action === 'logout') {
        unset($_SESSION['pia_user'], $_SESSION['pia_pass']);
        $flash = 'Logged out. Your PIA login has been cleared.';
    } elseif ($action === 'stop') {
        file_put_contents($stopFile, '1');
        $flash = 'Stopping after the current page...';
    } elseif ($action === 'disconnect') {
        $flash = PiaVpn::sendSignal() ? 'Told OpenVPN to disconnect.' : 'No OpenVPN connection was found.';
    } elseif ($action === 'start') {
        $form = [
            'url'       => trim((string)($_POST['url'] ?? '')),
            'vpn_every' => max(0, (int)($_POST['vpn_every'] ?? 0)),
            'delay'     => max(0, (float)($_POST['delay'] ?? 1)),
            'limit'     => trim((string)($_POST['limit'] ?? '')),
            'match'     => trim((string)($_POST['match'] ?? '')),
            'same_host' => !empty($_POST['same_host']),
            'regions'   => array_values(array_intersect((array)($_POST['regions'] ?? []), ['us', 'ca'])),
            'shuffle'   => !empty($_POST['shuffle']),
            'extract'   => !empty($_POST['extract']),
            'openvpn'   => trim((string)($_POST['openvpn'] ?? '')) ?: PiaVpn::defaultOpenVpnPath(),
        ];
        $_SESSION['form'] = $form;
        $current = readStatus($statusFile);

        if (in_array($current['state'], ['starting', 'running'], true)) {
            $flash = 'A job is already running. Stop it first.';
        } elseif (!preg_match('#^(https?://)?[^\s/]+\.[^\s]+#i', $form['url'])) {
            $flash = 'Enter a valid start URL.';
        } elseif ($form['vpn_every'] > 0 && empty($_SESSION['pia_user'])) {
            $flash = 'Log in with your PIA account first (or set "pages per IP" to 0 to run without the VPN).';
        } elseif ($form['vpn_every'] > 0 && !$form['regions']) {
            $flash = 'Pick at least one region (US or Canada).';
        } elseif ($form['vpn_every'] > 0 && !is_file($form['openvpn'])) {
            $flash = 'OpenVPN was not found at ' . $form['openvpn'] . '. Install it or fix the path.';
        } else {
            @unlink($stopFile);
            file_put_contents($logFile, '');
            file_put_contents($statusFile, json_encode(['state' => 'starting', 'message' => 'Starting', 'updated' => time()]));
            if ($form['vpn_every'] > 0) {
                // The background job reads this once and deletes it straight away.
                file_put_contents($authFile, json_encode(['user' => $_SESSION['pia_user'], 'pass' => $_SESSION['pia_pass']]));
                @chmod($authFile, 0600);
            }
            file_put_contents($jobFile, json_encode([
                'url'           => $form['url'],
                'out'           => $outDir,
                'questions_dir' => $qDir,
                'delay'         => $form['delay'],
                'limit'         => $form['limit'] === '' ? '' : (int)$form['limit'],
                'match'         => $form['match'],
                'same_host'     => $form['same_host'],
                'extract'       => $form['extract'],
                'vpn_every'     => $form['vpn_every'],
                'vpn_regions'   => implode(',', $form['regions']),
                'vpn_shuffle'   => $form['shuffle'],
                'openvpn'       => $form['openvpn'],
                'auth_file'     => $form['vpn_every'] > 0 ? $authFile : '',
                'log_file'      => $logFile,
                'status_file'   => $statusFile,
                'stop_file'     => $stopFile,
            ], JSON_PRETTY_PRINT));

            // Launch in the background so this page returns straight away.
            $php = phpBinary();
            $script = $scraperDir . DIRECTORY_SEPARATOR . 'scrape.php';
            if ($isWindows) {
                pclose(popen('start "" /B "' . $php . '" "' . $script . '" "--job=' . $jobFile . '" > NUL 2>&1', 'r'));
            } else {
                exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg("--job=$jobFile") . ' > /dev/null 2>&1 &');
            }
            $flash = 'Started.';
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$loggedIn = !empty($_SESSION['pia_user']);
$admin = isAdmin();
$serverCount = count(glob($scraperDir . '/vpn/pia/{us,ca}_*.ovpn', GLOB_BRACE) ?: []);
$csrf = h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Page Copier</title>
<style>
  :root {
    --bg: #f5f6f8; --card: #fff; --text: #1d2330; --muted: #667085; --line: #e3e6eb;
    --accent: #2f6fde; --accent-text: #fff; --ok: #1a7f4b; --warn: #a15c00; --bad: #c0362c;
    --log-bg: #11151c; --log-text: #d6dde8;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #12151b; --card: #1b2029; --text: #e6e9ef; --muted: #98a2b3; --line: #2b323e;
      --accent: #5b8ff0; --ok: #43b97f; --warn: #e0a345; --bad: #ef6b60; --log-bg: #0b0e13;
    }
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { max-width: 980px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 22px; margin: 0 0 4px; }
  h2 { font-size: 15px; margin: 0 0 14px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
  .sub { color: var(--muted); margin: 0 0 20px; }
  .grid { display: grid; grid-template-columns: 300px 1fr; gap: 16px; align-items: start; }
  @media (max-width: 760px) { .grid { grid-template-columns: 1fr; } }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 18px; margin-bottom: 16px; }
  label { display: block; font-size: 13px; color: var(--muted); margin: 0 0 4px; }
  input[type=text], input[type=password], input[type=number], input[type=url] {
    width: 100%; padding: 8px 10px; border: 1px solid var(--line); border-radius: 6px;
    background: var(--bg); color: var(--text); font: inherit; margin-bottom: 12px;
  }
  .row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  @media (max-width: 520px) { .row { grid-template-columns: 1fr; } }
  .check { display: flex; align-items: center; gap: 8px; color: var(--text); font-size: 14px; margin-bottom: 8px; }
  .checks { display: flex; flex-wrap: wrap; gap: 4px 18px; margin-bottom: 8px; }
  button { font: inherit; padding: 8px 16px; border-radius: 6px; border: 1px solid var(--line); background: var(--bg); color: var(--text); cursor: pointer; }
  button.primary { background: var(--accent); border-color: var(--accent); color: var(--accent-text); font-weight: 600; }
  button.danger { color: var(--bad); }
  button:disabled { opacity: .5; cursor: not-allowed; }
  .actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
  .flash { padding: 10px 14px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); margin-bottom: 16px; }
  .warn { color: var(--warn); font-size: 13px; margin: 8px 0 0; }
  .muted { color: var(--muted); font-size: 13px; }
  details summary { cursor: pointer; color: var(--muted); font-size: 13px; margin: 6px 0 10px; }
  .stat { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 14px; }
  @media (max-width: 520px) { .stat { grid-template-columns: repeat(2, 1fr); } }
  .stat div { background: var(--bg); border-radius: 8px; padding: 8px 10px; min-width: 0; }
  .stat b { display: block; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .stat span { font-size: 12px; color: var(--muted); }
  .bar { height: 6px; background: var(--bg); border-radius: 3px; overflow: hidden; margin-bottom: 14px; }
  .bar i { display: block; height: 100%; width: 0; background: var(--accent); transition: width .3s; }
  .state-running, .state-starting { color: var(--accent); }
  .state-done { color: var(--ok); }
  .state-error, .state-crashed { color: var(--bad); }
  .state-stopped { color: var(--warn); }
  pre#log { background: var(--log-bg); color: var(--log-text); border-radius: 8px; padding: 12px; height: 380px; overflow: auto;
            font: 12.5px/1.45 ui-monospace, Consolas, monospace; margin: 0; white-space: pre-wrap; word-break: break-all; }
  ul.files { margin: 0; padding-left: 18px; }
  a { color: var(--accent); }
</style>
</head>
<body>
<main>
  <h1>Page Copier</h1>
  <p class="sub">Copies every link on a page, switching to a new PIA VPN server every few pages.</p>

  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>

  <div class="grid">
    <div>
      <section class="card">
        <h2>PIA login</h2>
        <?php if ($loggedIn): ?>
          <p>Logged in as <b><?= h($_SESSION['pia_user']) ?></b></p>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button name="action" value="logout">Log out</button>
          </form>
        <?php else: ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <label for="pu">Username</label>
            <input type="text" id="pu" name="pia_user" placeholder="p1234567">
            <label for="pp">Password</label>
            <input type="password" id="pp" name="pia_pass">
            <button class="primary" name="action" value="login">Save login</button>
          </form>
        <?php endif; ?>
        <p class="muted">Kept only in this browser session, until you log out or close the browser.</p>
      </section>

      <section class="card">
        <h2>VPN</h2>
        <p class="muted"><?= $serverCount ?> US and Canada servers available.</p>
        <?php if ($admin === false): ?>
          <p class="warn">XAMPP isn't running as administrator, so OpenVPN won't be able to connect.
            Close XAMPP, right-click the XAMPP Control Panel, choose "Run as administrator" and start Apache again.</p>
        <?php endif; ?>
        <?php if (!is_file($form['openvpn'])): ?>
          <p class="warn">OpenVPN not found at <?= h($form['openvpn']) ?>.</p>
        <?php endif; ?>
        <form method="post" class="actions">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button name="action" value="disconnect">Disconnect VPN now</button>
        </form>
      </section>
    </div>

    <div>
      <section class="card">
        <h2>New job</h2>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <label for="url">Start URL</label>
          <input type="text" id="url" name="url" value="<?= h($form['url']) ?>" placeholder="https://www.funtrivia.com/..." required>

          <div class="row">
            <div>
              <label for="every">Pages per IP (0 = no VPN)</label>
              <input type="number" id="every" name="vpn_every" min="0" value="<?= h($form['vpn_every']) ?>">
            </div>
            <div>
              <label for="delay">Seconds between pages</label>
              <input type="number" id="delay" name="delay" min="0" step="0.5" value="<?= h($form['delay']) ?>">
            </div>
            <div>
              <label for="limit">Max links (blank = all)</label>
              <input type="number" id="limit" name="limit" min="1" value="<?= h($form['limit']) ?>">
            </div>
          </div>

          <label>Servers</label>
          <div class="checks">
            <label class="check"><input type="checkbox" name="regions[]" value="us" <?= in_array('us', $form['regions'], true) ? 'checked' : '' ?>> United States</label>
            <label class="check"><input type="checkbox" name="regions[]" value="ca" <?= in_array('ca', $form['regions'], true) ? 'checked' : '' ?>> Canada</label>
            <label class="check"><input type="checkbox" name="shuffle" value="1" <?= $form['shuffle'] ? 'checked' : '' ?>> Random order</label>
          </div>

          <div class="checks">
            <label class="check"><input type="checkbox" name="same_host" value="1" <?= $form['same_host'] ? 'checked' : '' ?>> Only links on the same site</label>
            <label class="check"><input type="checkbox" name="extract" value="1" <?= $form['extract'] ? 'checked' : '' ?>> Extract questions when finished</label>
          </div>

          <details>
            <summary>More options</summary>
            <label for="match">Only follow links containing</label>
            <input type="text" id="match" name="match" value="<?= h($form['match']) ?>" placeholder="e.g. /trivia-quiz/">
            <label for="ovpn">OpenVPN program</label>
            <input type="text" id="ovpn" name="openvpn" value="<?= h($form['openvpn']) ?>">
          </details>

          <div class="actions">
            <button class="primary" id="startBtn" name="action" value="start">Start</button>
          </div>
        </form>
      </section>

      <section class="card">
        <h2>Progress</h2>
        <div class="stat">
          <div><span>Status</span><b id="st">-</b></div>
          <div><span>Pages</span><b id="pg">-</b></div>
          <div><span>Server</span><b id="sv">-</b></div>
          <div><span>IP address</span><b id="ip">-</b></div>
        </div>
        <div class="bar"><i id="bar"></i></div>
        <p class="muted" id="msg"></p>
        <form method="post" class="actions" style="margin-bottom:12px">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <button class="danger" id="stopBtn" name="action" value="stop" disabled>Stop</button>
        </form>
        <pre id="log"></pre>
      </section>

      <section class="card">
        <h2>Questions</h2>
        <div id="files" class="muted">None yet.</div>
      </section>
    </div>
  </div>
</main>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let lastLog = '';

async function poll() {
  try {
    const r = await fetch('?status=1', {cache: 'no-store'});
    const d = await r.json();
    const s = d.status || {};
    const running = s.state === 'running' || s.state === 'starting';
    $('st').textContent = s.state || 'idle';
    $('st').className = 'state-' + (s.state || 'idle');
    $('pg').textContent = s.total ? `${s.done || 0} / ${s.total}` : '-';
    $('sv').textContent = s.server || '-';
    $('ip').textContent = s.ip || '-';
    $('msg').textContent = s.message || '';
    $('bar').style.width = s.total ? (100 * (s.done || 0) / s.total) + '%' : '0';
    $('stopBtn').disabled = !running;
    $('startBtn').disabled = running;

    if (d.log !== lastLog) {
      const box = $('log');
      const atBottom = box.scrollTop + box.clientHeight >= box.scrollHeight - 20;
      box.textContent = d.log;
      if (atBottom) box.scrollTop = box.scrollHeight;
      lastLog = d.log;
    }

    const files = d.questions || [];
    $('files').innerHTML = files.length
      ? '<ul class="files">' + files.map(f => `<li><a href="../../questions/${encodeURIComponent(f)}" target="_blank">${esc(f)}</a></li>`).join('') + '</ul>'
      : 'None yet.';
  } catch (e) { /* server busy; try again next tick */ }
}
poll();
setInterval(poll, 2000);
</script>
</body>
</html>
