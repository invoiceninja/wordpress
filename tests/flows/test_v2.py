"""End-to-end flows for the 2.0 foundation (M1): install/upgrade, connection, REST, screens, CLI."""

from lib import (Browser, api_calls, check, describe, option, reset_mock_log, wp, wp_eval, wp_ok,
                 in_container)

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


def admin():
    return Browser().login('admin', 'admin').rest_nonce()


def connect(browser, token='goodtoken-v2-flow', **extra):
    data = {'url': 'https://in.test', 'token': token}
    data.update(extra)
    return browser.api('POST', '/connection', data)


def table_exists(name):
    return wp_eval("global $wpdb; echo $wpdb->get_var(\"SHOW TABLES LIKE '{$wpdb->prefix}%s'\");" % name).endswith(name)


@test
def install_creates_tables_capabilities_and_secrets():
    check(wp('option', 'get', 'invoiceninja_version').startswith('2.'), 'version recorded')
    check(wp('option', 'get', 'invoiceninja_schema_version') == '8', 'schema at 8', wp('option', 'get', 'invoiceninja_schema_version'))
    check(table_exists('invoiceninja_log'), 'log table created')
    caps = wp('cap', 'list', 'administrator')
    check('manage_invoiceninja' in caps and 'view_invoiceninja' in caps, 'admin capabilities granted')
    secret = wp('option', 'get', 'invoiceninja_cart_secret')
    check(len(secret) == 64, 'per-purpose secret created', secret)
    check(len(wp('option', 'get', 'invoiceninja_site_code')) == 2, 'site code created')


@test
def upgrade_from_1x_encrypts_the_token_and_keeps_1x_working():
    # One process: every wp-cli call boots the plugin, which would run the upgrade itself
    wp_eval("delete_option('invoiceninja_version'); delete_option('invoiceninja_upgraded_from'); update_option('invoiceninja_schema_version', 0);"
            "update_option('invoiceninja_api_token', 'goodtoken-legacy-plain');"
            "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();")
    stored = wp('option', 'get', 'invoiceninja_api_token')
    check(stored.startswith('in1:') and 'goodtoken' not in stored, 'plain 1.x token encrypted in place', stored)
    check(wp('option', 'get', 'invoiceninja_upgraded_from') == '1.x', 'upgrade source recorded')
    check(wp('option', 'get', 'invoiceninja_schema_version') == '8', 'all migrations ran')
    reset_mock_log()
    wp_eval('InvoiceNinja\\Controllers\\ProductController::loadProducts();')
    calls = api_calls()
    check(calls and calls[0].get('token') == 'goodtoken-legacy-plain', '1.x code still talks to Invoice Ninja', describe(calls))
    option('invoiceninja_api_token', 'goodtoken1234567890')


@test
def rest_routes_need_capabilities_except_ping():
    anon = Browser()
    status, body = anon.api('GET', '/ping')
    check(status == 200 and body.get('ok') is True, 'ping is public', (status, body))
    for method, path in [('GET', '/connection'), ('POST', '/connection'), ('DELETE', '/connection'),
                         ('POST', '/connection/test'), ('GET', '/settings'), ('POST', '/settings'),
                         ('GET', '/diagnostics'), ('GET', '/logs'), ('DELETE', '/logs'), ('POST', '/environment'),
                         ('POST', '/mail/test'), ('GET', '/admin/orders'), ('POST', '/admin/orders/1/cancel')]:
        status, _ = anon.api(method, path, {} if method != 'GET' else None)
        check(status in (401, 403), '%s %s refuses anonymous users' % (method, path), status)


@test
def connection_test_reports_each_problem_in_plain_language():
    b = admin()
    status, ok = b.api('POST', '/connection/test', {'url': 'in.test', 'token': 'goodtoken-v2'})
    check(status == 200 and ok['ok'], 'good token passes', ok)
    check([c['status'] for c in ok['checks']] == ['pass'] * 6, 'all six checks pass', ok['checks'])
    check(ok['company']['name'] == 'Mock Company' and ok['gateways'] == ['Stripe'], 'company and gateway', ok)
    check('settings' not in ok['company'], 'company secrets never leave the server')

    cases = {'badtoken123456': 'auth', 'nonadmin-token': 'non_admin', 'freeplan-token': 'plan_required'}
    for token, kind in cases.items():
        _, r = b.api('POST', '/connection/test', {'url': 'https://in.test', 'token': token})
        check(not r['ok'] and r['error']['kind'] == kind, '%s -> %s' % (token, kind), r.get('error'))
        check(r['error']['title'] and r['error']['fix'], 'plain sentence and a fix for %s' % kind)

    option('in_mock_version', '5.12.8')
    try:
        _, r = b.api('POST', '/connection/test', {'url': 'https://in.test', 'token': 'goodtoken-v2'})
        check(r['error']['kind'] == 'version_too_old', 'old Invoice Ninja is refused', r.get('error'))
    finally:
        wp('option', 'delete', 'in_mock_version')

    status, r = b.api('POST', '/connection/test', {'url': 'not a url', 'token': 'x'})
    check(status == 400, 'invalid address rejected', (status, r))


@test
def connecting_stores_only_an_encrypted_token():
    b = admin()
    status, r = connect(b, token='goodtoken-SECRET-9f2e7')
    check(status == 200 and r['saved'], 'connection saved', r)
    check(r['connection']['state']['status'] == 'connected', 'state connected')
    check('goodtoken-SECRET' not in str(r), 'token never returned')
    dump = in_container('mysqldump -h db -uroot -proot wordpress 2>/dev/null || true')
    if not dump.strip():
        dump = wp('db', 'export', '-', check_ok=False)
    check('goodtoken-SECRET-9f2e7' not in dump, 'database dump contains no plain token')
    _, logs = b.api('GET', '/logs?level=info&per_page=50')
    check(any('Connected to Mock Company' in e['message'] for e in logs['items']), 'connection logged', logs)
    check('goodtoken-SECRET' not in str(logs), 'token never logged')
    # The saved token is reused when only re-testing the same address
    _, again = b.api('POST', '/connection/test', {'url': 'https://in.test'})
    check(again['ok'], 'saved token reused for a re-test', again)
    # Changing the address needs the token again
    status, _ = b.api('POST', '/connection/test', {'url': 'https://other.test'})
    check(status == 400, 'a new address needs the token')


@test
def switching_company_needs_confirmation():
    b = admin()
    connect(b)
    wp_eval("update_option('invoiceninja_connection', array_merge(get_option('invoiceninja_connection'), array('company' => array('id' => 'OLD', 'name' => 'Old Co'))));")
    _, r = connect(b)
    check(not r['saved'] and r['needs_switch_confirmation']['from'] == 'Old Co', 'asks before switching', r)
    _, r = connect(b, confirm_switch=True)
    check(r['saved'], 'switches after confirmation', r)


@test
def disconnect_keeps_the_address_and_clears_the_token():
    b = admin()
    connect(b)
    status, r = b.api('DELETE', '/connection')
    check(status == 200 and r['state']['status'] == 'disconnected', 'disconnected', r)
    check(wp('option', 'get', 'invoiceninja_api_token', check_ok=False) in ('', 'false'), 'token removed')
    check(r['url'] == 'https://in.test', 'address kept for reconnecting')
    connect(b)


@test
def diagnostics_run_and_report_problems_first():
    b = admin()
    connect(b)
    status, d = b.api('GET', '/diagnostics')
    check(status == 200, 'diagnostics ran', d)
    ids = [c['id'] for c in d['checks']]
    for expected in ['environment', 'configuration', 'encryption', 'token', 'version', 'webhooks', 'gateways', 'loopback', 'scheduler', 'database', 'mail', 'environment_change']:
        check(expected in ids, 'check %s present' % expected, ids)
    order = {'fail': 0, 'warn': 1, 'info': 2, 'skip': 3, 'pass': 4}
    ranks = [order[c['status']] for c in d['checks']]
    check(ranks == sorted(ranks), 'problems first', [(c['id'], c['status']) for c in d['checks']])
    loop = next(c for c in d['checks'] if c['id'] == 'loopback')
    check(loop['status'] == 'pass', 'loopback works through the dev proxy', loop)
    status, r = b.api('POST', '/diagnostics/repair')
    check(status == 200 and r['done'], 'repair ran', r)


@test
def staging_sites_pause_orders_until_allowed():
    b = admin()
    wp('option', 'delete', 'invoiceninja_environment', check_ok=False)
    _, c = b.api('GET', '/connection')
    check(not c['environment']['allowed'] and not c['environment']['looks_live'], 'localhost is treated as a copy', c['environment'])
    _, c = b.api('POST', '/environment', {'action': 'allow'})
    check(c['environment']['allowed'], 'allowed after confirming')
    _, c = b.api('POST', '/environment', {'action': 'pause'})
    check(not c['environment']['allowed'], 'paused again')
    status, _ = b.api('POST', '/environment', {'action': 'nonsense'})
    check(status == 400, 'unknown action rejected')


@test
def settings_are_validated():
    b = admin()
    status, s = b.api('POST', '/settings', {'log_level': 'loud'})
    check(status == 400, 'unknown log level rejected', s)
    status, s = b.api('POST', '/settings', {'notifications': {'recipients': 'ok@x.com, not-an-email'}})
    check(status == 400, 'bad recipient rejected', s)
    status, s = b.api('POST', '/settings', {'log_level': 'info', 'notifications': {'leads': 'daily'}, 'unknown': 1})
    check(status == 200 and s['log_level'] == 'info' and s['notifications']['leads'] == 'daily', 'saved', s)
    check('unknown' not in s, 'unknown keys dropped')


@test
def screens_load_and_assets_stay_on_our_pages():
    b = Browser().login('admin', 'admin')
    for page, screen in [('invoiceninja', 'home'), ('invoiceninja-setup', 'setup'), ('invoiceninja-settings', 'settings'), ('invoiceninja-status', 'status'), ('invoiceninja-legacy', None)]:
        status, _, html = b.get('/wp-admin/admin.php?page=' + page)
        check(status == 200, '%s loads' % page, status)
        if screen:
            check('data-screen="%s"' % screen in html, '%s renders its screen' % page)
            check('assets/admin/admin.js' in html, '%s loads the admin script' % page)
            check('goodtoken' not in html, 'no token in %s' % page)
        else:
            check('Products &amp; Settings (legacy)' in html or 'Products & Settings (legacy)' in html, 'legacy page under the new menu')
    _, _, dash = b.get('/wp-admin/index.php')
    check('assets/admin/admin.js' not in dash and 'assets/css/settings.css' not in dash, 'no plugin assets on other admin pages')
    _, _, front = Browser().get('/')
    check('assets/admin/' not in front, 'no admin assets on the front end')


@test
def mail_test_is_recorded():
    b = admin()
    status, r = b.api('POST', '/mail/test', {'to': 'owner@example.com'})
    check(status == 200 and r['to'] == 'owner@example.com', 'test sent', r)
    _, r = b.api('POST', '/mail/confirm', {'arrived': False})
    check(r['test']['confirmed'] is False, 'answer recorded', r)
    status, _ = b.api('POST', '/mail/test', {'to': 'nope'})
    check(status == 400, 'invalid address rejected')


@test
def cli_status_doctor_and_environment():
    out = wp('invoiceninja', 'status')
    check('connected' in out and 'Mock Company' in out, 'status shows the connection', out)
    out = wp('invoiceninja', 'environment', 'confirm')
    check('allowed' in out, 'environment confirm', out)
    check(wp_ok('invoiceninja', 'doctor', '--repair'), 'doctor --repair exits cleanly (no failing checks)')


# ---------- M2: product sync ----------

def product_ids():
    out = wp('post', 'list', '--post_type=invoiceninja_product', '--post_status=any', '--fields=ID,post_title,post_status', '--format=csv')
    rows = [line.split(',') for line in out.splitlines()[1:]]
    return {r[1]: (r[0], r[2]) for r in rows}


def meta(post_id, key):
    return wp('post', 'meta', 'get', post_id, key, check_ok=False)


def sync(b, **data):
    status, r = b.api('POST', '/sync/products', data)
    check(status == 200, 'sync ran', r)
    return r


@test
def upgrade_links_1x_products_and_retires_the_cron():
    wp_eval("InvoiceNinja\\Plugin::instance()->mapping->clear('product'); update_option('invoiceninja_schema_version', 3); InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();")
    count = int(wp_eval("echo InvoiceNinja\\Plugin::instance()->mapping->count('product');"))
    check(count == int(wp('post', 'list', '--post_type=invoiceninja_product', '--post_status=any', '--format=count')), '1.x posts linked', count)


@test
def sync_updates_products_in_place():
    b = admin()
    connect(b)
    before = product_ids()
    option('in_mock_price_bump', '2')
    try:
        r = sync(b)
        check(r['result']['status'] == 'synced' and r['result']['updated'] >= 2, 'prices updated', r)
        after = product_ids()
        check(after['Widget'][0] == before['Widget'][0] and after['Gadget'][0] == before['Gadget'][0], 'post ids unchanged (URLs stay)', (before, after))
        check(meta(after['Widget'][0], 'price') == '12', 'new price stored', meta(after['Widget'][0], 'price'))
        r = sync(b)
        check(r['result']['updated'] == 0 and r['result']['unchanged'] >= 2, 'unchanged products are skipped', r)
    finally:
        wp('option', 'delete', 'in_mock_price_bump')
        sync(admin())


@test
def archived_products_are_unpublished_not_deleted():
    b = admin()
    option('in_mock_archived', 'P2')
    try:
        r = sync(b)
        check(r['result']['drafted'] == 1, 'one product unpublished', r)
        check(product_ids()['Gadget'][1] == 'draft', 'archived product is a draft')
    finally:
        wp('option', 'delete', 'in_mock_archived')
    sync(b)
    check(product_ids()['Gadget'][1] == 'publish', 'restored product is published again')


@test
def outage_changes_nothing():
    b = admin()
    before = product_ids()
    option('in_mock_mode', 'down')
    try:
        r = sync(b)
        check(r['result']['status'] == 'failed', 'sync reports the failure', r)
    finally:
        option('in_mock_mode', 'up')
    check(product_ids() == before, 'every product kept during an outage')


@test
def circuit_breaker_stops_mass_changes_until_reviewed():
    b = admin()
    option('in_mock_many', '12')
    try:
        sync(b)  # adds the extra products (new products don't trip the breaker on a small catalog)
        option('in_mock_price_bump', '7')
        r = sync(b)
        check(r['result']['status'] == 'paused', 'mass price change paused', r)
        check(r['paused']['changes'] >= 12 and r['paused']['sample'], 'review details stored', r['paused'])
        check(meta(product_ids()['Widget'][0], 'price') != '17', 'nothing changed before review')
        _, d = b.api('GET', '/diagnostics')
        check(any(c['id'] == 'product_sync' and c['status'] == 'fail' and c['action'] == 'review_sync' for c in d['checks']), 'Status shows the paused sync', d['checks'])
        r = sync(b, force=True)
        check(r['result']['status'] == 'synced' and not r['paused'], 'applied after review', r)
        check(meta(product_ids()['Widget'][0], 'price') == '17', 'price applied')
    finally:
        wp('option', 'delete', 'in_mock_price_bump')
        wp('option', 'delete', 'in_mock_many')
        sync(b, force=True)
        # Remove the extra test products so later runs start from the two base products
        wp_eval("global $wpdb; foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'any', 'numberposts' => -1)) as $p) {"
                " $id = get_post_meta($p->ID, 'product_id', true); if (!in_array($id, array('P1', 'P2'), true)) {"
                " wp_delete_post($p->ID, true); $wpdb->delete($wpdb->prefix . 'invoiceninja_map', array('in_type' => 'product', 'in_id' => $id)); } }")


@test
def upgraded_catalog_syncs_without_tripping_the_breaker():
    b = admin()
    option('in_mock_many', '12')
    try:
        sync(b)
        # Simulate a 1.x site: posts exist but nothing is linked yet
        wp_eval("InvoiceNinja\\Plugin::instance()->mapping->clear('product'); update_option('invoiceninja_schema_version', 3); InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();")
        r = sync(b)
        check(r['result']['status'] == 'synced', 'first sync after linking 1.x posts runs (12 products, none really changed)', r)
        r = sync(b)
        check(r['result']['updated'] == 0, 'then everything is unchanged', r)
    finally:
        wp('option', 'delete', 'in_mock_many')
        wp_eval("global $wpdb; foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'any', 'numberposts' => -1)) as $p) {"
                " $id = get_post_meta($p->ID, 'product_id', true); if (!in_array($id, array('P1', 'P2'), true)) {"
                " wp_delete_post($p->ID, true); $wpdb->delete($wpdb->prefix . 'invoiceninja_map', array('in_type' => 'product', 'in_id' => $id)); } }")


@test
def order_numbers_are_unique_and_readable():
    out = wp_eval("$n = new InvoiceNinja\\Commerce\\OrderNumbers(InvoiceNinja\\Plugin::instance()->options, InvoiceNinja\\Plugin::instance()->secrets); echo $n->next(), ' ', $n->next();")
    a, b = out.split()
    code = wp('option', 'get', 'invoiceninja_site_code')
    check(a.startswith('WEB-' + code + '-') and b.startswith('WEB-' + code + '-'), 'prefix and site code', out)
    check(int(b.rsplit('-', 1)[1]) == int(a.rsplit('-', 1)[1]) + 1, 'sequence increments', out)


# ---------- M2: cart and checkout ----------

def mails(to=None):
    from lib import mock_log
    return [e for e in mock_log() if e.get('event') == 'mail' and (to is None or to in str(e.get('to')))]


def last_code(to):
    import re
    for m in reversed(mails(to)):
        found = re.match(r'^(\d{6}) is your', m['subject'])
        if found:
            return found.group(1)
    raise AssertionError('no code mailed to ' + to)


def shopper():
    s = Browser()
    s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 2})
    return s


def checkout(s, email, **extra):
    details = {'email': email, 'first_name': 'Gina', 'last_name': 'Guest'}
    status, r = s.api('POST', '/checkout/start', details)
    check(status == 200 and r['ok'], 'checkout started', r)
    if r['next'] == 'code':
        details['code'] = last_code(email)
    details.update(extra)
    return s.api('POST', '/checkout/confirm', details)


def store_ready():
    b = admin()
    connect(b)
    b.api('POST', '/settings', {'store': {'mode': 'cart', 'require_login': False}})
    wp('invoiceninja', 'environment', 'confirm')
    sync(b)
    wp('option', 'delete', 'in_mock_invoices', check_ok=False)
    wp('option', 'delete', 'in_mock_paid', check_ok=False)
    # Every flow checks out from the same IP: start each with fresh rate-limit counters.
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")
    reset_mock_log()


@test
def cart_uses_synced_prices_and_limits():
    store_ready()
    s = Browser()
    status, r = s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 500, 'price': 0.01})
    check(status == 200 and r['quantity'] == 5, 'quantity limited to the product maximum', r)
    check(r['cart']['total'] == 50.0, 'price comes from the synced product, not the request', r['cart'])
    _, r = s.api('POST', '/cart/items', {'product': 'NOPE', 'quantity': 1})
    check(not r['ok'], 'unknown products are refused', r)
    anon = Browser()
    import urllib.request, urllib.error, json as _json
    req = urllib.request.Request(anon_base() + '/wp-json/invoiceninja/v1/cart/items', data=_json.dumps({'product': 'P1', 'quantity': 1}).encode(), headers={'Content-Type': 'application/json'}, method='POST')
    try:
        anon.opener.open(req, timeout=30)
        cross = 200
    except urllib.error.HTTPError as e:
        cross = e.code
    check(cross in (401, 403), 'writes without a same-site Origin are refused', cross)


def anon_base():
    from lib import BASE
    return BASE


@test
def guest_checkout_with_email_code():
    store_ready()
    s = shopper()
    email = 'gina.guest@example.com'
    status, r = s.api('POST', '/checkout/start', {'email': email, 'first_name': 'Gina', 'last_name': 'Guest'})
    check(status == 200 and r['next'] == 'code', 'a code is required', r)
    writes = [c for c in api_calls() if c['method'] != 'GET']
    check(not writes, 'nothing is created in Invoice Ninja before the code is confirmed', describe(writes))
    status, r = s.api('POST', '/checkout/confirm', {'email': email, 'code': '000000' if last_code(email) != '000000' else '111111'})
    check(status == 422 and r['code'] == 'code_wrong', 'wrong code refused', r)
    status, r = s.api('POST', '/checkout/confirm', {'email': email, 'code': last_code(email), 'remember': True, 'first_name': 'Gina', 'last_name': 'Guest'})
    check(status == 200 and r['status'] == 'awaiting_payment', 'order placed', r)
    check(r['pay_link'].startswith('https://in.test/client/invoice/'), 'payment link returned to the buyer', r)
    check(r['number'].startswith('WEB-'), 'readable order number', r['number'])
    check(r['email'] == 'g***@example.com', 'email masked in the reply')
    invoice = [c for c in api_calls() if c['method'] == 'POST' and '/invoices' in c['url']]
    check(len(invoice) == 1 and r['number'] in invoice[0]['body'], 'one invoice with the order number as PO number', describe(invoice))
    check(any('complete your payment' in m['subject'] for m in mails(email)), 'payment email sent')
    # Order status with the key; the same reply for a wrong key or number
    key = r['status_url'].split('key=')[1]
    status, o = Browser().api('GET', '/orders/%s?key=%s' % (r['number'], key))
    check(status == 200 and o['status'] == 'awaiting_payment', 'order status with key', o)
    status, _ = Browser().api('GET', '/orders/%s?key=%s' % (r['number'], 'x' * 32))
    check(status == 404, 'wrong key refused')
    # Paid in Invoice Ninja -> paid here
    inv = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['invoice_id'];" % r['number'])
    option('in_mock_paid', '')
    wp_eval("update_option('in_mock_paid', array('%s')); $o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); delete_transient('invoiceninja_order_check_' . $o['id']);" % (inv, r['number']))
    status, o = Browser().api('GET', '/orders/%s?key=%s' % (r['number'], key))
    check(o['status'] == 'paid', 'payment detected', o)
    # Buying the same items again from the same browser is a new order
    s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 2})
    _, again = s.api('POST', '/checkout/confirm', {'email': email, 'first_name': 'Gina', 'last_name': 'Guest'})
    check(again['ok'] and again['number'] != r['number'], 'a repeat purchase is a new order', again)
    # Remembered device skips the code next time
    s2 = s
    s2.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
    _, r2 = s2.api('POST', '/checkout/start', {'email': email})
    check(r2['next'] == 'confirm', 'remembered device skips the code', r2)
    _, r3 = s2.api('POST', '/checkout/start', {'email': 'someone.else@example.com'})
    check(r3['next'] == 'code', 'but only for the same email', r3)


@test
def timeout_after_invoice_created_makes_one_invoice():
    store_ready()
    option('in_mock_invoice_timeout', '1')
    try:
        status, r = checkout(shopper(), 'timeout.buyer@example.com')
    finally:
        wp('option', 'delete', 'in_mock_invoice_timeout')
    check(status == 200 and r['status'] == 'awaiting_payment', 'found the invoice that was created despite the timeout', r)
    count = wp_eval("echo count(array_filter(get_option('in_mock_invoices', array()), function($i) { return $i['po_number'] === '%s'; }));" % r['number'])
    check(count == '1', 'exactly one invoice for the order', count)


@test
def invoice_ninja_down_queues_the_order():
    store_ready()
    option('in_mock_mode', 'down')
    try:
        status, r = checkout(shopper(), 'down.buyer@example.com')
    finally:
        option('in_mock_mode', 'up')
    check(status == 200 and r['status'] == 'queued' and not r['pay_link'], 'order saved while Invoice Ninja is down', r)
    check(any('received order' in m['subject'] for m in mails('down.buyer@example.com')), '"order received" email sent')
    # The retry job is scheduled a minute out; run it now
    wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); InvoiceNinja\\Plugin::instance()->orderService->retry(array('order_id' => $o['id']));" % r['number'])
    order = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['status'];" % r['number'])
    check(order == 'awaiting_payment', 'queued order sent once Invoice Ninja is back', order)
    check(any('complete your payment' in m['subject'] for m in mails('down.buyer@example.com')), 'payment email follows')


@test
def buyer_only_gets_their_own_portal_link():
    store_ready()
    option('in_mock_other_contact_first', '1')
    try:
        status, r = checkout(shopper(), 'a+b@x.com')
    finally:
        wp('option', 'delete', 'in_mock_other_contact_first')
    check(status == 200 and r['status'] == 'awaiting_payment', 'order placed for an existing client', r)
    check('OTHER' not in r['pay_link'] and r['pay_link'].endswith('INVITE1'), "the buyer's own invitation, never another contact's", r['pay_link'])


@test
def cancelled_invoice_is_not_paid():
    store_ready()
    status, r = checkout(shopper(), 'cancel.buyer@example.com')
    wp_eval("$i = get_option('in_mock_invoices'); foreach ($i as &$x) { $x['status_id'] = '5'; $x['balance'] = 0; } update_option('in_mock_invoices', $i);")
    key = r['status_url'].split('key=')[1]
    _, o = Browser().api('GET', '/orders/%s?key=%s' % (r['number'], key))
    check(o['status'] != 'paid', 'a cancelled invoice (no balance) is not treated as paid', o)


@test
def paused_site_takes_no_orders():
    store_ready()
    wp('invoiceninja', 'environment', 'pause')
    try:
        s = shopper()
        status, r = s.api('POST', '/checkout/start', {'email': 'paused@example.com'})
        check(status == 422 and r['code'] == 'unavailable', 'checkout closed on a paused site', r)
    finally:
        wp('invoiceninja', 'environment', 'confirm')


@test
def atomic_counters_count_from_one():
    out = wp_eval("$o = InvoiceNinja\\Plugin::instance()->options; delete_option('invoiceninja_ctr_test_1'); echo $o->increment('ctr_test_1'), ',', $o->increment('ctr_test_1'), ',', $o->increment('ctr_test_1');")
    check(out == '1,2,3', 'counter increments from 1', out)


@test
def admin_orders_list_resend_retry_and_cancel():
    store_ready()
    _, paid_later = checkout(shopper(), 'orders.one@example.com')
    option('in_mock_mode', 'down')
    try:
        _, queued = checkout(shopper(), 'orders.two@example.com')
    finally:
        option('in_mock_mode', 'up')
    b = admin()
    status, r = b.api('GET', '/admin/orders?status=queued')
    check(status == 200 and [o['number'] for o in r['items']] == [queued['number']], 'filter by status', r)
    check(r['counts'].get('awaiting_payment', 0) >= 1 and r['counts'].get('queued') == 1, 'counts per status', r['counts'])
    _, r = b.api('GET', '/admin/orders?search=orders.one')
    row = r['items'][0]
    check(row['number'] == paid_later['number'] and row['name'] == 'Gina Guest', 'search by email', row)
    inv_id = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['invoice_id'];" % row['number'])
    check(row['invoice_url'].startswith('https://in.test/app/invoices/%s' % inv_id), 'link to the invoice through Invoice Ninja app links (5.13.44+)', row['invoice_url'])
    option('in_mock_version', '5.13.30')
    try:
        b.api('POST', '/connection/refresh', {})
        _, old = b.api('GET', '/admin/orders?search=orders.one')
        check(old['items'][0]['invoice_url'] == 'https://in.test/#/invoices/%s/edit' % inv_id, 'older servers get the web app route', old['items'][0]['invoice_url'])
    finally:
        wp('option', 'delete', 'in_mock_version')
        b.api('POST', '/connection/refresh', {})
    check('pay_link' not in row and 'INVITE' not in str(row), 'no payment or portal links on the admin screen', row)
    check('#key=' in row['status_url'], 'status link carries its key in the fragment', row['status_url'])

    reset_mock_log()
    status, r = b.api('POST', '/admin/orders/%d/resend' % row['id'], {})
    check(status == 200 and any('complete your payment' in m['subject'] for m in mails('orders.one@example.com')), 'payment email resent', r)

    status, r = b.api('POST', '/admin/orders/%d/retry' % [o for o in b.api('GET', '/admin/orders?status=queued')[1]['items']][0]['id'], {})
    check(status == 200 and r['status'] == 'awaiting_payment', 'queued order placed on retry', r)

    reset_mock_log()
    status, r = b.api('POST', '/admin/orders/%d/cancel' % row['id'], {'email': True})
    check(status == 200 and r['status'] == 'cancelled', 'order cancelled', r)
    bulk = [c for c in api_calls() if c['method'] == 'POST' and c['url'].endswith('/invoices/bulk')]
    check(len(bulk) == 1 and '"cancel"' in bulk[0]['body'], 'invoice cancelled in Invoice Ninja', describe(bulk))
    check(any('was cancelled' in m['subject'] for m in mails('orders.one@example.com')), 'buyer told about the cancellation')
    status, r = b.api('POST', '/admin/orders/%d/resend' % row['id'], {})
    check(status == 409, 'no payment email for a cancelled order', (status, r))
    reset_mock_log()
    status, r = b.api('POST', '/admin/orders/%d/cancel' % row['id'], {'email': True})
    check(status == 409 and not mails('orders.one@example.com'), 'cancelling twice is refused and emails nobody', (status, r))

    # A paid order is never cancelled, even if the site hasn't noticed the payment yet
    _, paid = checkout(shopper(), 'orders.paid@example.com')
    paid_id = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['id'], ' ', $o['invoice_id'];" % paid['number']).split()
    wp_eval("update_option('in_mock_paid', array('%s'));" % paid_id[1])
    reset_mock_log()
    status, r = b.api('POST', '/admin/orders/%s/resend' % paid_id[0], {})
    check(status == 409 and not mails('orders.paid@example.com'), 'no payment email for a paid order', (status, r))
    status, r = b.api('POST', '/admin/orders/%s/cancel' % paid_id[0], {'email': True})
    check(status == 409 and not mails('orders.paid@example.com'), 'a paid order is not cancelled', (status, r))
    check(wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->find(%s)['status'];" % paid_id[0]) == 'paid', 'and is marked paid')

    # An order being placed right now can't be cancelled or retried
    option('in_mock_mode', 'down')
    try:
        _, busy = checkout(shopper(), 'orders.busy@example.com')
    finally:
        option('in_mock_mode', 'up')
    busy_id = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); InvoiceNinja\\Plugin::instance()->orders->claim($o['id']); echo $o['id'];" % busy['number'])
    for action in ('cancel', 'retry'):
        status, r = b.api('POST', '/admin/orders/%s/%s' % (busy_id, action), {})
        check(status == 409, '%s refused while the order is being placed' % action, (status, r))
    # Cancelled while its invoice was being created: that invoice is cancelled too
    out = wp_eval(
        "$p = InvoiceNinja\\Plugin::instance(); $o = $p->orders->find(%s);"
        "add_filter('pre_http_request', function($r, $args, $url) use ($p, $o) { if (strpos($url, '/invoices') !== false && $args['method'] === 'POST' && strpos($url, 'bulk') === false) { $p->orders->update($o['id'], array('status' => 'cancelled')); } return $r; }, 1, 3);"
        "$p->orders->update($o['id'], array('status' => 'queued'));"
        "$after = $p->orderService->place($p->orders->find($o['id']), false);"
        "$inv = get_option('in_mock_invoices'); echo $after['status'], ' ', $inv[$after['invoice_id']]['status_id'];" % busy_id)
    check(out == 'cancelled 5', 'an invoice created after the cancel is cancelled in Invoice Ninja', out)
    status, _ = b.api('POST', '/admin/orders/999999/cancel', {})
    check(status == 404, 'unknown order', status)
    status, _, html = b.get('/wp-admin/admin.php?page=invoiceninja-orders')
    check(status == 200 and 'data-screen="orders"' in html, 'orders screen renders', status)


@test
def stock_is_checked_live_before_the_code():
    store_ready()
    b = admin()
    option('in_mock_track_inventory', '1')
    b.api('POST', '/connection/refresh', {})
    # Start from Invoice Ninja's numbers even if an earlier run left a live value behind
    wp_eval("$p = InvoiceNinja\\Plugin::instance(); $m = $p->mapping->find('product', 'P1'); $p->mapping->save('product', 'P1', 'post', $m['wp_id'], '');")
    sync(b)
    try:
        s = Browser()
        _, r = s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 3})
        check(r['quantity'] == 3, 'three in stock at the last sync', r)
        option('in_mock_live_stock', 'P1:1')
        reset_mock_log()
        status, r = s.api('POST', '/checkout/start', {'email': 'stock.buyer@example.com'})
        check(status == 422 and r['code'] == 'cart_changed' and r['cart']['lines'][0]['quantity'] == 1, 'only one left now: the cart is lowered before the code', (status, r))
        check(not mails('stock.buyer@example.com'), 'no code sent until the buyer has seen the new total')
        status, r = s.api('POST', '/checkout/start', {'email': 'stock.buyer@example.com'})
        check(status == 200 and r['next'] == 'code', 'continuing works: the lowered cart was saved', (status, r))
        _, cart = s.api('GET', '/cart')
        check(cart['count'] == 1, 'cart shows the new quantity', cart)
        # Invoice Ninja too slow to answer: the synced numbers apply and checkout goes on
        s2 = Browser()
        s2.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
        option('in_mock_slow_stock', '1')
        status, r = s2.api('POST', '/checkout/start', {'email': 'slow.stock@example.com'})
        check(status == 200 and r['next'] == 'code', 'a slow stock check never blocks checkout', (status, r))
    finally:
        for name in ('in_mock_live_stock', 'in_mock_slow_stock'):
            wp('option', 'delete', name, check_ok=False)
        option('in_mock_track_inventory', '0')
        b.api('POST', '/connection/refresh', {})
        r = sync(b)
    check(meta(product_ids()['Widget'][0], 'in_stock_quantity') == '3', 'the next sync restores the stock from Invoice Ninja', r)


@test
def abandoned_cleanup_is_off_by_default_and_only_closes_unpaid_invoices():
    store_ready()
    b = admin()
    b.api('POST', '/settings', {'store': {'abandoned_enabled': False, 'abandoned_action': 'cancel'}})
    # Orders from other flows count as recent here
    wp_eval("global $wpdb; $wpdb->query($wpdb->prepare('UPDATE ' . InvoiceNinja\\Commerce\\Orders::table() . ' SET created_at = %s, placed_at = %s, note = \\'\\'', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')));" + "delete_option('invoiceninja_abandoned_cursor');")
    try:
        numbers = {}
        for name in ('unpaid', 'paid', 'partial', 'recent'):
            _, r = checkout(shopper(), 'abandon.%s@example.com' % name)
            numbers[name] = r['number']
        def order(name):
            out = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['id'], ' ', $o['status'], ' ', $o['invoice_id'];" % numbers[name])
            return out.split()
        for name in ('unpaid', 'paid', 'partial'):
            wp_eval("global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS), 'placed_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)), array('number' => '%s'));" % numbers[name])
        wp_eval("update_option('in_mock_paid', array('%s')); $i = get_option('in_mock_invoices'); $i['%s']['paid_to_date'] = 5; $i['%s']['balance'] -= 5; update_option('in_mock_invoices', $i);" % (order('paid')[2], order('partial')[2], order('partial')[2]))
        run = "echo wp_json_encode(InvoiceNinja\\Plugin::instance()->abandoned->run());"
        check(wp_eval(run) == '{"checked":0,"cleaned":0,"skipped":0}', 'nothing happens while the setting is off')
        status, r = b.api('POST', '/settings', {'store': {'abandoned_action': 'erase'}})
        check(status == 400, 'unknown action refused', (status, r))
        status, r = b.api('POST', '/settings', {'store': {'abandoned_enabled': True, 'abandoned_days': 14}})
        check(status == 200, 'turned on', r)
        reset_mock_log()
        out = wp_eval(run)
        check(out == '{"checked":3,"cleaned":1,"skipped":2}', 'three old orders checked, one closed', out)
        inv = wp_eval("echo wp_json_encode(array_map(function($i) { return array($i['status_id'], !empty($i['is_deleted'])); }, get_option('in_mock_invoices')));")
        check(order('unpaid')[1] == 'cancelled' and '"%s":["5",false]' % order('unpaid')[2] in inv, 'the unpaid invoice is cancelled', (order('unpaid'), inv))
        check(order('paid')[1] == 'paid', 'a paid invoice is recorded as paid, never cancelled', order('paid'))
        check(order('partial')[1] == 'awaiting_payment', 'a partly paid invoice is left alone', order('partial'))
        check(wp_eval(run) == '{"checked":0,"cleaned":0,"skipped":0}', 'and is not checked again on later runs')
        # Saved 20 days ago but only invoiced now (Invoice Ninja was down): not abandoned
        wp_eval("global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)), array('number' => '%s'));" % numbers['recent'])
        wp_eval(run)
        check(order('recent')[1] == 'awaiting_payment', 'age counts from when the invoice was sent', order('recent'))
        # Invoice deleted in Invoice Ninja: the order is closed and doesn't block later runs
        _, r = checkout(shopper(), 'abandon.gone@example.com')
        numbers['gone'] = r['number']
        wp_eval("global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('placed_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)), array('number' => '%s'));" % r['number'])
        wp_eval("$i = get_option('in_mock_invoices'); unset($i['%s']); update_option('in_mock_invoices', $i);" % order('gone')[2])
        wp_eval(run)
        check(order('gone')[1] == 'cancelled', 'an invoice deleted in Invoice Ninja closes the order', order('gone'))
        check(order('recent')[1] == 'awaiting_payment', 'recent orders are left alone', order('recent'))
        check(not [c for c in api_calls() if c['method'] == 'POST' and order('partial')[2] in c['body']], 'nothing is sent for the partly paid invoice')
        # Delete, when chosen
        _, r = checkout(shopper(), 'abandon.delete@example.com')
        numbers['delete'] = r['number']
        wp_eval("global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('created_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS), 'placed_at' => gmdate('Y-m-d H:i:s', time() - 20 * DAY_IN_SECONDS)), array('number' => '%s'));" % r['number'])
        b.api('POST', '/settings', {'store': {'abandoned_action': 'delete'}})
        reset_mock_log()
        wp_eval(run)
        inv = wp_eval("$i = get_option('in_mock_invoices'); echo !empty($i['%s']['is_deleted']) ? 'deleted' : 'kept';" % order('delete')[2])
        check(inv == 'deleted' and order('delete')[1] == 'cancelled', 'the invoice is deleted when the admin chose that', (inv, order('delete')))
        bulk = [c['body'] for c in api_calls() if c['url'].endswith('/invoices/bulk')]
        check(len(bulk) == 2 and '"cancel"' in bulk[0] and '"delete"' in bulk[1], 'cancelled first, so a just-paid invoice is never deleted', bulk)
    finally:
        b.api('POST', '/settings', {'store': {'abandoned_enabled': False, 'abandoned_action': 'cancel'}})


@test
def upgrade_keeps_the_1x_purchase_mode_and_login_requirement():
    try:
        for legacy, mode in (('single', 'buy_now'), ('multiple', 'cart'), ('disabled', 'off')):
            out = wp_eval("update_option('invoiceninja_schema_version', 6); $o = InvoiceNinja\\Plugin::instance()->options;"
                          "$o->saveSettings(array('store' => array('mode' => 'cart', 'require_login' => false, 'display' => 'blocks')));"
                          "update_option('invoiceninja_online_purchases', '%s'); InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();"
                          "echo wp_json_encode(InvoiceNinja\\Plugin::instance()->options->settings()['store']);" % legacy)
            check('"mode":"%s"' % mode in out and '"require_login":true' in out and '"display":"legacy"' in out, '1.x %s becomes %s, login required, legacy display' % (legacy, mode), out)
        out = wp_eval("update_option('invoiceninja_schema_version', 6); delete_option('invoiceninja_online_purchases'); $o = InvoiceNinja\\Plugin::instance()->options;"
                      "$o->saveSettings(array('store' => array('mode' => 'cart', 'require_login' => false)));"
                      "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();"
                      "echo wp_json_encode(InvoiceNinja\\Plugin::instance()->options->settings()['store']);")
        check('"mode":"off"' in out, "a 1.x site that never turned purchases on stays off", out)
        # Both ways: 1.x code reads Settings > Store, and writing the 1.x option moves it
        wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('store' => array('mode' => 'off')));")
        check(wp_eval("echo get_option('invoiceninja_online_purchases', 'disabled');") == 'disabled', '1.x buttons follow Settings > Store')
        wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('store' => array('mode' => 'cart')));")
        check(wp('option', 'get', 'invoiceninja_online_purchases') == 'multiple', 'changing the Store mode updates the 1.x option')
        wp('option', 'update', 'invoiceninja_online_purchases', 'single')
        check('"mode":"buy_now"' in wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->options->settings()['store']);"), 'and the 1.x option moves Settings > Store')
    finally:
        wp('option', 'update', 'invoiceninja_online_purchases', 'multiple')
        wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('store' => array('mode' => 'cart', 'require_login' => false)));")


@test
def store_mode_and_login_requirement_are_enforced():
    store_ready()
    b = admin()
    try:
        b.api('POST', '/settings', {'store': {'require_login': True}})
        s = shopper()
        status, r = s.api('POST', '/checkout/start', {'email': 'needs.login@example.com'})
        check(status == 422 and r['code'] == 'login_required' and 'wp-login.php' in r['login_url'], 'guests are asked to log in', (status, r))
        status, r = s.api('POST', '/checkout/confirm', {'email': 'needs.login@example.com', 'code': '123456'})
        check(status == 422 and r['code'] == 'login_required', 'and confirm refuses too', (status, r))
        # A logged-in customer (store pages send no REST nonce) can order, as their account's email
        member = Browser().login('admin', 'admin')
        member.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
        status, r = member.api('POST', '/checkout/start', {'email': 'someone.else@example.com'})
        check(status == 200 and r['ok'], 'logged-in customers pass the login requirement', (status, r))
        check(not mails('someone.else@example.com'), 'and order as their own account, not a typed email')
        # Buy Now
        b.api('POST', '/settings', {'store': {'require_login': False, 'mode': 'cart'}})
        s = Browser()
        s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 2})
        s.api('POST', '/cart/items', {'product': 'P2', 'quantity': 1})
        b.api('POST', '/settings', {'store': {'mode': 'buy_now'}})
        status, r = s.api('POST', '/checkout/start', {'email': 'buy.now@example.com'})
        check(status == 422 and r['code'] == 'cart_changed' and [l['product'] for l in r['cart']['lines']] == ['P2'], 'a cart from before Buy Now is trimmed to one product at checkout', (status, r))
        s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 2})
        _, r = s.api('POST', '/cart/items', {'product': 'P2', 'quantity': 1})
        check([l['product'] for l in r['cart']['lines']] == ['P2'], 'Buy Now keeps only the product just chosen', r['cart'])
        out = wp_eval("add_filter('invoiceninja_track_inventory', '__return_true'); $c = InvoiceNinja\\Plugin::instance()->cart; $id = str_repeat('a', 32);"
                      "$c->set($id, 'P1', 1, true); $c->set($id, 'P2', 1, true); echo wp_json_encode($c->items($id));")
        check(out == '[]', 'Buy Now on a sold-out product empties the cart instead of buying the previous one', out)
        b.api('POST', '/settings', {'store': {'mode': 'off'}})
        status, r = Browser().api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
        check(status == 403 and r['code'] == 'store_off', 'no cart when ordering is off', (status, r))
        status, r = b.api('POST', '/settings', {'store': {'mode': 'shop'}})
        check(status == 400, 'unknown mode refused', (status, r))
    finally:
        b.api('POST', '/settings', {'store': {'mode': 'cart', 'require_login': False}})
