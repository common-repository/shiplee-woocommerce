<?php

/**
 * ShipleeApiConnect
 *
 * Singleton class connecting to Shiplee API
 */
class ShipleeApiConnect {

    /** Class constants **/
    const API_URL = 'https://api.shiplee.com/v1';
    const GET_PRODUCT_AVAILABILITY_ENDPOINT = self::API_URL . '/product-availability';
    const GET_SHIPMENT_ENDPOINT = self::API_URL . '/shipments/%s';
    const CREATE_SHIPMENT_ENDPOINT = self::API_URL . '/shipments';
    const GET_SHIPMENT_LABEL_ENDPOINT = self::API_URL . '/shipments/%s/label';

    /**
     * @var self|null private singleton instance
     */
    private static $instance = null;

    /**
     * @var string|null private singleton instance
     */
    protected $api_key;

    /** private functions for singleton class **/
    private function __clone() {}
    private function __wakeup() {}
    private function __construct(string $api_key) {
        $this->api_key = $api_key;
    }

    /**
     * Get Singleton instance
     *
     * @param string $api_key, only required on first call
     */
    public static function getInstance(string $api_key) {

        if(!self::$instance) {
            self::$instance = new self($api_key);
        }

        return self::$instance;
    }

    /**
     * apiCall
     * Makes a curl call and returns json decoded object
     *
     * @param string $url
     * @param string $method Only supports GET and POST
     * @param array $query_fields query parameters as an associated array
     * @param array $headers heder parameters as an associated array
     */
    protected function apiCall($url, $method = 'GET', $query_fields = [], $headers = []) {
        $headers = array(
            'Authorization' => "Bearer {$this->api_key}"
        );

        $response = null;

        switch(strtolower($method)) {
            case 'get':
                $response = wp_remote_get($url . '?' . http_build_query($query_fields), array(
                    'headers' => $headers
                ));
                break;
            case 'post':
                $response = wp_remote_post($url, array(
                    'headers' => array_merge($headers, array(
                        'Content-Type' => 'application/json; charset=utf-8'
                    )),
                    'body' => json_encode($query_fields),
                    'method' => 'POST',
                    'data_format' => 'body'
                ));
                break;
        }

        if ($response === null) return null;

        $return_value = json_decode(wp_remote_retrieve_body( $response ), true) ?: [];
        $return_value['http_code'] = wp_remote_retrieve_response_code( $response );

        return $return_value;
    }

    /**
     * apiGet
     * Shortcut for apiCall with GET method
     *
     * @param string $url
     * @param array $query_fields query parameters as an associated array
     * @param array $headers heder parameters as an associated array
     */
    protected function apiGet($url, $query_fields = [], $headers = []) {
        return $this->apiCall($url, 'GET', $query_fields, $headers);
    }

    /**
     * apiPost
     * Shortcut for apiCall with POST method
     *
     * @param string $url
     * @param array $query_fields query parameters as an associated array
     * @param array $headers heder parameters as an associated array
     */
    protected function apiPost($url, $query_fields = [], $headers = []) {
        return $this->apiCall($url, 'POST', $query_fields, $headers);
    }

    /**
     * getProductAvailability
     *
     * @param string $sender_country_code 2 letter country code e.g. NL
     * @param string $recipient_country_code 2 letter country code e.g. NL
     * @param string $recipient_zipcode
     * @param string|int $weight
     * @param string $weight_uom K or L for kilograms of pounds
     * @param array $shipping_options optional, if provided, rates are filtered to only show rates that have all provided options available (e.g. option_age_check_18). Surpluses are added to token_amount, original token_amount is stored in original_token_amount.
     */
    public function getProductAvailability($sender_country_code, $recipient_country_code, $recipient_zipcode, $weight = '', $weight_uom = 'K', $shipping_options = []) {

        $query_fields = compact('sender_country_code', 'recipient_country_code', 'recipient_zipcode');
        if($weight) {
            $query_fields['weight'] = $weight;
            $query_fields['weight_uom'] = $weight_uom;
        }

        $product_rates = $this->apiGet(self::GET_PRODUCT_AVAILABILITY_ENDPOINT, $query_fields);

        if($shipping_options) {

            $sameDay = in_array('option_same_day', $shipping_options);
            $shipping_options = array_filter($shipping_options, function($option) { return $option != 'option_same_day'; });

            $filtered_rates = [];

            foreach($product_rates['products'] as $rate) {

                if($rate['same_day'] !== $sameDay) {
                    continue;
                }

                $option_tokens = 0;
                foreach($shipping_options as $option) {

                    $matched_options = array_filter($rate['available_options'] ?? [], function($rate_option) use ($option) {
                        return $option === $rate_option['name'];
                    });

                    $matched_option = current($matched_options);

                    if(!$matched_option) {
                        continue 2;
                    }

                    $option_tokens += $matched_option['token_amount'];

                }

                $rate['original_token_amount'] = $rate['token_amount'];
                $rate['token_amount'] = $rate['token_amount'] + $option_tokens;

                $filtered_rates[] = $rate;
            }

            return $filtered_rates;

        }

        return $product_rates;
    }

    /**
     * getShipment
     *
     * @param string $shipmentId
     */
    public function getShipment(string $shipmentId) {
        return $this->apiGet(sprintf(self::GET_SHIPMENT_ENDPOINT, $shipmentId));
    }

    /**
     * createShipment
     *
     * @param array $data associated array in the ShipmentFedexCreate or ShipmentRJPCreate format. See https://api-staging.shiplee.com/docs#operation/create_shipment_v1_alpha_shipments_post
     */
    public function createShipment(array $data) {
        return $this->apiPost(self::CREATE_SHIPMENT_ENDPOINT, $data);
    }

    /**
     * getShipmentLabel
     *
     * @param string $shipmentId
     */
    public function getShipmentLabel(string $shipmentId) {
        return $this->apiGet(sprintf(self::GET_SHIPMENT_LABEL_ENDPOINT, $shipmentId));
    }

}
