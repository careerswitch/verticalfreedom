<?php
/**
 * Workshop custom post type — organizer-facing management of each workshop,
 * its seat limit, schedule, and location, plus an at-a-glance seats column.
 *
 * Workshops are registered as Polylang-translatable. The seat limit is always
 * managed on the default-language (Romanian) workshop; secondary-language
 * translations share that limit and seat pool via HF_Seats canonicalization.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the workshop CPT and keeps the atomic seat-counter table in sync.
 */
class HF_Workshop_CPT {

	const POST_TYPE = 'hf_workshop';

	/**
	 * Wire up all WordPress hooks for the workshop CPT.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_filter( 'pll_get_post_types', array( $this, 'make_translatable' ), 10, 2 );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'force_classic_editor' ), 10, 2 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'render_admin_column' ), 10, 2 );
	}

	/**
	 * Register the workshop post type.
	 *
	 * @return void
	 */
	public function register_post_type() {
		$labels = array(
			'name'          => __( 'Workshops', 'healthfest-registration' ),
			'singular_name' => __( 'Workshop', 'healthfest-registration' ),
			'add_new'       => __( 'Add New', 'healthfest-registration' ),
			'add_new_item'  => __( 'Add New Workshop', 'healthfest-registration' ),
			'edit_item'     => __( 'Edit Workshop', 'healthfest-registration' ),
			'new_item'      => __( 'New Workshop', 'healthfest-registration' ),
			'view_item'     => __( 'View Workshop', 'healthfest-registration' ),
			'search_items'  => __( 'Search Workshops', 'healthfest-registration' ),
			'not_found'     => __( 'No workshops found', 'healthfest-registration' ),
			'menu_name'     => __( 'HealthFest', 'healthfest-registration' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-calendar-alt',
				'menu_position'   => 25,
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'has_archive'     => true,
				'rewrite'         => array( 'slug' => 'workshops' ),
				'show_in_rest'    => true,
				'capability_type' => 'post',
			)
		);
	}

	/**
	 * Tell Polylang the workshop CPT is translatable (EN + RO).
	 *
	 * @param array $post_types Translatable post types keyed by name.
	 * @param bool  $is_settings Whether called from the Polylang settings screen.
	 * @return array
	 */
	public function make_translatable( $post_types, $is_settings ) {
		$post_types[ self::POST_TYPE ] = self::POST_TYPE;
		return $post_types;
	}

	/**
	 * Use the simple classic editor for workshops instead of the block editor /
	 * page builder — organizers only need a title, a short description, and the
	 * Workshop Details sidebar.
	 *
	 * @param bool   $use_block Whether to use the block editor.
	 * @param string $post_type Post type being edited.
	 * @return bool
	 */
	public function force_classic_editor( $use_block, $post_type ) {
		return ( self::POST_TYPE === $post_type ) ? false : $use_block;
	}

	/**
	 * Register the workshop details meta box.
	 *
	 * @return void
	 */
	public function add_meta_boxes() {
		add_meta_box(
			'hf_workshop_details',
			__( 'Workshop Details', 'healthfest-registration' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the workshop details meta box (seat limit, schedule, location).
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		wp_nonce_field( 'hf_save_workshop', 'hf_workshop_nonce' );

		$seat_limit     = (int) get_post_meta( $post->ID, '_hf_seat_limit', true );
		$presenter      = (string) get_post_meta( $post->ID, '_hf_presenter', true );
		$start_datetime = (string) get_post_meta( $post->ID, '_hf_start_datetime', true );
		$end_datetime   = (string) get_post_meta( $post->ID, '_hf_end_datetime', true );
		$location       = (string) get_post_meta( $post->ID, '_hf_location', true );
		$availability   = HF_Seats::availability( $post->ID );
		$is_secondary   = $this->is_secondary_language( $post->ID );
		?>
		<?php if ( $is_secondary ) : ?>
			<p class="description" style="padding:6px;background:#fff8e5;border-left:3px solid #dba617;">
				<?php esc_html_e( 'Seat limit is managed on the Romanian (primary) version of this workshop. The value here is for reference only.', 'healthfest-registration' ); ?>
			</p>
		<?php endif; ?>
		<p>
			<label for="hf_seat_limit"><strong><?php esc_html_e( 'Seat limit', 'healthfest-registration' ); ?></strong></label><br />
			<input type="number" min="0" step="1" id="hf_seat_limit" name="hf_seat_limit" value="<?php echo esc_attr( (string) $seat_limit ); ?>" class="widefat" <?php disabled( $is_secondary ); ?> />
			<span class="description">
				<?php echo esc_html( sprintf( /* translators: %d: seats already reserved */ __( '%d reserved so far', 'healthfest-registration' ), $availability['taken'] ) ); ?>
			</span>
		</p>
		<p>
			<label for="hf_presenter"><strong><?php esc_html_e( 'Presenter / Therapist', 'healthfest-registration' ); ?></strong></label><br />
			<input type="text" id="hf_presenter" name="hf_presenter" value="<?php echo esc_attr( $presenter ); ?>" class="widefat" placeholder="<?php esc_attr_e( 'e.g. Mira, or Oana & Virgi', 'healthfest-registration' ); ?>" />
		</p>
		<p>
			<label for="hf_start_datetime"><strong><?php esc_html_e( 'Start (date & time)', 'healthfest-registration' ); ?></strong></label><br />
			<input type="datetime-local" id="hf_start_datetime" name="hf_start_datetime" value="<?php echo esc_attr( $start_datetime ); ?>" class="widefat" />
		</p>
		<p>
			<label for="hf_end_datetime"><strong><?php esc_html_e( 'End (date & time)', 'healthfest-registration' ); ?></strong></label><br />
			<input type="datetime-local" id="hf_end_datetime" name="hf_end_datetime" value="<?php echo esc_attr( $end_datetime ); ?>" class="widefat" />
		</p>
		<p>
			<label for="hf_location"><strong><?php esc_html_e( 'Location', 'healthfest-registration' ); ?></strong></label><br />
			<input type="text" id="hf_location" name="hf_location" value="<?php echo esc_attr( $location ); ?>" class="widefat" />
		</p>
		<?php
	}

	/**
	 * Persist workshop meta and sync the canonical seat-counter row.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['hf_workshop_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['hf_workshop_nonce'] ) ), 'hf_save_workshop' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$presenter = isset( $_POST['hf_presenter'] ) ? sanitize_text_field( wp_unslash( $_POST['hf_presenter'] ) ) : '';
		$start     = isset( $_POST['hf_start_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['hf_start_datetime'] ) ) : '';
		$end       = isset( $_POST['hf_end_datetime'] ) ? sanitize_text_field( wp_unslash( $_POST['hf_end_datetime'] ) ) : '';
		$location  = isset( $_POST['hf_location'] ) ? sanitize_text_field( wp_unslash( $_POST['hf_location'] ) ) : '';
		update_post_meta( $post_id, '_hf_presenter', $presenter );
		update_post_meta( $post_id, '_hf_start_datetime', $start );
		update_post_meta( $post_id, '_hf_end_datetime', $end );
		update_post_meta( $post_id, '_hf_location', $location );

		// Seat limit is authoritative only on the primary-language workshop.
		if ( ! $this->is_secondary_language( $post_id ) ) {
			$seat_limit = isset( $_POST['hf_seat_limit'] ) ? max( 0, (int) $_POST['hf_seat_limit'] ) : 0;
			update_post_meta( $post_id, '_hf_seat_limit', $seat_limit );
			HF_Seats::set_limit( $post_id, $seat_limit );
		}
	}

	/**
	 * Whether the given workshop is a secondary-language translation (i.e. not
	 * the canonical default-language post). Returns false when Polylang is off.
	 *
	 * @param int $post_id Workshop post ID.
	 * @return bool
	 */
	private function is_secondary_language( $post_id ) {
		if ( ! function_exists( 'pll_get_post' ) || ! function_exists( 'pll_default_language' ) ) {
			return false;
		}
		$canonical = HF_Seats::canonical_id( $post_id );
		return ( (int) $canonical !== (int) $post_id );
	}

	/**
	 * Add a "Seats" column to the workshop admin list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function admin_columns( $columns ) {
		$columns['hf_presenter'] = __( 'Presenter', 'healthfest-registration' );
		$columns['hf_seats']     = __( 'Seats (taken / limit)', 'healthfest-registration' );
		return $columns;
	}

	/**
	 * Render the custom "Seats" column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( $column, $post_id ) {
		if ( 'hf_presenter' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, '_hf_presenter', true ) );
			return;
		}
		if ( 'hf_seats' !== $column ) {
			return;
		}
		$a    = HF_Seats::availability( $post_id );
		$full = $a['is_full'] ? ' — ' . __( 'FULL', 'healthfest-registration' ) : '';
		echo esc_html( $a['taken'] . ' / ' . $a['limit'] . $full );
	}
}
