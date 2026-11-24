<?php
/**
 * CLI entrypoint for bulletin scraping and feed generation.
 */

declare(strict_types=1);

require_once __DIR__ . '/scraper/fetch.php';
require_once __DIR__ . '/scraper/parse.php';
require_once __DIR__ . '/scraper/normalize.php';
require_once __DIR__ . '/scraper/store.php';
require_once __DIR__ . '/scraper/feed.php';
require_once __DIR__ . '/scraper/archive.php';

const LINKS_CSV_PATH = __DIR__ . '/data/links.csv';
const FEED_PATH      = __DIR__ . '/feed.xml';

/**
 * Return the current UTC timestamp in ISO 8601 format.
 *
 * @return string UTC timestamp.
 */
function utc_timestamp(): string {
	return gmdate( 'Y-m-d\\TH:i:s\\Z' );
}

/**
 * Execute a live scrape or a one-time historical import.
 *
 * @param  bool $backfill Whether to import the historical snapshots.
 * @return int Process exit code.
 */
function run_scraper( bool $backfill = false ): int {
	$run_id = utc_timestamp();
	fwrite( STDOUT, sprintf( "Run started: %s\n", $run_id ) );
	$sources = $backfill ? [
		['20260508214416', '2023-01-01', '2026-05-08'],
		['20260827204335', '2026-05-09', '2026-08-31'],
		['20261023185538', '2026-09-01', '2026-10-31'],
	] : [['live', '', '']];
	$discovered = [];
	foreach ( $sources as [$source, $start, $end] ) {
		$url = $source === 'live' ? BULLETIN_PAGE_URL : 'https://web.archive.org/web/' . $source . '/' . BULLETIN_PAGE_URL;
		$content = fetch_url( $url );
		if ($content === null ) {
			throw new RuntimeException( 'Unable to fetch listing: ' . $source );
		}
		try {
			$entries = parse_bulletins( $content );
		} catch ( RuntimeException $error ) {
			if (file_put_contents( __DIR__ . '/scraper-output.html', $content ) === false ) {
				fwrite( STDERR, "Unable to save the fetched listing to scraper-output.html.\n" );
			} else {
				fwrite( STDERR, "Fetched listing saved to scraper-output.html (source: $source).\n" );
			}
			throw $error;
		}
		foreach ( $entries as $entry ) {
			$original = normalize_url( $entry['url'] );
			if ($original === null ) {
				continue;
			}
			$date = $entry['publication_date'];
			if ($backfill && ( $date === '' || $date < $start || $date > $end ) ) {
				if ($date === '' ) {
					fwrite( STDERR, "Historical bulletin has no recognizable date: $original\n" );
				}
				continue;
			}
			$entry['url'] = $original;
			$entry['source'] = $source;
			$discovered[] = $entry;
		}
	}
	if (count( $discovered ) === 0 ) {
		throw new RuntimeException( 'No valid bulletin PDF URLs were discovered.' );
	}
	$records = load_records( LINKS_CSV_PATH );
	$added = 0;
	$duplicates = 0;
	foreach ( $discovered as $entry ) {
		$url = $entry['url'];
		if (! isset( $records[$url] ) ) {
			$entry['first_seen_utc'] = $run_id;
			$entry['run_id'] = $run_id;
			$entry['archive_status'] = 'new';
			$records[$url] = $entry;
			$added++;
			continue;
		}
		$duplicates++;
		foreach ( ['title', 'issue_number', 'publication_date'] as $field ) {
			if (empty( $records[$url][$field] ) && ! empty( $entry[$field] ) ) {
				$records[$url][$field] = $entry[$field];
			}
		}
		$provenance = explode( '|', $records[$url]['source'] );
		if (! in_array( $entry['source'], $provenance, true ) ) {
			$records[$url]['source'] .= '|' . $entry['source'];
		}
	}
	save_records( LINKS_CSV_PATH, $records );
	$queue = $records;
	uasort( $queue, static function ( array $left, array $right ): int {
		return strcmp( (string) ($left['archive_retry_after'] ?? ''), (string) ($right['archive_retry_after'] ?? '') );
	} );
	$processed = 0;
	$access = (string) (getenv( 'IA_ACCESS_KEY' ) ?: '');
	$secret = (string) (getenv( 'IA_SECRET_KEY' ) ?: '');
	foreach ( $queue as $url => $row ) {
		if ($processed >= ARCHIVE_BATCH_SIZE ) {
			break;
		}
		if (! empty( $row['wayback_url'] ) || ($row['archive_status'] ?? '') === 'failed' || ($row['archive_retry_after'] ?? '') > $run_id ) {
			continue;
		}
		$records[$url] = process_archive_record( $row, 'archive_request', $access, $secret, time() );
		save_records( LINKS_CSV_PATH, $records );
		$processed++;
	}
	write_feed( FEED_PATH, build_feed( $records ) );
	$captured = count( array_filter( $records, static fn( array $row ): bool => ! empty( $row['wayback_url'] ) ) );
	$failed = count( array_filter( $records, static fn( array $row ): bool => ($row['archive_status'] ?? '') === 'failed' ) );
	fwrite( STDOUT, sprintf( "Added %d, duplicates %d, confirmed captures %d, failed captures %d, archive checks %d.\n", $added, $duplicates, $captured, $failed, $processed ) );
	if ($access === '' || $secret === '' ) {
		fwrite( STDOUT, "Internet Archive credentials are not configured; new capture submissions are deferred.\n" );
	}
	if ($backfill ) {
		fwrite( STDOUT, "Historical import complete; early August 2026 has an accepted coverage gap. Other gaps may remain.\n" );
	}
	return 0;
}

try {
	if (count( $argv ) > 2 || ( isset( $argv[1] ) && ! in_array( $argv[1], ['--backfill', '--initialize'], true ) ) ) {
		throw new InvalidArgumentException( 'Usage: php main.php [--backfill|--initialize]' );
	}
	if (($argv[1] ?? '') === '--initialize' ) {
		save_records( LINKS_CSV_PATH, [] );
		write_feed( FEED_PATH, build_feed( [] ) );
		fwrite( STDOUT, "Initialized empty bulletin CSV and feed.\n" );
		exit( 0 );
	}
	exit( run_scraper( ($argv[1] ?? '') === '--backfill' ) );
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . "\n" );
	exit( 1 );
}
