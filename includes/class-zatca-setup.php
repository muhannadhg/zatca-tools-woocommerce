<?php
/**
 * Two-way setup help, so a merchant never types the same thing twice.
 *
 *  - WooCommerce → ZATCA Tools: a new merchant starts registration with their
 *    store name, email and address already filled in from WooCommerce.
 *  - ZATCA Tools → WooCommerce: a store whose VAT is not configured gets it
 *    configured in one click (taxes on + a 15% Saudi rate), and a connected
 *    store can pull its establishment address back from ZATCA Tools.
 *
 * Anything that would change the MEANING of existing data is never silent —
 * see apply_tax_settings() on the price-inclusive setting.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Setup {

    const KSA_RATE = 15.0;

    /* ------------------------------------------- WooCommerce → ZATCA Tools */

    /**
     * What WooCommerce already knows about this business.
     *
     * @return array<string,string>
     */
    public static function store_profile() {
        $country_state = (string) get_option('woocommerce_default_country', '');
        $country = strtoupper(substr($country_state, 0, 2));

        return array(
            'name'     => trim((string) get_bloginfo('name')),
            'email'    => trim((string) get_option('admin_email', '')),
            'street'   => trim((string) get_option('woocommerce_store_address', '') . ' ' . get_option('woocommerce_store_address_2', '')),
            'city'     => trim((string) get_option('woocommerce_store_city', '')),
            'postcode' => preg_replace('/\D/', '', (string) get_option('woocommerce_store_postcode', '')),
            'country'  => $country,
            'site'     => home_url(),
        );
    }

    /**
     * Our sign-in/registration page, carrying what we already know so the
     * merchant only fills what WooCommerce cannot tell us (the VAT number and
     * the national short address).
     */
    public static function signup_url() {
        $profile = self::store_profile();

        return add_query_arg(
            array_filter(array(
                'from'     => 'woocommerce',
                'email'    => $profile['email'],
                'name'     => $profile['name'],
                'street'   => $profile['street'],
                'city'     => $profile['city'],
                'postcode' => $profile['postcode'],
            )),
            'https://zatcatools.com/login'
        );
    }

    /* ------------------------------------------- ZATCA Tools → WooCommerce */

    /**
     * Turn WooCommerce VAT on and make sure a 15% Saudi rate exists.
     *
     * Deliberately additive. `woocommerce_prices_include_tax` is NOT touched:
     * flipping it re-interprets every existing product price (a 115 SAR product
     * silently becomes 100 + VAT, or the reverse), so that stays the merchant's
     * explicit decision — the dashboard shows the current value and explains it.
     *
     * @return array{changed:array<string>, already:array<string>}
     */
    public static function apply_tax_settings() {
        $changed = array();
        $already = array();

        if ('yes' !== get_option('woocommerce_calc_taxes')) {
            update_option('woocommerce_calc_taxes', 'yes');
            $changed[] = __('Enabled taxes in WooCommerce', 'e-invoicing-saudi-arabia-by-zatca-tools');
        } else {
            $already[] = __('Taxes were already enabled', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        if (self::has_ksa_rate()) {
            $already[] = __('A 15% Saudi VAT rate already exists', 'e-invoicing-saudi-arabia-by-zatca-tools');
        } else {
            WC_Tax::_insert_tax_rate(array(
                'tax_rate_country'  => 'SA',
                'tax_rate_state'    => '',
                'tax_rate'          => '15.0000',
                'tax_rate_name'     => __('VAT', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                'tax_rate_priority' => 1,
                'tax_rate_compound' => 0,
                'tax_rate_shipping' => 1, // shipping is taxable in KSA
                'tax_rate_order'    => 0,
                'tax_rate_class'    => '',
            ));
            $changed[] = __('Added a 15% VAT rate for Saudi Arabia (standard class, applied to shipping too)', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        // Shipping should follow the standard class so it carries VAT.
        if ('' !== get_option('woocommerce_shipping_tax_class', '')) {
            update_option('woocommerce_shipping_tax_class', '');
            $changed[] = __('Set shipping to use the standard tax class', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        // A single-country Saudi store should tax from its own base address.
        if ('base' !== get_option('woocommerce_tax_based_on', 'shipping')) {
            update_option('woocommerce_tax_based_on', 'base');
            $changed[] = __('Set VAT to be calculated from the shop base address', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        return array('changed' => $changed, 'already' => $already);
    }

    /** Does a 15% rate that a Saudi order would match already exist? */
    public static function has_ksa_rate() {
        $rates = WC_Tax::get_rates_for_tax_class('');

        foreach ((array) $rates as $rate) {
            $country = isset($rate->tax_rate_country) ? strtoupper($rate->tax_rate_country) : '';
            $percent = isset($rate->tax_rate) ? (float) $rate->tax_rate : 0.0;

            if (abs($percent - self::KSA_RATE) < 0.01 && ('' === $country || 'SA' === $country)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill the WooCommerce store address from the establishment registered with
     * ZATCA Tools — the mirror of the sign-up prefill, for a merchant who set
     * their details up with us first. Only fills what WooCommerce left empty.
     *
     * @return array<string> Human-readable list of what was filled.
     */
    public static function pull_address_from_account() {
        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $res = $client->account();

        if (! $res['ok']) {
            return array();
        }

        $body = $res['body'];
        $address = isset($body['address']) && is_array($body['address']) ? $body['address'] : array();
        $filled = array();

        $map = array(
            'woocommerce_store_address'  => isset($address['street']) ? $address['street'] : '',
            'woocommerce_store_city'     => isset($address['city']) ? $address['city'] : '',
            'woocommerce_store_postcode' => isset($address['postal_zone']) ? $address['postal_zone'] : '',
        );

        foreach ($map as $option => $value) {
            $value = trim((string) $value);
            if ('' !== $value && '' === trim((string) get_option($option, ''))) {
                update_option($option, $value);
                $filled[] = $option;
            }
        }

        // A Saudi establishment implies a Saudi shop base.
        if ('' === trim((string) get_option('woocommerce_default_country', ''))) {
            update_option('woocommerce_default_country', 'SA');
            $filled[] = 'woocommerce_default_country';
        }

        return $filled;
    }
}
