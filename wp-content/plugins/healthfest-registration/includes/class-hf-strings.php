<?php
/**
 * Bilingual UI strings. The plugin ships BOTH languages in code — Romanian
 * (self::all(), the primary language and source for Polylang) and English
 * (self::en()) — so the form, validation, and confirmation email are fully
 * localised out of the box with no manual translation step.
 *
 * Resolution order in HF_Strings::t():
 *   1. An organizer override entered in Languages → String translations (group
 *      "HealthFest") wins, if present — lets the organizer fine-tune wording.
 *   2. Otherwise the built-in value for the active language (EN on an English
 *      page, RO elsewhere).
 *   3. Romanian source as the ultimate fallback.
 *
 * NOTE: the consent texts below (RO and EN) are PLACEHOLDERS — the organizer must
 * review and finalise the legal wording, in code or via Polylang.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central registry of front-end strings.
 */
class HF_Strings {

	const GROUP = 'HealthFest';

	/**
	 * Forced active language slug (the real Polylang slug, e.g. 'ro' or 'en-gb') for
	 * contexts that have no page language to infer from — chiefly the admin-ajax
	 * registration handler, where Polylang otherwise reports the default (Romanian)
	 * regardless of the page the visitor submitted from. Null means "auto-detect".
	 * See self::set_lang().
	 *
	 * @var string|null
	 */
	private static $forced_lang = null;

	/**
	 * String key => Romanian default. Keys are stable; values are translatable.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			'register_heading'    => 'Înscriere la ateliere',
			'intro'               => 'Alege atelierele la care vrei să participi și completează datele de contact.',
			'choose_workshops'    => 'Alege atelierele',
			'seats_left'          => 'locuri disponibile',
			'full'                => 'COMPLET',
			'first_name'          => 'Prenume',
			'last_name'           => 'Nume',
			'email'               => 'E-mail',
			'phone'               => 'Telefon',
			'consent_section'     => 'Acorduri',
			'consent_privacy'     => 'Sunt de acord cu prelucrarea datelor mele personale conform Politicii de confidențialitate. (obligatoriu)',
			'consent_photo'       => 'Sunt de acord să fiu fotografiat(ă) / filmat(ă) în scopul promovării evenimentului. (opțional)',
			'consent_marketing'   => 'Doresc să primesc informații despre evenimentele și activitățile viitoare ale Vertical Freedom Foundation. (opțional)',
			'cancellation_note'   => 'Dacă nu mai poți participa, te rugăm să anunți organizatorul pentru a elibera locul.',
			'submit'              => 'Trimite înscrierea',
			'required_field'      => 'Acest câmp este obligatoriu.',
			'invalid_email'       => 'Adresă de e-mail invalidă.',
			'select_one'          => 'Te rugăm să alegi cel puțin un atelier.',
			'must_accept_privacy' => 'Trebuie să accepți prelucrarea datelor pentru a te înscrie.',
			'success'             => 'Înscriere reușită! Vei primi un e-mail de confirmare.',
			'error_generic'       => 'A apărut o eroare. Te rugăm să încerci din nou.',
			'rate_limited'        => 'Prea multe încercări. Te rugăm să aștepți câteva minute și să încerci din nou.',
			'already_registered'  => 'Ești deja înscris(ă) la acest atelier.',
			'workshop_full'       => 'Ne pare rău, atelierul s-a umplut între timp.',
			'no_workshops'        => 'Momentan nu există ateliere disponibile pentru înscriere.',
			'privacy_policy'      => 'Politica de confidențialitate',
			'email_subject'       => 'Confirmare înscriere — HealthFest',
			'email_greeting'      => 'Salut %s,',
			'email_intro'         => 'Îți confirmăm înscrierea la HealthFest. Te-ai înscris la:',
			'email_signature'     => "Cu drag,\nEchipa Vertical Freedom Foundation",
		);
	}

	/**
	 * English translations, keyed identically to self::all(). Any key absent here
	 * falls back to its Romanian value, so the table can be filled incrementally.
	 *
	 * @return array<string,string>
	 */
	public static function en() {
		return array(
			'register_heading'    => 'Workshop registration',
			'intro'               => 'Choose the workshops you want to attend and fill in your contact details.',
			'choose_workshops'    => 'Choose workshops',
			'seats_left'          => 'seats left',
			'full'                => 'FULL',
			'first_name'          => 'First name',
			'last_name'           => 'Last name',
			'email'               => 'Email',
			'phone'               => 'Phone',
			'consent_section'     => 'Consents',
			'consent_privacy'     => 'I agree to the processing of my personal data in accordance with the Privacy Policy. (required)',
			'consent_photo'       => 'I agree to be photographed / filmed for the purpose of promoting the event. (optional)',
			'consent_marketing'   => 'I would like to receive information about future events and activities of the Vertical Freedom Foundation. (optional)',
			'cancellation_note'   => 'If you can no longer attend, please notify the organizer so your seat can be released.',
			'submit'              => 'Submit registration',
			'required_field'      => 'This field is required.',
			'invalid_email'       => 'Invalid email address.',
			'select_one'          => 'Please choose at least one workshop.',
			'must_accept_privacy' => 'You must accept the data processing in order to register.',
			'success'             => 'Registration successful! You will receive a confirmation email.',
			'error_generic'       => 'Something went wrong. Please try again.',
			'rate_limited'        => 'Too many attempts. Please wait a few minutes and try again.',
			'already_registered'  => 'You are already registered for this workshop.',
			'workshop_full'       => 'Sorry, this workshop filled up in the meantime.',
			'no_workshops'        => 'There are currently no workshops available for registration.',
			'privacy_policy'      => 'Privacy Policy',
			'email_subject'       => 'Registration confirmation — HealthFest',
			'email_greeting'      => 'Hi %s,',
			'email_intro'         => 'We confirm your registration for HealthFest. You signed up for:',
			'email_signature'     => "Warm regards,\nThe Vertical Freedom Foundation Team",
		);
	}

	/**
	 * Keys whose values are long/multiline (consent + notes).
	 *
	 * @return string[]
	 */
	private static function multiline_keys() {
		return array( 'intro', 'consent_privacy', 'consent_photo', 'consent_marketing', 'cancellation_note', 'email_intro', 'email_signature' );
	}

	/**
	 * Register every string with Polylang (no-op when Polylang is inactive).
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'pll_register_string' ) ) {
			return;
		}
		$multiline = self::multiline_keys();
		foreach ( self::all() as $key => $default ) {
			pll_register_string( 'hf_' . $key, $default, self::GROUP, in_array( $key, $multiline, true ) );
		}
	}

	/**
	 * Force the active language slug for subsequent calls (the real Polylang slug,
	 * e.g. 'ro' or 'en'/'en-gb'), or pass null/'' to resume auto-detection. The AJAX
	 * registration handler calls this so the confirmation email and JSON messages
	 * match the page the visitor submitted from — admin-ajax has no page context for
	 * Polylang to read.
	 *
	 * @param string|null $slug Language slug, or null to auto-detect.
	 * @return void
	 */
	public static function set_lang( $slug ) {
		self::$forced_lang = ( is_string( $slug ) && '' !== $slug ) ? sanitize_key( $slug ) : null;
	}

	/**
	 * The active language slug as Polylang reports it (forced override > Polylang
	 * current language). Returns '' when there is no language context. This is the
	 * REAL slug (whatever the site configured — 'en', 'en-gb', …), suitable for the
	 * `lang` query var; use self::use_english() to choose the string table.
	 *
	 * @return string
	 */
	public static function current_lang() {
		if ( null !== self::$forced_lang ) {
			return self::$forced_lang;
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$slug = pll_current_language( 'slug' );
			if ( is_string( $slug ) && '' !== $slug ) {
				return $slug;
			}
		}
		return '';
	}

	/**
	 * Whether the built-in English string table should be used for the active
	 * language. Romanian is the primary language, so ANY other Polylang language is
	 * treated as the (English) secondary — comparing against the default avoids
	 * hardcoding the English slug, which may be 'en', 'en-gb', 'en-us', etc.
	 *
	 * @return bool
	 */
	private static function use_english() {
		$slug = self::current_lang();

		// No Polylang context — decide from the WordPress locale instead.
		if ( '' === $slug ) {
			$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
			return ( 0 === strpos( (string) $locale, 'en' ) );
		}

		if ( function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language( 'slug' );
			if ( is_string( $default ) && '' !== $default ) {
				return ( $slug !== $default );
			}
		}

		// Polylang helpers unavailable but a slug was forced — treat en* as English.
		return ( 0 === strpos( $slug, 'en' ) );
	}

	/**
	 * Translate a string by key for the active language.
	 *
	 * Precedence: a Polylang String-translation override (organizer-edited) wins;
	 * otherwise the built-in value for the active language; otherwise Romanian.
	 *
	 * @param string $key String key from self::all().
	 * @return string
	 */
	public static function t( $key ) {
		$all = self::all();
		$ro  = isset( $all[ $key ] ) ? $all[ $key ] : $key;

		// 1) Organizer override via Languages → String translations, when present
		//    and meaningfully different from the Romanian source. On admin-ajax this
		//    resolves in the default (RO) language, so an EN override there is not
		//    applied — the built-in EN below still guarantees the correct language.
		if ( function_exists( 'pll__' ) ) {
			$override = pll__( $ro );
			if ( is_string( $override ) && '' !== $override && $override !== $ro ) {
				return $override;
			}
		}

		// 2) Built-in translation for the active language.
		if ( self::use_english() ) {
			$en = self::en();
			if ( isset( $en[ $key ] ) && '' !== $en[ $key ] ) {
				return $en[ $key ];
			}
		}

		// 3) Romanian source / ultimate fallback.
		return $ro;
	}

	/**
	 * Return all strings translated for the current language (for JS localisation).
	 *
	 * @return array<string,string>
	 */
	public static function localized() {
		$out = array();
		foreach ( array_keys( self::all() ) as $key ) {
			$out[ $key ] = self::t( $key );
		}
		return $out;
	}
}
