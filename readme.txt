=== E-Invoicing for Saudi Arabia by ZATCA Tools ===
Contributors: zatcatools
Tags: zatca, e-invoice, invoice, saudi arabia, vat
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically issue Saudi Phase 2 compliant e-invoices for paid WooCommerce orders. Refunds issue credit notes. Signed, reported, delivered.

== Description ==

This plugin connects your WooCommerce store to ZATCA Tools, a cloud e-invoicing service for Saudi merchants, and issues a compliant tax invoice for every paid order — automatically.

ZATCA Tools is an independent commercial service and the brand of the plugin author (zatcatools.com). It is not affiliated with, endorsed by, or an official product of the Zakat, Tax and Customs Authority (ZATCA). "ZATCA" refers to that authority only as the compliance standard the plugin helps you meet.

When an order is paid, the plugin sends its details to your ZATCA Tools account, which builds the invoice, signs it with your establishment certificate, and reports it to ZATCA (the Fatoora platform for the second phase of e-invoicing). The invoice and its QR code are then available from the order screen, and the buyer can receive a PDF copy by email.

**Features**

* Automatic tax invoice for every paid order
* Automatic credit notes for refunds
* A VAT field at checkout so business buyers get an invoice in their company name
* Standard (B2B) cleared tax invoices for supplies of SAR 1,000 or more to a VAT-registered buyer
* The tax invoice number and a download link in the order emails, the thank-you page and My Account
* A dashboard (ZATCA Tools in the admin menu) with a VAT setup check and per-order invoice status
* Refuses to invoice an order that did not actually carry 15% VAT, instead of inventing tax
* Invoice PDF with the official ZATCA QR code
* Runs in the background so checkout is never slowed down

**Requirements**

* A ZATCA Tools account connected to ZATCA (a free account at zatcatools.com)
* Your store currency set to SAR
* WooCommerce taxes enabled with a 15% rate for Saudi Arabia — without VAT on the order no tax invoice can be issued
* WooCommerce active

== External services ==

This plugin connects to ZATCA Tools, an external e-invoicing service operated by ZATCA Tools, to create and report tax invoices for your orders. This connection is required for the plugin to function, and only happens after you enter your own API key in the plugin settings.

**What data is sent, and when**

* When you save your API key in the settings screen, the plugin sends the key to the ZATCA Tools API to verify it (an account lookup request).
* When an order is paid, the plugin sends that order's details — line items, amounts, discounts, shipping, the buyer's name, and (if present) the buyer's email, billing address and VAT number — to the ZATCA Tools API so a tax invoice can be issued and reported to ZATCA.
* When an order is refunded, the plugin sends the refund details so a credit note can be issued.

No data is sent to any external service until you enter an API key and enable the plugin.

* Service provider: ZATCA Tools — https://zatcatools.com
* Terms of Use: https://zatcatools.com/terms
* Privacy Policy: https://zatcatools.com/privacy

== Installation ==

1. Upload the plugin and activate it, or install it from your WordPress dashboard.
2. Create an account and an API key at zatcatools.com (Settings > API).
3. In WooCommerce > Settings > ZATCA Tools, paste the API key and save.
4. A success message confirms the connection. Paid orders are now invoiced automatically.

Full guide: https://zatcatools.com/docs/woocommerce

== Frequently Asked Questions ==

= Do I need an account with an external service? =

Yes. This plugin is a connector for the ZATCA Tools e-invoicing service. You create a free account at zatcatools.com and connect it to ZATCA, then paste your API key into the plugin.

= Do my customers see anything different at checkout? =

Yes, one optional addition: a "Buying for a business" box where a VAT-registered buyer can enter their VAT number to receive a tax invoice in their company name. Consumers can ignore it. Everything else runs in the background.

= Why does an order say it was not invoiced? =

The most common reason is VAT. If WooCommerce taxes are disabled, or the order carried no VAT (or a rate other than 15%), the plugin refuses to issue a tax invoice rather than adding tax the buyer never paid. Open the ZATCA Tools screen in your admin menu — it names exactly what to fix and links to the right settings page.

= How are business (B2B) invoices handled? =

Saudi VAT requires a full tax invoice for a supply of SAR 1,000 or more to a VAT-registered buyer. So when an order carries a valid Saudi VAT number (from the checkout field, an existing VAT field, the customer's profile, or the order note) plus the buyer's national address, a standard cleared tax invoice is issued. Below SAR 1,000 the invoice stays simplified but still shows the buyer's VAT number — the regulations permit a simplified invoice there for any buyer.

You can also mark an order (or a customer) as an establishment or an individual in the ZATCA e-invoice panel. Marking it as an establishment means "aim for the tax invoice, and tell me if you could not": if the total is under SAR 1,000, or a required buyer detail is missing, the simplified invoice is still issued and the panel says exactly why it is not a tax invoice.

= What about refunds and cancellations? =

Refunding an order automatically issues a credit note against the original invoice, for the refunded amount.

Cancelling an order that was already invoiced issues a credit note for the invoice's whole open balance. A tax invoice that ZATCA has accepted cannot be deleted — reversing it with a credit note IS the cancellation. If the order is cancelled before it was ever invoiced, nothing is issued and nothing is reported.

Putting that cancelled order back to a paid status issues a debit note for the same amount, so the invoice is due again. The credit note is not removed: every document reported to ZATCA is permanent, and the pair of them is the audit trail.

== Screenshots ==

1. The ZATCA Tools settings screen in WooCommerce.
2. The invoice panel on the order screen with the PDF link.

== Changelog ==

= 1.7.0 =
* Putting a cancelled order back to a paid status now issues a **debit note**, so the invoice is due again. The credit note that reversed it stays — every reported document is permanent — and both are listed on the order with buttons to view either. Cancelling again writes a new credit note.
* Fixed the invoice and note layout when viewed in your dashboard: the issue date and the original-invoice reference were being pushed to the far side of the header, away from their own labels, and the whole document was rendering in the wrong typeface. Both now match the PDF exactly.

= 1.6.0 =
* Cancelling an order that was already invoiced now issues a **full credit note** that reverses it. A tax invoice reported to ZATCA cannot be deleted, so the reversal is the document — and it is shown on the order and in the dashboard with a button to view and print it.
* Cancelled and refunded orders no longer disappear from the dashboard list. They stayed invisible exactly when you needed to find their credit note.
* An order cancelled in the seconds before its background invoicing job ran is no longer invoiced at all — a sale that was undone is never reported.
* Marking an order "Refunded" without recording a refund now also produces the credit note.

= 1.5.0 =
* One address field, the one Saudi buyers actually have: the **national address** (4 letters + 4 digits, e.g. RRRD2929). The street, building number, city and postal code a tax invoice needs are resolved from it by the service — you are never asked for a building number again. It is on the order panel, at checkout, and on the customer's profile.
* Choosing "Establishment (B2B)" now marks what it requires in red, and every other field says "optional" outright.
* Shorter, clearer wording in the order panel.

= 1.4.1 =
* You can now mark an order as being to an establishment (B2B) or to an individual (B2C), in the ZATCA e-invoice panel on the order, together with the buyer's VAT number, commercial registration and national address. The panel tells you which document will be issued before it goes out.
* The same three details can be saved on a customer's profile, so a returning business buyer is recognised automatically and nobody retypes anything. An individual order can still override it.
* When an order was in line for a tax invoice but could not have one — the total is under SAR 1,000, where the regulations allow a simplified invoice for any buyer, or a required buyer detail such as the building number is missing — the simplified invoice is still issued and the reason is stated on the order, in the order notes and in the dashboard list. An order is never left uninvoiced over it: a simplified invoice has to reach ZATCA within 24 hours either way.
* Fixed the buyer VAT number always appearing empty on the order screen: a field added the WooCommerce way is saved under one key but displayed from another, so whatever you entered was never shown back to you.
* The dashboard's order list now shows B2B or B2C per order.

= 1.3.1 =
* Fixed the invoice layout when viewed in the dashboard — it is built for the PDF engine, so a browser was substituting a different font and floating the footer over the totals.
* Fixed Print producing a blank page.

= 1.3.0 =
* Invoices now open INSIDE your dashboard instead of as a separate file. Some ad blockers and privacy extensions block invoice links on sight — whether they point at us or at your own site — leaving you with a blocked page. Viewing it in the page means there is no file request to block, and Print / Save as PDF still gives you the same document.
* Added "Email it to me" — the invoice is sent by the server, so it always arrives no matter what the browser blocks.

= 1.2.1 =
* Fixed a "not allowed to view this invoice" error when opening an invoice from the admin: the WordPress REST API ignores login cookies unless a nonce is sent, so the invoice link now carries the order key itself — the same way WooCommerce authorises its own order links.

= 1.2.0 =
* Invoices are now served from YOUR store's own domain, over a clean REST route (/wp-json/zatca-tools/v1/…). Ad blockers and privacy extensions block third-party document links (ERR_BLOCKED_BY_CLIENT), which left merchants — and buyers — staring at a blocked page. Your store now fetches the PDF securely and serves it itself, so there is nothing left to block.

= 1.1.1 =
* Shows the running plugin version on the dashboard (after an update, PHP opcache can keep serving the old files — this makes that obvious).

= 1.1.0 =
* Orders waiting in the background queue now say so, with an "Issue now" button instead of looking stuck (WordPress runs background tasks from site traffic, so a quiet store can wait a minute or two).
* Fixed the invoice link: it pointed at the API endpoint, which answers "Invalid or missing API key" when opened in a browser. Existing orders repair themselves.
* Two-way setup help: a new merchant starts registration with their store name, email and address already filled in from WooCommerce, and a store with no VAT configured can switch it on with one click (taxes on + a 15% Saudi rate applied to shipping). A connected store can also pull its establishment address back into an empty shop address. The "prices include tax" setting is never changed silently, because it re-interprets existing prices.
* Refuses to issue a tax invoice for an order without 15% VAT (taxes disabled, zero-rated, or a mixed rate) instead of inventing tax.
* Fixed the order discount being sent tax-inclusive, which made the invoice total disagree with the amount paid.
* New "Buying for a business" VAT (and commercial registration) field at checkout, highlighted for orders of SAR 1,000 or more.
* B2B now follows the SAR 1,000 rule: cleared standard invoice at or above it, simplified with the buyer's VAT below it.
* New ZATCA Tools admin dashboard: connection and VAT setup checks, invoice count, recent orders with per-order retry.
* The tax invoice number and a download link now appear in the order emails, the thank-you page and My Account.

= 1.0.0 =
* Initial release: automatic invoices, credit notes, B2B detection, order-screen invoice panel.

== Upgrade Notice ==

= 1.1.0 =
Important correctness fixes: orders without a real 15% VAT are no longer invoiced, and discounted orders now reconcile to the amount paid. Adds a checkout VAT field, a setup dashboard, and the invoice in customer emails.

= 1.0.0 =
Initial release.
