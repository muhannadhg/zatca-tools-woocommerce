<?php
/**
 * The ZATCA Tools dashboard inside WP admin — the WordPress counterpart of the
 * embedded panels we ship for Salla, Shopify and Zid.
 *
 * It answers the three questions a merchant actually has: is my store wired up
 * correctly (especially VAT), how many invoices went out, and where is the
 * invoice for a given order. The VAT health check is the important part: with
 * WooCommerce taxes off or a missing 15% rate, no compliant invoice can be
 * issued at all, so we say exactly what to fix and where.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Admin_Page {

    const SLUG = 'zatca-tools';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_post_zatca_tools_retry', array($this, 'handle_retry'));
        add_action('admin_post_zatca_tools_fix_tax', array($this, 'handle_fix_tax'));
        add_action('admin_post_zatca_tools_email', array($this, 'handle_email'));
        add_action('admin_post_zatca_tools_pull_address', array($this, 'handle_pull_address'));
        add_action('admin_notices', array($this, 'tax_notice'));
    }

    public function add_menu() {
        add_menu_page(
            __('ZATCA Tools', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            __('ZATCA Tools', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            'manage_woocommerce',
            self::SLUG,
            array($this, 'render'),
            'dashicons-media-spreadsheet',
            56
        );
    }

    /**
     * Which meta holds a given document — the invoice, or the credit/debit note
     * that reversed or reinstated it. Anything unrecognised is the invoice.
     *
     * @return array{0:string,1:string} [uuid meta, number meta]
     */
    private static function document_meta($doc) {
        if ('credit' === $doc) {
            return array(ZATCA_Tools_Orders::META_CREDIT_UUID, ZATCA_Tools_Orders::META_CREDIT_NUMBER);
        }
        if ('debit' === $doc) {
            return array(ZATCA_Tools_Orders::META_DEBIT_UUID, ZATCA_Tools_Orders::META_DEBIT_NUMBER);
        }

        return array(ZATCA_Tools_Orders::META_UUID, ZATCA_Tools_Orders::META_NUMBER);
    }

    /* ------------------------------------------------------------ VAT health */

    /**
     * Is the store capable of producing a compliant invoice at all?
     *
     * @return array{ok:bool, problems:array<string>}
     */
    public static function tax_health() {
        $problems = array();

        if (! wc_tax_enabled()) {
            $problems[] = __('VAT is switched off in WooCommerce → Settings → General → “Enable taxes”. Without it orders carry no VAT and no tax invoice can be issued.', 'e-invoicing-saudi-arabia-by-zatca-tools');

            return array('ok' => false, 'problems' => $problems);
        }

        // Is there a 15% rate that a Saudi order would actually match?
        $has_15 = false;
        foreach (WC_Tax::get_rates_for_tax_class('') as $rate) {
            $country = isset($rate->tax_rate_country) ? strtoupper($rate->tax_rate_country) : '';
            $percent = isset($rate->tax_rate) ? (float) $rate->tax_rate : 0.0;

            if (abs($percent - 15.0) < 0.01 && ('' === $country || 'SA' === $country)) {
                $has_15 = true;
                break;
            }
        }

        if (! $has_15) {
            $problems[] = __('No 15% VAT rate for Saudi Arabia was found in the standard tax class (WooCommerce → Settings → Tax → Standard rates). Add a rate with Country = SA and Rate = 15.', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        if ('SA' !== strtoupper(WC()->countries->get_base_country())) {
            $problems[] = __('Your store base country is not Saudi Arabia — Saudi VAT rules may not apply to your orders.', 'e-invoicing-saudi-arabia-by-zatca-tools');
        }

        return array('ok' => empty($problems), 'problems' => $problems);
    }

    /** A store that cannot issue invoices should hear about it, not wonder. */
    public function tax_notice() {
        if (! ZATCA_Tools_Settings::is_enabled() || ! current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && false !== strpos((string) $screen->id, self::SLUG)) {
            return; // the page itself already shows the detail
        }

        $health = self::tax_health();
        if ($health['ok']) {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button" href="%s">%s</a></p></div>',
            esc_html__('ZATCA Tools:', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            esc_html($health['problems'][0]),
            esc_url(admin_url('admin.php?page=' . self::SLUG)),
            esc_html__('Review e-invoicing status', 'e-invoicing-saudi-arabia-by-zatca-tools')
        );
    }

    /* ----------------------------------------------------------------- retry */

    /** Re-run invoicing for one order (a failed or skipped attempt). */
    public function handle_retry() {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer('zatca_tools_retry_' . $order_id);

        if ($order_id) {
            $order = wc_get_order($order_id);

            // An invoiced order whose reversal trail is behind its status: the
            // missing document is a credit note (cancelled) or a debit note
            // (reinstated) — never a second invoice.
            $reversal = $order ? (string) $order->get_meta(ZATCA_Tools_Orders::META_REVERSAL) : '';
            $invoiced = $order && $order->get_meta(ZATCA_Tools_Orders::META_UUID);

            if ($invoiced && 'credited' !== $reversal && $order->has_status(array('cancelled', 'refunded'))) {
                ZATCA_Tools_Orders::instance()->run_cancel_note($order_id);
            } elseif ($invoiced && 'credited' === $reversal && ! $order->has_status(array('cancelled', 'refunded'))) {
                ZATCA_Tools_Orders::instance()->run_restore_note($order_id);
            } elseif ($invoiced) {
                // Already invoiced → re-read it (repairs a stale/unauthenticated
                // link) rather than attempting to issue a second document.
                ZATCA_Tools_Orders::instance()->refresh_invoice($order_id);
            } else {
                if ($order) {
                    // Clear the previous outcome so the run is not short-circuited.
                    $order->delete_meta_data(ZATCA_Tools_Orders::META_STATUS);
                    $order->delete_meta_data(ZATCA_Tools_Orders::META_ERROR);
                    $order->save();
                }
                ZATCA_Tools_Orders::instance()->run_invoice($order_id);
            }
        }

        $back = wp_get_referer();
        wp_safe_redirect($back ? $back : admin_url('admin.php?page=' . self::SLUG . '&retried=1'));
        exit;
    }

    /**
     * Email the invoice to the account owner.
     *
     * The guaranteed path: the mail is sent by the server, so no ad blocker,
     * extension or iframe sandbox is involved at all.
     */
    public function handle_email() {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }

        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        check_admin_referer('zatca_tools_email_' . $order_id);

        $order = $order_id ? wc_get_order($order_id) : null;
        // Whichever document the merchant was looking at.
        list($uuid_meta) = self::document_meta(isset($_GET['doc']) ? sanitize_key(wp_unslash($_GET['doc'])) : 'invoice');
        $uuid = $order ? (string) $order->get_meta($uuid_meta) : '';
        $sent = false;

        if ('' !== $uuid) {
            $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
            $res = $client->email_invoice($uuid);
            $sent = ! empty($res['ok']);
        }

        set_transient('zatca_tools_email_result', $sent ? 'ok' : 'fail', 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&emailed=1'));
        exit;
    }

    /** One click: switch WooCommerce VAT on and add the 15% Saudi rate. */
    public function handle_fix_tax() {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }
        check_admin_referer('zatca_tools_fix_tax');

        $result = ZATCA_Tools_Setup::apply_tax_settings();
        set_transient('zatca_tools_setup_result', $result, 60);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&taxfixed=1'));
        exit;
    }

    /** The mirror: fill an empty WooCommerce store address from our account. */
    public function handle_pull_address() {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'e-invoicing-saudi-arabia-by-zatca-tools'));
        }
        check_admin_referer('zatca_tools_pull_address');

        $filled = ZATCA_Tools_Setup::pull_address_from_account();
        set_transient('zatca_tools_address_result', count($filled), 60);

        wp_safe_redirect(admin_url('admin.php?page=' . self::SLUG . '&addressfilled=1'));
        exit;
    }

    /* ------------------------------------------------------------------ view */

    public function render() {
        $health = self::tax_health();
        $key_status = get_option(ZATCA_Tools_Settings::OPTION_STATUS, '');
        $connected = $key_status !== '' && 0 !== strpos($key_status, 'error:');
        $orders = $this->recent_orders();
        $issued = $this->issued_count();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('ZATCA e-invoicing', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</h1>';

        // The running build, stated plainly: after a plugin update PHP opcache
        // can keep serving the old files, and then nothing behaves as expected.
        // Seeing the version here settles that in one look.
        echo '<p style="margin:4px 0 0;color:#666;font-size:12px;">'
            . esc_html(sprintf(
                /* translators: %s: plugin version */
                __('Plugin version %s', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                ZATCA_TOOLS_VERSION
            ))
            . '</p>';

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags after a nonce-checked action
        if (isset($_GET['retried'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__('Retry finished — see the order status below.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p></div>';
        }

        if (isset($_GET['taxfixed'])) {
            $result = get_transient('zatca_tools_setup_result');
            delete_transient('zatca_tools_setup_result');
            echo '<div class="notice notice-success is-dismissible"><p><strong>'
                . esc_html__('VAT setup applied.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</strong></p><ul style="margin:6px 0 0 20px;list-style:disc;">';
            foreach (array_merge(
                isset($result['changed']) ? $result['changed'] : array(),
                isset($result['already']) ? $result['already'] : array()
            ) as $line) {
                echo '<li>' . esc_html($line) . '</li>';
            }
            echo '</ul></div>';
        }

        if (isset($_GET['emailed'])) {
            $ok = 'ok' === get_transient('zatca_tools_email_result');
            delete_transient('zatca_tools_email_result');
            printf(
                '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
                $ok ? 'notice-success' : 'notice-error',
                esc_html($ok
                    ? __('The invoice was emailed to your ZATCA Tools account address.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    : __('The invoice could not be emailed — please try again in a moment.', 'e-invoicing-saudi-arabia-by-zatca-tools'))
            );
        }

        if (isset($_GET['addressfilled'])) {
            $count = (int) get_transient('zatca_tools_address_result');
            delete_transient('zatca_tools_address_result');
            echo '<div class="notice notice-success is-dismissible"><p>'
                . esc_html($count > 0
                    ? __('Your store address was filled in from your ZATCA Tools establishment.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    : __('Nothing to fill — your WooCommerce store address is already set.', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                . '</p></div>';
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        // A merchant with no account yet: start registration with everything
        // WooCommerce already knows, so they only add the VAT number.
        if (! $connected) {
            $profile = ZATCA_Tools_Setup::store_profile();
            echo '<div class="notice notice-info inline" style="margin:0 0 18px;padding:14px 16px;">'
                . '<p style="margin:0 0 6px;"><strong>' . esc_html__('No ZATCA Tools account yet?', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</strong> '
                . esc_html__('Create one in a minute — we carry your store details over so you only add your VAT number.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>'
                . '<p style="margin:0 0 10px;color:#555;font-size:12px;">'
                . esc_html(sprintf(
                    /* translators: 1: store name, 2: admin email */
                    __('We will pass: %1$s · %2$s · your store address. You can edit everything before saving.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                    $profile['name'],
                    $profile['email']
                ))
                . '</p>'
                . '<p style="margin:0;"><a class="button button-primary" target="_blank" rel="noopener" href="' . esc_url(ZATCA_Tools_Setup::signup_url()) . '">'
                . esc_html__('Create my account (details prefilled)', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p></div>';
        }

        /* ---- status cards ---- */
        echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin:18px 0;">';
        $this->card(
            __('Connection', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $connected ? esc_html($key_status) : __('Not connected', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $connected,
            $connected ? '' : admin_url('admin.php?page=wc-settings&tab=zatca_tools')
        );
        $this->card(
            __('VAT setup', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $health['ok'] ? __('15% VAT configured', 'e-invoicing-saudi-arabia-by-zatca-tools') : __('Needs attention', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $health['ok'],
            $health['ok'] ? '' : admin_url('admin.php?page=wc-settings&tab=tax')
        );
        $this->card(
            __('Invoices issued', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            (string) $issued,
            true,
            ''
        );
        echo '</div>';

        /* ---- VAT problems, spelled out ---- */
        if (! $health['ok']) {
            echo '<div class="notice notice-error inline" style="margin:0 0 18px;padding:12px 14px;"><p><strong>'
                . esc_html__('Automatic invoicing is blocked until VAT is set up correctly:', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</strong></p><ol style="margin:6px 0 0 20px;">';
            foreach ($health['problems'] as $problem) {
                echo '<li>' . esc_html($problem) . '</li>';
            }
            $fix_url = wp_nonce_url(admin_url('admin-post.php?action=zatca_tools_fix_tax'), 'zatca_tools_fix_tax');
            echo '</ol><p style="margin-top:10px;">'
                . '<a class="button button-primary" href="' . esc_url($fix_url) . '">'
                . esc_html__('Set VAT up for me', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a> '
                . '<a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=tax')) . '">'
                . esc_html__('Do it myself', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p>'
                . '<p style="margin:8px 0 0;font-size:12px;color:#555;">'
                . esc_html__('“Set VAT up for me” switches taxes on and adds a 15% Saudi rate (applied to shipping too). It never changes whether your entered prices include tax — that would re-interpret every product price, so it stays your decision.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</p></div>';
        }

        // How prices are entered decides what the buyer is charged, so show it
        // plainly rather than changing it behind the merchant's back.
        if ($health['ok']) {
            $inclusive = 'yes' === get_option('woocommerce_prices_include_tax');
            echo '<p style="margin:-4px 0 18px;color:#555;font-size:12.5px;">'
                . esc_html($inclusive
                    ? __('Your product prices are entered INCLUDING VAT — invoices show the price broken down into net + 15%.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    : __('Your product prices are entered EXCLUDING VAT — 15% is added at checkout and shown on the invoice.', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                . ' <a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=tax')) . '">'
                . esc_html__('Change', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p>';
        }

        // The mirror of the sign-up prefill: pull the establishment address we
        // already hold into an empty WooCommerce store address.
        if ($connected && '' === trim((string) get_option('woocommerce_store_address', ''))) {
            $pull_url = wp_nonce_url(admin_url('admin-post.php?action=zatca_tools_pull_address'), 'zatca_tools_pull_address');
            echo '<div class="notice notice-info inline" style="margin:0 0 18px;padding:12px 14px;"><p style="margin:0 0 8px;">'
                . esc_html__('Your WooCommerce store address is empty. Fill it from the establishment registered with ZATCA Tools?', 'e-invoicing-saudi-arabia-by-zatca-tools')
                . '</p><p style="margin:0;"><a class="button" href="' . esc_url($pull_url) . '">'
                . esc_html__('Fill my store address', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p></div>';
        }

        /* ---- inline invoice view (nothing for a blocker to intercept) ---- */
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view of the merchant's own order, capability-gated by the page itself.
        $view_id = isset($_GET['view']) ? absint($_GET['view']) : 0;
        $doc = isset($_GET['doc']) ? sanitize_key(wp_unslash($_GET['doc'])) : 'invoice';
        $doc = in_array($doc, array('credit', 'debit'), true) ? $doc : 'invoice';
        if ($view_id) {
            $this->render_inline_invoice($view_id, $doc);
        }

        /* ---- recent orders ---- */

        if (empty($orders)) {
            echo '<p>' . esc_html__('No paid orders yet — the first one is invoiced automatically.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr>'
                . '<th>' . esc_html__('Order', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</th>'
                . '<th>' . esc_html__('Total', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</th>'
                . '<th>' . esc_html__('Buyer', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</th>'
                . '<th>' . esc_html__('Invoice', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</th>'
                . '<th>' . esc_html__('Status', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</th>'
                . '<th></th></tr></thead><tbody>';

            foreach ($orders as $order) {
                $status = $order->get_meta(ZATCA_Tools_Orders::META_STATUS);
                $number = $order->get_meta(ZATCA_Tools_Orders::META_NUMBER);
                $error  = $order->get_meta(ZATCA_Tools_Orders::META_ERROR);

                echo '<tr><td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a></td>';
                echo '<td>' . wp_kses_post($order->get_formatted_order_total()) . '</td>';
                $credit = (string) $order->get_meta(ZATCA_Tools_Orders::META_CREDIT_NUMBER);
                $debit = (string) $order->get_meta(ZATCA_Tools_Orders::META_DEBIT_NUMBER);
                $reversal = (string) $order->get_meta(ZATCA_Tools_Orders::META_REVERSAL);

                echo '<td>' . esc_html(ZATCA_Tools_Buyer::badge($order)) . '</td>';
                echo '<td><code>' . esc_html($number ? $number : '—') . '</code>';
                if ('' !== $credit) {
                    echo '<div style="margin-top:3px;font-size:11px;color:#8a6d00;">'
                        . esc_html__('Credit note', 'e-invoicing-saudi-arabia-by-zatca-tools')
                        . ' <code>' . esc_html($credit) . '</code></div>';
                }
                if ('' !== $debit) {
                    echo '<div style="margin-top:3px;font-size:11px;color:#0a6b38;">'
                        . esc_html__('Debit note', 'e-invoicing-saudi-arabia-by-zatca-tools')
                        . ' <code>' . esc_html($debit) . '</code></div>';
                }
                if ('restored' === $reversal) {
                    echo '<div style="margin-top:2px;font-size:11px;color:#666;">'
                        . esc_html__('reinstated — the invoice is due again', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</div>';
                }
                echo '</td>';
                echo '<td>' . $this->status_html($status, $error, (string) $order->get_meta(ZATCA_Tools_Orders::META_NOTICE)) . '</td>';
                echo '<td style="white-space:nowrap">';
                if ('issued' === $status) {
                    $view = admin_url('admin.php?page=' . self::SLUG . '&view=' . $order->get_id());
                    echo '<a class="button button-small" href="' . esc_url($view) . '">'
                        . esc_html__('Invoice', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a> ';
                }
                foreach (array('credit' => $credit, 'debit' => $debit) as $doc => $note_number) {
                    if ('' === $note_number) {
                        continue;
                    }
                    $view_note = admin_url('admin.php?page=' . self::SLUG . '&doc=' . $doc . '&view=' . $order->get_id());
                    echo '<a class="button button-small" href="' . esc_url($view_note) . '">'
                        . esc_html('credit' === $doc
                            ? __('Credit note', 'e-invoicing-saudi-arabia-by-zatca-tools')
                            : __('Debit note', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                        . '</a> ';
                }
                $url = wp_nonce_url(
                    admin_url('admin-post.php?action=zatca_tools_retry&order_id=' . $order->get_id()),
                    'zatca_tools_retry_' . $order->get_id()
                );
                if ('issued' !== $status) {
                    echo '<a class="button button-small" href="' . esc_url($url) . '">'
                        . esc_html('' === $status
                            ? __('Issue now', 'e-invoicing-saudi-arabia-by-zatca-tools')
                            : __('Retry', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                        . '</a>';
                }
                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '<p style="margin-top:18px;color:#666;">'
            . sprintf(
                /* translators: %s: dashboard URL */
                wp_kses_post(__('All invoices, credit notes and reports live in your <a href="%s" target="_blank" rel="noopener">ZATCA Tools dashboard</a>.', 'e-invoicing-saudi-arabia-by-zatca-tools')),
                'https://zatcatools.com/invoices'
            )
            . '</p>';

        echo '</div>';
    }

    /**
     * Print the invoice INTO this page.
     *
     * The browser makes no request for a document: WordPress fetches the HTML
     * server-to-server and it is written into a srcdoc iframe. That is what
     * survives aggressive ad blockers, which block any invoice URL — ours or
     * the store's own — on sight. Printing from here produces the same document.
     */
    private function render_inline_invoice($order_id, $doc = 'invoice') {
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        list($uuid_meta, $number_meta) = self::document_meta($doc);
        $uuid = (string) $order->get_meta($uuid_meta);

        if ('' === $uuid) {
            return;
        }

        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $html = $client->get_invoice_html($uuid);
        $number = (string) $order->get_meta($number_meta);

        echo '<div style="margin:18px 0;border:1px solid #dcdcde;border-radius:8px;background:#fff;overflow:hidden;">';
        echo '<div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #dcdcde;">';
        echo '<strong>' . esc_html('' !== $number ? $number : __('Invoice', 'e-invoicing-saudi-arabia-by-zatca-tools')) . '</strong>';
        if ('credit' === $doc) {
            echo ' <span style="font-size:11px;font-weight:600;color:#8a6d00;">'
                . esc_html__('Credit note — reverses this order’s invoice', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>';
        } elseif ('debit' === $doc) {
            echo ' <span style="font-size:11px;font-weight:600;color:#0a6b38;">'
                . esc_html__('Debit note — the invoice is due again', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>';
        }
        echo '<span style="flex:1"></span>';

        if ('' !== $html) {
            echo '<button type="button" class="button button-primary" id="zatca-print">'
                . esc_html__('Print / Save as PDF', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</button> ';
        }

        $email_url = wp_nonce_url(
            admin_url('admin-post.php?action=zatca_tools_email&order_id=' . $order_id . '&doc=' . $doc),
            'zatca_tools_email_' . $order_id
        );
        echo '<a class="button" href="' . esc_url($email_url) . '">'
            . esc_html__('Email it to me', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">'
            . esc_html__('Close', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a>';
        echo '</div>';

        if ('' === $html) {
            echo '<p style="padding:16px;">' . esc_html__('The invoice could not be loaded right now. Use “Email it to me” to receive it instead.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
        } else {
            // The frame keeps the invoice's own CSS out of the admin page. Its
            // content is WRITTEN into the document rather than passed as srcdoc:
            // Chrome prints a srcdoc frame as a blank page, while a written
            // document prints properly. Printing the frame also prints the
            // invoice alone, without the admin chrome around it.
            echo '<iframe id="zatca-inv-frame" style="width:100%;height:1100px;border:0;background:#fff;"></iframe>';
            printf(
                '<script>(function(){
                    var html = %s;
                    var f = document.getElementById("zatca-inv-frame");
                    var d = f.contentWindow.document;
                    d.open(); d.write(html); d.close();
                    var b = document.getElementById("zatca-print");
                    if (b) b.addEventListener("click", function () {
                        f.contentWindow.focus();
                        f.contentWindow.print();
                    });
                })();</script>',
                wp_json_encode($html)
            );
        }

        echo '</div>';
    }

    private function card($title, $value, $ok, $action_url) {
        echo '<div style="flex:1;min-width:220px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;">';
        echo '<div style="font-size:12px;color:#666;">' . esc_html($title) . '</div>';
        echo '<div style="font-size:16px;font-weight:600;margin-top:4px;color:' . ($ok ? '#0a6b38' : '#b42318') . ';">'
            . ($ok ? '&#10003; ' : '&#9888; ') . esc_html($value) . '</div>';
        if ($action_url) {
            echo '<div style="margin-top:8px;"><a class="button button-small" href="' . esc_url($action_url) . '">'
                . esc_html__('Fix', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></div>';
        }
        echo '</div>';
    }

    private function status_html($status, $error, $notice = '') {
        if ('issued' === $status) {
            // Issued, but not as the tax invoice the order aimed for: the
            // merchant has to see that here, not only on the order screen.
            return '<span style="color:#0a6b38;font-weight:600;">' . esc_html__('Issued', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>'
                . ('' !== $notice ? '<br><span style="font-size:11px;color:#8a6d00;">' . esc_html($notice) . '</span>' : '');
        }
        if ('failed' === $status) {
            return '<span style="color:#b42318;font-weight:600;">' . esc_html__('Failed', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>'
                . ($error ? '<br><span style="font-size:11px;color:#666;">' . esc_html($error) . '</span>' : '');
        }
        if ('skipped' === $status) {
            return '<span style="color:#8a6d00;font-weight:600;">' . esc_html__('Not invoiced', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>'
                . ($error ? '<br><span style="font-size:11px;color:#666;">' . esc_html($error) . '</span>' : '');
        }

        return '<span style="color:#666;">' . esc_html__('Pending', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</span>';
    }

    /**
     * @return WC_Order[]
     *
     * Cancelled and refunded orders belong here too. An order that vanishes from
     * this table the moment it is cancelled is the worst possible answer: the
     * invoice was reported to ZATCA and reversed with a credit note, and this is
     * where the merchant looks for that document.
     */
    private function recent_orders() {
        $orders = wc_get_orders(array(
            'limit'   => 15,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => array('wc-processing', 'wc-completed', 'wc-refunded', 'wc-cancelled', 'wc-on-hold'),
        ));

        return is_array($orders) ? $orders : array();
    }

    private function issued_count() {
        $orders = wc_get_orders(array(
            'limit'      => -1,
            'return'     => 'ids',
            'meta_key'   => ZATCA_Tools_Orders::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => 'issued',                        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        ));

        return is_array($orders) ? count($orders) : 0;
    }
}
