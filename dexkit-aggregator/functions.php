<?php

if ( ! function_exists( 'dexkit_aggregator_bootstrap' ) ) {

	/**
	 * Initialize the plugin.
	 */
	function dexkit_aggregator_bootstrap() {

		// Register the aggregator full page template
		dexkit_aggregator_add_template(
			'full-dexkit-aggregator.php',
			esc_html__( 'DexKit Aggregator Full', 'full-dexkit-aggregator' )
		);

		// Add our template(s) to the dropdown in the admin
		add_filter(
			'theme_page_templates',
			function ( array $templates ) {
				return array_merge( $templates, dexkit_aggregator_get_templates() );
			}
		);

		// Ensure our template is loaded on the front end
		add_filter(
			'template_include',
			function ( $template ) {

				if ( is_singular() ) {

					$assigned_template = get_post_meta( get_the_ID(), '_wp_page_template', true );

					if ( dexkit_aggregator_get_template( $assigned_template ) ) {

						if ( file_exists( $assigned_template ) ) {
							return $assigned_template;
						}

						// Allow themes to override plugin templates
						$file = locate_template( wp_normalize_path( '/dexkit-aggregator/' . $assigned_template ) );
						if ( ! empty( $file ) ) {
							return $file;
						}

						// Fetch template from plugin directory
						$file = wp_normalize_path( plugin_dir_path( __FILE__ ) . '/templates/' . $assigned_template );
						if ( file_exists( $file ) ) {
							return $file;
						}
					}
				}

				return $template;

			}
		);

	}
}

if ( ! function_exists( 'dexkit_aggregator_get_templates' ) ) {

	/**
	 * Get all registered templates.
	 *
	 * @return array
	 */
	function dexkit_aggregator_get_templates() {
		return (array) apply_filters( 'dexkit_aggregator_templates', array() );
	}
}

if ( ! function_exists( 'dexkit_aggregator_get_template' ) ) {

	/**
	 * Get a registered template.
	 *
	 * @param string $file Template file/path
	 *
	 * @return string|null
	 */
	function dexkit_aggregator_get_template( $file ) {
		$templates = dexkit_aggregator_get_templates();

		return isset( $templates[ $file ] ) ? $templates[ $file ] : null;
	}
}

if ( ! function_exists( 'dexkit_aggregator_add_template' ) ) {

	/**
	 * Register a new template.
	 *
	 * @param string $file  Template file/path
	 * @param string $label Label for the template
	 */
	function dexkit_aggregator_add_template( $file, $label ) {
		add_filter(
			'dexkit_aggregator_templates',
			function ( array $templates ) use ( $file, $label ) {
				$templates[ $file ] = $label;

				return $templates;
			}
		);
	}
}


if ( ! function_exists( 'wp_body_open' ) ) {

	/**
	 * Add wp_body_open() template tag if it doesn't exist (WP versions less than 5.2).
	 */
	function wp_body_open() {
		/**
		 * Triggered after the opening body tag.
		 *
		 * @since 5.2.0
		 */
		do_action( 'wp_body_open' );
	}
}
