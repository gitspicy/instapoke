<?php
/**
 * Quiz question extractor.
 *
 * Reads every .txt file that scrape.php saved, finds the quiz questions and
 * their answer choices, and throws away everything else on the page.
 *
 * Usage:
 *   php extract-questions.php [options]
 *
 * Options:
 *   --in=DIR        Folder of .txt files to read (default: ./output)
 *   --out=DIR       Folder to write the cleaned files to (default: ./questions)
 *   --all=FILE      Name of the combined file inside --out (default: all-questions.txt)
 *
 * Output:
 *   questions/<Quiz Title>.txt   one cleaned file per unique quiz title
 *   questions/all-questions.txt  every question from every quiz, one after another,
 *                                numbered continuously (1, 2, 3 ... no titles, no breaks)
 *
 * Expected page layout (as text):
 *   Question 1 of 10
 *   1. What magazine does TIM find in his parents' SHOES?
 *   Hint
 *   Maxime
 *   Victoria's Secret
 *   ...
 *   NEXT>
 *   Question 2 of 10
 *   ...
 *   (Optional) Create a Free FunTrivia ID ...   <- end of the last question
 */

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line: php extract-questions.php\n");
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

// ---------------------------------------------------------------- helpers

/** Lines that mean "the answer list for this question is over". */
function isStopLine(string $line): bool
{
    return (bool)preg_match(
        '/^(NEXT\s*>?|Question\s+\d+\s+of\s+\d+|\(Optional\)\s*Create a Free|Select a User ID|Share on |Most Recent Scores)/i',
        $line
    );
}

/** Get the page title from the "Title:" line scrape.php writes at the top. */
function pageTitle(array $lines, string $file): string
{
    foreach (array_slice($lines, 0, 5) as $line) {
        if (preg_match('/^Title:\s*(.+)$/', $line, $m)) return trim($m[1]);
    }
    return pathinfo($file, PATHINFO_FILENAME);
}

/**
 * Turn a page title into a clean file name, e.g.
 *   "Tim's Shoes Trivia Quiz | Kids TV | 10 Questions"  ->  "Tim's Shoes"
 */
function cleanTitle(string $title): string
{
    $t = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_split('/\s+[|\-–—:]\s+/u', $t)[0];                // keep the part before " | " / " - "
    $t = preg_replace('/^\s*fun\s*trivia\s*:?\s*/i', '', $t);           // leading site name
    $t = preg_replace('/\s*\b(trivia\s+quiz|quiz|trivia)\s*$/i', '', $t); // trailing "Trivia Quiz"
    $t = str_replace(':', ' -', $t);
    $t = preg_replace('/[<>"\/\\\\|?*\x00-\x1F]/', '', $t);      // characters Windows can't use in names
    $t = trim(preg_replace('/\s+/', ' ', $t), " .-_");
    return $t !== '' ? mb_substr($t, 0, 120) : 'Untitled';
}

/**
 * Pull the questions out of one page.
 * Returns a list of ['q' => 'question text', 'a' => ['answer', ...]].
 */
function extractQuestions(array $lines): array
{
    // Find "Question 1 of N" to learn how many questions there are and where they start.
    $start = null;
    $total = 0;
    foreach ($lines as $i => $line) {
        if (preg_match('/^Question\s+1\s+of\s+(\d+)/i', $line, $m)) {
            $start = $i;
            $total = (int)$m[1];
            break;
        }
    }
    if ($start === null || $total < 1) return [];

    $questions = [];
    $i = $start;
    $count = count($lines);

    for ($n = 1; $n <= $total; $n++) {
        // Find the line "n. question text".
        while ($i < $count && !preg_match('/^' . $n . '\.\s+(.+)$/', $lines[$i], $m)) {
            if (stripos($lines[$i], '(Optional) Create a Free') === 0) break 2; // ran off the end
            $i++;
        }
        if ($i >= $count) break;
        $question = trim($m[1]);
        $i++;

        // Collect answers until we hit NEXT>, the next question, or the end-of-quiz marker.
        $answers = [];
        while ($i < $count) {
            $line = $lines[$i];
            if (isStopLine($line) || preg_match('/^' . ($n + 1) . '\.\s+/', $line)) break;
            if (!preg_match('/^Hint$/i', $line)) $answers[] = $line;
            $i++;
        }
        $questions[] = ['q' => $question, 'a' => $answers];
    }
    return $questions;
}

/** Format questions as text, numbering from $startAt. */
function formatQuestions(array $questions, int $startAt = 1): string
{
    $out = '';
    foreach ($questions as $k => $item) {
        $out .= ($startAt + $k) . '. ' . $item['q'] . "\n";
        foreach ($item['a'] as $answer) $out .= $answer . "\n";
        $out .= "\n";
    }
    return $out;
}

// ---------------------------------------------------------------- read every .txt file
$files = glob($inDir . DIRECTORY_SEPARATOR . '*.txt');
sort($files);
if (!$files) {
    exit("No .txt files found in $inDir\n");
}

$quizzes = [];   // clean title => list of questions (unique by question text)
$skipped = 0;

foreach ($files as $file) {
    $raw = file_get_contents($file);
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);                   // strip BOM
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\R/u', $raw)),
        fn($l) => $l !== ''
    ));

    $title = cleanTitle(pageTitle($lines, $file));
    $questions = extractQuestions($lines);

    if (!$questions) {
        echo "  - skipped (no questions found): " . basename($file) . "\n";
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

    echo "  + " . basename($file) . "  ->  \"$title\"  ($added question(s))\n";
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
