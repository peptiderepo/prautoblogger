<?php
/**
 * Plugin Name: AutoBlogger Deploy Receiver
 * Description: REST endpoint that accepts authenticated plugin zip uploads for automated deployment.
 * Version:     1.0.0
 * Author:      AutoBlogger CI/CD
 *
 * INSTALLATION:
 *   1. Upload this file to wp-content/mu-plugins/autoblogger-deploy-receiver.php
 *   2. Add to wp-config.php (above "That's all, stop editing!"):
 *        define('AUTOBLOGGER_DEPLOY_KEY', 'your-random-64-char-token');
 *   3. Generate a key: php -r "echo bin2hex(random_bytes(32));"
 *
 * SECURITY:
 *   - Deploy key validated with hash_equals() (timing-safe comparison).
 *   - Only accepts zip files containing autoblogger/autoblogger.php.
 *   - Backs up current plugin before extraction; rolls back on failure.
 *   - Rate limited: max 1 deploy per 60 seconds.
 *   - All actions logged to PHP error_log for audit trail.
 *
 * @see .github/workflows/deploy.yml — GitHub Actions workflow that calls this.
 * @see scripts/deploy.sh             — Manual deploy script that calls this.
 */

declare(strict_types=1);

// Hard requirement: deploy key must be defined in wp-config.php.
if ( ! defined( 'AUTOBLOGGER_DEPLOY_KEY' ) || '' === AUTOBLOGGER_DEPLOY_KEY ) {
	return;
}

add_action( 'rest_api_init', 'autoblogger_deploy_register_route' );

/**
 * Register the deploy REST route.
 */
function autoblogger_deploy_register_route(): void {
	register_rest_route( 'autoblogger-deploy/v1', '/deploy', [
		'methods'             => 'POST',
		'callback'            => 'autoblogger_deploy_handle',
		'permission_callback' => 'autoblogger_deploy_check_auth',
	] );

	// Health check endpoint (GET) — useful for monitoring.
	register_rest_route( 'autoblogger-deploy/v1', '/status', [
		'methods'             => 'GET',
		'callback'            => function () {
			return [
				'status'  => 'ready',
				'plugin'  => is_dir( WP_PLUGIN_DIR . '/autoblogger' ) ? 'installed' : 'not_installed',
				'active'  => is_plugin_active( 'autoblogger/autoblogger.php' ),
				'version' => autoblogger_deploy_get_installed_version(),
			];
		},
		'permission_callback' => 'autoblogger_deploy_check_auth',
	] );
}

/**
 * Validate the deploy key from the request header.
 * Uses hash_equals() for timing-safe comparison.
 */
function autoblogger_deploy_check_auth( WP_REST_Request $request ): bool {
	$key = $request->get_header( 'X-Deploy-Key' );
	if ( null === $key || '' === $key ) {
		return false;
	}
	return hash_equals( (string) AUTOBLOGGER_DEPLOY_KEY, $key );
}

/**
 * Handle a deploy request: receive zip, validate, extract, activate.
 */
function autoblogger_deploy_handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	// Rate limit: 1 deploy per 60 seconds.
	$lock = get_transient( 'autoblogger_deploy_lock' );
	if ( false !== $lock ) {
		return new WP_Error(
			'rate_limited',
			'Deploy in progress or recently completed. Wait 60 seconds.',
			[ 'status' => 429 ]
		);
	}
	set_transient( 'autoblogger_deploy_lock', time(), 60 );

	// 1. Validate uploaded file exists.
	$files = $request->get_file_params();
	if ( empty( $files['plugin']['tmp_name'] ) || ! is_uploaded_file( $files['plugin']['tmp_name'] ) ) {
		delete_transient( 'autoblogger_deploy_lock' );
		return new WP_Error( 'no_file', 'No plugin zip uploaded.', [ 'status' => 400 ] );
	}

	$tmp_file = $files['plugin']['tmp_name'];

	// 2. Validate it's a real zip and contains our plugin.
	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp_file ) ) {
		delete_transient( 'autoblogger_deploy_lock' );
		return new WP_Error( 'invalid_zip', 'Uploaded file is not a valid zip.', [ 'status' => 400 ] );
	}

	$has_main_file = false;
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		if ( 'autoblogger/autoblogger.php' === $zip->getNameIndex( $i ) ) {
			$has_main_file = true;
			break;
		}
	}

	if ( ! $has_main_file ) {
		$zip->close();
		delete_transient( 'autoblogger_deploy_lock' );
		return new WP_Error(
			'invalid_plugin',
			'Zip must contain autoblogger/autoblogger.php at root.',
			[ 'status' => 400 ]
		);
	}

	// 3. Read version from the incoming zip before extraction.
	$incoming_main = $zip->getFromName( 'autoblogger/autoblogger.php' );
	$incoming_version = 'unknown';
	if ( $incoming_main && preg_match( '/Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $incoming_main, $m ) ) {
		$incoming_version = $m[1];
	}

	$plugin_dir = WP_PLUGIN_DIR . '/autoblogger';
	$backup_dir = null;

	// 4. Deactivate plugin before file operations (prevent fatal errors).
	$was_active = is_plugin_active( 'autoblogger/autoblogger.php' );
	if ( $was_active ) {
		deactivate_plugins( 'autoblogger/autoblogger.php', true );
	}

	// 5. Backup current installation if it exists.
	if ( is_dir( $plugin_dir ) ) {
		$backup_dir = WP_PLUGIN_DIR . '/autoblogger-backup-' . time();
		if ( ! rename( $plugin_dir, $backup_dir ) ) {
			$zip->close();
			// Re-activate if we deactivated.
			if ( $was_active ) {
				activate_plugin( 'autoblogger/autoblogger.php' );
			}
			delete_transient( 'autoblogger_deploy_lock' );
			return new WP_Error( 'backup_failed', 'Could not backup current plugin.', [ 'status' => 500 ] );
		}
	}

	// 6. Extract the zip.
	$extract_ok = $zip->extractTo( WP_PLUGIN_DIR );
	$zip->close();

	if ( ! $extract_ok || ! file_exists( $plugin_dir . '/autoblogger.php' ) ) {
		// Rollback: restore backup.
		if ( $backup_dir && is_dir( $backup_dir ) ) {
			rename( $backup_dir, $plugin_dir );
		}
		if ( $was_active ) {
			activate_plugin( 'autoblogger/autoblogger.php' );
		}
		delete_transient( 'autoblogger_deploy_lock' );
		error_log( '[AutoBlogger Deploy] Extraction failed. Rolled back to previous version.' );
		return new WP_Error( 'extract_failed', 'Zip extraction failed. Rolled back.', [ 'status' => 500 ] );
	}

	// 7. Activate plugin.
	$activation_result = activate_plugin( 'autoblogger/autoblogger.php' );
	if ( is_wp_error( $activation_result ) ) {
		// Activation failed — rollback.
		autoblogger_deploy_rmdir_recursive( $plugin_dir );
		if ( $backup_dir && is_dir( $backup_dir ) ) {
			rename( $backup_dir, $plugin_dir );
			activate_plugin( 'autoblogger/autoblogger.php' );
		}
		delete_transient( 'autoblogger_deploy_lock' );
		error_log( '[AutoBlogger Deploy] Activation failed: ' . $activation_result->get_error_message() . '. Rolled back.' );
		return new WP_Error(
			'activation_failed',
			'Plugin activation failed: ' . $activation_result->get_error_message() . '. Rolled back.',
			[ 'status' => 500 ]
		);
	}

	// 8. Clean up backup.
	if ( $backup_dir && is_dir( $backup_dir ) ) {
		autoblogger_deploy_rmdir_recursive( $backup_dir );
	}

	$old_version = autoblogger_deploy_get_installed_version();
	error_log( sprintf(
		'[AutoBlogger Deploy] Successfully deployed v%s (was: %s) at %s',
		$incoming_version,
		$old_version ?: 'fresh install',
		gmdate( 'Y-m-d H:i:s' )
	) );

	return new WP_REST_Response( [
		'success' => true,
		'version' => $incoming_version,
		'message' => "AutoBlogger v{$incoming_version} deployed and activated.",
	], 200 );
}

/**
 * Get the currently installed plugin version from the main file header.
 */
function autoblogger_deploy_get_installed_version(): string {
	$main_file = WP_PLUGIN_DIR . '/autoblogger/autoblogger.php';
	if ( ! file_exists( $main_file ) ) {
		return '';
	}
	$data = get_file_data( $main_file, [ 'Version' => 'Version' ] );
	return $data['Version'] ?? '';
}

/**
 * Recursively delete a directory.
 */
function autoblogger_deploy_rmdir_recursive( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $file ) {
		if ( $file->isDir() ) {
			rmdir( $file->getPathname() );
		} else {
			unlink( $file->getPathname() );
		}
	}
	rmdir( $dir );
}
