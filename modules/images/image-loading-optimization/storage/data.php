<?php
/**
 * Metrics storage data.
 *
 * @package performance-lab
 * @since n.e.x.t
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Gets the freshness age (TTL) for a given URL metric.
 *
 * When a URL metric expires it is eligible to be replaced by a newer one if its viewport lies within the same breakpoint.
 *
 * @since n.e.x.t
 * @access private
 *
 * @return int Expiration TTL in seconds.
 */
function ilo_get_url_metric_freshness_ttl(): int {
	/**
	 * Filters the freshness age (TTL) for a given URL metric.
	 *
	 * @since n.e.x.t
	 *
	 * @param int $ttl Expiration TTL in seconds.
	 */
	return (int) apply_filters( 'ilo_url_metric_freshness_ttl', DAY_IN_SECONDS );
}

/**
 * Determines whether the current response can be optimized.
 *
 * Only search results are not eligible by default for optimization. This is because there is no predictability in
 * whether posts in the loop will have featured images assigned or not. If a theme template for search results doesn't
 * even show featured images, then this isn't an issue.
 *
 * @since n.e.x.t
 * @access private
 *
 * @return bool Whether response can be optimized.
 */
function ilo_can_optimize_response(): bool {
	$able = ! is_search();

	/**
	 * Filters whether the current response can be optimized.
	 *
	 * @since n.e.x.t
	 *
	 * @param bool $able Whether response can be optimized.
	 */
	return (bool) apply_filters( 'ilo_can_optimize_response', $able );
}

/**
 * Gets the normalized query vars for the current request.
 *
 * This is used as a cache key for stored URL metrics.
 *
 * TODO: For non-singular requests, consider adding the post IDs from The Loop to ensure publishing a new post will invalidate the cache.
 *
 * @since n.e.x.t
 * @access private
 *
 * @return array Normalized query vars.
 */
function ilo_get_normalized_query_vars(): array {
	global $wp;

	// Note that the order of this array is naturally normalized since it is
	// assembled by iterating over public_query_vars.
	$normalized_query_vars = $wp->query_vars;

	// Normalize unbounded query vars.
	if ( is_404() ) {
		$normalized_query_vars = array(
			'error' => 404,
		);
	}

	return $normalized_query_vars;
}

/**
 * Gets slug for URL metrics.
 *
 * @since n.e.x.t
 * @access private
 *
 * @see ilo_get_normalized_query_vars()
 *
 * @param array $query_vars Normalized query vars.
 * @return string Slug.
 */
function ilo_get_url_metrics_slug( array $query_vars ): string {
	return md5( wp_json_encode( $query_vars ) );
}

/**
 * Computes nonce for storing URL metrics for a specific slug.
 *
 * This is used in the REST API to authenticate the storage of new URL metrics from a given URL.
 *
 * @since n.e.x.t
 * @access private
 *
 * @see wp_create_nonce()
 * @see ilo_verify_url_metrics_storage_nonce()
 *
 * @param string $slug URL metrics slug.
 * @return string Nonce.
 */
function ilo_get_url_metrics_storage_nonce( string $slug ): string {
	return wp_create_nonce( "store_url_metrics:{$slug}" );
}

/**
 * Verifies nonce for storing URL metrics for a specific slug.
 *
 * @since n.e.x.t
 * @access private
 *
 * @see wp_verify_nonce()
 * @see ilo_get_url_metrics_storage_nonce()
 *
 * @param string $nonce URL metrics storage nonce.
 * @param string $slug  URL metrics slug.
 * @return int 1 if the nonce is valid and generated between 0-12 hours ago,
 *             2 if the nonce is valid and generated between 12-24 hours ago.
 *             0 if the nonce is invalid.
 */
function ilo_verify_url_metrics_storage_nonce( string $nonce, string $slug ): int {
	return (int) wp_verify_nonce( $nonce, "store_url_metrics:{$slug}" );
}

/**
 * Unshifts a new URL metric onto an array of URL metrics.
 *
 * @since n.e.x.t
 * @access private
 *
 * @param array $url_metrics          URL metrics.
 * @param array $validated_url_metric Validated URL metric. See JSON Schema defined in ilo_register_endpoint().
 * @return array Updated URL metrics.
 */
function ilo_unshift_url_metrics( array $url_metrics, array $validated_url_metric ): array {
	array_unshift( $url_metrics, $validated_url_metric );
	$breakpoints         = ilo_get_breakpoint_max_widths();
	$sample_size         = ilo_get_url_metrics_breakpoint_sample_size();
	$grouped_url_metrics = ilo_group_url_metrics_by_breakpoint( $url_metrics, $breakpoints );

	foreach ( $grouped_url_metrics as &$breakpoint_url_metrics ) {
		if ( count( $breakpoint_url_metrics ) > $sample_size ) {

			// Sort URL metrics in descending order by timestamp.
			usort(
				$breakpoint_url_metrics,
				static function ( $a, $b ) {
					if ( ! isset( $a['timestamp'] ) || ! isset( $b['timestamp'] ) ) {
						return 0;
					}
					return $b['timestamp'] <=> $a['timestamp'];
				}
			);

			$breakpoint_url_metrics = array_slice( $breakpoint_url_metrics, 0, $sample_size );
		}
	}

	return array_merge( ...$grouped_url_metrics );
}

/**
 * Gets the breakpoint max widths to group URL metrics for various viewports.
 *
 * Each max with represents the maximum width (inclusive) for a given breakpoint. So if there is one number, 480, then
 * this means there will be two viewport groupings, one for 0<=480, and another >480. If instead there were three
 * provided breakpoints (320, 480, 576) then this means there will be four viewport groupings:
 *
 *  1. 0-320 (small smartphone)
 *  2. 321-480 (normal smartphone)
 *  3. 481-576 (phablets)
 *  4. >576 (desktop)
 *
 * @since n.e.x.t
 * @access private
 *
 * @return int[] Breakpoint max widths, sorted in ascending order.
 */
function ilo_get_breakpoint_max_widths(): array {

	$breakpoint_max_widths = array_map(
		static function ( $breakpoint_max_width ) {
			return (int) $breakpoint_max_width;
		},
		/**
		 * Filters the breakpoint max widths to group URL metrics for various viewports.
		 *
		 * @param int[] $breakpoint_max_widths Max widths for viewport breakpoints.
		 */
		(array) apply_filters( 'ilo_breakpoint_max_widths', array( 480 ) )
	);

	sort( $breakpoint_max_widths );
	return $breakpoint_max_widths;
}

/**
 * Gets the sample size for a breakpoint's URL metrics on a given URL.
 *
 * A breakpoint divides URL metrics for viewports which are smaller and those which are larger. Given the default
 * sample size of 3 and there being just a single breakpoint (480) by default, for a given URL, there would be a maximum
 * total of 6 URL metrics stored for a given URL: 3 for mobile and 3 for desktop.
 *
 * @since n.e.x.t
 * @access private
 *
 * @return int Sample size.
 */
function ilo_get_url_metrics_breakpoint_sample_size(): int {
	/**
	 * Filters the sample size for a breakpoint's URL metrics on a given URL.
	 *
	 * @since n.e.x.t
	 *
	 * @param int $sample_size Sample size.
	 */
	return (int) apply_filters( 'ilo_url_metrics_breakpoint_sample_size', 3 );
}

/**
 * Groups URL metrics by breakpoint.
 *
 * @since n.e.x.t
 * @access private
 *
 * @param array $url_metrics URL metrics.
 * @param int[] $breakpoints  Viewport breakpoint max widths, sorted in ascending order.
 * @return array URL metrics grouped by breakpoint. The array keys are the minimum widths for a viewport to lie within
 *               the breakpoint. The returned array is always one larger than the provided array of breakpoints, since
 *               the breakpoints reflect the max inclusive boundaries whereas the return value is the groups of page
 *               metrics with viewports on either side of the breakpoint boundaries.
 */
function ilo_group_url_metrics_by_breakpoint( array $url_metrics, array $breakpoints ): array {

	// Convert breakpoint max widths into viewport minimum widths.
	$viewport_minimum_widths = array_map(
		static function ( $breakpoint ) {
			return $breakpoint + 1;
		},
		$breakpoints
	);

	$grouped = array_fill_keys( array_merge( array( 0 ), $viewport_minimum_widths ), array() );

	foreach ( $url_metrics as $url_metric ) {
		if ( ! isset( $url_metric['viewport']['width'] ) ) {
			continue;
		}
		$viewport_width = $url_metric['viewport']['width'];

		$current_minimum_viewport = 0;
		foreach ( $viewport_minimum_widths as $viewport_minimum_width ) {
			if ( $viewport_width > $viewport_minimum_width ) {
				$current_minimum_viewport = $viewport_minimum_width;
			} else {
				break;
			}
		}

		$grouped[ $current_minimum_viewport ][] = $url_metric;
	}
	return $grouped;
}

/**
 * Gets needed minimum viewport widths.
 *
 * @since n.e.x.t
 * @access private
 *
 * @param array $url_metrics           URL metrics.
 * @param float $current_time           Current time as returned by microtime(true).
 * @param int[] $breakpoint_max_widths  Breakpoint max widths.
 * @param int   $sample_size            Sample size for viewports in a breakpoint.
 * @param int   $freshness_ttl          Freshness TTL for a URL metric.
 * @return array<int, array{int, bool}> Array of tuples mapping minimum viewport width to whether URL metric(s) are needed.
 */
function ilo_get_needed_minimum_viewport_widths( array $url_metrics, float $current_time, array $breakpoint_max_widths, int $sample_size, int $freshness_ttl ): array {
	$metrics_by_breakpoint          = ilo_group_url_metrics_by_breakpoint( $url_metrics, $breakpoint_max_widths );
	$needed_minimum_viewport_widths = array();
	foreach ( $metrics_by_breakpoint as $minimum_viewport_width => $viewport_url_metrics ) {
		$needs_url_metrics = false;
		if ( count( $viewport_url_metrics ) < $sample_size ) {
			$needs_url_metrics = true;
		} else {
			foreach ( $viewport_url_metrics as $url_metric ) {
				if ( isset( $url_metric['timestamp'] ) && $url_metric['timestamp'] + $freshness_ttl < $current_time ) {
					$needs_url_metrics = true;
					break;
				}
			}
		}
		$needed_minimum_viewport_widths[] = array(
			$minimum_viewport_width,
			$needs_url_metrics,
		);
	}

	return $needed_minimum_viewport_widths;
}

/**
 * Gets needed minimum viewport widths by slug for the current time.
 *
 * This is a convenience wrapper on top of ilo_get_needed_minimum_viewport_widths() to reduce code duplication.
 *
 * @since n.e.x.t
 * @access private
 *
 * @see ilo_get_needed_minimum_viewport_widths()
 *
 * @param string $slug URL metrics slug.
 * @return array<int, array{int, bool}> Array of tuples mapping minimum viewport width to whether URL metric(s) are needed.
 */
function ilo_get_needed_minimum_viewport_widths_now_for_slug( string $slug ): array {
	$post = ilo_get_url_metrics_post( $slug );
	return ilo_get_needed_minimum_viewport_widths(
		$post instanceof WP_Post ? ilo_parse_stored_url_metrics( $post ) : array(),
		microtime( true ),
		ilo_get_breakpoint_max_widths(),
		ilo_get_url_metrics_breakpoint_sample_size(),
		ilo_get_url_metric_freshness_ttl()
	);
}

/**
 * Checks whether there is a URL metric needed for one of the breakpoints.
 *
 * @since n.e.x.t
 * @access private
 *
 * @param array<int, array{int, bool}> $needed_minimum_viewport_widths Array of tuples mapping minimum viewport width to whether URL metric(s) are needed.
 * @return bool Whether a URL metric is needed.
 */
function ilo_needs_url_metric_for_breakpoint( array $needed_minimum_viewport_widths ): bool {
	foreach ( $needed_minimum_viewport_widths as list( $minimum_viewport_width, $is_needed ) ) {
		if ( $is_needed ) {
			return true;
		}
	}
	return false;
}
