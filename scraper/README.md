# Page copier (`scrape.php`)

Give it a URL. It reads that page, finds every link on it (top to bottom),
then visits each link one at a time and saves a copy of that page, going
back to the starting page's list for the next link until all are done.

Needs PHP 8+ with the `curl` and `dom` extensions (included in most installs).

```bash
php scraper/scrape.php https://example.com/some-page
```

Options (before or after the URL):

| Option | What it does |
| --- | --- |
| `--out=DIR` | Folder to save into (default `./output`) |
| `--delay=SECONDS` | Wait between pages (default `1`, be polite) |
| `--limit=N` | Only follow the first N links |
| `--same-host` | Skip links that go to other websites |
| `--match=TEXT` | Only follow links whose URL contains TEXT |
| `--timeout=SECONDS` | Give up on a page after this long (default `30`) |

Output:

- `000-start-page.html`: the page you started from
- `001-<name>.html`: the full raw HTML of each linked page
- `001-<name>.txt`: the readable text of that page (title + body text, no scripts/styles)
- non-HTML links (PDFs, images, etc.) are saved as-is with their own extension
- `index.csv`: one row per link: number, URL, link text, HTTP status, title, files, error

Skipped automatically: `#anchor` links, `mailto:`/`tel:`/`javascript:` links,
duplicate links, and links back to the start page itself.

Note: pages that build their content with JavaScript after loading will only
have their initial HTML copied, since this script doesn't run a browser.

# Question extractor (`extract-questions.php`)

Run this after `scrape.php`. It reads every `.txt` file in the output folder,
pulls out only the quiz questions and their answer choices, and drops
everything else on the page.

```bash
php scraper/extract-questions.php
```

| Option | What it does |
| --- | --- |
| `--in=DIR` | Folder of `.txt` files to read (default `./output`) |
| `--out=DIR` | Folder to write results to (default `./questions`) |
| `--all=FILE` | Name of the combined file (default `all-questions.txt`) |

How it reads a page:

- `Question 1 of N` tells it how many questions to look for.
- Each question is the line starting `1.`, `2.`, … and the answers are the lines
  after it, up to `NEXT>`. `Hint` lines are dropped.
- The last question ends at `(Optional) Create a Free FunTrivia ID…`, so a
  True/False question gets 2 answers and a multiple-choice one gets 4.

Output:

- `questions/<Quiz Title>.txt`: one cleaned file per quiz. The name comes from
  the page title, cleaned up (e.g. `Tim's Shoes Trivia Quiz | Kids TV` becomes
  `Tim's Shoes`). Pages with the same cleaned title are merged into one file,
  without repeating questions.
- `questions/all-questions.txt`: every question from every quiz in one file,
  no titles, numbered 1, 2, 3 … straight through.
- Pages with no questions are skipped and listed when the script runs.
