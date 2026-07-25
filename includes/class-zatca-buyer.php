<?php
/**
 * Buyer identity: is this sale to an individual or to an establishment, and
 * what are that establishment's details?
 *
 * WooCommerce has no concept of a tax-registered buyer, so the three answers
 * ZATCA needs — buyer type, VAT number, commercial registration — are captured
 * in the two places a merchant looks: on the order, and on the customer's
 * profile, so a returning business buyer is recognised without retyping.
 *
 * Why these are not WooCommerce "billing fields": a field added through the
 * woocommerce_admin_billing_fields filter is SAVED to its own meta id but
 * RENDERED from `_billing_{key}` — so it always displays empty, whatever is
 * stored. Owning the panel keeps what is saved and what is shown the same
 * thing, and lets it say what will actually be issued.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Buyer {

    const NONCE  = 'zatca_tools_buyer_nonce';
    const ACTION = 'zatca_tools_buyer';

    /** Form inputs on the order screen. */
    const IN_TYPE    = 'zatca_buyer_type';
    const IN_VAT     = 'zatca_buyer_vat';
    const IN_CR      = 'zatca_buyer_cr';
    const IN_ADDRESS = 'zatca_buyer_address';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Priority 50: after WooCommerce has saved its own order data (40), so
        // our meta is never overwritten by a stale order instance.
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order_fields'), 50, 1);

        // Remembered on the customer, for every future order.
        add_filter('woocommerce_customer_meta_fields', array($this, 'customer_meta_fields'));
    }

    /* ------------------------------------------------------------- the panel */

    /**
     * The buyer block inside the "ZATCA e-invoice" panel on the order screen.
     *
     * Before invoicing it is editable and states what will be issued; once an
     * invoice exists it becomes a record of what WAS issued — changing the
     * inputs then would only misdescribe a document that is already signed and
     * reported (a real change needs a credit note).
     */
    public static function render_order_panel($order) {
        $vat = (string) $order->get_meta(ZATCA_Tools_Mapper::VAT_META);

        // Anything with an invoice is a record, not a form — and the invoice may
        // predate us storing which form was issued, so only state what we know.
        if ('' !== (string) $order->get_meta(ZATCA_Tools_Orders::META_UUID)) {
            $issued_form = (string) $order->get_meta(ZATCA_Tools_Orders::META_FORM);
            $notice = (string) $order->get_meta(ZATCA_Tools_Orders::META_NOTICE);

            if ('' === $issued_form && '' === $vat && '' === $notice) {
                return;
            }

            echo '<hr style="margin:14px 0 12px;">';

            if ('' !== $issued_form) {
                echo '<p style="margin:0;font-size:12px;color:#555;">'
                    . esc_html__('Issued as', 'e-invoicing-saudi-arabia-by-zatca-tools') . ': <strong>'
                    . esc_html(self::form_label($issued_form)) . '</strong></p>';
            }

            if ('' !== $vat) {
                echo '<p style="margin:4px 0 0;font-size:12px;color:#555;">'
                    . esc_html__('Buyer VAT', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    . ': <code>' . esc_html($vat) . '</code></p>';
            }

            // Why it is not a tax invoice — so the next order can be.
            if ('' !== $notice) {
                echo '<p style="margin:8px 0 0;padding:8px 10px;border-radius:6px;background:#fffbeb;color:#78350f;font-size:12px;">'
                    . esc_html($notice) . '</p>';
            }

            return;
        }

        echo '<hr style="margin:14px 0 12px;">';

        $stored = strtolower(trim((string) $order->get_meta(ZATCA_Tools_Mapper::BUYER_TYPE_META)));
        $stored = in_array($stored, array('b2b', 'b2c'), true) ? $stored : '';

        wp_nonce_field(self::ACTION, self::NONCE);

        echo '<div class="zatca-buyer">';
        echo '<p style="margin:0 0 4px;font-weight:600;">'
            . esc_html__('Buyer', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';

        echo '<p style="margin:0 0 10px;"><label for="' . esc_attr(self::IN_TYPE) . '" style="display:block;font-size:12px;color:#555;margin-bottom:2px;">'
            . esc_html__('Buyer type', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</label>';
        echo '<select name="' . esc_attr(self::IN_TYPE) . '" id="' . esc_attr(self::IN_TYPE) . '" style="width:100%;">';
        foreach (self::type_options() as $value => $label) {
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($value),
                selected($stored, $value, false),
                esc_html($label)
            );
        }
        echo '</select></p>';

        // Only two things are ever required, and only for an establishment: who
        // they are for tax purposes, and where they are. The national address is
        // the ONE address field a Saudi merchant can ask a buyer for — street,
        // building number, city and postal code all resolve from it on our side.
        self::text_input(self::IN_VAT, __('VAT number', 'e-invoicing-saudi-arabia-by-zatca-tools'), $vat, '3XXXXXXXXXXXXX3', true);
        self::text_input(
            self::IN_ADDRESS,
            __('National address', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            (string) $order->get_meta(ZATCA_Tools_Mapper::ADDRESS_META),
            'RRRD2929',
            true
        );
        self::text_input(self::IN_CR, __('Commercial registration', 'e-invoicing-saudi-arabia-by-zatca-tools'), (string) $order->get_meta(ZATCA_Tools_Mapper::CR_META), '1010101010');

        echo '<p style="margin:0 0 10px;font-size:11px;color:#666;">'
            . esc_html__('Fields marked * are needed for a tax invoice. Saved with the order.', 'e-invoicing-saudi-arabia-by-zatca-tools')
            . '</p>';

        self::render_preview($order);
        echo '</div>';

        self::render_requirement_script();
    }

    /**
     * Mark what the chosen buyer type actually requires.
     *
     * Required fields turn red while empty and carry a *; everything else says
     * «optional» outright, so a merchant is never guessing. Deliberately NOT
     * html-required: blocking Update would trap someone mid-edit, and an
     * incomplete B2B order is not an error — it issues a simplified invoice
     * with the reason stated.
     */
    private static function render_requirement_script() {
        $optional = esc_js(__('(optional)', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        ?>
        <script>
        (function () {
            var box = document.querySelector('.zatca-buyer');
            var select = document.getElementById('<?php echo esc_js(self::IN_TYPE); ?>');
            if (!box || !select) return;

            var rows = box.querySelectorAll('p[data-zatca-req]');
            function sync() {
                var b2b = select.value === 'b2b';
                rows.forEach(function (row) {
                    var needed = b2b && row.getAttribute('data-zatca-req') === 'b2b';
                    var flag = row.querySelector('.zatca-flag');
                    var input = row.querySelector('input');
                    if (flag) {
                        flag.textContent = needed ? '*' : '<?php echo $optional; // phpcs:ignore WordPress.Security.EscapeOutput ?>';
                        flag.style.color = needed ? '#b32d2e' : '#787c82';
                    }
                    if (input) {
                        var empty = input.value.trim() === '';
                        input.style.borderColor = needed && empty ? '#b32d2e' : '';
                        input.style.boxShadow = needed && empty ? '0 0 0 1px #b32d2e' : '';
                    }
                });
            }
            select.addEventListener('change', sync);
            box.querySelectorAll('input').forEach(function (input) { input.addEventListener('input', sync); });
            sync();
        })();
        </script>
        <?php
    }

    /**
     * What this order will produce, decided by the very same mapper that will
     * run — so the panel can never promise something different from what the
     * job does. A read with no side effects.
     */
    private static function render_preview($order) {
        // An order with nothing in it yet has nothing to preview — saying "no
        // billable line items" to someone still building the order is noise.
        if (0 === count($order->get_items())) {
            return;
        }

        $preview = ZATCA_Tools_Mapper::to_invoice($order);

        if (! empty($preview['ok'])) {
            echo '<p style="margin:0;padding:8px 10px;border-radius:6px;background:#f0fdf4;color:#14532d;font-size:12px;">'
                . esc_html__('Will be issued as', 'e-invoicing-saudi-arabia-by-zatca-tools') . ': <strong>'
                . esc_html(self::form_label($preview['payload']['type'])) . '</strong></p>';

            // Aimed at a tax invoice and cannot get there yet — say why now,
            // while it can still be fixed before the document is signed.
            if (! empty($preview['notice'])) {
                echo '<p style="margin:6px 0 0;padding:8px 10px;border-radius:6px;background:#fffbeb;color:#78350f;font-size:12px;">'
                    . esc_html($preview['notice']) . '</p>';
            }

            return;
        }

        echo '<p style="margin:0;padding:8px 10px;border-radius:6px;background:#fffbeb;color:#78350f;font-size:12px;">'
            . esc_html($preview['skip']) . '</p>';
    }

    /** @param bool $needed_for_b2b Required once the buyer is an establishment. */
    private static function text_input($name, $label, $value, $placeholder, $needed_for_b2b = false) {
        printf(
            '<p data-zatca-req="%5$s" style="margin:0 0 10px;">'
            . '<label for="%1$s" style="display:block;font-size:12px;color:#555;margin-bottom:2px;">%2$s '
            . '<span class="zatca-flag" style="font-weight:600;"></span></label>'
            . '<input type="text" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s" style="width:100%%;"></p>',
            esc_attr($name),
            esc_html($label),
            esc_attr($value),
            esc_attr($placeholder),
            $needed_for_b2b ? 'b2b' : 'never'
        );
    }

    /* -------------------------------------------------------------- the save */

    public function save_order_fields($order_id) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified on the next line.
        $nonce = isset($_POST[self::NONCE]) ? sanitize_key(wp_unslash($_POST[self::NONCE])) : '';
        if ('' === $nonce || ! wp_verify_nonce($nonce, self::ACTION)) {
            return;
        }

        if (! current_user_can('edit_shop_orders')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $type = isset($_POST[self::IN_TYPE]) ? sanitize_key(wp_unslash($_POST[self::IN_TYPE])) : '';
        $order->update_meta_data(
            ZATCA_Tools_Mapper::BUYER_TYPE_META,
            in_array($type, array('b2b', 'b2c'), true) ? $type : ''
        );

        $numeric = array(
            self::IN_VAT => ZATCA_Tools_Mapper::VAT_META,
            self::IN_CR  => ZATCA_Tools_Mapper::CR_META,
        );

        foreach ($numeric as $input => $meta) {
            $digits = isset($_POST[$input])
                ? preg_replace('/\D/', '', sanitize_text_field(wp_unslash($_POST[$input])))
                : '';

            // An emptied field must actually clear: leaving the old value would
            // put a VAT number on the invoice the merchant just removed.
            if ('' === $digits) {
                $order->delete_meta_data($meta);
            } else {
                $order->update_meta_data($meta, $digits);
            }
        }

        // The national address is letters AND digits (RRRD2929) — never stripped
        // to digits like the numbers above.
        $address = isset($_POST[self::IN_ADDRESS])
            ? ZATCA_Tools_Mapper::normalise_short_address(sanitize_text_field(wp_unslash($_POST[self::IN_ADDRESS])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ('' === $address) {
            $order->delete_meta_data(ZATCA_Tools_Mapper::ADDRESS_META);
        } else {
            $order->update_meta_data(ZATCA_Tools_Mapper::ADDRESS_META, $address);
        }

        $order->save();
    }

    /* ---------------------------------------------------- customer's profile */

    /**
     * The same three answers on the customer's own profile — set once, applied
     * to every future order (an individual order can still override).
     */
    public function customer_meta_fields($fields) {
        $fields['zatca_tools'] = array(
            'title'  => __('ZATCA e-invoicing', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'fields' => array(
                ZATCA_Tools_Mapper::USER_TYPE_META => array(
                    'label'       => __('Invoice type', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    'description' => __('Applied to this customer’s orders unless the order itself says otherwise.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    'type'        => 'select',
                    'options'     => self::type_options(),
                ),
                ZATCA_Tools_Mapper::USER_VAT_META => array(
                    'label'       => __('VAT number', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    'description' => __('15 digits, starting and ending with 3.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                ),
                ZATCA_Tools_Mapper::USER_ADDRESS_META => array(
                    'label'       => __('National address', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    'description' => __('العنوان الوطني — 4 letters + 4 digits, e.g. RRRD2929. The street, building number, city and postal code a tax invoice needs are resolved from it.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                ),
                ZATCA_Tools_Mapper::USER_CR_META => array(
                    'label'       => __('Commercial registration', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    'description' => __('Optional — printed on the tax invoice as an extra buyer identifier.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                ),
            ),
        );

        return $fields;
    }

    /* -------------------------------------------------------------- labelling */

    /**
     * @return array<string,string> Value → label, '' meaning «decide for me».
     *
     * The B2B label carries the threshold on purpose: marking a buyer as an
     * establishment cannot turn a 460-riyal sale into a tax invoice, and a
     * label that implied otherwise would be a promise we then break.
     */
    public static function type_options() {
        return array(
            ''    => __('Not specified — decide from the order (recommended)', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'b2b' => __('Establishment (B2B) — tax invoice from SAR 1,000', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'b2c' => __('Individual (B2C) — simplified invoice', 'e-invoicing-saudi-arabia-by-zatca-tools'),
        );
    }

    private static function form_label($form) {
        return 'standard' === $form
            ? __('Tax invoice (B2B)', 'e-invoicing-saudi-arabia-by-zatca-tools')
            : __('Simplified invoice (B2C)', 'e-invoicing-saudi-arabia-by-zatca-tools');
    }

    /** A short badge for a list: what was issued, or what is set. */
    public static function badge($order) {
        $issued = (string) $order->get_meta(ZATCA_Tools_Orders::META_FORM);
        if ('' !== $issued) {
            return 'standard' === $issued ? 'B2B' : 'B2C';
        }

        $type = ZATCA_Tools_Mapper::buyer_type($order);

        return 'auto' === $type ? '—' : strtoupper($type);
    }

    /** What a returning buyer already gave us, for the checkout form. */
    public static function saved_defaults($user_id) {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array('type' => '', 'vat' => '', 'cr' => '', 'address' => '');
        }

        return array(
            'type'    => (string) get_user_meta($user_id, ZATCA_Tools_Mapper::USER_TYPE_META, true),
            'vat'     => (string) get_user_meta($user_id, ZATCA_Tools_Mapper::USER_VAT_META, true),
            'cr'      => (string) get_user_meta($user_id, ZATCA_Tools_Mapper::USER_CR_META, true),
            'address' => (string) get_user_meta($user_id, ZATCA_Tools_Mapper::USER_ADDRESS_META, true),
        );
    }

    /** Remember what a buyer typed at checkout, so they never type it twice. */
    public static function remember($user_id, $vat, $cr, $address = '') {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        foreach (array(
            ZATCA_Tools_Mapper::USER_VAT_META     => $vat,
            ZATCA_Tools_Mapper::USER_CR_META      => $cr,
            ZATCA_Tools_Mapper::USER_ADDRESS_META => $address,
        ) as $key => $value) {
            if ('' !== $value) {
                update_user_meta($user_id, $key, $value);
            }
        }
    }
}
