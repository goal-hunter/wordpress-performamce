<?php
/**
 * Image Loading Optimization: ILO_URL_Metrics_Group_Collection class
 *
 * @package performance-lab
 * @since n.e.x.t
 */

/**
 * Collection of URL groups according to the breakpoints.
 *
 * @since n.e.x.t
 * @access private
 */
final class ILO_URL_Metrics_Group_Collection {

	/**
	 * URL metrics groups.
	 *
	 * The number of groups corresponds to one greater than the number of
	 * breakpoints. This is because breakpoints are the dividing line between
	 * the groups of URL metrics with specific viewport widths. This extends
	 * even to when there are zero breakpoints: there will still be one group
	 * in this case, in which every single URL metric is added.
	 *
	 * @var ILO_URL_Metrics_Group[]
	 * @phpstan-var non-empty-array<ILO_URL_Metrics_Group>
	 */
	private $groups;

	/**
	 * Breakpoints in max widths.
	 *
	 * @var int[]
	 * @phpstan-var int<1, max>[]
	 */
	private $breakpoints;

	/**
	 * Sample size for URL metrics for a given breakpoint.
	 *
	 * @var int
	 * @phpstan-var int<1, max>
	 */
	private $sample_size;

	/**
	 * Freshness age (TTL) for a given URL metric.
	 *
	 * @var int
	 * @phpstan-var int<0, max>
	 */
	private $freshness_ttl;

	/**
	 * Constructor.
	 *
	 * @throws InvalidArgumentException When an invalid argument is supplied.
	 *
	 * @param ILO_URL_Metric[] $url_metrics   URL metrics.
	 * @param int[]            $breakpoints   Breakpoints in max widths.
	 * @param int              $sample_size   Sample size for the maximum number of viewports in a group between breakpoints.
	 * @param int              $freshness_ttl Freshness age (TTL) for a given URL metric.
	 */
	public function __construct( array $url_metrics, array $breakpoints, int $sample_size, int $freshness_ttl ) {
		// Set breakpoints.
		sort( $breakpoints );
		$breakpoints = array_values( array_unique( $breakpoints, SORT_NUMERIC ) );
		foreach ( $breakpoints as $breakpoint ) {
			if ( $breakpoint <= 0 ) {
				throw new InvalidArgumentException(
					esc_html(
						sprintf(
							/* translators: %d is the invalid breakpoint */
							__(
								'Each of the breakpoints must be greater than zero, but encountered: %d',
								'performance-lab'
							),
							$breakpoint
						)
					)
				);
			}
		}
		$this->breakpoints = $breakpoints;

		// Set sample size.
		if ( $sample_size <= 0 ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %d is the invalid sample size */
						__( 'Sample size must be greater than zero, but provided: %d', 'performance-lab' ),
						$sample_size
					)
				)
			);
		}
		$this->sample_size = $sample_size;

		// Set freshness TTL.
		if ( $freshness_ttl < 0 ) {
			throw new InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %d is the invalid sample size */
						__( 'Freshness TTL must be at least zero, but provided: %d', 'performance-lab' ),
						$freshness_ttl
					)
				)
			);
		}
		$this->freshness_ttl = $freshness_ttl;

		// Create groups and the URL metrics to them.
		$this->groups = $this->create_groups();
		foreach ( $url_metrics as $url_metric ) {
			$this->add_url_metric( $url_metric );
		}
	}

	/**
	 * Create groups.
	 *
	 * @return ILO_URL_Metrics_Group[] Groups.
	 */
	private function create_groups(): array {
		$groups    = array();
		$min_width = 0;
		foreach ( $this->breakpoints as $max_width ) {
			$groups[]  = new ILO_URL_Metrics_Group( array(), $min_width, $max_width, $this->sample_size, $this->freshness_ttl );
			$min_width = $max_width + 1;
		}
		$groups[] = new ILO_URL_Metrics_Group( array(), $min_width, PHP_INT_MAX, $this->sample_size, $this->freshness_ttl );
		return $groups;
	}

	/**
	 * Adds a new URL metric to a group.
	 *
	 * Once a group reaches the sample size, the oldest URL metric is pushed out.
	 *
	 * @param ILO_URL_Metric $new_url_metric New URL metric.
	 * @return bool Whether the URL metric was added to a group.
	 */
	public function add_url_metric( ILO_URL_Metric $new_url_metric ): bool {
		foreach ( $this->groups as $group ) {
			if ( $group->add_url_metric( $new_url_metric ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Gets grouped keyed by the minimum viewport width.
	 *
	 * @return ILO_URL_Metrics_Group[] Groups.
	 */
	public function get_groups(): array {
		return $this->groups;
	}

	/**
	 * Gets group for viewport width.
	 *
	 * @throws InvalidArgumentException When there is no group for the provided viewport width. This would only happen if a negative width is provided.
	 *
	 * @param int $viewport_width Viewport width.
	 * @return ILO_URL_Metrics_Group URL metrics group for the viewport width.
	 */
	public function get_group_for_viewport_width( int $viewport_width ): ILO_URL_Metrics_Group {
		foreach ( $this->groups as $group ) {
			if ( $group->is_viewport_width_in_range( $viewport_width ) ) {
				return $group;
			}
		}
		throw new InvalidArgumentException(
			esc_html(
				sprintf(
					/* translators: %d is viewport width */
					__( 'No URL metrics group found for viewport width: %d', 'performance-lab' ),
					$viewport_width
				)
			)
		);
	}

	/**
	 * Checks whether every group is populated with at least one URL metric each.
	 *
	 * They aren't necessarily filled to the sample size, however.
	 * The URL metrics may also be stale (non-fresh). This method
	 * should be contrasted with the `is_every_group_complete()`
	 * method below.
	 *
	 * @see ILO_URL_Metrics_Group_Collection::is_every_group_complete()
	 *
	 * @return bool Whether all groups have some URL metrics.
	 */
	public function is_every_group_populated(): bool {
		foreach ( $this->groups as $group ) {
			if ( $group->count() === 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks whether every group is complete.
	 *
	 * @see ILO_URL_Metrics_Group::is_complete()
	 *
	 * @return bool Whether all groups are complete.
	 */
	public function is_every_group_complete(): bool {
		foreach ( $this->groups as $group ) {
			if ( ! $group->is_complete() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Gets URL metrics from all groups merged into one list.
	 *
	 * @return ILO_URL_Metric[] All URL metrics.
	 */
	public function get_merged_url_metrics(): array {
		return array_merge(
			...array_map(
				static function ( ILO_URL_Metrics_Group $group ): array {
					return $group->get_url_metrics();
				},
				$this->groups
			)
		);
	}
}
