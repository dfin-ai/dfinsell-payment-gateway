<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class DFINSELL_Blocks_Gateway extends AbstractPaymentMethodType {
    protected $id = 'dfinsell';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->id . '_settings', [] );
    }

	public function is_active() {
	    return ( isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] );
	}

    // In class
	public function get_payment_method_script_handles() {
	    return [ 'dfinsell-blocks-js' ]; // match your registered handle
	}

  	public function get_payment_method_data() {
		 error_log( print_r( $this->settings, true ) ); // Debug
	    return [
	        'id'          => $this->id, // e.g. 'dfinsell'
	        'title'       => isset( $this->settings['title'] ) ? $this->settings['title'] : '',
	        'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
	        'supports'    => [ 'products' ],
	        'is_active'   => ( isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'] ),
	        // extra settings you want to pass
	        'sandbox'     => isset( $this->settings['sandbox'] ) ? $this->settings['sandbox'] : '',
	        'order_status'=> isset( $this->settings['order_status'] ) ? $this->settings['order_status'] : '',
	        'instructions'=> isset( $this->settings['instructions'] ) ? $this->settings['instructions'] : '',
	        'accounts'    => isset( $this->settings['accounts'] ) ? $this->settings['accounts'] : '',
	    ];
	}
}

