<?php
/**
 * Translatable UI strings, registered with Polylang so the organizer can edit
 * both languages from Languages → String translations (group "HealthFest").
 *
 * Defaults are Romanian (the site's primary language). When Polylang is active,
 * HF_Strings::t() returns the translation for the current language; otherwise it
 * falls back to the Romanian default.
 *
 * NOTE: the consent texts below are PLACEHOLDERS — the organizer must review and
 * finalise the legal wording (and the English translations) in Polylang.
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
	 * Translate a string by key for the current language.
	 *
	 * @param string $key String key from self::all().
	 * @return string
	 */
	public static function t( $key ) {
		$all     = self::all();
		$default = isset( $all[ $key ] ) ? $all[ $key ] : $key;
		if ( function_exists( 'pll__' ) ) {
			return pll__( $default );
		}
		return $default;
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
