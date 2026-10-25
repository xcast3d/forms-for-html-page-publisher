<?php
/**
 * Uninstall cleanup for Forms for HTML Page Publisher.
 *
 * @package FFHPP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_transient( 'ffhpp_flagged_count' );
