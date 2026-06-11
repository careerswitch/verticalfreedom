<?php
/**
 * Admin: HealthFest → Registrations.
 *
 * Lists registrations (filterable by workshop), shows the consent audit, lets an
 * admin cancel a registration (which frees the seat), and exports everything to
 * a UTF-8 CSV that opens cleanly in Excel.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registrations admin screen + actions.
 */
class HF_Admin {

	const CAP       = 'manage_options';
	const PER_PAGE  = 20;
	const PAGE_SLUG = 'hf-registrations';

	/**
	 * Hook the admin menu and action handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_hf_cancel_registration', array( $this, 'handle_cancel' ) );
		add_action( 'admin_post_hf_export_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Add the Registrations submenu under the HealthFest menu.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . HF_Workshop_CPT::POST_TYPE,
			__( 'Registrations', 'healthfest-registration' ),
			__( 'Registrations', 'healthfest-registration' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Distinct workshop IDs that actually have registrations, with titles.
	 *
	 * @return array<int,string> workshop_id => title
	 */
	private function workshops_with_registrations() {
		global $wpdb;
		$table = $wpdb->prefix . 'hf_registrations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( "SELECT DISTINCT workshop_id FROM {$table}" );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = get_the_title( (int) $id );
		}
		asort( $out );
		return $out;
	}

	/**
	 * Render the registrations admin page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'healthfest-registration' ) );
		}

		global $wpdb;
		$reg   = $wpdb->prefix . 'hf_registrations';
		$part  = $wpdb->prefix . 'hf_participants';

		$workshop = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$offset   = ( $paged - 1 ) * self::PER_PAGE;

		$where  = '';
		$params = array();
		if ( $workshop ) {
			$where    = 'WHERE r.workshop_id = %d';
			$params[] = $workshop;
		}

		// Total for pagination.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$reg} r {$where}", $params )
				: "SELECT COUNT(*) FROM {$reg} r"
		);

		$query        = "SELECT r.id, r.workshop_id, r.status, r.created_at, p.full_name, p.email, p.phone
			FROM {$reg} r JOIN {$part} p ON p.id = r.participant_id {$where}
			ORDER BY r.created_at DESC LIMIT %d OFFSET %d";
		$query_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $query_params ) );

		$consents = $this->consents_for( wp_list_pluck( $rows, 'id' ) );
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$workshops   = $this->workshops_with_registrations();

		echo '<div class="wrap"><h1>' . esc_html__( 'HealthFest — Registrations', 'healthfest-registration' ) . '</h1>';

		if ( isset( $_GET['hf_cancelled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Registration cancelled and the seat was released.', 'healthfest-registration' ) . '</p></div>';
		}

		// --- Filter + export toolbar ---
		echo '<form method="get" style="margin:1em 0;display:flex;gap:.5em;align-items:center;flex-wrap:wrap;">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( HF_Workshop_CPT::POST_TYPE ) . '" />';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<select name="workshop"><option value="0">' . esc_html__( 'All workshops', 'healthfest-registration' ) . '</option>';
		foreach ( $workshops as $id => $title ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $workshop, $id, false ), esc_html( $title ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'Filter', 'healthfest-registration' ) . '</button>';

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=hf_export_csv&workshop=' . $workshop ),
			'hf_export_csv'
		);
		echo ' <a class="button button-primary" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'healthfest-registration' ) . '</a>';
		echo ' <span class="description">' . esc_html( sprintf( /* translators: %d: total registrations */ __( '%d registration(s)', 'healthfest-registration' ), $total ) ) . '</span>';
		echo '</form>';

		// --- Table ---
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		$cols = array( 'Name', 'Email', 'Phone', 'Workshop', 'Registered', 'Privacy', 'Photo', 'Marketing', 'Status', '' );
		foreach ( $cols as $c ) {
			echo '<th>' . esc_html( '' === $c ? '' : __( $c, 'healthfest-registration' ) ) . '</th>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="10">' . esc_html__( 'No registrations yet.', 'healthfest-registration' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$c          = isset( $consents[ $row->id ] ) ? $consents[ $row->id ] : array();
			$cancelled  = ( 'cancelled' === $row->status );
			$cancel_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=hf_cancel_registration&reg=' . (int) $row->id ),
				'hf_cancel_' . (int) $row->id
			);
			echo '<tr' . ( $cancelled ? ' style="opacity:.55;"' : '' ) . '>';
			echo '<td>' . esc_html( $row->full_name ) . '</td>';
			echo '<td>' . esc_html( $row->email ) . '</td>';
			echo '<td>' . esc_html( $row->phone ) . '</td>';
			echo '<td>' . esc_html( get_the_title( (int) $row->workshop_id ) ) . '</td>';
			echo '<td>' . esc_html( $row->created_at ) . '</td>';
			echo '<td>' . $this->tick( isset( $c['privacy_required'] ) ? $c['privacy_required'] : 0 ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . $this->tick( isset( $c['photo_video'] ) ? $c['photo_video'] : 0 ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . $this->tick( isset( $c['marketing'] ) ? $c['marketing'] : 0 ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( $cancelled ? __( 'Cancelled', 'healthfest-registration' ) : __( 'Confirmed', 'healthfest-registration' ) ) . '</td>';
			echo '<td>';
			if ( ! $cancelled ) {
				echo '<a class="button button-small" href="' . esc_url( $cancel_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Cancel this registration and free the seat?', 'healthfest-registration' ) ) . '\');">' . esc_html__( 'Cancel', 'healthfest-registration' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// --- Pagination ---
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			$base = add_query_arg(
				array(
					'post_type' => HF_Workshop_CPT::POST_TYPE,
					'page'      => self::PAGE_SLUG,
					'workshop'  => $workshop,
					'paged'     => '%#%',
				),
				admin_url( 'edit.php' )
			);
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => $base,
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'prev_text' => '‹',
						'next_text' => '›',
					)
				)
			);
			echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Coloured ✓/✗ for a consent value.
	 *
	 * @param int $value 0/1.
	 * @return string Safe HTML.
	 */
	private function tick( $value ) {
		return (int) $value
			? '<span style="color:#1c6b43;font-weight:600;">&#10003;</span>'
			: '<span style="color:#9a2727;">&#10007;</span>';
	}

	/**
	 * Map registration IDs to their consent values.
	 *
	 * @param int[] $registration_ids Registration IDs.
	 * @return array<int,array<string,int>>
	 */
	private function consents_for( $registration_ids ) {
		$ids = array_filter( array_map( 'absint', (array) $registration_ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'hf_consents';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT registration_id, consent_type, consent_value FROM {$table} WHERE registration_id IN ({$placeholders})", $ids ) );

		$map = array();
		foreach ( $results as $r ) {
			$map[ (int) $r->registration_id ][ $r->consent_type ] = (int) $r->consent_value;
		}
		return $map;
	}

	/**
	 * Cancel a registration and release its seat.
	 *
	 * @return void
	 */
	public function handle_cancel() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'healthfest-registration' ) );
		}
		$reg_id = isset( $_GET['reg'] ) ? absint( $_GET['reg'] ) : 0;
		check_admin_referer( 'hf_cancel_' . $reg_id );

		global $wpdb;
		$table = $wpdb->prefix . 'hf_registrations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, workshop_id, status FROM {$table} WHERE id = %d", $reg_id ) );

		if ( $row && 'confirmed' === $row->status ) {
			$wpdb->update(
				$table,
				array(
					'status'       => 'cancelled',
					'cancelled_at' => current_time( 'mysql' ),
					'cancelled_by' => get_current_user_id(),
				),
				array( 'id' => $reg_id ),
				array( '%s', '%s', '%d' ),
				array( '%d' )
			);
			HF_Seats::release( (int) $row->workshop_id );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'    => HF_Workshop_CPT::POST_TYPE,
					'page'         => self::PAGE_SLUG,
					'hf_cancelled' => 1,
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * Stream all (optionally filtered) registrations as a UTF-8 CSV.
	 *
	 * @return void
	 */
	public function handle_export() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'healthfest-registration' ) );
		}
		check_admin_referer( 'hf_export_csv' );

		global $wpdb;
		$reg      = $wpdb->prefix . 'hf_registrations';
		$part     = $wpdb->prefix . 'hf_participants';
		$workshop = isset( $_GET['workshop'] ) ? absint( $_GET['workshop'] ) : 0;

		if ( $workshop ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT r.id, r.workshop_id, r.status, r.created_at, r.cancelled_at, p.full_name, p.email, p.phone FROM {$reg} r JOIN {$part} p ON p.id = r.participant_id WHERE r.workshop_id = %d ORDER BY r.created_at DESC", $workshop ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( "SELECT r.id, r.workshop_id, r.status, r.created_at, r.cancelled_at, p.full_name, p.email, p.phone FROM {$reg} r JOIN {$part} p ON p.id = r.participant_id ORDER BY r.created_at DESC" );
		}

		$consents = $this->consents_for_full( wp_list_pluck( $rows, 'id' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=healthfest-registrations-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel renders Romanian diacritics correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv(
			$out,
			array( 'ID', 'Nume', 'Email', 'Telefon', 'Atelier', 'Status', 'Data inscrierii', 'Confidentialitate', 'Foto/Video', 'Marketing', 'Versiune politica', 'IP', 'Data acord' ),
			';'
		);

		foreach ( $rows as $row ) {
			$c = isset( $consents[ $row->id ] ) ? $consents[ $row->id ] : array();
			fputcsv(
				$out,
				array(
					$row->id,
					$row->full_name,
					$row->email,
					$row->phone,
					get_the_title( (int) $row->workshop_id ),
					$row->status,
					$row->created_at,
					$this->yesno( isset( $c['privacy_required'] ) ? $c['privacy_required']['value'] : 0 ),
					$this->yesno( isset( $c['photo_video'] ) ? $c['photo_video']['value'] : 0 ),
					$this->yesno( isset( $c['marketing'] ) ? $c['marketing']['value'] : 0 ),
					isset( $c['privacy_required'] ) ? $c['privacy_required']['policy'] : '',
					isset( $c['privacy_required'] ) ? $c['privacy_required']['ip'] : '',
					isset( $c['privacy_required'] ) ? $c['privacy_required']['at'] : '',
				),
				';'
			);
		}
		fclose( $out );
		exit;
	}

	/**
	 * Consent values + audit fields for export, keyed by registration then type.
	 *
	 * @param int[] $registration_ids Registration IDs.
	 * @return array<int,array<string,array{value:int,policy:string,ip:string,at:string}>>
	 */
	private function consents_for_full( $registration_ids ) {
		$ids = array_filter( array_map( 'absint', (array) $registration_ids ) );
		if ( empty( $ids ) ) {
			return array();
		}
		global $wpdb;
		$table        = $wpdb->prefix . 'hf_consents';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT registration_id, consent_type, consent_value, policy_version, ip_address, created_at FROM {$table} WHERE registration_id IN ({$placeholders})", $ids ) );

		$map = array();
		foreach ( $results as $r ) {
			$map[ (int) $r->registration_id ][ $r->consent_type ] = array(
				'value'  => (int) $r->consent_value,
				'policy' => $r->policy_version,
				'ip'     => $r->ip_address,
				'at'     => $r->created_at,
			);
		}
		return $map;
	}

	/**
	 * "Da"/"Nu" for a boolean consent value (Romanian, for the export).
	 *
	 * @param int $value 0/1.
	 * @return string
	 */
	private function yesno( $value ) {
		return (int) $value ? 'Da' : 'Nu';
	}
}
