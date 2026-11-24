<?php
/**
 * Bulletin PDF URL normalization and domain filtering.
 */

declare(strict_types=1);

const BULLETIN_PAGE_URL = 'https://www.columbus.gov/Government/City-Council/City-Bulletins';

/**
 * Resolve a city PDF URL, including a Wayback-rewritten URL.
 *
 * @param  string $raw The raw URL to normalize.
 * @return ?string Normalized city PDF URL, or null if rejected.
 */
function normalize_url( string $raw ): ?string {
	$raw = trim( $raw );
	if ($raw === '') {
		return null;
	}

	if (preg_match( '~^(?:https?://web\.archive\.org)?/web/\d+(?:[a-z_]+)?/(https?://.+)$~i', $raw, $matches ) ) {
		$raw = $matches[1];
	}
	if (str_starts_with( $raw, '//' ) ) {
		$raw = 'https:' . $raw;
	} elseif (str_starts_with( $raw, '/' ) ) {
		$raw = 'https://www.columbus.gov' . $raw;
	} elseif (! preg_match( '~^[a-z][a-z0-9+.-]*:~i', $raw ) ) {
		$raw = 'https://www.columbus.gov/Government/City-Council/' . $raw;
	}
	$parts = parse_url( $raw );
	if ($parts === false || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
		return null;
	}
	$scheme = strtolower( (string) ($parts['scheme'] ?? '') );
	$host = strtolower( (string) ($parts['host'] ?? '') );
	if (( $scheme !== 'http' && $scheme !== 'https' ) || ( $host !== 'columbus.gov' && ! str_ends_with( $host, '.columbus.gov' ) ) ) {
		return null;
	}
	$path = (string) ($parts['path'] ?? '');
	if (! preg_match( '/\.pdf$/i', $path ) ) {
		return null;
	}
	$segments = [];
	foreach ( explode( '/', $path ) as $segment ) {
		if ($segment === '' || $segment === '.' ) {
			continue;
		}
		if ($segment === '..' ) {
			array_pop( $segments );
		} else {
			$segments[] = $segment;
		}
	}
	$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	return $scheme . '://' . $host . '/' . implode( '/', $segments ) . $query;
}
