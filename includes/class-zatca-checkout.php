<?php
/**
 * Checkout: let a business buyer supply their VAT number so they receive a
 * proper tax invoice.
 *
 * KSA VAT: a supply of SAR 1,000 or more to a VAT-registered buyer must be
 * documented with a full tax invoice, so at that total the field is presented
 * prominently and becomes mandatory once the buyer says the purchase is for a
 * business. Consumers are never blocked.
 *
 * This is the storefront half of buyer identity; ZATCA_Tools_Buyer owns the
 * admin half (the order panel and the customer's profile) and the meta keys
 * live on ZATCA_Tools_Mapper, which is what reads them.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Checkout {

    const FIELD_TOGGLE  = 'zatca_is_business';
    const FIELD_VAT     = 'zatca_vat';
    const FIELD_CR      = 'zatca_cr';
    const FIELD_ADDRESS = 'zatca_short_address';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Classic checkout: render after the billing form so it sits with the
        // buyer's own details rather than among shipping fields.
        add_action('woocommerce_after_checkout_billing_form', array($this, 'render'));
        add_action('woocommerce_checkout_process', array($this, 'validate'));
        add_action('woocommerce_checkout_create_order', array($this, 'save'), 10, 2);

        // Show it wherever WooCommerce prints the billing address. Editing lives
        // in the ZATCA panel on the order (see ZATCA_Tools_Buyer).
        add_filter('woocommerce_order_get_formatted_billing_address', array($this, 'append_to_address'), 10, 3);
    }

    /** SAR 1,000+ carts get the prominent treatment. */
    private function is_large_cart() {
        if (! function_exists('WC') || ! WC()->cart) {
            return false;
        }

        return (float) WC()->cart->get_total('edit') >= ZATCA_Tools_Mapper::B2B_THRESHOLD;
    }

    public function render($checkout) {
        if (! ZATCA_Tools_Settings::is_enabled()) {
            return;
        }

        $big = $this->is_large_cart();

        // A returning business buyer should not retype what they already gave
        // us (or what the merchant saved on their profile).
        $saved = ZATCA_Tools_Buyer::saved_defaults(get_current_user_id());
        $vat_value = $checkout->get_value(self::FIELD_VAT);
        $vat_value = ('' === $vat_value || null === $vat_value) ? $saved['vat'] : $vat_value;
        $cr_value = $checkout->get_value(self::FIELD_CR);
        $cr_value = ('' === $cr_value || null === $cr_value) ? $saved['cr'] : $cr_value;
        $address_value = $checkout->get_value(self::FIELD_ADDRESS);
        $address_value = ('' === $address_value || null === $address_value) ? $saved['address'] : $address_value;
        $toggle_value = $checkout->get_value(self::FIELD_TOGGLE);
        if (null === $toggle_value || '' === $toggle_value) {
            $toggle_value = ('b2b' === $saved['type'] || '' !== $saved['vat']) ? 1 : '';
        }

        echo '<div id="zatca-tools-vat" class="zatca-tools-vat"' . ($big ? ' style="border:1.5px solid #0f766e;background:#f0fdfa;border-radius:12px;padding:14px 16px;margin:14px 0;"' : ' style="margin:14px 0;"') . '>';

        if ($big) {
            echo '<p style="margin:0 0 6px;font-weight:700;">'
                . esc_html__('Is this purchase for a business? (VAT invoice in your company name)', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</p><p style="margin:0 0 10px;font-size:12px;color:#4b5f5d;">'
                . esc_html__('This order is SAR 1,000 or more. If you are buying for a VAT-registered business, enter its VAT number to receive a full tax invoice.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</p>';
        }

        woocommerce_form_field(self::FIELD_TOGGLE, array(
            'type'  => 'checkbox',
            'class' => array('form-row-wide'),
            'label' => __('Buying for a business (VAT invoice)', 'e-invoicing-saudi-arabia-by-zatca-tools'),
        ), $toggle_value);

        woocommerce_form_field(self::FIELD_VAT, array(
            'type'        => 'text',
            'class'       => array('form-row-wide', 'zatca-biz-field'),
            'label'       => __('VAT number', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'placeholder' => '3XXXXXXXXXXXXX3',
            'description' => __('15 digits, starts and ends with 3', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'maxlength'   => 15,
        ), $vat_value);

        // The national address earns the buyer a FULL tax invoice on orders of
        // SAR 1,000+ — street, building number, city and postal code all resolve
        // from it, so this one field replaces asking them for an address.
        woocommerce_form_field(self::FIELD_ADDRESS, array(
            'type'        => 'text',
            'class'       => array('form-row-wide', 'zatca-biz-field'),
            'label'       => __('National address (optional)', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'placeholder' => 'RRRD2929',
            'description' => __('4 letters + 4 digits — needed for a full tax invoice on orders of SAR 1,000 or more', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'maxlength'   => 8,
        ), $address_value);

        woocommerce_form_field(self::FIELD_CR, array(
            'type'  => 'text',
            'class' => array('form-row-wide', 'zatca-biz-field'),
            'label' => __('Commercial registration (optional)', 'e-invoicing-saudi-arabia-by-zatca-tools'),
        ), $cr_value);

        echo '</div>';

        // Reveal the business fields only when the toggle is on — no build step,
        // no dependency on the theme's scripts.
        $toggle = esc_js('#' . self::FIELD_TOGGLE);
        ?>
        <script>
        (function () {
            var box = document.getElementById('zatca-tools-vat');
            if (!box) return;
            var toggle = box.querySelector('<?php echo $toggle; // phpcs:ignore WordPress.Security.EscapeOutput ?>');
            var fields = box.querySelectorAll('.zatca-biz-field');
            function sync() {
                var on = toggle && toggle.checked;
                fields.forEach(function (f) { f.style.display = on ? '' : 'none'; });
            }
            if (toggle) toggle.addEventListener('change', sync);
            sync();
        })();
        </script>
        <?php
    }

    /** A business buyer must give a well-formed VAT — otherwise checkout stops. */
    public function validate() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
        $wants_business = ! empty($_POST[self::FIELD_TOGGLE]);
        $vat = isset($_POST[self::FIELD_VAT]) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST[self::FIELD_VAT]))) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! $wants_business) {
            return;
        }

        if (! preg_match('/^3\d{13}3$/', $vat)) {
            wc_add_notice(
                __('Enter your establishment\'s VAT number (15 digits, starting and ending with 3) to receive a tax invoice — or uncheck “Buying for a business”.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'error'
            );
        }
    }

    public function save($order, $data) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before this hook.
        if (empty($_POST[self::FIELD_TOGGLE])) {
            return;
        }

        $vat = isset($_POST[self::FIELD_VAT]) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST[self::FIELD_VAT]))) : '';
        $cr  = isset($_POST[self::FIELD_CR]) ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST[self::FIELD_CR]))) : '';
        $address = isset($_POST[self::FIELD_ADDRESS])
            ? ZATCA_Tools_Mapper::normalise_short_address(sanitize_text_field(wp_unslash($_POST[self::FIELD_ADDRESS])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if (! preg_match('/^3\d{13}3$/', $vat)) {
            $vat = '';
        }
        if (! ZATCA_Tools_Mapper::is_valid_short_address($address)) {
            $address = '';
        }

        if ('' !== $vat) {
            $order->update_meta_data(ZATCA_Tools_Mapper::VAT_META, $vat);
        }
        if ($cr !== '') {
            $order->update_meta_data(ZATCA_Tools_Mapper::CR_META, $cr);
        }
        if ('' !== $address) {
            $order->update_meta_data(ZATCA_Tools_Mapper::ADDRESS_META, $address);
        }

        // Keep it on their profile too, so their next order is prefilled and
        // the merchant can see who buys as an establishment.
        ZATCA_Tools_Buyer::remember($order->get_customer_id(), $vat, $cr, $address);
    }

    /** Print the buyer's VAT under the billing address wherever Woo shows it. */
    public function append_to_address($address, $raw_address, $order) {
        $vat = $order->get_meta(ZATCA_Tools_Mapper::VAT_META);

        if ($vat) {
            $address .= '<br>' . esc_html__('VAT number', 'e-invoicing-saudi-arabia-by-zatca-tools') . ': ' . esc_html($vat);
        }

        return $address;
    }
}
