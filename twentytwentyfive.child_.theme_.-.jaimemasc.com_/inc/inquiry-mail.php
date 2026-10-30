<?php
/** Plain PHP inquiry validation, private storage, and Brevo transport. */
namespace MohFam\Mail;

/** Trusted site settings. Adding a map entry does not deploy to another site. */
function siteSettings( string $site ): array {
	$sites = array(
		'mohfam.co' => array( 'inquiries' => 3, 'newsletter' => 4, 'prefix' => '[MohFam]' ),
		'mohfamsecurity.com' => array( 'inquiries' => 11, 'newsletter' => null, 'prefix' => '[MohFam Security]' ),
		'mohfamcommercialcleaning.com' => array( 'inquiries' => 12, 'newsletter' => null, 'prefix' => '[MohFam Commercial Cleaning]' ),
		'mohfampoolcleaning.com' => array( 'inquiries' => 13, 'newsletter' => null, 'prefix' => '[MohFam Pool Cleaning]' ),
		'mohfamlandscaping.com' => array( 'inquiries' => 14, 'newsletter' => null, 'prefix' => '[MohFam Landscaping]' ),
		'mohfamcarwash.com' => array( 'inquiries' => 15, 'newsletter' => null, 'prefix' => '[MohFam Car Wash]' ),
	);
	if ( ! isset( $sites[ $site ] ) ) {
		throw new \InvalidArgumentException( 'unknown_source_site' );
	}
	return $sites[ $site ];
}

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
	$optIn = $input['marketing_opt_in'] ?? false;
	if ( ! in_array( $optIn, array( false, true, '', '1' ), true ) ) {
		throw new \InvalidArgumentException( 'Please check your updates and offers selection.' );
	}
	$fields['marketing_opt_in'] = true === $optIn || '1' === $optIn;
	return $fields;
}

/** Build an additive upsert. Never write false over any existing contact. */
function contactPayload( string $site, array $fields, string $inquiryDate, ?array $existing ): array {
	$settings = siteSettings( $site );
	$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $inquiryDate );
	if ( false === $date || $date->format( 'Y-m-d' ) !== $inquiryDate ) {
		throw new \InvalidArgumentException( 'invalid_inquiry_date' );
	}
	$name = explode( ' ', trim( $fields['name'] ), 2 );
	$optIn = true === ( $fields['marketing_opt_in'] ?? false );
	$attributes = array(
		'FIRSTNAME' => $name[0],
		'LASTNAME' => trim( $name[1] ?? '' ),
		'SERVICE_INTEREST' => $fields['service'],
		'SOURCE_SITE' => $site,
		'LAST_INQUIRY_AT' => $inquiryDate,
	);
	// Omitting false on updates also avoids overwriting a concurrent opt-in.
	if ( $optIn || null === $existing ) {
		$attributes['MARKETING_OPT_IN'] = $optIn;
	} elseif ( true === ( $existing['attributes']['MARKETING_OPT_IN'] ?? false ) ) {
		$attributes['MARKETING_OPT_IN'] = true;
	}
	$lists = array( $settings['inquiries'] );
	if ( $optIn && null !== $settings['newsletter'] ) {
		$lists[] = $settings['newsletter'];
	}
	return array( 'email' => $fields['email'], 'attributes' => $attributes, 'listIds' => $lists, 'updateEnabled' => true );
}

/** Fixed contact endpoints only; raw provider content is never logged. */
function contactRequest( string $key, string $email, ?array $payload = null ): array {
	$url = 'https://api.brevo.com/v3/contacts';
	if ( null === $payload ) {
		$url .= '/' . rawurlencode( $email ) . '?identifierType=email_id';
	}
	$curl = curl_init( $url );
	if ( false === $curl ) {
		throw new \RuntimeException( 'contact_transport_initialization_failed' );
	}
	try {
		$options = array(
			CURLOPT_POST => null !== $payload,
			CURLOPT_HTTPHEADER => array( 'api-key: ' . $key, 'accept: application/json', 'content-type: application/json' ),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 10,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
		);
		if ( null !== $payload ) {
			$options[ CURLOPT_POSTFIELDS ] = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
		}
		if ( ! curl_setopt_array( $curl, $options ) ) {
			throw new \RuntimeException( 'contact_transport_options_failed' );
		}
		$body = curl_exec( $curl );
		return array( 'ok' => false !== $body, 'http_code' => (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ), 'data' => false === $body ? null : json_decode( $body, true ) );
	} finally {
		curl_close( $curl );
	}
}

/** Best-effort private metadata only. Logging errors cannot affect visitors. */
function logContactEvent( string $siteRoot, string $reference, string $status, int $code = 0 ): void {
	$log = false;
	try {
		$path = privateDirectory( $siteRoot ) . '/mohfam-mail.log';
		if ( is_link( $path ) ) {
			return;
		}
		$log = @fopen( $path, 'ab' );
		if ( is_resource( $log ) && @chmod( $path, 0600 ) ) {
			writeLog( $log, $reference, $status, $code );
		}
	} catch ( \Throwable $error ) {
		// No public output or fallback log containing provider errors or contact data.
	} finally {
		if ( is_resource( $log ) ) {
			fclose( $log );
		}
	}
}

/** Select the native response-finishing API when the hosting SAPI provides one. */
function responseFinishMode(): string {
	if ( function_exists( 'fastcgi_finish_request' ) ) {
		return 'fastcgi_finish_request';
	}
	if ( function_exists( 'litespeed_finish_request' ) ) {
		return 'litespeed_finish_request';
	}
	return 'content_length_flush';
}

/** Called before JSON output. Explicit framing supports the shared-host fallback. */
function prepareContactResponse( int $length ): void {
	ignore_user_abort( true );
	if ( 'content_length_flush' !== responseFinishMode() ) {
		return;
	}
	@ini_set( 'zlib.output_compression', '0' );
	while ( ob_get_level() > 0 ) {
		if ( ! @ob_end_clean() ) {
			break;
		}
	}
	if ( ! headers_sent() ) {
		header( 'Connection: close' );
		header( 'Content-Length: ' . $length );
	}
}

/** No output is permitted after this function; provider errors stay private. */
function finishContactResponse(): bool {
	$mode = responseFinishMode();
	if ( 'fastcgi_finish_request' === $mode ) {
		return fastcgi_finish_request();
	}
	if ( 'litespeed_finish_request' === $mode ) {
		return false !== litespeed_finish_request();
	}
	while ( ob_get_level() > 0 ) {
		if ( ! @ob_end_flush() ) {
			break;
		}
	}
	flush();
	return true;
}

/** Register in memory only; never schedules a WP-Cron event or loopback. */
function deferContactSync( string $site, array $fields, string $siteRoot, string $reference, array $notification = array() ): void {
	try {
		$contact = array_intersect_key( $fields, array_flip( array( 'name', 'email', 'service', 'marketing_opt_in' ) ) );
		$date = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' );
		$notificationId = acceptedNotification( $notification ) ? $notification['messageId'] : '';
		register_shutdown_function( __NAMESPACE__ . '\\runContactAfterResponse', $site, $contact, $siteRoot, $reference, $date, $notificationId );
		logContactEvent( $siteRoot, $reference, 'contact_deferred' );
	} catch ( \Throwable $error ) {
		logContactEvent( $siteRoot, $reference, 'contact_defer_failed' );
	}
}

function runContactAfterResponse( string $site, array $fields, string $siteRoot, string $reference, string $date, string $notificationId = '' ): void {
	try {
		ignore_user_abort( true );
		@set_time_limit( 45 );
		if ( ! finishContactResponse() ) {
			logContactEvent( $siteRoot, $reference, 'contact_response_finish_failed' );
			return;
		}
		logContactEvent( $siteRoot, $reference, 'contact_after_response_started' );
		syncInquiryContact( $site, $fields, $siteRoot, $reference, $date );
		// Contact behavior stays first and unchanged. Confirmation is mohfam.co only.
		if ( 'mohfam.co' === $site && validMessageId( $notificationId ) ) {
			sendInquiryConfirmation( $fields, $siteRoot, $reference );
		}
	} catch ( \Throwable $error ) {
		logContactEvent( $siteRoot, $reference, 'contact_after_response_failed' );
	}
}

/** Runs after response completion (or for a previously scheduled legacy event). */
function syncInquiryContact( string $site, array $fields, string $siteRoot, string $reference, string $inquiryDate ): bool {
	$lock = false;
	try {
		siteSettings( $site );
		if ( ! filter_var( $fields['email'] ?? '', FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'contact_email_invalid' );
		}
		$private = privateDirectory( $siteRoot );
		$lockPath = $private . '/mohfam-contact-sync.lock';
		if ( is_link( $lockPath ) ) {
			throw new \RuntimeException( 'contact_lock_unavailable' );
		}
		$lock = @fopen( $lockPath, 'c' );
		// Shared by these site integrations: serialize lookup/upsert consent decisions.
		if ( false === $lock || ! @chmod( $lockPath, 0600 ) ) {
			throw new \RuntimeException( 'contact_lock_unavailable' );
		}
		$deadline = microtime( true ) + 5;
		while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( microtime( true ) >= $deadline ) {
				logContactEvent( $siteRoot, $reference, 'contact_lock_timeout' );
				return false;
			}
			usleep( 50000 );
		}
		$key = loadApiKey( $private );
		$lookup = contactRequest( $key, $fields['email'] );
		$existing = null;
		if ( ! $lookup['ok'] || ! in_array( $lookup['http_code'], array( 200, 404 ), true ) ) {
			logContactEvent( $siteRoot, $reference, 'contact_lookup_failed', $lookup['http_code'] );
			return false;
		}
		if ( 200 === $lookup['http_code'] ) {
			if ( ! is_array( $lookup['data'] ) || empty( $lookup['data']['id'] ) || ( isset( $lookup['data']['attributes'] ) && ! is_array( $lookup['data']['attributes'] ) ) ) {
				logContactEvent( $siteRoot, $reference, 'contact_lookup_invalid', 200 );
				return false;
			}
			$existing = $lookup['data'];
		}
		$payload = contactPayload( $site, $fields, $inquiryDate, $existing );
		$response = contactRequest( $key, $fields['email'], $payload );
		$success = $response['ok'] && in_array( $response['http_code'], array( 201, 204 ), true );
		logContactEvent( $siteRoot, $reference, $success ? 'contact_synced' : 'contact_upsert_failed', $response['http_code'] );
		return $success;
	} catch ( \Throwable $error ) {
		logContactEvent( $siteRoot, $reference, 'contact_sync_internal_error' );
		return false;
	} finally {
		if ( is_resource( $lock ) ) {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}
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
		'tags' => array( 'inquiry-notification' ),
		'sender' => array( 'name' => 'MohFam Website', 'email' => 'website@mohfam.co' ),
		'to' => array( array( 'email' => 'mohfamsolutions@gmail.com', 'name' => 'MohFam' ) ),
		'replyTo' => array( 'email' => $fields['email'], 'name' => $fields['name'] ),
		'subject' => '[' . $sitePrefix . '] ' . $fields['service'] . ' inquiry — ' . $fields['name'],
		'textContent' => $text,
		'htmlContent' => $html . '</table></body></html>',
	);
}

function validMessageId( $messageId ): bool {
	return is_string( $messageId ) && 1 === preg_match( '/\A[<>A-Za-z0-9@._:+\/-]{1,512}\z/', $messageId );
}

function acceptedNotification( array $result ): bool {
	return true === ( $result['success'] ?? false ) && 201 === ( $result['http_code'] ?? 0 ) && validMessageId( $result['messageId'] ?? null );
}

/** Strictly allowlisted body fields: never read company, message, or page_url. */
function confirmationPayload( array $fields, string $reference ): array {
	if ( ! preg_match( '/\A[a-f0-9]{24}\z/', $reference ) ) {
		throw new \InvalidArgumentException( 'confirmation_reference_invalid' );
	}
	$safe = validateFields( array(
		'name' => $fields['name'] ?? '',
		'email' => $fields['email'] ?? '',
		'service' => $fields['service'] ?? '',
		'message' => 'confirmation',
		'page_url' => 'https://mohfam.co/',
	), array( 'Security Services', 'Commercial Cleaning', 'Pool Cleaning', 'Landscaping (Waitlist)', 'Car Wash', 'General Inquiry' ) );
	$firstName = explode( ' ', $safe['name'], 2 )[0];
	$text = 'Hi ' . $firstName . ",\n\n"
		. 'Thanks for contacting MohFam. We received your ' . $safe['service'] . " request and will get back to you within 1 business day.\n\n"
		. 'Reference: ' . $reference . "\n\n"
		. "Need help sooner? Call +1 (818) 818-1181.\n\n"
		. "— The MohFam Team\nMohFam | Los Angeles, CA | mohfam.co";
	$escape = static function ( $value ) { return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); };
	$logo = 'https://mohfam.co/wp-content/themes/twentytwentyfive.child_.theme_.-.jaimemasc.com_/assets/images/email-logo.png';
	$html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
		. '<body style="margin:0;padding:0;background:#f9f9f7;color:#1a1a1b;font-family:Arial,sans-serif;-webkit-text-size-adjust:100%">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9f9f7"><tr><td align="center" style="padding:24px 12px">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-top:4px solid #c5a059"><tr><td style="padding:24px">'
		. '<img src="' . $logo . '" alt="MohFam" width="180" height="56" style="display:block;border:0;">'
		. '<p style="margin:12px 0 16px;font-size:18px;line-height:1.5">Hi ' . $escape( $firstName ) . ',</p>'
		. '<p style="margin:0 0 20px;font-size:16px;line-height:1.6">Thanks for contacting MohFam. We received your ' . $escape( $safe['service'] ) . ' request and will get back to you within 1 business day.</p>'
		. '<p style="margin:0 0 20px;padding:12px;background:#f9f9f7;font-size:14px;line-height:1.6;overflow-wrap:anywhere">Reference: <strong>' . $escape( $reference ) . '</strong></p>'
		. '<p style="margin:0 0 20px;font-size:16px;line-height:1.6">Need help sooner? Call <a href="tel:+18188181181" style="color:#775a19">+1 (818) 818-1181</a>.</p>'
		. '<p style="margin:0 0 24px;font-size:16px;line-height:1.6">— The MohFam Team</p>'
		. '<p style="margin:0;padding-top:16px;border-top:1px solid #e5e5e5;color:#626267;font-size:13px;line-height:1.6">MohFam | Los Angeles, CA | <a href="https://mohfam.co/" style="color:#775a19">mohfam.co</a></p>'
		. '</td></tr></table></td></tr></table></body></html>';
	return array(
		'sender' => array( 'name' => 'MohFam', 'email' => 'website@mohfam.co' ),
		'to' => array( array( 'email' => $safe['email'], 'name' => $safe['name'] ) ),
		'replyTo' => array( 'email' => 'mohfamsolutions@gmail.com', 'name' => 'MohFam' ),
		'subject' => 'We received your request — MohFam',
		'textContent' => $text,
		'htmlContent' => $html,
		'tags' => array( 'inquiry-confirmation' ),
	);
}

/** One attempt only. Never writes output, retries, or changes an inquiry result. */
function sendInquiryConfirmation( array $fields, string $siteRoot, string $reference ): bool {
	try {
		$payload = confirmationPayload( $fields, $reference );
		return sendEmailPayload( $payload, $siteRoot, $reference, 'confirmation_' )['success'];
	} catch ( \Throwable $error ) {
		logContactEvent( $siteRoot, $reference, 'confirmation_internal_error' );
		return false;
	}
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

/** Reusable notification sender. Only the after-response worker confirms receipt. */
function sendInquiry( string $sitePrefix, array $fields, string $siteRoot ): array {
	$reference = bin2hex( random_bytes( 12 ) );
	try {
		$fields = validateFields( $fields, array( $fields['service'] ?? '' ) );
		$payload = inquiryPayload( $sitePrefix, $fields, $reference, new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
		return sendEmailPayload( $payload, $siteRoot, $reference );
	} catch ( \Throwable $error ) {
		return array( 'success' => false, 'reference' => $reference, 'messageId' => '', 'http_code' => 0 );
	}
}

/** Shared TLS-verified transport; statuses use fixed internal prefixes only. */
function sendEmailPayload( array $payload, string $siteRoot, string $reference, string $statusPrefix = '' ): array {
	$failure = array( 'success' => false, 'reference' => $reference, 'messageId' => '', 'http_code' => 0 );
	$log = false;
	try {
		$private = privateDirectory( $siteRoot );
		$logPath = $private . '/mohfam-mail.log';
		if ( is_link( $logPath ) ) {
			return $failure;
		}
		$log = @fopen( $logPath, 'ab' );
		if ( false === $log || ! @chmod( $logPath, 0600 ) || ! writeLog( $log, $reference, $statusPrefix . 'attempt' ) ) {
			return $failure;
		}
		try {
			$key = loadApiKey( $private );
		} catch ( \Throwable $error ) {
			writeLog( $log, $reference, $statusPrefix . 'config_unavailable_or_invalid' );
			return $failure;
		}
		if ( ! function_exists( 'curl_init' ) || ! in_array( 'https', curl_version()['protocols'] ?? array(), true ) ) {
			writeLog( $log, $reference, $statusPrefix . 'curl_https_unavailable' );
			return $failure;
		}
		$curl = curl_init( 'https://api.brevo.com/v3/smtp/email' );
		if ( false === $curl ) {
			writeLog( $log, $reference, $statusPrefix . 'curl_initialization_failed' );
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
				writeLog( $log, $reference, $statusPrefix . 'transport_options_failed' );
				return $failure;
			}
			$response = curl_exec( $curl );
			$httpCode = (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE );
			$failure['http_code'] = $httpCode;
			if ( false === $response ) {
				writeLog( $log, $reference, $statusPrefix . 'transport_error_' . (int) curl_errno( $curl ), $httpCode );
				return $failure;
			}
		} finally {
			curl_close( $curl );
			unset( $key );
		}
		$result = json_decode( $response, true );
		$messageId = is_array( $result ) ? ( $result['messageId'] ?? '' ) : '';
		if ( 201 !== $httpCode || ! validMessageId( $messageId ) ) {
			// Fixed error categories prevent provider echoes from leaking input or keys.
			writeLog( $log, $reference, $statusPrefix . ( 201 === $httpCode ? 'invalid_message_id' : 'provider_rejected' ), $httpCode );
			return $failure;
		}
		writeLog( $log, $reference, $statusPrefix . 'accepted', $httpCode, $messageId );
		return array( 'success' => true, 'reference' => $reference, 'messageId' => $messageId, 'http_code' => $httpCode );
	} catch ( \Throwable $error ) {
		if ( is_resource( $log ) ) {
			writeLog( $log, $reference, $statusPrefix . 'internal_error' );
		}
		return $failure;
	} finally {
		if ( is_resource( $log ) ) {
			fclose( $log );
		}
	}
}
