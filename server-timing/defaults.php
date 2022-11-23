<?php
/**
 * Server-Timing API default metrics
 *
 * @package performance-lab
 * @since n.e.x.t
 */

/**
 * Registers the default Server-Timing metrics for before rendering the template.
 *
 * These metrics should be registered as soon as possible.
 *
 * @since n.e.x.t
 */
function perflab_register_default_server_timing_before_template_metrics() {
	$calculate_before_template_metrics = function() {
		// WordPress execution prior to serving the template.
		perflab_server_timing_register_metric(
			'before-template',
			array(
				'measure_callback' => function( $metric ) {
					// The 'timestart' global is set right at the beginning of WordPress execution.
					$metric->set_value( ( microtime( true ) - $GLOBALS['timestart'] ) * 1000.0 );
				},
				'access_cap'       => 'exist',
			)
		);

		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
			// WordPress database query time before template.
			perflab_server_timing_register_metric(
				'before-template-db-queries',
				array(
					'measure_callback' => function( $metric ) {
						// Store this value in a global to later subtract it from total query time after template.
						$GLOBALS['perflab_query_time_before_template'] = array_reduce(
							$GLOBALS['wpdb']->queries,
							function( $acc, $query ) {
								return $acc + $query[1];
							},
							0.0
						);
						$metric->set_value( $GLOBALS['perflab_query_time_before_template'] * 1000.0 );
					},
					'access_cap'       => 'exist',
				)
			);
		}
	};

	// If output buffering is used, explicitly measure only the time before serving the template.
	// Otherwise, the Server-Timing header will be sent before serving the template anyway.
	// We need to check for output buffer usage in the callback so that e.g. plugins and theme can
	// modify the value prior to the check.
	add_filter(
		'template_include',
		function( $passthrough ) use ( $calculate_before_template_metrics ) {
			if ( perflab_server_timing_use_output_buffer() ) {
				$calculate_before_template_metrics();
			}
			return $passthrough;
		},
		PHP_INT_MAX
	);
	add_action(
		'perflab_server_timing_send_header',
		function() use ( $calculate_before_template_metrics ) {
			if ( ! perflab_server_timing_use_output_buffer() ) {
				$calculate_before_template_metrics();
			}
		},
		PHP_INT_MAX
	);
}
perflab_register_default_server_timing_before_template_metrics();

/**
 * Registers the default Server-Timing metrics for while rendering the template.
 *
 * These metrics should be registered at a later point, e.g. the 'wp_loaded' action.
 * They will only be registered if the Server-Timing API is configured to use an
 * output buffer for the site's template.
 *
 * @since n.e.x.t
 */
function perflab_register_default_server_timing_template_metrics() {
	// Template-related metrics can only be recorded if output buffering is used.
	if ( ! perflab_server_timing_use_output_buffer() ) {
		return;
	}

	add_filter(
		'template_include',
		function( $passthrough = null ) {
			// WordPress execution while serving the template.
			perflab_server_timing_register_metric(
				'template',
				array(
					'measure_callback' => function( $metric ) {
						$metric->measure_before();
						add_action( 'perflab_server_timing_send_header', array( $metric, 'measure_after' ), PHP_INT_MAX );
					},
					'access_cap'       => 'exist',
				)
			);

			return $passthrough;
		},
		PHP_INT_MAX
	);

	if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
		add_action(
			'perflab_server_timing_send_header',
			function() {
				// WordPress database query time within template.
				perflab_server_timing_register_metric(
					'template-db-queries',
					array(
						'measure_callback' => function( $metric ) {
							// This global should always be set when this is called, but check just in case.
							if ( ! isset( $GLOBALS['perflab_query_time_before_template'] ) ) {
								return;
							}
							$total_query_time = array_reduce(
								$GLOBALS['wpdb']->queries,
								function( $acc, $query ) {
									return $acc + $query[1];
								},
								0.0
							);
							$metric->set_value( ( $total_query_time - $GLOBALS['perflab_query_time_before_template'] ) * 1000.0 );
						},
						'access_cap'       => 'exist',
					)
				);
			},
			PHP_INT_MAX
		);
	}
}
add_action( 'wp_loaded', 'perflab_register_default_server_timing_template_metrics' );
