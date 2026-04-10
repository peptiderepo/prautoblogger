<?php
declare(strict_types=1);

/**
 * AutoBlogger — Automated Content Research & Publishing
 *
 * @wordpress-plugin
 * Plugin Name:       AutoBlogger
 * Plugin URI:        https://peptiderepo.com/autoblogger
 * Description:       Monitors social media for trending topics, generates SEO-friendly blog posts using AI, and publishes them on a daily schedule with full cost tracking and self-improvement metrics.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PeptideRepo
 * Author URI:        https://peptiderepo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       autoblogger
 * Domain Path:       /languages
 *
 * @see ARCHITECTURE.md — Full data flow and file tree
 * @see CONVENTIONS.md  — Naming patterns and extension guides
 */

// Abort if called directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
|--------------------------------------------------------------------------
| Plugin Constants
|--------------------------------------------------------------------------
| Defined here so every file in the plugin can reference paths, versions,
| and limits without magic strings.
*/

define( 'AUTOBLOGGER_VERSION', '0.1.0' );
define( 'AUTOBLOGGER_DB_VERSION', '1.1.0' );
define( 'AUTOBLOGGER_PLUGIN_FILE', __FILE__ );
define( 'AUTOBLOGGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AUTOBLOGGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AUTOBLOGGER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// API and retry limits — named constants instead of magic numbers.
define( 'AUTOBLOGGER_MAX_RETRIES', 3 );
define( 'AUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 2 );
define( 'AUTOBLOGGER_API_TIMEOUT_SECONDS', 120 );
define( 'AUTOBLOGGER_CACHE_TTL_SECONDS', 3600 );

// Default models for OpenRouter (user can override in settings).
define( 'AUTOBLOGGER_DEFAULT_ANALYSIS_MODEL', 'anthropic/claude-3.5-haiku' );
define( 'AUTOBLOGGER_DEFAULT_WRITING_MODEL', 'anthropic/claude-sonnet-4' );
define( 'AUTOBLOGGER_DEFAULT_EDITOR_MODEL', 'anthropic/claude-sonnet-4' );

/*
|--------------------------------------------------------------------------
| Autoloader
|--------------------------------------------------------------------------
*/

require_once AUTOBLOGGER_PLUGIN_DIR . 'includes/class-autoloader.php';
Autoblogger_Autoloader::register();

/*
|--------------------------------------------------------------------------
| Activation & Deactivation
|--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, [ 'Autoblogger_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Autoblogger_Deactivator', 'deactivate' ] );

/*
|--------------------------------------------------------------------------
| Boot the Plugin
|--------------------------------------------------------------------------
| The main orchestrator registers all hooks. Nothing else happens here —
| keep the bootstrap file minimal.
*/

/**
 * Returns the singleton instance of the main plugin class.
 *
 * @return Autoblogger
 */
function autoblogger(): Autoblogger {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new Autoblogger();
	}
	return $instance;
}

// Initialize on plugins_loaded so all dependencies are available.
add_action( 'plugins_loaded', static function (): void {
	autoblogger()->run();
} );
