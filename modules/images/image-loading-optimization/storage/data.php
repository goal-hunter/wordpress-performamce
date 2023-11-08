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
 * Gets the expiration age for a given page metric.
 *
 * When a page metric expires it is eligible to be replaced by a newer one.
 *
 * TODO: However, we keep viewport-specific page metrics regardless of TTL.
 *
 * @return int Expiration age in seconds.
 */
function ilo_get_page_metric_ttl() {
	/**
	 * Filters the expiration age for a given page metric.
	 *
	 * @param int $ttl TTL.
	 */
	return (int) apply_filters( 'ilo_page_metric_ttl', MONTH_IN_SECONDS );
}

/**
 * Unshift a new page metric onto an array of page metrics.
 *
 * @param array $page_metrics          Page metrics.
 * @param array $validated_page_metric Validated page metric.
 * @return array Updated page metrics.
 */
function ilo_unshift_page_metrics( $page_metrics, $validated_page_metric ) {
	array_unshift( $page_metrics, $validated_page_metric );
	$breakpoints          = ilo_get_breakpoint_max_widths();
	$sample_size          = ilo_get_page_metrics_breakpoint_sample_size();
	$grouped_page_metrics = ilo_group_page_metrics_by_breakpoint( $page_metrics, $breakpoints );

	foreach ( $grouped_page_metrics as &$breakpoint_page_metrics ) {
		if ( count( $breakpoint_page_metrics ) > $sample_size ) {
			$breakpoint_page_metrics = array_slice( $breakpoint_page_metrics, 0, $sample_size );
		}
	}

	return array_merge( ...$grouped_page_metrics );
}

/**
 * Gets the breakpoint max widths to group page metrics for various viewports.
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
 * @return int[] Breakpoint max widths, sorted in ascending order.
 */
function ilo_get_breakpoint_max_widths() {

	/**
	 * Filters the breakpoint max widths to group page metrics for various viewports.
	 *
	 * @param int[] $breakpoint_max_widths Max widths for viewport breakpoints.
	 */
	$breakpoint_max_widths = array_map(
		static function ( $breakpoint_max_width ) {
			return (int) $breakpoint_max_width;
		},
		(array) apply_filters( 'ilo_breakpoint_max_widths', array( 480 ) )
	);

	sort( $breakpoint_max_widths );
	return $breakpoint_max_widths;
}

/**
 * Gets desired sample size for a breakpoint's page metrics.
 *
 * @return int Sample size.
 */
function ilo_get_page_metrics_breakpoint_sample_size() {
	/**
	 * Filters desired sample size for a viewport's page metrics.
	 *
	 * @param int $sample_size Sample size.
	 */
	return (int) apply_filters( 'ilo_page_metrics_breakpoint_sample_size', 10 );
}

/**
 * Groups page metrics by breakpoint.
 *
 * @param array $page_metrics Page metrics.
 * @param int[] $breakpoints  Viewport breakpoint max widths, sorted in ascending order.
 * @return array Grouped page metrics.
 */
function ilo_group_page_metrics_by_breakpoint( array $page_metrics, array $breakpoints ) {
	$max_index          = count( $breakpoints );
	$groups             = array_fill( 0, $max_index + 1, array() );
	$largest_breakpoint = $breakpoints[ $max_index - 1 ];
	foreach ( $page_metrics as $page_metric ) {
		if ( ! isset( $page_metric['viewport']['width'] ) ) {
			continue;
		}
		$viewport_width = $page_metric['viewport']['width'];
		if ( $viewport_width > $largest_breakpoint ) {
			$groups[ $max_index ][] = $page_metric;
		}
		foreach ( $breakpoints as $group => $breakpoint ) {
			if ( $viewport_width <= $breakpoint ) {
				$groups[ $group ][] = $page_metric;
			}
		}
	}
	return $groups;
}
