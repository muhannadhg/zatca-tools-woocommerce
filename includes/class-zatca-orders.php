<?php
/**
 * Order lifecycle: issue invoices on payment, credit notes on refund, and
 * surface the result on the order screen.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Orders {

    const META_UUID   = '_zatca_invoice_uuid';
    const META_NUMBER = '_zatca_invoice_number';
    const META_STATUS = '_zatca_invoice_status'; // issued | failed | skipped
    const META_ERROR  = '_zatca_invoice_error';
    const META_PDF    = '_zatca_invoice_pdf';
    const META_FORM   = '_zatca_invoice_form';   // standard (B2B) | simplified (B2C)
    const META_NOTICE = '_zatca_invoice_notice'; // issued, but not as a tax invoice — why

    /**
     * Reversing and re-instating an invoice.
     *
     * An issued invoice is signed and reported: it can never be deleted or
     * edited. So cancelling the order writes a CREDIT note that reverses it,
     * and putting that order back to a paid status writes a DEBIT note that
     * restores the same amount. The pair can repeat, so the state and the cycle
     * are tracked — each cycle needs its own idempotency key, or the second
     * cancellation would silently return the first credit note.
     */
    const META_CREDIT_UUID   = '_zatca_credit_uuid';
    const META_CREDIT_NUMBER = '_zatca_credit_number';
    const META_DEBIT_UUID    = '_zatca_debit_uuid';
    const META_DEBIT_NUMBER  = '_zatca_debit_number';
    const META_REVERSAL      = '_zatca_reversal';       // '' | credited | restored
    const META_CYCLE         = '_zatca_reversal_cycle'; // 1, 2, 3…

    const HOOK_INVOICE = 'zatca_tools_issue_invoice';
    const HOOK_NOTE    = 'zatca_tools_issue_note';
    const HOOK_CANCEL  = 'zatca_tools_issue_cancel_note';
    const HOOK_RESTORE = 'zatca_tools_issue_restore_note';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Fire once payment is complete (covers most gateways) and on the
        // processing/completed transitions for gateways that skip it.
        add_action('woocommerce_payment_complete', array($this, 'schedule_invoice'));
        add_action('woocommerce_order_status_completed', array($this, 'schedule_invoice'));
        add_action('woocommerce_order_status_processing', array($this, 'schedule_invoice'));

        // Refund → credit note.
        add_action('woocommerce_order_refunded', array($this, 'schedule_note'), 10, 2);

        // Cancelled (or marked refunded with no refund recorded) → a FULL credit
        // note. An invoice that ZATCA has already accepted cannot be deleted, so
        // «cancelling» an order means issuing the document that reverses it.
        add_action('woocommerce_order_status_cancelled', array($this, 'schedule_cancel_note'));
        add_action('woocommerce_order_status_refunded', array($this, 'schedule_cancel_note'));

        // Background workers (Action Scheduler ships with WooCommerce).
        add_action(self::HOOK_INVOICE, array($this, 'run_invoice'));
        add_action(self::HOOK_NOTE, array($this, 'run_note'), 10, 2);
        add_action(self::HOOK_CANCEL, array($this, 'run_cancel_note'));
        add_action(self::HOOK_RESTORE, array($this, 'run_restore_note'));

        // Order screen: a panel with the invoice status + links.
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
    }

    /* ---------------------------------------------------------------- issue */

    public function schedule_invoice($order_id) {
        if (! ZATCA_Tools_Settings::is_enabled()) {
            return;
        }
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        if ($order->get_meta(self::META_UUID)) {
            // Already invoiced. If that invoice was reversed by a credit note and
            // the order is live again, the amount is re-established with a debit
            // note — never with a second invoice.
            if ('credited' === $order->get_meta(self::META_REVERSAL)) {
                $this->schedule_restore_note($order_id);
            }

            return;
        }

        // Defer so checkout is never blocked by a ZATCA round-trip.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_INVOICE, array('order_id' => $order_id), 'e-invoicing-saudi-arabia-by-zatca-tools');
        } else {
            $this->run_invoice($order_id);
        }
    }

    public function run_invoice($order_id) {
        $order = wc_get_order($order_id);
        if (! $order || $order->get_meta(self::META_UUID)) {
            return;
        }

        // Cancelled between payment and this job running: never report a sale
        // that was undone. (A cancellation AFTER invoicing is reversed with a
        // credit note instead — see schedule_cancel_note.)
        if ($order->has_status(array('cancelled', 'refunded', 'failed'))) {
            $this->mark($order, 'skipped', array(self::META_ERROR => __('The order was cancelled before it was invoiced, so nothing was reported to ZATCA.', 'e-invoicing-saudi-arabia-by-zatca-tools')));

            return;
        }

        $mapped = ZATCA_Tools_Mapper::to_invoice($order);
        if (! $mapped['ok']) {
            $this->mark($order, 'skipped', array(self::META_ERROR => $mapped['skip']));
            return;
        }

        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $res = $client->create_invoice($mapped['payload'], $mapped['payload']['external_id']);

        // The server could not build a valid tax invoice from this buyer (a
        // national address that does not resolve, most often). Never leave the
        // sale uninvoiced over it: issue the simplified invoice and carry the
        // reason. Nothing was created by the refused request, so this is safe.
        if (! $res['ok'] && 'customer_incomplete' === (string) $res['code'] && 'standard' === $mapped['payload']['type']) {
            $mapped['payload']['type'] = 'simplified';
            $mapped['notice'] = __('Issued as a simplified invoice (B2C), not a tax invoice (B2B): the buyer’s national address could not be verified. Check it in the “ZATCA e-invoice” panel on this order.', 'e-invoicing-saudi-arabia-by-zatca-tools');

            $res = $client->create_invoice($mapped['payload'], $mapped['payload']['external_id']);
        }

        if (! $res['ok']) {
            $this->mark($order, 'failed', array(self::META_ERROR => $res['error']));
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('ZATCA Tools: invoice failed — %s', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                $res['error']
            ));
            return;
        }

        $body = $res['body'];

        // Store the BROWSER-openable link, never links.pdf: that one is an API
        // endpoint and answers 401 without a Bearer key, so the merchant (and
        // worse, the buyer receiving it by email) would just see an auth error.
        // links.view carries its own per-invoice token and opens anywhere.
        $link = '';
        if (isset($body['links']['view'])) {
            $link = $body['links']['view'];
        } elseif (isset($body['uuid'])) {
            $link = 'https://zatcatools.com/invoices'; // pre-`view` server: send them to the dashboard
        }

        // Record WHICH document was issued: the buyer panel and the dashboard
        // then report the fact rather than re-deriving a rule that may have
        // been changed since.
        $form = isset($body['type']) ? (string) $body['type'] : (string) $mapped['payload']['type'];
        $notice = isset($mapped['notice']) ? (string) $mapped['notice'] : '';

        $this->mark($order, 'issued', array(
            self::META_UUID   => isset($body['uuid']) ? $body['uuid'] : '',
            self::META_NUMBER => isset($body['number']) ? $body['number'] : '',
            self::META_PDF    => $link,
            self::META_FORM   => $form,
            self::META_NOTICE => $notice,
            self::META_ERROR  => '',
        ));
        $order->add_order_note(sprintf(
            /* translators: 1: invoice number, 2: ZATCA status */
            __('ZATCA Tools: invoice %1$s issued (%2$s).', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            isset($body['number']) ? $body['number'] : '',
            isset($body['status']) ? $body['status'] : ''
        ));

        // A tax invoice was aimed for and not produced: that belongs in the
        // order's own history, not only in our panel.
        if ('' !== $notice) {
            $order->add_order_note('ZATCA Tools: ' . $notice);
        }
    }

    /**
     * The invoice link to show a human, healing it in place if an older build
     * stored the API endpoint (which answers 401 in a browser).
     *
     * @return string Empty when there is nothing to link to.
     */
    public function invoice_link($order) {
        // Prefer OUR OWN domain: a link to zatcatools.com is a third-party
        // document URL, which ad blockers and privacy extensions block outright
        // (ERR_BLOCKED_BY_CLIENT) — the store proxies the PDF instead.
        $same_domain = ZATCA_Tools_Invoice_File::url($order);
        if ('' !== $same_domain) {
            return $same_domain;
        }

        $link = (string) $order->get_meta(self::META_PDF);

        if ($link !== '' && false === strpos($link, '/api/')) {
            return $link;
        }

        if ($order->get_meta(self::META_UUID) && ZATCA_Tools_Settings::is_enabled() && $this->refresh_invoice($order->get_id())) {
            $fresh = wc_get_order($order->get_id());

            return $fresh ? (string) $fresh->get_meta(self::META_PDF) : '';
        }

        // An unusable API link is worse than none — it only shows an auth error.
        return false === strpos($link, '/api/') ? $link : '';
    }

    /**
     * Re-read an already-issued invoice from the server and refresh what we
     * cached on the order (number, link). Never re-issues — a read only.
     *
     * @return bool True when the stored link changed.
     */
    public function refresh_invoice($order_id) {
        $order = wc_get_order($order_id);
        if (! $order) {
            return false;
        }

        $uuid = $order->get_meta(self::META_UUID);
        if (! $uuid) {
            return false;
        }

        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $res = $client->get_invoice($uuid);

        if (! $res['ok'] || empty($res['body']['links']['view'])) {
            return false;
        }

        $body = $res['body'];
        $this->mark($order, 'issued', array(
            self::META_NUMBER => isset($body['number']) ? $body['number'] : $order->get_meta(self::META_NUMBER),
            self::META_PDF    => $body['links']['view'],
            self::META_ERROR  => '',
        ));

        return true;
    }

    /* --------------------------------------------------------------- refund */

    public function schedule_note($order_id, $refund_id) {
        if (! ZATCA_Tools_Settings::is_enabled()) {
            return;
        }
        $order = wc_get_order($order_id);
        if (! $order || ! $order->get_meta(self::META_UUID)) {
            return; // no invoice to credit
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::HOOK_NOTE, array('order_id' => $order_id, 'refund_id' => $refund_id), 'e-invoicing-saudi-arabia-by-zatca-tools');
        } else {
            $this->run_note($order_id, $refund_id);
        }
    }

    /* ------------------------------------------- cancelled / re-instated */

    /**
     * A cancelled order that we already invoiced → a CREDIT note.
     *
     * The invoice is signed and reported; deleting it is not a thing that
     * exists. An order cancelled BEFORE it was invoiced needs nothing at all.
     */
    public function schedule_cancel_note($order_id) {
        $this->schedule_reversal($order_id, 'credit');
    }

    /**
     * A cancelled order put back to a paid status → a DEBIT note.
     *
     * The credit note that reversed the invoice is itself reported and final,
     * so the amount is re-established by adding it back, not by removing the
     * reversal or issuing a second invoice.
     */
    public function schedule_restore_note($order_id) {
        $this->schedule_reversal($order_id, 'debit');
    }

    /** @param string $kind credit (cancel) | debit (re-instate) */
    private function schedule_reversal($order_id, $kind) {
        if (! ZATCA_Tools_Settings::is_enabled()) {
            return;
        }

        $order = wc_get_order($order_id);
        if (! $order || ! $order->get_meta(self::META_UUID)) {
            return; // nothing was issued, so there is nothing to reverse
        }

        // Each direction runs once per cycle: credited → restored → credited…
        $state = (string) $order->get_meta(self::META_REVERSAL);
        if (('credit' === $kind) === ('credited' === $state)) {
            return;
        }

        $hook = 'credit' === $kind ? self::HOOK_CANCEL : self::HOOK_RESTORE;

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action($hook, array('order_id' => $order_id), 'e-invoicing-saudi-arabia-by-zatca-tools');
        } elseif ('credit' === $kind) {
            $this->run_cancel_note($order_id);
        } else {
            $this->run_restore_note($order_id);
        }
    }

    public function run_cancel_note($order_id) {
        $this->run_reversal($order_id, 'credit');
    }

    public function run_restore_note($order_id) {
        $this->run_reversal($order_id, 'debit');
    }

    /**
     * Issue the note that reverses (credit) or re-instates (debit) the invoice.
     *
     * `full` lets the SERVER work out the amount: it knows the earlier partial
     * refunds, so a credit can never breach the Art. 40 ceiling and a debit
     * restores exactly what was written off — no arithmetic here to drift.
     */
    private function run_reversal($order_id, $kind) {
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $invoice_uuid = (string) $order->get_meta(self::META_UUID);
        $state = (string) $order->get_meta(self::META_REVERSAL);
        if ('' === $invoice_uuid || ('credit' === $kind) === ('credited' === $state)) {
            return;
        }

        $credit = 'credit' === $kind;
        $cycle = max(1, (int) $order->get_meta(self::META_CYCLE));
        $external_id = ($credit ? 'wc-cancel-' : 'wc-restore-') . $order->get_id() . '-' . $cycle;

        // Restoring undoes OUR cancellation note by name, never «everything ever
        // credited» — otherwise an earlier partial refund would be resurrected
        // with it, billing the buyer VAT on goods they had returned.
        $reverses = $credit ? '' : (string) $order->get_meta(self::META_CREDIT_UUID);

        if (! $credit && '' === $reverses) {
            $order->update_meta_data(self::META_REVERSAL, 'restored');
            $order->update_meta_data(self::META_CYCLE, $cycle + 1);
            $order->save();
            $order->add_order_note(__('ZATCA Tools: this order has no credit note of ours to reverse, so nothing was issued.', 'e-invoicing-saudi-arabia-by-zatca-tools'));

            return;
        }

        $payload = array(
            'kind'         => $kind,
            'invoice_uuid' => $invoice_uuid,
            'external_id'  => $external_id,
            'reason'       => sprintf(
                $credit
                    /* translators: %s: order number */
                    ? __('Order %s cancelled', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    /* translators: %s: order number */
                    : __('Order %s reinstated after cancellation', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                $order->get_order_number()
            ),
        );

        if ($credit) {
            // The server computes the open balance: it knows the earlier partial
            // refunds, so a credit can never breach the Art. 40 ceiling.
            $payload['full'] = true;
        } else {
            $payload['reverses'] = $reverses;
        }

        $res = (new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key()))->create_note($payload, $external_id);

        if (! $res['ok']) {
            // Nothing left to move: refund notes already covered the invoice, or
            // there was no credited amount to give back. The books are correct.
            if (in_array((string) $res['code'], array('nothing_to_credit', 'nothing_to_restore'), true)) {
                $order->update_meta_data(self::META_REVERSAL, $credit ? 'credited' : 'restored');
                if (! $credit) {
                    $order->update_meta_data(self::META_CYCLE, $cycle + 1);
                }
                $order->save();
                $order->add_order_note($credit
                    ? __('ZATCA Tools: the invoice was already fully credited — no further credit note needed.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    : __('ZATCA Tools: nothing was credited on this invoice, so there is nothing to restore.', 'e-invoicing-saudi-arabia-by-zatca-tools'));

                return;
            }

            $order->add_order_note(sprintf(
                $credit
                    /* translators: %s: error message */
                    ? __('ZATCA Tools: the credit note for this cancellation failed — %s. Use “Retry” on this order in the ZATCA Tools screen.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    /* translators: %s: error message */
                    : __('ZATCA Tools: the debit note reinstating this order failed — %s. Use “Retry” on this order in the ZATCA Tools screen.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                $res['error']
            ));

            return;
        }

        $body = $res['body'];
        $number = isset($body['number']) ? $body['number'] : '';

        $order->update_meta_data($credit ? self::META_CREDIT_UUID : self::META_DEBIT_UUID, isset($body['uuid']) ? $body['uuid'] : '');
        $order->update_meta_data($credit ? self::META_CREDIT_NUMBER : self::META_DEBIT_NUMBER, $number);
        $order->update_meta_data(self::META_REVERSAL, $credit ? 'credited' : 'restored');
        // A completed cycle gets a fresh idempotency namespace, so cancelling the
        // same order again issues a NEW credit note instead of returning the old.
        if (! $credit) {
            $order->update_meta_data(self::META_CYCLE, $cycle + 1);
        }
        $order->save();

        $order->add_order_note(sprintf(
            $credit
                /* translators: %s: credit note number */
                ? __('ZATCA Tools: credit note %s issued — the invoice for this order is reversed.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                /* translators: %s: debit note number */
                : __('ZATCA Tools: debit note %s issued — the invoice for this order is due again.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
            $number
        ));
    }

    public function run_note($order_id, $refund_id) {
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (! $order || ! $refund) {
            return;
        }
        $invoice_uuid = $order->get_meta(self::META_UUID);
        if (! $invoice_uuid) {
            return;
        }

        $mapped = ZATCA_Tools_Mapper::to_note($order, $refund, $invoice_uuid);
        if (! $mapped['ok']) {
            return;
        }

        $client = new ZATCA_Tools_Client(ZATCA_Tools_Settings::api_key());
        $res = $client->create_note($mapped['payload'], $mapped['payload']['external_id']);

        if ($res['ok']) {
            $order->add_order_note(sprintf(
                /* translators: %s: credit note number */
                __('ZATCA Tools: credit note %s issued for refund.', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                isset($res['body']['number']) ? $res['body']['number'] : ''
            ));
        } else {
            $order->add_order_note(sprintf(
                /* translators: %s: error message */
                __('ZATCA Tools: credit note failed — %s', 'e-invoicing-saudi-arabia-by-zatca-tools'),
                $res['error']
            ));
        }
    }

    /* ------------------------------------------------------------ meta box */

    public function add_meta_box() {
        // Register on both the legacy post screen and the HPOS orders screen —
        // whichever the store uses is the one that renders.
        $screens = array('shop_order');
        if (function_exists('wc_get_page_screen_id')) {
            $hpos = wc_get_page_screen_id('shop-order');
            if ($hpos) {
                $screens[] = $hpos;
            }
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box('zatca_tools_box', __('ZATCA e-invoice', 'e-invoicing-saudi-arabia-by-zatca-tools'), array($this, 'render_meta_box'), $screen, 'side', 'default');
        }
    }

    public function render_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
        if (! $order) {
            return;
        }

        $status = $order->get_meta(self::META_STATUS);
        $number = $order->get_meta(self::META_NUMBER);
        $pdf    = $this->invoice_link($order);
        $error  = $order->get_meta(self::META_ERROR);

        if ($status === 'issued') {
            echo '<p style="color:#0a6b38;font-weight:600;">&#10003; ' . esc_html__('Invoice issued', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
            if ($number) {
                echo '<p><code>' . esc_html($number) . '</code></p>';
            }
            if ($pdf) {
                echo '<p><a href="' . esc_url($pdf) . '" target="_blank" class="button">' . esc_html__('View / print PDF', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p>';
            }

            // A reversal the status calls for but that has not been issued yet.
            $pending = $this->pending_reversal($order);
            if ('' !== $pending) {
                echo '<p style="margin-top:12px;font-weight:600;color:#8a6d00;">'
                    . esc_html('credit' === $pending
                        ? __('Credit note being issued…', 'e-invoicing-saudi-arabia-by-zatca-tools')
                        : __('Debit note being issued…', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                    . '</p>';
                echo '<p style="font-size:11px;color:#666;">'
                    . esc_html__('WordPress runs background tasks when the site gets traffic, so this can take a minute or two on a quiet store. It will issue on its own.', 'e-invoicing-saudi-arabia-by-zatca-tools')
                    . '</p>';
                $this->issue_now_button($order);
            }

            // The reversal trail: cancelled → credit note, re-instated → debit
            // note. Both are permanent documents, so both stay on the record.
            $reversal = (string) $order->get_meta(self::META_REVERSAL);
            $credit = (string) $order->get_meta(self::META_CREDIT_NUMBER);
            $debit = (string) $order->get_meta(self::META_DEBIT_NUMBER);

            if ('' !== $credit || '' !== $debit) {
                echo '<p style="margin-top:12px;font-weight:600;color:' . ('credited' === $reversal ? '#8a6d00' : '#0a6b38') . ';">'
                    . esc_html('credited' === $reversal
                        ? __('Reversed by a credit note', 'e-invoicing-saudi-arabia-by-zatca-tools')
                        : __('Reinstated by a debit note', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                    . '</p>';

                foreach (array('credit' => $credit, 'debit' => $debit) as $doc => $note_number) {
                    if ('' === $note_number) {
                        continue;
                    }
                    echo '<p style="margin:4px 0;"><code>' . esc_html($note_number) . '</code> '
                        . '<a href="' . esc_url(add_query_arg(
                            array('page' => ZATCA_Tools_Admin_Page::SLUG, 'view' => $order->get_id(), 'doc' => $doc),
                            admin_url('admin.php')
                        )) . '">'
                        . esc_html('credit' === $doc
                            ? __('view credit note', 'e-invoicing-saudi-arabia-by-zatca-tools')
                            : __('view debit note', 'e-invoicing-saudi-arabia-by-zatca-tools'))
                        . '</a></p>';
                }
            }
        } elseif ($status === 'failed') {
            echo '<p style="color:#b42318;font-weight:600;">' . esc_html__('Invoice failed', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
            if ($error) {
                echo '<p>' . esc_html($error) . '</p>';
            }
            echo '<p><a href="#" class="button" onclick="return false;">' . esc_html__('Will retry on next status change', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p>';
        } elseif ($status === 'skipped') {
            echo '<p>' . esc_html__('Not invoiced', 'e-invoicing-saudi-arabia-by-zatca-tools') . ($error ? ': ' . esc_html($error) : '') . '</p>';
        } elseif ($this->is_queued($order)) {
            // WordPress runs background jobs from site traffic (WP-Cron), so a
            // quiet store can wait a minute or two. Say so, and offer the
            // shortcut instead of leaving the merchant guessing.
            echo '<p style="font-weight:600;">' . esc_html__('Queued for invoicing…', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
            echo '<p style="font-size:11px;color:#666;">' . esc_html__('WordPress runs background tasks when the site gets traffic, so this can take a minute or two on a quiet store. It will issue on its own.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
            $this->issue_now_button($order);
        } else {
            echo '<p>' . esc_html__('No invoice yet — issues automatically once the order is paid.', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</p>';
            if ($order->is_paid()) {
                $this->issue_now_button($order);
            }
        }

        // Individual or establishment, and the details a tax invoice needs.
        ZATCA_Tools_Buyer::render_order_panel($order);
    }

    /* --------------------------------------------------------------- helper */

    /** Is this order sitting in the Action Scheduler queue, waiting to run? */
    private function is_queued($order, $hook = self::HOOK_INVOICE) {
        if (! function_exists('as_has_scheduled_action')) {
            return false;
        }

        return as_has_scheduled_action(
            $hook,
            array('order_id' => $order->get_id()),
            'e-invoicing-saudi-arabia-by-zatca-tools'
        );
    }

    /**
     * The reversal note this order still owes, if any.
     *
     * WordPress runs background work off site traffic, so on a quiet store a
     * credit note can be a minute or two behind the cancellation. Saying so —
     * and offering to run it now — is the difference between «working» and
     * «nothing happened».
     *
     * @return string '' | credit | debit
     */
    public function pending_reversal($order) {
        if (! $order->get_meta(self::META_UUID)) {
            return '';
        }

        $credited = 'credited' === $order->get_meta(self::META_REVERSAL);
        $cancelled = $order->has_status(array('cancelled', 'refunded'));

        if ($cancelled && ! $credited) {
            return 'credit';
        }
        if (! $cancelled && $credited) {
            return 'debit';
        }

        return '';
    }

    /** "Issue now" — runs the job in this request instead of waiting for cron. */
    private function issue_now_button($order) {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=zatca_tools_retry&order_id=' . $order->get_id()),
            'zatca_tools_retry_' . $order->get_id()
        );

        echo '<p><a class="button" href="' . esc_url($url) . '">'
            . esc_html__('Issue now', 'e-invoicing-saudi-arabia-by-zatca-tools') . '</a></p>';
    }

    private function mark($order, $status, $meta = array()) {
        $order->update_meta_data(self::META_STATUS, $status);
        foreach ($meta as $k => $v) {
            $order->update_meta_data($k, $v);
        }
        $order->save();
    }
}
