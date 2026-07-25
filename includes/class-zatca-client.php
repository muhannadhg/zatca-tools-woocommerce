<?php
/**
 * Thin REST client for the ZATCA Tools v1 API.
 *
 * @package ZatcaTools
 */

if (! defined('ABSPATH')) {
    exit;
}

class ZATCA_Tools_Client {

    const BASE = 'https://zatcatools.com/api/v1';

    /** @var string */
    private $api_key;

    public function __construct($api_key) {
        $this->api_key = trim((string) $api_key);
    }

    /** GET /account — used to validate the key from the settings screen. */
    public function account() {
        return $this->request('GET', '/account');
    }

    /**
     * POST /invoices with an idempotency key so retries never double-invoice.
     *
     * @param array  $payload        Invoice body (type, lines, customer, discount…).
     * @param string $idempotency_key Stable per-order key.
     */
    public function create_invoice($payload, $idempotency_key) {
        return $this->request('POST', '/invoices', $payload, $idempotency_key);
    }

    /** POST /notes — a credit note for a refund. */
    public function create_note($payload, $idempotency_key) {
        return $this->request('POST', '/notes', $payload, $idempotency_key);
    }

    /** GET /invoices/{uuid} — re-read an issued invoice (number, links, status). */
    public function get_invoice($uuid) {
        return $this->request('GET', '/invoices/' . rawurlencode($uuid));
    }

    /**
     * The invoice as self-contained HTML, for printing INSIDE a store page.
     *
     * No document URL is involved, so nothing in the browser can block it —
     * the alternative that survives aggressive ad blockers.
     *
     * @return string HTML, or '' on any failure.
     */
    public function get_invoice_html($uuid) {
        $response = wp_remote_get(self::BASE . '/invoices/' . rawurlencode($uuid) . '/html', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'text/html',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);

        return (is_string($body) && false !== stripos($body, '<html')) ? $body : '';
    }

    /** POST /invoices/{uuid}/email — mail the invoice (server-side, unblockable). */
    public function email_invoice($uuid, $to = '') {
        return $this->request('POST', '/invoices/' . rawurlencode($uuid) . '/email', array('to' => $to));
    }

    /**
     * The invoice PDF bytes, fetched server-to-server so the store can serve it
     * from its own domain (see ZATCA_Tools_Invoice_File).
     *
     * @return string Raw PDF, or '' on any failure.
     */
    public function get_invoice_pdf($uuid) {
        $response = wp_remote_get(self::BASE . '/invoices/' . rawurlencode($uuid) . '/pdf', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/pdf',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return '';
        }

        $body = wp_remote_retrieve_body($response);

        // Guard against an error page being handed to the browser as a "PDF".
        return (is_string($body) && 0 === strpos($body, '%PDF')) ? $body : '';
    }

    /**
     * @return array{ok:bool, status:int, body:array, error:string}
     */
    private function request($method, $path, $body = null, $idempotency_key = null) {
        $headers = array(
            'Authorization' => 'Bearer ' . $this->api_key,
            'Accept'        => 'application/json',
        );
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }

        $args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request(self::BASE . $path, $args);

        if (is_wp_error($response)) {
            return array('ok' => false, 'status' => 0, 'body' => array(), 'error' => $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($decoded)) {
            $decoded = array();
        }

        $ok = $status >= 200 && $status < 300;
        $error = $ok ? '' : (isset($decoded['error']['message']) ? $decoded['error']['message'] : ('HTTP ' . $status));
        // The machine-readable code lets a caller react to a SPECIFIC refusal
        // (see the standard→simplified fallback in ZATCA_Tools_Orders) instead
        // of pattern-matching a human message that may be reworded.
        $code = isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : '';

        return array('ok' => $ok, 'status' => $status, 'body' => $decoded, 'error' => $error, 'code' => $code);
    }
}
