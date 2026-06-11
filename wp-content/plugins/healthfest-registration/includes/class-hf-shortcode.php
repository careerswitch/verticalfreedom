<?php
/**
 * Front-end: the [healthfest_registration] shortcode renders the workshop list
 * (grouped by day, with live seat availability) and the registration form.
 *
 * Submission is handled over AJAX (see HF_Registration_Handler) so that seat
 * counts stay live even when the host's LiteSpeed cache serves a cached page.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the registration shortcode.
 */
class HF_Shortcode {

	const SHORTCODE = 'healthfest_registration';

	/**
	 * Hook the shortcode and asset registration.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register (but do not force-enqueue) the front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_style( 'hf-frontend', HF_PLUGIN_URL . 'assets/css/hf-frontend.css', array(), HF_VERSION );
		wp_register_script( 'hf-frontend', HF_PLUGIN_URL . 'assets/js/hf-frontend.js', array(), HF_VERSION, true );
	}

	/**
	 * Fetch published workshops for the current language, ordered by start time.
	 *
	 * Polylang automatically scopes the query to the current language, so each
	 * language's page shows its own workshop posts (sharing one seat pool).
	 *
	 * @return WP_Post[]
	 */
	private function get_workshops() {
		$query = new WP_Query(
			array(
				'post_type'      => HF_Workshop_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'meta_key'       => '_hf_start_datetime',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		return $query->posts;
	}

	/**
	 * Format a stored datetime-local value (Y-m-d\TH:i) into a day label + time.
	 *
	 * @param string $value Stored datetime-local string.
	 * @return array{day:string,time:string,ts:int}
	 */
	private function parse_dt( $value ) {
		return HF_Util::parse_dt( $value );
	}

	/**
	 * Render the shortcode output.
	 *
	 * @return string HTML.
	 */
	public function render() {
		wp_enqueue_style( 'hf-frontend' );
		wp_enqueue_script( 'hf-frontend' );
		wp_localize_script(
			'hf-frontend',
			'HF_DATA',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'hf_register' ),
				'strings' => HF_Strings::localized(),
			)
		);

		$workshops = $this->get_workshops();

		ob_start();
		echo '<div class="hf-registration">';
		echo '<h2 class="hf-heading">' . esc_html( HF_Strings::t( 'register_heading' ) ) . '</h2>';
		echo '<p class="hf-intro">' . esc_html( HF_Strings::t( 'intro' ) ) . '</p>';

		if ( empty( $workshops ) ) {
			echo '<p class="hf-empty">' . esc_html( HF_Strings::t( 'no_workshops' ) ) . '</p></div>';
			return ob_get_clean();
		}

		echo '<form class="hf-form" novalidate>';
		wp_nonce_field( 'hf_register', 'hf_nonce' );

		// --- Workshop chooser, grouped by day ---
		echo '<fieldset class="hf-workshops"><legend>' . esc_html( HF_Strings::t( 'choose_workshops' ) ) . '</legend>';
		$current_day = null;
		foreach ( $workshops as $post ) {
			$start = $this->parse_dt( (string) get_post_meta( $post->ID, '_hf_start_datetime', true ) );
			$end   = $this->parse_dt( (string) get_post_meta( $post->ID, '_hf_end_datetime', true ) );
			if ( $start['day'] !== $current_day ) {
				if ( null !== $current_day ) {
					echo '</div>';
				}
				$current_day = $start['day'];
				echo '<h3 class="hf-day">' . esc_html( $current_day ) . '</h3><div class="hf-day-list">';
			}

			$avail      = HF_Seats::availability( $post->ID );
			$presenter  = (string) get_post_meta( $post->ID, '_hf_presenter', true );
			$location   = (string) get_post_meta( $post->ID, '_hf_location', true );
			$time_range = trim( $start['time'] . ( $end['time'] ? '–' . $end['time'] : '' ) );

			printf(
				'<label class="hf-workshop%1$s" data-workshop="%2$d" data-remaining="%3$d">',
				$avail['is_full'] ? ' hf-is-full' : '',
				(int) $post->ID,
				(int) $avail['remaining']
			);
			printf(
				'<input type="checkbox" name="workshops[]" value="%1$d"%2$s />',
				(int) $post->ID,
				$avail['is_full'] ? ' disabled' : ''
			);
			echo '<span class="hf-w-body">';
			echo '<span class="hf-w-title">' . esc_html( get_the_title( $post ) ) . '</span>';
			if ( $time_range ) {
				echo '<span class="hf-w-time">' . esc_html( $time_range ) . '</span>';
			}
			if ( $presenter ) {
				echo '<span class="hf-w-presenter">' . esc_html( $presenter ) . '</span>';
			}
			if ( $location ) {
				echo '<span class="hf-w-location">' . esc_html( $location ) . '</span>';
			}
			echo '<span class="hf-w-seats">';
			if ( $avail['is_full'] ) {
				echo '<span class="hf-badge hf-badge-full">' . esc_html( HF_Strings::t( 'full' ) ) . '</span>';
			} else {
				echo '<span class="hf-badge">' . esc_html( $avail['remaining'] . ' ' . HF_Strings::t( 'seats_left' ) ) . '</span>';
			}
			echo '</span></span></label>';
		}
		if ( null !== $current_day ) {
			echo '</div>';
		}
		echo '</fieldset>';

		// --- Contact details ---
		echo '<fieldset class="hf-details">';
		$this->text_field( 'first_name', HF_Strings::t( 'first_name' ), true );
		$this->text_field( 'last_name', HF_Strings::t( 'last_name' ), true );
		$this->text_field( 'email', HF_Strings::t( 'email' ), true, 'email' );
		$this->text_field( 'phone', HF_Strings::t( 'phone' ), true, 'tel' );
		echo '</fieldset>';

		// --- Consents ---
		echo '<fieldset class="hf-consents"><legend>' . esc_html( HF_Strings::t( 'consent_section' ) ) . '</legend>';
		$this->consent_field( 'privacy', $this->privacy_label(), true );
		$this->consent_field( 'photo', HF_Strings::t( 'consent_photo' ), false );
		$this->consent_field( 'marketing', HF_Strings::t( 'consent_marketing' ), false );
		echo '</fieldset>';

		echo '<p class="hf-cancellation-note">' . esc_html( HF_Strings::t( 'cancellation_note' ) ) . '</p>';

		echo '<div class="hf-message" role="status" aria-live="polite"></div>';
		echo '<button type="submit" class="hf-submit">' . esc_html( HF_Strings::t( 'submit' ) ) . '</button>';

		echo '</form></div>';
		return ob_get_clean();
	}

	/**
	 * Render a labelled text input.
	 *
	 * @param string $name     Field name.
	 * @param string $label    Visible label.
	 * @param bool   $required Whether required.
	 * @param string $type     Input type.
	 * @return void
	 */
	private function text_field( $name, $label, $required, $type = 'text' ) {
		printf(
			'<p class="hf-field"><label for="hf_%1$s">%2$s%3$s</label><input type="%4$s" id="hf_%1$s" name="%1$s"%5$s /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$required ? ' <span class="hf-req">*</span>' : '',
			esc_attr( $type ),
			$required ? ' required' : ''
		);
	}

	/**
	 * Render a consent checkbox.
	 *
	 * @param string $name     Consent slug (privacy|photo|marketing).
	 * @param string $label    Visible (possibly HTML) label.
	 * @param bool   $required Whether required.
	 * @return void
	 */
	private function consent_field( $name, $label, $required ) {
		printf(
			'<p class="hf-consent"><label><input type="checkbox" name="consent_%1$s" value="1"%2$s /> <span>%3$s</span></label></p>',
			esc_attr( $name ),
			$required ? ' required' : '',
			wp_kses_post( $label )
		);
	}

	/**
	 * Privacy consent label, linking to the site's privacy policy page if set.
	 *
	 * @return string HTML.
	 */
	private function privacy_label() {
		$text = HF_Strings::t( 'consent_privacy' );
		$url  = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( ! $url ) {
			return esc_html( $text );
		}
		// Link the policy name within the consent text if present, else append a link.
		$policy = HF_Strings::t( 'privacy_policy' );
		$link   = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $policy ) . '</a>';
		if ( false !== strpos( $text, $policy ) ) {
			return str_replace( $policy, $link, esc_html( $text ) );
		}
		return esc_html( $text ) . ' (' . $link . ')';
	}
}
