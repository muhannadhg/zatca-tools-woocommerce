<?php
/**
 * Standalone checks for ZATCA_Tools_Mapper against a minimal WooCommerce stub,
 * so the money math and the VAT gates are proven rather than assumed.
 *
 * It cannot live in the Laravel PHPUnit suite: the WordPress shims below define
 * __(), which collides with Laravel's own helper. Run it directly:
 *
 *     php plugins/woocommerce/tests/test-mapper.php
 *
 * Exit code 0 = all pass.
 */

define('ABSPATH', __DIR__);

// --- WordPress / WooCommerce shims -----------------------------------------
function __($s, $d = null) { return $s; }
function wp_strip_all_tags($s) { return strip_tags($s); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function number_format_i18n($n) { return number_format($n, 2); }

$GLOBALS['tax_enabled'] = true;
function wc_tax_enabled() { return $GLOBALS['tax_enabled']; }

// Customer profile meta: [user_id][key] => value.
$GLOBALS['user_meta'] = [];
function get_user_meta($user_id, $key, $single = false) { return $GLOBALS['user_meta'][$user_id][$key] ?? ''; }

class StubItem {
    public function __construct(private string $name, private float $qty, private float $subtotal) {}
    public function get_name() { return $this->name; }
    public function get_quantity() { return $this->qty; }
    public function get_subtotal() { return $this->subtotal; }
}

class StubFee {
    public function __construct(private string $name, private float $total) {}
    public function get_name() { return $this->name; }
    public function get_total() { return $this->total; }
}

class StubOrder {
    public array $meta = [];
    public int $customer_id = 0;
    public function __construct(
        private array $items,
        private float $shipping,
        private float $tax,
        private float $total,
        private array $fees = [],
        private array $billing = [],
    ) {}
    public function get_currency() { return 'SAR'; }
    public function get_id() { return 1234; }
    public function get_items() { return $this->items; }
    public function get_fees() { return $this->fees; }
    public function get_shipping_total() { return $this->shipping; }
    public function get_total_tax() { return $this->tax; }
    public function get_total() { return $this->total; }
    public function get_billing_email() { return 'buyer@example.com'; }
    public function get_customer_note() { return ''; }
    public function get_meta($k) { return $this->meta[$k] ?? ''; }
    public function get_customer_id() { return $this->customer_id; }
    public function get_billing_first_name() { return $this->billing['first'] ?? 'نورة'; }
    public function get_billing_last_name() { return $this->billing['last'] ?? ''; }
    public function get_billing_company() { return $this->billing['company'] ?? ''; }
    public function get_billing_address_1() { return $this->billing['a1'] ?? ''; }
    public function get_billing_address_2() { return $this->billing['a2'] ?? ''; }
    public function get_billing_city() { return $this->billing['city'] ?? ''; }
    public function get_billing_postcode() { return $this->billing['zip'] ?? ''; }
    public function get_order_number() { return '1234'; }
}

require __DIR__ . '/../zatca-tools/includes/class-zatca-mapper.php';

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (! $ok) { $fails++; }
    printf("%s %s → %s%s\n", $ok ? 'PASS' : 'FAIL', $label,
        is_scalar($got) ? var_export($got, true) : json_encode($got, JSON_UNESCAPED_UNICODE),
        $ok ? '' : ' (expected '.(is_scalar($want) ? var_export($want, true) : json_encode($want, JSON_UNESCAPED_UNICODE)).')');
};

/* 1) Plain order: 2 × 100 net + 20 shipping = 220 net, 33 tax, 253 paid. */
$o = new StubOrder([new StubItem('عود', 2, 200.0)], 20.0, 33.0, 253.0);
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('plain: ok', $m['ok'], true);
$check('plain: unit price', $m['payload']['lines'][0]['unit_price'], 100.0);
$check('plain: shipping line', $m['payload']['lines'][1]['unit_price'], 20.0);
$check('plain: discount', $m['payload']['discount'], 0.0);
$check('plain: type', $m['payload']['type'], 'simplified');

/* 2) Coupon: same lines but 10% off → net 198, tax 29.7, paid 227.70.
      The discount must be derived NET (22), not tax-inclusive (25.30). */
$o = new StubOrder([new StubItem('عود', 2, 200.0)], 20.0, 29.7, 227.70);
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('coupon: ok', $m['ok'], true);
$check('coupon: net discount', $m['payload']['discount'], 22.0);
// The document must reconcile to what was actually paid.
$taxable = 220.0 - $m['payload']['discount'];
$check('coupon: reconciles to paid', round($taxable * 1.15, 2), 227.70);

/* 3) Taxes switched off in WooCommerce → refuse (never fabricate 15%). */
$GLOBALS['tax_enabled'] = false;
$m = ZATCA_Tools_Mapper::to_invoice(new StubOrder([new StubItem('عود', 1, 100.0)], 0.0, 0.0, 100.0));
$check('taxes off: refused', $m['ok'], false);
$check('taxes off: reason mentions VAT', stripos($m['skip'], 'VAT is disabled') !== false, true);
$GLOBALS['tax_enabled'] = true;

/* 4) Zero-tax order in a tax-enabled store → refuse. */
$m = ZATCA_Tools_Mapper::to_invoice(new StubOrder([new StubItem('كتاب', 1, 50.0)], 0.0, 0.0, 50.0));
$check('zero tax: refused', $m['ok'], false);

/* 5) Mixed / exempt rates (tax is not 15% of net) → refuse. */
$m = ZATCA_Tools_Mapper::to_invoice(new StubOrder([new StubItem('مختلط', 1, 200.0)], 0.0, 15.0, 215.0));
$check('mixed rate: refused', $m['ok'], false);
$check('mixed rate: reason mentions uniform', stripos($m['skip'], 'not a uniform') !== false, true);

/* 6) B2B under 1,000 → simplified, but the VAT still prints. */
$o = new StubOrder([new StubItem('سلعة', 1, 400.0)], 0.0, 60.0, 460.0, [], [
    'company' => 'شركة المثال', 'a1' => 'شارع العليا 7071', 'city' => 'الرياض', 'zip' => '12251',
]);
$o->meta['_zatca_vat'] = '310122393500003';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('b2b <1000: type', $m['payload']['type'], 'simplified');
$check('b2b <1000: vat printed', $m['payload']['customer']['vat_number'], '310122393500003');

/* 7) B2B at 1,000+ with a complete address → standard (cleared). */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], [
    'company' => 'شركة المثال', 'a1' => 'شارع العليا 7071', 'city' => 'الرياض', 'zip' => '12251',
]);
$o->meta['_zatca_vat'] = '310122393500003';
$o->meta['_zatca_cr'] = '1010101010';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('b2b >=1000: type', $m['payload']['type'], 'standard');
$check('b2b >=1000: building from address line', $m['payload']['customer']['building_number'], '7071');
$check('b2b >=1000: cr', $m['payload']['customer']['cr_number'], '1010101010');

/* 8) B2B at 1,000+ with NO usable address → must fall back to simplified
      (ZATCA rejects a standard invoice with a partial buyer address). */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], [
    'company' => 'شركة المثال', 'a1' => 'شارع العليا', 'city' => 'الرياض', 'zip' => '12251',
]);
$o->meta['_zatca_vat'] = '310122393500003';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('b2b incomplete address: falls back', $m['payload']['type'], 'simplified');

/* --- marking an order B2B aims at a tax invoice; the AMOUNT still decides ---
   KSA VAT Implementing Regulations art. 53: a simplified invoice is permitted
   below SAR 1,000 whoever the buyer is. And an invoice is never withheld — a
   shortfall is a NOTICE on an issued document, because a simplified invoice
   still has to be reported to ZATCA within 24 hours. */

$business_billing = [
    'company' => 'شركة المثال', 'a1' => 'شارع العليا', 'city' => 'الرياض', 'zip' => '12251',
];

/* 9) Marked B2B under 1,000 → SIMPLIFIED (with the VAT printed), and a notice
      saying why it is not a tax invoice. */
$o = new StubOrder([new StubItem('سلعة', 1, 400.0)], 0.0, 60.0, 460.0, [], $business_billing);
$o->meta['_zatca_buyer_type'] = 'b2b';
$o->meta['_zatca_vat'] = '310122393500003';
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('marked b2b <1000: ok', $m['ok'], true);
$check('marked b2b <1000: type', $m['payload']['type'], 'simplified');
$check('marked b2b <1000: vat printed', $m['payload']['customer']['vat_number'], '310122393500003');
$check('marked b2b <1000: explains the threshold', stripos($m['notice'], '1,000') !== false, true);

/* 10) Marked B2B at 1,000+ but no national address → still ISSUED, as
       simplified, with a notice naming the missing detail. Withholding it would
       leave the sale unreported; hiding the reason would leave the merchant
       believing a tax invoice went out. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->meta['_zatca_buyer_type'] = 'b2b';
$o->meta['_zatca_vat'] = '310122393500003';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('marked b2b, no address: still issues', $m['ok'], true);
$check('marked b2b, no address: type', $m['payload']['type'], 'simplified');
$check('marked b2b, no address: names it', stripos($m['notice'], 'national address') !== false, true);
// The merchant is never shown our data model: no «building number».
$check('marked b2b, no address: never says building number', stripos($m['notice'], 'building number') !== false, false);

/* 11) Marked B2B at 1,000+ with no VAT number at all → simplified + a notice
       naming the VAT number. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->meta['_zatca_buyer_type'] = 'b2b';
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('marked b2b, no vat: still issues', $m['ok'], true);
$check('marked b2b, no vat: type', $m['payload']['type'], 'simplified');
$check('marked b2b, no vat: names it', stripos($m['notice'], 'VAT number') !== false, true);

/* 11b) Automatic, 1,000+, valid VAT, incomplete address → simplified, and the
        merchant is warned: at this amount the law wanted a tax invoice. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->meta['_zatca_vat'] = '310122393500003';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('auto >=1000 incomplete: type', $m['payload']['type'], 'simplified');
$check('auto >=1000 incomplete: warns', stripos($m['notice'], 'national address') !== false, true);

/* 11c) A plain consumer sale is never warned about. */
$o = new StubOrder([new StubItem('عود', 2, 200.0)], 20.0, 33.0, 253.0);
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('consumer sale: no notice', $m['notice'], '');

/* 12) Marked B2C at 1,000+ WITH a VAT on the order → simplified, and the
       company VAT must NOT appear: the merchant said this is an individual. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->meta['_zatca_buyer_type'] = 'b2c';
$o->meta['_zatca_vat'] = '310122393500003';
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('marked b2c: type', $m['payload']['type'], 'simplified');
$check('marked b2c: no buyer vat', isset($m['payload']['customer']['vat_number']), false);

/* 13) The customer's profile default applies when the order says nothing. */
$GLOBALS['user_meta'][7] = ['zatca_buyer_type' => 'b2b', 'zatca_vat' => '310122393500003', 'zatca_cr' => '4030201010'];
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->customer_id = 7;
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('profile default: type', $m['payload']['type'], 'standard');
$check('profile default: vat from profile', $m['payload']['customer']['vat_number'], '310122393500003');
$check('profile default: cr from profile', $m['payload']['customer']['cr_number'], '4030201010');

/* 14) …and the order still wins over the profile. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->customer_id = 7;
$o->meta['_zatca_buyer_type'] = 'b2c';
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('order overrides profile: type', $m['payload']['type'], 'simplified');
$check('order overrides profile: no vat', isset($m['payload']['customer']['vat_number']), false);

/* 15) The national address alone earns a tax invoice: it travels to the API,
       which resolves it into the street/building/city/postal ZATCA wants. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], [
    'company' => 'شركة المثال', 'a1' => 'شارع العليا 1234', 'city' => 'الرياض', 'zip' => '12251',
]);
$o->meta['_zatca_vat'] = '310122393500003';
$o->meta['_zatca_short_address'] = 'RRRD2929';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('short address sent', $m['payload']['customer']['short_address'], 'RRRD2929');
$check('short address earns standard', $m['payload']['type'], 'standard');

/* 16) A malformed national address is neither sent nor trusted. */
$o = new StubOrder([new StubItem('سلعة', 1, 1500.0)], 0.0, 225.0, 1725.0, [], $business_billing);
$o->meta['_zatca_vat'] = '310122393500003';
$o->meta['_zatca_short_address'] = '7071';
$m = ZATCA_Tools_Mapper::to_invoice($o);
$check('bad short address: not sent', $m['payload']['customer']['short_address'] ?? '', '');
$check('bad short address: stays simplified', $m['payload']['type'], 'simplified');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILURE(S)\n";
exit($fails === 0 ? 0 : 1);
