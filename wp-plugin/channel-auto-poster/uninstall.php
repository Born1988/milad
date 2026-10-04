<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
delete_option( 'cap_settings' );
delete_post_meta_by_key( '_cap_sent' );
delete_post_meta_by_key( '_cap_skip' );
