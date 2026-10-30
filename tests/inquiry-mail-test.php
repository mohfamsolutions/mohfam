<?php
/** All transport calls are replaced below. No real API key or network is used. */
namespace MohFam\Mail {
	function register_shutdown_function( $callback, ...$args ) {
		if ( ! empty( $GLOBALS['defer_fail'] ) ) { throw new \RuntimeException( 'Mocked registration failure' ); }
		$GLOBALS['deferred_contacts'][] = array( 'callback' => $callback, 'args' => $args );
	}
	function fastcgi_finish_request() { $GLOBALS['response_finished'] = true; $GLOBALS['response_order'][] = 'finished'; return $GLOBALS['finish_success'] ?? true; }
	function curl_version() { return array( 'protocols' => array( 'https' ) ); }
	function curl_init( $url ) { $GLOBALS['transport_calls']++; $GLOBALS['transport_url'] = $url; if ( str_contains( $url, '/contacts' ) ) { $GLOBALS['response_order'][] = 'contact'; } return (object) array( 'url' => $url ); }
	function curl_setopt_array( $curl, $options ) { $GLOBALS['transport_options'] = $options; $curl->options = $options; return true; }
	function curl_exec( $curl ) {
		if ( str_contains( $curl->url, '/v3/contacts' ) ) {
			if ( empty( $GLOBALS['contact_responses'] ) ) { throw new \RuntimeException( 'Unexpected mocked contact API call' ); }
			$next = array_shift( $GLOBALS['contact_responses'] );
			$curl->status = $next[0];
			$GLOBALS['contact_requests'][] = array( 'url' => $curl->url, 'options' => $curl->options );
			return is_array( $next[1] ) ? json_encode( $next[1] ) : $next[1];
		}
		return $GLOBALS['transport_body'];
	}
	function curl_getinfo( $curl, $option ) { return $curl->status ?? $GLOBALS['transport_status']; }
	function curl_errno( $curl ) { return 28; }
	function curl_close( $curl ) {}
}

namespace {
	if ( ! function_exists( 'fastcgi_finish_request' ) ) {
		function fastcgi_finish_request() { throw new \RuntimeException( 'Real response finisher forbidden in unit tests' ); }
	}
	function wp_json_encode( $data ) { return json_encode( $data ); }
	// Minimal cURL symbols permit offline tests even when CLI has no extension.
	if ( ! function_exists( 'curl_init' ) ) {
		function curl_init( $url ) { throw new \RuntimeException( 'Real transport forbidden in tests' ); }
	}
	foreach ( array( 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLOPT_HTTPHEADER', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_TIMEOUT', 'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_SSL_VERIFYPEER', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_FOLLOWLOCATION', 'CURLOPT_PROTOCOLS', 'CURLPROTO_HTTPS', 'CURLINFO_HTTP_CODE' ) as $index => $constant ) {
		if ( ! defined( $constant ) ) { define( $constant, 1000 + $index ); }
	}
	function check( $value, $description ) { if ( ! $value ) { throw new \RuntimeException( $description ); } $GLOBALS['checks']++; }
	function expectInvalid( $fields, $description ) {
		try { \MohFam\Mail\validateFields( $fields, array( 'Car Wash', 'General Inquiry' ) ); }
		catch ( \InvalidArgumentException $error ) { check( true, $description ); return; }
		throw new \RuntimeException( $description );
	}
	class JsonResponse extends \RuntimeException {
		public $data;
		public $status;
		public $success;
		public function __construct( $success, $data, $status ) { parent::__construct( 'JSON response' ); $this->success = $success; $this->data = $data; $this->status = $status; }
	}
	class WP_Error {}
	function add_action( ...$args ) {}
	function wp_schedule_single_event( ...$args ) { throw new \RuntimeException( 'WP-Cron must not be used for new inquiries' ); }
	function home_url( $path ) { return 'https://mohfam.co' . $path; }
	function wp_parse_url( $value ) { return parse_url( $value ); }
	function wp_unslash( $value ) { return stripslashes( $value ); }
	function wp_hash( $value, $scheme ) { return hash_hmac( 'md5', $value, 'test-timing-secret' ); }
	function wp_salt( $scheme ) { return 'test-ip-hash-secret'; }
	function wp_create_nonce( $action ) { return 'test-nonce'; }
	function wp_verify_nonce( $nonce, $action ) { return 'test-nonce' === $nonce; }
	function nocache_headers() {}
	function wp_send_json_error( $data, $status ) { throw new JsonResponse( false, $data, $status ); }
	function wp_send_json_success( $data ) { throw new JsonResponse( true, $data, 200 ); }
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function mohfam_validate_turnstile( $token, $ip ) { return 'test-turnstile' === $token ? true : new WP_Error(); }
	function sanitize_text_field( $value ) { return strip_tags( $value ); }
	function sanitize_textarea_field( $value ) { return strip_tags( $value ); }
	function current_time( $format ) { return gmdate( $format ); }
	function esc_url_raw( $url ) { return $url; }
	function mohfam_store_submission( ...$args ) { return 1; }
	function token( $age = 5, $ip = '192.0.2.1' ) {
		$payload = ( time() - $age ) . '.' . str_repeat( 'a', 32 );
		return $payload . '.' . wp_hash( $payload . '|' . $ip, 'nonce' );
	}
	function request( $overrides = array(), $server = array() ) {
		$_SERVER = array_merge( array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://mohfam.co', 'REMOTE_ADDR' => '192.0.2.1' ), $server );
		$_POST = array_merge( $GLOBALS['fields'], array( 'nonce' => 'test-nonce', 'form_kind' => 'inquiry', 'website' => '', 'form_token' => token(), 'cf-turnstile-response' => 'test-turnstile' ), $overrides );
		$_POST = array_map( static function ( $value ) { return is_string( $value ) ? addslashes( $value ) : $value; }, $_POST );
		try { mohfam_handle_form_submission(); } catch ( JsonResponse $result ) { return $result; }
		throw new \RuntimeException( 'Handler did not return JSON' );
	}
	function clearRate() { $file = $GLOBALS['private'] . '/mohfam-inquiry-rate.json'; if ( is_file( $file ) ) { unlink( $file ); } }
	function removeFixture( $dir ) { foreach ( scandir( $dir ) as $name ) { if ( '.' === $name || '..' === $name ) { continue; } $path = $dir . '/' . $name; if ( is_dir( $path ) && ! is_link( $path ) ) { removeFixture( $path ); } else { unlink( $path ); } } rmdir( $dir ); }

	$GLOBALS['checks'] = 0;
	$GLOBALS['transport_calls'] = 0;
	$GLOBALS['transport_status'] = 201;
	$GLOBALS['transport_body'] = '{"messageId":"<test-123@brevo.example>"}';
	$fixture = sys_get_temp_dir() . '/mohfam-mail-test-' . bin2hex( random_bytes( 8 ) );
	mkdir( $fixture . '/public_html/mohfam.co', 0700, true );
	mkdir( $fixture . '/private', 0700 );
	$GLOBALS['private'] = realpath( $fixture . '/private' );
	define( 'ABSPATH', $fixture . '/public_html/mohfam.co/' );
	require __DIR__ . '/../twentytwentyfive.child_.theme_.-.jaimemasc.com_/inc/inquiry-mail.php';
	require __DIR__ . '/../twentytwentyfive.child_.theme_.-.jaimemasc.com_/inc/inquiry-handler.php';
	try {
		$GLOBALS['fields'] = array( 'name' => 'Taylor <Example>', 'company' => 'Example & Co', 'email' => 'visitor@example.com', 'phone' => '+1 818 555 0100', 'service' => 'Car Wash', 'message' => "Please discuss <script>alert(1)</script> & care.\nSecond line.", 'page_url' => 'https://mohfam.co/contact/#inquiry' );
		$fields = $GLOBALS['fields'];
		check( \MohFam\Mail\privateDirectory( ABSPATH ) === $GLOBALS['private'], 'Private directory resolved outside public_html' );
		try { \MohFam\Mail\privateDirectory( $fixture . '/private' ); throw new \Exception( 'Invalid root accepted' ); } catch ( \RuntimeException $error ) { check( true, 'Reject non-cPanel root' ); }
		expectInvalid( array_replace( $fields, array( 'name' => "Taylor\r\nBcc: another@example.com" ) ), 'Reject name header injection' );
		expectInvalid( array_replace( $fields, array( 'email' => "visitor@example.com\n" ) ), 'Reject email control before trim' );
		expectInvalid( array_replace( $fields, array( 'name' => array( 'Taylor' ) ) ), 'Reject arrays' );
		expectInvalid( array_replace( $fields, array( 'name' => str_repeat( 'a', 121 ) ) ), 'Reject long name rather than truncate' );
		expectInvalid( array_replace( $fields, array( 'message' => str_repeat( 'a', 4001 ) ) ), 'Reject oversized message' );
		expectInvalid( array_replace( $fields, array( 'email' => 'invalid' ) ), 'Reject invalid email' );
		expectInvalid( array_replace( $fields, array( 'service' => 'Unknown Service' ) ), 'Reject unsupported service' );
		expectInvalid( array_replace( $fields, array( 'message' => "bad\x00body" ) ), 'Reject message controls' );
		expectInvalid( array_replace( $fields, array( 'marketing_opt_in' => 'true' ) ), 'Reject unsupported checkbox values' );
		expectInvalid( array_replace( $fields, array( 'marketing_opt_in' => array( '1' ) ) ), 'Reject array checkbox values' );
		$unicode = array_replace( $fields, array( 'name' => str_repeat( 'é', 120 ) ) );
		check( \MohFam\Mail\validateFields( $unicode, array( 'Car Wash' ) )['name'] === $unicode['name'], 'UTF-8 length counts characters' );
		$summer = \MohFam\Mail\inquiryPayload( 'MohFam', $fields, 'test-ref', new \DateTimeImmutable( '2026-07-01T12:00:00Z' ) );
		$winter = \MohFam\Mail\inquiryPayload( 'MohFam Car Wash', $fields, 'test-ref', new \DateTimeImmutable( '2026-12-01T12:00:00Z' ) );
		check( '[MohFam] Car Wash inquiry — Taylor <Example>' === $summer['subject'], 'Exact subject format' );
		check( $fields['email'] === $summer['replyTo']['email'], 'Visitor Reply-To' );
		check( 'website@mohfam.co' === $summer['sender']['email'] && 'mohfamsolutions@gmail.com' === $summer['to'][0]['email'], 'Fixed sender and recipient' );
		check( ! str_contains( $summer['htmlContent'], '<script>' ) && str_contains( $summer['htmlContent'], '&lt;script&gt;' ), 'HTML escaped' );
		check( str_contains( $summer['textContent'], '05:00:00 PDT (-07:00)' ) && str_contains( $winter['textContent'], '04:00:00 PST (-08:00)' ), 'Los Angeles DST and winter timestamps' );
		foreach ( $fields as $value ) { check( str_contains( $summer['textContent'], $value ), 'All fields included' ); }
		check( ! \MohFam\Mail\sendInquiry( 'MohFam', $fields, ABSPATH )['success'] && 0 === $GLOBALS['transport_calls'], 'Missing config fails before transport' );
		check( ! \MohFam\Mail\checkReadiness( ABSPATH ), 'Readiness fails without private config' );
		$configPath = $fixture . '/private/mohfam-mail.php';
		file_put_contents( $configPath, "<?php return ['brevo_api_key' => 'test-only-not-a-real-api-key'];" );
		$beforeReadiness = $GLOBALS['transport_calls'];
		check( \MohFam\Mail\checkReadiness( ABSPATH ) && $GLOBALS['transport_calls'] === $beforeReadiness, 'Readiness validates setup without transport' );
		$result = \MohFam\Mail\sendInquiry( 'MohFam', $fields, ABSPATH );
		check( $result['success'] && '<test-123@brevo.example>' === $result['messageId'], '201 with messageId succeeds' );
		$options = $GLOBALS['transport_options'];
		check( 'https://api.brevo.com/v3/smtp/email' === $GLOBALS['transport_url'], 'Exact fixed API endpoint' );
		check( 10 === $options[CURLOPT_TIMEOUT] && true === $options[CURLOPT_SSL_VERIFYPEER] && 2 === $options[CURLOPT_SSL_VERIFYHOST] && false === $options[CURLOPT_FOLLOWLOCATION], 'Timeout, TLS, no redirects' );
		check( in_array( 'accept: application/json', $options[CURLOPT_HTTPHEADER], true ) && in_array( 'content-type: application/json', $options[CURLOPT_HTTPHEADER], true ), 'JSON headers' );
		foreach ( array( array( 200, '{"messageId":"<id@example>"}' ), array( 201, '{}' ), array( 201, '{"messageId":""}' ), array( 401, '{"message":"test-only-not-a-real-api-key"}' ), array( 500, 'invalid JSON' ), array( 0, false ) ) as $case ) {
			$GLOBALS['transport_status'] = $case[0]; $GLOBALS['transport_body'] = $case[1];
			check( ! \MohFam\Mail\sendInquiry( 'MohFam', $fields, ABSPATH )['success'], 'Reject malformed/failed API result' );
		}
		$log = file_get_contents( $fixture . '/private/mohfam-mail.log' );
		check( ! str_contains( $log, 'test-only-not-a-real-api-key' ) && ! str_contains( $log, $fields['email'] ) && ! str_contains( $log, 'alert(1)' ), 'No secrets, contacts or body in transport log' );
		check( str_contains( $log, 'provider_rejected' ) && str_contains( $log, 'transport_error_28' ) && str_contains( $log, 'test-123@brevo.example' ), 'Sanitized errors and messageId logged' );
		file_put_contents( $configPath, "<?php echo 'test-output-must-not-leak'; return [];" );
		ob_start(); $result = \MohFam\Mail\sendInquiry( 'MohFam', $fields, ABSPATH ); $output = ob_get_clean();
		check( ! $result['success'] && '' === $output, 'Bad config output suppressed' );
		file_put_contents( $configPath, '<?php this is invalid syntax' );
		check( ! \MohFam\Mail\sendInquiry( 'MohFam', $fields, ABSPATH )['success'], 'Malformed config fails safely' );
		file_put_contents( $configPath, "<?php return ['brevo_api_key' => 'test-only-not-a-real-api-key'];" );
		check( \MohFam\Mail\consumeRateLimit( $GLOBALS['private'], 'hashed-test-ip', 1000 ), 'Initial rate accepted' );
		check( ! \MohFam\Mail\consumeRateLimit( $GLOBALS['private'], 'hashed-test-ip', 1010 ), '20 second spacing enforced' );
		foreach ( array( 1020, 1040, 1060, 1080 ) as $now ) { check( \MohFam\Mail\consumeRateLimit( $GLOBALS['private'], 'hashed-test-ip', $now ), 'Rate quota usable' ); }
		check( ! \MohFam\Mail\consumeRateLimit( $GLOBALS['private'], 'hashed-test-ip', 1100 ), 'Sixth attempt blocked' );
		check( \MohFam\Mail\consumeRateLimit( $GLOBALS['private'], 'hashed-test-ip', 1900 ), 'Window expires' );
		clearRate();
		$GLOBALS['transport_status'] = 201; $GLOBALS['transport_body'] = '{"messageId":"<test-123@brevo.example>"}';
		$before = $GLOBALS['transport_calls'];
		foreach ( array(
			array( array( 'nonce' => 'bad' ), array(), 403 ),
			array( array(), array( 'REQUEST_METHOD' => 'GET' ), 405 ),
			array( array(), array( 'HTTP_ORIGIN' => 'https://evil.example' ), 403 ),
			array( array(), array( 'HTTP_ORIGIN' => 'http://mohfam.co' ), 403 ),
			array( array(), array( 'HTTP_ORIGIN' => 'https://mohfam.co:444' ), 403 ),
			array( array(), array( 'CONTENT_LENGTH' => 65537 ), 413 ),
			array( array( 'website' => 'spam' ), array(), 422 ),
			array( array( 'form_kind' => 'unknown' ), array(), 400 ),
			array( array( 'form_token' => token( 0 ) ), array(), 422 ),
			array( array( 'form_token' => token( 7201 ) ), array(), 422 ),
			array( array( 'form_token' => token( 5, '192.0.2.2' ) ), array(), 422 ),
			array( array( 'name' => array( 'bad' ) ), array(), 422 ),
			array( array( 'page_url' => 'https://evil.example/' ), array(), 422 ),
			array( array( 'cf-turnstile-response' => 'invalid' ), array(), 403 ),
		) as $case ) {
			clearRate(); $result = request( $case[0], $case[1] );
			check( ! $result->success && $result->status === $case[2] && str_contains( $result->data['message'], '+1 (818) 818-1181' ), 'Invalid request rejected with backup phone: ' . $case[2] );
		}
		check( $GLOBALS['transport_calls'] === $before, 'All invalid requests avoided transport' );
		clearRate(); $result = request();
		check( $result->success && $GLOBALS['transport_calls'] === $before + 1, 'Valid complete handler submits once' );
		$queued = end( $GLOBALS['deferred_contacts'] );
		check( 'MohFam\\Mail\\runContactAfterResponse' === $queued['callback'] && false === $queued['args'][1]['marketing_opt_in'], 'Successful email registers unchecked after-response sync' );
		check( ! isset( $queued['args'][1]['message'] ) && ! isset( $queued['args'][1]['phone'] ), 'Deferred job excludes inquiry message and unnecessary fields' );
		check( ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'America/Los_Angeles' ) ) )->format( 'Y-m-d' ) === $queued['args'][4], 'Deferred job captures Los Angeles inquiry date' );
		check( 429 === request()->status, 'Handler rate limit stops duplicate transport' );
		clearRate(); $GLOBALS['transport_status'] = 503;
		$queuedCount = count( $GLOBALS['deferred_contacts'] );
		$result = request(); check( ! $result->success && 503 === $result->status, 'Provider failure never reports success' );
		check( $queuedCount === count( $GLOBALS['deferred_contacts'] ), 'Failed notification never registers contact sync' );
		$_SERVER = array( 'REQUEST_METHOD' => 'POST', 'HTTP_ORIGIN' => 'https://mohfam.co', 'REMOTE_ADDR' => '192.0.2.1' );
		$beforeSession = $GLOBALS['transport_calls'];
		try { mohfam_inquiry_session(); } catch ( JsonResponse $session ) {
			check( $session->success && 'test-nonce' === $session->data['nonce'], 'Session returns fresh nonce' );
			check( ! mohfam_inquiry_check_timing( $session->data['form_token'], time() ), 'Fresh session cannot immediately submit' );
			check( mohfam_inquiry_check_timing( $session->data['form_token'], time() + 3 ), 'Signed three-second minimum works' );
		}
		check( $beforeSession === $GLOBALS['transport_calls'], 'Session initialization sends no email' );

		// Contact API cases use a separate deterministic response queue. No real API.
		$contact = array_intersect_key( $fields, array_flip( array( 'name', 'email', 'service' ) ) );
		$contact['marketing_opt_in'] = false;
		$sync = static function ( $data, $responses ) {
			$GLOBALS['contact_responses'] = $responses;
			$GLOBALS['contact_requests'] = array();
			return \MohFam\Mail\syncInquiryContact( 'mohfam.co', $data, ABSPATH, 'test-contact-ref', '2026-10-29' );
		};
		$lastPayload = static function () {
			$last = end( $GLOBALS['contact_requests'] );
			return json_decode( $last['options'][ CURLOPT_POSTFIELDS ], true );
		};
		check( $sync( $contact, array( array( 404, array( 'code' => 'document_not_found' ) ), array( 201, array( 'id' => 10 ) ) ) ), 'New contact created' );
		$payload = $lastPayload();
		check( array( 3 ) === $payload['listIds'] && true === $payload['updateEnabled'], 'Inquiry list only with additive upsert' );
		check( false === $payload['attributes']['MARKETING_OPT_IN'], 'New unchecked contact is false' );
		check( 'Taylor' === $payload['attributes']['FIRSTNAME'] && '<Example>' === $payload['attributes']['LASTNAME'], 'Split name on first space' );
		check( 'mohfam.co' === $payload['attributes']['SOURCE_SITE'] && '2026-10-29' === $payload['attributes']['LAST_INQUIRY_AT'] && 'Car Wash' === $payload['attributes']['SERVICE_INTEREST'], 'Source, LA date, and service attributes' );
		$requestOptions = end( $GLOBALS['contact_requests'] )['options'];
		check( 10 === $requestOptions[CURLOPT_TIMEOUT] && true === $requestOptions[CURLOPT_SSL_VERIFYPEER] && 2 === $requestOptions[CURLOPT_SSL_VERIFYHOST], 'Contact API timeout and TLS' );
		check( $GLOBALS['contact_requests'][0]['url'] === 'https://api.brevo.com/v3/contacts/visitor%40example.com?identifierType=email_id', 'Lookup email URL encoded' );
		check( end( $GLOBALS['contact_requests'] )['url'] === 'https://api.brevo.com/v3/contacts', 'Exact contact POST endpoint' );
		$opted = array_replace( $contact, array( 'marketing_opt_in' => true, 'name' => 'Taylor One Two' ) );
		check( $sync( $opted, array( array( 404, array() ), array( 201, array( 'id' => 10 ) ) ) ), 'New opt-in succeeds' );
		$payload = $lastPayload();
		check( array( 3, 4 ) === $payload['listIds'] && true === $payload['attributes']['MARKETING_OPT_IN'], 'Opt-in adds Newsletter list 4 and true attribute' );
		check( 'One Two' === $payload['attributes']['LASTNAME'], 'Remaining full name retained as last name' );
		$single = \MohFam\Mail\contactPayload( 'mohfam.co', array_replace( $contact, array( 'name' => 'Taylor' ) ), '2026-10-29', null );
		check( '' === $single['attributes']['LASTNAME'], 'Single word leaves last name empty' );
		$existing = array( 'id' => 10, 'attributes' => array( 'MARKETING_OPT_IN' => false ), 'listIds' => array( 3 ) );
		check( $sync( $opted, array( array( 200, $existing ), array( 204, '' ) ) ), 'Existing contact updated, not duplicated' );
		$payload = $lastPayload();
		check( true === $payload['updateEnabled'] && array( 3, 4 ) === $payload['listIds'], 'Existing contact can opt in' );
		$existing['attributes']['MARKETING_OPT_IN'] = true; $existing['listIds'] = array( 3, 4, 99 );
		check( $sync( $contact, array( array( 200, $existing ), array( 204, '' ) ) ), 'Later unchecked inquiry syncs' );
		$payload = $lastPayload();
		check( true === $payload['attributes']['MARKETING_OPT_IN'] && array( 3 ) === $payload['listIds'] && ! isset( $payload['unlinkListIds'] ), 'Prior opt-in and Newsletter membership never removed' );
		check( ! isset( $payload['emailBlacklisted'] ), 'Sync never resets unsubscribe/suppression status' );
		$existing['attributes']['MARKETING_OPT_IN'] = false;
		check( $sync( $contact, array( array( 200, $existing ), array( 204, '' ) ) ), 'Unchecked existing contact syncs' );
		check( ! array_key_exists( 'MARKETING_OPT_IN', $lastPayload()['attributes'] ), 'Unchecked updates omit false to protect concurrent opt-ins' );
		check( $sync( $contact, array( array( 200, array( 'id' => 10 ) ), array( 204, '' ) ) ), 'Existing contacts without attributes can receive inquiry details safely' );
		foreach ( array( 'mohfamsecurity.com' => 11, 'mohfamcommercialcleaning.com' => 12, 'mohfampoolcleaning.com' => 13, 'mohfamlandscaping.com' => 14, 'mohfamcarwash.com' => 15 ) as $site => $list ) {
			$mapped = \MohFam\Mail\contactPayload( $site, $opted, '2026-10-29', null );
			check( array( $list ) === $mapped['listIds'] && $site === $mapped['attributes']['SOURCE_SITE'], 'Other sites use configured inquiry list without a Newsletter list' );
		}
		check( ! $sync( $contact, array( array( 500, array( 'message' => 'test-only-not-a-real-api-key' ) ) ) ) && 1 === count( $GLOBALS['contact_requests'] ), 'Failed lookup never risks overwriting unknown consent' );
		check( ! $sync( $contact, array( array( 200, 'malformed' ) ) ), 'Malformed existing contact fails safely' );
		check( ! $sync( $contact, array( array( 404, array() ), array( 503, array( 'message' => 'private rejected contact body' ) ) ) ), 'Contact upsert failure is caught' );
		check( ! $sync( $contact, array( array( 0, false ) ) ), 'Contact transport timeout is caught' );
		$GLOBALS['transport_status'] = 201; $GLOBALS['transport_body'] = '{"messageId":"<test-123@brevo.example>"}';
		clearRate(); $GLOBALS['defer_fail'] = true;
		check( request()->success, 'Registration failure never changes accepted email success' );
		$GLOBALS['defer_fail'] = false;
		clearRate(); $visitor = request( array( 'marketing_opt_in' => '1' ) );
		$job = end( $GLOBALS['deferred_contacts'] );
		check( $visitor->success && true === $job['args'][1]['marketing_opt_in'], 'Checked consent carried to deferred worker' );
		$GLOBALS['contact_responses'] = array( array( 404, array() ), array( 503, array( 'message' => 'private rejected contact body' ) ) );
		$GLOBALS['response_order'] = array();
		call_user_func_array( $job['callback'], $job['args'] );
		check( $visitor->success && 200 === $visitor->status, 'Contact API failure cannot change completed visitor success' );
		check( array( 'finished', 'contact', 'contact' ) === $GLOBALS['response_order'], 'Response is finished before any contact request starts' );
		$beforeFailedFinish = $GLOBALS['transport_calls'];
		$GLOBALS['finish_success'] = false;
		call_user_func_array( $job['callback'], $job['args'] );
		check( $beforeFailedFinish === $GLOBALS['transport_calls'] && $visitor->success, 'Failed response finish cannot block visitor with contact API work' );
		$GLOBALS['finish_success'] = true;
		$contactLog = file_get_contents( $fixture . '/private/mohfam-mail.log' );
		check( str_contains( $contactLog, 'contact_upsert_failed' ) && str_contains( $contactLog, 'contact_defer_failed' ), 'Contact failure categories privately logged' );
		check( ! str_contains( $contactLog, 'private rejected contact body' ) && ! str_contains( $contactLog, 'test-only-not-a-real-api-key' ) && ! str_contains( $contactLog, 'visitor@example.com' ), 'Contact logs contain no provider bodies, keys, or email addresses' );
		echo 'Passed ' . $GLOBALS['checks'] . " offline inquiry checks; no network or email used.\n";
	} finally { removeFixture( $fixture ); }
}
