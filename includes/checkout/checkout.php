<?php
/**
 * Displays Klarna checkout page
 *
 * @package WC_Gateway_Klarna
 */

// Bail if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

// Check if iframe needs to be displayed
if ( ! $this->show_kco() )
	return;

// Process order via Klarna Checkout page
if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) )
	define( 'WOOCOMMERCE_CHECKOUT', true );

// Set Klarna Checkout as the choosen payment method in the WC session
WC()->session->set( 'chosen_payment_method', 'klarna_checkout' );

// Debug
if ( $this->debug == 'yes' )
	$this->log->add( 'klarna', 'Rendering Checkout page...' );

// Mobile or desktop browser
if ( wp_is_mobile() ) {
	$klarna_checkout_layout = 'mobile';
} else {
	$klarna_checkout_layout = 'desktop';
}

/**
 * Set $add_klarna_window_size_script to true so that Window size 
 * detection script can load in the footer
 */
global $add_klarna_window_size_script;
$add_klarna_window_size_script = true;

// Add button to Standard Checkout Page if this is enabled in the settings
if ( $this->add_std_checkout_button == 'yes' ) {
	echo '<div class="woocommerce"><a href="' . get_permalink( get_option( 'woocommerce_checkout_page_id' ) ) . '" class="button std-checkout-button">' . $this->std_checkout_button_label . '</a></div>';
}

// Recheck cart items so that they are in stock
$result = $woocommerce->cart->check_cart_item_stock();
if ( is_wp_error( $result ) ) {
	return $result->get_error_message();
	exit();
}

// Check if there's anything in the cart
if ( sizeof( $woocommerce->cart->get_cart() ) > 0 ) {

	// Get Klarna credentials
	$eid = $this->klarna_eid;
	$sharedSecret = $this->klarna_secret;

	// Store WC object as transient
	$klarna_wc = WC();
	$klarna_transient = md5( time() . rand( 1000, 1000000 ) );
	set_transient( $klarna_transient, $klarna_wc, 48 * 60 * 60 );
	WC()->session->set( 'klarna_sid', $klarna_transient );
	
	// Process cart contents and prepare them for Klarna
	include_once( KLARNA_DIR . 'classes/class-wc-to-klarna.php' );
	$wc_to_klarna = new WC_Gateway_Klarna_WC2K( $this->is_rest() );
	$cart = $wc_to_klarna->process_cart_contents();

	// Initiate Klarna
	if ( $this->is_rest() ) {
		require_once( KLARNA_LIB . 'vendor/autoload.php' );
		$connector = Klarna\Rest\Transport\Connector::create(
		    $eid,
		    $sharedSecret,
		    Klarna\Rest\Transport\ConnectorInterface::TEST_BASE_URL
		);
	} else {
		require_once( KLARNA_LIB . '/src/Klarna/Checkout.php' );
		$connector = Klarna_Checkout_Connector::create( $sharedSecret );
	}
	$klarna_order = null;
	
	/**
	 * Check if Klarna order already exists
	 */
		
	// If it does, see if it needs to be updated
	if ( WC()->session->get( 'klarna_checkout' ) ) {
		include( KLARNA_DIR . 'includes/checkout/resume.php' );
	}
	// If it doesn't, create Klarna order
	if ( $klarna_order == null ) {
		include( KLARNA_DIR . 'includes/checkout/create.php' );
	}

	// Store location of checkout session
	$sessionId = $klarna_order->getLocation();
	if ( null === WC()->session->get( 'klarna_checkout' ) ) {
		WC()->session->set( 'klarna_checkout', $sessionId );
	}

	// Display checkout
	do_action( 'klarna_before_kco_checkout' );
	if ( $this->is_rest() ) {
		$snippet = $klarna_order['html_snippet'];
	} else {
		$snippet = $klarna_order['gui']['snippet'];
	}
	echo '<div>' . apply_filters( 'klarna_kco_checkout', $snippet ) . '</div>';
	do_action( 'klarna_after_kco_checkout' );

} // End if sizeof cart 