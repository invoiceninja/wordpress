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
    check(wp('option', 'get', 'invoiceninja_schema_version') == '4', 'schema at 4', wp('option', 'get', 'invoiceninja_schema_version'))
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
    check(wp('option', 'get', 'invoiceninja_schema_version') == '4', 'all migrations ran')
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
                         ('POST', '/mail/test')]:
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
def order_numbers_are_unique_and_readable():
    out = wp_eval("$n = new InvoiceNinja\\Commerce\\OrderNumbers(InvoiceNinja\\Plugin::instance()->options, InvoiceNinja\\Plugin::instance()->secrets); echo $n->next(), ' ', $n->next();")
    a, b = out.split()
    code = wp('option', 'get', 'invoiceninja_site_code')
    check(a.startswith('WEB-' + code + '-') and b.startswith('WEB-' + code + '-'), 'prefix and site code', out)
    check(int(b.rsplit('-', 1)[1]) == int(a.rsplit('-', 1)[1]) + 1, 'sequence increments', out)
