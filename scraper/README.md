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

Run this after `scrape.php`. It reads every `.html` file in the output folder,
finds the **Quiz Answer Key and Fun Facts** section, and pulls out each
question, its answer and its fun fact as plain text. Everything else on the
page is dropped.

```bash
php scraper/extract-questions.php
```

| Option | What it does |
| --- | --- |
| `--in=DIR` | Folder of `.html` files to read (default `./output`) |
| `--out=DIR` | Folder to write results to (default `./questions`) |
| `--all=FILE` | Name of the combined file (default `all-questions.txt`) |

How it reads a page:

- It starts at the heading `Quiz Answer Key and Fun Facts`; anything above it
  (menus, the quiz form itself) is ignored.
- Each question is the line starting `1.`, `2.`, …, followed by `Answer: …`
  and then the fun fact, up to the next question.
- It stops at `Source: Author`. If a page has no such line, it stops at the
  usual page footer (`Related Quizzes`, `Most Recent Scores`, …).

Output, one question per block:

```
1. Where was the first Larry David and Jerry Seinfeld?

Answer: Diner
The first script for "Seinfeld" was written at Westway Diner ...
```

- `questions/<Quiz Title>.txt`: one file per quiz, named from the page title
  with the clutter removed (`Seinfeld Trivia Quiz | TV | 10 Questions` becomes
  `Seinfeld`). Pages with the same cleaned title are merged into one file,
  without repeating questions.
- `questions/all-questions.txt`: every question from every quiz in one file,
  no titles, numbered 1, 2, 3 … straight through.
- Pages with no answer key (like the starting page) are skipped and listed
  when the script runs.
