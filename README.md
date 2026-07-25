# E-Invoicing for Saudi Arabia by ZATCA Tools

[![Version](https://img.shields.io/badge/version-1.7.0-0E8345)](https://github.com/muhannadhg/zatca-tools-woocommerce/releases)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759B)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)](https://www.php.net/)

A WooCommerce plugin that turns every paid order into a **Saudi Phase 2 compliant e-invoice** — built as UBL 2.1, signed, reported to the Fatoora platform, and delivered to the buyer. Refunds issue credit notes automatically.

**Free.** The plugin is GPL, and the [ZATCA Tools](https://zatcatools.com) account it connects to is free — no card.

📘 **Setup guide (Arabic):** https://zatcatools.com/docs/woocommerce

---

## What it does

- Issues a tax invoice for every paid order, in the background — checkout is never blocked
- Issues a **credit note** automatically when an order is refunded or cancelled
- Adds a VAT-number field at checkout so business buyers get an invoice in their company name
- Sends **standard (B2B) cleared** invoices for supplies of SAR 1,000+ to a VAT-registered buyer, simplified invoices otherwise
- Puts the invoice number and a PDF download link in order emails, the thank-you page and My Account
- Ships an admin dashboard with a VAT setup check and per-order invoice status
- **Refuses to invoice an order that did not actually carry 15% VAT**, instead of inventing tax

## Requirements

| | |
|---|---|
| WooCommerce | active |
| Store currency | SAR |
| Taxes | enabled, with a 15% rate for Saudi Arabia |
| Account | a free [ZATCA Tools](https://zatcatools.com) account connected to ZATCA |
| WordPress / PHP | 6.0+ / 7.4+ |

Without VAT on the order there is nothing to invoice — the plugin will tell you rather than guess.

## Install

Download the latest release, then **Plugins → Add New → Upload Plugin**. Or clone straight into your plugins directory:

```bash
git clone https://github.com/muhannadhg/zatca-tools-woocommerce.git wp-content/plugins/zatca-tools
```

Then create an API key at zatcatools.com (**Settings → API**) and paste it into **WooCommerce → Settings → ZATCA Tools**.

## Notes for developers

Phase 2 is more than an XML format, and two details cause most of the trouble:

**The hash chain is over issued invoices, not accepted ones.** Every invoice carries an ICV counter and a PIH — the hash of the previous invoice. It is tempting to advance the chain head only once ZATCA returns a 200, but a rejection does not un-issue an invoice: roll the head back on a rejection and the chain forks, and every invoice after it is wrong. A rejected invoice keeps its place in the chain.

**Claim the chain synchronously, submit asynchronously.** The critical section is claim ICV + PIH → assign the number → build the UBL → sign → write the new chain head, as one database transaction under a row lock on the seller. No HTTP inside that lock. The call to ZATCA happens after the commit, so a network failure leaves the chain consistent and the retry is just a resubmit of bytes that were already signed.

This plugin does none of that locally — signing, the chain and the certificate lifecycle live in the ZATCA Tools service, and the plugin is the WooCommerce end of that API. If you are building your own, those two points are the ones worth getting right first.

## Disclaimer

ZATCA Tools is an independent commercial service and the brand of the plugin author. It is **not affiliated with, endorsed by, or an official product of** the Zakat, Tax and Customs Authority. "ZATCA" refers to that authority only as the compliance standard this plugin helps you meet.

The plugin connects to an external service; see the *External services* section of [readme.txt](readme.txt) for exactly what data is sent and when, plus the [Terms](https://zatcatools.com/terms) and [Privacy Policy](https://zatcatools.com/privacy).

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).

---

<div dir="rtl">

## بالعربية

إضافة ووردبريس تحوّل كل طلب مدفوع في متجر WooCommerce إلى **فاتورة إلكترونية متوافقة مع المرحلة الثانية**: تُبنى بصيغة UBL 2.1، وتُوقَّع رقميًا، وتُرسل لمنصة فاتورة، وتصل للعميل. والإرجاع يصدر إشعار دائن تلقائيًا.

الإضافة مجانية، وحساب [ZATCA Tools](https://zatcatools.com) مجاني كذلك — بدون بطاقة ائتمانية.

**المتطلبات:** عملة المتجر بالريال، والضرائب مفعّلة بنسبة 15% للسعودية، وWooCommerce نشط. وإذا لم يحمل الطلب ضريبة فعلية فالإضافة تخبرك بذلك بدل أن تخترع ضريبة.

**دليل الربط خطوة بخطوة:** https://zatcatools.com/docs/woocommerce

</div>
