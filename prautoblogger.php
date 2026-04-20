<?php
declare(strict_types=1);

/**
 * PRAutoBlogger — Automated Content Research & Publishing
 *
 * @wordpress-plugin
 * Plugin Name:       PRAutoBlogger
 * Plugin URI:        https://peptiderepo.com/prautoblogger
 * Description:       Monitors social media for trending topics, generates SEO-friendly blog posts using AI, and publishes them on a daily schedule with full cost tracking and self-improvement metrics.
 * Version:           0.7.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PeptideRepo
 * Author URI:        https://peptiderepo.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prautoblogger
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

define( 'PRAUTOBLOGGER_VERSION', '0.7.1' );
define( 'PRAUTOBLOGGER_DB_VERSION', '1.1.0' );
define( 'PRAUTOBLOGGER_PLUGIN_FILE', __FILE__ );
define( 'PRAUTOBLOGGER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRAUTOBLOGGER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRAUTOBLOGGER_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// API and retry limits — named constants instead of magic numbers.
define( 'PRAUTOBLOGGER_MAX_RETRIES', 3 );
define( 'PRAUTOBLOGGER_RETRY_BASE_DELAY_SECONDS', 2 );
define( 'PRAUTOBLOGGER_API_TIMEOUT_SECONDS', 180 );
define( 'PRAUTOBLOGGER_CACHE_TTL_SECONDS', 3600 );

// Default models for OpenRouter (user can override in settings).
define( 'PRAUTOBLOGGER_DEFAULT_ANALYSIS_MODEL', 'google/gemini-2.5-flash-lite' );
define( 'PRAUTOBLOGGER_DEFAULT_WRITING_MODEL', 'google/gemini-2.5-flash-lite' );
define( 'PRAUTOBLOGGER_DEFAULT_EDITOR_MODEL', 'google/gemini-2.5-flash-lite' );

// Image generation defaults.
// Default provider is OpenRouter; Cloudflare is the fallback.
// Default model is Gemini 2.5 Flash Image ($0.005/image, good for comics).
// User can override via Settings → Images → Image Model.
define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_PROVIDER', 'openrouter' );
define( 'PRAUTOBLOGGER_DEFAULT_IMAGE_MODEL', 'google/gemini-2.5-flash-image' );

// Default style suffix appended to every image-gen prompt. Updated
// 2026-04-18 v4: single-panel newspaper comic style. Caption is now
// inserted as HTML text below the image, NOT rendered inside it.
// Users may override via Settings → Images → Style Suffix.
define(
	'PRAUTOBLOGGER_DEFAULT_IMAGE_STYLE_SUFFIX',
	'Style: a single-panel newspaper comic in classic black ink on white paper, simple clean linework like The Far Side or B.C., slightly yellowed newsprint texture, hand-drawn crosshatching for shading, bold panel border, warm and humorous tone, visible in a newspaper comics section. IMPORTANT: Do not render any text, captions, speech bubbles, or words inside the image.'
);

/*
|--------------------------------------------------------------------------
| Autoloader
|--------------------------------------------------------------------------
*/

require_once PRAUTOBLOGGER_PLUGIN_DIR . 'includes/class-autoloader.php';
PRAutoBlogger_Autoloader::register();

// The main orchestrator class is loaded explicitly because its CamelCase name
// (PRAutoBlogger) doesn't map cleanly to a filename via the autoloader's
// kebab-case convention (it would look for class-pr-auto-blogger.php).
require_once PRAUTOBLOGGER_PLUGIN_DIR . 'includes/class-prautoblogger.php';

/*
|--------------------------------------------------------------------------
| Activation & Deactivation
|--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, [ 'PRAutoBlogger_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PRAutoBlogger_Deactivator', 'deactivate' ] );

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
 * @return PRAutoBlogger
 */
function prautoblogger(): PRAutoBlogger {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new PRAutoBlogger();
	}
	return $instance;
}

// Initialize on plugins_loaded so all dependencies are available.
add_action( 'plugins_loaded', static function (): void {
	prautoblogger()->run();
} );
