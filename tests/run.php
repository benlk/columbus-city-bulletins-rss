<?php
/**
 * Offline regression tests for bulletin discovery and archive state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../scraper/fetch.php';
require_once __DIR__ . '/../scraper/parse.php';
require_once __DIR__ . '/../scraper/normalize.php';
require_once __DIR__ . '/../scraper/store.php';
require_once __DIR__ . '/../scraper/feed.php';
require_once __DIR__ . '/../scraper/archive.php';

/**
 * Assert a condition with a useful failure message.
 *
 * @param  bool   $condition Condition to check.
 * @param  string $message   Failure description.
 * @return void
 */
function check( bool $condition, string $message ): void {
	if (! $condition ) {
		throw new RuntimeException( $message );
	}
}

$html = '<html><body><a href="/outside.pdf">outside</a><div id="main-content">'
	. '<a href="/bulletin.pdf?x=1#fragment">47 - November 21, 2026<span>(PDF, 3MB)</span></a>'
	. '<a href="mailto:clerk@columbus.gov">email</a><a href="MAILTO:other@columbus.gov">email</a>'
	. '<a href="/not-a-pdf.html">not a PDF</a><a href="/earlier.pdf">2 - January 11, 2025</a>'
	. '</div></body></html>';
$parsed = parse_bulletins( $html );
check( count( $parsed ) === 3, 'Parser must only return non-email links inside main content.' );
check( $parsed[0]['title'] === '47 - November 21, 2026', 'Parser must remove file-size text.' );
check( $parsed[0]['issue_number'] === '47' && $parsed[0]['publication_date'] === '2026-11-21', 'Issue and date extraction failed.' );
check( $parsed[1]['url'] === '/not-a-pdf.html' && normalize_url( $parsed[1]['url'] ) === null, 'URL normalization must reject non-PDF links.' );
check( $parsed[2]['url'] === '/earlier.pdf', 'Parser must find links without accordion markup.' );
$failed = false;
try {
	parse_bulletins( '<html><body>No bulletin list</body></html>' );
} catch ( RuntimeException $error ) {
	$failed = true;
}
check( $failed, 'Missing main-content links must fail.' );

$url = 'https://www.columbus.gov/bulletin.pdf?x=1';
check( normalize_url( '/bulletin.pdf?x=1#fragment' ) === $url, 'Relative URL normalization failed.' );
check( normalize_url( 'https://web.archive.org/web/20260508214416/' . $url ) === $url, 'Wayback URL unwrapping failed.' );
check( normalize_url( 'https://columbus.gov.evil.test/bulletin.pdf' ) === null, 'Non-city host must be rejected.' );
check( normalize_url( '/page.html' ) === null, 'Non-PDF must be rejected.' );

$row = [
	'url' => $url,
	'title' => 'Bulletin & notices',
	'issue_number' => '47',
	'publication_date' => '2026-11-21',
	'first_seen_utc' => '2026-11-23T12:00:00Z',
	'source' => 'live',
	'run_id' => '2026-11-23T12:00:00Z',
	'wayback_url' => 'https://web.archive.org/web/20261122000000/' . $url,
];
$csv_path = tempnam( sys_get_temp_dir(), 'bulletin-test-' );
check( $csv_path !== false, 'Unable to create test CSV.' );
save_records( $csv_path, [$row] );
$loaded = load_records( $csv_path );
unlink( $csv_path );
check( $loaded[$url]['wayback_url'] === $row['wayback_url'], 'CSV must preserve the Wayback URL.' );
check( $loaded[$url]['title'] === $row['title'], 'CSV must preserve quoted text.' );
$xml = simplexml_load_string( build_feed( $loaded ) );
check( $xml !== false && count( $xml->channel->item ) === 1, 'RSS must be valid and contain one item.' );
check( (string) $xml->channel->item->guid === $url, 'RSS GUID must be the original URL.' );
check( str_contains( (string) $xml->channel->item->description, 'web.archive.org' ), 'RSS must include the Wayback link.' );

$empty = ['status' => 200, 'body' => '{"archived_snapshots":{}}'];
$archive_url = 'https://web.archive.org/web/20261122000000/' . $url;
$available = ['status' => 200, 'body' => json_encode( ['archived_snapshots' => ['closest' => ['available' => true, 'status' => '200', 'url' => $archive_url]]] )];
$responses = [$empty, ['status' => 200, 'body' => '{"job_id":"spn2-test"}']];
$request = static function ( string $request_url, array $headers, ?array $form ) use ( &$responses ): array {
	return array_shift( $responses );
};
$pending = process_archive_record( ['url' => $url], $request, 'access', 'secret', 0 );
check( $pending['archive_status'] === 'pending' && $pending['archive_job_id'] === 'spn2-test', 'Capture job must remain pending.' );
$responses = [$empty, ['status' => 200, 'body' => '{"status":"pending"}']];
$polled = process_archive_record( $pending, $request, 'access', 'secret', 86400 );
check( $polled['archive_job_id'] === 'spn2-test' && $polled['archive_status'] === 'pending', 'Pending jobs must be resumed without resubmitting.' );
$responses = [$available];
$completed = process_archive_record( $pending, $request, 'access', 'secret', 86400 );
check( $completed['archive_status'] === 'captured' && $completed['wayback_url'] === $archive_url, 'Only confirmed capture URLs should be stored.' );
$responses = [['status' => 429, 'body' => '']];
$retry = process_archive_record( ['url' => $url], $request, 'access', 'secret', 0 );
check( $retry['archive_status'] === 'retry' && $retry['archive_retry_after'] !== '', 'Rate limits must be retried.' );
$responses = [$empty];
$needs_credentials = process_archive_record( ['url' => $url], $request, '', '', 0 );
check( $needs_credentials['archive_status'] === 'needs_credentials', 'Missing credentials must defer capture.' );
$responses = [$empty, ['status' => 0, 'body' => '']];
$uncertain = process_archive_record( ['url' => $url], $request, 'access', 'secret', 0 );
check( $uncertain['archive_status'] === 'uncertain', 'An ambiguous submission must not be immediately repeated.' );

fwrite( STDOUT, "All offline tests passed.\n" );
