<?php
/**
 * PHPUnit bootstrap file
 *
 * @package performance-lab
 */

define( 'TESTS_PLUGIN_DIR', dirname( __DIR__ ) );

// Determine correct location for plugins directory to use.
if ( false !== getenv( 'WP_PLUGIN_DIR' ) ) {
	define( 'WP_PLUGIN_DIR', getenv( 'WP_PLUGIN_DIR' ) );
} else {
	define( 'WP_PLUGIN_DIR', dirname( TESTS_PLUGIN_DIR ) );
}

// Load Composer dependencies if applicable.
if ( file_exists( TESTS_PLUGIN_DIR . '/vendor/autoload.php' ) ) {
	require_once TESTS_PLUGIN_DIR . '/vendor/autoload.php';
}

// Detect where to load the WordPress tests environment from.
if ( false !== getenv( 'WP_TESTS_DIR' ) ) {
	$_test_root = getenv( 'WP_TESTS_DIR' );
} elseif ( false !== getenv( 'WP_DEVELOP_DIR' ) ) {
	$_test_root = getenv( 'WP_DEVELOP_DIR' ) . '/tests/phpunit';
} elseif ( false !== getenv( 'WP_PHPUNIT__DIR' ) ) {
	$_test_root = getenv( 'WP_PHPUNIT__DIR' );
} elseif ( file_exists( TESTS_PLUGIN_DIR . '/../../../../tests/phpunit/includes/functions.php' ) ) {
	$_test_root = TESTS_PLUGIN_DIR . '/../../../../tests/phpunit';
} else { // Fallback.
	$_test_root = '/tmp/wordpress-tests-lib';
}

require_once $_test_root . '/includes/functions.php';

// Check if we use the plugin's test suite. If so, disable the PL plugin and only load the requested plugin.
$plugin_name = '';
foreach ( $_SERVER['argv'] as $index => $arg ) {
	if (
		'--testsuite' === $arg &&
		isset( $_SERVER['argv'][ $index + 1 ] ) &&
		file_exists( TESTS_PLUGIN_DIR . '/plugins/' . $_SERVER['argv'][ $index + 1 ] )
	) {
		$plugin_name = $_SERVER['argv'][ $index + 1 ];
	}
}

// Set default plugin to load if not specified.
if ( ! $plugin_name ) {
	$plugin_name = 'performance-lab';
}

/**
 * Load plugin bootstrap and any dependencies.
 *
 * @param string $plugin_name Plugin slug to load.
 */
$load_plugin = static function ( string $plugin_name ) use ( &$load_plugin ): void {
	$plugin_test_path = TESTS_PLUGIN_DIR . '/plugins/' . $plugin_name;
	if ( file_exists( $plugin_test_path . '/' . $plugin_name . '.php' ) ) {
		$plugin_file = $plugin_test_path . '/' . $plugin_name . '.php';
	} elseif ( file_exists( $plugin_test_path . '/load.php' ) ) {
		$plugin_file = $plugin_test_path . '/load.php';
	} else {
		echo "Unable to locate standalone plugin bootstrap file in $plugin_test_path.";
		exit( 1 );
	}

	if ( preg_match( '/^ \* Requires Plugins:\s*(.+)$/m', (string) file_get_contents( $plugin_file ), $matches ) ) {
		foreach ( (array) preg_split( '/\s*,\s*/', $matches[1] ) as $requires_plugin ) {
			$load_plugin( (string) $requires_plugin );
		}
	}
	require_once $plugin_file;
};

tests_add_filter(
	'plugins_loaded',
	static function () use ( $load_plugin, $plugin_name ): void {
		$load_plugin( $plugin_name );
	},
	1
);

if ( 'performance-lab' === $plugin_name ) {
	// Add filter to ensure the plugin's admin integration is loaded for tests.
	tests_add_filter(
		'plugins_loaded',
		static function () use ( $plugin_name ): void {
			require_once TESTS_PLUGIN_DIR . '/plugins/' . $plugin_name . '/includes/admin/load.php';
			require_once TESTS_PLUGIN_DIR . '/plugins/' . $plugin_name . '/includes/admin/server-timing.php';
			require_once TESTS_PLUGIN_DIR . '/plugins/' . $plugin_name . '/includes/admin/plugins.php';
		},
		1
	);
}

// Start up the WP testing environment.
require $_test_root . '/includes/bootstrap.php';
