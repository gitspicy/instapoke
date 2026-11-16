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

# Web control panel with VPN IP switching

A page for running the copier from your browser. It changes IP address every
N pages by cycling through the Private Internet Access (PIA) **US and Canada**
servers with OpenVPN. The PIA config files are in `scraper/vpn/pia/`.

## One-time setup (Windows + XAMPP)

1. **Install OpenVPN** (the free "Community" installer from openvpn.net, with the
   default options). It goes to `C:\Program Files\OpenVPN\bin\openvpn.exe`.
2. **Quit the PIA app** (disconnect, then right-click its tray icon and choose Exit),
   so it doesn't fight with OpenVPN or block traffic with its kill switch.
3. **Run XAMPP as administrator.** OpenVPN needs administrator rights to connect.
   Close XAMPP, right-click **XAMPP Control Panel**, choose **Run as administrator**,
   then start Apache. The page warns you if this is missing.

## Using it

Open **http://localhost/instapoke/scraper/web/**

1. **PIA login:** enter your PIA username (`p1234567`) and password and press Save login.
2. **New job:** the start URL, **pages per IP** (e.g. 10; 0 turns the VPN off),
   seconds between pages, and which servers to use (US, Canada, random order).
   Tick **Extract questions when finished** to run the question extractor at the end.
3. Press **Start**. The Progress box shows the page count, current server, current
   IP and a live log. **Stop** ends the job after the current page.
   **Disconnect VPN now** drops the VPN if something gets stuck.

What it does:

- Connects to the first server before loading the start page, then switches to
  the next server after every N pages.
- If a page fails or the site answers 403/429 (blocked / too many requests),
  it switches IP straight away and retries that page once.
- If a server won't connect it tries the next one. If 5 in a row fail, or PIA
  rejects the login, the job **stops** rather than carrying on with your real IP.
- The VPN is disconnected when the job finishes or is stopped.

Things to know:

- While a job runs, the **whole PC's** internet traffic goes through the VPN,
  not only the copier's.
- The page only works from the computer it runs on (`localhost`).
- Your PIA login is kept in the PHP session (a file in `C:\xampp\tmp`) until you
  press Log out. The background job gets it in a file it deletes as soon as it
  reads it, and OpenVPN's copy is deleted once the connection is up.
- The page only allows one job at a time. Its log and status are in `scraper/jobs/`.

## Same thing from the command line

From a PowerShell window opened with **Run as administrator**:

```bash
C:\xampp\php\php.exe scraper\scrape.php https://example.com/page --vpn-every=10 --extract
```

It asks for your PIA username and password. Other VPN options:
`--vpn-regions=us,ca`, `--vpn-shuffle`, `--openvpn=PATH`, `--vpn-dir=DIR`.

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
