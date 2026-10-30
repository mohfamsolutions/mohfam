<?php
/** Plain PHP inquiry validation, private storage, and Brevo transport. */
namespace MohFam\Mail;

/** Resolve only the sibling private directory of this cPanel site's public_html. */
function privateDirectory( string $siteRoot ): string {
	$root = realpath( $siteRoot );
	if ( false === $root || 'public_html' !== basename( dirname( $root ) ) ) {
		throw new \RuntimeException( 'private_path_unavailable' );
	}
	$home = dirname( $root, 2 );
	$private = realpath( $home . '/private' );
	if ( false === $private || $private !== $home . DIRECTORY_SEPARATOR . 'private' || ! is_dir( $private ) ) {
		throw new \RuntimeException( 'private_path_unavailable' );
	}
	return $private;
}

/** Read only the owner's private config. Accidental output is discarded. */
function loadApiKey( string $private ): string {
	$configPath = $private . '/mohfam-mail.php';
	if ( is_link( $configPath ) || ! is_file( $configPath ) || ! is_readable( $configPath ) ) {
		throw new \RuntimeException( 'config_unavailable' );
	}
	ob_start();
	try {
		$config = @include $configPath;
	} finally {
		ob_end_clean();
	}
	$key = is_array( $config ) ? ( $config['brevo_api_key'] ?? null ) : null;
	if ( ! is_string( $key ) || ! preg_match( '/\A[\x21-\x7E]{20,512}\z/', $key ) || 'PASTE_YOUR_KEY_HERE' === $key ) {
		throw new \RuntimeException( 'config_invalid' );
	}
	return $key;
}

/** Local capability/config check only. Never contacts Brevo or sends email. */
function checkReadiness( string $siteRoot ): bool {
	try {
		$private = privateDirectory( $siteRoot );
		loadApiKey( $private );
		$log = $private . '/mohfam-mail.log';
		return function_exists( 'curl_init' ) && in_array( 'https', curl_version()['protocols'] ?? array(), true )
			&& is_writable( $private ) && ! is_link( $log ) && ( ! file_exists( $log ) || ( is_file( $log ) && is_writable( $log ) ) );
	} catch ( \Throwable $error ) {
		return false;
	}
}

/** Reject malformed values rather than silently rewriting contact information. */
function validateFields( array $input, array $services ): array {
	$limits = array( 'name' => 120, 'company' => 160, 'email' => 254, 'phone' => 40, 'service' => 100, 'message' => 4000, 'page_url' => 2048 );
	$fields = array();
	foreach ( $limits as $key => $limit ) {
		$value = $input[ $key ] ?? '';
		if ( ! is_string( $value ) || ! preg_match( '//u', $value ) ) {
			throw new \InvalidArgumentException( 'Please check the information in your form.' );
		}
		// Newlines and tabs are permitted only in the message body.
		$controls = 'message' === $key ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u' : '/[\x00-\x1F\x7F-\x9F]/u';
		if ( preg_match( $controls, $value ) ) {
			throw new \InvalidArgumentException( 'Please remove unsupported characters from your form.' );
		}
		$value = trim( $value );
		$length = preg_match_all( '/./us', $value );
		if ( false === $length || $length > $limit ) {
			throw new \InvalidArgumentException( 'One of your fields is too long. Please shorten it and try again.' );
		}
		$fields[ $key ] = $value;
	}
	foreach ( array( 'name', 'email', 'service', 'message', 'page_url' ) as $key ) {
		if ( '' === $fields[ $key ] ) {
			throw new \InvalidArgumentException( 'Please complete your name, email, service interest, and message.' );
		}
	}
	if ( ! filter_var( $fields['email'], FILTER_VALIDATE_EMAIL ) || ! in_array( $fields['service'], $services, true ) ) {
		throw new \InvalidArgumentException( 'Please check your email address and service interest.' );
	}
	return $fields;
}

/** Locked, bounded rate state; stores keyed IP hashes only, never raw addresses. */
function consumeRateLimit( string $private, string $ipKey, int $now ): bool {
	$path = $private . '/mohfam-inquiry-rate.json';
	if ( is_link( $path ) ) {
		throw new \RuntimeException( 'rate_storage_unavailable' );
	}
	$file = @fopen( $path, 'c+' );
	if ( false === $file ) {
		throw new \RuntimeException( 'rate_storage_unavailable' );
	}
	try {
		if ( ! @chmod( $path, 0600 ) || ! flock( $file, LOCK_EX ) || fstat( $file )['size'] > 2000000 ) {
			throw new \RuntimeException( 'rate_storage_unavailable' );
		}
		$content = stream_get_contents( $file );
		$state = '' === $content ? array() : json_decode( $content, true );
		if ( ! is_array( $state ) ) {
			throw new \RuntimeException( 'rate_storage_unavailable' );
		}
		foreach ( $state as $key => $entry ) {
			if ( ! is_array( $entry ) || ( $entry['start'] ?? 0 ) <= $now - 900 ) {
				unset( $state[ $key ] );
			}
		}
		$entry = $state[ $ipKey ] ?? array( 'start' => $now, 'last' => 0, 'count' => 0 );
		if ( $entry['last'] > $now - 20 || $entry['count'] >= 5 || ( ! isset( $state[ $ipKey ] ) && count( $state ) >= 10000 ) ) {
			return false;
		}
		$state[ $ipKey ] = array( 'start' => $entry['start'], 'last' => $now, 'count' => $entry['count'] + 1 );
		$json = json_encode( $state, JSON_THROW_ON_ERROR );
		rewind( $file );
		if ( ! ftruncate( $file, 0 ) || fwrite( $file, $json ) !== strlen( $json ) || ! fflush( $file ) ) {
			throw new \RuntimeException( 'rate_storage_unavailable' );
		}
		return true;
	} finally {
		flock( $file, LOCK_UN );
		fclose( $file );
	}
}

/** Build a transport-independent payload. $sitePrefix is a trusted site setting. */
function inquiryPayload( string $sitePrefix, array $fields, string $reference, \DateTimeImmutable $submitted ): array {
	if ( ! preg_match( '/\A[A-Za-z0-9 &-]{1,60}\z/', $sitePrefix ) ) {
		throw new \InvalidArgumentException( 'invalid_site_prefix' );
	}
	$details = array(
		'Reference' => $reference,
		'Full Name' => $fields['name'],
		'Company' => $fields['company'],
		'Email' => $fields['email'],
		'Phone' => $fields['phone'],
		'Service Interest' => $fields['service'],
		'Project Details' => $fields['message'],
		'Page URL' => $fields['page_url'],
		'Submitted (UTC)' => $submitted->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s T' ),
		'Submitted (America/Los_Angeles)' => $submitted->setTimezone( new \DateTimeZone( 'America/Los_Angeles' ) )->format( 'Y-m-d H:i:s T (P)' ),
	);
	$text = '';
	$html = '<!doctype html><html><body><h1>New website inquiry</h1><table>';
	foreach ( $details as $label => $value ) {
		$text .= $label . ': ' . $value . "\n\n";
		$html .= '<tr><th style="text-align:left;vertical-align:top;padding:8px">' . htmlspecialchars( $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</th><td style="padding:8px">' . nl2br( htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) ) . '</td></tr>';
	}
	return array(
		'sender' => array( 'name' => 'MohFam Website', 'email' => 'website@mohfam.co' ),
		'to' => array( array( 'email' => 'mohfamsolutions@gmail.com', 'name' => 'MohFam' ) ),
		'replyTo' => array( 'email' => $fields['email'], 'name' => $fields['name'] ),
		'subject' => '[' . $sitePrefix . '] ' . $fields['service'] . ' inquiry — ' . $fields['name'],
		'textContent' => $text,
		'htmlContent' => $html . '</table></body></html>',
	);
}

/** Only transport metadata is written; never include provider response bodies. */
function writeLog( $file, string $reference, string $status, int $httpCode = 0, string $messageId = '' ): bool {
	$entry = array( 'timestamp' => gmdate( 'c' ), 'reference' => $reference, 'status' => $status, 'http_code' => $httpCode );
	if ( '' !== $messageId ) {
		$entry['messageId'] = $messageId;
	}
	$line = json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	if ( ! flock( $file, LOCK_EX ) ) {
		return false;
	}
	$ok = fwrite( $file, $line ) === strlen( $line ) && fflush( $file );
	flock( $file, LOCK_UN );
	return $ok;
}

/**
 * Reusable sender. No WordPress APIs, SDK, visitor confirmation, or mail fallback.
 * Caller supplies the cPanel site root and validated fields.
 */
function sendInquiry( string $sitePrefix, array $fields, string $siteRoot ): array {
	$reference = bin2hex( random_bytes( 12 ) );
	$failure = array( 'success' => false, 'reference' => $reference, 'messageId' => '', 'http_code' => 0 );
	$log = false;
	try {
		// Validate again at the reusable boundary, including raw header characters.
		$fields = validateFields( $fields, array( $fields['service'] ?? '' ) );
		$private = privateDirectory( $siteRoot );
		$logPath = $private . '/mohfam-mail.log';
		if ( is_link( $logPath ) ) {
			return $failure;
		}
		$log = @fopen( $logPath, 'ab' );
		if ( false === $log || ! @chmod( $logPath, 0600 ) || ! writeLog( $log, $reference, 'attempt' ) ) {
			return $failure;
		}
		try {
			$key = loadApiKey( $private );
		} catch ( \Throwable $error ) {
			writeLog( $log, $reference, 'config_unavailable_or_invalid' );
			return $failure;
		}
		if ( ! function_exists( 'curl_init' ) || ! in_array( 'https', curl_version()['protocols'] ?? array(), true ) ) {
			writeLog( $log, $reference, 'curl_https_unavailable' );
			return $failure;
		}
		$payload = inquiryPayload( $sitePrefix, $fields, $reference, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		$curl = curl_init( 'https://api.brevo.com/v3/smtp/email' );
		if ( false === $curl ) {
			writeLog( $log, $reference, 'curl_initialization_failed' );
			return $failure;
		}
		try {
			$optionsSet = curl_setopt_array( $curl, array(
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR ),
				CURLOPT_HTTPHEADER => array( 'api-key: ' . $key, 'accept: application/json', 'content-type: application/json' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 10,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
			) );
			if ( ! $optionsSet ) {
				writeLog( $log, $reference, 'transport_options_failed' );
				return $failure;
			}
			$response = curl_exec( $curl );
			$httpCode = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
			$failure['http_code'] = $httpCode;
			if ( false === $response ) {
				writeLog( $log, $reference, 'transport_error_' . (int) curl_errno( $curl ), $httpCode );
				return $failure;
			}
		} finally {
			curl_close( $curl );
			unset( $key );
		}
		$result = json_decode( $response, true );
		$messageId = is_array( $result ) ? ( $result['messageId'] ?? '' ) : '';
		if ( 201 !== $httpCode || ! is_string( $messageId ) || ! preg_match( '/\A[<>A-Za-z0-9@._:+\/-]{1,512}\z/', $messageId ) ) {
			// Fixed error categories prevent provider echoes from leaking input or keys.
			writeLog( $log, $reference, 201 === $httpCode ? 'invalid_message_id' : 'provider_rejected', $httpCode );
			return $failure;
		}
		writeLog( $log, $reference, 'accepted', $httpCode, $messageId );
		return array( 'success' => true, 'reference' => $reference, 'messageId' => $messageId, 'http_code' => $httpCode );
	} catch ( \Throwable $error ) {
		if ( is_resource( $log ) ) {
			writeLog( $log, $reference, 'internal_error' );
		}
		return $failure;
	} finally {
		if ( is_resource( $log ) ) {
			fclose( $log );
		}
	}
}
