<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure slot maths (no WordPress calls) so it can be unit-tested from the CLI.
 * Busy blocks are padded by the buffer on both sides, so viewings never sit back to back.
 */
class HZB_Slot_Calculator {
	/**
	 * @param array             $busy     List of array{start:string,end:string} (any ISO 8601 with offset).
	 * @param string            $from     Y-m-d in $tz, inclusive.
	 * @param string            $to       Y-m-d in $tz, inclusive.
	 * @param DateTimeZone      $tz       Agent timezone.
	 * @param array             $opts     hours_start, hours_end, days, duration, buffer, min_notice_hours.
	 * @param DateTimeImmutable $now      Injected for testing.
	 * @return array List of array{start:string,end:string} in ISO 8601 (agent offset).
	 */
	public static function compute( array $busy, $from, $to, DateTimeZone $tz, array $opts, DateTimeImmutable $now ) {
		$duration = (int) $opts['duration'] * 60;
		$buffer   = (int) $opts['buffer'] * 60;
		$earliest = $now->getTimestamp() + (int) $opts['min_notice_hours'] * 3600;

		$blocks = array();
		foreach ( $busy as $b ) {
			$blocks[] = array(
				strtotime( $b['start'] ) - $buffer,
				strtotime( $b['end'] ) + $buffer,
			);
		}

		$slots = array();
		$day   = new DateTimeImmutable( $from . ' 00:00:00', $tz );
		$last  = new DateTimeImmutable( $to . ' 00:00:00', $tz );
		for ( ; $day <= $last; $day = $day->modify( '+1 day' ) ) {
			if ( ! in_array( (int) $day->format( 'N' ), $opts['days'], true ) ) {
				continue;
			}
			$open  = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $opts['hours_start'], $tz );
			$close = new DateTimeImmutable( $day->format( 'Y-m-d' ) . ' ' . $opts['hours_end'], $tz );
			// Step by duration + buffer so offered slots themselves leave a gap.
			for ( $t = $open->getTimestamp(); $t + $duration <= $close->getTimestamp(); $t += $duration + $buffer ) {
				$end = $t + $duration;
				if ( $t < $earliest ) {
					continue;
				}
				foreach ( $blocks as $blk ) {
					if ( $t < $blk[1] && $end > $blk[0] ) {
						continue 2;
					}
				}
				$slots[] = array(
					'start' => ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz )->format( 'c' ),
					'end'   => ( new DateTimeImmutable( '@' . $end ) )->setTimezone( $tz )->format( 'c' ),
				);
			}
		}
		return $slots;
	}
}
