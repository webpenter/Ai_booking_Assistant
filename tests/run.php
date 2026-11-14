<?php
// CLI unit tests for the pure slot maths: php tests/run.php
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-slot-calculator.php';

$fail = 0;
function check( $name, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$fail += $cond ? 0 : 1;
}

$tz   = new DateTimeZone( 'Europe/Rome' );
$opts = array( 'hours_start' => '09:00', 'hours_end' => '12:00', 'days' => array( 1, 2, 3, 4, 5 ), 'duration' => 30, 'buffer' => 15, 'min_notice_hours' => 0 );
$now  = new DateTimeImmutable( '2030-01-01 00:00:00', $tz ); // A Tuesday, far enough ahead.

// Tue 2030-01-08: 09:00-12:00, step 45 min => 09:00, 09:45, 10:30, 11:15 (ends 11:45).
$all = HZB_Slot_Calculator::compute( array(), '2030-01-08', '2030-01-08', $tz, $opts, $now );
check( 'empty calendar gives 4 slots', count( $all ) === 4 );
check( 'first slot 09:00', substr( $all[0]['start'], 11, 5 ) === '09:00' );

// Busy 10:00-10:30 (+/-15 buffer = 09:45-10:45) removes 09:45 and 10:30.
$busy = array( array( 'start' => '2030-01-08T10:00:00+01:00', 'end' => '2030-01-08T10:30:00+01:00' ) );
$s    = HZB_Slot_Calculator::compute( $busy, '2030-01-08', '2030-01-08', $tz, $opts, $now );
$st   = array_map( function ( $x ) { return substr( $x['start'], 11, 5 ); }, $s );
check( 'busy block removes overlapping slots', $st === array( '09:00', '11:15' ) );

// Weekend excluded (2030-01-12 is Saturday).
check( 'weekend skipped', HZB_Slot_Calculator::compute( array(), '2030-01-12', '2030-01-13', $tz, $opts, $now ) === array() );

// Minimum notice: now 09:30 + 2h notice => earliest 11:30, nothing at 11:15.
$o2 = $opts; $o2['min_notice_hours'] = 2;
$n2 = new DateTimeImmutable( '2030-01-08 09:30:00', $tz );
check( 'min notice enforced', HZB_Slot_Calculator::compute( array(), '2030-01-08', '2030-01-08', $tz, $o2, $n2 ) === array() );

exit( $fail ? 1 : 0 );
