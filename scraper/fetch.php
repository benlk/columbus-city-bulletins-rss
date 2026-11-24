<?php
/**
 * HTTP fetching with retry and exponential backoff.
 */

declare(strict_types=1);

const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64; rv:160.0) Gecko/20100101 Firefox/160.0';
const REQUEST_TIMEOUT_SECONDS = 20;
const MAX_RETRIES = 3;
const ERROR_BODY_LOG_LIMIT = 4096;

/**
 * Fetch a URL with retry logic and exponential backoff.
 *
 * @param  string $url The URL to fetch.
 * @return ?string The response body, or null on failure.
 */
function fetch_url( string $url ): ?string {
	for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT => REQUEST_TIMEOUT_SECONDS,
				CURLOPT_USERAGENT => USER_AGENT,
				CURLOPT_ENCODING => '',
				CURLOPT_HTTPHEADER => [
					'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language: en-US,en;q=0.5',
				],
			]
		);

		$body = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$error = curl_error( $ch );
		$content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );

		if (is_string( $body ) && $status >= 200 && $status < 300) {
			return $body;
		}

		fwrite(
			STDERR,
			sprintf(
				"Attempt %d/%d failed for %s (status=%d, content-type=%s, error=%s)\n",
				$attempt,
				MAX_RETRIES,
				$url,
				$status,
				$content_type,
				$error
			)
		);

		if (is_string( $body ) && $body !== '' ) {
			$debug_path = __DIR__ . '/../scraper-output.html';
			if (file_put_contents( $debug_path, $body ) === false ) {
				fwrite( STDERR, "Unable to save the response body to scraper-output.html.\n" );
			} else {
				fwrite( STDERR, "Full response body saved to scraper-output.html.\n" );
			}
			$excerpt = substr( $body, 0, ERROR_BODY_LOG_LIMIT );
			$excerpt = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $excerpt ) ?? '';
			fwrite( STDERR, "Response body excerpt:\n" . $excerpt . "\n" );
		}

		if ($attempt < MAX_RETRIES) {
			sleep( (int) pow( 2, $attempt ) );
		}
	}

	return null;
}
