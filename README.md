# Columbus City Bulletins RSS

**[-> Subscribe to the feed <-](./feed.xml)**

A weekly scraper for the [Columbus City Bulletins page](https://www.columbus.gov/Government/City-Council/City-Bulletins), which records bulletin links in [a CSV](./data/links.csv) and publishes an RSS feed with original links, and Wayback Machine links when possible.

Why does this exist? Because the Columbus City Bulletin is buried in a corner of the City website which no one will go to. The first step to making this easier for people to find is to make it available for their tools to find.

In time, there might even be a Bluesky bot tooting these updates when they're made.

## How It Works

1. Fetch [the City Bulletins page](https://www.columbus.gov/Government/City-Council/Meeting-Schedules-Agendas/City-Bulletins) and read bulletin links within `div#main-content`.
2. Extract and deduplicate city PDF URLs and record issue metadata in the CSV.
3. Check up to 10 outstanding Wayback records. Record existing captures confirmed by the availability API and request new captures when Internet Archive credentials are configured.
4. Generate [an RSS feed](./feed.xml) of items.
5. If anything changed, the workflow commits and pushes the updated CSV and feed back to the repository.

## Feed

The RSS feed is available at:

- https://raw.githubusercontent.com/benlk/engage-columbus-scrape-to-rss/refs/heads/trunk/feed.xml
- https://benlk.github.io/engage-columbus-scrape-to-rss/feed.xml

RSS item fields:

| Field | Source |
|---|---|
| `<title>` | Bulletin link text |
| `<link>` / `<guid>` | Original PDF URL |
| `<pubDate>` | Publication date, or discovery time when unavailable |
| `<description>` | Link to the confirmed Wayback capture, when available |

The feed contains up to 500 items, sorted by publication date, or by discovery time when the date is unavailable.

## Data

`data/links.csv` is the source of truth for all discovered URLs. Its fields include:

| Column | Description |
|---|---|
| `url` | Original normalized PDF URL (primary key) |
| `title` | Bulletin link text |
| `issue_number` | Issue number from the listing, when available |
| `publication_date` | Publication date from the listing, when available |
| `first_seen_utc` | UTC discovery timestamp |
| `run_id` | Timestamp of the run that discovered the URL |
| `source` | `live` and/or Wayback listing snapshot timestamps, separated by `|` |
| `wayback_url` | Confirmed archived PDF URL, or empty |
| `archive_status` | Current capture or retry state |
| `archive_job_id` | Pending Save Page Now job ID |
| `archive_attempts` | Number of capture submission attempts |
| `archive_retry_after` | Earliest UTC time for another archive check |

## Schedule

The workflow is scheduled for Mondays at 6:00 a.m. Eastern Time and also runs on pushes. To run it manually:

1. Go to **Actions** and select **Scrape Columbus City Bulletins**.
2. Click **Run workflow**.

Only one run executes at a time (concurrency lock). A run that finds no new URLs produces no commit.

For Save Page Now submissions, configure `IA_ACCESS_KEY` and `IA_SECRET_KEY` as GitHub Actions secrets using Internet Archive account access keys. Without them, the scraper still discovers PDFs and checks existing captures; it records missing credentials and retries later. Pending jobs, missing captures and rate limits may delay archive links.

## AI usage statement

This was almost entirely vibe-coded, using Github Copilot. The decision to use Copilot was made in order to see what's possible with a from-scratch project.

Lessons learned:

- High-level project descriptions can be converted to functional requirements documents pretty easily.
- Copilot defaults to Python for building Github Action scrapers.
- Copilot defaults to PSR-12 when writing PHP.
- Copilot can be convinced to obey your `phpcs.xml`, in time.
- First-pass generated code often contains a number of archaicisms and bugs, which are only picked up after test-running the code and viewing the logs.
- First-pass generated `composer.json` and GitHub Actions _frequently_ refer to out-of-date versions of packages, and must be manually verified for current-ness. This is no doubt because of outdated training data.

## License

Because of the AI usage, I've chosen to license this in the public domain, under [The Unlicense](https://unlicense.org/).

## Local Development

### Run the Scraper

```bash
# Destructively reset the scaffold's old CSV and RSS feed once.
php main.php --initialize
# Discover the current bulletin listing.
php main.php
# Import the historical listing snapshots once; this command is repeatable.
php main.php --backfill

# Then diff, then commit changes to the scraped URLs.
```

Requires PHP 8.5 with cURL, DOM and SimpleXML. Optional environment variables `IA_ACCESS_KEY` and `IA_SECRET_KEY` enable Save Page Now.

Run the offline checks with `composer run tests` and `composer run lint`.

### Build the site

1. `composer install`
2. `composer run build`
3. `php -S localhost:8000 -t _site/` to start a local server in that folder

GitHub Pages is deployed from the `trunk` branch by GitHub Actions.
