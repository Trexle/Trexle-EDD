<?php
/*
Plugin Name: EDD Trexle Gateway
Plugin URI: https://trexle.com/edd-payment-gateways
Description: Accept credit card payments in EDD by connecting to +100 payment gateway using Trexle.
Author: DesignWriteBuild and Pippin Williamson and Hossam Hossny
Author URI: https://trexle.com
Version: 1.0.4

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/


define( 'EDD_TREXLE_STORE_API_URL', 'https://trexle.com' );
define( 'EDD_TREXLE_PRODUCT_NAME', 'Trexle Gateway' );

if( class_exists( 'EDD_License' ) ) {
    $edd_fd_license = new EDD_License( __FILE__, EDD_TREXLE_PRODUCT_NAME, '1.0.4', 'Trexle' );
}

function edd_fd_textdomain() {
    // Set filter for plugin's languages directory
    $edd_lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
    $edd_lang_dir = apply_filters( 'edd_fd_languages_directory', $edd_lang_dir );

    // Load the translations
    load_plugin_textdomain( 'edd_trexle', false, $edd_lang_dir );
}
add_action('init', 'edd_fd_textdomain');

function edd_fd_register_gateway( $gateways ) {
    $gateways['trexle'] = array(
        'admin_label' => 'Trexle',
        'checkout_label' => __( 'Trexle', 'edd_trexle' )
    );
    return $gateways;
}
add_filter( 'edd_payment_gateways', 'edd_fd_register_gateway' );

function edd_trexle_add_settings( $settings ) {

    $gateway_settings = array(
        array(
            'id' => 'trexle_settings',
            'name' => '<strong>' . __( 'Trexle Settings', 'edd_trexle' ) . '</strong>',
            'desc' => __( 'Configure Trexle Platform', 'edd_trexle' ),
            'type' => 'header'
        ),
        array(
            'id' => 'trexle_gateway_password',
            'name' => __( 'Gateway Password', 'edd_trexle' ),
            'desc' => __( 'Enter your Trexle API Secret Key', 'edd_trexle' ),
            'type' => 'password',
            'size' => 'regular'
        ),
        array(
            'id' => 'trexle_transaction_type',
            'name' => __( 'Transaction Type', 'edd_trexle' ),
            'desc' => __( 'Choose the location of the currency sign.', 'edd_trexle' ),
            'type' => 'select',
            'options' => array(
                '00'	=> __( 'Purchase', 'edd_trexle' ),
            )
        )
    );

    return array_merge( $settings,  $gateway_settings );
}
add_filter( 'edd_settings_gateways', 'edd_trexle_add_settings' );


function edd_fd_process_payment( $purchase_data ) {
    global $edd_options;

    // setup gateway appropriately for test mode
    if( edd_is_test_mode() ) {
        $endpoint = 'https://core.trexle.com/api/v1/charges';
    } else {
        $endpoint = 'https://core.trexle.com/api/v1/charges';
    }

    // check the posted cc deails
    $cc = edd_fd_check_cc_details( $purchase_data );

    // check for errors before we continue to processing
    if( !edd_get_errors() ) {

        $payment = array(
            'price' 		=> $purchase_data['price'],
            'date' 			=> $purchase_data['date'],
            'user_email' 	=> $purchase_data['user_email'],
            'purchase_key' 	=> $purchase_data['purchase_key'],
            'currency' 		=> edd_get_currency(),
            'downloads' 	=> $purchase_data['downloads'],
            'cart_details' 	=> $purchase_data['cart_details'],
            'user_info' 	=> $purchase_data['user_info'],
            'status' 		=> 'pending'
        );

        // record the pending payment
        $payment = edd_insert_payment( $payment );

        $trexle = [
            'amount' => $purchase_data['price'] * 100,
            'currency' => edd_get_currency(),
            'description' => 'Purchase Key: ' . $purchase_data['purchase_key'],
            'email' => $purchase_data['user_email'],
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'card[number]' => $cc['card_number'],
            'card[expiry_month]' => $cc['card_exp_month'],
            'card[expiry_year]' => $cc['card_exp_year'],
            'card[cvc]' => $cc['card_cvc'],
            'card[name]' => $cc['card_name'],
            'card[address_line1]' => $_POST['card_address'],
            'card[address_line2]' => empty($_POST['card_address_2']) ? '-' : $_POST['card_address_2'],
            'card[address_city]' => empty($_POST['card_city']) ? '-' : $_POST['card_city'],
            'card[address_postcode]' => empty($_POST['card_zip']) ? '000' : $_POST['card_zip'],
            'card[address_state]' => empty($_POST['card_state']) ? '-' : $_POST['card_state'],
            'card[address_country]' => empty($_POST['billing_country']) ? '-' : $_POST['billing_country']
        ];

        $response = null;
        $httpcode = null;

        try {

            $curl = curl_init($endpoint);

            curl_setopt($curl, CURLOPT_USERPWD, $edd_options['trexle_gateway_password'] . ':');
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $trexle);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array('Accept: application/json'));

            $response = curl_exec($curl);
            $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        } catch ( Exception $e ) {
            edd_set_error( 'trexle_api_error' , sprintf( __( 'Trexle System Error: %s', 'edd_trexle' ), $e->getMessage() ) );
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
            $fail = true;
        }

        if ($httpcode == '201') {
            if (isset($response) && !empty($response)) {
                if (isset($response['response']['success']) && $response['response']['success'] == true) {
                    edd_update_payment_status( $payment, 'complete' );
                    edd_send_to_success_page();
                } else if ( isset($response['response']['error']) ) {
                    edd_set_error( 'trexle_decline' , sprintf( __( 'Transaction Declined: %s', 'edd_trexle' ), $response['response']['error'] ) );
                    edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
                    $fail = true;
                }
            } else {
                edd_set_error( 'trexle_decline' , 'Transaction Declined');
                edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
                $fail = true;
            }
        } else {
            edd_set_error( 'trexle_decline' , 'Transaction Declined');
            edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
            $fail = true;
        }
    } else {
        $fail = true;
    }

}
add_action( 'edd_gateway_trexle', 'edd_fd_process_payment' );

function edd_fd_check_cc_details( $purchase_data ) {
    $keys = array(
        'card_number' => __( 'credit card number', 'edd_trexle' ),
        'card_exp_month' => __( 'expiration month', 'edd_trexle' ),
        'card_exp_year' => __( 'expiration year', 'edd_trexle' ),
        'card_name' => __( 'card holder name', 'edd_trexle' ),
        'card_cvc' => __( 'security code', 'edd_trexle' ),
    );

    $cc_details = array();

    foreach( $keys as $key => $desc ) {
        if( !isset( $_POST[ $key ] ) || empty( $_POST[ $key ] ) ) {
            edd_set_error( 'bad_' . $key , sprintf( __('You must enter a valid %s.', 'edd_trexle' ), $desc ) );
        } else {
            $data = esc_textarea( trim( $_POST[ $key ] ) );
            switch( $key ) {
                case 'card_exp_month':
                    $data = str_pad( $data, 2, 0, STR_PAD_LEFT);
                    break;
                case 'card_exp_year':
                    if( strlen( $data ) > 2 )
                        $data = substr( $data, -2);
                    break;
            }
            $cc_details[ $key ] = $data;

        }
    }
    return $cc_details;
}
