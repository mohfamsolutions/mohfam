<?php
/**
 * Theme setup for the MohFam child theme.
 *
 * @package MohFam
 */

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

		$form_script_path = get_stylesheet_directory() . '/assets/js/forms.js';
		$form_script_version = file_exists( $form_script_path )
			? (string) filemtime( $form_script_path )
			: wp_get_theme()->get( 'Version' );

		wp_enqueue_script(
			'mohfam-forms',
			get_stylesheet_directory_uri() . '/assets/js/forms.js',
			array(),
			$form_script_version,
			true
		);

		wp_localize_script(
			'mohfam-forms',
			'mohfamForms',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mohfam_form_submission' ),
			)
		);
	}
);

/**
 * Register a private admin area for inquiry and newsletter records.
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
		'Visitor email sent'   => 'user_mail_sent',
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
 * Handle all public MohFam forms.
 */
function mohfam_handle_form_submission() {
	if ( ! check_ajax_referer( 'mohfam_form_submission', 'nonce', false ) ) {
		wp_send_json_error(
			array( 'message' => 'Your form session expired. Please refresh the page and try again.' ),
			403
		);
	}

	$form_kind = isset( $_POST['form_kind'] )
		? sanitize_key( wp_unslash( $_POST['form_kind'] ) )
		: '';
	$honeypot = isset( $_POST['website'] )
		? sanitize_text_field( wp_unslash( $_POST['website'] ) )
		: '';

	if ( ! in_array( $form_kind, array( 'inquiry', 'newsletter' ), true ) ) {
		wp_send_json_error( array( 'message' => 'This form could not be identified.' ), 400 );
	}

	if ( '' !== $honeypot ) {
		wp_send_json_success(
			array( 'message' => 'Thank you. Your request has been received.' )
		);
	}

	$email = isset( $_POST['email'] )
		? sanitize_email( wp_unslash( $_POST['email'] ) )
		: '';

	if ( ! is_email( $email ) ) {
		wp_send_json_error( array( 'message' => 'Please enter a valid email address.' ), 422 );
	}

	$ip_address = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
		: 'unknown';
	$rate_key = 'mohfam_form_' . md5( wp_hash( $ip_address . '|' . $form_kind ) );

	if ( get_transient( $rate_key ) ) {
		wp_send_json_error(
			array( 'message' => 'Please wait a moment before submitting again.' ),
			429
		);
	}

	set_transient( $rate_key, 1, 20 );

	$admin_email = 'info@mohfamsecurity.com';
	$page_url = isset( $_POST['page_url'] )
		? esc_url_raw( wp_unslash( $_POST['page_url'] ) )
		: home_url( '/' );
	$html_headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'Reply-To: MohFam <' . $admin_email . '>',
	);

	if ( 'newsletter' === $form_kind ) {
		$existing = new WP_Query(
			array(
				'post_type'      => 'mohfam_submission',
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => '_mohfam_form_kind',
						'value' => 'newsletter',
					),
					array(
						'key'   => '_mohfam_email',
						'value' => $email,
					),
				),
			)
		);

		if ( $existing->have_posts() ) {
			wp_send_json_success(
				array( 'message' => 'You are already subscribed to MohFam Insights.' )
			);
		}

		$post_id = mohfam_store_submission(
			'Newsletter — ' . $email,
			'Newsletter subscription request.',
			array(
				'form_kind' => 'newsletter',
				'email'     => $email,
				'page_url'  => $page_url,
			)
		);

		$admin_message = mohfam_build_email_template(
			'New newsletter subscriber',
			'A visitor subscribed to MohFam Insights.',
			array(
				'Email'  => $email,
				'Source' => $page_url,
			),
			'A private copy is also available under Form Submissions in WordPress.'
		);
		$user_message = mohfam_build_email_template(
			'Welcome to MohFam Insights',
			'Your subscription has been received. We will send occasional professional insights and company updates.',
			array( 'Email' => $email ),
			'If you did not submit this request, reply to this email and we will remove the address.'
		);

		$admin_sent = wp_mail(
			$admin_email,
			'[MohFam Website] New insights subscriber',
			$admin_message,
			array_merge(
				array( 'Content-Type: text/html; charset=UTF-8' ),
				array( 'Reply-To: ' . $email )
			)
		);
		$user_sent = wp_mail(
			$email,
			'Welcome to MohFam Insights',
			$user_message,
			$html_headers
		);
	} else {
		$name = isset( $_POST['name'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['name'] ) ), 0, 120 )
			: '';
		$company = isset( $_POST['company'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['company'] ) ), 0, 160 )
			: '';
		$phone = isset( $_POST['phone'] )
			? substr( sanitize_text_field( wp_unslash( $_POST['phone'] ) ), 0, 40 )
			: '';
		$service = isset( $_POST['service'] )
			? sanitize_text_field( wp_unslash( $_POST['service'] ) )
			: '';
		$message = isset( $_POST['message'] )
			? substr( sanitize_textarea_field( wp_unslash( $_POST['message'] ) ), 0, 4000 )
			: '';

		if ( '' === $name || '' === $service || '' === $message ) {
			wp_send_json_error(
				array( 'message' => 'Please complete your name, service interest, and message.' ),
				422
			);
		}

		$post_id = mohfam_store_submission(
			'Inquiry — ' . $name . ' — ' . current_time( 'M j, Y g:i a' ),
			$message,
			array(
				'form_kind' => 'inquiry',
				'name'      => $name,
				'company'   => $company,
				'email'     => $email,
				'phone'     => $phone,
				'service'   => $service,
				'page_url'  => $page_url,
			)
		);

		$admin_message = mohfam_build_email_template(
			'New website inquiry',
			'A prospective client submitted an inquiry through the MohFam website.',
			array(
				'Name'     => $name,
				'Company'  => $company,
				'Email'    => $email,
				'Phone'    => $phone,
				'Service'  => $service,
				'Message'  => $message,
				'Source'   => $page_url,
			),
			'Reply directly to this email to contact the sender. A private copy is also available under Form Submissions in WordPress.'
		);
		$user_message = mohfam_build_email_template(
			'We received your inquiry',
			'Thank you for contacting MohFam. Our team has received your request and will review it promptly.',
			array(
				'Name'    => $name,
				'Service' => $service,
				'Message' => $message,
			),
			'For immediate assistance, call +1 (818) 818-1181 or email info@mohfamsecurity.com.'
		);

		$admin_subject = sprintf(
			'[MohFam Website] New %1$s inquiry from %2$s',
			$service,
			$name
		);
		$admin_sent = wp_mail(
			$admin_email,
			$admin_subject,
			$admin_message,
			array(
				'Content-Type: text/html; charset=UTF-8',
				'Reply-To: ' . $name . ' <' . $email . '>',
			)
		);
		$user_sent = wp_mail(
			$email,
			'We received your MohFam inquiry',
			$user_message,
			$html_headers
		);
	}

	if ( ! is_wp_error( $post_id ) ) {
		update_post_meta( $post_id, '_mohfam_admin_mail_sent', (bool) $admin_sent );
		update_post_meta( $post_id, '_mohfam_user_mail_sent', (bool) $user_sent );
	}

	if ( ! $admin_sent || ! $user_sent ) {
		wp_send_json_error(
			array(
				'message' => 'Your submission was saved, but an email could not be sent. Please call +1 (818) 818-1181 or email info@mohfamsecurity.com.',
			),
			500
		);
	}

	wp_send_json_success(
		array(
			'message' => 'newsletter' === $form_kind
				? 'Thank you for subscribing. Please check your inbox for confirmation.'
				: 'Thank you. Your inquiry was sent successfully, and a confirmation is on its way to your email.',
		)
	);
}
add_action( 'wp_ajax_mohfam_submit_form', 'mohfam_handle_form_submission' );
add_action( 'wp_ajax_nopriv_mohfam_submit_form', 'mohfam_handle_form_submission' );
