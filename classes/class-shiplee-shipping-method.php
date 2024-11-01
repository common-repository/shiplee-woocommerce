<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Shiplee_Shipping_Method' ) ) {

    class Shiplee_Shipping_Method extends WC_Shipping_Method {

        /**
         * Constructor for Shiplee_Shipping_Method
         *
         * @access public
         * @return void
         */
        public function __construct() {
            $this->id = 'shiplee_shipping_method';
            $this->title = __( 'Shiplee shipping plug-in', 'shiplee' );
            $this->method_title = __( 'Shiplee shipping plug-in', 'shiplee' );
            $this->method_description = __( 'Shipping method enabling you to create Shiplee shipment labels directly from the WooCommerce Orders section.', 'shiplee' );
            $this->init();
        }

        public function getApiKey() {
            return $this->settings['api_key'];
        }

        public function getWeightUnitOfMeasure() {
            return mb_strtoupper( substr( get_option( 'woocommerce_weight_unit' ), 0, 1 ) );
        }

        /**
         * Init your settings
         *
         * @access public
         * @return void
         */
        public function init() {

            $this->init_form_fields();
            $this->init_settings();

            if( $this->getApiKey() ) {
                $this->enabled = 'yes';
            }

            add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );

        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields() {
            $this->form_fields = [
                'api_key' => [
                    'title' => __( 'API Key', 'shiplee' ),
                    'type' => 'text',
                    'description' => sprintf(
                        '%s<a href="https://app.shiplee.com" target="_blank">%s</a>%s',
                        __( 'You can create an API key in the ', 'shiplee' ),
                        __( 'Shiplee Dashboard', 'shiplee' ),
                        __( '', 'shiplee' )
                    )
                ],
            ];

        }

        public function admin_options() { ?>
            <h2><?php _e( 'Shiplee shipping plug-in', 'shiplee' ); ?></h2>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><?php

            if( $this->getApiKey() ) {
                $shiplee_api_connect = ShipleeApiConnect::getInstance( $this->getApiKey() );
                $response = $shiplee_api_connect->getProductAvailability( 'NL', 'NL', '6825ME' );

                if( $response[ 'http_code' ] == '200' ) {
                    $color = 'var(--wc-green)';
                    $description = __( 'API Key is valid', 'shiplee' );
                } else {
                    $color = 'var(--wc-red)';
                    $description = __( 'API Key is invalid', 'shiplee' );
                } ?>

                <script type="text/javascript">
                    ( function( $ ) {
                        $( '#woocommerce_shiplee_shipping_method_api_key' )
                            .next( '.description' )
                            .html( '<?php echo wp_kses($description, array()) ?>' )
                            .css( 'color', '<?php echo wp_kses($color, array()) ?>' );
                    } ) ( jQuery );
                </script><?php
            }
        }
    }
}
