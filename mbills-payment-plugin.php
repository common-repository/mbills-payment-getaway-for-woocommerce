<?php
/**
 * Plugin Name: mBills Payment Gateway for WooCommerce
 * Plugin URI: https://www.mbills.si/
 * Description: Enable costumers to make payment with mBills mobile wallet. How to start: Activate plugin and go to plugin settings where you enter API and SECRET KEY.
 * Version: 1.0.0
 * Author: mBills
 * Author URI: https://www.mbills.si
 * License: GPLv3
 * Text Domain: mbills-payment-plugin
 * Domain Path: /languages
 * Stable tag: 1.0.0
 * Requires at least: 5.1
 * Tested up to: 5.8
 * 
 * mBills Payment Gateway for WooCommerce is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * mBills Payment Gateway for WooCommerce is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with mBills Payment Gateway for WooCommerce plugin. If not, see <http://www.gnu.org/licenses/>.
 * 
 * @category WooCommerce
 * @package  mBills Payment Gateway for WooCommerce
 * @author   mBills <info@mbills.si>
 * @license  http://www.gnu.org/licenses/ GNU General Public License
 *
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$consts = array(
    'MBILLS_WC_PLUGIN_VERSION'       => '1.0.0', // plugin version
    'MBILLS_WC_PLUGIN_BASENAME'      => plugin_basename( __FILE__ ),
	'MBILLS_WC_PLUGIN_DIR'           => plugin_dir_url( __FILE__ ),
    'MBILLS_API_BASE_URL'            => 'https://api.mbills.si/MBillsWS/API/v1', 
    'MBILLS_DEEPLINK_PRE'            => 'https://mbills.si/dl/?type=1&token=',
    'MBILLS_DEEPLINK_AFT'            => '&plugin=mb_woocomerce',
    'MBILLS_QR_SERVICE'              => 'https://qr.mbills.si/qr/png/type1/', 
);

foreach( $consts as $const => $value ) {
    if ( ! defined( $const ) ) {
        define( $const, $value );
    }
}

// Internationalization
add_action( 'plugins_loaded', 'mbillswc_plugin_load_textdomain' );

function mbillswc_plugin_load_textdomain() {
    load_plugin_textdomain( 'mbills-payment-for-woocommerce', false, dirname( MBILLS_WC_PLUGIN_BASENAME ) . '/languages/' ); 
}

// register activation hook
register_activation_hook( __FILE__, 'mbillswc_plugin_activation' );

function mbillswc_plugin_activation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    set_transient( 'mbillswc-admin-notice-on-activation', true, 5 );
}

// register deactivation hook
register_deactivation_hook( __FILE__, 'mbillswc_plugin_deactivation' );

function mbillswc_plugin_deactivation() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    delete_option( 'mbillswc_plugin_dismiss_rating_notice' );
    delete_option( 'mbillswc_plugin_no_thanks_rating_notice' );
    delete_option( 'mbillswc_plugin_installed_time' );
}

// plugin action links
add_filter( 'plugin_action_links_' . MBILLS_WC_PLUGIN_BASENAME, 'mbillswc_add_action_links', 10, 2 );

function mbillswc_add_action_links( $links ) {
    $mbillswclinks = array(
        '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mbills-wc' ) . '">' . __( 'Settings', 'mbills-payment-for-woocommerce' ) . '</a>',
    );
    return array_merge( $mbillswclinks, $links );
}

// plugin row elements
//add_filter( 'plugin_row_meta', 'mbillswc_plugin_meta_links', 10, 2 );

/*function mbillswc_plugin_meta_links( $links, $file ) {
    $plugin = MBILLS_WC_PLUGIN_BASENAME;
    if ( $file == $plugin ) // only for this plugin
        return array_merge( $links, 
           // array( '<a href="https://wordpress.org/support/plugin/mbills-payment-getaway-for-wooCommerce" target="_blank">' . __( 'Support', 'mbills-payment-for-woocommerce' ) . '</a>' ),
        );
    return $links;
}*/

// add admin notices
add_action( 'admin_notices', 'mbillswc_new_plugin_install_notice' );

function mbillswc_new_plugin_install_notice() { 
    // Show a warning to sites running PHP < 5.6
    if( version_compare( PHP_VERSION, '5.6', '<' ) ) {
	    echo '<div class="error"><p>' . __( 'Your version of PHP is below the minimum version of PHP required by mBills Payment Gateway for WooCommerce plugin. Please contact your host and request that your version be upgraded to 5.6 or later.', 'mbills-payment-for-woocommerce' ) . '</p></div>';
    }

    // Check transient, if available display notice
    if( get_transient( 'mbillswc-admin-notice-on-activation' ) ) { ?>
        <div class="notice notice-success">
            <p><strong><?php printf( __( 'Thanks for installing %1$s v%2$s plugin. Click <a href="%3$s">here</a> to configure plugin settings.', 'mbills-payment-for-woocommerce' ), 'mBills Payment Gateway for WooCommerce', MBILLS_WC_PLUGIN_VERSION, admin_url( 'admin.php?page=wc-settings&tab=checkout&section=mbills-wc' ) ); ?></strong></p>
        </div> <?php
        delete_transient( 'mbillswc-admin-notice-on-activation' );
    }
}

require_once plugin_dir_path( __FILE__ ) . 'includes/payment.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/notice.php';

