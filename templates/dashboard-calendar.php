<?php
/** Houzez dashboard page: connect Google Calendar and see upcoming bookings (the panel is shared with the shortcode). */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! is_user_logged_in() || ! HZB_Dashboard::owner_for_user( get_current_user_id(), false, true ) ) {
	wp_safe_redirect( home_url() ); // Buyers and users without a calendar to manage have nothing here.
	exit;
}
wp_enqueue_style( 'hzb-booking', HZB_URL . 'assets/booking.css', array(), HZB_VERSION );
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
		<?php echo HZB_Dashboard::panel( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaped parts. ?>
	</div>
</section>
<?php get_footer(); ?>
