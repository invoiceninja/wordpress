"""M4 flows: Invoice Ninja webhooks (Registrar, Receiver, Dispatcher).

Invoice Ninja is faked by dev/mu-plugins/invoiceninja-mock.php plus invoiceninja-mock-webhooks.php.
"""

import json
import urllib.error
import urllib.request

from lib import BASE, api_calls, check, describe, option, reset_mock_log, wp, wp_eval
from test_v2 import admin, checkout, connect, product_ids, meta, shopper, store_ready, sync

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


EVENTS = [1, 10, 11, 37, 45, 2, 8, 9, 33, 41, 60, 24, 22, 4, 31, 12, 32, 40, 21, 50, 51, 52, 53, 54]


def secret():
    return wp_eval("echo InvoiceNinja\\Plugin::instance()->secrets->get('webhook_secret');")


def clear_counters():
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")


def send(event, body, key=None, method='POST', query=None):
    """Call the receiver like Invoice Ninja does. Returns (status, headers, body)."""
    headers = {'Content-Type': 'application/json'}
    if key is not None:
        headers['X-Invoiceninja-Secret'] = key
    url = BASE + '/wp-json/invoiceninja/v1/webhook?' + (query if query is not None else 'event=%d' % event)
    data = json.dumps(body).encode() if method != 'GET' else None
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        resp = urllib.request.urlopen(req, timeout=60)
        return resp.status, resp.headers, resp.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.headers, e.read().decode()


def run_jobs(hook):
    """Run the pending queue jobs for a hook now (Action Scheduler would, a moment later)."""
    wp_eval("foreach (as_get_scheduled_actions(array('hook' => '%s', 'status' => 'pending', 'per_page' => 100), 'ids') as $id) {"
            " ActionScheduler::runner()->process_action($id); }" % hook)


def pending(hook):
    return int(wp_eval("echo count(as_get_scheduled_actions(array('hook' => '%s', 'status' => 'pending', 'per_page' => 100), 'ids'));" % hook))


def mock_hooks():
    return json.loads(wp_eval("echo wp_json_encode(array_values((array) get_option('in_mock_webhooks', array())));") or '[]')


def state():
    return json.loads(wp_eval("echo wp_json_encode(get_option('invoiceninja_webhooks'));") or 'null') or {}


def ready():
    """Connected, orders allowed, webhooks registered against the mock."""
    wp('option', 'delete', 'in_mock_webhooks', check_ok=False)
    for name in ('in_mock_webhooks_forbidden', 'in_mock_webhooks_unreachable', 'in_mock_product_override', 'in_mock_payments', 'in_mock_missing'):
        wp('option', 'delete', name, check_ok=False)
    wp('invoiceninja', 'environment', 'confirm')
    wp_eval("as_unschedule_all_actions('invoiceninja_webhook_dispatch');")
    b = admin()
    connect(b)
    run_jobs('invoiceninja_webhooks_register')
    clear_counters()
    return b


@test
def connecting_registers_one_webhook_per_event():
    wp('option', 'delete', 'in_mock_webhooks', check_ok=False)
    # A webhook someone else made in Invoice Ninja: never touched
    wp_eval("update_option('in_mock_webhooks', array('THEIRS' => array('id' => 'THEIRS', 'target_url' => 'https://zapier.test/hook', 'event_id' => '4', 'rest_method' => 'post', 'headers' => array(), 'is_deleted' => false, 'archived_at' => 0)));")
    wp('invoiceninja', 'environment', 'confirm')
    reset_mock_log()
    b = admin()
    connect(b)
    check(pending('invoiceninja_webhooks_register') >= 1, 'registration queued on connect')
    run_jobs('invoiceninja_webhooks_register')
    hooks = [h for h in mock_hooks() if h['id'] != 'THEIRS']
    check(sorted(int(h['event_id']) for h in hooks) == sorted(EVENTS), 'one webhook per event', [h['event_id'] for h in hooks])
    key = secret()
    for h in hooks:
        check('/invoiceninja/v1/webhook' in h['target_url'] and h['target_url'].endswith('event=' + h['event_id']), 'target names the event', h)
        check(h['headers'] == {'X-Invoiceninja-Secret': key}, 'secret sent as a header', h)
        check(h['rest_method'] == 'post' and h['format'] == 'JSON', 'JSON POST', h)
    s = state()
    check(s['mode'] == 'instant' and len(s['hooks']) == len(EVENTS), 'ids stored', s)

    # Running again (the nightly check) creates nothing new
    reset_mock_log()
    run_jobs('invoiceninja_webhooks_register')
    wp_eval("do_action('invoiceninja_daily_tasks');")
    run_jobs('invoiceninja_webhooks_register')
    creates = [c for c in api_calls() if c['method'] == 'POST' and c['url'].endswith('/webhooks')]
    check(not creates, 'existing webhooks are kept', describe(creates))

    # Invoice Ninja deleted one (after a 404): the nightly check puts it back
    wp_eval("$h = get_option('in_mock_webhooks'); foreach ($h as &$w) { if ($w['event_id'] === '51') { $w['is_deleted'] = true; $w['archived_at'] = time(); } } update_option('in_mock_webhooks', $h);")
    wp_eval("do_action('invoiceninja_daily_tasks');")
    run_jobs('invoiceninja_webhooks_register')
    active = [h for h in mock_hooks() if h['event_id'] == '51' and not h['is_deleted']]
    check(len(active) == 1, 'missing webhook re-created', mock_hooks())

    _, d = b.api('GET', '/diagnostics')
    check(any(c['id'] == 'webhooks_registered' and c['status'] == 'pass' for c in d['checks']), 'Diagnostics: webhooks registered', [c for c in d['checks'] if 'webhook' in c['id']])

    # Disconnecting removes ours only
    reset_mock_log()
    b.api('DELETE', '/connection')
    remaining = [h for h in mock_hooks() if not h['is_deleted']]
    check([h['id'] for h in remaining] == ['THEIRS'], 'our webhooks deleted on disconnect, theirs kept', remaining)
    bulk = [c for c in api_calls() if c['url'].endswith('/webhooks/bulk')]
    check(bulk and 'THEIRS' not in bulk[0]['body'], 'one bulk delete of our ids', describe(bulk))
    connect(b)
    run_jobs('invoiceninja_webhooks_register')


@test
def without_webhook_access_it_polls_instead():
    b = ready()
    option('in_mock_webhooks_forbidden', '1')
    wp('option', 'delete', 'in_mock_webhooks')
    try:
        wp_eval("do_action('invoiceninja_daily_tasks');")
        run_jobs('invoiceninja_webhooks_register')
        s = state()
        check(s['mode'] == 'polling' and s['reason'] == 'not_allowed', 'falls back to polling', s)
        wp_eval("do_action('action_scheduler_init');")
        check(pending('invoiceninja_webhooks_poll') == 1, 'a 15-minute poll is scheduled')
        _, d = b.api('GET', '/diagnostics')
        check(any(c['id'] == 'webhooks_registered' and c['status'] == 'warn' for c in d['checks']), 'Diagnostics warns', d['checks'])
        _, w = b.api('GET', '/webhooks')
        check(w['mode'] == 'polling' and 'every 15 minutes' in w['message'], 'status explains it', w)
    finally:
        wp('option', 'delete', 'in_mock_webhooks_forbidden')
    _, w = b.api('POST', '/webhooks')
    check(w['mode'] == 'instant' and w['complete'], 'repair registers them once allowed', w)
    wp_eval("do_action('action_scheduler_init');")
    check(pending('invoiceninja_webhooks_poll') == 0, 'polling stops')


@test
def receiver_replies_only_202_401_or_429():
    ready()
    key = secret()
    status, _, _ = send(51, {'id': 'P1'})
    check(status == 401, 'no secret: 401', status)
    status, _, _ = send(51, {'id': 'P1'}, 'wrong')
    check(status == 401, 'wrong secret: 401', status)
    for method in ('POST', 'PUT', 'PATCH', 'DELETE', 'GET'):
        status, headers, _ = send(51, {'id': 'P1'}, key, method=method)
        check(status == 202, '%s with the secret: 202' % method, status)
    for query, body in (('event=3', {'id': 'Q1'}), ('event=51', {}), ('', {'id': 'P1'}), ('event=abc', {'id': 'P1'})):
        status, _, _ = send(0, body, key, query=query)
        check(status == 202, 'unknown event or no id is accepted and ignored (never 404/405/410): %r' % query, status)
    check(pending('invoiceninja_webhook_dispatch') == 1, 'the five calls for one product queued one job')
    clear_counters()
    for _ in range(30):
        send(51, {'id': 'P1'}, 'wrong')
    status, headers, _ = send(51, {'id': 'P1'}, key)
    check(status == 429 and int(headers.get('Retry-After', '0')) > 0, 'too many wrong secrets: 429 with Retry-After', (status, dict(headers)))
    clear_counters()
    run_jobs('invoiceninja_webhook_dispatch')


@test
def a_forged_body_is_not_trusted():
    store_ready()
    ready()
    before = product_ids()
    reset_mock_log()
    status, _, _ = send(51, {'id': 'P1', 'product_key': 'Hacked', 'price': 0.01, 'in_stock_quantity': 999}, secret())
    check(status == 202, 'accepted')
    run_jobs('invoiceninja_webhook_dispatch')
    after = product_ids()
    check('Hacked' not in after and after['Widget'][0] == before['Widget'][0], 'name from Invoice Ninja, not the body', after)
    check(meta(after['Widget'][0], 'price') == '10', 'price from Invoice Ninja, not the body', meta(after['Widget'][0], 'price'))
    check(any(c['method'] == 'GET' and c['url'].endswith('/products/P1') for c in api_calls()), 'the product was fetched again', describe(api_calls()))
    # An id Invoice Ninja doesn't have does nothing
    option('in_mock_missing', '')
    wp_eval("update_option('in_mock_missing', array('products/NOPE', 'payments/NOPE'));")
    try:
        send(50, {'id': 'NOPE', 'product_key': 'Fake'}, secret())
        send(4, {'id': 'NOPE', 'invoices': [{'id': 'INV1'}]}, secret())
        run_jobs('invoiceninja_webhook_dispatch')
    finally:
        wp('option', 'delete', 'in_mock_missing')
    check('Fake' not in product_ids(), 'no product created from a forged id')


@test
def a_product_update_syncs_just_that_product():
    b = admin()
    store_ready()
    ready()
    before = product_ids()
    wp_eval("update_option('in_mock_product_override', array('P1' => array('product_key' => 'Widget', 'notes' => 'Widget description', 'price' => 42, 'max_quantity' => 5, 'in_stock_quantity' => 3)));")
    try:
        reset_mock_log()
        send(51, {'id': 'P1'}, secret())
        run_jobs('invoiceninja_webhook_dispatch')
        after = product_ids()
        check(after['Widget'][0] == before['Widget'][0], 'same post (URL kept)')
        check(meta(after['Widget'][0], 'price') == '42', 'new price applied', meta(after['Widget'][0], 'price'))
        check(meta(after['Gadget'][0], 'price') == '25.5', 'other products untouched')
        lists = [c for c in api_calls() if c['method'] == 'GET' and '/products?' in c['url']]
        check(not lists, 'no full sync', describe(lists))
        # Archived in Invoice Ninja: the post becomes a draft
        wp_eval("update_option('in_mock_product_override', array('P1' => array('product_key' => 'Widget', 'notes' => 'Widget description', 'price' => 42, 'max_quantity' => 5, 'in_stock_quantity' => 3, 'archived_at' => 1700000000)));")
        send(54, {'id': 'P1'}, secret())
        run_jobs('invoiceninja_webhook_dispatch')
        check(product_ids()['Widget'][1] == 'draft', 'archived product unpublished')
    finally:
        wp('option', 'delete', 'in_mock_product_override')
        sync(b)
    check(product_ids()['Widget'][1] == 'publish', 'published again after the full sync')


@test
def a_payment_event_marks_the_order_paid():
    store_ready()
    ready()
    status, r = checkout(shopper(), 'hook.buyer@example.com')
    check(status == 200 and r['status'] == 'awaiting_payment', 'order placed', r)
    order = json.loads(wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'));" % r['number']))
    inv = order['invoice_id']
    wp_eval("update_option('in_mock_payments', array('PAY1' => array('id' => 'PAY1', 'amount' => 20, 'invoices' => array(array('id' => '%s')), 'paymentables' => array(array('invoice_id' => '%s')))));" % (inv, inv))
    # Not paid yet in Invoice Ninja: a payment event (forged or early) changes nothing
    send(4, {'id': 'PAY1'}, secret())
    run_jobs('invoiceninja_webhook_dispatch')
    check(wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->find(%d)['status'];" % order['id']) == 'awaiting_payment', 'unpaid invoice: order stays unpaid')
    # Paid; the shopper's live check just ran, so its throttle is set
    wp_eval("update_option('in_mock_paid', array('%s')); set_transient('invoiceninja_order_check_%d', 1, 15);" % (inv, order['id']))
    send(4, {'id': 'PAY1'}, secret())
    run_jobs('invoiceninja_webhook_dispatch')
    check(wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->find(%d)['status'];" % order['id']) == 'paid', 'order marked paid by the webhook')
    wp('option', 'delete', 'in_mock_payments')


@test
def quote_approved_fires_the_contract_action():
    ready()
    out = wp_eval("$got = ''; add_action('invoiceninja_webhook_quote_approved', function ($q, $e) use (&$got) { $got = $q['id'] . ':' . $e; }, 10, 2);"
                  "InvoiceNinja\\Plugin::instance()->module('webhooks')->dispatcher->handle(array('event' => 21, 'id' => 'Q7')); echo $got;")
    check(out == 'Q7:21', 'invoiceninja_webhook_quote_approved fired with the fetched quote', out)
