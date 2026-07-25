<?php
/**
 * Serves the ZATCA invoice PDF from THE STORE'S OWN domain, over the WordPress
 * REST API.
 *
 * Two problems shaped this. A link to zatcatools.com is a third-party document
 * URL, which ad blockers block outright (ERR_BLOCKED_BY_CLIENT) — so the store
 * must serve the file itself. And an `admin-post.php?action=…&order_id=…` URL
 * is exactly the shape filter lists match, so it was blocked too. A REST route
 * is the approach WordPress itself recommends for plugin endpoints and gives a
 * clean, query-free path: /wp-json/zatca-tools/v1/invoice/{order}/{key}
 *
 * The store fetches the PDF server-to-server with its own API key, so the key
 * is never exposed and the buyer stays on the shop they bought from.
 *
 * Access mirrors WooCommerce's own rules: shop managers by capability, the
 * buyer by the order key (the same secret behind their "view order" link) or by
 * owning the order.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Invoice_File {

    const NAMESPACE_V1 = 'zatca-tools/v1';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }

    public function register_routes() {
        register_rest_route(
            self::NAMESPACE_V1,
            '/invoice/(?P<order>\d+)(?:/(?P<key>[A-Za-z0-9_-]+))?',
            array(
                'methods'  => 'GET',
                'callback' => array($this, 'serve'),
                // Mandatory since WP 5.5. The order-level check lives in
                // may_view(): a guest legitimately holds only the order key.
                'permission_callback' => '__return_true',
                'args' => array(
                    'order' => array(
                        'required' => true,
                        'validate_callback' => static function ($value) {
                            return (bool) absint($value);
                        },
                    ),
                ),
            )
        );
    }

    /** A same-domain URL for this order's invoice, or '' when there is none. */
    public static function url($order) {
        if (! $order->get_meta(ZATCA_Tools_Orders::META_UUID) || ! ZATCA_Tools_Settings::is_enabled()) {
            return '';
        }

        // The order key ALWAYS travels in the path — for the merchant too.
        // WordPress REST ignores cookies unless a nonce is present, so a plain
        // click from an admin screen arrives unauthenticated and a
        // capability-only URL would 403. The key authorises the request on its
        // own (exactly how WooCommerce's own order links work) and keeps the URL
        // free of query parameters, which is what the ad blocker matched on.
        $path = '/invoice/' . $order->get_id() . '/' . rawurlencode($order->get_order_key());

        return rest_url(self::NAMESPACE_V1 . $path);
    }

    /** @param WP_REST_Request $request */
    public function serve($request) {
        $order = wc_get_order(absint($request['order']));

        if (! $order) {
            return new WP_Error('zatca_not_found', __('Invoice not found.', 'e-invoicing-saudi-arabia-by-zatca-tools'), array('status' => 404));
        }

        if (! $this->may_view($order, (string) $request['key'])) {
            return new WP_Error('zatca_forbidden', __('You are not allowed to view this invoice.', 'e-invoicing-saudi-arabia-by-zatca-tools'), array('status' => 403));
        }

        $uuid = (string) $order->get_meta(ZATCA_Tools_Orders::META_UUID);
        if ('' === $uuid) {
            return new WP_Error('zatca_no_invoice', __('This order has no invoice yet.', 'e-invoicing-saudi-arabia-by-zatca-tools'), array('status' => 404));
        }

        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $pdf = $client->get_invoice_pdf($uuid);

        if ('' === $pdf) {
            return new WP_Error('zatca_unavailable', __('The invoice could not be fetched right now. Please try again in a moment.', 'e-invoicing-saudi-arabia-by-zatca-tools'), array('status' => 502));
        }

        $number = (string) $order->get_meta(ZATCA_Tools_Orders::META_NUMBER);
        $filename = ('' !== $number ? $number : 'invoice') . '.pdf';

        // The REST layer serialises JSON, so the PDF is written directly and the
        // request ends here.
        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdf));
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');

        echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF body.
        exit;
    }

    /** @param WC_Order $order */
    private function may_view($order, $key) {
        if (current_user_can('manage_woocommerce')) {
            return true;
        }

        // The order key is the secret WooCommerce itself trusts for guest access.
        if ('' !== $key && hash_equals($order->get_order_key(), $key)) {
            return true;
        }

        $user_id = get_current_user_id();

        return $user_id && (int) $order->get_customer_id() === $user_id;
    }
}
