<?php
/**
 * Maps a WooCommerce order to a ZATCA Tools API invoice payload.
 *
 * WooCommerce stores line totals NET (tax-exclusive) regardless of the store's
 * price-display setting, so — unlike other platforms — no inclusive→exclusive
 * conversion is needed. Our API applies 15% VAT itself.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Mapper {

    /** Our own buyer fields on the order (panel + checkout write these). */
    const VAT_META        = '_zatca_vat';
    const CR_META         = '_zatca_cr';
    const ADDRESS_META    = '_zatca_short_address'; // العنوان الوطني — RRRD2929
    const BUYER_TYPE_META = '_zatca_buyer_type';    // '' (auto) | b2b | b2c

    /** The same answers remembered on the customer's WordPress profile. */
    const USER_TYPE_META    = 'zatca_buyer_type';
    const USER_VAT_META     = 'zatca_vat';
    const USER_CR_META      = 'zatca_cr';
    const USER_ADDRESS_META = 'zatca_short_address';

    /** Common meta keys where merchants store a buyer VAT number. */
    const VAT_META_KEYS = array('_billing_vat', '_vat_number', '_billing_vat_number', 'billing_vat', 'vat_number', self::VAT_META);

    /** KSA VAT: a supply of SAR 1,000+ to a VAT-registered buyer needs a full tax invoice. */
    const B2B_THRESHOLD = 1000.0;

    /** Tolerance (SAR) when checking that the order really carries uniform 15% VAT. */
    const TOLERANCE = 0.05;

    /**
     * @return array{ok:bool, payload:array, skip:string, notice:string} Invoice
     *         payload, or a skip reason. `notice` is set when the invoice IS
     *         issued but not in the form the merchant aimed for — a warning to
     *         act on, never a reason to withhold the document.
     */
    public static function to_invoice($order) {
        if (strtoupper($order->get_currency()) !== 'SAR') {
            return array('ok' => false, 'payload' => array(), 'notice' => '', 'skip' => __('Order currency is not SAR — only Saudi Riyal is supported.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        // A store with WooCommerce taxes switched off never charged VAT, so a
        // tax invoice for it would invent tax the buyer never paid.
        if (! wc_tax_enabled()) {
            return array('ok' => false, 'payload' => array(), 'notice' => '', 'skip' => __('VAT is disabled in WooCommerce (WooCommerce → Settings → General → Enable taxes). No tax invoice can be issued for orders without VAT.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        $lines = array();

        foreach ($order->get_items() as $item) {
            $qty = (float) $item->get_quantity();
            $subtotal = (float) $item->get_subtotal(); // net, before discounts
            if ($qty <= 0 || $subtotal <= 0) {
                continue;
            }
            $lines[] = array(
                'name'       => wp_strip_all_tags($item->get_name()),
                'quantity'   => $qty,
                'unit_price' => round($subtotal / $qty, 2),
            );
        }

        // Shipping as its own line (net).
        $shipping = (float) $order->get_shipping_total();
        if ($shipping > 0) {
            $lines[] = array(
                'name'       => __('Shipping', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'quantity'   => 1,
                'unit_price' => round($shipping, 2),
            );
        }

        // Fees as their own lines (net).
        foreach ($order->get_fees() as $fee) {
            $fee_total = (float) $fee->get_total();
            if ($fee_total > 0) {
                $lines[] = array(
                    'name'       => wp_strip_all_tags($fee->get_name()),
                    'quantity'   => 1,
                    'unit_price' => round($fee_total, 2),
                );
            }
        }

        if (empty($lines)) {
            return array('ok' => false, 'payload' => array(), 'notice' => '', 'skip' => __('No billable line items', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        $tax = round((float) $order->get_total_tax(), 2);
        if ($tax <= 0) {
            return array('ok' => false, 'payload' => array(), 'notice' => '', 'skip' => __('This order carries no VAT (0%) — check that your products are assigned a taxable class and a 15% rate exists for Saudi Arabia.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        // What the customer actually paid, net of WooCommerce's own tax figure.
        // Deriving the discount from it (rather than trusting
        // get_total_discount(), which is tax-inclusive) makes the issued
        // document reconcile to the charge no matter how coupons were applied.
        $net_paid = round((float) $order->get_total() - $tax, 2);
        $line_total = 0.0;
        foreach ($lines as $line) {
            $line_total += round($line['quantity'] * $line['unit_price'], 2);
        }
        $line_total = round($line_total, 2);

        $discount = round($line_total - $net_paid, 2);
        if ($discount < 0) {
            $discount = 0.0;
        }
        if ($discount > $line_total) {
            $discount = $line_total;
        }

        // Uniform-15% guard: mixed or exempt rates (and unknown surcharges) must
        // never be flattened into a 15% invoice — the totals would disagree with
        // the money taken. Those orders are left for a manual invoice.
        $taxable = round($line_total - $discount, 2);
        if (abs(round($taxable * 0.15, 2) - $tax) > self::TOLERANCE) {
            return array(
                'ok' => false,
                'payload' => array(),
                'notice' => '',
                'skip' => sprintf(
                    /* translators: 1: VAT charged on the order, 2: VAT expected at 15% */
                    __('Order VAT (%1$s) is not a uniform 15%% of the taxable amount (%2$s expected) — exempt/zero-rated items or a different rate. Issue this invoice manually from zatcatools.com.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    number_format($tax, 2),
                    number_format(round($taxable * 0.15, 2), 2)
                ),
            );
        }

        $payload = array(
            'external_id' => 'wc-order-' . $order->get_id(),
            'lines'       => $lines,
            'discount'    => $discount,
            'issue_date'  => gmdate('Y-m-d'),
        );

        // Buyer email so the invoice PDF reaches the customer.
        $email = $order->get_billing_email();
        if (is_email($email)) {
            $payload['customer_email'] = $email;
        }

        /*
         * Which document?
         *
         * The AMOUNT decides, not the merchant's wish. KSA VAT Implementing
         * Regulations art. 53: a simplified tax invoice is permitted for a
         * supply below SAR 1,000 whoever the buyer is, and a supply of SAR 1,000
         * or more to a VAT-registered buyer must carry a full tax invoice. So
         * marking an order B2B means «aim for the tax invoice, and tell me if
         * you could not» — it cannot promote a 460-riyal sale.
         *
         * And nothing here ever withholds an invoice: a simplified invoice must
         * still reach ZATCA within 24 hours, so a shortfall is reported as a
         * NOTICE on an issued document — never as a missing one.
         */
        $type = self::buyer_type($order);
        $vat = 'b2c' === $type ? '' : self::find_vat($order);
        $has_vat = '' !== $vat && self::is_valid_vat($vat);
        $person = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $gross = round((float) $order->get_total(), 2);
        $notice = '';

        // A tax invoice is on the table only when the buyer is known to be an
        // establishment — the merchant said so, or the buyer gave a valid VAT.
        if ('b2b' === $type || $has_vat) {
            if ($gross >= self::B2B_THRESHOLD) {
                $customer = self::billing_customer($order, $vat);
                $missing = self::missing_for_standard($customer);

                if (empty($missing)) {
                    $payload['type'] = 'standard';
                    $payload['customer'] = $customer;

                    return array('ok' => true, 'payload' => $payload, 'skip' => '', 'notice' => '');
                }

                // The order earned a tax invoice but the buyer's details cannot
                // produce a valid one (ZATCA rejects a partial buyer address).
                // Issue the simplified invoice — the sale must be reported — and
                // say exactly what to complete.
                $notice = sprintf(
                    /* translators: %s: comma-separated list of missing buyer details */
                    __('Issued as a simplified invoice (B2C), not a tax invoice (B2B): the buyer is missing %s. A tax invoice needs the buyer’s VAT number and full national address — add it in the “ZATCA e-invoice” panel on this order.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    implode(', ', $missing)
                );
            } elseif ('b2b' === $type) {
                $notice = sprintf(
                    /* translators: %s: order total in SAR */
                    __('Marked as a business purchase, issued as a simplified invoice (B2C): the order total is %s SAR. Below SAR 1,000 a simplified invoice is permitted for any buyer, and the buyer’s VAT number is printed on it.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    number_format($gross, 2)
                );
            }
        }

        // Simplified. The buyer's VAT still prints when we have one, so the
        // establishment can identify the purchase in its own records.
        $payload['type'] = 'simplified';

        if ($has_vat) {
            $payload['customer'] = array(
                'name'       => self::billing_name($order) !== '' ? self::billing_name($order) : $person,
                'vat_number' => $vat,
            );
        } elseif ($person !== '') {
            $payload['customer'] = array('name' => $person);
        }

        return array('ok' => true, 'payload' => $payload, 'skip' => '', 'notice' => $notice);
    }

    /**
     * A refund → credit note payload against the order's invoice.
     *
     * @param WC_Order        $order
     * @param WC_Order_Refund $refund
     * @param string        $invoice_uuid The origin invoice uuid stored on the order.
     */
    public static function to_note($order, $refund, $invoice_uuid) {
        $lines = array();

        foreach ($refund->get_items() as $item) {
            $qty = abs((float) $item->get_quantity());
            $subtotal = abs((float) $item->get_subtotal());
            if ($qty <= 0 || $subtotal <= 0) {
                continue;
            }
            $lines[] = array(
                'name'       => wp_strip_all_tags($item->get_name()),
                'quantity'   => $qty,
                'unit_price' => round($subtotal / $qty, 2),
            );
        }

        $shipping = abs((float) $refund->get_shipping_total());
        if ($shipping > 0) {
            $lines[] = array(
                'name'       => __('Shipping refund', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'quantity'   => 1,
                'unit_price' => round($shipping, 2),
            );
        }

        // A refund with only an amount (no itemised lines) → single aggregate line.
        if (empty($lines)) {
            $amount = abs((float) $refund->get_amount());
            if ($amount <= 0) {
                return array('ok' => false, 'payload' => array(), 'notice' => '', 'skip' => 'Refund has no amount');
            }
            $net = round($amount / 1.15, 2);
            $lines[] = array('name' => __('Refund', 'e-invoicing-saudi-arabia-by-zatca-tools'), 'quantity' => 1, 'unit_price' => $net);
        }

        $reason = trim((string) $refund->get_reason());
        if ($reason === '') {
            /* translators: %s: order number */
            $reason = sprintf(__('Refund for order %s', 'e-invoicing-saudi-arabia-by-zatca-tools'), $order->get_order_number());
        }

        return array(
            'ok' => true,
            'payload' => array(
                'kind'         => 'credit',
                'invoice_uuid' => $invoice_uuid,
                'reason'       => $reason,
                'external_id'  => 'wc-refund-' . $refund->get_id(),
                'lines'        => $lines,
            ),
            'skip' => '',
        );
    }

    /**
     * Individual or establishment?
     *
     * The merchant's explicit answer on the order wins; then the default saved
     * on the customer's profile (so a returning business buyer is recognised
     * without anyone retyping anything); otherwise «auto» — the SAR 1,000 rule.
     *
     * @return string 'b2b' | 'b2c' | 'auto'
     */
    public static function buyer_type($order) {
        $on_order = self::normalise_type($order->get_meta(self::BUYER_TYPE_META));

        if ('auto' !== $on_order) {
            return $on_order;
        }

        return self::normalise_type(self::user_meta($order, self::USER_TYPE_META));
    }

    private static function normalise_type($value) {
        $value = strtolower(trim((string) $value));

        return ('b2b' === $value || 'b2c' === $value) ? $value : 'auto';
    }

    /** A value saved on the buyer's WordPress profile ('' for guest checkout). */
    private static function user_meta($order, $key) {
        if (! is_callable(array($order, 'get_customer_id')) || ! function_exists('get_user_meta')) {
            return '';
        }

        $user_id = (int) $order->get_customer_id();

        return $user_id > 0 ? (string) get_user_meta($user_id, $key, true) : '';
    }

    /**
     * What a standard (B2B) invoice still needs before it can be issued.
     *
     * ZATCA wants the buyer's full national address, but a merchant must never
     * be asked for a «building number» — that is our data model leaking onto
     * their screen. ONE national short address (RRRD2929) resolves the street,
     * building, city and postal code on our side, so that is what we ask for; a
     * billing address that already spells all four out is accepted too.
     *
     * @return array<string> Human-readable field names; empty when ready.
     */
    private static function missing_for_standard($customer) {
        $missing = array();

        if ('' === trim((string) $customer['name'])) {
            $missing[] = __('buyer name', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }
        if (! self::is_valid_vat($customer['vat_number'])) {
            $missing[] = __('VAT number (15 digits, starting and ending with 3)', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }
        if (! self::has_address($customer)) {
            $missing[] = __('national address (4 letters + 4 digits, e.g. RRRD2929)', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        return $missing;
    }

    /** A resolvable short address, or a billing address with all four parts. */
    private static function has_address($customer) {
        if (self::is_valid_short_address($customer['short_address'])) {
            return true;
        }

        foreach (array('street', 'building_number', 'city', 'postal_zone') as $part) {
            if ('' === trim((string) $customer[$part])) {
                return false;
            }
        }

        return true;
    }

    /** العنوان الوطني المختصر: four letters then four digits. */
    public static function is_valid_short_address($value) {
        return (bool) preg_match('/^[A-Z]{4}\d{4}$/', self::normalise_short_address($value));
    }

    public static function normalise_short_address($value) {
        return strtoupper(preg_replace('/\s+/', '', (string) $value));
    }

    private static function find_vat($order) {
        foreach (self::VAT_META_KEYS as $key) {
            $val = $order->get_meta($key);
            if ($val) {
                $digits = preg_replace('/\D/', '', (string) $val);
                if ($digits !== '') {
                    return $digits;
                }
            }
        }

        // A returning business buyer: the VAT saved on their profile.
        $saved = preg_replace('/\D/', '', self::user_meta($order, self::USER_VAT_META));
        if ('' !== $saved) {
            return $saved;
        }

        // Fall back to a VAT-looking number in the customer note.
        if (preg_match('/(?<!\d)(3\d{13}3)(?!\d)/', (string) $order->get_customer_note(), $m)) {
            return $m[1];
        }

        return '';
    }

    private static function is_valid_vat($vat) {
        return (bool) preg_match('/^3\d{13}3$/', $vat);
    }

    /** The establishment name if the buyer gave one, else the person's name. */
    private static function billing_name($order) {
        $name = trim($order->get_billing_company());

        return '' !== $name
            ? $name
            : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    }

    private static function billing_customer($order, $vat) {
        $name = self::billing_name($order);

        // The national short address: one field the merchant can actually ask a
        // buyer for. Our API resolves it into street/building/city/postal.
        $short = self::normalise_short_address($order->get_meta(self::ADDRESS_META));
        if (! self::is_valid_short_address($short)) {
            $short = self::normalise_short_address(self::user_meta($order, self::USER_ADDRESS_META));
        }

        // WooCommerce has no building-number field, so when no short address was
        // given, fall back to a standalone 4-digit token in the address lines.
        $building = '';
        $lines = trim($order->get_billing_address_2() . ' ' . $order->get_billing_address_1());
        if (preg_match('/(?<!\d)(\d{4})(?!\d)/', $lines, $m)) {
            $building = $m[1];
        }

        $cr = preg_replace('/\D/', '', (string) $order->get_meta(self::CR_META));
        if ('' === $cr) {
            $cr = preg_replace('/\D/', '', self::user_meta($order, self::USER_CR_META));
        }

        return array(
            'name'            => $name,
            'vat_number'      => $vat,
            'cr_number'       => $cr,
            'short_address'   => self::is_valid_short_address($short) ? $short : '',
            'street'          => $order->get_billing_address_1(),
            'building_number' => $building,
            'city'            => $order->get_billing_city(),
            'postal_zone'     => $order->get_billing_postcode(),
        );
    }
}
