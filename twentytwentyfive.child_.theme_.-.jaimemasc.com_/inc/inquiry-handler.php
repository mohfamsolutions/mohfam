<?php
/** WordPress boundary for the shared homepage and contact inquiry forms. */

function mohfam_inquiry_error( $message, $status = 400, $reference = '' ) {
	$message .= ' For help, call +1 (818) 818-1181.';
	if ( '' !== $reference ) {
		$message .= ' Reference: ' . $reference . '.';
	}
	wp_send_json_error( array( 'message' => $message ), $status );
}

function mohfam_inquiry_origin( $url ) {
	$parts = wp_parse_url( $url );
	if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
		return '';
	}
	$scheme = strtolower( $parts['scheme'] );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	$port = $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 );
	return $scheme . '://' . strtolower( $parts['host'] ) . ':' . $port;
}

function mohfam_inquiry_check_request() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		mohfam_inquiry_error( 'Please submit the form from our website.', 405 );
	}
	if ( (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 65536 ) {
		mohfam_inquiry_error( 'Your request is too large. Please shorten your message.', 413 );
	}
	$source = $_SERVER['HTTP_ORIGIN'] ?? ( $_SERVER['HTTP_REFERER'] ?? '' );
	$expected = mohfam_inquiry_origin( home_url( '/' ) );
	if ( '' === $expected || $expected !== mohfam_inquiry_origin( $source ) ) {
		mohfam_inquiry_error( 'Please open the form on our website and try again.', 403 );
	}
}

function mohfam_inquiry_post_string( $key ) {
	if ( isset( $_POST[ $key ] ) && ! is_string( $_POST[ $key ] ) ) {
		mohfam_inquiry_error( 'Please check the information in your form.', 422 );
	}
	return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
}

/** Fresh tokens avoid stale form timing/nonces on cached public pages. No email. */
function mohfam_inquiry_session() {
	mohfam_inquiry_check_request();
	nocache_headers();
	if ( ! \MohFam\Mail\checkReadiness( ABSPATH ) ) {
		mohfam_inquiry_error( 'The inquiry form is temporarily unavailable. Please contact us directly.', 503 );
	}
	$issued = time();
	$random = bin2hex( random_bytes( 16 ) );
	$payload = $issued . '.' . $random;
	$signature = wp_hash( $payload . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ), 'nonce' );
	wp_send_json_success( array(
		'nonce' => wp_create_nonce( 'mohfam_form_submission' ),
		'form_token' => $payload . '.' . $signature,
		'contact_response_mode' => \MohFam\Mail\responseFinishMode(),
	) );
}
add_action( 'wp_ajax_mohfam_form_session', 'mohfam_inquiry_session' );
add_action( 'wp_ajax_nopriv_mohfam_form_session', 'mohfam_inquiry_session' );

function mohfam_inquiry_check_timing( $token, $now ) {
	$parts = explode( '.', $token );
	if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $parts[1] ) ) {
		return false;
	}
	$expected = wp_hash( $parts[0] . '.' . $parts[1] . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ), 'nonce' );
	$age = $now - (int) $parts[0];
	return hash_equals( $expected, $parts[2] ) && $age >= 3 && $age <= 7200;
}

function mohfam_handle_form_submission() {
	mohfam_inquiry_check_request();
	$nonce = mohfam_inquiry_post_string( 'nonce' );
	if ( ! wp_verify_nonce( $nonce, 'mohfam_form_submission' ) ) {
		mohfam_inquiry_error( 'Your form session expired. Please refresh the page and try again.', 403 );
	}
	if ( 'inquiry' !== mohfam_inquiry_post_string( 'form_kind' ) ) {
		mohfam_inquiry_error( 'This form could not be identified.' );
	}
	if ( '' !== mohfam_inquiry_post_string( 'website' ) ) {
		mohfam_inquiry_error( 'Your request could not be verified.', 422 );
	}
	if ( ! mohfam_inquiry_check_timing( mohfam_inquiry_post_string( 'form_token' ), time() ) ) {
		mohfam_inquiry_error( 'Please wait a few seconds before submitting. If the form has been open for more than two hours, refresh the page.', 422 );
	}
	$input = array();
	foreach ( array( 'name', 'company', 'email', 'phone', 'service', 'message', 'page_url' ) as $key ) {
		$input[ $key ] = mohfam_inquiry_post_string( $key );
	}
	$input['marketing_opt_in'] = mohfam_inquiry_post_string( 'marketing_opt_in' );
	// Older cached forms and links may still submit the former waitlist value.
	if ( 'Landscaping (Waitlist)' === $input['service'] ) {
		$input['service'] = 'Landscaping';
	}
	try {
		$fields = \MohFam\Mail\validateFields( $input, \MohFam\Mail\inquiryServices() );
	} catch ( \InvalidArgumentException $error ) {
		mohfam_inquiry_error( $error->getMessage(), 422 );
	}
	if ( mohfam_inquiry_origin( $fields['page_url'] ) !== mohfam_inquiry_origin( home_url( '/' ) ) ) {
		mohfam_inquiry_error( 'Please submit the form from our website.', 422 );
	}
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		mohfam_inquiry_error( 'Your request could not be verified.', 403 );
	}
	try {
		$private = \MohFam\Mail\privateDirectory( ABSPATH );
		$ipKey = hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
		$allowed = \MohFam\Mail\consumeRateLimit( $private, $ipKey, time() );
	} catch ( \Throwable $error ) {
		mohfam_inquiry_error( 'We could not send your inquiry right now. Please try again later.', 503 );
	}
	if ( ! $allowed ) {
		mohfam_inquiry_error( 'Please wait before trying again. You can submit up to five requests in 15 minutes, at least 20 seconds apart.', 429 );
	}
	$turnstile = mohfam_validate_turnstile( mohfam_inquiry_post_string( 'cf-turnstile-response' ), $ip );
	if ( is_wp_error( $turnstile ) ) {
		mohfam_inquiry_error( 'Security verification failed. Please refresh the page and try again.', 403 );
	}
	$site = strtolower( (string) parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$settings = \MohFam\Mail\siteSettings( $site );
	$result = \MohFam\Mail\sendInquiry( trim( $settings['prefix'], '[]' ), $fields, ABSPATH );
	// Preserve the existing private WordPress inbox, but it never determines success.
	mohfam_store_submission(
		'Inquiry — ' . sanitize_text_field( $fields['name'] ) . ' — ' . current_time( 'M j, Y g:i a' ),
		sanitize_textarea_field( $fields['message'] ),
		array(
			'form_kind' => 'inquiry',
			'name' => sanitize_text_field( $fields['name'] ),
			'company' => sanitize_text_field( $fields['company'] ),
			'email' => $fields['email'],
			'phone' => sanitize_text_field( $fields['phone'] ),
			'service' => $fields['service'],
			'page_url' => esc_url_raw( $fields['page_url'] ),
			'admin_mail_sent' => $result['success'],
			'mail_reference' => $result['reference'],
			'mail_message_id' => $result['messageId'],
			'marketing_opt_in' => $fields['marketing_opt_in'] ? '1' : '0',
		)
	);
	if ( ! $result['success'] ) {
		mohfam_inquiry_error( 'We could not send your inquiry. Please try again later.', 503, $result['reference'] );
	}
	\MohFam\Mail\deferContactSync( $site, $fields, ABSPATH, $result['reference'], $result );
	$data = array( 'message' => 'Thank you. Your inquiry was sent to the MohFam team. Reference: ' . $result['reference'] . '.' );
	\MohFam\Mail\prepareContactResponse( strlen( wp_json_encode( array( 'success' => true, 'data' => $data ) ) ) );
	wp_send_json_success( $data );
}

/** Compatibility only: drain any events scheduled by the previous deployment. */
function mohfam_sync_inquiry_contact( $site, $fields, $reference, $date ) {
	\MohFam\Mail\syncInquiryContact( $site, $fields, ABSPATH, $reference, $date );
}
add_action( 'mohfam_sync_inquiry_contact', 'mohfam_sync_inquiry_contact', 10, 4 );
add_action( 'wp_ajax_mohfam_submit_form', 'mohfam_handle_form_submission' );
add_action( 'wp_ajax_nopriv_mohfam_submit_form', 'mohfam_handle_form_submission' );
