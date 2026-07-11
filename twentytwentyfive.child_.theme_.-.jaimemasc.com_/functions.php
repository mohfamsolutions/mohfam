<?php
/**
 * Theme setup for the MohFam child theme.
 *
 * @package MohFam
 */

add_action(
	'wp_enqueue_scripts',
	static function () {
		$theme = wp_get_theme();

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
			$theme->get( 'Version' )
		);
	}
);

