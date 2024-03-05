<?php
/**
 * REST API integration for the module.
 *
 * @package performance-lab
 * @since n.e.x.t
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Namespace for image-loading-optimization.
 *
 * @var string
 */
const ILO_REST_API_NAMESPACE = 'image-loading-optimization/v1';

/**
 * Route for storing a URL metric.
 *
 * Note the `:store` art of the endpoint follows Google's guidance in AIP-136 for the use of the POST method in a way
 * that does not strictly follow the standard usage. Namely, submitting a POST request to this endpoint will either
 * create a new `ilo_url_metrics` post, or it will update an existing post if one already exists for the provided slug.
 *
 * @link https://google.aip.dev/136
 * @var string
 */
const ILO_URL_METRICS_ROUTE = '/url-metrics:store';

/**
 * Registers endpoint for storage of URL metric.
 *
 * @since n.e.x.t
 * @access private
 */
function ilo_register_endpoint() {

	$args = array(
		'url'   => array(
			'type'              => 'string',
			'description'       => __( 'The URL for which the metric was obtained.', 'performance-lab' ),
			'required'          => true,
			'format'            => 'uri',
			'validate_callback' => static function ( $url ) {
				if ( ! wp_validate_redirect( $url ) ) {
					return new WP_Error( 'non_origin_url', __( 'URL for another site provided.', 'performance-lab' ) );
				}
				// TODO: This is not validated as corresponding to the slug in any way. True it is not used for anything but metadata.
				return true;
			},
		),
		'slug'  => array(
			'type'        => 'string',
			'description' => __( 'An MD5 hash of the query args.', 'performance-lab' ),
			'required'    => true,
			'pattern'     => '^[0-9a-f]{32}$',
			// This is validated via the nonce validate_callback, as it is provided as input to create the nonce by the server
			// which then is verified to match in the REST API request.
		),
		'nonce' => array(
			'type'              => 'string',
			'description'       => __( 'Nonce originally computed by server required to authorize the request.', 'performance-lab' ),
			'required'          => true,
			'pattern'           => '^[0-9a-f]+$',
			'validate_callback' => static function ( $nonce, WP_REST_Request $request ) {
				if ( ! ilo_verify_url_metrics_storage_nonce( $nonce, $request->get_param( 'slug' ) ) ) {
					return new WP_Error( 'invalid_nonce', __( 'URL metrics nonce verification failure.', 'performance-lab' ) );
				}
				return true;
			},
		),
	);

	register_rest_route(
		ILO_REST_API_NAMESPACE,
		ILO_URL_METRICS_ROUTE,
		array(
			'methods'             => 'POST',
			'args'                => array_merge(
				$args,
				rest_get_endpoint_args_for_schema( ILO_URL_Metric::get_json_schema() )
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				return ilo_handle_rest_request( $request );
			},
			'permission_callback' => static function () {
				// Needs to be available to unauthenticated visitors.
				if ( ilo_is_url_metric_storage_locked() ) {
					return new WP_Error(
						'url_metric_storage_locked',
						__( 'URL metric storage is presently locked for the current IP.', 'performance-lab' ),
						array( 'status' => 403 )
					);
				}
				return true;
			},
		)
	);
}
add_action( 'rest_api_init', 'ilo_register_endpoint' );

/**
 * Handles REST API request to store metrics.
 *
 * @since n.e.x.t
 * @access private
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error Response.
 */
function ilo_handle_rest_request( WP_REST_Request $request ) {
	$post = ilo_get_url_metrics_post( $request->get_param( 'slug' ) );

	$grouped_url_metrics = new ILO_Grouped_URL_Metrics(
		$post ? ilo_parse_stored_url_metrics( $post ) : array(),
		ilo_get_breakpoint_max_widths(),
		ilo_get_url_metrics_breakpoint_sample_size(),
		ilo_get_url_metric_freshness_ttl()
	);

	// Block the request if URL metrics aren't needed for the provided viewport width.
	// This logic is the same as the isViewportNeeded() function in detect.js.
	try {
		$group = $grouped_url_metrics->get_group_for_viewport_width(
			$request->get_param( 'viewport' )['width']
		);
	} catch ( InvalidArgumentException $exception ) {
		return new WP_Error(
			'invalid_viewport_width',
			$exception->getMessage()
		);
	}
	if ( ! $group->is_lacking() ) {
		return new WP_Error(
			'no_url_metric_needed',
			__( 'No URL metric needed for the provided viewport width.', 'performance-lab' ),
			array( 'status' => 403 )
		);
	}

	ilo_set_url_metric_storage_lock();

	try {
		$properties = ILO_URL_Metric::get_json_schema()['properties'];
		$url_metric = new ILO_URL_Metric(
			array_merge(
				wp_array_slice_assoc(
					$request->get_params(),
					array_keys( $properties )
				),
				array(
					// Now supply the timestamp since it was omitted from the REST API params since it is `readonly`.
					// Nevertheless, it is also `required`, so it must be set to instantiate an ILO_URL_Metric.
					'timestamp' => $properties['timestamp']['default'],
				)
			)
		);
	} catch ( ILO_Data_Validation_Exception $e ) {
		return new WP_Error(
			'url_metric_exception',
			sprintf(
				/* translators: %s is exception name */
				__( 'Failed to validate URL metric: %s', 'performance-lab' ),
				$e->getMessage()
			)
		);
	}

	$result = ilo_store_url_metric(
		$request->get_param( 'url' ),
		$request->get_param( 'slug' ),
		$url_metric
	);

	if ( $result instanceof WP_Error ) {
		return $result;
	}

	return new WP_REST_Response(
		array(
			'success' => true,
		)
	);
}
