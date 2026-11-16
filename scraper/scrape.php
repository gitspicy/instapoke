<?php
/**
 * Link follower / page copier.
 *
 * Give it a starting URL. It loads that page, collects every link on it
 * (top to bottom), then visits each link in order and saves a copy of that
 * page. After each page it "goes back" to the list from the starting page and
 * moves on to the next link, until every link has been copied.
 *
 * Optionally it changes IP address every N pages by cycling through Private
 * Internet Access (PIA) VPN servers with OpenVPN.
 *
 * Usage:
 *   php scrape.php <url> [options]
 *
 * Options:
 *   --out=DIR          Where to save copies (default: ./output)
 *   --delay=SECONDS    Pause between requests, be polite (default: 1)
 *   --limit=N          Only follow the first N links (default: all)
 *   --same-host        Only follow links on the same website as the start URL
 *   --match=TEXT       Only follow links whose URL contains TEXT
 *   --timeout=SECONDS  Per-request timeout (default: 30)
 *   --extract          Run extract-questions.php on the results when finished
 *
 * VPN options (needs OpenVPN installed and an administrator window):
 *   --vpn-every=N      Switch to the next VPN server every N pages (0 = no VPN, default)
 *   --vpn-regions=LIST Which PIA server files to use (default: us,ca)
 *   --vpn-shuffle      Use the servers in random order instead of A-Z
 *   --vpn-dir=DIR      Folder with the PIA .ovpn files (default: scraper/vpn/pia)
 *   --openvpn=PATH     Path to openvpn.exe (default: C:\Program Files\OpenVPN\bin\openvpn.exe)
 *   PIA login: set PIA_USER and PIA_PASS environment variables, or you'll be asked for them.
 *
 * For every followed link it writes:
 *   output/NNN-<slug>.html   the full raw page as downloaded
 *   output/NNN-<slug>.txt    the readable text of the page (title + body text)
 * plus output/index.csv listing number, URL, HTTP status, title and file names.
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line: php scrape.php <url>\n");
}
if (!extension_loaded('curl') || !extension_loaded('dom')) {
    exit("This script needs the PHP 'curl' and 'dom' extensions.\n");
}
require_once __DIR__ . '/lib/PiaVpn.php';

// ---------------------------------------------------------------- arguments
// Options may come before or after the URL (PHP's getopt() stops at the URL, so parse by hand).
$opts = [];
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $args[] = $arg;
    }
}
$known = ['out', 'delay', 'limit', 'same-host', 'match', 'timeout', 'extract', 'help',
          'vpn-every', 'vpn-regions', 'vpn-shuffle', 'vpn-dir', 'openvpn', 'job'];
$unknown = array_diff(array_keys($opts), $known);
if ($unknown) {
    fwrite(STDERR, 'Unknown option(s): --' . implode(', --', $unknown) . "\n");
    exit(1);
}

// --job=FILE: settings come from a JSON file written by the web page (web/index.php).
$job = [];
if (isset($opts['job'])) {
    $job = json_decode((string)@file_get_contents($opts['job']), true);
    if (!is_array($job)) exit("Could not read job file: {$opts['job']}\n");
    $map = ['out' => 'out', 'delay' => 'delay', 'limit' => 'limit', 'same_host' => 'same-host',
            'match' => 'match', 'timeout' => 'timeout', 'extract' => 'extract', 'vpn_every' => 'vpn-every',
            'vpn_regions' => 'vpn-regions', 'vpn_shuffle' => 'vpn-shuffle', 'vpn_dir' => 'vpn-dir', 'openvpn' => 'openvpn'];
    foreach ($map as $k => $opt) {
        if (!isset($job[$k]) || $job[$k] === '' || $job[$k] === false) continue;
        $opts[$opt] = $job[$k];
    }
    $args = [$job['url'] ?? ''];
}

$startUrl = $args[0] ?? null;
if (isset($opts['help']) || !$startUrl) {
    fwrite(STDERR, "Usage: php scrape.php <url> [--out=DIR] [--delay=1] [--limit=N] [--same-host] [--match=TEXT] [--timeout=30]\n"
                 . "                       [--extract] [--vpn-every=N] [--vpn-regions=us,ca] [--vpn-shuffle] [--openvpn=PATH]\n");
    exit(1);
}
if (!preg_match('#^https?://#i', $startUrl)) {
    $startUrl = 'https://' . $startUrl;
}

$outDir   = rtrim($opts['out'] ?? (getcwd() . '/output'), '/\\');
$delay    = (float)($opts['delay'] ?? 1);
$limit    = isset($opts['limit']) ? max(0, (int)$opts['limit']) : null;
$sameHost = isset($opts['same-host']);
$match    = $opts['match'] ?? null;
$timeout  = (int)($opts['timeout'] ?? 30);
$extract  = isset($opts['extract']);
$vpnEvery = max(0, (int)($opts['vpn-every'] ?? 0));

// Files the web page watches (only used in --job mode).
$logFile    = $job['log_file'] ?? null;
$statusFile = $job['status_file'] ?? null;
$stopFile   = $job['stop_file'] ?? null;

// ---------------------------------------------------------------- output helpers

/** Print a line, and add it to the job log for the web page. */
function say(string $msg): void
{
    global $logFile;
    echo $msg, "\n";
    if ($logFile) file_put_contents($logFile, date('H:i:s') . "  $msg\n", FILE_APPEND);
}

/** Update the status the web page shows. */
function status(array $changes): void
{
    global $statusFile;
    static $state = [];
    $state = array_merge($state, $changes, ['updated' => time()]);
    if ($statusFile) file_put_contents($statusFile, json_encode($state));
}

function stopRequested(): bool
{
    global $stopFile;
    return $stopFile && is_file($stopFile);
}

/** End the run: disconnect the VPN, record why, exit. */
function finish(string $state, string $message, int $code = 0): never
{
    global $vpn;
    if ($vpn) $vpn->disconnect();
    say($message);
    status(['state' => $state, 'message' => $message, 'server' => null]);
    exit($code);
}

status(['state' => 'starting', 'message' => 'Starting', 'done' => 0, 'total' => 0, 'pid' => getmypid(), 'started' => time()]);

if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    finish('error', "Could not create output folder: $outDir", 1);
}

// ---------------------------------------------------------------- page helpers

/** Download a URL. Returns [status, body, finalUrl, contentType] or throws. */
function fetch(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_ENCODING       => '',          // accept gzip/deflate
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PageCopier/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,*/*;q=0.8'],
        CURLOPT_FORBID_REUSE   => true,        // new connection each time, so a new IP takes effect
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($err);
    }
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $type     = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return [$status, $body, $finalUrl, $type];
}

/** Parse HTML into a DOMDocument without spamming warnings. */
function loadDom(string $html): DOMDocument
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    // Prefix forces UTF-8 when the page doesn't declare an encoding.
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $dom;
}

/** Turn a (possibly relative) href into an absolute URL. */
function absoluteUrl(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#') return null;
    if (preg_match('#^(mailto|tel|javascript|data|ftp):#i', $href)) return null;
    if (preg_match('#^https?://#i', $href)) return $href;

    $b = parse_url($base);
    $scheme = $b['scheme'] ?? 'https';
    $host   = $b['host'] ?? '';
    $port   = isset($b['port']) ? ':' . $b['port'] : '';
    $root   = "$scheme://$host$port";

    if (str_starts_with($href, '//')) return "$scheme:$href";
    if ($href[0] === '/') return $root . $href;
    if ($href[0] === '?') return $root . ($b['path'] ?? '/') . $href;

    // Relative path: resolve against the base page's directory.
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    $path = $dir . $href;
    $parts = [];
    $query = '';
    if (($q = strpos($path, '?')) !== false) {
        $query = substr($path, $q);
        $path = substr($path, 0, $q);
    }
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') array_pop($parts);
        elseif ($seg !== '.') $parts[] = $seg;
    }
    $resolved = implode('/', $parts);
    if ($resolved === '' || $resolved[0] !== '/') $resolved = '/' . $resolved;
    return $root . $resolved . $query;
}

/** Collect links from the page, in the order they appear, without duplicates. */
function extractLinks(string $html, string $pageUrl): array
{
    $dom = loadDom($html);
    $base = $pageUrl;
    $baseTag = $dom->getElementsByTagName('base')->item(0);
    if ($baseTag && $baseTag->getAttribute('href')) {
        $base = absoluteUrl($baseTag->getAttribute('href'), $pageUrl) ?? $pageUrl;
    }

    $links = [];
    foreach ($dom->getElementsByTagName('a') as $a) {
        $url = absoluteUrl($a->getAttribute('href'), $base);
        if (!$url) continue;
        $url = preg_replace('/#.*$/', '', $url);       // drop #fragment
        if (isset($links[$url])) continue;
        $text = trim(preg_replace('/\s+/', ' ', $a->textContent));
        $links[$url] = $text;
    }
    return $links; // url => link text
}

/** Pull the title and readable text out of a page. */
function pageText(string $html): array
{
    $dom = loadDom($html);
    foreach (['script', 'style', 'noscript', 'template'] as $tag) {
        $nodes = iterator_to_array($dom->getElementsByTagName($tag));
        foreach ($nodes as $n) $n->parentNode->removeChild($n);
    }
    $titleNode = $dom->getElementsByTagName('title')->item(0);
    $title = $titleNode ? trim(preg_replace('/\s+/', ' ', $titleNode->textContent)) : '';

    $body = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
    $text = $body ? $body->textContent : '';
    $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text);
    $text = preg_replace("/ *\n[ \n]*/", "\n", $text);   // collapse blank lines
    return [$title, trim($text)];
}

function slug(string $url): string
{
    $p = parse_url($url);
    $s = ($p['host'] ?? '') . ($p['path'] ?? '') . (isset($p['query']) ? '-' . $p['query'] : '');
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s));
    return substr(trim($s, '-'), 0, 80) ?: 'page';
}

/** Wait between requests, but notice the stop button quickly. */
function politePause(float $delay): void
{
    $end = microtime(true) + $delay;
    while (microtime(true) < $end && !stopRequested()) usleep(100000);
}

// ---------------------------------------------------------------- VPN setup
$vpn = null;
$pagesOnThisIp = 0;

/** Switch to the next VPN server and report the new IP. Ends the run if no server works. */
function switchIp(string $why): void
{
    global $vpn, $pagesOnThisIp;
    say("Changing IP ($why)");
    status(['state' => 'running', 'message' => 'Changing IP...', 'server' => null, 'ip' => null]);
    try {
        $server = $vpn->rotate();
    } catch (VpnAuthException $e) {
        finish('error', 'VPN: ' . $e->getMessage() . ' - check your PIA login.', 1);
    } catch (RuntimeException $e) {
        // Stop rather than carry on with your real IP.
        finish('error', 'VPN: ' . $e->getMessage() . ' - stopping so your real IP is not used.', 1);
    }
    $ip = PiaVpn::publicIp();
    say("VPN: connected to $server, IP is now $ip");
    status(['state' => 'running', 'message' => 'Copying pages', 'server' => $server, 'ip' => $ip]);
    $pagesOnThisIp = 0;
}

if ($vpnEvery > 0) {
    $user = getenv('PIA_USER') ?: '';
    $pass = getenv('PIA_PASS') ?: '';
    if (!empty($job['auth_file'])) {                       // written by the web page; read once, then delete
        $auth = json_decode((string)@file_get_contents($job['auth_file']), true) ?: [];
        @unlink($job['auth_file']);
        $user = $auth['user'] ?? '';
        $pass = $auth['pass'] ?? '';
    }
    if ($user === '' && !$job) { echo 'PIA username: '; $user = trim((string)fgets(STDIN)); }
    if ($pass === '' && !$job) { echo 'PIA password: '; $pass = trim((string)fgets(STDIN)); }

    try {
        $vpn = new PiaVpn([
            'openvpn'   => $opts['openvpn'] ?? PiaVpn::defaultOpenVpnPath(),
            'configDir' => $opts['vpn-dir'] ?? (__DIR__ . '/vpn/pia'),
            'regions'   => array_filter(array_map('trim', explode(',', (string)($opts['vpn-regions'] ?? 'us,ca')))),
            'shuffle'   => !empty($opts['vpn-shuffle']),
            'user'      => $user,
            'pass'      => $pass,
            'workDir'   => $job ? dirname($opts['job']) : sys_get_temp_dir(),
            'log'       => 'say',
        ]);
    } catch (RuntimeException $e) {
        finish('error', 'VPN: ' . $e->getMessage(), 1);
    }
    unset($user, $pass, $auth);
    say("VPN: will switch IP every $vpnEvery page(s), cycling through {$vpn->serverCount()} server(s)");
    switchIp('starting');
}

// ---------------------------------------------------------------- step 1: the starting page
say("Loading start page: $startUrl");
status(['state' => 'running', 'message' => 'Loading start page']);
try {
    [$code, $html, $startFinal] = fetch($startUrl, $timeout);
} catch (RuntimeException $e) {
    finish('error', "Could not load start page: {$e->getMessage()}", 1);
}
if ($code >= 400) {
    finish('error', "Start page returned HTTP $code", 1);
}
file_put_contents("$outDir/000-start-page.html", $html);

$links = extractLinks($html, $startFinal);
$startHost = strtolower(parse_url($startFinal, PHP_URL_HOST) ?? '');

$links = array_filter($links, function ($text, $url) use ($sameHost, $startHost, $match, $startFinal) {
    if ($url === $startFinal) return false;                                     // don't re-copy the start page
    if ($sameHost && strtolower(parse_url($url, PHP_URL_HOST) ?? '') !== $startHost) return false;
    if ($match !== null && $match !== true && stripos($url, $match) === false) return false;
    return true;
}, ARRAY_FILTER_USE_BOTH);

if ($limit !== null) {
    $links = array_slice($links, 0, $limit, true);
}

$total = count($links);
say("Found $total link(s) to follow.");
status(['total' => $total, 'message' => 'Copying pages']);
if ($total === 0) finish('done', 'Nothing to copy.');

// ---------------------------------------------------------------- step 2: follow each link in order
$csv = fopen("$outDir/index.csv", 'w');
fputcsv($csv, ['#', 'url', 'link_text', 'status', 'title', 'html_file', 'text_file', 'error']);

$n = 0;
foreach ($links as $url => $linkText) {
    if (stopRequested()) {
        fclose($csv);
        finish('stopped', "Stopped by user after $n of $total page(s).");
    }
    $n++;
    $num = str_pad((string)$n, 3, '0', STR_PAD_LEFT);

    // Time for a new IP? (Also reconnect if the VPN dropped.)
    if ($vpn) {
        if ($pagesOnThisIp >= $vpnEvery) switchIp("$vpnEvery page(s) done on this IP");
        elseif (!$vpn->isUp()) switchIp('VPN connection dropped');
    }

    say("[$n/$total] $url");
    status(['done' => $n - 1, 'current' => $url]);

    $error = null;
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        try {
            [$code, $body, $finalUrl, $type] = fetch($url, $timeout);
            $error = null;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        $pagesOnThisIp++;
        // Looks blocked? With the VPN on, switch IP and try this link once more.
        $blocked = $error !== null || in_array($code, [403, 429], true);
        if (!$blocked || !$vpn || $attempt === 2) break;
        say('    ' . ($error ? "failed: $error" : "HTTP $code (blocked?)") . ' - retrying on a new IP');
        switchIp('looked blocked');
    }

    if ($error !== null) {
        say("    ! failed: $error");
        fputcsv($csv, [$n, $url, $linkText, '', '', '', '', $error]);
        if ($n < $total) politePause($delay);
        continue;
    }

    $name = "$num-" . slug($finalUrl);
    $isHtml = $type === '' || stripos($type, 'html') !== false;

    if ($isHtml) {
        file_put_contents("$outDir/$name.html", $body);
        [$title, $text] = pageText($body);
        file_put_contents("$outDir/$name.txt", "URL: $finalUrl\nTitle: $title\n\n$text\n");
        say("    saved $name.html / .txt  (HTTP $code, \"$title\")");
        fputcsv($csv, [$n, $finalUrl, $linkText, $code, $title, "$name.html", "$name.txt", '']);
    } else {
        // Not a web page (PDF, image, zip...): save the file as-is.
        $ext = pathinfo(parse_url($finalUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'bin';
        file_put_contents("$outDir/$name.$ext", $body);
        say("    saved $name.$ext  (HTTP $code, $type)");
        fputcsv($csv, [$n, $finalUrl, $linkText, $code, '', "$name.$ext", '', '']);
    }
    status(['done' => $n]);

    // "Go back" to the start page's list and move to the next link.
    if ($n < $total) politePause($delay);
}

fclose($csv);
if ($vpn) $vpn->disconnect();
say("Done. Copied $n page(s) into $outDir (see index.csv).");

// ---------------------------------------------------------------- optional: pull out the questions
if ($extract) {
    $qDir = $job['questions_dir'] ?? (dirname($outDir) . DIRECTORY_SEPARATOR . 'questions');
    say("Extracting questions into $qDir ...");
    status(['message' => 'Extracting questions']);
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/extract-questions.php', "--in=$outDir", "--out=$qDir"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (is_resource($proc)) {
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        foreach (array_filter(explode("\n", rtrim($output))) as $line) say(rtrim($line));
    }
}

finish('done', "Finished: $n page(s) copied.");
