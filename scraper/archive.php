<?php
/**
 * Wayback availability and Save Page Now processing.
 */

declare(strict_types=1);

const ARCHIVE_BATCH_SIZE = 10;

/**
 * Send one request to a fixed Internet Archive API endpoint.
 *
 * @param  string $url     API endpoint.
 * @param  array  $headers Request headers.
 * @param  ?array $form    Optional POST form.
 * @return array HTTP status and response body.
 */
function archive_request( string $url, array $headers = [], ?array $form = null ): array {
	$handle = curl_init( $url );
	$options = [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_USERAGENT => USER_AGENT,
		CURLOPT_HTTPHEADER => $headers,
	];
	if ($form !== null ) {
		$options[CURLOPT_POST] = true;
		$options[CURLOPT_POSTFIELDS] = http_build_query( $form );
	}
	curl_setopt_array( $handle, $options );
	$body = curl_exec( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	curl_close( $handle );
	return ['status' => $status, 'body' => is_string( $body ) ? $body : ''];
}

/**
 * Process one archive record, preserving pending jobs between runs.
 *
 * @param  array    $row     Bulletin record.
 * @param  callable $request HTTP request function.
 * @param  string   $access  Internet Archive access key.
 * @param  string   $secret  Internet Archive secret key.
 * @param  int      $now     Current Unix timestamp.
 * @return array Updated record.
 */
function process_archive_record( array $row, callable $request, string $access, string $secret, int $now ): array {
	$url = (string) $row['url'];
	$lookup = $request( 'https://archive.org/wayback/available?' . http_build_query( ['url' => $url] ), [], null );
	$data = json_decode( (string) $lookup['body'], true );
	if ($lookup['status'] !== 200 || ! is_array( $data ) || ! isset( $data['archived_snapshots'] ) || ! is_array( $data['archived_snapshots'] ) ) {
		$row['archive_status'] = 'retry';
		$row['archive_retry_after'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );
		return $row;
	}
	$capture = $data['archived_snapshots']['closest'] ?? null;
	if (is_array( $capture ) && ($capture['available'] ?? false) === true && (string) ($capture['status'] ?? '') === '200' ) {
		$wayback_url = (string) ($capture['url'] ?? '');
		if (preg_match( '~^https?://web\.archive\.org/web/\d{14}/~i', $wayback_url ) && normalize_url( $wayback_url ) === $url ) {
			$row['wayback_url'] = preg_replace( '~^http://~i', 'https://', $wayback_url );
			$row['archive_status'] = 'captured';
			$row['archive_job_id'] = '';
			$row['archive_retry_after'] = '';
			return $row;
		}
	}
	if ($access === '' || $secret === '' ) {
		$row['archive_status'] = 'needs_credentials';
		$row['archive_retry_after'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );
		return $row;
	}
	if (($row['archive_status'] ?? '') === 'uncertain' ) {
		$row['archive_status'] = 'retry';
		$row['archive_retry_after'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 7 * 86400 );
		return $row;
	}
	$headers = ['Accept: application/json', 'Authorization: LOW ' . $access . ':' . $secret];
	$job = (string) ($row['archive_job_id'] ?? '');
	if ($job !== '' ) {
		$response = $request( 'https://web.archive.org/save/status/' . rawurlencode( $job ), $headers, null );
	} else {
		$response = $request( 'https://web.archive.org/save', $headers, ['url' => $url, 'force_get' => 1] );
		$row['archive_attempts'] = (string) ((int) ($row['archive_attempts'] ?? 0) + 1);
	}
	$result = json_decode( (string) $response['body'], true );
	$row['archive_retry_after'] = gmdate( 'Y-m-d\TH:i:s\Z', $now + 86400 );
	if ($response['status'] !== 200 || ! is_array( $result ) ) {
		$row['archive_status'] = $job === '' && ($response['status'] === 0 || $response['status'] >= 500) ? 'uncertain' : 'retry';
		return $row;
	}
	if (! empty( $result['original_job_id'] ) ) {
		$row['archive_job_id'] = (string) $result['original_job_id'];
	} elseif (! empty( $result['job_id'] ) ) {
		$row['archive_job_id'] = (string) $result['job_id'];
	}
	if (($result['status'] ?? '') === 'error' ) {
		$reason = (string) ($result['status_ext'] ?? '');
		if (in_array( $reason, ['error:invalid-url', 'error:not-found', 'error:no-access', 'error:forbidden'], true ) ) {
			$row['archive_status'] = 'failed';
		} else {
			$row['archive_status'] = 'retry';
			$row['archive_job_id'] = '';
		}
	} elseif (! empty( $row['archive_job_id'] ) ) {
		$row['archive_status'] = 'pending';
	} else {
		$row['archive_status'] = 'retry';
	}
	return $row;
}