<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'cap_settings' );
delete_option( 'cap_bot_secret' );
delete_option( 'cap_leads' );
delete_option( 'cap_seeded' );
delete_post_meta_by_key( '_cap_sent' );
delete_post_meta_by_key( '_cap_skip' );
// مشکلات ربات (نوع‌نوشته cap_problem) عمداً حذف نمی‌شوند تا محتوای شما از بین نرود.
