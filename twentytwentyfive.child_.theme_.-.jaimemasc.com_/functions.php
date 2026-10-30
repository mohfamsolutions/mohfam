<?php
/**
 * Theme setup for the MohFam child theme.
 *
 * @package MohFam
 */

/**
 * Send former division landing pages to their dedicated websites.
 */
function mohfam_redirect_division_pages() {
	if ( is_admin() || is_preview() || is_feed() || is_embed() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? '', array( 'GET', 'HEAD' ), true ) ) {
		return;
	}

	$request_path = untrailingslashit( (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) );
	$destinations = array(
		'/mohfam-security'             => 'https://mohfamsecurity.com/',
		'/services/mohfam-security'    => 'https://mohfamsecurity.com/',
		'/landscaping'                 => 'https://mohfamlandscaping.com/',
		'/services/landscaping'        => 'https://mohfamlandscaping.com/',
		'/commercial-cleaning'          => 'https://mohfamcommercialcleaning.com/',
		'/services/commercial-cleaning' => 'https://mohfamcommercialcleaning.com/',
		'/pool-cleaning'                => 'https://mohfampoolcleaning.com/',
		'/pool-care'                    => 'https://mohfampoolcleaning.com/',
		'/services/pool-cleaning'       => 'https://mohfampoolcleaning.com/',
		'/services/pool-care'           => 'https://mohfampoolcleaning.com/',
		'/car-wash'                    => 'https://mohfamcarwash.com/',
		'/services/car-wash'           => 'https://mohfamcarwash.com/',
	);

	if ( isset( $destinations[ $request_path ] ) ) {
		wp_redirect( $destinations[ $request_path ], 301, 'MohFam' );
		exit;
	}
}
add_action( 'template_redirect', 'mohfam_redirect_division_pages', 1 );

add_action(
	'wp_enqueue_scripts',
	static function () {
		$stylesheet_path = get_stylesheet_directory() . '/style.css';
		$stylesheet_version = file_exists( $stylesheet_path )
			? (string) filemtime( $stylesheet_path )
			: wp_get_theme()->get( 'Version' );

		wp_enqueue_style(
			'mohfam-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Playfair+Display:wght@400;600;700&display=swap',
			array(),
			null
		);

		wp_enqueue_style(
			'mohfam-child',
			get_stylesheet_uri(),
			array( 'mohfam-fonts' ),
			$stylesheet_version
		);

		$experience_stylesheet_path = get_stylesheet_directory() . '/assets/css/experience.css';
		wp_enqueue_style(
			'mohfam-experience',
			get_stylesheet_directory_uri() . '/assets/css/experience.css',
			array( 'mohfam-child' ),
			file_exists( $experience_stylesheet_path ) ? (string) filemtime( $experience_stylesheet_path ) : wp_get_theme()->get( 'Version' )
		);

		$navigation_script_path = get_stylesheet_directory() . '/assets/js/navigation.js';
		wp_enqueue_script(
			'mohfam-navigation',
			get_stylesheet_directory_uri() . '/assets/js/navigation.js',
			array(),
			file_exists( $navigation_script_path ) ? (string) filemtime( $navigation_script_path ) : wp_get_theme()->get( 'Version' ),
			true
		);

		$experience_script_path = get_stylesheet_directory() . '/assets/js/experience.js';
		wp_enqueue_script(
			'mohfam-experience',
			get_stylesheet_directory_uri() . '/assets/js/experience.js',
			array( 'mohfam-navigation' ),
			file_exists( $experience_script_path ) ? (string) filemtime( $experience_script_path ) : wp_get_theme()->get( 'Version' ),
			true
		);

		if ( is_singular( 'post' ) ) {
			$share_script_path = get_stylesheet_directory() . '/assets/js/blog-share.js';
			$share_script_version = file_exists( $share_script_path )
				? (string) filemtime( $share_script_path )
				: wp_get_theme()->get( 'Version' );

			wp_enqueue_script(
				'mohfam-blog-share',
				get_stylesheet_directory_uri() . '/assets/js/blog-share.js',
				array(),
				$share_script_version,
				true
			);
		}

		if ( ! is_front_page() && ! is_page( 'contact' ) ) {
			return;
		}

		$form_script_path = get_stylesheet_directory() . '/assets/js/forms.js';
		$form_script_version = file_exists( $form_script_path )
			? (string) filemtime( $form_script_path )
			: wp_get_theme()->get( 'Version' );
		$turnstile_enabled = defined( 'MOHFAM_TURNSTILE_SITE_KEY' )
			&& defined( 'MOHFAM_TURNSTILE_SECRET_KEY' )
			&& '' !== MOHFAM_TURNSTILE_SITE_KEY
			&& '' !== MOHFAM_TURNSTILE_SECRET_KEY;
		$form_script_dependencies = array();

		if ( $turnstile_enabled ) {
			wp_enqueue_script(
				'cloudflare-turnstile',
				'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
				array(),
				null,
				true
			);
			$form_script_dependencies[] = 'cloudflare-turnstile';
		}

		wp_enqueue_script(
			'mohfam-forms',
			get_stylesheet_directory_uri() . '/assets/js/forms.js',
			$form_script_dependencies,
			$form_script_version,
			true
		);

		wp_localize_script(
			'mohfam-forms',
			'mohfamForms',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'turnstileEnabled' => $turnstile_enabled,
				'turnstileSiteKey' => $turnstile_enabled ? MOHFAM_TURNSTILE_SITE_KEY : '',
			)
		);
	}
);

/**
 * Register a private admin area for inquiry records.
 */
function mohfam_register_submission_post_type() {
	register_post_type(
		'mohfam_submission',
		array(
			'labels' => array(
				'name'          => 'Form Submissions',
				'singular_name' => 'Form Submission',
				'menu_name'     => 'Form Submissions',
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => false,
			'menu_icon'           => 'dashicons-email-alt',
			'supports'            => array( 'title', 'editor' ),
			'capabilities'        => array(
				'edit_post'              => 'manage_options',
				'read_post'              => 'manage_options',
				'delete_post'            => 'manage_options',
				'edit_posts'             => 'manage_options',
				'edit_others_posts'      => 'manage_options',
				'publish_posts'          => 'manage_options',
				'read_private_posts'     => 'manage_options',
				'delete_posts'           => 'manage_options',
				'delete_private_posts'   => 'manage_options',
				'delete_published_posts' => 'manage_options',
				'delete_others_posts'    => 'manage_options',
				'edit_private_posts'     => 'manage_options',
				'edit_published_posts'   => 'manage_options',
				'create_posts'           => 'do_not_allow',
			),
		)
	);
}
add_action( 'init', 'mohfam_register_submission_post_type' );

/**
 * Preserve the latest transport error for an administrator diagnostic notice.
 *
 * @param \WP_Error $error WordPress mail error.
 */
function mohfam_capture_mail_error( $error ) {
	set_transient(
		'mohfam_last_mail_error',
		sanitize_text_field( $error->get_error_message() ),
		HOUR_IN_SECONDS
	);
}
add_action( 'wp_mail_failed', 'mohfam_capture_mail_error' );

/**
 * Show a one-time mail transport error to administrators.
 */
function mohfam_mail_error_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$error = get_transient( 'mohfam_last_mail_error' );

	if ( ! $error ) {
		return;
	}

	delete_transient( 'mohfam_last_mail_error' );

	printf(
		'<div class="notice notice-error is-dismissible"><p><strong>MohFam email delivery error:</strong> %s</p></div>',
		esc_html( $error )
	);
}
add_action( 'admin_notices', 'mohfam_mail_error_admin_notice' );

/**
 * Add a readable details panel to saved form submissions.
 */
function mohfam_add_submission_meta_box() {
	add_meta_box(
		'mohfam-submission-details',
		'Submission Details',
		'mohfam_render_submission_meta_box',
		'mohfam_submission',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'mohfam_add_submission_meta_box' );

/**
 * Render saved submission fields in the WordPress admin.
 *
 * @param \WP_Post $post Current submission.
 */
function mohfam_render_submission_meta_box( $post ) {
	$fields = array(
		'Form type'            => 'form_kind',
		'Name'                 => 'name',
		'Company'              => 'company',
		'Email'                => 'email',
		'Phone'                => 'phone',
		'Service'              => 'service',
		'Source page'          => 'page_url',
		'Admin email sent'     => 'admin_mail_sent',
		'Visitor email sent (legacy)' => 'user_mail_sent',
		'Mail reference' => 'mail_reference',
		'Brevo message ID' => 'mail_message_id',
	);

	echo '<table class="widefat striped" style="border:0">';

	foreach ( $fields as $label => $key ) {
		$value = get_post_meta( $post->ID, '_mohfam_' . $key, true );

		if ( '' === (string) $value ) {
			continue;
		}

		if ( in_array( $key, array( 'admin_mail_sent', 'user_mail_sent' ), true ) ) {
			$value = $value ? 'Yes' : 'No';
		}

		printf(
			'<tr><th style="width:180px">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( (string) $value )
		);
	}

	echo '</table>';
}

/**
 * Build a simple, readable HTML email.
 *
 * @param string $heading Email heading.
 * @param string $intro   Introductory sentence.
 * @param array  $fields  Label/value pairs.
 * @param string $footer  Footer sentence.
 * @return string
 */
function mohfam_build_email_template( $heading, $intro, $fields, $footer ) {
	$rows = '';

	foreach ( $fields as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			continue;
		}

		$rows .= sprintf(
			'<tr><th style="width:160px;padding:12px 16px;border-bottom:1px solid #e4e1dc;color:#715516;font-size:12px;letter-spacing:.06em;text-align:left;text-transform:uppercase;vertical-align:top;">%1$s</th><td style="padding:12px 16px;border-bottom:1px solid #e4e1dc;color:#333;line-height:1.6;vertical-align:top;">%2$s</td></tr>',
			esc_html( $label ),
			nl2br( esc_html( (string) $value ) )
		);
	}

	return sprintf(
		'<!doctype html><html><body style="margin:0;background:#f4f3f1;padding:24px;font-family:Arial,sans-serif;color:#1a1a1b;"><table role="presentation" style="width:100%%;max-width:680px;margin:0 auto;border-collapse:collapse;background:#fff;"><tr><td style="border-top:4px solid #967018;padding:32px;"><div style="margin-bottom:8px;color:#967018;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;">MohFam</div><h1 style="margin:0 0 16px;font-family:Georgia,serif;font-size:28px;line-height:1.2;">%1$s</h1><p style="margin:0 0 24px;color:#555;line-height:1.7;">%2$s</p><table role="presentation" style="width:100%%;border-collapse:collapse;background:#faf9f7;">%3$s</table><p style="margin:24px 0 0;color:#777;font-size:12px;line-height:1.6;">%4$s</p></td></tr></table></body></html>',
		esc_html( $heading ),
		esc_html( $intro ),
		$rows,
		esc_html( $footer )
	);
}

/**
 * Save a private copy of a form submission in WordPress.
 *
 * @param string $title   Submission title.
 * @param string $message Submission message.
 * @param array  $meta    Sanitized submission fields.
 * @return int|\WP_Error
 */
function mohfam_store_submission( $title, $message, $meta ) {
	$post_id = wp_insert_post(
		array(
			'post_type'    => 'mohfam_submission',
			'post_status'  => 'private',
			'post_title'   => $title,
			'post_content' => $message,
		),
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, '_mohfam_' . sanitize_key( $key ), $value );
	}

	return $post_id;
}

/**
 * Validate a Cloudflare Turnstile token.
 *
 * @param string $token      Turnstile response token.
 * @param string $ip_address Visitor IP address.
 * @return true|\WP_Error
 */
function mohfam_validate_turnstile( $token, $ip_address ) {
	if (
		! defined( 'MOHFAM_TURNSTILE_SITE_KEY' )
		|| ! defined( 'MOHFAM_TURNSTILE_SECRET_KEY' )
		|| '' === MOHFAM_TURNSTILE_SITE_KEY
		|| '' === MOHFAM_TURNSTILE_SECRET_KEY
	) {
		return new WP_Error( 'turnstile_not_configured', 'Turnstile is not configured.' );
	}

	if ( '' === $token || 2048 < strlen( $token ) ) {
		return new WP_Error( 'turnstile_token_missing', 'The Turnstile token is missing or invalid.' );
	}

	$response = wp_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => MOHFAM_TURNSTILE_SECRET_KEY,
				'response' => $token,
				'remoteip' => $ip_address,
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'turnstile_unavailable', 'Turnstile verification is temporarily unavailable.' );
	}

	$result = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $result ) || empty( $result['success'] ) ) {
		return new WP_Error( 'turnstile_failed', 'Turnstile verification failed.' );
	}

	$expected_hostname = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	$verified_hostname = isset( $result['hostname'] )
		? strtolower( sanitize_text_field( $result['hostname'] ) )
		: '';
	$verified_action = isset( $result['action'] )
		? sanitize_key( $result['action'] )
		: '';

	if ( '' === $expected_hostname || $expected_hostname !== $verified_hostname ) {
		return new WP_Error( 'turnstile_hostname_mismatch', 'Turnstile hostname verification failed.' );
	}

	if ( 'mohfam_form' !== $verified_action ) {
		return new WP_Error( 'turnstile_action_mismatch', 'Turnstile action verification failed.' );
	}

	return true;
}

require_once __DIR__ . '/inc/inquiry-mail.php';
require_once __DIR__ . '/inc/inquiry-handler.php';
