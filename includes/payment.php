<?php
/**
 * The admin-facing functionality of the plugin.
 *
 * @package    mBills Payment Gateway for WooCommerce
 * @subpackage Includes
 * @author     mBills
 * @license    http://www.gnu.org/licenses/ GNU General Public License
 */

// add Gateway to woocommerce
add_filter( 'woocommerce_payment_gateways', 'mbillswc_woocommerce_payment_add_gateway_class' );

function mbillswc_woocommerce_payment_add_gateway_class( $gateways ) {
	$gateways[] = 'WC_mBills_Payment_Gateway'; // class name
	
	return $gateways;
}

add_action( 'plugins_loaded', 'mbillswc_payment_gateway_init' );

function mbillswc_payment_gateway_init() {


 add_action( 'rest_api_init', 'wc_rest_payment_endpoints' );


function wc_rest_payment_endpoints() {
    /**
     * Handle Webhook from mBills
     */
    register_rest_route( 'mbills/v1', 'webhook', array(
        'methods'  => 'POST',
        'callback' => 'webhook',
        'permission_callback' => '__return_true',
    ) );
}

// Changes status of order if user dont go back to webshop for order completion
function webhook($request){

	$queryParams = $request->get_json_params();

	// Get needed data from webhook
	$transaction_id = $queryParams['transactionid'];
	$order_id = $queryParams['orderid'];
	$status = $queryParams['status'];
	$auth = $queryParams['auth'];
	$signature = $auth['signature'];
	$nonce = $auth['nonce'];
	$timestamp = $auth['timestamp'];

	// Get me order
	$order = wc_get_order( $order_id );
			
	// Get me api key
	$api_key = $order->get_meta( 'merchant_api_key', true );

	// Make raw test data for signature validation
	$msg = $api_key.$nonce.$timestamp.$transaction_id; 
	
	// Get public cert
	$pubkeyid = openssl_pkey_get_public(file_get_contents(apply_filters( 'mbillswc_pub', MBILLS_WC_PLUGIN_DIR . 'includes/pub/apicert.pem' )));	 

	// Validate signature in request
	$result = openssl_verify($msg, hex2bin($signature), $pubkeyid, OPENSSL_ALGO_SHA256);

	if ($result == 1)
	{
		if($order->has_status('pending')) {

			$payment_status = $order->get_meta( 'order_payment_status_complete', true );
				
			if ( is_a( $order, 'WC_Order' ) ) {
	            // set mbills id as trnsaction id
				update_post_meta( $order->get_id(), '_transaction_id', $transaction_id );
			}

			// 3 == paid
			if ($status == 3){
				// update post meta
		        update_post_meta( $order->get_id(), '_mbillswc_order_paid', 'yes' );

		        $order->payment_complete();

		        $order->update_status($payment_status);

	        // Not paid
	        }else {
	            $order->update_status('failed', __('Payment canceled', 'mbills-payment-for-woocommerce'));
	        }
        }
    }
	else if ($result == 0)
	{
		$resultT = "Unverified";
	 	error_log($result);
	}
	else
	{
		$resultT = "Unknown verification response";
		error_log($result);
	}
	return $result;
}

// If the WooCommerce payment gateway class is not available nothing will return
if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

class WC_mBills_Payment_Gateway extends WC_Payment_Gateway {

		// Construct this
		public function __construct() {
	  
			$this->id                 = 'mbills-wc';
			$this->icon               = apply_filters( 'mbillswc_custom_gateway_icon', MBILLS_WC_PLUGIN_DIR . 'includes/icon/logo1.jpg' );
			$this->has_fields         = true;
			$this->method_title       = __( 'mBills Payment Gateway for WooCommerce', 'mbills-payment-for-woocommerce' );
			$this->method_description = __( 'Omogočite strankam, da lahko spletno naročilo plačajo z mBills mobilno denarnico. Vsa polja so obvezna.', 'mbills-payment-for-woocommerce' );
			$this->order_button_text  = __( 'mBills plačilo', 'mbills-payment-for-woocommerce' );

			// Method with all the options fields
            $this->init_form_fields();
         
            // Load the settings.
            $this->init_settings();

            $this->validate_fields();
		  
			// Define user set variables
			$this->title                = $this->get_option( 'title' );
			$this->description          = $this->get_option( 'description' );
			$this->payment_status       = $this->get_option( 'payment_status', 'on-hold' );
			$this->app_theme 		    = $this->get_option( 'theme', 'light' );
			$this->default_status       = apply_filters( 'mbillswc_process_payment_order_status', 'pending' );
			$this->api_key				= $this->get_option( 'api_key' );
			$this->secret_key			= $this->get_option( 'secret_key' );

			$this->supports = array(
 			 'products',
  				'refunds'
				);

			
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			// We need custom JavaScript to obtain the transaction number
	        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );

			// thank you page output
			add_action( 'woocommerce_receipt_'.$this->id, array( $this, 'generate_qr_code' ), 4, 1 );

			// verify payment from redirection
            add_action( 'woocommerce_api_mbillswc-payment', array( $this, 'capture_payment' ) );

			// check payment from redirection
            add_action( 'woocommerce_api_mbillswc-check', array( $this, 'check_payment' ) );

			// add support for payment for on hold orders
			add_action( 'woocommerce_valid_order_statuses_for_payment', array( $this, 'on_hold_payment' ), 10, 2 );
			
			// Add support for periodic staus check
			add_action( 'woocommerce_api_check_payment_status', array( $this, 'check_payment_status' ) );
			
			// Add support for periodic staus check
			add_action( 'woocommerce_api_eeeeeee', array( $this, 'eeeeeee' ) );			

		}

         function makeAuthAndCallStatus($transaction_id_m) {

				// Url
				$url_status = MBILLS_API_BASE_URL."/transaction/".$transaction_id_m."/status";
					
				// Make auth data
				$time2 = time();
				$nonce = rand(10000000, 1999999999);
				$username = $this->api_key . '.' . $nonce . '.' . $time2;
				$passwordBeforeSHA = $username.$this->secret_key.$url_status;
				$passwordAfterSHA = hash('sha256', $passwordBeforeSHA);
				$authString = $username . ':' . $passwordAfterSHA;
				$encoded = base64_encode($authString);

                // HTTP API Call
				$response = wp_remote_get($url_status, array(
				    'headers' => array(
				        'Authorization' => 'Basic ' . $encoded,
				        'Content-Type: application/json'
				    ),
				));

				// Get mbills payment token from json response
                $data = json_decode( $response['body'], true );
             
				// Set variable
				$tx_status = $data['status'];
	        
				return $tx_status;
    	}


		// Periodic status check
		function check_payment_status() {

				// mBills tx id
				$transaction_id_m = intval( $_POST['transaction_id_m'] );

				$tx_status = $this->makeAuthAndCallStatus($transaction_id_m);

				// Return status value
    		die(''.$tx_status);
		}

		// Only EUR is supported
	    public function is_valid_for_use() {
			if ( in_array( get_woocommerce_currency(), apply_filters( 'mbillswc_supported_currency', array( 'EUR' ) ) ) ) {
				return true;
			}
	    	return false;
        }

         public function is_valid_for_use_api_key() {
          
			$intLen = strlen($this->api_key);
			
			if ( $intLen == 36) {
				return true;
			}
	    	return false;
        }

           public function is_valid_for_use_secret_key() {
          
			$intLen = strlen($this->secret_key);
			
			if ( $intLen == 36) {
				return true;
			}
	    	return false;
        }


 		public function is_valid_for_use_title() {
			
			if (empty($this->title)) {
    			return false;
			}
	    	return true;
        }

        public function is_valid_for_use_description() {
			
			if (empty($this->description)) {
    			return false;
			}
	    	return true;
        }

           public function is_valid_for_use_decimal_places() {
           	$int = wc_get_price_decimals();
			if ( $int == 2) {
				return true;
			}
	    	return false;
        }
     
     	// Check for correct values in plugin settings
	    public function admin_options() {
	    	if ( !$this->is_valid_for_use() || !$this->is_valid_for_use_decimal_places()) {

	    			?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'This plugin does not support your store currency or not supported decimal places for amounts. mBills Payment Gateway only supports EUR Currency and 2 decimal places for amount. FIX this or DEACTIVATE plugin!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php

	    	} else if (!$this->is_valid_for_use_api_key() && !$this->is_valid_for_use_secret_key()){
				?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT CREDENTIALS !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'API KEY and SECRET KEY not correct!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p> 
	    		</div>
	    		<?php
					parent::admin_options();

			}else if (!$this->is_valid_for_use_api_key()){
				?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT CREDENTIALS !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'API KEY is not correct!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
					parent::admin_options();

			}
			else if (!$this->is_valid_for_use_secret_key()){
				?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT CREDENTIALS !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'SECRET KEY is not correct!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
					parent::admin_options();

			}
			else if (!$this->is_valid_for_use_title()){
				?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'Ime plačilne metode cannot be empty!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
					parent::admin_options();

			}else if (!$this->is_valid_for_use_description()){
				?>
	    		<div class="inline error">
	    			<p>
	    				<strong><?php esc_html_e( '!!! ALERT !!!', 'mbills-payment-for-woocommerce' ); ?></strong>: <?php _e( 'Opis plačilne metode cannot be empty!', 'mbills-payment-for-woocommerce' ); ?>
	    			</p>
	    		</div>
	    		<?php
					parent::admin_options();

			}else {
	    		parent::admin_options();
       	    
       	    }
        }
	
		// Initialize gateway settings form fields
		public function init_form_fields() {

			$this->form_fields = array(
				'enabled' => array(
					'title'       => __( 'Omogoči/Onemogoči:', 'mbills-payment-for-woocommerce' ),
					'type'        => 'checkbox',
					'label'       => __( 'Omogoči mBills način plačila', 'mbills-payment-for-woocommerce' ),
					'description' => __( 'Omogočite če želite sprejemati plačila tudi preko mBills.', 'mbills-payment-for-woocommerce' ),
					'default'     => 'yes',
					'desc_tip'    => true,
				),
				'title' => array(
					'title'       => __( 'Ime plačilne metode:', 'mbills-payment-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Ime plačilne metode je prikazano v naboru vseh možnih načinov plačila pred postopkom plačila.', 'mbills-payment-for-woocommerce' ),
					'default'     => __( 'mBills', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
					'required'    => true,
				),
				'description' => array(
					'title'       => __( 'Opis plačilne metode:', 'mbills-payment-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Opis plačilne metode se prikaže, ko uporabnik iz nabora plačilnih možnosti izbere mBills', 'mbills-payment-for-woocommerce' ),
					'default'     => __( 'Plačajte z mobilno denarnico mBills', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
					'required'    => true,
				),  
				'payment_status' => array(
                    'title'       => __( 'Status naročila po uspešnen plačilu:', 'mbills-payment-for-woocommerce' ),
                    'type'        => 'select',
					'description' =>  __( 'Določite status v katerem bo prejeto naročilo, ki je uspešno plačano z mBills', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
                    'default'     => 'on-hold',
                    'options'     => apply_filters( 'mbillswc_settings_order_statuses', array(
						'pending'      => __( 'Pending Payment', 'mbills-payment-for-woocommerce' ),
						'on-hold'      => __( 'On Hold', 'mbills-payment-for-woocommerce' ),
						'processing'   => __( 'Processing', 'mbills-payment-for-woocommerce' ),
						'completed'    => __( 'Completed', 'mbills-payment-for-woocommerce' )
                    ) )
                ),
				'theme' => array(
                    'title'       => __( 'Barva pojavnega okna z mBills QR kodo za plačilo:', 'mbills-payment-for-woocommerce' ),
                    'type'        => 'select',
					'description' =>  __( 'Izberite ton ozadja prikazanega pojavnega okna za prikaz mBills QR kode za plačilo', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
                    'default'     => 'light',
                    'options'     => apply_filters( 'mbillswc_popup_themes', array(
						'light'     => __( 'Svetlo', 'mbills-payment-for-woocommerce' ),
						'dark'      => __( 'Temno', 'mbills-payment-for-woocommerce' )
                    ) )
                ),
                'api_key' => array(
					'title'       => __( 'API KEY:', 'mbills-payment-for-woocommerce' ),
					'type'        => 'text',
					'description' => __( 'Vnesite vrednost API KEY, ki ste ga prejeli ob podpisu pogodbe.', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
					'required'    => true,
				),
				'secret_key' => array(
					'title'       => __( 'SECRET KEY:', 'mbills-payment-for-woocommerce' ),
					'type'        => 'password',
					'description' => __( 'Vnesite vrednost SECRET KEY, ki ste ga prejeli ob podpisu pogodbe.', 'mbills-payment-for-woocommerce' ),
					'desc_tip'    => true,
					'required'    => true,
				)
			);
		}

		public function payment_fields() {

	        if ( $this->description ) {
	        	echo wpautop( wp_kses_post( $this->description ) );
			}
		}

		public function validate_fields() {

			if (!$this->is_valid_for_use_decimal_places()){
				$this->enabled = 'no'; 
			}


			if (!$this->is_valid_for_use()){
				$this->enabled = 'no'; 
			}
		}

		
		// Custom CSS and JS
		public function payment_scripts() {
			// if our payment gateway is disabled, we do not have to enqueue JS too
	        if( 'no' === $this->enabled ) {
	        	return;
			}
		
			$ver = MBILLS_WC_PLUGIN_VERSION;
            if( defined( 'MBILLS_WC_PLUGIN_ENABLE_DEBUG' ) ) {
                $ver = time();
			}
			
			if ( is_checkout() ) {
			    wp_enqueue_style( 'mbillswc-selectize', plugins_url( 'css/selectize.min.css' , __FILE__ ), array(), '0.12.6' );
				wp_enqueue_script( 'mbillswc-selectize', plugins_url( 'js/selectize.min.js' , __FILE__ ), array( 'jquery' ), '0.12.6', false );
			}
		
			wp_register_style( 'mbillswc-jquery-confirm', plugins_url( 'css/jquery-confirm.min.css' , __FILE__ ), array(), '3.3.4' );
			wp_register_style( 'mbillswc-qr-code', plugins_url( 'css/mbills.min.css' , __FILE__ ), array( 'mbillswc-jquery-confirm' ), $ver );
			
			wp_register_script( 'mbillswc-qr-code', plugins_url( 'js/easy.qrcode.min.js' , __FILE__ ), array( 'jquery' ), '3.6.0', true );
			wp_register_script( 'mbillswc-jquery-confirm', plugins_url( 'js/jquery-confirm.min.js' , __FILE__ ), array( 'jquery' ), '3.3.4', true );
		    wp_register_script( 'mbillswc', plugins_url( 'js/mbills.min.js' , __FILE__ ), array( 'jquery', 'mbillswc-qr-code', 'mbillswc-jquery-confirm' ), $ver, true );
		}

		
		// Process the payment and return the result
		public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );

			// Mark as pending (we're awaiting the payment)
			$order->update_status( $this->default_status );

			// update meta
			update_post_meta( $order->get_id(), '_mbillswc_order_paid', 'no' );

			do_action( 'mbillswc_after_payment_init', $order_id, $order );

			// Return redirect
			return array(
				'result' 	=> 'success',
				'redirect'	=> apply_filters( 'mbillswc_process_payment_redirect', $order->get_checkout_payment_url( true ), $order, 22222 )
			);
		}
		
		
		public function generate_qr_code( $order_id ) {
			try {

				// Get order
				$order = wc_get_order( $order_id );
	            $total = apply_filters( 'mbillswc_order_total_amount', $order->get_total(), $order );

	            if (!$this->is_valid_for_use_api_key()  || !$this->is_valid_for_use_secret_key()){
	            			$order->update_status('failed', __('Payment canceled', 'mbills-payment-for-woocommerce'));

				            // create redirect
							wp_safe_redirect(apply_filters( 'woocommerce_add_to_cart_redirect', wc_get_cart_url(), $this->get_return_url( $order ), $order ) );

							wc_add_notice(  'Plačilna metoda mBills ni pravilno konfigurirana! Kontaktirajte skrbnika spletne strani.', 'notice' );
							exit;
	            }

	            // Prepare amount for mBills service 
	            $total_without_dot = str_replace('.', '', $total);
	            $total_without_dot = (int)$total_without_dot;

	            // Url
	            $url_sale = MBILLS_API_BASE_URL."/transaction/sale";

				// Prepare request
				$data = array("amount"=> $total_without_dot,"currency"=>"EUR", "purpose"=>"Spletno plačilo. Št. naročila: ".$order_id, "paymentreference"=>$order_id, "orderid"=>$order_id, "channelid"=>"WooCommerce plugin", "webhookurl"=>get_home_url()."/wp-json/mbills/v1/webhook");
				$postdata = json_encode($data);

				// Make auth data
				$time2 = time();
				$nonce = rand(10000000, 1999999999);
				$username = $this->api_key . '.' . $nonce . '.' . $time2;
				$passwordBeforeSHA = $username.$this->secret_key.$url_sale;
				$passwordAfterSHA = hash('sha256', $passwordBeforeSHA);
				$authString = $username . ':' . $passwordAfterSHA;
				$encoded = base64_encode($authString);
              
                $headers = array(
                     'Authorization' => 'Basic ' . $encoded,
                     'Content-Type' => 'application/json'
                );
                
                $fields = array(
                    'body' => $postdata,
                    'headers'     => $headers,
                    'method'      => 'POST',
                    'data_format' => 'body'
                );
                
                $response = wp_remote_post($url_sale, $fields);
                $httpcode = wp_remote_retrieve_response_code($response);

				// If credentials (API KEY and SECRET KEY) are not correct mBills service returns 401
				if ($httpcode == 401){

							$order->update_status('failed', __('Payment canceled', 'mbills-payment-for-woocommerce'));

				            // create redirect
							wp_safe_redirect(apply_filters( 'woocommerce_add_to_cart_redirect', wc_get_cart_url(), $this->get_return_url( $order ), $order ) );

							wc_add_notice(  'Plačilna metoda mBills ni pravilno konfigurirana! Kontaktirajte skrbnika spletne strani.', 'notice' );
							exit;
				}

				// Get mbills payment token from json response
                $data = json_decode(wp_remote_retrieve_body( $response ), TRUE );

				// Set variable
				$mbills_code = $data['paymenttokennumber'];
				$transaction_id_m = $data['transactionid'];

				update_post_meta( $order->get_id(), 'order_payment_status_complete', $this->payment_status );
				update_post_meta( $order->get_id(), 'merchant_api_key', $this->api_key );

				// enqueue required css & js files
				wp_enqueue_style( 'mbillswc-jquery-confirm' );
				wp_enqueue_style( 'mbillswc-qr-code' );
				wp_enqueue_script( 'mbillswc-jquery-confirm' );
			    wp_enqueue_script( 'mbillswc-qr-code' );
				wp_enqueue_script( 'mbillswc' );
				
				// add localize scripts
				wp_localize_script( 'mbillswc', 'mbillswc_params',
	                array( 
	                    'ajaxurl'           => admin_url( 'admin-ajax.php' ),
						'orderid'           => $order_id,
						'order_key'         => $order->get_order_key(),
						'processing_text'   => apply_filters( 'mbillswc_payment_processing_text', __( 'Please wait while we are processing your request...', 'mbills-payment-for-woocommerce' ) ),
						'callback_url'      => add_query_arg( array( 'wc-api' => 'mbillswc-payment' ), trailingslashit( get_home_url() ) ),
						'payment_url'       => $order->get_checkout_payment_url(),
						'cancel_url'        => apply_filters( 'mbillswc_payment_cancel_url', wc_get_checkout_url(), $this->get_return_url( $order ), $order ),
						'payment_status'    => $this->payment_status,
						'app_theme'         => $this->app_theme,
						'prevent_reload'    => apply_filters( 'mbillswc_enable_payment_reload', true ),
						'intent_interval'   => apply_filters( 'mbillswc_auto_open_interval', 1000 ),
						'app_version'       => MBILLS_WC_PLUGIN_VERSION,
						'total' 			=> $total,
						'mbills_code'		=> $mbills_code,
						'response'			=> $response,
						'transaction_id_m'	=> $transaction_id_m,
						'payment_text'		=> wp_is_mobile() ? '<b>1. )</b> Pritisni <b>"Plačaj z mBills"</b>. </br> <b>2. )</b> V mBills <b>potrdi plačilo</b>. </br> <b>3. )</b> Nato se za zaključek postopka, <b>vrni na to spletno stran</b>.' : '<b>1. )</b> Skeniraj QR kodo z <b>mBills</b>.  </br> <b>2. )</b> V mBills <b>potrdi plačilo</b>.',
						'is_mobile'			=> wp_is_mobile() ? true : false,
						'mbills_icon'		=> $this->icon,
						'encodedAuthData'   => $encoded,
						'callback_check_payment_status'  => add_query_arg( array( 'wc-api' => 'check_payment_status' ), trailingslashit( get_home_url() ) ),
						'mbills_deeplink_pre'   => MBILLS_DEEPLINK_PRE,
						'mbills_deeplink_aft'   => MBILLS_DEEPLINK_AFT,
						'mbills_qr_service'   => MBILLS_QR_SERVICE

	                )
				);

				// add html output on payment endpoint
				if( 'yes' === $this->enabled && $order->needs_payment() === true && $order->has_status( $this->default_status ) ) { ?>
				    <section class="woo-upi-section">
					    <div class="mbillswc-info">
					        <h6 class="mbillswc-waiting-text"><?php _e( 'Please wait and don\'t press back or refresh this page while we are processing your payment.', 'mbills-payment-for-woocommerce' ); ?></h6>
	                        <button id="mbillswc-processing" class="btn button" disabled="disabled"><?php _e( 'Waiting for payment...', 'mbills-payment-for-woocommerce' ); ?></button>
							<?php do_action( 'mbillswc_after_before_title', $order ); ?>

							<div class="mbillswc-buttons" style="display: none;">
							    <button id="mbillswc-confirm-payment" class="btn button" data-theme="<?php echo apply_filters( 'mbillswc_payment_dialog_theme', 'blue' ); ?>"></button>
				    	       
							</div>
							
							<?php do_action( 'mbillswc_after_payment_buttons', $order ); ?>
					        <div id="js_qrcode">
						
						    	<div id="mbillswc-qrcode" <?php echo esc_js(isset( $style ) ? $style : '') ?><?php do_action( 'mbillswc_after_qr_code', $order ); ?></div>
					
			
						    
						    </div>
							<div id="payment-success-container" style="display: none;"></div>
						</div>
					</section><?php
				}
			} catch (Exception $e) {
			    
					$order->update_status('failed', __('Payment canceled', 'mbills-payment-for-woocommerce'));

			        // create redirect
					wp_safe_redirect(apply_filters( 'woocommerce_add_to_cart_redirect', wc_get_cart_url(), $this->get_return_url( $order ), $order ) );

					wc_add_notice(  'Prišlo je do sistemske napake pri plačilu z mBills. Poiskusite kasneje.', 'notice' );

					exit;
			}

		}

		/**
	     * Process payment verification.
	     */
        public function capture_payment() {
            // get order id
            if ( ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) || ! isset( $_GET['wc-api'] ) || ( 'mbillswc-payment' !== $_GET['wc-api'] ) ) {
                return;
            }

            // generate order
			$order_id = wc_get_order_id_by_order_key( sanitize_text_field( $_POST['wc_order_key'] ) );
			$order = wc_get_order( $order_id );
            
			$mbills_tx_id = sanitize_text_field($_POST['wc_mbills_tx_id']);

            // check if it an order
            if ( is_a( $order, 'WC_Order' ) ) {
			
				// set mbills id as trnsaction id
				if ( isset( $_POST['wc_mbills_tx_id'] ) && !empty( $_POST['wc_mbills_tx_id'] ) ) {
					update_post_meta( $order->get_id(), '_transaction_id', sanitize_text_field( $_POST['wc_mbills_tx_id'] ) );
				}

                $tx_status = $this->makeAuthAndCallStatus($mbills_tx_id);

				// 3 == paid
				if ($tx_status == 3){

					// check order if it actually needs payment
					if( in_array( $this->payment_status, apply_filters( 'mbillswc_valid_order_status_for_note', array( 'pending', 'on-hold' ) ) ) ) {
			            // set order note
			            $order->add_order_note( __( 'Payment primarily completed. Needs shop owner\'s verification.', 'mbills-payment-for-woocommerce' ), false );
					}
					// update post meta
					update_post_meta( $order->get_id(), '_mbillswc_order_paid', 'yes' );

					$order->payment_complete();

					$order->update_status( apply_filters( 'mbillswc_capture_payment_order_status', $this->payment_status ) );

					wp_safe_redirect( apply_filters( 'mbillswc_payment_redirect_url', $this->get_return_url( $order ), $order ) );

	                exit;
 
				}
				// not paid
				else{
						// Lets void this tx in mBills system
						$url_void = MBILLS_API_BASE_URL."/transaction/".$mbills_tx_id."/void";
						
						// Make auth data
						$time2i = time();
						$noncei = rand(10000000, 1999999999);
						$usernamei = $this->api_key . '.' . $noncei . '.' . $time2i;
						$passwordBeforeSHAi = $usernamei.$this->secret_key.$url_void;
						$passwordAfterSHAi = hash('sha256', $passwordBeforeSHAi);
						$authStringi = $usernamei . ':' . $passwordAfterSHAi;
						$encodedi = base64_encode($authStringi);
                    
                        $headers = array(
                           'Authorization' => 'Basic ' . $encodedi,
                           'Content-Type' => 'application/json'
                        );
                        
                        $fields = array(
                            'headers'     => $headers,
                            'method'      => 'PUT',
                        );
                    
                        $response11 = wp_remote_request($url_void, $fields);
                     
						$order->update_status('failed', __('Payment canceled', 'mbills-payment-for-woocommerce'));

			            // create redirect
						wp_safe_redirect(apply_filters( 'woocommerce_add_to_cart_redirect', wc_get_cart_url(), $this->get_return_url( $order ), $order ) );

						wc_add_notice(  'Vaše naročilo je bilo preklicano ali ni bilo plačano.', 'notice' );

						exit;
					}
            } else {
				// create redirect
                $title = __( 'Order can\'t be found against this Order ID. If the money debited from your account, please Contact with Site Administrator for further action.', 'mbills-payment-for-woocommerce' );
                        
                wp_die( $title, get_bloginfo( 'name' ) );
                exit;
			}
        }

		public function on_hold_payment( $statuses, $order ) {
			if ( $this->id === $order->get_payment_method() && $order->has_status( 'on-hold' ) && $order->get_meta( '_mbillswc_order_paid', true ) !== 'yes' && $this->default_status === 'on-hold' ) {
				$statuses[] = 'on-hold';
			}
			return $statuses;
		}

		public function process_refund( $order_id, $amount = null, $reason = '' ) {
		 	 // Do your refund here. Refund $amount for the order with ID $order_id

			try {

	 			$order = wc_get_order( $order_id );
				$tx_id = $order->get_transaction_id();

	    		// prepare amount
	            $amount_without_dot = str_replace('.', '', $amount);
	            $amount_without_dot = (int)$amount_without_dot;

				//	Url
	            $url_refund = MBILLS_API_BASE_URL."/transaction/".$tx_id."/refund";

				// Prepare request
				$data = array("amount"=> $amount_without_dot,"currency"=>"EUR");
				$postdata = json_encode($data);

				// Make auth data
				$time2 = time();
				$nonce = rand(10000000, 1999999999);
				$username = $this->api_key . '.' . $nonce . '.' . $time2;
				$passwordBeforeSHA = $username.$this->secret_key.$url_refund;
				$passwordAfterSHA = hash('sha256', $passwordBeforeSHA);
				$authString = $username . ':' . $passwordAfterSHA;
				$encoded = base64_encode($authString);
                
                $headers = array(
                    'Authorization' => 'Basic ' . $encoded,
                    'Content-Type' => 'application/json'
                );
                
                $fields = array(
                    'body' => $postdata,
                    'headers'     => $headers,
                    'method'      => 'POST',
                    'data_format' => 'body'
                );
                
                $response = wp_remote_post($url_refund, $fields);
                
                $httpcode = wp_remote_retrieve_response_code($response);
                
                $body = wp_remote_retrieve_body($response);
                
				// 400 Status Bad Request - Return error msg
				if ($httpcode == 400){
					$error_data = json_decode($body, true);

					$error_code = $error_data['code'];
					$error_status = $error_data['status'];
					
					if ($error_code == "A400" && $error_status ==-5){

						$error = new WP_Error( 'custom-error', "Trenutno je preko mBills dovoljeno samo vračilo celotnega zneska naročila!");
						return $error;
					}
					else if ($error_code == "A462" && $error_status ==-5){

						$error = new WP_Error( 'custom-error', "Vračilo preko mBills je bilo za to spletno naročilo že narejeno!");
						return $error;
					}

				}

				// 403 Status Bad Request - Return error msg
				if ($httpcode == 403){
					$error_data = json_decode($body, true);

					$error_code = $error_data['code'];
					$error_status = $error_data['status'];
					
					if ($error_code == "A201" && $error_status ==-3){

						$error = new WP_Error( 'custom-error', "Možnost ni na voljo saj na zbirnem računu podjetja ni dovolj sredstev za izplačilo vračila! ");
						return $error;
					}
				}

				// 404 Not found - Return error msg
				if ($httpcode == 404){
					$error_data = json_decode($body, true);

					$error_code = $error_data['code'];
					$error_status = $error_data['status'];
					
					if ($error_code == "A007a" && $error_status ==-5){

						$error = new WP_Error( 'custom-error', "Vračilo ni možno, ker transakcija v sistemu mBills ne obstaja!");
						return $error;
					}
				}

				// Status Ok - All ok
				if ($httpcode == 200){

					// Get mbills payment token from json response
					$data = json_decode($body, true);

					// Set variable
					$mbills_status = $data['status'];

					if ($mbills_status == 3){
						return true;
					}
				}

				// Status Error - Return error msg
				if ($httpcode == 500){
	 				$error = new WP_Error( 'custom-error', "Ups. Prišlo je do sistemske napake. Poskusite kasneje.");
					return $error;
					
				}

				return false;

			} catch (Exception $e) {
			    $error = new WP_Error( 'custom-error', "Ups. Prišlo je do sistemske napake. Poskusite kasneje.");
				return $error;
			}
		}
    }
}
