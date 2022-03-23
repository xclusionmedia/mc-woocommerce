<?php

class MailChimp_WooCommerce_Rest_Api
{
    protected static $namespace = 'mailchimp-for-woocommerce/v1';

    /**
     * @param $path
     * @return string
     */
    public static function url($path)
    {
        return esc_url_raw(rest_url(static::$namespace.'/'.ltrim($path, '/')));
    }
    /**
     * Register all Mailchimp API routes.
     */
    public function register_routes()
    {
        // ping
        register_rest_route(static::$namespace, '/ping', array(
            'methods' => 'GET',
            'callback' => array($this, 'ping'),
            'permission_callback' => '__return_true',
        ));

        // Right now we only have a survey disconnect endpoint.
        register_rest_route(static::$namespace, "/survey/disconnect", array(
            'methods' => 'POST',
            'callback' => array($this, 'post_disconnect_survey'),
            'permission_callback' => array($this, 'permission_callback'),
        ));

        // Sync Stats
        register_rest_route(static::$namespace, '/sync/stats', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sync_stats'),
            'permission_callback' => array($this, 'permission_callback'),
        ));

        // remove review banner
        register_rest_route(static::$namespace, "/review-banner", array(
            'methods' => 'GET',
            'callback' => array($this, 'dismiss_review_banner'),
            'permission_callback' => array($this, 'permission_callback'),
        ));

        //Member Sync
        register_rest_route(static::$namespace, "/member-sync", array(
            'methods' => 'GET',
            'callback' => array($this, 'member_sync_alive_signal'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(static::$namespace, "/member-sync", array(
            'methods' => 'POST',
            'callback' => array($this, 'member_sync'),
            'permission_callback' => '__return_true',
        ));

        // Tower report
        register_rest_route(static::$namespace, "/tower/report", array(
            'methods' => 'POST',
            'callback' => array($this, 'get_tower_report'),
            'permission_callback' => '__return_true',
        ));

        // tower logs
        register_rest_route(static::$namespace, "/tower/logs", array(
            'methods' => 'POST',
            'callback' => array($this, 'get_tower_logs'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * @return bool
     */
    public function permission_callback()
    {
        $cap = mailchimp_get_allowed_capability();
        return ($cap === 'manage_woocommerce' || $cap === 'manage_options' );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function ping(WP_REST_Request $request)
    {
        return $this->mailchimp_rest_response(array('success' => true));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function post_disconnect_survey(WP_REST_Request $request)
    {
        // need to send a post request to
        $host = mailchimp_environment_variables()->environment === 'staging' ?
            'https://staging.conduit.vextras.com' : 'https://conduit.mailchimpapp.com';

        $route = "{$host}/survey/woocommerce";

        $result = wp_remote_post(esc_url_raw($route), array(
            'timeout'   => 12,
            'blocking'  => true,
            'method'      => 'POST',
            'data_format' => 'body',
            'headers'     => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'        => json_encode($request->get_params()),
        ));

        return $this->mailchimp_rest_response($result);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_sync_stats(WP_REST_Request $request)
    {
        // if the queue is running in the console - we need to say tell the response why it's not going to fire this way.
        if (!mailchimp_is_configured() || !($api = mailchimp_get_api())) {
            return $this->mailchimp_rest_response(array('success' => false, 'reason' => 'not configured'));
        }

        $store_id = mailchimp_get_store_id();
        
        $complete = array(
            'coupons' => get_option('mailchimp-woocommerce-sync.coupons.completed_at'),
            'products' => get_option('mailchimp-woocommerce-sync.products.completed_at'),
            'orders' => get_option('mailchimp-woocommerce-sync.orders.completed_at')
        );

        $promo_rules_count = mailchimp_get_coupons_count();
        $product_count = mailchimp_get_product_count();
        $order_count = mailchimp_get_order_count();

        $mailchimp_total_promo_rules = $complete['coupons'] ? $promo_rules_count - mailchimp_get_remaining_jobs_count('MailChimp_WooCommerce_SingleCoupon') : 0;
        $mailchimp_total_products = $complete['products'] ? $product_count - mailchimp_get_remaining_jobs_count('MailChimp_WooCommerce_Single_Product') : 0;
        $mailchimp_total_orders = $complete['orders'] ? $order_count - mailchimp_get_remaining_jobs_count('MailChimp_WooCommerce_Single_Order') : 0;
        // try {
        //     $promo_rules = $api->getPromoRules($store_id, 1, 1, 1);
        //     $mailchimp_total_promo_rules = $promo_rules['total_items'];
        //     if (isset($promo_rules_count['publish']) && $mailchimp_total_promo_rules > $promo_rules_count['publish']) $mailchimp_total_promo_rules = $promo_rules_count['publish'];
        // } catch (\Exception $e) { $mailchimp_total_promo_rules = 0; }
        // try {
        //     $products = $api->products($store_id, 1, 1);
        //     $mailchimp_total_products = $products['total_items'];
        //     if ($mailchimp_total_products > $product_count) $mailchimp_total_products = $product_count;
        // } catch (\Exception $e) { $mailchimp_total_products = 0; }
        // try {
        //     $orders = $api->orders($store_id, 1, 1);
        //     $mailchimp_total_orders = $orders['total_items'];
        //     if ($mailchimp_total_orders > $order_count) $mailchimp_total_orders = $order_count;
        // } catch (\Exception $e) { $mailchimp_total_orders = 0; }

        $date = mailchimp_date_local('now');

        // but we need to do it just in case.
        return $this->mailchimp_rest_response(array(
            'success' => true,
            'promo_rules_in_store' => $promo_rules_count,
            'promo_rules_in_mailchimp' => $mailchimp_total_promo_rules,
            
            'products_in_store' => $product_count,
            'products_in_mailchimp' => $mailchimp_total_products,
            
            'orders_in_store' => $order_count,
            'orders_in_mailchimp' => $mailchimp_total_orders,
            
            // 'promo_rules_page' => get_option('mailchimp-woocommerce-sync.coupons.current_page'),
            // 'products_page' => get_option('mailchimp-woocommerce-sync.products.current_page'),
            // 'orders_page' => get_option('mailchimp-woocommerce-sync.orders.current_page'),
            
            'date' => $date->format( __('D, M j, Y g:i A', 'mailchimp-for-woocommerce')),
            'has_started' => mailchimp_has_started_syncing() || ($order_count != $mailchimp_total_orders),
            'has_finished' => mailchimp_is_done_syncing() && ($order_count == $mailchimp_total_orders),
        ));
    }
    
    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function dismiss_review_banner(WP_REST_Request $request)
    {
        return $this->mailchimp_rest_response(array('success' => delete_option('mailchimp-woocommerce-sync.initial_sync')));
    }

    /**
     * Syncs members with updated statuses on Mailchimp panel
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function member_sync(WP_REST_Request $request)
    {
        $this->authorize('webhook.token', $request);
        $data = $request->get_params();
        $list_id = mailchimp_get_list_id();
        if (empty($data['type']) || empty($data['data']['list_id']) || $list_id !== $data['data']['list_id']) {
            return $this->mailchimp_rest_response(array('success' => false));
        }
        mailchimp_handle_or_queue(new MailChimp_WooCommerce_Subscriber_Sync($data));
        return $this->mailchimp_rest_response(array('success' => true));
    }

    /**
     * Returns an alive signal to confirm url exists to mailchimp system
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function member_sync_alive_signal(WP_REST_Request $request)
    {
        $this->authorize('webhook.token', $request);
        return $this->mailchimp_rest_response(array('success' => true));
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function get_tower_report(WP_REST_Request $request)
    {
        $this->authorize('tower.support_token', $request);
        return $this->mailchimp_rest_response(
            $this->tower($request->get_query_params())->handle()
        );
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_Error|WP_REST_Response
     */
    public function get_tower_logs(WP_REST_Request $request)
    {
        $this->authorize('tower.support_token', $request);
        return $this->mailchimp_rest_response(
            $this->tower($request->get_query_params())->logs()
        );
    }

    /**
     * @param null $params
     * @return MailChimp_WooCommerce_Tower
     */
    private function tower($params = null)
    {
        if (!is_array($params)) $params = array();
        $job = new MailChimp_WooCommerce_Tower(mailchimp_get_store_id());
        $job->withLogFile(!empty($params['log_view']) ? $params['log_view'] : null);
        $job->withLogSearch(!empty($params['search']) ? $params['search'] : null);
        return $job;
    }

    /**
     * @param array $data
     * @param int $status
     * @return WP_REST_Response
     */
    private function mailchimp_rest_response($data, $status = 200)
    {
        if (!is_array($data)) $data = array();
        $response = new WP_REST_Response($data);
        $response->set_status($status);
        return $response;
    }

    /**
     * @param $key
     * @param WP_REST_Request $request
     * @return bool
     * @throws WC_REST_Exception
     */
    private function authorize($key, WP_REST_Request $request)
    {
        $allowed_keys = array(
            'tower.token',
            'webhook.token',
        );
        // this is just a safeguard against people trying to do wonky things.
        if (!in_array($key, $allowed_keys, true)) {
            throw new WC_REST_Exception(403, 'unauthorized token type', 403);
        }
        // get the auth token from either a header, or the query string
        $token = $this->getAuthToken($request);
        // pull the saved data
        $saved = mailchimp_get_data($key);
        // if we don't have a token - or we don't have the saved comparison
        // or the token doesn't equal the saved token, throw an error.
        if (empty($token) || empty($saved) || base64_decode($token) !== $saved) {
            throw new WC_REST_Exception(403, 'unauthorized', 403);
        }
        return true;
    }

    /**
     * @param WP_REST_Request $request
     * @return false|mixed|string
     */
    private function getAuthToken(WP_REST_Request $request)
    {
        if (($token = $this->getBearerTokenHeader($request))) {
            return $token;
        }
        return $this->getAuthQueryStringParam($request);
    }

    /**
     * @param WP_REST_Request $request
     * @return false|string
     */
    private function getBearerTokenHeader(WP_REST_Request $request)
    {
        $header = $request->get_header('Authorization');
        $position = strrpos($header, 'Bearer ');
        if ($position !== false) {
            $header = substr($header, $position + 7);
            return strpos($header, ',') !== false ?
                strstr(',', $header, true) :
                $header;
        }
        return false;
    }

    /**
     * @param WP_REST_Request $request
     * @return false|mixed
     */
    private function getAuthQueryStringParam(WP_REST_Request $request)
    {
        $params = $request->get_query_params();
        return empty($params['auth']) ? false : $params['auth'];
    }
}