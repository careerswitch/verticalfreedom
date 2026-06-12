<?php
/**
 * Activation routines — create custom tables via dbDelta and record the schema
 * version so future upgrades can run migrations conditionally.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles plugin activation: schema creation and rewrite flushing.
 */
class HF_Activator {

	/**
	 * Run on plugin activation.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		update_option( 'hf_db_version', HF_DB_VERSION );

		// Register the CPT once so its rewrite rules can be flushed cleanly.
		require_once HF_PLUGIN_DIR . 'includes/class-hf-workshop-cpt.php';
		( new HF_Workshop_CPT() )->register_post_type();
		flush_rewrite_rules();
	}

	/**
	 * Run idempotent schema migrations when the installed DB version is behind the
	 * code's HF_DB_VERSION. Called on every load (cost: one option read) so schema
	 * changes ship on plugin *update*, not only on manual re-activation.
	 *
	 * dbDelta is idempotent, so re-running create_tables() for a forward migration
	 * is safe; add version-specific data migrations below the dbDelta call as the
	 * schema evolves. reason: activate() only runs on activation, so updates that
	 * bumped HF_DB_VERSION never migrated existing installs.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'hf_db_version' );

		// No stored version means the plugin was never activated — activate() owns
		// fresh installs; nothing to migrate here.
		if ( false === $installed ) {
			return;
		}

		if ( (string) $installed === (string) HF_DB_VERSION ) {
			return;
		}

		self::create_tables();

		// Future per-version data migrations go here, e.g.:
		// if ( version_compare( $installed, '2', '<' ) ) { /* backfill ... */ }

		update_option( 'hf_db_version', HF_DB_VERSION );
	}

	/**
	 * Create or update the plugin's custom tables.
	 *
	 * Uses dbDelta, which is idempotent — safe to run on every activation and
	 * on schema-version bumps. All tables are InnoDB (WP default) so the
	 * registration flow can use transactions / atomic counter updates.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		// One row per person; reused across multiple workshop registrations.
		$participants = "CREATE TABLE {$prefix}hf_participants (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			full_name VARCHAR(191) NOT NULL,
			email VARCHAR(191) NOT NULL,
			phone VARCHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email)
		) {$charset_collate};";

		// One row per (participant, workshop). UNIQUE blocks double-booking;
		// the (workshop_id,status) index keeps confirmed-seat counts fast.
		$registrations = "CREATE TABLE {$prefix}hf_registrations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			participant_id BIGINT UNSIGNED NOT NULL,
			workshop_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'confirmed',
			created_at DATETIME NOT NULL,
			cancelled_at DATETIME NULL DEFAULT NULL,
			cancelled_by BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY participant_workshop (participant_id,workshop_id),
			KEY workshop_status (workshop_id,status)
		) {$charset_collate};";

		// Audit-grade consent log: one row per consent type per submission.
		$consents = "CREATE TABLE {$prefix}hf_consents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT UNSIGNED NOT NULL,
			consent_type VARCHAR(30) NOT NULL,
			consent_value TINYINT(1) NOT NULL DEFAULT 0,
			policy_version VARCHAR(20) NOT NULL DEFAULT '',
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id),
			KEY consent_type (consent_type)
		) {$charset_collate};";

		// Atomic seat counter. seats_taken is incremented via a conditional
		// UPDATE guarded by seat_limit, preventing overbooking under concurrency.
		$workshop_seats = "CREATE TABLE {$prefix}hf_workshop_seats (
			workshop_id BIGINT UNSIGNED NOT NULL,
			seat_limit INT UNSIGNED NOT NULL DEFAULT 0,
			seats_taken INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (workshop_id)
		) {$charset_collate};";

		dbDelta( $participants );
		dbDelta( $registrations );
		dbDelta( $consents );
		dbDelta( $workshop_seats );
	}
}
