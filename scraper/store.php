<?php
/**
 * CSV persistence layer for discovered links.
 */

declare(strict_types=1);

const CSV_FIELDS = ['url', 'title', 'issue_number', 'publication_date', 'first_seen_utc', 'source', 'run_id', 'wayback_url', 'archive_status', 'archive_job_id', 'archive_attempts', 'archive_retry_after'];

/**
 * Ensure CSV file exists and is initialized with headers.
 *
 * @param  string $path The path to the CSV file.
 * @return void
 */
function ensure_csv_exists(string $path): void {
	if (file_exists( $path )) {
		return;
	}

	$dir = dirname( $path );
	if (!is_dir( $dir )) {
		mkdir( $dir, 0777, true );
	}

	$fh = fopen( $path, 'w' );
	if ($fh === false) {
		throw new RuntimeException( 'Unable to create CSV file: ' . $path );
	}
	fputcsv( $fh, CSV_FIELDS, ',', '"', '\\' );
	fclose( $fh );
}

/**
 * Load all bulletin records keyed by original URL.
 *
 * @param  string $path The path to the CSV file.
 * @return array Associative array with URLs as keys.
 */
function load_records( string $path ): array {
	ensure_csv_exists( $path );

	$records = [];
	$fh = fopen( $path, 'r' );
	if ($fh === false) {
		throw new RuntimeException( 'Unable to read CSV file: ' . $path );
	}

	$header = fgetcsv( $fh, null, ',', '"', '\\' );
	if ($header === false) {
		fclose( $fh );
		throw new RuntimeException( 'CSV header is missing: ' . $path );
	}
	if ($header !== CSV_FIELDS ) {
		fclose( $fh );
		throw new RuntimeException( 'Unexpected CSV header: ' . $path );
	}

	while (($row = fgetcsv( $fh, null, ',', '"', '\\' )) !== false) {
		if (count( $header ) !== count( $row ) ) {
			fclose( $fh );
			throw new RuntimeException( 'Malformed CSV record: ' . $path );
		}
		$mapped = array_combine( $header, $row );
		if (!is_array( $mapped )) {
			fclose( $fh );
			throw new RuntimeException( 'Malformed CSV record: ' . $path );
		}

		$url = trim( (string) ($mapped['url'] ?? '') );
		if ($url !== '') {
			$records[$url] = $mapped;
		}
	}

	fclose( $fh );
	return $records;
}

/**
 * Atomically replace the CSV with the supplied records.
 *
 * @param  string $path The path to the CSV file.
 * @param  array  $rows Records to save.
 * @return void
 */
function save_records( string $path, array $rows ): void {
	ensure_csv_exists( $path );

	$dir = dirname( $path );
	$tmp_path = tempnam( $dir, 'links_' );
	if ($tmp_path === false) {
		throw new RuntimeException( 'Unable to create temp file in ' . $dir );
	}

	$tmp_file = fopen( $tmp_path, 'w' );
	if ($tmp_file === false ) {
		throw new RuntimeException( 'Unable to open temp file for writing: ' . $tmp_path );
	}

	if (fputcsv( $tmp_file, CSV_FIELDS, ',', '"', '\\' ) === false ) {
		fclose( $tmp_file );
		unlink( $tmp_path );
		throw new RuntimeException( 'Unable to write CSV header.' );
	}
	foreach ($rows as $row) {
		$values = [];
		foreach ( CSV_FIELDS as $field ) {
			$values[] = (string) ($row[$field] ?? '');
		}
		if (fputcsv( $tmp_file, $values, ',', '"', '\\' ) === false ) {
			fclose( $tmp_file );
			unlink( $tmp_path );
			throw new RuntimeException( 'Unable to write CSV record.' );
		}
	}
	if (! fclose( $tmp_file ) || ! rename( $tmp_path, $path ) ) {
		unlink( $tmp_path );
		throw new RuntimeException( 'Unable to replace CSV: ' . $path );
	}
}
