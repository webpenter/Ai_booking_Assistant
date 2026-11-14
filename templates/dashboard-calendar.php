<?php
/** Houzez dashboard page: connect Google Calendar and see upcoming viewings. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( home_url() );
	exit;
}
$uid     = get_current_user_id();
$is_admin = user_can( $uid, 'manage_options' );
$own     = HZB_Dashboard::agent_for_user( $uid );
if ( ! $own && ! $is_admin ) {
	wp_safe_redirect( home_url() ); // Buyers and users without an agent profile have nothing to manage.
	exit;
}
$agent = $own;
if ( $is_admin && ! empty( $_GET['agent'] ) && get_post_type( (int) $_GET['agent'] ) === 'houzez_agent' ) {
	$agent = (int) $_GET['agent'];
}
$agents = $is_admin ? get_posts( array( 'post_type' => 'houzez_agent', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
if ( ! $agent && $agents ) {
	$agent = $agents[0]->ID;
}
$msgs = array(
	'connected'    => array( 'success', 'Google Calendar connected. Visitors can now book viewings with you.' ),
	'disconnected' => array( 'info', 'Google Calendar disconnected. The booking assistant is off for your listings until you reconnect.' ),
	'denied'       => array( 'warning', 'Access was declined on the Google screen. Press Connect and allow calendar access to continue.' ),
	'failed'       => array( 'danger', 'Connecting failed. Please try again; if it keeps failing, contact the site admin.' ),
	'notready'     => array( 'warning', 'The site admin has not finished the Google setup yet.' ),
);
$m = isset( $_GET['hzb_msg'] ) ? sanitize_key( $_GET['hzb_msg'] ) : '';

get_header();
?>
<header class="header-main-wrap dashboard-header-main-wrap">
	<div class="dashboard-header-wrap">
		<div class="d-flex align-items-center">
			<div class="dashboard-header-left flex-grow-1"><h1>Viewing Calendar</h1></div>
		</div>
	</div>
</header>

<section class="dashboard-content-wrap">
	<div class="dashboard-content-inner-wrap">
		<?php if ( isset( $msgs[ $m ] ) ) : ?>
			<div class="alert alert-<?php echo esc_attr( $msgs[ $m ][0] ); ?>" role="alert"><?php echo esc_html( $msgs[ $m ][1] ); ?></div>
		<?php endif; ?>

		<?php if ( $is_admin && $agents ) : ?>
			<div class="dashboard-content-block-wrap">
				<form method="get" class="form-inline" action="<?php echo esc_url( HZB_Dashboard::page_url() ); ?>">
					<label for="hzb-agent" class="mr-2"><strong>Agent</strong></label>
					<select id="hzb-agent" name="agent" class="form-control mr-2" onchange="this.form.submit()">
						<?php foreach ( $agents as $a ) : ?>
							<option value="<?php echo (int) $a->ID; ?>" <?php selected( $agent, $a->ID ); ?>><?php echo esc_html( $a->post_title ); ?><?php echo HZB_Google_OAuth::is_connected( $a->ID ) ? ' (connected)' : ''; ?></option>
						<?php endforeach; ?>
					</select>
				</form>
			</div>
		<?php endif; ?>

		<div class="dashboard-content-block-wrap">
			<div class="dashboard-content-block">
				<h2 style="font-size:20px">Google Calendar</h2>
				<?php if ( $agent && HZB_Google_OAuth::is_connected( $agent ) ) :
					$email = get_post_meta( $agent, HZB_Google_OAuth::META_EMAIL, true );
					?>
					<p><span style="color:#2e9e5b">&#9679;</span> <strong>Connected</strong><?php echo $email ? ' as ' . esc_html( $email ) : ''; ?> for <?php echo esc_html( get_the_title( $agent ) ); ?>.</p>
					<p class="text-muted">The booking assistant on your listings reads your free/busy times and creates viewing events on this calendar. We never read event details.</p>
					<a class="btn btn-primary-outlined" href="<?php echo esc_url( HZB_Dashboard::action_url( 'disconnect', $agent ) ); ?>" onclick="return confirm('Disconnect your Google Calendar? The booking assistant will stop offering your times.');">Disconnect</a>
				<?php elseif ( $agent ) : ?>
					<p><span style="color:#c9302c">&#9679;</span> <strong>Not connected</strong></p>
					<p>Connect your Google Calendar so visitors to your listings can book viewings in your real free time. You choose which Google account to use and can disconnect at any time.</p>
					<a class="btn btn-primary" href="<?php echo esc_url( HZB_Dashboard::action_url( 'connect', $agent ) ); ?>">Connect Google Calendar</a>
				<?php else : ?>
					<p>No agent profile is linked to your account yet. Please contact the site admin.</p>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( $agent && HZB_Google_OAuth::is_connected( $agent ) ) :
			$rows = HZB_Dashboard::upcoming( $agent );
			$tz   = HZB_Calendar::timezone( $agent );
			?>
			<div class="dashboard-content-block-wrap">
				<div class="dashboard-content-block">
					<h2 style="font-size:20px">Upcoming viewings</h2>
					<?php if ( ! $rows ) : ?>
						<p class="text-muted">No upcoming viewings yet.</p>
					<?php else : ?>
						<div class="table-responsive"><table class="table">
							<thead><tr><th>When (<?php echo esc_html( $tz->getName() ); ?>)</th><th>Property</th><th>Buyer</th><th>Type</th><th>Status</th></tr></thead>
							<tbody>
							<?php foreach ( $rows as $r ) : ?>
								<tr>
									<td><?php echo esc_html( HZB_Chat::format_ts( strtotime( $r->start_utc . ' UTC' ), $tz ) ); ?></td>
									<td><a href="<?php echo esc_url( get_permalink( $r->listing_id ) ); ?>"><?php echo esc_html( get_the_title( $r->listing_id ) ); ?></a></td>
									<td><?php echo esc_html( $r->buyer_name ); ?><br><small><?php echo esc_html( $r->buyer_email ); ?></small></td>
									<td><?php echo $r->is_virtual ? 'Virtual' : 'In person'; ?><?php if ( $r->meet_link ) : ?><br><a href="<?php echo esc_url( $r->meet_link ); ?>" target="_blank" rel="noopener">Meet link</a><?php endif; ?></td>
									<td><?php echo $r->status === 'pending' ? 'Awaiting buyer email confirmation' : 'Confirmed'; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table></div>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
