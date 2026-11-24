<?php
/**
 * RSS 2.0 feed generation.
 */

declare(strict_types=1);

const MAX_FEED_ITEMS = 500;
const FEED_TITLE = 'Columbus City Bulletins';
const FEED_LINK = 'https://www.columbus.gov/Government/City-Council/City-Bulletins';
const FEED_DESCRIPTION = 'PDF editions of the Columbus City Bulletin.';

/**
 * Convert ISO 8601 UTC timestamp to RFC 2822 format.
 *
 * @param  string $iso_timestamp ISO 8601 UTC timestamp string.
 * @return string RFC 2822 formatted date.
 */
function to_rfc2822(string $iso_timestamp): string {
	try {
		$date = new DateTimeImmutable( $iso_timestamp, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $error ) {
		$date = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}
	return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( DATE_RSS );
}

/**
 * Load existing feed items from an RSS file.
 *
 * @param  string $feed_path The path to the RSS file.
 * @return array Array of item arrays.
 */
function load_existing_items(string $feed_path): array {
	if (!file_exists( $feed_path )) {
		return [];
	}

	libxml_use_internal_errors( true );
	$xml = simplexml_load_file( $feed_path );
	if ($xml === false || !isset( $xml->channel )) {
		return [];
	}

	$items = [];
	foreach ($xml->channel->item as $item) {
		$items[] = [
			'title' => (string) ($item->title ?? ''),
			'link' => (string) ($item->link ?? ''),
			'guid' => (string) ($item->guid ?? ''),
			'pub_date' => (string) ($item->{'pubDate'} ?? ''),
		];
	}

	return $items;
}

/**
 * Build RSS XML from bulletin records.
 *
 * @param  array $rows Bulletin CSV records.
 * @return string RSS XML content.
 */
function build_feed( array $rows ): string {
	$all_items = array_values( $rows );
	usort( $all_items, static function ( array $left, array $right ): int {
		$left_date = (string) (($left['publication_date'] ?? '') ?: ($left['first_seen_utc'] ?? ''));
		$right_date = (string) (($right['publication_date'] ?? '') ?: ($right['first_seen_utc'] ?? ''));
		return strcmp( $right_date, $left_date ) ?: strcmp( (string) $left['url'], (string) $right['url'] );
	} );
	$all_items = array_slice( $all_items, 0, MAX_FEED_ITEMS );

	$dom = new DOMDocument( '1.0', 'UTF-8' );
	$dom->{'formatOutput'} = true;

	$rss = $dom->createElement( 'rss' );
	$rss->setAttribute( 'version', '2.0' );
	$dom->appendChild( $rss );

	$channel = $dom->createElement( 'channel' );
	$rss->appendChild( $channel );
	$channel->appendChild( $dom->createElement( 'title', FEED_TITLE ) );
	$channel->appendChild( $dom->createElement( 'link', FEED_LINK ) );
	$channel->appendChild( $dom->createElement( 'description', FEED_DESCRIPTION ) );

	foreach ($all_items as $item) {
		$item_element = $dom->createElement( 'item' );
		$url = (string) $item['url'];
		$title = (string) (($item['title'] ?? '') ?: $url);
		$title_element = $dom->createElement( 'title' );
		$title_element->appendChild( $dom->createTextNode( $title ) );
		$item_element->appendChild( $title_element );
		$link = $dom->createElement( 'link' );
		$link->appendChild( $dom->createTextNode( $url ) );
		$item_element->appendChild( $link );

		$guid = $dom->createElement( 'guid' );
		$guid->appendChild( $dom->createTextNode( $url ) );
		$guid->setAttribute( 'isPermaLink', 'true' );
		$item_element->appendChild( $guid );

		$date = (string) (($item['publication_date'] ?? '') ?: ($item['first_seen_utc'] ?? ''));
		$item_element->appendChild( $dom->createElement( 'pubDate', to_rfc2822( $date ) ) );
		if (! empty( $item['wayback_url'] ) ) {
			$description = $dom->createElement( 'description' );
			$archive_url = htmlspecialchars( (string) $item['wayback_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			$description->appendChild( $dom->createTextNode( '<a href="' . $archive_url . '">Wayback Machine</a>' ) );
			$item_element->appendChild( $description );
		}
		$channel->appendChild( $item_element );
	}

	$xml = $dom->saveXML();
	if ($xml === false ) {
		throw new RuntimeException( 'Unable to serialize RSS feed.' );
	}
	return $xml;
}

/**
 * Write RSS XML content to the feed path.
 *
 * @param  string $feed_path   Destination feed path.
 * @param  string $xml_content RSS XML content.
 * @return void
 */
function write_feed(string $feed_path, string $xml_content): void {
	$dir = dirname($feed_path);
	if (!is_dir($dir)) {
		mkdir($dir, 0777, true);
	}

	if (is_file( $feed_path ) && file_get_contents( $feed_path ) === $xml_content ) {
		return;
	}
	$tmp_path = tempnam( $dir, 'feed_' );
	if ($tmp_path === false ) {
		throw new RuntimeException( 'Unable to create temporary feed.' );
	}
	if (file_put_contents( $tmp_path, $xml_content ) === false || ! rename( $tmp_path, $feed_path ) ) {
		unlink( $tmp_path );
		throw new RuntimeException( 'Unable to replace RSS feed.' );
	}
}
