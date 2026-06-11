<?php
/**
 * Shared formatting helpers (date/time, workshop summaries) used by both the
 * front-end form and the confirmation email so they always agree.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless utility helpers.
 */
class HF_Util {

	/**
	 * Format a naive datetime-local value (Y-m-d\TH:i) WITHOUT timezone
	 * conversion, so it displays exactly as the organizer entered it.
	 *
	 * @param string $value Stored datetime-local value.
	 * @return array{day:string,time:string,ts:int}
	 */
	public static function parse_dt( $value ) {
		if ( ! $value ) {
			return array(
				'day'  => '',
				'time' => '',
				'ts'   => 0,
			);
		}
		$dt = DateTime::createFromFormat( 'Y-m-d\TH:i', $value );
		if ( ! $dt ) {
			$dt = DateTime::createFromFormat( 'Y-m-d\TH:i:s', $value );
		}
		if ( ! $dt ) {
			return array(
				'day'  => '',
				'time' => '',
				'ts'   => 0,
			);
		}
		return array(
			'day'  => $dt->format( 'd.m.Y' ),
			'time' => $dt->format( 'H:i' ),
			'ts'   => $dt->getTimestamp(),
		);
	}

	/**
	 * Build a "10:00–12:00" range from two datetime-local values.
	 *
	 * @param string $start_value Start datetime-local.
	 * @param string $end_value   End datetime-local.
	 * @return string
	 */
	public static function time_range( $start_value, $end_value ) {
		$start = self::parse_dt( $start_value );
		$end   = self::parse_dt( $end_value );
		$range = $start['time'];
		if ( $end['time'] ) {
			$range .= '–' . $end['time'];
		}
		return trim( $range );
	}

	/**
	 * One-line summary of a workshop for the confirmation email, e.g.
	 * "Yoga terapeutică — 26.06.2026, 10:00–12:00 (Mira, Health Fest)".
	 *
	 * @param int    $workshop_id Workshop post ID.
	 * @param string $title       Workshop title.
	 * @return string
	 */
	public static function workshop_line( $workshop_id, $title ) {
		$start     = (string) get_post_meta( $workshop_id, '_hf_start_datetime', true );
		$end       = (string) get_post_meta( $workshop_id, '_hf_end_datetime', true );
		$presenter = (string) get_post_meta( $workshop_id, '_hf_presenter', true );
		$location  = (string) get_post_meta( $workshop_id, '_hf_location', true );

		$day   = self::parse_dt( $start )['day'];
		$range = self::time_range( $start, $end );

		$when = array_filter( array( $day, $range ) );
		$line = $title;
		if ( $when ) {
			$line .= ' — ' . implode( ', ', $when );
		}
		$extra = array_filter( array( $presenter, $location ) );
		if ( $extra ) {
			$line .= ' (' . implode( ', ', $extra ) . ')';
		}
		return $line;
	}
}
