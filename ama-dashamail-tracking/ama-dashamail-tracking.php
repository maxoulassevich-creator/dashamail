<?php
/**
 * Plugin Name: Amarèssence — DashaMail Tracking
 * Description: Передача событий WooCommerce и RELOD Auth в DashaMail CDP, включая брошенную корзину.
 * Version: 1.0.0
 * Author: Amarèssence
 * License: GPLv2 or later
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: ama-dashamail
 */

defined( 'ABSPATH' ) || exit;

define( 'AMA_DM_VERSION', '1.0.0' );
define( 'AMA_DM_FILE', __FILE__ );
define( 'AMA_DM_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMA_DM_URL', plugin_dir_url( __FILE__ ) );

require_once AMA_DM_DIR . 'includes/class-ama-dm-settings.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-product-id.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-product-data.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-customer.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-events.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-event-queue.php';
require_once AMA_DM_DIR . 'includes/class-ama-dm-plugin.php';

register_activation_hook( __FILE__, array( 'AMA_DM_Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'AMA_DM_Plugin', 'instance' ), 20 );
