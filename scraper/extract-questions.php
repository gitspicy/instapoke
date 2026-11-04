<?php
/**
 * Quiz answer-key extractor.
 *
 * Reads every .html file that scrape.php saved, finds the
 * "Quiz Answer Key and Fun Facts" section, and pulls out each question,
 * its answer and its fun fact as plain text. Everything else on the page
 * is thrown away.
 *
 * Usage:
 *   php extract-questions.php [options]
 *
 * Options:
 *   --in=DIR     Folder of .html files to read (default: ./output)
 *   --out=DIR    Folder to write the cleaned files to (default: ./questions)
 *   --all=FILE   Name of the combined file inside --out (default: all-questions.txt)
 *
 * Output:
 *   questions/<Quiz Title>.txt   one cleaned file per unique quiz title
 *   questions/all-questions.txt  every question from every quiz, one after another,
 *                                numbered continuously (1, 2, 3 ... no titles, no breaks)
 *
 * Expected layout of the answer key (as it reads on the page):
 *   Quiz Answer Key and Fun Facts
 *   1. Where was the first Larry David and Jerry Seinfeld?
 *   Answer: Diner
 *   The first script for "Seinfeld" was written at ...
 *   2. Whose off-the-cuff remark ...
 *   Answer: Julia Louis Dreyfus
 *   ...
 *   Source: Author ...          <- end of the answer key
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line: php extract-questions.php\n");
}
if (!extension_loaded('dom')) {
    exit("This script needs the PHP 'dom' extension.\n");
}

// ---------------------------------------------------------------- arguments
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        fwrite(STDERR, "Unexpected argument: $arg\n");
        exit(1);
    }
}
if (isset($opts['help'])) {
    fwrite(STDERR, "Usage: php extract-questions.php [--in=output] [--out=questions] [--all=all-questions.txt]\n");
    exit(0);
}
$unknown = array_diff(array_keys($opts), ['in', 'out', 'all', 'help']);
if ($unknown) {
    fwrite(STDERR, 'Unknown option(s): --' . implode(', --', $unknown) . "\n");
    exit(1);
}

$inDir   = rtrim($opts['in'] ?? (getcwd() . '/output'), '/\\');
$outDir  = rtrim($opts['out'] ?? (getcwd() . '/questions'), '/\\');
$allName = $opts['all'] ?? 'all-questions.txt';

if (!is_dir($inDir)) {
    exit("Input folder not found: $inDir\n");
}
if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    exit("Could not create output folder: $outDir\n");
}

// ---------------------------------------------------------------- HTML -> lines of text

const BLOCK_TAGS = [
    'address', 'article', 'aside', 'blockquote', 'center', 'dd', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'tbody',
    'td', 'th', 'thead', 'tr', 'ul', 'body', 'html',
];
const SKIP_TAGS = ['script', 'style', 'noscript', 'template', 'svg', 'head', 'iframe', 'select', 'option', 'button'];

/** Parse HTML, honouring the page's declared character set. */
function loadDom(string $html): DOMDocument
{
    $charset = 'UTF-8';
    if (preg_match('/<meta[^>]+charset=["\']?\s*([\w-]+)/i', $html, $m)) {
        $charset = strtoupper($m[1]);
    }
    if ($charset !== 'UTF-8' && in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
        $html = mb_convert_encoding($html, 'UTF-8', $charset);
    } elseif (!mb_check_encoding($html, 'UTF-8')) {
        $html = mb_convert_encoding($html, 'UTF-8', 'Windows-1252');
    }
    // The text is UTF-8 now; stop the page's own charset tag from re-decoding it.
    $html = preg_replace('/(<meta[^>]+charset=["\']?\s*)[\w-]+/i', '${1}UTF-8', $html);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $dom;
}

/** Walk the page and write out its visible text, one line per block / <br>. */
function nodeText(DOMNode $node, string &$out): void
{
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMText) {
            $out .= preg_replace('/\s+/u', ' ', $child->data);
        } elseif ($child instanceof DOMElement) {
            $tag = strtolower($child->tagName);
            if (in_array($tag, SKIP_TAGS, true)) continue;
            if ($tag === 'br') { $out .= "\n"; continue; }
            $block = in_array($tag, BLOCK_TAGS, true);
            if ($block) $out .= "\n";
            nodeText($child, $out);
            if ($block) $out .= "\n";
        }
    }
}

/** Returns [page title, array of non-empty trimmed text lines]. */
function htmlToLines(string $html): array
{
    $dom = loadDom($html);
    $titleNode = $dom->getElementsByTagName('title')->item(0);
    $title = $titleNode ? trim(preg_replace('/\s+/u', ' ', $titleNode->textContent)) : '';

    $text = '';
    nodeText($dom, $text);
    $text = str_replace("\u{00A0}", ' ', $text);
    $lines = array_values(array_filter(
        array_map('trim', explode("\n", $text)),
        fn($l) => $l !== ''
    ));
    return [$title, $lines];
}

// ---------------------------------------------------------------- parsing

/**
 * Turn a page title into a clean file name, e.g.
 *   "Seinfeld Trivia Quiz | TV Sitcoms | 10 Questions"  ->  "Seinfeld"
 */
function cleanTitle(string $title): string
{
    $t = preg_split('/\s+[|\-–—]\s+/u', $title)[0];                     // keep the part before " | " / " - "
    $t = preg_replace('/^\s*fun\s*trivia\s*:?\s*/i', '', $t);           // leading site name
    $t = preg_replace('/\s*\b(trivia\s+quiz|quiz|trivia)\s*$/i', '', $t); // trailing "Trivia Quiz"
    $t = str_replace(':', ' -', $t);
    $t = preg_replace('/[<>"\/\\\\|?*\x00-\x1F]/', '', $t);              // characters Windows can't use in names
    $t = trim(preg_replace('/\s+/u', ' ', $t), " .-_");
    return $t !== '' ? mb_substr($t, 0, 120) : 'Untitled';
}

/** "Source: Author ..." marks the end of the answer key. */
function isEndLine(string $line): bool
{
    return (bool)preg_match('/^Source:\s*Author\b/i', $line);
}

/** Fallback end markers, used only if a page has no "Source: Author" line. */
function isFooterLine(string $line): bool
{
    return (bool)preg_match(
        '/^(This quiz was reviewed|Related Quizzes|Most Recent Scores|Share on |\(Optional\) Create a Free|Copyright|Go to Top|Return to)/i',
        $line
    );
}

/**
 * Pull the questions out of the "Quiz Answer Key and Fun Facts" section.
 * Returns a list of ['q' => question, 'a' => answer, 'f' => fun fact].
 */
function extractAnswerKey(array $lines): array
{
    $count = count($lines);

    // 1. Find the answer key heading.
    $i = 0;
    while ($i < $count && stripos($lines[$i], 'Quiz Answer Key and Fun Facts') === false) $i++;
    if ($i >= $count) return [];
    $i++;

    $questions = [];
    $n = 1;

    while ($i < $count) {
        // 2. Find the line "n. question text" (only look a little way ahead after the first one).
        $limit = $n === 1 ? $count : min($count, $i + 3);
        $found = false;
        for (; $i < $limit; $i++) {
            if (isEndLine($lines[$i])) break;
            if (preg_match('/^' . $n . '\.\s*(.+)$/u', $lines[$i], $m)) { $found = true; break; }
        }
        if (!$found) break;
        $question = trim($m[1]);
        $i++;

        // 3. Anything before "Answer:" is the rest of a question that wrapped onto more lines.
        while ($i < $count && !preg_match('/^Answer:/i', $lines[$i])
               && !preg_match('/^' . ($n + 1) . '\.\s/', $lines[$i]) && !isEndLine($lines[$i])) {
            $question .= ' ' . $lines[$i];
            $i++;
        }

        // 4. The answer.
        $answer = '';
        if ($i < $count && preg_match('/^Answer:\s*(.*)$/i', $lines[$i], $m)) {
            $answer = trim($m[1]);
            $i++;
            if ($answer === '' && $i < $count) {          // "Answer:" alone, answer on the next line
                $answer = $lines[$i];
                $i++;
            }
        }

        // 5. The fun fact: everything up to the next question or the end marker.
        $fact = [];
        while ($i < $count) {
            $line = $lines[$i];
            if (isEndLine($line) || isFooterLine($line) || preg_match('/^' . ($n + 1) . '\.\s/', $line)) break;
            $fact[] = $line;
            $i++;
        }

        $questions[] = ['q' => $question, 'a' => $answer, 'f' => implode("\n", $fact)];

        // "Source: Author" ends the key, unless another numbered question follows right after it.
        if ($i < $count && (isEndLine($lines[$i]) || isFooterLine($lines[$i]))) {
            $i++;
            if (!($i < $count && preg_match('/^' . ($n + 1) . '\.\s/', $lines[$i]))) break;
        }
        $n++;
    }
    return $questions;
}

/** Format questions as text, numbering from $startAt. */
function formatQuestions(array $questions, int $startAt = 1): string
{
    $out = '';
    foreach ($questions as $k => $item) {
        $out .= ($startAt + $k) . '. ' . $item['q'] . "\n\n";
        $out .= 'Answer: ' . $item['a'] . "\n";
        if ($item['f'] !== '') $out .= $item['f'] . "\n";
        $out .= "\n";
    }
    return $out;
}

// ---------------------------------------------------------------- read every .html file
$files = array_merge(
    glob($inDir . DIRECTORY_SEPARATOR . '*.html') ?: [],
    glob($inDir . DIRECTORY_SEPARATOR . '*.htm') ?: []
);
sort($files);
if (!$files) {
    exit("No .html files found in $inDir\n");
}

$quizzes = [];   // clean title => list of questions (unique by question text)
$skipped = 0;

foreach ($files as $file) {
    [$pageTitle, $lines] = htmlToLines(file_get_contents($file));
    $title = cleanTitle($pageTitle !== '' ? $pageTitle : pathinfo($file, PATHINFO_FILENAME));
    $questions = extractAnswerKey($lines);

    if (!$questions) {
        echo "  - skipped (no answer key found): " . basename($file) . "\n";
        $skipped++;
        continue;
    }

    // Same quiz title seen before? Merge, keeping only questions we don't already have.
    $existing = $quizzes[$title] ?? [];
    $seen = array_flip(array_map(fn($q) => mb_strtolower($q['q']), $existing));
    $added = 0;
    foreach ($questions as $q) {
        $key = mb_strtolower($q['q']);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $existing[] = $q;
        $added++;
    }
    $quizzes[$title] = $existing;

    $noAnswer = count(array_filter($questions, fn($q) => $q['a'] === ''));
    echo "  + " . basename($file) . "  ->  \"$title\"  ($added question(s)"
        . ($noAnswer ? ", $noAnswer without an answer" : '') . ")\n";
}

// ---------------------------------------------------------------- write the cleaned files
$all = '';
$number = 1;
foreach ($quizzes as $title => $questions) {
    file_put_contents($outDir . DIRECTORY_SEPARATOR . $title . '.txt', formatQuestions($questions));
    $all .= formatQuestions($questions, $number);
    $number += count($questions);
}
file_put_contents($outDir . DIRECTORY_SEPARATOR . $allName, $all);

$totalQuestions = $number - 1;
echo "\nDone. " . count($quizzes) . " unique quiz(zes), $totalQuestions question(s) total";
echo $skipped ? ", $skipped file(s) skipped" : '';
echo ".\nCleaned files are in $outDir, everything combined in $allName\n";
