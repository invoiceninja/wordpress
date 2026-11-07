"""End-to-end flows for the WooCommerce module (M6): Woo is the store, Invoice Ninja is the ledger.

Every test is skipped when WooCommerce isn't installed in the stack (`wp plugin is-installed
woocommerce`). WooCommerce is activated for these tests and put back the way it was at the end.
Jobs are run directly (the same handlers the queue calls), so results don't depend on timing.
"""

import json

from lib import api_calls, check, describe, reset_mock_log, wp, wp_eval, wp_ok
from test_v2 import admin, connect

_state = {}


def woo_installed():
    if 'installed' not in _state:
        _state['installed'] = wp_ok('plugin', 'is-installed', 'woocommerce')
    return _state['installed']


class _WooTests(list):
    """Checked when the runner collects tests (after its setup), so a missing WooCommerce skips them all."""

    def __iter__(self):
        if not woo_installed():
            print('  SKIP  test_woocommerce: WooCommerce is not installed (wp plugin is-installed woocommerce)', flush=True)
            return iter([])
        return super().__iter__()


TESTS = _WooTests()


def test(fn):
    TESTS.append(fn)
    return fn


P = 'InvoiceNinja\\Plugin::instance()'
M = P + "->module('woocommerce')"
HOOKS = ('invoiceninja_woo_invoice', 'invoiceninja_woo_payment', 'invoiceninja_woo_refund', 'invoiceninja_woo_cancel', 'invoiceninja_woo_backfill')


def php(code):
    return wp_eval(code)


def woo_ready(hpos=None):
    """WooCommerce active, taxes on (10% VAT), a test product, the module on and connected."""
    if 'was_active' not in _state:
        _state['was_active'] = wp_ok('plugin', 'is-active', 'woocommerce')
    if not wp_ok('plugin', 'is-active', 'woocommerce'):
        wp('plugin', 'activate', 'woocommerce')
    # A first activation (a fresh site, as on CI) sends the next admin page to WooCommerce's setup wizard.
    php("delete_transient('_wc_activation_redirect');")
    if 'hpos_was' not in _state:
        _state['hpos_was'] = php("echo Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off';") == 'on'
    b = admin()
    connect(b)
    wp('invoiceninja', 'environment', 'confirm')
    status, saved = b.api('POST', '/settings', {'modules': {'woo': True}, 'woo': {'invoices': True, 'email_link': True, 'customer_sync': 'orders'}})
    check(status == 200 and saved['modules']['woo'] is True, 'WooCommerce module switched on', saved)
    php("update_option('woocommerce_calc_taxes', 'yes'); update_option('woocommerce_prices_include_tax', 'no');"
        "update_option('woocommerce_tax_round_at_subtotal', 'no'); update_option('woocommerce_currency', 'USD');"
        "update_option('woocommerce_cod_settings', array('enabled' => 'yes')); update_option('woocommerce_bacs_settings', array('enabled' => 'yes'));"
        "global $wpdb; if (!$wpdb->get_var(\"SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_name = 'VAT'\")) {"
        " WC_Tax::_insert_tax_rate(array('tax_rate_country' => '', 'tax_rate' => '10.0000', 'tax_rate_name' => 'VAT', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '')); }"
        "if (!wc_get_product_id_by_sku('WOO-TEST')) { $p = new WC_Product_Simple(); $p->set_name('Woo test product'); $p->set_sku('WOO-TEST'); $p->set_regular_price('10'); $p->set_status('publish'); $p->save(); }")
    if hpos is not None:
        set_hpos(hpos)
    php("foreach (array('%s') as $h) { as_unschedule_all_actions($h); }"
        "delete_option('in_mock_invoices'); delete_option('in_mock_payments'); delete_option('in_mock_paid'); delete_option('in_mock_lock_invoices');"
        "delete_option('in_mock_woo_invoice_timeout'); delete_option('in_mock_payment_timeout'); delete_transient('invoiceninja_woo_lock_invoices');"
        "delete_option('in_mock_mark_sent_timeout'); delete_option('in_mock_refund_timeout');"
        "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_woo\\\\_lock\\\\_%%'\");" % "','".join(HOOKS))
    reset_mock_log()
    return b


def set_hpos(on):
    """Switch order storage; returns whether it is now as asked."""
    # Switching is refused while orders aren't synced between the two stores.
    if not wp_ok('wc', 'hpos', 'sync'):
        wp_ok('wc', 'cot', 'sync')
    php("use Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer;"
        "$s = wc_get_container()->get(DataSynchronizer::class); if (!$s->check_orders_table_exists()) { $s->create_database_tables(); }"
        "update_option('woocommerce_custom_orders_table_data_sync_enabled', 'yes');"
        "update_option('woocommerce_custom_orders_table_enabled', '%s');" % ('yes' if on else 'no'))
    return php("echo Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off';") == ('on' if on else 'off')


def new_order(email='woo.buyer@example.com', method='bacs', quantity=2, shipping=5, status='pending', total_delta=0, checkout=True):
    """A WooCommerce order with taxes, placed like a checkout would; returns its id."""
    order_id = php(
        "$o = wc_create_order(); $o->add_product(wc_get_product(wc_get_product_id_by_sku('WOO-TEST')), %d);"
        "$a = array('first_name' => 'Woo', 'last_name' => 'Buyer', 'email' => '%s', 'phone' => '555', 'address_1' => '1 Main St', 'city' => 'Springfield', 'postcode' => '12345', 'country' => 'US', 'state' => 'CA');"
        "$o->set_address($a, 'billing'); $o->set_address($a, 'shipping'); $o->set_payment_method('%s'); $o->set_payment_method_title('%s');"
        "if (%s > 0) { $s = new WC_Order_Item_Shipping(); $s->set_method_title('Flat rate'); $s->set_method_id('flat_rate'); $s->set_total(%s); $o->add_item($s); }"
        "$o->calculate_totals(true); if (%s != 0) { $o->set_total($o->get_total() + %s); } $o->set_status('%s'); $o->save();"
        "if (%s) { do_action('woocommerce_checkout_order_processed', $o->get_id(), array(), $o); } echo $o->get_id();"
        % (quantity, email, method, method, shipping, shipping, total_delta, total_delta, status, 'true' if checkout else 'false'))
    check(order_id.isdigit(), 'order created', order_id)
    return int(order_id)


def meta(order_id, key):
    return php("$o = wc_get_order(%d); $v = $o->get_meta('%s'); echo is_scalar($v) ? $v : json_encode($v);" % (order_id, key))


def order_total(order_id):
    return float(php("echo wc_get_order(%d)->get_total();" % order_id))


def number(order_id):
    return php("echo wc_get_order(%d)->get_order_number();" % order_id)


def queued(hook, order_id):
    return int(php("echo count(as_get_scheduled_actions(array('hook' => '%s', 'status' => 'pending', 'group' => 'invoiceninja', 'per_page' => 50,"
                   " 'args' => array(array('payload' => array('order_id' => %d), 'attempt' => 1))), 'ids'));" % (hook, order_id)))


def sync(order_id):
    return php("try { %s->invoices->sync(%d); echo 'ok'; } catch (Throwable $e) { echo 'error: ' . $e->getMessage(); }" % (M, order_id))


def record_payment(order_id):
    return php("try { %s->payments->record(%d); echo 'ok'; } catch (Throwable $e) { echo 'error: ' . $e->getMessage(); }" % (M, order_id))


def mirror_refund(order_id, refund_id):
    return php("try { %s->payments->refund(%d, %d); echo 'ok'; } catch (Throwable $e) { echo 'error: ' . $e->getMessage(); }" % (M, order_id, refund_id))


def new_refund(order_id, amount):
    refund_id = int(php("$r = wc_create_refund(array('order_id' => %d, 'amount' => %s, 'reason' => 'flow test')); echo is_wp_error($r) ? 0 : $r->get_id();" % (order_id, amount)))
    check(refund_id > 0, 'refund created in WooCommerce')
    return refund_id


def mock_invoices():
    return json.loads(php("echo json_encode(array_values(get_option('in_mock_invoices', array())));") or '[]')


def mock_payments():
    return json.loads(php("echo json_encode(array_values(get_option('in_mock_payments', array())));") or '[]')


def posts(path):
    return [c for c in api_calls() if c['method'] == 'POST' and c['url'].split('/api/v1/')[1].split('?')[0] == path]


def notes(order_id):
    return php("foreach (wc_get_order_notes(array('order_id' => %d)) as $n) { echo $n->content . \"\\n\"; }" % order_id)


@test
def woo_order_becomes_a_draft_then_a_sent_invoice_without_email():
    woo_ready()
    order_id = new_order()
    check(queued('invoiceninja_woo_invoice', order_id) == 1, 'checkout queues the invoice job')
    check(sync(order_id) == 'ok', 'job ran')
    calls = api_calls()
    created = posts('invoices')
    check(len(created) == 1, 'one invoice created', describe(calls))
    check('mark_sent' not in created[0]['url'] and 'send_email' not in created[0]['url'], 'created as a draft, with no email', created[0]['url'])
    body = json.loads(created[0]['body'])
    check(body['po_number'] == number(order_id), 'po_number is the WooCommerce order number', body)
    keys = [line['product_key'] for line in body['line_items']]
    check(keys == ['WOO-TEST', 'Shipping'], 'product (by SKU) and shipping lines', keys)
    check(body['line_items'][0]['tax_name1'] == 'VAT' and float(body['line_items'][0]['tax_rate1']) == 10, 'the tax WooCommerce charged', body['line_items'][0])
    bulk = posts('invoices/bulk')
    check(bulk and json.loads(bulk[0]['body'])['action'] == 'mark_sent', 'marked sent through bulk mark_sent (no email)', describe(calls))
    check(not any('email' in c['url'] for c in calls if '/invoices' in c['url']), 'Invoice Ninja is never asked to email', describe(calls))
    check(meta(order_id, '_invoiceninja_status') == 'sent', 'order meta: sent')
    inv = mock_invoices()[0]
    check(meta(order_id, '_invoiceninja_invoice_id') == inv['id'] and meta(order_id, '_invoiceninja_invoice_number') == inv['number'], 'invoice id and number stored on the order')
    check(abs(float(inv['amount']) - order_total(order_id)) < 0.001, 'invoice total equals the order total', (inv['amount'], order_total(order_id)))
    check(inv['status_id'] == '2', 'invoice is sent in Invoice Ninja', inv)
    check(body.get('auto_bill_enabled') is False, 'auto-bill is off: WooCommerce takes the payment, a saved card is never also charged', body)
    check('created in Invoice Ninja' in notes(order_id), 'order note added')
    link = meta(order_id, '_invoiceninja_invoice_link')
    check(link.endswith('INVITE1') and 'OTHER' not in link, "the buyer's own invitation link is kept, never another contact's", link)
    mail = php("echo InvoiceNinja\\WooCommerce\\Emails::text(wc_get_order(%d), true);" % order_id)
    check('INVITE1' in mail and inv['number'] in mail, 'customer emails get the invoice link', mail)
    box = php("echo %s->metaBox->html(wc_get_order(%d));" % (M, order_id))
    check(inv['number'] in box and 'Open in Invoice Ninja' in box and 'INVITE1' not in box, 'meta box shows the invoice and never the portal link', box)


@test
def totals_are_reconciled_rounding_adjusted_and_bigger_differences_flagged():
    woo_ready()
    order_id = new_order(email='round@example.com', total_delta=0.01)
    sync(order_id)
    body = json.loads(posts('invoices')[0]['body'])
    check(body['line_items'][-1]['product_key'] == 'Rounding' and abs(float(body['line_items'][-1]['cost']) - 0.01) < 0.0001, 'a rounding line closes a cent difference', body['line_items'])
    check(abs(float(mock_invoices()[0]['amount']) - order_total(order_id)) < 0.001, 'invoice matches the order after rounding')
    check(meta(order_id, '_invoiceninja_mismatch') in ('', '[]', 'null'), 'no mismatch alert for rounding')

    reset_mock_log()
    bad = new_order(email='mismatch@example.com', total_delta=5)
    sync(bad)
    body = json.loads(posts('invoices')[0]['body'])
    check(all(line['product_key'] != 'Rounding' for line in body['line_items']), 'a real difference is not hidden with an adjustment', body['line_items'])
    mismatch = json.loads(meta(bad, '_invoiceninja_mismatch') or '{}')
    check(abs(float(mismatch.get('difference', 0)) - 5) < 0.001, 'mismatch alert stored on the order', mismatch)
    check('differs' in notes(bad), 'order note explains the mismatch')
    check(meta(bad, '_invoiceninja_status') == 'sent', 'still marked sent, so it can be paid')


@test
def payment_recorded_on_payment_complete_but_not_for_cash_on_delivery_processing():
    woo_ready()
    cod = new_order(email='cod@example.com', method='cod')
    sync(cod)
    php("wc_get_order(%d)->update_status('processing');" % cod)
    check(queued('invoiceninja_woo_payment', cod) == 0, 'cash on delivery reaching processing queues no payment')
    record_payment(cod)
    check(not posts('payments'), 'and no payment is recorded while it is unpaid', describe(api_calls()))
    php("wc_get_order(%d)->update_status('completed');" % cod)
    check(queued('invoiceninja_woo_payment', cod) == 1, 'completing the cash-on-delivery order queues the payment')
    record_payment(cod)
    paid = posts('payments')
    check(len(paid) == 1, 'payment recorded once completed', describe(api_calls()))

    reset_mock_log()
    card = new_order(email='card@example.com', method='bacs')
    php("wc_get_order(%d)->payment_complete('TXN-%d');" % (card, card))
    check(queued('invoiceninja_woo_payment', card) == 1, 'payment_complete queues the payment')
    check(record_payment(card) == 'ok', 'payment job ran (invoicing the order first)')
    paid = posts('payments')
    check(len(paid) == 1, 'one payment', describe(api_calls()))
    body = json.loads(paid[0]['body'])
    site = wp('option', 'get', 'invoiceninja_site_code')
    check(body['idempotency_key'] == 'wp_%s_%d' % (site, card), 'idempotency key wp_<site>_<order>', body)
    check('email_receipt=false' in paid[0]['url'], 'email_receipt=false', paid[0]['url'])
    check(abs(float(body['amount']) - order_total(card)) < 0.001 and body['invoices'][0]['invoice_id'] == meta(card, '_invoiceninja_invoice_id'), 'the order total, applied to its invoice', body)
    check(meta(card, '_invoiceninja_payment_id').startswith('PAY'), 'payment id stored on the order')
    check([p for p in mock_payments() if p['transaction_reference'].startswith('TXN-%d' % card)], 'gateway transaction id kept as the reference')


@test
def retries_never_duplicate_the_invoice_or_the_payment():
    woo_ready()
    order_id = new_order(email='twice@example.com')
    sync(order_id)
    sync(order_id)
    check(len(posts('invoices')) == 1, 'running the job twice creates one invoice', describe(api_calls()))
    # A reply that got lost: the order forgot the invoice, the lookup by po_number finds it.
    php("$o = wc_get_order(%d); $o->delete_meta_data('_invoiceninja_invoice_id'); $o->update_meta_data('_invoiceninja_status', 'queued'); $o->save();" % order_id)
    sync(order_id)
    check(len(posts('invoices')) == 1 and len(mock_invoices()) == 1, 'found by po_number instead of creating another', describe(api_calls()))

    reset_mock_log()
    lost = new_order(email='timeout@example.com')
    wp('option', 'update', 'in_mock_woo_invoice_timeout', '1')
    try:
        first = sync(lost)
    finally:
        wp('option', 'delete', 'in_mock_woo_invoice_timeout')
    check(first == 'ok', 'a timeout after Invoice Ninja saved it is resolved by looking it up', first)
    sync(lost)
    mine = [i for i in mock_invoices() if i['po_number'] == number(lost)]
    check(len(mine) == 1 and meta(lost, '_invoiceninja_status') == 'sent', 'timeout after Invoice Ninja saved it: still exactly one invoice', mine)

    php("wc_get_order(%d)->payment_complete('TXN-T');" % order_id)
    record_payment(order_id)
    record_payment(order_id)
    check(len(posts('payments')) == 1, 'recording twice sends one payment', describe(api_calls()))
    # The payment id was lost: the duplicate key (422) is looked up instead.
    php("$o = wc_get_order(%d); $o->delete_meta_data('_invoiceninja_payment_id'); $o->save();" % order_id)
    php("$i = get_option('in_mock_invoices'); foreach ($i as &$x) { if ($x['po_number'] === '%s') { $x['balance'] = 1; } } update_option('in_mock_invoices', $i);" % number(order_id))
    record_payment(order_id)
    check(len(mock_payments()) == 1, 'the duplicate key makes no second payment', mock_payments())
    check(meta(order_id, '_invoiceninja_payment_id') == mock_payments()[0]['id'], 'the existing payment is found and stored')


@test
def refunds_are_mirrored_once_and_a_full_refund_cancels_the_balance():
    woo_ready()
    order_id = new_order(email='refund@example.com')
    php("wc_get_order(%d)->payment_complete('TXN-R');" % order_id)
    record_payment(order_id)
    refund_id = int(php("$r = wc_create_refund(array('order_id' => %d, 'amount' => 5, 'reason' => 'flow test')); echo is_wp_error($r) ? 0 : $r->get_id();" % order_id))
    check(refund_id > 0, 'refund created in WooCommerce')
    check(int(php("echo count(as_get_scheduled_actions(array('hook' => 'invoiceninja_woo_refund', 'status' => 'pending', 'group' => 'invoiceninja'), 'ids'));")) >= 1, 'refund job queued')
    php("%s->payments->refund(%d, %d);" % (M, order_id, refund_id))
    php("%s->payments->refund(%d, %d);" % (M, order_id, refund_id))
    refunds = posts('payments/refund')
    check(len(refunds) == 1, 'mirrored once', describe(api_calls()))
    body = json.loads(refunds[0]['body'])
    check(body['gateway_refund'] is False and body['send_email'] is False and abs(float(body['amount']) - 5) < 0.001, 'gateway_refund=false, no email, the refunded amount', body)
    check(body['invoices'][0]['invoice_id'] == meta(order_id, '_invoiceninja_invoice_id'), 'refunded against the invoice', body)
    check(abs(float(mock_payments()[0]['refunded']) - 5) < 0.001, 'payment shows the refund in Invoice Ninja')
    check(meta(order_id, '_invoiceninja_refund_balance') not in ('', '0'), 'the reopened balance after a partial refund is flagged')
    box = php("echo %s->metaBox->html(wc_get_order(%d));" % (M, order_id))
    check('Refund of' in box and 'mirrored' in box, 'meta box: refund mirrored', box)

    rest = order_total(order_id) - 5
    refund2 = int(php("$r = wc_create_refund(array('order_id' => %d, 'amount' => %s, 'reason' => 'rest')); echo is_wp_error($r) ? 0 : $r->get_id();" % (order_id, rest)))
    php("%s->payments->refund(%d, %d);" % (M, order_id, refund2))
    inv = [i for i in mock_invoices() if i['id'] == meta(order_id, '_invoiceninja_invoice_id')][0]
    check(inv['status_id'] == '5', 'fully refunded: the invoice is cancelled so nobody is asked to pay', inv)


@test
def cancelled_and_failed_orders_cancel_their_invoice():
    woo_ready()
    order_id = new_order(email='cancel@example.com')
    sync(order_id)
    php("wc_get_order(%d)->update_status('cancelled');" % order_id)
    check(queued('invoiceninja_woo_cancel', order_id) == 1, 'cancelling queues the cancel job')
    php("%s->invoices->cancel(%d);" % (M, order_id))
    bulk = [json.loads(c['body']) for c in posts('invoices/bulk')]
    check({'action': 'cancel', 'ids': [meta(order_id, '_invoiceninja_invoice_id')]} in bulk, 'invoices/bulk cancel', bulk)
    check(meta(order_id, '_invoiceninja_status') == 'cancelled', 'order meta: cancelled')

    failed = new_order(email='failed@example.com')
    sync(failed)
    php("wc_get_order(%d)->update_status('failed');" % failed)
    php("%s->invoices->cancel(%d);" % (M, failed))
    check(meta(failed, '_invoiceninja_status') == 'cancelled', 'a failed order cancels its invoice too')
    # Paid on a second try: a new invoice is made for the payment.
    php("$o = wc_get_order(%d); $o->set_status('pending'); $o->save(); $o->payment_complete('TXN-2');" % failed)
    record_payment(failed)
    mine = [i for i in mock_invoices() if i['po_number'] == number(failed)]
    check(len(mine) == 2 and meta(failed, '_invoiceninja_payment_id').startswith('PAY'), 'paid after failing: a fresh invoice takes the payment', mine)


@test
def cancelling_an_order_whose_invoice_was_deleted_or_cancelled_in_invoice_ninja():
    woo_ready()
    # Invoice Ninja keeps a deleted invoice (200, is_deleted) and refuses to cancel it (422)
    gone = new_order(email='cancel.gone@example.com')
    sync(gone)
    php("$i = get_option('in_mock_invoices'); $i['%s']['is_deleted'] = true; update_option('in_mock_invoices', $i);" % meta(gone, '_invoiceninja_invoice_id'))
    php("wc_get_order(%d)->update_status('cancelled');" % gone)
    reset_mock_log()
    php("%s->invoices->cancel(%d);" % (M, gone))
    check(not posts('invoices/bulk'), 'no cancel asked for a deleted invoice', describe(api_calls()))
    check(meta(gone, '_invoiceninja_error') != 'validation' and meta(gone, '_invoiceninja_status') == 'cancelled', 'the order is done, not an error', (meta(gone, '_invoiceninja_status'), meta(gone, '_invoiceninja_error')))
    # Cancelled there already (e.g. by hand)
    done = new_order(email='cancel.done@example.com')
    sync(done)
    php("$i = get_option('in_mock_invoices'); $i['%s']['status_id'] = '5'; update_option('in_mock_invoices', $i);" % meta(done, '_invoiceninja_invoice_id'))
    php("wc_get_order(%d)->update_status('cancelled');" % done)
    reset_mock_log()
    php("%s->invoices->cancel(%d);" % (M, done))
    check(not posts('invoices/bulk') and meta(done, '_invoiceninja_status') == 'cancelled', 'an already cancelled invoice counts as cancelled', describe(api_calls()))


@test
def order_edits_update_the_invoice_unless_invoices_are_locked():
    woo_ready()
    order_id = new_order(email='edit@example.com', quantity=1)
    sync(order_id)
    php("$o = wc_get_order(%d); foreach ($o->get_items() as $i) { $i->set_quantity(3); $i->set_subtotal(30); $i->set_total(30); $i->save(); } $o->calculate_totals(true); $o->save();"
        "do_action('woocommerce_saved_order_items', %d, array());" % (order_id, order_id))
    sync(order_id)
    puts = [c for c in api_calls() if c['method'] == 'PUT' and '/invoices/' in c['url']]
    check(len(puts) == 1, 'the invoice is updated', describe(api_calls()))
    check(abs(float(mock_invoices()[0]['amount']) - order_total(order_id)) < 0.001, 'updated invoice matches the edited order')

    wp('option', 'update', 'in_mock_lock_invoices', 'when_sent')
    php("delete_transient('invoiceninja_woo_lock_invoices');")
    reset_mock_log()
    php("$o = wc_get_order(%d); foreach ($o->get_items() as $i) { $i->set_quantity(4); $i->set_subtotal(40); $i->set_total(40); $i->save(); } $o->calculate_totals(true); $o->save();" % order_id)
    sync(order_id)
    check(not [c for c in api_calls() if c['method'] == 'PUT'], 'a locked invoice is left alone', describe(api_calls()))
    check(meta(order_id, '_invoiceninja_locked') == 'locked', 'and the order says why')
    wp('option', 'delete', 'in_mock_lock_invoices')


@test
def orders_work_with_hpos_on_and_off():
    for on in (True, False):
        woo_ready()
        if not set_hpos(on):
            print('      (could not switch HPOS %s here; skipped that half)' % ('on' if on else 'off'))
            continue
        order_id = new_order(email='hpos-%s@example.com' % ('on' if on else 'off'))
        sync(order_id)
        check(meta(order_id, '_invoiceninja_status') == 'sent', 'invoiced with HPOS %s' % ('on' if on else 'off'))
        if on:
            stored = php("global $wpdb; echo $wpdb->get_var($wpdb->prepare(\"SELECT meta_value FROM {$wpdb->prefix}wc_orders_meta WHERE order_id = %%d AND meta_key = '_invoiceninja_status'\", %d));" % order_id)
            check(stored == 'sent', 'meta lives in the HPOS tables (written through the CRUD)', stored)
        box = php("echo %s->metaBox->html(wc_get_order(%d));" % (M, order_id))
        check('Invoice ' in box, 'meta box renders', box)


@test
def store_steps_aside_and_product_pages_point_to_woocommerce():
    woo_ready()
    for_admin = php("wp_set_current_user(1); echo do_shortcode('[invoiceninja_checkout_form]');")
    check('WooCommerce runs your store' in for_admin, 'admins see the WooCommerce placeholder', for_admin)
    for_visitor = php("wp_set_current_user(0); echo do_shortcode('[invoiceninja_checkout_form]');")
    check(for_visitor.strip() == '', 'visitors see nothing', for_visitor)
    check(php("echo do_shortcode('[invoiceninja_mini_cart]');").strip() == '', 'no mini cart')
    public = php("$t = get_post_type_object('invoiceninja_product'); echo $t && $t->public ? 'public' : 'private';")
    check(public == 'private', 'our product post type is not public in Woo mode', public)


@test
def backfill_dry_run_counts_and_the_real_run_queues():
    woo_ready()
    order_id = new_order(email='backfill@example.com', status='processing', checkout=False)
    php("$o = wc_get_order(%d); $o->set_date_paid(time()); $o->save();" % order_id)
    today = php("echo wp_date('Y-m-d');")
    out = wp('invoiceninja', 'woo', 'backfill', '--from=' + today, '--to=' + today, '--dry-run')
    check('Dry run' in out and 'Nothing was changed' in out, 'CLI dry run', out)
    check(not posts('invoices'), 'a dry run sends nothing', describe(api_calls()))
    status, r = admin().api('POST', '/woo/backfill', {'from': today, 'to': today, 'dry_run': True})
    check(status == 200 and r['invoice'] >= 1 and r['payment'] >= 1, 'REST dry run counts invoices and payments', r)
    status, bad = admin().api('POST', '/woo/backfill', {'from': '2026-02-30', 'to': today})
    check(status == 400, 'bad dates refused', bad)
    out = wp('invoiceninja', 'woo', 'backfill', '--from=' + today, '--to=' + today)
    check('Backfill started' in out, 'CLI starts the backfill', out)
    run = php("echo json_encode(get_option('invoiceninja_woo_backfill'));")
    run_id = json.loads(run)['id']
    php("%s->backfill->page(array('run' => '%s'));" % (M, run_id))
    pending = int(php("echo count(as_get_scheduled_actions(array('hook' => 'invoiceninja_woo_invoice', 'status' => 'pending', 'group' => 'invoiceninja', 'per_page' => 100), 'ids'));"))
    check(pending >= 1, 'orders queued through the queue', pending)
    php("%s->invoices->handleSync(array('order_id' => %d, 'backfill' => '%s'));" % (M, order_id, run_id))
    progress = json.loads(php("echo json_encode(get_option('invoiceninja_woo_backfill'));"))
    check(progress['done'] >= 1 and progress['queued'] >= 1, 'progress counts finished orders', progress)
    out = wp('invoiceninja', 'woo', 'backfill', '--status')
    check('done' in out, 'CLI shows progress', out)

    # An order that runs out of retries still counts, so the run can finish.
    stuck = new_order(email='backfill-stuck@example.com', status='processing', checkout=False)
    php("update_option('invoiceninja_woo_backfill', array('id' => 'stuckrun', 'status' => 'queued', 'from' => '%s', 'to' => '%s', 'page' => 1,"
        " 'total' => 1, 'scanned' => 1, 'queued' => 1, 'done' => 0, 'started_at' => time(), 'touched' => time(), 'user' => 1));" % (today, today))
    wp('option', 'update', 'in_mock_mode', 'down')
    try:
        early = php("try { %s->invoices->handleSync(array('order_id' => %d, 'backfill' => 'stuckrun'), 1); echo 'ok'; } catch (Throwable $e) { echo 'error'; }" % (M, stuck))
        check(early == 'error' and json.loads(php("echo json_encode(get_option('invoiceninja_woo_backfill'));"))['done'] == 0, 'a try that will be retried is not counted yet')
        php("try { %s->invoices->handleSync(array('order_id' => %d, 'backfill' => 'stuckrun'), 9); } catch (Throwable $e) {}" % (M, stuck))
    finally:
        wp('option', 'update', 'in_mock_mode', 'up')
    progress = json.loads(php("echo json_encode(get_option('invoiceninja_woo_backfill'));"))
    check(progress['status'] == 'done', 'an order out of retries still counts, so the run finishes', progress)


@test
def a_lost_mark_sent_reply_or_a_pause_still_ends_sent_and_paid():
    woo_ready()
    order_id = new_order(email='marksent@example.com')
    wp('option', 'update', 'in_mock_mark_sent_timeout', '1')
    try:
        first = sync(order_id)
    finally:
        wp('option', 'delete', 'in_mock_mark_sent_timeout')
    check(first.startswith('error'), 'the lost mark_sent reply fails the job', first)
    check(meta(order_id, '_invoiceninja_status') == 'draft', 'the order still says draft', meta(order_id, '_invoiceninja_status'))
    check(sync(order_id) == 'ok', 'the retry ran')
    check(meta(order_id, '_invoiceninja_status') == 'sent' and meta(order_id, '_invoiceninja_error') == '', 'the retry sees the invoice sent in Invoice Ninja and says so')
    link = meta(order_id, '_invoiceninja_invoice_link')
    check('INVITE' in link and 'OTHER' not in link, "the buyer's link is filled in from the invoice", link)
    check(len(posts('invoices/bulk')) == 1, 'mark_sent is not sent again', describe(api_calls()))

    # Paused (it looks like a staging copy) after it was invoiced: the order still says sent.
    php("InvoiceNinja\\Plugin::instance()->environment->pause();")
    try:
        sync(order_id)
        check(meta(order_id, '_invoiceninja_status') == 'sent', 'a pause never hides an invoice that was sent', meta(order_id, '_invoiceninja_status'))
    finally:
        wp('invoiceninja', 'environment', 'confirm')
    php("wc_get_order(%d)->payment_complete('TXN-MS');" % order_id)
    check(record_payment(order_id) == 'ok' and len(posts('payments')) == 1, 'and the payment is recorded', describe(api_calls()))


@test
def a_refund_whose_reply_was_lost_is_never_mirrored_twice():
    woo_ready()
    order_id = new_order(email='slowrefund@example.com')
    php("wc_get_order(%d)->payment_complete('TXN-SR');" % order_id)
    record_payment(order_id)
    refund_id = new_refund(order_id, 5)
    wp('option', 'update', 'in_mock_refund_timeout', 'lost')
    try:
        first = mirror_refund(order_id, refund_id)
    finally:
        wp('option', 'delete', 'in_mock_refund_timeout')
    check(first.startswith('error'), 'no reply, and the refunded total did not move yet: try again later', first)
    pending = json.loads(meta(order_id, '_invoiceninja_refund_pending') or '{}')
    check(int(pending.get('refund_id', 0)) == refund_id and abs(float(pending.get('amount', 0)) - 5) < 0.001, 'the refund is kept as pending', pending)
    # Invoice Ninja saves it late, after we looked.
    php("$p = get_option('in_mock_payments'); foreach ($p as &$x) { if ($x['id'] === '%s') { $x['refunded'] = round($x['refunded'] + 5, 2); } } update_option('in_mock_payments', $p);" % meta(order_id, '_invoiceninja_payment_id'))
    reset_mock_log()
    check(mirror_refund(order_id, refund_id) == 'ok', 'the retry ran')
    check(not posts('payments/refund'), 'the retry sees it landed and refunds nothing again', describe(api_calls()))
    refunds = json.loads(meta(order_id, '_invoiceninja_refunds') or '{}')
    check(refunds.get(str(refund_id), {}).get('result') == 'mirrored', 'remembered as mirrored', refunds)
    check(meta(order_id, '_invoiceninja_refund_pending') in ('', '[]', 'null'), 'nothing pending any more')

    # A refund whose reply is lost but that Invoice Ninja saved: counted once, right away.
    second = new_refund(order_id, 2)
    wp('option', 'update', 'in_mock_refund_timeout', '1')
    try:
        check(mirror_refund(order_id, second) == 'ok', 'the refunded total moved, so it counts as done')
    finally:
        wp('option', 'delete', 'in_mock_refund_timeout')
    mirror_refund(order_id, second)
    check(len(posts('payments/refund')) == 1, 'sent once', describe(api_calls()))
    check(abs(float(mock_payments()[0]['refunded']) - 7) < 0.001, 'Invoice Ninja shows 7 refunded, not more', mock_payments()[0])


@test
def private_item_meta_stays_off_the_invoice_and_stock_notes_change_nothing():
    woo_ready()
    order_id = new_order(email='itemmeta@example.com', checkout=False)
    php("$o = wc_get_order(%d); foreach ($o->get_items() as $i) { $i->add_meta_data('_wc_cog_item_cost', '4.20'); $i->add_meta_data('Gift note', 'Happy birthday'); $i->save(); }" % order_id)
    sync(order_id)
    notes = json.loads(posts('invoices')[0]['body'])['line_items'][0]['notes']
    check('Gift note: Happy birthday' in notes, 'meta for the customer is on the invoice', notes)
    check('_wc_cog' not in notes and '4.20' not in notes, 'private meta (the cost) is not', notes)
    reset_mock_log()
    php("$o = wc_get_order(%d); foreach ($o->get_items() as $i) { $i->add_meta_data('_reduced_stock', 2, true); $i->save(); } do_action('woocommerce_saved_order_items', %d, array());" % (order_id, order_id))
    sync(order_id)
    check(not [c for c in api_calls() if c['method'] == 'PUT'], 'stock reduced after payment does not touch the invoice', describe(api_calls()))
    # An invoice made by an earlier version (its fingerprint can't tell): no rewrite when it charges the same.
    php("$o = wc_get_order(%d); $o->update_meta_data('_invoiceninja_lines_hash', sha1('older lines')); $o->save();" % order_id)
    sync(order_id)
    check(not [c for c in api_calls() if c['method'] == 'PUT'], 'an older invoice is not rewritten for its descriptions', describe(api_calls()))
    check(meta(order_id, '_invoiceninja_lines_hash').startswith('v2:'), 'its fingerprint is brought up to date')


@test
def a_paid_order_whose_invoice_was_deleted_gets_a_new_invoice_and_payment():
    woo_ready()
    order_id = new_order(email='deleted@example.com')
    php("wc_get_order(%d)->payment_complete('TXN-D');" % order_id)
    record_payment(order_id)
    old_payment = meta(order_id, '_invoiceninja_payment_id')
    check(old_payment.startswith('PAY'), 'paid')
    php("$i = get_option('in_mock_invoices'); $i['%s']['is_deleted'] = true; update_option('in_mock_invoices', $i);" % meta(order_id, '_invoiceninja_invoice_id'))
    sync(order_id)
    check(meta(order_id, '_invoiceninja_error') == 'invoice_gone' and meta(order_id, '_invoiceninja_payment_id') == '', 'the deleted invoice and its payment are forgotten')
    reset_mock_log()
    check(php("echo %s->metaBox->run(wc_get_order(%d));" % (M, order_id)) == 'done', 'Retry ran')
    live = [i for i in mock_invoices() if i['po_number'] == number(order_id) and not i.get('is_deleted')]
    check(len(live) == 1 and live[0]['status_id'] == '4', 'a new invoice, paid', live)
    paid = posts('payments')
    check(len(paid) == 1 and json.loads(paid[0]['body'])['idempotency_key'].endswith('_%d_r1' % order_id), 'a new payment under a new key', describe(api_calls()))
    check(meta(order_id, '_invoiceninja_payment_id') not in ('', old_payment), 'the new payment is stored')


@test
def the_rest_api_never_hands_out_the_invitation_link():
    woo_ready()
    order_id = new_order(email='rest@example.com')
    sync(order_id)
    check(meta(order_id, '_invoiceninja_invoice_link') != '', 'the link is kept on the order')
    keys = json.loads(php("wp_set_current_user(1); $r = rest_do_request(new WP_REST_Request('GET', '/wc/v3/orders/%d'));"
                          " $d = rest_get_server()->response_to_data($r, false); echo json_encode(array('status' => $r->get_status(), 'keys' => array_column((array) ($d['meta_data'] ?? array()), 'key')));" % order_id))
    check(keys['status'] == 200, 'the order is read through the REST API', keys)
    check('_invoiceninja_invoice_link' not in keys['keys'], 'without the invitation link', keys)


@test
def a_changed_billing_email_never_gets_the_first_clients_invoice_link():
    woo_ready()
    order_id = new_order(email='first.buyer@example.com')
    check(sync(order_id) == 'ok', 'invoiced')
    check(meta(order_id, '_invoiceninja_invoice_link') != '', 'link kept for the buyer')
    check(meta(order_id, '_invoiceninja_client_email') == 'first.buyer@example.com', 'with the email it was made for')
    # The admin picked the wrong customer, then corrected the billing email
    php("$o = wc_get_order(%d); $o->set_billing_email('second.buyer@example.com'); $o->save();" % order_id)
    mail = php("echo InvoiceNinja\\WooCommerce\\Emails::text(wc_get_order(%d), true);" % order_id)
    check('INVITE' not in mail, "the other customer's portal link is not emailed", mail)
    check(sync(order_id) == 'ok', 'the edit is synced')
    check('billing email changed' in notes(order_id), 'the order says what happened and what to do', notes(order_id))
    sync(order_id)
    check(notes(order_id).count('billing email changed') == 1, 'once')


@test
def catalog_push_never_brings_back_a_product_the_shop_removed():
    b = woo_ready()
    b.api('POST', '/settings', {'woo': {'catalog_push': True}})
    push = "echo wp_json_encode(%s->catalog->push(false));" % M
    try:
        php("foreach (array('Widget', 'Gadget') as $k) { $id = wc_get_product_id_by_sku($k); if ($id) { wp_delete_post($id, true); } } delete_option('invoiceninja_woo_pushed_products');")
        r = json.loads(php(push))
        check('Widget' in r['create'], 'pushed into WooCommerce', r)
        widget = php("echo wc_get_product_id_by_sku('Widget');")
        php("wp_trash_post(%s);" % widget)  # Trashed by the shop
        r = json.loads(php(push))
        check('Widget' not in r['create'], 'a trashed product is not recreated', r)
        wp('post', 'delete', widget, '--force')  # Deleted for good
        r = json.loads(php(push))
        check('Widget' not in r['create'] and php("echo (int) wc_get_product_id_by_sku('Widget');") == '0', 'nor a deleted one', r)
    finally:
        b.api('POST', '/settings', {'woo': {'catalog_push': False}})


@test
def restore_store_after_woocommerce_flows():
    """Last: switch the module off and put WooCommerce back as it was, so other suites see the plugin's own store."""
    b = admin()
    b.api('POST', '/settings', {'modules': {'woo': False}})
    php("foreach (array('%s') as $h) { as_unschedule_all_actions($h); }" % "','".join(HOOKS))
    if 'hpos_was' in _state:
        set_hpos(_state['hpos_was'])
    if not _state.get('was_active', True):
        wp('plugin', 'deactivate', 'woocommerce')
