<?php
/**
 * Plugin Name:       HTML Page Publisher – Forms
 * Description:       Embed Gravity Forms, Contact Form 7 or WPForms in pages published with HTML Page Publisher, and convert raw HTML forms into them.
 * Version:           0.5.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Requires Plugins:  html-page-publisher
 * Author:            Xavier Castaneda
 * Author URI:        https://xcast3d.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       htmlpp-forms
 *
 * @package HTMLPP_Forms
 */

defined( 'ABSPATH' ) || exit;

define( 'HTMLPP_FORMS_VERSION', '0.5.0' );
define( 'HTMLPP_FORMS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/class-htmlpp-forms-adapter.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/adapters/class-htmlpp-forms-adapter-gravity.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/adapters/class-htmlpp-forms-adapter-cf7.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/adapters/class-htmlpp-forms-adapter-wpforms.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/class-htmlpp-forms-parser.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/class-htmlpp-forms-converter.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/class-htmlpp-forms-admin.php';
require_once HTMLPP_FORMS_PLUGIN_DIR . 'includes/class-htmlpp-forms.php';

// After HTML Page Publisher (plugins_loaded, 10) has built its renderer.
add_action( 'plugins_loaded', array( 'HTMLPP_Forms', 'boot' ), 20 );
