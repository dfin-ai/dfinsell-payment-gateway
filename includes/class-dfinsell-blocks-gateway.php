<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DFINSELL_Blocks_Gateway extends AbstractPaymentMethodType {

    protected $name = 'dfinsell'; // must match your gateway_id

    public function initialize() {
        $this->settings = get_option( 'woocommerce_' . $this->name . '_settings', [] );
    }

    public function is_active() {
        // Use your gateway's active check
        return 'yes' === ( $this->settings['enabled'] ?? 'no' );
    }

    public function get_payment_method_script_handles() {
        return [ 'dfinsell-blocks-script' ];
    }

    public function get_payment_method_data() {
        return [
            'title'       => $this->settings['title'] ?? __( 'DFin Sell Payment', 'dfinsell' ),
            'description' => $this->settings['description'] ?? __( 'Pay securely via DFin Sell.', 'dfinsell' ),
            'supports'    => [ 'products' ],
        ];
    }
}
