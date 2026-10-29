<?php
/**
 * Uninstall cleanup for Forms Converter Add-On for HTML Page Publisher.
 *
 * @package FFHPP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'ffhpp_flagged_count' );
delete_metadata( 'user', 0, 'ffhpp_notice_dismissed', '', true );
