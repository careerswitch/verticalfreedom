<?php
/**
 * Seat accounting — the heart of overbooking prevention.
 *
 * Workshops are translatable via Polylang, so the EN and RO versions of one
 * workshop are separate posts with different IDs. To make them share a single
 * pool of seats, every seat operation is keyed to the workshop's CANONICAL id:
 * the post in the site's default (Romanian) language. Reservation uses a single
 * conditional UPDATE, which is atomic at the database level and therefore safe
 * against two visitors grabbing the last seat simultaneously.
 *
 * @package HealthFest_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static helper for reading and mutating workshop seat counts.
 */
class HF_Seats {

	const TABLE = 'hf_workshop_seats';

	/**
	 * Resolve any workshop translation to its canonical (default-language) ID so
	 * all Polylang translations of the same workshop share one seat pool.
	 *
	 * Falls back to the given ID when Polylang is inactive or no translation
	 * exists yet.
	 *
	 * @param int $workshop_id Any language's workshop post ID.
	 * @return int Canonical workshop post ID.
	 */
	public static function canonical_id( $workshop_id ) {
		$workshop_id = (int) $workshop_id;

		if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language();
			if ( $default ) {
				$canonical = pll_get_post( $workshop_id, $default );
				if ( $canonical ) {
					return (int) $canonical;
				}
			}
		}

		return $workshop_id;
	}

	/**
	 * Fully-qualified seat-counter table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Availability snapshot for a workshop (any language).
	 *
	 * @param int $workshop_id Workshop post ID.
	 * @return array{workshop_id:int,limit:int,taken:int,remaining:int,is_full:bool}
	 */
	public static function availability( $workshop_id ) {
		global $wpdb;

		$cid   = self::canonical_id( $workshop_id );
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom plugin table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT seat_limit, seats_taken FROM {$table} WHERE workshop_id = %d", $cid ), ARRAY_A );

		$limit = $row ? (int) $row['seat_limit'] : (int) get_post_meta( $cid, '_hf_seat_limit', true );
		$taken = $row ? (int) $row['seats_taken'] : 0;

		return array(
			'workshop_id' => $cid,
			'limit'       => $limit,
			'taken'       => $taken,
			'remaining'   => max( 0, $limit - $taken ),
			'is_full'     => ( $limit > 0 && $taken >= $limit ),
		);
	}

	/**
	 * Atomically reserve one seat.
	 *
	 * The conditional UPDATE only succeeds while seats remain, so concurrent
	 * submissions can never oversell the last seat. Returns false when the
	 * workshop is full (or has no seat limit set).
	 *
	 * @param int $workshop_id Workshop post ID.
	 * @return bool True if a seat was reserved.
	 */
	public static function reserve( $workshop_id ) {
		global $wpdb;

		$cid = self::canonical_id( $workshop_id );
		self::ensure_row( $cid );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic guarded counter update.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET seats_taken = seats_taken + 1 WHERE workshop_id = %d AND seat_limit > 0 AND seats_taken < seat_limit",
				$cid
			)
		);

		return ( 1 === (int) $affected );
	}

	/**
	 * Release one seat (used when an admin cancels a registration). Never drops
	 * below zero.
	 *
	 * @param int $workshop_id Workshop post ID.
	 * @return bool True if a seat was released.
	 */
	public static function release( $workshop_id ) {
		global $wpdb;

		$cid = self::canonical_id( $workshop_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic guarded counter update.
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE " . self::table() . " SET seats_taken = seats_taken - 1 WHERE workshop_id = %d AND seats_taken > 0",
				$cid
			)
		);

		return ( 1 === (int) $affected );
	}

	/**
	 * Set/refresh the seat limit for a workshop without disturbing seats_taken.
	 * The limit is always stored against the canonical workshop.
	 *
	 * @param int $workshop_id Workshop post ID.
	 * @param int $limit       Seat limit.
	 * @return void
	 */
	public static function set_limit( $workshop_id, $limit ) {
		global $wpdb;

		$cid = self::canonical_id( $workshop_id );
		self::ensure_row( $cid );

		$wpdb->update(
			self::table(),
			array( 'seat_limit' => max( 0, (int) $limit ) ),
			array( 'workshop_id' => $cid ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Guarantee a counter row exists for a canonical workshop, seeded from meta.
	 *
	 * @param int $canonical_id Canonical workshop post ID.
	 * @return void
	 */
	public static function ensure_row( $canonical_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- existence check on custom table.
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT workshop_id FROM {$table} WHERE workshop_id = %d", $canonical_id ) );

		if ( null === $exists ) {
			$wpdb->insert(
				$table,
				array(
					'workshop_id' => (int) $canonical_id,
					'seat_limit'  => (int) get_post_meta( $canonical_id, '_hf_seat_limit', true ),
					'seats_taken' => 0,
				),
				array( '%d', '%d', '%d' )
			);
		}
	}
}
