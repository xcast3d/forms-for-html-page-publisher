<?php
/**
 * Plugin Name:       Forms Converter Add-on For HTML Page Publisher
 * Plugin URI:        https://github.com/xcast3d/forms-for-html-page-publisher
 * Description:       Add-on for HTML Page Publisher: turns the static forms in your landing pages into working Gravity Forms, Contact Form 7 or WPForms forms.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Requires Plugins:  html-page-publisher
 * Author:            Xavier Castaneda
 * Author URI:        https://xcast3d.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forms-converter-add-on-for-html-page-publisher
 *
 * @package FFHPP
 */

defined( 'ABSPATH' ) || exit;

define( 'FFHPP_VERSION', '1.0.0' );
define( 'FFHPP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FFHPP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FFHPP_MIN_HTMLPP', '1.5.2' );

require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-adapter.php';
require_once FFHPP_PLUGIN_DIR . 'includes/adapters/class-ffhpp-adapter-gravity.php';
require_once FFHPP_PLUGIN_DIR . 'includes/adapters/class-ffhpp-adapter-cf7.php';
require_once FFHPP_PLUGIN_DIR . 'includes/adapters/class-ffhpp-adapter-wpforms.php';
require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-parser.php';
require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-converter.php';
require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-admin.php';
require_once FFHPP_PLUGIN_DIR . 'includes/class-ffhpp-plugin.php';

// HTML Page Publisher reads this once while booting (plugins_loaded, 10).
add_filter( 'htmlpp_serve_priority', array( 'FFHPP_Plugin', 'serve_priority' ) );

// After HTML Page Publisher (plugins_loaded, 10) has booted.
add_action( 'plugins_loaded', array( 'FFHPP_Plugin', 'boot' ), 20 );
