<?php
/**
 * Link follower / page copier.
 *
 * Give it a starting URL. It loads that page, collects every link on it
 * (top to bottom), then visits each link in order and saves a copy of that
 * page. After each page it "goes back" to the list from the starting page and
 * moves on to the next link, until every link has been copied.
 *
 * Usage:
 *   php scrape.php <url> [options]
 *
 * Options:
 *   --out=DIR         Where to save copies (default: ./output)
 *   --delay=SECONDS   Pause between requests, be polite (default: 1)
 *   --limit=N         Only follow the first N links (default: all)
 *   --same-host       Only follow links on the same website as the start URL
 *   --match=TEXT      Only follow links whose URL contains TEXT
 *   --timeout=SECONDS Per-request timeout (default: 30)
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
$unknown = array_diff(array_keys($opts), ['out', 'delay', 'limit', 'same-host', 'match', 'timeout', 'help']);
if ($unknown) {
    fwrite(STDERR, 'Unknown option(s): --' . implode(', --', $unknown) . "\n");
    exit(1);
}
$startUrl = $args[0] ?? null;

if (isset($opts['help']) || !$startUrl) {
    fwrite(STDERR, "Usage: php scrape.php <url> [--out=DIR] [--delay=1] [--limit=N] [--same-host] [--match=TEXT] [--timeout=30]\n");
    exit(1);
}
if (!preg_match('#^https?://#i', $startUrl)) {
    $startUrl = 'https://' . $startUrl;
}

$outDir   = rtrim($opts['out'] ?? (getcwd() . '/output'), '/');
$delay    = (float)($opts['delay'] ?? 1);
$limit    = isset($opts['limit']) ? max(0, (int)$opts['limit']) : null;
$sameHost = isset($opts['same-host']);
$match    = $opts['match'] ?? null;
$timeout  = (int)($opts['timeout'] ?? 30);

if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    exit("Could not create output folder: $outDir\n");
}

// ---------------------------------------------------------------- helpers

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

// ---------------------------------------------------------------- step 1: the starting page
echo "Loading start page: $startUrl\n";
try {
    [$status, $html, $startFinal] = fetch($startUrl, $timeout);
} catch (RuntimeException $e) {
    exit("Could not load start page: {$e->getMessage()}\n");
}
if ($status >= 400) {
    exit("Start page returned HTTP $status\n");
}
file_put_contents("$outDir/000-start-page.html", $html);

$links = extractLinks($html, $startFinal);
$startHost = strtolower(parse_url($startFinal, PHP_URL_HOST) ?? '');

$links = array_filter($links, function ($text, $url) use ($sameHost, $startHost, $match, $startFinal) {
    if ($url === $startFinal) return false;                                     // don't re-copy the start page
    if ($sameHost && strtolower(parse_url($url, PHP_URL_HOST) ?? '') !== $startHost) return false;
    if ($match !== null && stripos($url, $match) === false) return false;
    return true;
}, ARRAY_FILTER_USE_BOTH);

if ($limit !== null) {
    $links = array_slice($links, 0, $limit, true);
}

$total = count($links);
echo "Found $total link(s) to follow.\n\n";
if ($total === 0) exit(0);

// ---------------------------------------------------------------- step 2: follow each link in order
$csv = fopen("$outDir/index.csv", 'w');
fputcsv($csv, ['#', 'url', 'link_text', 'status', 'title', 'html_file', 'text_file', 'error']);

$n = 0;
foreach ($links as $url => $linkText) {
    $n++;
    $num = str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    echo "[$n/$total] $url\n";

    try {
        [$status, $body, $finalUrl, $type] = fetch($url, $timeout);
    } catch (RuntimeException $e) {
        echo "    ! failed: {$e->getMessage()}\n";
        fputcsv($csv, [$n, $url, $linkText, '', '', '', '', $e->getMessage()]);
        if ($n < $total) usleep((int)($delay * 1e6));
        continue;
    }

    $name = "$num-" . slug($finalUrl);
    $isHtml = $type === '' || stripos($type, 'html') !== false;

    if ($isHtml) {
        file_put_contents("$outDir/$name.html", $body);
        [$title, $text] = pageText($body);
        file_put_contents("$outDir/$name.txt", "URL: $finalUrl\nTitle: $title\n\n$text\n");
        echo "    saved $name.html / .txt  (HTTP $status, \"$title\")\n";
        fputcsv($csv, [$n, $finalUrl, $linkText, $status, $title, "$name.html", "$name.txt", '']);
    } else {
        // Not a web page (PDF, image, zip...): save the file as-is.
        $ext = pathinfo(parse_url($finalUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'bin';
        file_put_contents("$outDir/$name.$ext", $body);
        echo "    saved $name.$ext  (HTTP $status, $type)\n";
        fputcsv($csv, [$n, $finalUrl, $linkText, $status, '', "$name.$ext", '', '']);
    }

    // "Go back" to the start page's list and move to the next link.
    if ($n < $total) usleep((int)($delay * 1e6));
}

fclose($csv);
echo "\nDone. Copied $n page(s) into $outDir (see index.csv).\n";
