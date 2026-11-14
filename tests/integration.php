<?php
// Integration test (WordPress + live Google Calendar, mocked Groq): php tests/integration.php [agent_id] [listing_id] [buyer_email]
// Creates real events on the agent's calendar and removes them again by the end.
define( 'WP_USE_THEMES', false );
require dirname( __DIR__, 4 ) . '/wp-load.php';

$agent   = (int) ( $argv[1] ?? 158 );
$listing = (int) ( $argv[2] ?? 0 );
$email   = $argv[3] ?? 'sainmehtab15@gmail.com';
if ( ! $listing ) {
	$l       = get_posts( array( 'post_type' => 'property', 'numberposts' => 1, 'fields' => 'ids' ) );
	$listing = (int) $l[0];
}
$orig_agents = get_post_meta( $listing, 'fave_agents' );
delete_post_meta( $listing, 'fave_agents' );
add_post_meta( $listing, 'fave_agents', $agent ); // Listing's own agent is the only calendar used.
$fail = 0;
function check( $name, $cond ) {
	global $fail;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $name . "\n";
	$fail += $cond ? 0 : 1;
}

$queue = array();
$mails = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$queue ) {
	if ( strpos( $url, 'api.groq.com' ) === false ) {
		return $pre;
	}
	$next = array_shift( $queue );
	if ( $next === 'DOWN' ) {
		return new WP_Error( 'down', 'simulated outage' );
	}
	return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( $next ) ) ) ) ) ) );
}, 10, 3 );
add_filter( 'wp_mail', function ( $a ) use ( &$mails ) { $mails[] = $a; $a['to'] = array(); return $a; } );

function say( $sid, $msg, $ai, $extra = array() ) {
	global $queue, $listing;
	$queue = $ai === null ? array() : array( $ai );
	$req   = new WP_REST_Request( 'POST', '/houzez-booking/v1/chat' );
	$req->set_body_params( array_merge( array( 'session' => $sid, 'listing_id' => $listing, 'message' => $msg, 'tz' => 'Europe/London' ), $extra ) );
	$res = HZB_Chat::handle( $req )->get_data();
	echo "  > $msg\n  < " . $res['reply'] . "\n";
	return $res;
}
function ai( $intent, $extra = array() ) {
	return array_merge( array( 'reply' => 'ok', 'intent' => $intent, 'slot_date' => null, 'slot_time' => null, 'viewing_type' => null, 'name' => null, 'email' => null ), $extra );
}
function reset_state( $agent ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . HZB_Bookings::table() . ' WHERE ip_hash = %s OR buyer_email LIKE %s', HZB_Chat::ip_hash(), '%@limit.test' ) );
	foreach ( array( 'chat', 'write' ) as $bucket ) { // CLI has no IP; reset the shared buckets so reruns work.
		delete_transient( 'hzb_rl_' . $bucket . '_' . md5( '' ) );
	}
	delete_transient( 'hzb_slots_' . $agent );
	delete_transient( 'hzb_slots_stale_' . $agent );
}
reset_state( $agent );
$orig_settings = get_option( HZB_Settings::OPTION );

$tz    = HZB_Calendar::timezone( $agent );
$today = new DateTimeImmutable( 'now', $tz );
$slots = get_agent_availability( $agent, $today->format( 'Y-m-d' ), $today->modify( '+10 days' )->format( 'Y-m-d' ) );
check( 'availability returns slots (agent tz ' . $tz->getName() . ')', is_array( $slots ) && count( $slots ) > 8 );
$ts = array_map( function ( $x ) { return strtotime( $x['start'] ); }, $slots );
$k  = function ( $t ) use ( $tz ) { $d = ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz ); return array( $d->format( 'Y-m-d' ), $d->format( 'H:i' ) ); };
list( $d1, $t1 ) = $k( $ts[1] );

echo "HOLD FLOW (chat)\n";
$r   = say( '', '', null );
$sid = $r['session'];
check( 'greeting names the agent, no LLM', stripos( $r['reply'], get_the_title( $agent ) ) !== false );
say( $sid, 'virtual please', ai( 'chat', array( 'viewing_type' => 'virtual' ) ) );
$r = say( $sid, 'Book ' . $d1 . ' ' . $t1, ai( 'propose_slot', array( 'slot_date' => $d1, 'slot_time' => $t1 ) ) );
check( 'asks for name+email first', stripos( $r['reply'], 'email' ) !== false );
$r = say( $sid, "I'm Test Buyer, email " . $email, ai( 'chat', array( 'name' => 'Test Buyer', 'email' => $email ) ) );
check( 'server-written confirmation question', stripos( $r['reply'], 'Shall I go ahead' ) !== false && $r['awaiting'] );
$r = say( $sid, 'yes', ai( 'confirm' ) );
check( 'slot is HELD, not booked', stripos( $r['reply'], 'holding' ) !== false && stripos( $r['reply'], 'You are booked' ) === false );
global $wpdb;
$b = $wpdb->get_row( 'SELECT * FROM ' . HZB_Bookings::table() . ' ORDER BY id DESC LIMIT 1' );
check( 'row is pending with no Google event', $b && $b->status === 'pending' && $b->event_id === '' );
$hold_mail = array_values( array_filter( $mails, function ( $m ) { return stripos( $m['subject'], 'confirm your viewing' ) !== false; } ) );
check( 'confirmation email with link sent', $hold_mail && strpos( $hold_mail[0]['message'], 'hzb_confirm=' . $b->token ) !== false );

echo "AGENT ISOLATION\n";
$others        = get_posts( array( 'post_type' => 'property', 'numberposts' => 1, 'fields' => 'ids', 'exclude' => array( $listing ) ) );
$other_listing = (int) $others[0];
$saved_other   = get_post_meta( $other_listing, 'fave_agents' );
delete_post_meta( $other_listing, 'fave_agents' );
check( 'listing without an assigned agent shows no widget', HZB_Chat::resolve_agent( $other_listing ) === 0 );
add_post_meta( $other_listing, 'fave_agents', 17983 ); // An agent who has not connected a calendar.
check( 'assigned agent without a calendar shows no widget', HZB_Chat::resolve_agent( $other_listing ) === 0 );
delete_post_meta( $other_listing, 'fave_agents' );
foreach ( $saved_other as $o ) { add_post_meta( $other_listing, 'fave_agents', $o ); }
check( 'listing with a connected agent uses that agent', HZB_Chat::resolve_agent( $listing ) === $agent );

echo "HOLD BLOCKS OTHERS / EXPIRES\n";
check( 'held slot no longer free for others', HZB_Booking_Service::slot_is_free( $agent, strtotime( $b->start_utc . ' UTC' ) ) === false );
$other = HZB_Booking_Service::hold_viewing( $agent, strtotime( $b->start_utc . ' UTC' ), 'other@example.com', $listing, false, 'Other', '', 'x' );
check( 'second visitor refused (taken)', is_wp_error( $other ) && $other->get_error_code() === 'hzb_taken' );
HZB_Bookings::update( $b->id, array( 'hold_until' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
check( 'expired hold frees the slot', HZB_Booking_Service::slot_is_free( $agent, strtotime( $b->start_utc . ' UTC' ) ) === true );
HZB_Bookings::update( $b->id, array( 'hold_until' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );

echo "CONFIRM PAGE (HTTP)\n";
$url = HZB_Booking_Service::confirm_url( $b );
$g   = wp_remote_get( $url, array( 'timeout' => 30 ) );
check( 'GET shows button only', ! is_wp_error( $g ) && strpos( wp_remote_retrieve_body( $g ), 'Confirm my viewing' ) !== false );
check( 'GET did not confirm (scanner-safe)', HZB_Bookings::by_token( $b->token )->status === 'pending' );
$p = wp_remote_post( $url, array( 'timeout' => 60, 'redirection' => 0 ) );
check( 'POST confirms and redirects', ! is_wp_error( $p ) && in_array( wp_remote_retrieve_response_code( $p ), array( 301, 302 ), true ) );
$b = HZB_Bookings::by_token( $b->token );
check( 'now confirmed with event + Meet link', $b->status === 'confirmed' && $b->event_id !== '' && strpos( $b->meet_link, 'meet.google.com' ) !== false );
HZB_Booking_Service::email_agent( $b, 'booked' ); // The HTTP request above ran in the web server, so mail capture only sees direct calls.
check( 'agent booking email built', (bool) array_filter( $mails, function ( $m ) { return stripos( $m['subject'], 'New viewing booked' ) !== false && strpos( $m['message'], 'Test Buyer' ) !== false; } ) );

echo "LIMITS\n";
list( $d2, $t2 ) = $k( $ts[4] ); list( $d3, $t3 ) = $k( $ts[6] ); list( $d4, $t4 ) = $k( $ts[8] );
$h = array();
foreach ( array( $ts[4], $ts[6], $ts[8] ) as $i => $t ) {
	$h[ $i ] = HZB_Booking_Service::hold_viewing( $agent, $t, 'one@limit.test', $listing, false, 'One', '', 'iphash-limit' );
}
check( '2 allowed per email', ! is_wp_error( $h[0] ) && ! is_wp_error( $h[1] ) );
check( '3rd per email refused', is_wp_error( $h[2] ) && $h[2]->get_error_code() === 'hzb_limit' );
$ip = HZB_Booking_Service::hold_viewing( $agent, $ts[8], 'two@limit.test', $listing, false, 'Two', '', 'iphash-limit' );
check( 'per-IP cap applies across emails', is_wp_error( $ip ) && $ip->get_error_code() === 'hzb_limit' );
$opts = $orig_settings ? $orig_settings : array();
$opts['max_per_day'] = 1;
update_option( HZB_Settings::OPTION, $opts );
$dayslots = get_agent_availability( $agent, $d2, $d2 );
check( 'per-day cap hides a full day', is_array( $dayslots ) && count( $dayslots ) === 0 );
if ( $orig_settings ) { update_option( HZB_Settings::OPTION, $orig_settings ); } else { delete_option( HZB_Settings::OPTION ); }
foreach ( $h as $x ) { if ( ! is_wp_error( $x ) ) { HZB_Booking_Service::cancel( $x ); } }
$vis = HZB_Settings::get( 'visible_days' );
delete_transient( 'hzb_slots_' . $agent );
$st = new ReflectionMethod( 'HZB_Chat', 'slot_map' ); $st->setAccessible( true );
$map = $st->invoke( null, $agent );
check( 'chat only offers the visible window', is_array( $map ) && max( $map ) <= time() + ( $vis + 1 ) * DAY_IN_SECONDS );

echo "BOT CHECKS\n";
$r = say( '', 'hello', null, array( 'website' => 'http://spam.test' ) );
check( 'honeypot filled is rejected', stripos( $r['reply'], 'could not process' ) !== false );
$r = say( '', 'hello', ai( 'chat' ), array( 'ms' => 100 ) );
check( 'inhumanly fast first message rejected', stripos( $r['reply'], 'could not process' ) !== false );
$r = say( '', 'hello', ai( 'chat' ), array( 'ms' => 4000 ) );
check( 'normal timing accepted', stripos( $r['reply'], 'could not process' ) === false );

echo "GUARDS\n";
$r2 = say( '', '', null ); $sid2 = $r2['session'];
say( $sid2, 'yes', ai( 'confirm', array( 'viewing_type' => 'virtual' ) ) );
check( 'confirm with nothing pending holds nothing', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . HZB_Bookings::table() . " WHERE status='pending' AND ip_hash='" . HZB_Chat::ip_hash() . "'" ) === 0 );
$r2 = say( $sid2, 'book 2030-01-01 03:00', ai( 'propose_slot', array( 'slot_date' => '2030-01-01', 'slot_time' => '03:00' ) ) );
check( 'invented slot rejected', stripos( $r2['reply'], 'not available' ) !== false );
say( $sid2, 'hi', ai( 'chat', array( 'email' => 'attacker@evil.test' ) ) );
$state = get_transient( 'hzb_s_' . $sid2 );
check( 'hallucinated email ignored', $state && $state['email'] === '' );
$r2 = say( $sid2, 'hi', 'DOWN' );
check( 'Groq outage gives fallback text', stripos( $r2['reply'], 'trouble' ) !== false );

echo "RESCHEDULE (via manage link token)\n";
delete_transient( 'hzb_slots_' . $agent );
$m = say( '', '', null, array( 'booking_token' => $b->token ) ); $sidm = $m['session'];
check( 'manage greeting mentions the viewing', stripos( $m['reply'], 'reschedule or cancel' ) !== false );
list( $d5, $t5 ) = $k( $ts[10] );
say( $sidm, 'move to ' . $d5 . ' ' . $t5, ai( 'propose_slot', array( 'slot_date' => $d5, 'slot_time' => $t5 ) ), array( 'booking_token' => $b->token ) );
$r = say( $sidm, 'yes', ai( 'confirm' ), array( 'booking_token' => $b->token ) );
check( 'rescheduled', stripos( $r['reply'], 'Done' ) !== false );
check( 'row moved', HZB_Bookings::by_token( $b->token )->start_utc !== $b->start_utc );

echo "CANCEL\n";
$r = say( $sidm, 'cancel it', ai( 'cancel_booking' ), array( 'booking_token' => $b->token ) );
check( 'asks to confirm cancel', stripos( $r['reply'], 'Are you sure' ) !== false );
$r = say( $sidm, 'yes', ai( 'confirm' ), array( 'booking_token' => $b->token ) );
check( 'cancelled', stripos( $r['reply'], 'cancelled' ) !== false );
check( 'row cancelled', HZB_Bookings::by_token( $b->token )->status === 'cancelled' );
$ev = HZB_Calendar::request( $agent, 'GET', '/calendars/primary/events/' . rawurlencode( $b->event_id ) );
check( 'Google event gone/cancelled', is_wp_error( $ev ) || ( $ev['status'] ?? '' ) === 'cancelled' );
check( 'agent told about cancellation', (bool) array_filter( $mails, function ( $m ) { return stripos( $m['subject'], 'Viewing cancelled' ) !== false; } ) );

delete_post_meta( $listing, 'fave_agents' );
foreach ( $orig_agents as $o ) { add_post_meta( $listing, 'fave_agents', $o ); }
reset_state( $agent );
echo $fail ? "\n$fail FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
