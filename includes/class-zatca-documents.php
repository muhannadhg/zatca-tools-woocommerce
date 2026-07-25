<?php
/**
 * Puts OUR ZATCA invoice in front of the buyer everywhere WooCommerce would
 * otherwise show only its own order summary: the order emails, the thank-you
 * page, and My Account → order details.
 *
 * WooCommerce core has no tax invoice of its own — its emails are order
 * confirmations. This is what makes the compliant document the one the buyer
 * actually receives, instead of an order summary that ZATCA does not accept.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Documents {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Order emails (customer + admin): after the order table, before totals
        // notes — the invoice belongs with the money, not at the very bottom.
        add_action('woocommerce_email_after_order_table', array($this, 'email_block'), 10, 4);

        // Thank-you page and My Account → order details.
        add_action('woocommerce_thankyou', array($this, 'thankyou_block'), 15);
        add_action('woocommerce_view_order', array($this, 'thankyou_block'), 15);

        // The invoice number belongs in the order meta shown to the buyer.
        add_filter('woocommerce_get_order_item_totals', array($this, 'add_invoice_row'), 10, 3);
    }

    /** Issued invoice details for an order, or null. */
    private function invoice($order) {
        if ($order->get_meta(ZATCA_Tools_Orders::META_STATUS) !== 'issued') {
            return null;
        }

        $number = (string) $order->get_meta(ZATCA_Tools_Orders::META_NUMBER);
        $pdf = (string) ZATCA_Tools_Orders::instance()->invoice_link($order);

        if ($number === '' && $pdf === '') {
            return null;
        }

        return array('number' => $number, 'pdf' => $pdf);
    }

    /**
     * A row in the order totals table, so the tax invoice number travels with
     * every place WooCommerce prints the order (emails, account, admin).
     */
    public function add_invoice_row($rows, $order, $tax_display = '') {
        $invoice = $this->invoice($order);

        if ($invoice === null || $invoice['number'] === '') {
            return $rows;
        }

        $rows['zatca_invoice'] = array(
            'label' => __('Tax invoice no.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'value' => esc_html($invoice['number']),
        );

        return $rows;
    }

    /** @param WC_Order $order */
    public function email_block($order, $sent_to_admin, $plain_text, $email = null) {
        $invoice = $this->invoice($order);
        if ($invoice === null || $invoice['pdf'] === '') {
            return;
        }

        if ($plain_text) {
            echo "\n" . esc_html__('Your ZATCA tax invoice', 'e-invoicing-saudi-arabia-by-zatca-tools') . "\n";
            if ($invoice['number'] !== '') {
                echo esc_html($invoice['number']) . "\n";
            }
            echo esc_url_raw($invoice['pdf']) . "\n";

            return;
        }

        printf(
            '<div style="margin:16px 0;padding:14px 16px;border:1px solid #cfe8dd;background:#f2fbf6;border-radius:8px;">
                <p style="margin:0 0 8px;font-weight:600;color:#0a6b38;">%1$s</p>
                %2$s
                <p style="margin:0;"><a href="%3$s" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:9px 16px;border-radius:6px;font-weight:600;">%4$s</a></p>
                <p style="margin:8px 0 0;font-size:12px;color:#5b6660;">%5$s</p>
            </div>',
            esc_html__('Your ZATCA tax invoice is ready', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $invoice['number'] !== ''
                ? '<p style="margin:0 0 8px;font-family:monospace;">' . esc_html($invoice['number']) . '</p>'
                : '',
            esc_url($invoice['pdf']),
            esc_html__('View / download the tax invoice', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            esc_html__('A Phase 2 compliant e-invoice, signed and reported to the Zakat, Tax and Customs Authority.', 'e-invoicing-saudi-arabia-by-zatca-tools')
        );
    }

    /** @param int $order_id */
    public function thankyou_block($order_id) {
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $invoice = $this->invoice($order);
        if ($invoice === null || $invoice['pdf'] === '') {
            return;
        }

        printf(
            '<section class="woocommerce-zatca-invoice" style="margin:22px 0;padding:16px 18px;border:1px solid #cfe8dd;background:#f2fbf6;border-radius:10px;">
                <h2 style="margin:0 0 6px;font-size:1.1em;">%1$s</h2>
                %2$s
                <p style="margin:10px 0 0;"><a class="button" href="%3$s" target="_blank" rel="noopener">%4$s</a></p>
            </section>',
            esc_html__('Your tax invoice', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $invoice['number'] !== ''
                ? '<p style="margin:0;font-family:monospace;">' . esc_html($invoice['number']) . '</p>'
                : '',
            esc_url($invoice['pdf']),
            esc_html__('View / download the tax invoice', 'e-invoicing-saudi-arabia-by-zatca-tools')
        );
    }
}
