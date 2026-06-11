<?php
/**
 * Plugin Name:       HealthFest Registration
 * Description:       Workshop registration for HealthFest — seat-limited sign-ups, granular GDPR consent logging, CSV/Excel export, and email confirmations. Managed entirely from the WordPress admin.
 * Version:           0.3.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Vertical Freedom Foundation
 * License:           GPL-2.0-or-later
 * Text Domain:       healthfest-registration
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'HF_VERSION', '0.3.0' );
define( 'HF_DB_VERSION', '1' );
define( 'HF_PLUGIN_FILE', __FILE__ );
define( 'HF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'HF_TEXT_DOMAIN', 'healthfest-registration' );

/**
 * Privacy-policy version stamped onto every consent row. Bump this whenever the
 * privacy/consent wording materially changes, so the audit log records exactly
 * which version each participant agreed to. Override via wp-config if preferred.
 */
if ( ! defined( 'HF_PRIVACY_POLICY_VERSION' ) ) {
	define( 'HF_PRIVACY_POLICY_VERSION', '2026-06-11' );
}

require_once HF_PLUGIN_DIR . 'includes/class-hf-activator.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-seats.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-util.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-strings.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-workshop-cpt.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-shortcode.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-registration-handler.php';
require_once HF_PLUGIN_DIR . 'includes/class-hf-admin.php';

register_activation_hook( __FILE__, array( 'HF_Activator', 'activate' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);

/**
 * Load the plugin text domain for translations.
 *
 * @return void
 */
function hf_load_textdomain() {
	load_plugin_textdomain( HF_TEXT_DOMAIN, false, dirname( plugin_basename( HF_PLUGIN_FILE ) ) . '/languages' );
}
add_action( 'init', 'hf_load_textdomain' );

/**
 * Bootstrap plugin components once all plugins are loaded.
 *
 * @return void
 */
function hf_bootstrap() {
	( new HF_Workshop_CPT() )->register();
	( new HF_Shortcode() )->register();
	( new HF_Registration_Handler() )->register();
	( new HF_Admin() )->register();

	// Register translatable strings once Polylang is available.
	add_action( 'init', array( 'HF_Strings', 'register' ) );
}
add_action( 'plugins_loaded', 'hf_bootstrap' );
