<?php
/**
 * AJAX submission engine: validates a registration, upserts the participant,
 * atomically reserves a seat per chosen workshop, writes the registration and
 * its consent audit rows, and emails a confirmation.
 *
 * All workshop IDs are canonicalised (HF_Seats) so EN/RO translations share one
 * seat pool and the UNIQUE(participant, workshop) guard blocks cross-language
 * double-booking.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the front-end registration and availability AJAX endpoints.
 */
class HF_Registration_Handler {

	/**
	 * Register AJAX hooks (logged-in and guest).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_ajax_hf_register', array( $this, 'handle_register' ) );
		add_action( 'wp_ajax_nopriv_hf_register', array( $this, 'handle_register' ) );
		add_action( 'wp_ajax_hf_availability', array( $this, 'handle_availability' ) );
		add_action( 'wp_ajax_nopriv_hf_availability', array( $this, 'handle_availability' ) );
	}

	/**
	 * Transient-backed per-IP throttle for the registration endpoint.
	 *
	 * Permits HF_RATE_LIMIT_MAX submissions per HF_RATE_LIMIT_WINDOW seconds from a
	 * single client IP. Fails OPEN when the IP can't be determined, so a proxy quirk
	 * never blocks a legitimate participant. The window resets on each counted hit,
	 * which keeps a sustained attacker blocked while letting a quiet IP recover.
	 *
	 * @return bool True if the request is under the limit.
	 */
	private function within_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true; // Can't identify the caller — don't block.
		}

		$key   = 'hf_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= (int) HF_RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, (int) HF_RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Process a registration submission. Responds with JSON.
	 *
	 * @return void
	 */
	public function handle_register() {
		if ( ! check_ajax_referer( 'hf_register', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'error_generic' ) ), 400 );
		}

		// Throttle scripted floods before doing any DB work. The nonce is weak here
		// (nopriv nonces are shared across guests for ~24h), so cap per-IP volume.
		if ( ! $this->within_rate_limit() ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'rate_limited' ) ), 429 );
		}

		$first = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		$last  = isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		$workshops = array();
		if ( isset( $_POST['workshops'] ) && is_array( $_POST['workshops'] ) ) {
			$workshops = array_filter( array_map( 'absint', wp_unslash( $_POST['workshops'] ) ) );
		}

		$consent_privacy   = ! empty( $_POST['consent_privacy'] ) ? 1 : 0;
		$consent_photo     = ! empty( $_POST['consent_photo'] ) ? 1 : 0;
		$consent_marketing = ! empty( $_POST['consent_marketing'] ) ? 1 : 0;

		// --- Validation ---
		if ( '' === $first || '' === $last || '' === $email || '' === $phone ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'required_field' ) ), 422 );
		}
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'invalid_email' ) ), 422 );
		}
		if ( empty( $workshops ) ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'select_one' ) ), 422 );
		}
		if ( ! $consent_privacy ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'must_accept_privacy' ) ), 422 );
		}

		$participant_id = $this->upsert_participant( $first, $last, $email, $phone );
		if ( ! $participant_id ) {
			wp_send_json_error( array( 'message' => HF_Strings::t( 'error_generic' ) ), 500 );
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$registered = array();
		$failed     = array();

		foreach ( $workshops as $workshop_id ) {
			$canonical = HF_Seats::canonical_id( $workshop_id );

			if ( $this->already_registered( $participant_id, $canonical ) ) {
				$failed[] = array(
					'title'  => get_the_title( $workshop_id ),
					'reason' => HF_Strings::t( 'already_registered' ),
				);
				continue;
			}

			if ( ! HF_Seats::reserve( $canonical ) ) {
				$failed[] = array(
					'title'  => get_the_title( $workshop_id ),
					'reason' => HF_Strings::t( 'workshop_full' ),
				);
				continue;
			}

			$registration_id = $this->insert_registration( $participant_id, $canonical );
			if ( ! $registration_id ) {
				// Insert failed after reserving — give the seat back.
				HF_Seats::release( $canonical );
				$failed[] = array(
					'title'  => get_the_title( $workshop_id ),
					'reason' => HF_Strings::t( 'error_generic' ),
				);
				continue;
			}

			$this->record_consents( $registration_id, $consent_privacy, $consent_photo, $consent_marketing, $ip, $ua );
			$registered[] = array(
				'id'    => $workshop_id,
				'title' => get_the_title( $workshop_id ),
			);
		}

		if ( empty( $registered ) ) {
			$reason = ! empty( $failed ) ? $failed[0]['reason'] : HF_Strings::t( 'error_generic' );
			wp_send_json_error(
				array(
					'message' => $reason,
					'failed'  => $failed,
				),
				409
			);
		}

		$this->send_confirmation( $first, $email, $registered );

		wp_send_json_success(
			array(
				'message'    => HF_Strings::t( 'success' ),
				'registered' => $registered,
				'failed'     => $failed,
			)
		);
	}

	/**
	 * Return live availability for all current-language workshops, keyed by post ID.
	 *
	 * @return void
	 */
	public function handle_availability() {
		$query = new WP_Query(
			array(
				'post_type'      => HF_Workshop_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $id ) {
			$a          = HF_Seats::availability( $id );
			$out[ $id ] = array(
				'remaining' => $a['remaining'],
				'is_full'   => $a['is_full'],
			);
		}
		wp_send_json_success( $out );
	}

	/**
	 * Create or update a participant by email. Returns the participant ID.
	 *
	 * @param string $first First name.
	 * @param string $last  Last name.
	 * @param string $email Email (unique key).
	 * @param string $phone Phone.
	 * @return int Participant ID, or 0 on failure.
	 */
	private function upsert_participant( $first, $last, $email, $phone ) {
		global $wpdb;
		$table = $wpdb->prefix . 'hf_participants';
		$now   = current_time( 'mysql' );
		$name  = trim( $first . ' ' . $last );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array(
					'full_name'  => $name,
					'phone'      => $phone,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'full_name'  => $name,
				'email'      => $email,
				'phone'      => $phone,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Whether the participant already has a confirmed registration for the workshop.
	 *
	 * @param int $participant_id Participant ID.
	 * @param int $workshop_id    Canonical workshop ID.
	 * @return bool
	 */
	private function already_registered( $participant_id, $workshop_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'hf_registrations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE participant_id = %d AND workshop_id = %d AND status = 'confirmed'",
				$participant_id,
				$workshop_id
			)
		);
		return (bool) $found;
	}

	/**
	 * Insert a confirmed registration row. Returns the new ID or 0.
	 *
	 * @param int $participant_id Participant ID.
	 * @param int $workshop_id    Canonical workshop ID.
	 * @return int
	 */
	private function insert_registration( $participant_id, $workshop_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'hf_registrations';
		$now   = current_time( 'mysql' );

		// UNIQUE(participant_id, workshop_id) keeps cancelled rows on file, so a
		// plain INSERT for a participant who previously cancelled this workshop
		// hits the constraint, fails silently, and permanently blocks re-joining.
		// Revive the existing row instead. reason: cancel→re-register was broken.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE participant_id = %d AND workshop_id = %d",
				$participant_id,
				$workshop_id
			)
		);

		if ( $existing ) {
			// Already confirmed (e.g. a concurrent duplicate submission that slipped
			// past the application-level check): signal failure so the caller releases
			// the seat it just reserved. The DB UNIQUE key remains the final guard.
			if ( 'confirmed' === $existing->status ) {
				return 0;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- revive cancelled row.
			$revived = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'confirmed', created_at = %s, cancelled_at = NULL, cancelled_by = NULL WHERE id = %d",
					$now,
					(int) $existing->id
				)
			);

			return ( false !== $revived ) ? (int) $existing->id : 0;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'participant_id' => $participant_id,
				'workshop_id'    => $workshop_id,
				'status'         => 'confirmed',
				'created_at'     => $now,
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Write the three consent audit rows for a registration.
	 *
	 * @param int    $registration_id Registration ID.
	 * @param int    $privacy         Privacy consent (always 1 here).
	 * @param int    $photo           Photo/video consent (0/1).
	 * @param int    $marketing       Marketing consent (0/1).
	 * @param string $ip              Client IP.
	 * @param string $ua              User agent.
	 * @return void
	 */
	private function record_consents( $registration_id, $privacy, $photo, $marketing, $ip, $ua ) {
		global $wpdb;
		$table = $wpdb->prefix . 'hf_consents';
		$now   = current_time( 'mysql' );
		$rows  = array(
			'privacy_required' => $privacy,
			'photo_video'      => $photo,
			'marketing'        => $marketing,
		);
		foreach ( $rows as $type => $value ) {
			$wpdb->insert(
				$table,
				array(
					'registration_id' => $registration_id,
					'consent_type'    => $type,
					'consent_value'   => (int) $value,
					'policy_version'  => HF_PRIVACY_POLICY_VERSION,
					'ip_address'      => $ip,
					'user_agent'      => $ua,
					'created_at'      => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * Send the participant a plain-text confirmation (routes through the site's
	 * SMTP plugin). Includes the mandatory "report cancellations" note.
	 *
	 * @param string $first      First name.
	 * @param string $email      Recipient.
	 * @param array  $registered List of {id,title}.
	 * @return void
	 */
	private function send_confirmation( $first, $email, $registered ) {
		$lines   = array();
		$lines[] = sprintf( HF_Strings::t( 'email_greeting' ), $first );
		$lines[] = '';
		$lines[] = HF_Strings::t( 'email_intro' );
		$lines[] = '';
		foreach ( $registered as $w ) {
			$lines[] = '• ' . HF_Util::workshop_line( $w['id'], $w['title'] );
		}
		$lines[] = '';
		$lines[] = HF_Strings::t( 'cancellation_note' );
		$lines[] = '';
		$lines[] = HF_Strings::t( 'email_signature' );

		$subject = HF_Strings::t( 'email_subject' );
		$body    = implode( "\n", $lines );

		wp_mail( $email, $subject, $body );
	}
}
