<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Shiplee_Main' ) ) {

    class Shiplee_Main {

        public static function activate() {
            ;
        }

        public static function wp_init() {
            ;
        }

        /**
         * Add query vars needed for this plugin
         */
        public static function rewrite_query_vars($query_vars){    
            $query_vars[] = SHIPLEE_CALLBACK_QUERY_VAR; 
            return $query_vars; 
        }

        /**
         * Hook for template_include
         */
        public static function template_include($template) {
            if (get_query_var(SHIPLEE_CALLBACK_QUERY_VAR, false)) {
                define('WP_USE_THEMES', false);

                if( $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
                    die( 'Incorrect request method' );
                }

                $data = json_decode(file_get_contents('php://input'), true);
                $id = $data['id'] ?? false;

                if( ! $id ) {
                    die( 'Incorrect input' );
                }

                $shipping_methods = WC()->shipping->get_shipping_methods();
                $shiplee_shipping_method = $shipping_methods['shiplee_shipping_method'] ?? false;

                if( ! $shiplee_shipping_method ) {
                    die( 'Shiplee plugin failure' );
                }

                $shiplee_api_connect = ShipleeApiConnect::getInstance( $shiplee_shipping_method->getApiKey() );
                $shipment = $shiplee_api_connect->getShipment( $id );

                if( ! $shipment || ! isset( $shipment['id'] ) ) {
                    die( 'Could not find shipment' );
                }

                global $wpdb;
                $post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'shiplee_shipment_id' AND meta_value = %s", $shipment['id'] ) );
                $order = $post_id ? wc_get_order( $post_id ) : false;

                if( ! $order ) {
                    die( 'Could not find order' );
                }

                $tracking_code = isset( $shipment['delivery'], $shipment['delivery']['tracking_code'] ) ? $shipment['delivery']['tracking_code'] : false;
                $tracking_url = isset( $shipment['delivery'], $shipment['delivery']['tracking_url'] ) ? $shipment['delivery']['tracking_url'] : false;
                $status = isset( $shipment['delivery'], $shipment['delivery']['status'] ) ? $shipment['delivery']['status'] : false;
                $order->add_order_note( sprintf( __( 'Shiplee callback; status %s', 'shiplee' ), $status ) );

                update_post_meta( $order->get_id(), $shipment['id'], $status );
                update_post_meta( $order->get_id(), $shipment['id'] . '_tracking_url', $tracking_url );
                update_post_meta( $order->get_id(), $shipment['id'] . '_tracking_code', $tracking_code );

                $logger = wc_get_logger();

                switch( $status ) {

                    case 'no_funds':
                    case 'failed':
                    case 'expired':

                        $logger->error( 'Shiplee callback; status: ' . $status );

                        break;

                    case 'pre_transit':

                        $logger->info( 'Shiplee callback; status: ' . $status );

                        $anchor_tags = [];
                        $shipment_label = $shiplee_api_connect->getShipmentLabel( $shipment['id'] );
                        foreach( [ 'pdf', 'png', 'zpl' ] as $type ) {
                            $key = $type . '_url';
                            $url = $shipment_label[ $key ] ?? false;

                            if( $url ) {
                                update_post_meta( $post_id, $shipment['id'] . '_shipment_label_' . $type . '_url', $url );
                                $anchor_tags[] = '<a href="' . $url . '" target="_blank">' . mb_strtoupper( $type ) . '</a>';
                            }
                        }

                        if( $anchor_tags ) {
                            foreach( $order->get_shipping_methods() as $shipping_method ) {
                                if( $shipping_method->meta_exists( 'product_id' ) && $shipping_method->meta_exists( 'carrier' ) ) {
                                    // $shipping_method->add_meta_data( 'download', implode( ' - ', $anchor_tags ), true );
                                    wc_update_order_item_meta( $shipping_method->get_id(), 'download', implode( ' - ', $anchor_tags ) );
                                }
                            }

                        }

                        break;

                    default:
                        // TODO: Store meta data?
                        break;
                }

                return;
            }

            return $template;
        }

        public static function init() {
            require_once __DIR__ . '/class-shiplee-shipping-method.php';
            if( ! class_exists( 'ShipleeApiConnect' ) ) {
                require_once __DIR__ . '/ShipleeApiConnect.php';
            }
        }

        public static function enqueue_scripts( $hook ) {

            if( ! in_array( $hook, [ 'post.php', 'edit.php' ] ) ) {
                return;
            }

            global $post;
            if( ! $post || ( $post->post_type != 'shop_order' ) ) {
                return;
            }

            $plugin_data = get_plugin_data(SHIPLEE_PLUGIN_ROOT_PATH . 'shiplee-woocommerce.php');
            $plugin_version = $plugin_data['Version'];

            wp_enqueue_style( 'shiplee-edit', SHIPLEE_PLUGIN_URL . "assets/css/edit.css?plugin_version={$plugin_version}" );
            wp_enqueue_script( 'shiplee-edit', SHIPLEE_PLUGIN_URL . "assets/js/edit.js?plugin_version={$plugin_version}", [ 'jquery' ] );

        }

        private static function human_readable_shipment_status( $status ) {
            $status_to_human_readable = array(
                "initial" => "Initial",
                "pre_transit" => "Accepted",
                "ready_for_pickup" => "Ready",
                "in_transit" => "In transit",
                "in_depot" => "In depot",
                "out_for_delivery" => "Out for delivery",
                "delivered" => "Delivered",
                "delivered_at_neighbours" => "Delivered (neighbours)",
                "ready_for_collection" => "Ready for collection",
                "failed_to_deliver" => "Failed",
                "return_to_sender" => "Return",
                "returned_to_sender_out_for_delivery" => "Returning",
                "returned_to_sender" => "Returned",
                "expired" => "Expired",
                "cancelled" => "Cancelled",
                "not_available" => "Not available",
                "delayed" => "Delayed",
                "lost" => "Lost",
                "error" => "Error",
                "failed" => "Failed",
                "no_funds" => "No funds",
                "waiting_for_callback" => "Waiting for update from Shiplee (refresh page for updates)"
            );

            if ( array_key_exists($status, $status_to_human_readable) ) {
                return $status_to_human_readable[ $status ];
            }

            return $status;
        }

        /**
         * Hook that fires on Order pages
         * https://developer.wordpress.org/reference/hooks/admin_footer/
         * Renders the form that is used to create labels
         */
        public static function admin_footer() {

            if( in_array( get_current_screen()->id, [ 'shop_order', 'edit-shop_order' ] ) ): ?>
                <div id="shiplee-create-label-form" data-created-message="<?php _e( 'Shipment created, status: '. static::human_readable_shipment_status('waiting_for_callback'), 'shiplee' ); ?>">
                    <table class="shiplee-create-label-table widefat">
                        <thead>
                            <tr>
                                <th colspan="2" scope="row"><h2 class="shiplee-create-label-title" data-content="<?php _e( 'Create label for #%d', 'shiplee' ); ?>"></h2></th>
                            </tr>
                        </thead>
                        <tbody class="shiplee-rates" data-prototype="<tr class=&quot;shiplee-rate&quot;><td colspan=&quot;2&quot;><h4></h4><label><?php _e( 'options:', 'shiplee' ); ?></label></td></tr>"></tbody>
                        <tbody class="shiplee-additional-fields">
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="weight"><?php _e( 'Weight (kg)', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input type="text" name="weight" value=""></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="value_of_goods"><?php _e( 'Value of goods (&euro;)', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input type="text" name="value_of_goods" value=""></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="description_of_goods"><?php _e( 'Description of goods', 'shiplee' ); ?></label></th>
                                <td><textarea name="description_of_goods" maxlength="70" class="regular-text"></textarea></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="age_check_18"><?php _e( 'Age check 18', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input name="age_check_18" type="checkbox" value="" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="require_signature"><?php _e( 'Require signature', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input name="require_signature" type="checkbox" value="" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="dont_allow_neighbours"><?php _e( 'Don\'t deliver at neighbours', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input name="dont_allow_neighbours" type="checkbox" value="" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row" class="fifty-percent-width"><label for="perishable"><?php _e( 'Perishable', 'shiplee' ); ?></label></th>
                                <td class="fifty-percent-width"><input name="perishable" type="checkbox" value="" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="perishable_max_attempts"><?php _e( 'Perishable maximum attempts', 'shiplee' ); ?></label></th>
                                <td>
                                    <select name="perishable_max_attempts">
                                        <option value="2">2</option>
                                        <option value="3">3</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delivery_date"><?php _e( 'Delivery date', 'shiplee' ); ?></label></th>
                                <td><input name="delivery_date" type="text" value="" class="date-picker"></td>
                            </tr>
                            <tr class="deliverer_note">
                                <th colspan="2" scope="row"><label for="deliverer_note"><?php _e( 'Deliverer note', 'shiplee' ); ?></label></th>
                            </tr>
                            <tr class="deliverer_note">
                                <td colspan="2"><textarea name="deliverer_note" class="regular-text"></textarea></td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2"><button class="button button-primary right"><?php _e( 'Create label', 'shiplee' ); ?></button></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
<?php
                echo ob_get_clean();
            endif;
        }

        /**
         * Hook that fires on Order edit page: /post.php?post=XX&action=edit
         * http://hookr.io/actions/woocommerce_admin_order_items_after_shipping/
         */
        public static function order_items_after_shipping( $order_id ) { ?>
            <table class="shiplee-table-wrapper">
                <tbody>
                    <tr>
                        <td class="thumb"><div></div></td>
                        <td><?php self::render_shipping_action( $order_id ); ?></td>
                    </tr>
                </tbody>
            </table><?php
        }

        /**
         * Hook that fires on Order list table: /edit.php?post_type=shop_order
         * Modifies shipping address column
         * https://developer.wordpress.org/reference/hooks/manage_post_type_posts_columns/
         */
        public static function shop_order_columns( $column ) {

            if( $column == 'shipping_address' ) {
                global $post;
                self::render_shipping_action( $post->ID );
            }
        }

        /**
         * Renders list of created labels,
         * and button to add more.
         *
         * @param int $order_id
         *
         */
        public static function render_shipping_action( $order_id ) {

            $order = wc_get_order( $order_id ); ?>
            <?php if( !$order->has_shipping_address() ): ?>
            <div class="notice notice-warning inline">
                This order has no shipping address, so we'll use the billing address for creating Shiplee labels.
            </div>
            <?php endif; ?>

            <div class="shiplee-label-wrapper" data-id="<?php echo esc_attr($order_id); ?>">
            <?php

            foreach( get_post_meta( $order_id, 'shiplee_shipment_id' ) as $shipment_id ) {
                $anchor_tags = [];
                foreach( [ 'pdf', 'png', 'zpl' ] as $type ) {
                    $key = $shipment_id . '_shipment_label_' . $type . '_url';
                    if( $url = get_post_meta( $order_id, $key, true ) ) {
                        $anchor_tags[] = '<a href="' . $url . '" target="_blank">' . mb_strtoupper( $type ) . '</a>';
                    }
                }

                printf( __( 'Shipment status: %s' , 'shiplee' ), static::human_readable_shipment_status(get_post_meta( $order_id, $shipment_id, true )) );
                if( $anchor_tags ) {
                    _e( ' - Label: ', 'shiplee' );
                    echo wp_kses(implode( ' - ', $anchor_tags ), array(
                        'a' => array(
                            'href' => array(),
                            'target' => array()
                        )
                    ));
                }

                $tracking_code = get_post_meta( $order_id, $shipment_id . '_tracking_code', true );
                $tracking_url = get_post_meta( $order_id, $shipment_id . '_tracking_url', true );
                if( $tracking_code && $tracking_url ) {
                    _e( ' - Tracking code: ', 'shiplee' );
                    echo '<a href="' . esc_url($tracking_url) . '" target="_blank">' . wp_kses($tracking_code, array()) . '</a>';
                }
                echo '<br />';
            } ?>

            </div><?php

            $country = get_option( 'woocommerce_default_country' );
            $country_parts = explode( ':', $country );

            $billing_or_shipping = $order->has_shipping_address() ? 'shipping' : 'billing';

            $data = [
                'recipient_zipcode' => $order->{"get_{$billing_or_shipping}_postcode"}(),
                'recipient_country' => $order->{"get_{$billing_or_shipping}_country"}(),
                'sender_country' => $country_parts[1] ?? 'NL',
            ]; ?>
            <div class="shiplee-wrapper" data-fields="<?php echo wc_esc_json( json_encode( $data ), false ); ?>" data-id="<?php echo esc_attr($order_id); ?>">
                <a class="shiplee-create-label" href="javascript:;"><?php _e( 'Add label', 'shiplee' ); ?></a>
            </div><?php
        }

        public static function add_shipping_method( $methods ) {
            $methods[ 'shiplee_shipping_method' ] = 'Shiplee_Shipping_Method';
            return $methods;
        }


        /**
         * Processes ajax request
         * get_product_availability
         */
        public static function ajax_get_product_availability() {

            $sender_country = wp_kses($_POST[ 'sender_country' ], array()) ?? '';
            $recipient_country = wp_kses($_POST[ 'recipient_country' ], array()) ?? '';
            $recipient_zipcode = wp_kses($_POST[ 'recipient_zipcode' ], array()) ?? '';

            $shipping_methods = WC()->shipping->get_shipping_methods();
            $shiplee_shipping_method = $shipping_methods[ 'shiplee_shipping_method' ] ?? false;

            if( ! $shiplee_shipping_method ) {
                wp_send_json( [ 'status' => 'error', 'message' => __( 'Shiplee shipping method not instantiated', 'shiplee' ) ], 200 );
            }

            $shiplee_api_connect = ShipleeApiConnect::getInstance( $shiplee_shipping_method->getApiKey() );
            $response = $shiplee_api_connect->getProductAvailability( $sender_country, $recipient_country, $recipient_zipcode );

            if( ! $response ) {
                wp_send_json( [ 'status' => 'error', 'message' => __( 'Found no Shiplee products', 'shiplee' ) ], 200 );
            }

            if( isset( $response[ 'detail' ] ) && $response[ 'detail' ] ) {

                if( in_array( $response[ 'http_code' ], [ 401 , 403 ] ) ) {
                    wp_send_json( [ 'status' => 'error', 'message' => $response[ 'detail' ] ], 200 );
                }

                $detail = current( $response[ 'detail' ] );
                $location = implode( ', ', $detail[ 'loc' ] );
                wp_send_json( [ 'status' => 'error', 'message' => "[$location] " . $detail[ 'msg' ] ], 200 );
            }

            unset( $response[ 'http_code' ] );

            wp_send_json( [ 'status' => 'success', 'rates' => $response['products'] ], 200 );
        }

        /**
         * Processes ajax request sent using
         *  - self::render_shipping_action
         *  - self::admin_footer
         */
        public static function ajax_create_label() {

            $id = intval( $_POST[ 'id' ] ?? 0 );
            $order = wc_get_order( $id );
            $logger = wc_get_logger();

            if( ! $order ) {
                $logger->error( 'Could not find order ' );
                wp_send_json( [ 'status' => 'error', 'message' => __( 'Could not find order', 'shiplee' ) ], 200 );
            }

            $shipping_options = [];
            foreach( [ 'age_check_18', 'require_signature', 'dont_allow_neighbours', 'perishable' ] as $option ) {
                $shipping_options[ 'option_' . $option ] = isset( $_POST[ $option ] ) && rest_sanitize_boolean($_POST[ $option ]);
            }

            $rate = [
                'id' => sanitize_key($_POST[ 'product_id' ]) ?? '',
                'carrier' => sanitize_key($_POST[ 'carrier' ]) ?? '',
            ];

            $fields = [
                'perishable_max_attempts' => intval($_POST[ 'perishable_max_attempts' ]) ?? 2,
                'shipping_options' => $shipping_options,
                'deliverer_note' => sanitize_textarea_field($_POST[ 'deliverer_note' ]) ?? '',
                'weight' => floatval( $_POST[ 'weight' ] ?? 0 ),
                'value_of_goods' => floatval( $_POST[ 'value_of_goods' ] ?? 0 ),
                'description_of_goods' => substr( sanitize_textarea_field($_POST[ 'description_of_goods' ]) ?? '', 0, 70 ),
            ];

            $delivery_date = date_create( $_POST[ 'delivery_date' ] ?? '' );

            try {
                if( $shiplee_id = self::create_shipment( $order, $rate, $fields, $delivery_date->format( 'Y-m-d' ) ) ) {
                    wp_send_json( [ 'status' => 'success', 'shiplee_id' => $shiplee_id, 'message' => __( 'Shipment created', 'shiplee' ) ], 200 );
                }
            } catch (\Exception $e) {
                wp_send_json( [ 'status' => 'error', 'message' => $e->getMessage() ], 200 );

            }

            // Should never get here
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Unknown error', 'shiplee' ) ], 200 );

        }


        /**
         * Makes call to the Shiplee API to create a shipment and label
         *
         * @param WC_Order $order
         * @param array $rate The response from ShipleeApiConnect->getProductAvailability
         * @param array $fields Associated array with shipment data
         * @param int $delivery_date Delivery date in YYYY-MM-DD format
         *
         */
        public static function create_shipment( $order, $rate, $fields, $delivery_date ) {

            $product_id = $rate[ 'id' ];
            $carrier = $rate[ 'carrier' ];

            $country = get_option( 'woocommerce_default_country' );
            $country_parts = explode( ':', $country );

            $billing_or_shipping = $order->has_shipping_address() ? 'shipping' : 'billing';

            $data = [
                'recipient' => [
                    'contact_name' => $order->{"get_formatted_{$billing_or_shipping}_full_name"}(),
                    'company_name' => $order->{"get_{$billing_or_shipping}_company"}(),
                    'address_1' => $order->{"get_{$billing_or_shipping}_address_1"}(),
                    'address_2' => $order->{"get_{$billing_or_shipping}_address_2"}(),
                    'address_3' => '',
                    'city' => $order->{"get_{$billing_or_shipping}_city"}(),
                    'zipcode' => $order->{"get_{$billing_or_shipping}_postcode"}(),
                    'country_code' => $order->{"get_{$billing_or_shipping}_country"}(),
                    'phone_number' => $order->get_shipping_phone() ?: $order->get_billing_phone(),
                    'email_address' => $order->get_billing_email(),
                ],
                'product' => [
                    'product_id' => $product_id,
                    'delivery_date' => $delivery_date,
                ],
                'callback_url' => SHIPLEE_CALLBACK_URL,
            ];

            if( $carrier === 'fedex' ) {
                $data[ 'sender' ] = [
                    'reference' => '',
                    'contact_name' => '',
                    'company_name' => get_bloginfo( 'name' ),
                    'address_1' => get_option( 'woocommerce_store_address' ),
                    'address_2' => get_option( 'woocommerce_store_address_2' ),
                    'address_3' => '',
                    'city' => get_option( 'woocommerce_store_city' ),
                    'county' => $country_parts[1] ?? '',
                    'zipcode' => get_option( 'woocommerce_store_postcode' ),
                    'country_code' => $country_parts[0] ?? '',
                    'phone_number' => '',
                    'email_address' => get_bloginfo( 'admin_email' ),
                ];
                $data[ 'recipient' ][ 'county' ] = $order->get_shipping_state();
                $data[ 'details' ] = [
                    'weight' => $fields[ 'weight' ],
                    'weight_uom' => 'K', // mb_strtoupper( substr( get_option( 'woocommerce_weight_unit' ), 0, 1 ) ),
                    'description_of_goods' => $fields[ 'description_of_goods' ], //'Order #' . $order->get_id(),
                    'value_of_goods' => $fields[ 'value_of_goods' ],
                    'value_of_goods_currency' => $order->get_currency(),
                ];
            } else { // $carrier === 'rjp'
                $data[ 'sender' ] = [
                    'reference' => $order->get_id(),
                    'company_name' => get_bloginfo( 'name' ),
                ];
                $data[ 'details' ] = $fields[ 'shipping_options' ];

                if( in_array('option_perishable', $fields[ 'shipping_options' ]) && $fields[ 'shipping_options' ][ 'option_perishable' ] === true) {
                    $data[ 'details' ][ 'perishable_max_attempts' ] = $fields[ 'perishable_max_attempts' ];
                }
                $data[ 'details' ][ 'deliverer_note' ] = $fields[ 'deliverer_note' ];
            }

            $shipping_methods = WC()->shipping->get_shipping_methods();
            $shiplee_shipping_method = $shipping_methods[ 'shiplee_shipping_method' ] ?? false;

            if( ! $shiplee_shipping_method ) {
                $order->add_order_note( __( 'Failed to create shipment', 'shiplee' ) );
                $logger = wc_get_logger();
                $logger->error( 'Shiplee shipping method not instantiated ' );
                throw new \Exception( __( 'Failed to create shipment', 'shiplee' ) );
            }

            $shiplee_api_connect = ShipleeApiConnect::getInstance( $shiplee_shipping_method->getApiKey() );
            $response = $shiplee_api_connect->createShipment( $data );

            $shiplee_id = $response[ 'id' ] ?? false;
            if( $shiplee_id ) {
                add_post_meta( $order->get_id(), 'shiplee_shipment_id', $shiplee_id );
                update_post_meta( $order->get_id(), $shiplee_id, 'waiting_for_callback' );
                $order->add_order_note( sprintf( __( 'Shipment created, id: %s', 'shiplee' ), $shiplee_id ) );
                return $shiplee_id;
            }

            $order->add_order_note( __( 'Failed to create shipment', 'shiplee' ) );
            $logger = wc_get_logger();
            $logger->error( json_encode( $response ) );
            throw new \Exception( __( 'Failed to create shipment', 'shiplee' ) );

        }
    }
}
