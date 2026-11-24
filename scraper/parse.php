<?php
/**
 * Extract bulletin metadata from city listing pages.
 */

declare(strict_types=1);

/**
 * Parse PDF links inside the bulletin year sections of the main content.
 *
 * @param  string $content Listing HTML.
 * @return array Bulletin records with raw URLs and optional metadata.
 * @throws RuntimeException When the listing structure is missing or empty.
 */
function parse_bulletins( string $content ): array {
	$previous = libxml_use_internal_errors( true );
	$dom = new DOMDocument();
	$loaded = $content !== '' && $dom->loadHTML( $content );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	if (! $loaded ) {
		throw new RuntimeException( 'Unable to parse bulletin listing HTML.' );
	}

	$xpath = new DOMXPath( $dom );
	$links = $xpath->query( '//div[@id="main-content"]//a[@href]' );
	if ($links === false ) {
		throw new RuntimeException( 'Unable to select links inside main content.' );
	}

	$records = [];
	foreach ( $links as $link ) {
		$href = trim( $link->getAttribute( 'href' ) );
		if ($href === '' || stripos( $href, 'mailto:' ) === 0 ) {
			continue;
		}
		$text = preg_replace( '/\s*\(PDF,?[^)]*\)\s*$/iu', '', $link->{'textContent'} );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ?? '' ) ?? '' );
		$issue = '';
		$date = '';
		if (preg_match( '/^(\d+)\s*[-–—]\s*([A-Za-z]+\s+\d{1,2},?\s+\d{4})/u', $text, $matches ) ) {
			$issue = $matches[1];
			$parsed_date = strtotime( $matches[2] . ' UTC' );
			if ($parsed_date !== false ) {
				$date = gmdate( 'Y-m-d', $parsed_date );
			}
		}
		$records[] = [
			'url' => $href,
			'title' => $text,
			'issue_number' => $issue,
			'publication_date' => $date,
		];
	}
	if (count( $records ) === 0 ) {
		throw new RuntimeException( 'Bulletin sections contain no PDF links.' );
	}
	return $records;
}
