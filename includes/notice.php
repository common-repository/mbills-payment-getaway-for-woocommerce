<?php

/**
 *
 * @package    mBills Payment Gateway for WooCommerce
 * @subpackage Includes
 * @author     mBills
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

function mbillswc_plugin_get_installed_time() {
    $installed_time = get_option( 'mbillswc_plugin_installed_time' );
    if ( ! $installed_time ) {
        $installed_time = time();
        update_option( 'mbillswc_plugin_installed_time', $installed_time );
    }
    return $installed_time;
}