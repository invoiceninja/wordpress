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
    check(wp('option', 'get', 'invoiceninja_schema_version') == '11', 'schema at 11', wp('option', 'get', 'invoiceninja_schema_version'))
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
            "update_option('invoiceninja_profile', wp_json_encode(array('company_key' => 'ck_legacy_key', 'settings' => array('name' => 'Mock Company', 'postmark_secret' => 'pm_legacy_secret'))));"
            "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();")
    stored = wp('option', 'get', 'invoiceninja_api_token')
    check(stored.startswith('in1:') and 'goodtoken' not in stored, 'plain 1.x token encrypted in place', stored)
    check(wp('option', 'get', 'invoiceninja_upgraded_from') == '1.x', 'upgrade source recorded')
    check(wp('option', 'get', 'invoiceninja_schema_version') == '11', 'all migrations ran')
    profile = wp('option', 'get', 'invoiceninja_profile')
    check('Mock Company' in profile and 'ck_legacy_key' not in profile and 'pm_legacy_secret' not in profile,
          'the 1.x company profile keeps only its allowlist', profile)
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
        # Every product sold some: Invoice Ninja changed only the stock, which shoppers don't see as a change
        option('in_mock_stock_all', '1')
        reset_mock_log()
        r = sync(b)
        check(r['result']['status'] == 'synced' and r['result']['updated'] >= 12 and not r['paused'], 'stock changes on every product do not pause the sync', r)
        check(meta(product_ids()['Extra 3'][0], 'in_stock_quantity') == '1', 'the stock is stored', meta(product_ids()['Extra 3'][0], 'in_stock_quantity'))
        option('in_mock_price_bump', '7')
        r = sync(b)
        check(r['result']['status'] == 'paused', 'mass price change paused', r)
        paused_mails = [m for m in mails('admin@example.com') if 'paused' in m['subject']]
        check(len(paused_mails) == 1, 'admins get one email', mails())
        r = sync(b)
        check(r['result']['status'] == 'paused' and len([m for m in mails('admin@example.com') if 'paused' in m['subject']]) == 1, '...not one per run', mails())
        _, _, html = Browser().login('admin', 'admin').get('/wp-admin/')
        check('Product sync is paused' in html, 'and a notice on the Dashboard', html[:300])
        check(r['paused']['changes'] >= 12 and r['paused']['sample'], 'review details stored', r['paused'])
        check(meta(product_ids()['Widget'][0], 'price') != '17', 'nothing changed before review')
        _, d = b.api('GET', '/diagnostics')
        check(any(c['id'] == 'product_sync' and c['status'] == 'fail' and c['action'] == 'review_sync' for c in d['checks']), 'Status shows the paused sync', d['checks'])
        r = sync(b, force=True)
        check(r['result']['status'] == 'synced' and not r['paused'], 'applied after review', r)
        check(meta(product_ids()['Widget'][0], 'price') == '17', 'price applied')
    finally:
        wp('option', 'delete', 'in_mock_price_bump')
        wp('option', 'delete', 'in_mock_stock_all', check_ok=False)
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
def large_catalogs_sync_in_steps():
    b = admin()
    option('in_mock_many', '12')
    try:
        # One run writes 5 products (a filter makes the batch small); the rest continue in queued runs
        out = wp_eval("add_filter('invoiceninja_product_sync_batch', function () { return 5; }); $p = InvoiceNinja\\Plugin::instance();"
                      " foreach (array('P3', 'P4', 'P5', 'P6', 'P7', 'P8', 'P9', 'P10', 'P11', 'P12') as $id) { $m = $p->mapping->find('product', $id); if ($m) { wp_delete_post($m['wp_id'], true); } }"
                      " global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->prefix}invoiceninja_map WHERE in_type = 'product' AND in_id NOT IN ('P1', 'P2')\");"
                      " $first = $p->products->run(true); $busy = $p->products->run();"
                      " $queued = as_has_scheduled_action('invoiceninja_sync_products', array(array('payload' => array('full' => true, 'continue' => true), 'attempt' => 1)), 'invoiceninja');"
                      " $runs = array(); do { $r = $p->products->run(true, false, true); $runs[] = $r; } while ($r['remaining'] > 0 && count($runs) < 5);"
                      " echo wp_json_encode(array('first' => $first, 'busy' => $busy, 'queued' => $queued, 'runs' => $runs, 'count' => $p->mapping->count('product'), 'continue' => get_option('invoiceninja_sync_continue')));")
        r = json_loads(out)
        check(r['first']['created'] == 5 and r['first']['remaining'] == 5, 'the first run writes one batch', r['first'])
        check(r['queued'], 'and queues the next step', r)
        check(r['busy']['status'] == 'busy', 'the hourly run waits for the steps', r['busy'])
        check(r['count'] == 12 and r['runs'][-1]['remaining'] == 0 and not r['continue'], 'the steps finish the catalog without pausing', r)
        _, _, html = Browser().login('admin', 'admin').get('/wp-admin/')
        check('Product sync is paused' not in html, 'no false alarm', html[:300])
    finally:
        wp_eval("as_unschedule_all_actions('invoiceninja_sync_products', array(), 'invoiceninja'); delete_option('invoiceninja_sync_continue');")
        wp('option', 'delete', 'in_mock_many')
        wp_eval("global $wpdb; foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'any', 'numberposts' => -1)) as $p) {"
                " $id = get_post_meta($p->ID, 'product_id', true); if (!in_array($id, array('P1', 'P2'), true)) {"
                " wp_delete_post($p->ID, true); $wpdb->delete($wpdb->prefix . 'invoiceninja_map', array('in_type' => 'product', 'in_id' => $id)); } }")


@test
def one_failed_daily_check_is_rechecked_before_admins_are_emailed():
    b = admin()
    connect(b)
    wp_eval("delete_option('invoiceninja_incident_open'); as_unschedule_all_actions('invoiceninja_connection_recheck', array(), 'invoiceninja');")
    reset_mock_log()
    option('in_mock_mode', 'down')
    try:
        wp_eval("InvoiceNinja\\Plugin::instance()->daily();")
        check(not [m for m in mails() if 'connection problem' in m['subject']], 'no email for one failed check', mails())
        queued = wp_eval("echo as_has_scheduled_action('invoiceninja_connection_recheck', null, 'invoiceninja') ? 'yes' : 'no';")
        check(queued == 'yes', 'checked again in 15 minutes', queued)
        # Still down at the next two checks: now admins hear about it, once
        wp_eval("do_action('invoiceninja_connection_recheck', array('payload' => array('failures' => 1), 'attempt' => 1));"
                "do_action('invoiceninja_connection_recheck', array('payload' => array('failures' => 2), 'attempt' => 1));"
                "do_action('invoiceninja_connection_recheck', array('payload' => array('failures' => 3), 'attempt' => 1));")
        check(len([m for m in mails() if 'connection problem' in m['subject']]) == 1, 'one email after three failed checks', mails())
    finally:
        option('in_mock_mode', 'up')
    wp_eval("do_action('invoiceninja_connection_recheck', array('payload' => array('failures' => 4), 'attempt' => 1));")
    check(any('is back' in m['subject'] for m in mails()), 'the next check after it works says it is back', mails())
    wp_eval("as_unschedule_all_actions('invoiceninja_connection_recheck', array(), 'invoiceninja');")


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
    b.api('POST', '/settings', {'store': {'mode': 'cart', 'require_login': False}, 'modules': {'woo': False}})
    wp('invoiceninja', 'environment', 'confirm')
    sync(b)
    wp('option', 'delete', 'in_mock_invoices', check_ok=False)
    wp('option', 'delete', 'in_mock_paid', check_ok=False)
    # Flows add to the cart and check out at once; abuse_protection tests the minimum time.
    option('in_mock_checkout_min_seconds', '0')
    # Every flow checks out from the same IP: start each with fresh rate-limit counters,
    # and orders from earlier flows don't count towards today's unpaid orders per email.
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");"
            "$wpdb->query($wpdb->prepare('UPDATE ' . InvoiceNinja\\Commerce\\Orders::table() . ' SET created_at = %s WHERE created_at > %s', gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)));")
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


def json_loads(raw):
    import json
    return json.loads(raw.decode() if isinstance(raw, bytes) else raw)


def run_jobs(hook):
    """Run the pending queue jobs for a hook now (Action Scheduler would, a moment later)."""
    wp_eval("foreach (as_get_scheduled_actions(array('hook' => '%s', 'status' => 'pending', 'per_page' => 100), 'ids') as $id) {"
            " ActionScheduler::runner()->process_action($id); }" % hook)


def order_row(number):
    import json
    return json.loads(wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'));" % number))


def invoice_bodies():
    import json
    return [json.loads(c['body']) for c in api_calls() if c['method'] == 'POST' and c['url'].startswith('https://in.test/api/v1/invoices') and '/bulk' not in c['url']]


def client_bodies():
    import json
    return [json.loads(c['body']) for c in api_calls() if c['method'] == 'POST' and c['url'].split('?')[0] == 'https://in.test/api/v1/clients']


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
    check(status == 200 and queued['number'] in [o['number'] for o in r['items']] and all(o['status'] == 'queued' for o in r['items']), 'filter by status', r)
    check(r['counts'].get('awaiting_payment', 0) >= 1 and r['counts'].get('queued', 0) >= 1, 'counts per status', r['counts'])
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
    # This flow's orders from earlier runs would hit the open-orders cap.
    wp_eval("global $wpdb; $wpdb->query('DELETE FROM ' . InvoiceNinja\\Commerce\\Orders::table() . \" WHERE email LIKE 'abandon.%'\");")
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


@test
def ship_review_regressions():
    store_ready()
    b = admin()
    # A resubmit after a lost reply gets the same order, not "your cart is empty"
    import copy
    s = shopper()
    email = 'lost.reply@example.com'
    s.api('POST', '/checkout/start', {'email': email})
    saved = [copy.copy(c) for c in s.jar]
    status, first = s.api('POST', '/checkout/confirm', {'email': email, 'code': last_code(email)})
    check(status == 200 and first['ok'], 'order placed', first)
    for c in saved:
        s.jar.set_cookie(c)  # as if the reply (and its cookies) never arrived
    status, again = s.api('POST', '/checkout/confirm', {'email': email, 'code': last_code(email)})
    check(status == 200 and again['number'] == first['number'], 'the resubmit returns the same order', again)
    check(wp_eval("echo count(array_filter(get_option('in_mock_invoices', array()), function($i) { return $i['po_number'] === '%s'; }));" % first['number']) == '1', 'and no second invoice')
    # A job can schedule its own next round while it runs
    out = wp_eval("$q = InvoiceNinja\\Plugin::instance()->queue; add_action('in_test_self', function () use ($q) { echo $q->push('in_test_self', array(), 60, true) > 0 ? 'queued' : 'skipped'; });"
                  "$q->push('in_test_self', array(), 0, true); $ids = as_get_scheduled_actions(array('hook' => 'in_test_self', 'status' => 'pending'), 'ids');"
                  "ActionScheduler::runner()->process_action(reset($ids)); as_unschedule_all_actions('in_test_self');")
    check(out == 'queued', 'a running job may queue its follow-up', out)
    # Saving the legacy settings form on a fresh 2.0 store doesn't turn the store off
    wp('option', 'delete', 'invoiceninja_online_purchases', check_ok=False)
    _, _, html = b.get('/wp-admin/admin.php?page=invoiceninja-legacy')
    check('name="invoiceninja_online_purchases" value="multiple"' in html, 'the 1.x form carries the current Store mode', html[html.find('online_purchases') - 80:html.find('online_purchases') + 120])
    # Admin cancel when the invoice was deleted in Invoice Ninja
    _, r = checkout(shopper(), 'gone.admin@example.com')
    oid, inv = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); echo $o['id'], ' ', $o['invoice_id'];" % r['number']).split()
    wp_eval("$i = get_option('in_mock_invoices'); unset($i['%s']); update_option('in_mock_invoices', $i);" % inv)
    status, r = b.api('POST', '/admin/orders/%s/cancel' % oid, {})
    check(status == 200 and r['status'] == 'cancelled', 'an order whose invoice was deleted in Invoice Ninja can be cancelled', (status, r))
    # A 2.0 site (not from 1.x) keeps its store open through migration 7
    out = wp_eval("update_option('invoiceninja_schema_version', 6); delete_option('invoiceninja_online_purchases'); update_option('invoiceninja_upgraded_from', '');"
                  "InvoiceNinja\\Plugin::instance()->options->saveSettings(array('store' => array('mode' => 'cart', 'require_login' => false)));"
                  "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade(); echo InvoiceNinja\\Plugin::instance()->options->setting('store.mode');")
    check(out == 'cart', 'a connected 2.0 store is not mistaken for a 1.x upgrade', out)


@test
def final_review_regressions():
    store_ready()
    b = admin()
    # Queued filter: the total matches the rows
    option('in_mock_mode', 'down')
    try:
        _, q = checkout(shopper(), 'queued.count@example.com')
    finally:
        option('in_mock_mode', 'up')
    _, r = b.api('GET', '/admin/orders?status=queued')
    check(r['total'] == len(r['items']), 'queued count matches its list', (r['total'], len(r['items'])))
    # An order whose placing request died can be cancelled, and the daily sweep re-queues it
    oid = wp_eval("$o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('status' => 'placing', 'updated_at' => gmdate('Y-m-d H:i:s', time() - 900)), array('id' => $o['id'])); echo $o['id'];" % q['number'])
    check(wp_eval("echo implode(',', InvoiceNinja\\Plugin::instance()->orders->staleClaims());").split(',').count(oid) == 1, 'the stuck order is found by the sweep')
    status, r = b.api('POST', '/admin/orders/%s/cancel' % oid, {})
    check(status == 200 and r['status'] == 'cancelled', 'a stuck placing order can be cancelled', (status, r))
    # A changed cart at confirm keeps the code usable
    s = shopper()
    email = 'keep.code@example.com'
    s.api('POST', '/checkout/start', {'email': email})
    code = last_code(email)
    wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', 'P1'); update_post_meta($m['wp_id'], 'max_quantity', 1);")
    try:
        status, r = s.api('POST', '/checkout/confirm', {'email': email, 'code': code})
        check(status == 422 and r['code'] == 'cart_changed', 'cart changed before the code is checked', (status, r))
        status, r = s.api('POST', '/checkout/confirm', {'email': email, 'code': code})
        check(status == 200 and r['ok'], 'the same code still works after reviewing the cart', (status, r))
    finally:
        wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', 'P1'); update_post_meta($m['wp_id'], 'max_quantity', 5);")
    # Shop managers can save Advanced settings (the JS leaves out admin-only keys; the server accepts the rest)
    wp_eval("get_role('shop_manager') || add_role('shop_manager', 'Shop manager', array('read' => true)); InvoiceNinja\\Core\\Capabilities::grant();"
            "if (!get_user_by('login', 'manager1')) { wp_insert_user(array('user_login' => 'manager1', 'user_pass' => 'manager1', 'user_email' => 'manager1@example.com', 'role' => 'shop_manager')); }")
    m = Browser().login('manager1', 'manager1').rest_nonce()
    status, r = m.api('POST', '/settings', {'log_level': 'info', 'notifications': {'attention': True}})
    check(status == 200, 'a shop manager saves Advanced settings without admin-only fields', (status, r))


@test
def checkout_abuse_protection():
    store_ready()
    # Orders from earlier runs of this flow would count against its caps.
    wp_eval("global $wpdb; $wpdb->query('DELETE FROM ' . InvoiceNinja\\Commerce\\Orders::table() . \" WHERE email IN ('price.watch@example.com', 'trap.bot@example.com', 'too.fast@example.com', 'robot@example.com', 'autofill@example.com', 'quote.captcha@example.com')\");")
    # The spam trap: the reply people get, but no code is sent
    s = shopper()
    status, r = s.api('POST', '/checkout/start', {'email': 'trap.bot@example.com', 'in_leave_empty': 'http://spam.example'})
    check(status == 200 and r['next'] == 'code', 'a filled spam trap gets the usual reply', r)
    check(not mails('trap.bot@example.com'), 'but no code is sent')
    status, r = s.api('POST', '/checkout/confirm', {'email': 'trap.bot@example.com', 'code': '123456', 'in_leave_empty': 'x'})
    check(status == 422 and not order_count('trap.bot@example.com'), 'and nothing is ordered', r)
    # A "website" field filled in by the browser's autofill is not the trap
    status, r = shopper().api('POST', '/checkout/start', {'email': 'autofill@example.com', 'website': 'https://buyer.example'})
    check(status == 200 and r['next'] == 'code' and mails('autofill@example.com'), 'an autofilled "website" field still gets a code', r)
    # Minimum time from the first add to checkout
    option('in_mock_checkout_min_seconds', '30')
    try:
        fast = shopper()
        status, r = fast.api('POST', '/checkout/start', {'email': 'too.fast@example.com'})
        check(status == 422 and r['code'] == 'too_fast' and 0 < r['retry_after'] <= 30, 'checking out a moment after the first add waits', r)
        check(not mails('too.fast@example.com'), 'no code sent')
    finally:
        option('in_mock_checkout_min_seconds', '0')
    # A CAPTCHA plugin can refuse the code (the mock's invoiceninja_checkout_captcha filter)
    option('in_mock_checkout_captcha_fail', '1')
    try:
        status, r = shopper().api('POST', '/checkout/start', {'email': 'robot@example.com'})
        check(status == 422 and r['code'] == 'captcha' and not mails('robot@example.com'), 'a failed CAPTCHA sends no code', r)
    finally:
        wp('option', 'delete', 'in_mock_checkout_captcha_fail', check_ok=False)
    # A CAPTCHA hooked to the quote form (invoiceninja_captcha, ignoring its context) never blocks checkout
    option('in_mock_captcha_fail', '1')
    try:
        status, r = shopper().api('POST', '/checkout/start', {'email': 'quote.captcha@example.com'})
        check(status == 200 and r['next'] == 'code', "the quote form's CAPTCHA doesn't run at checkout", r)
    finally:
        wp('option', 'delete', 'in_mock_captcha_fail', check_ok=False)
    # The total the shopper saw: a price change since is shown before the code
    s = shopper()
    status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com', 'total': 1.0})
    check(status == 422 and r['code'] == 'cart_changed' and r['cart']['total'] == 20.0, 'a different total than the page showed is flagged', r)
    status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com', 'total': 20.0})
    check(status == 200 and r['next'] == 'code', 'the total as shown goes on', r)
    code = last_code('price.watch@example.com')
    status, r = s.api('POST', '/checkout/confirm', {'email': 'price.watch@example.com', 'code': code, 'total': 18.0})
    check(status == 422 and r['code'] == 'cart_changed', 'a price change between the code and the order is flagged too', r)
    status, r = s.api('POST', '/checkout/confirm', {'email': 'price.watch@example.com', 'code': code, 'total': 20.0, 'remember': True})
    check(status == 200 and r['ok'], 'and the same code works once the new total was seen', r)
    # Unpaid orders per email are capped (default 5), even on a remembered device
    for _ in range(4):
        s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
        status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com'})
        check(status == 200 and r['next'] == 'confirm', 'remembered device', r)
        status, r = s.api('POST', '/checkout/confirm', {'email': 'price.watch@example.com'})
        check(status == 200 and r['ok'], 'another unpaid order', r)
    s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
    status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com'})
    check(status == 422 and r['code'] == 'too_many_orders', 'a sixth unpaid order from the last day is refused', r)
    status, r = s.api('POST', '/checkout/confirm', {'email': 'price.watch@example.com'})
    check(status == 422 and r['code'] == 'too_many_orders' and order_count('price.watch@example.com') == 5, 'confirm refuses it too', r)
    # Orders held up on the shop's side (needing attention) and cancelled ones don't count against the buyer
    wp_eval("global $wpdb; $t = InvoiceNinja\\Commerce\\Orders::table(); $ids = $wpdb->get_col(\"SELECT id FROM $t WHERE email = 'price.watch@example.com' ORDER BY id LIMIT 2\"); $wpdb->query(\"UPDATE $t SET status = 'attention' WHERE id = \" . (int) $ids[0]); $wpdb->query(\"UPDATE $t SET status = 'cancelled' WHERE id = \" . (int) $ids[1]);")
    status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com'})
    check(status == 200 and r['next'] == 'confirm', 'with two of them held up or cancelled, the buyer can order again', r)
    status, r = s.api('POST', '/checkout/confirm', {'email': 'price.watch@example.com'})
    check(status == 200 and r['ok'] and order_count('price.watch@example.com') == 6, 'and the order is placed', r)
    s.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
    # "Not you?" clears the remembered device
    status, r = s.api('POST', '/checkout/forget', {})
    check(status == 200 and 'invoiceninja_device' not in [c.name for c in s.jar], 'the device cookie is cleared', [c.name for c in s.jar])
    status, r = s.api('POST', '/checkout/start', {'email': 'price.watch@example.com'})
    check(status == 200 and r['next'] == 'code', 'the next checkout asks for a code', r)


def order_count(email):
    return int(wp_eval("global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . InvoiceNinja\\Commerce\\Orders::table() . ' WHERE email = %%s', '%s'));" % email))


@test
def an_add_on_route_outside_the_allowlist_is_skipped_not_fatal():
    store_ready()
    out = wp_eval("add_filter('doing_it_wrong_trigger_error', '__return_false'); add_action('invoiceninja_rest_routes', function ($r) { $r->add('/addon/open', 'GET', '__return_true', 'public'); });"
                  " $ping = rest_do_request(new WP_REST_Request('GET', '/invoiceninja/v1/ping')); $addon = rest_do_request(new WP_REST_Request('GET', '/invoiceninja/v1/addon/open'));"
                  " echo $ping->get_status(), ',', $addon->get_status();")
    check(out.strip().endswith('200,404'), "the REST API keeps working and the open route isn't added", out)
    logged = wp_eval("global $wpdb; echo (int) $wpdb->get_var(\"SELECT COUNT(*) FROM \" . InvoiceNinja\\Core\\Logger::table() . \" WHERE message LIKE '%/addon/open%'\");")
    check(int(logged) >= 1, 'and the mistake is in the log', logged)


@test
def existing_client_in_another_currency_gets_no_invoice():
    store_ready()
    b = admin()
    s = shopper()
    status, r = checkout(s, 'euro@x.com')
    check(status == 200 and r['problem'] == 'currency' and r['status'] == 'attention' and not r['pay_link'], "the buyer is told we can't take it online", r)
    check(not invoice_bodies(), 'no invoice in the client\'s other currency', describe(api_calls()))
    check(not [m for m in mails('euro@x.com') if 'received' in m['subject'] or 'payment' in m['subject']], 'and no payment promised by email')
    check(any('needs you' in m['subject'] for m in mails()), 'an admin is alerted')
    _, rows = b.api('GET', '/admin/orders?search=euro@x.com')
    row = rows['items'][0]
    check(row['status'] == 'attention' and 'currency' in row['note'] and row['client_url'].startswith('https://in.test/app/clients/CLIENT_EURO'), 'the admin sees why, with a link to the client', row)
    status, again = b.api('POST', '/admin/orders/%d/retry' % row['id'], {})
    check(status == 200 and again['status'] == 'attention' and not invoice_bodies(), 'a retry checks the currency again', (status, again))


@test
def new_clients_get_currency_language_and_tax_address():
    store_ready()
    b = admin()
    option('in_mock_calculate_taxes', '1')
    b.api('POST', '/connection/refresh', {})
    wp_eval("InvoiceNinja\\Plugin::instance()->statics->refresh();")
    try:
        data = json_loads(wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->store->scriptData());"))
        check(data['taxFields'] and ['276', 'Germany'] in data['countries'] and data['country'] == '840', "checkout asks for the country, from Invoice Ninja's list", data)
        s = shopper()
        # A page cached before taxes were turned on still learns about the fields: from the cart...
        status, cart = s.api('GET', '/cart?checkout=1')
        check(status == 200 and cart['tax_address']['fields'] and ['276', 'Germany'] in cart['tax_address']['countries'] and cart['tax_address']['country'] == '840' and isinstance(cart['tax'], (int, float)), 'the checkout cart says the tax address is needed', cart)
        check(cart['tax_address']['postcode_required'] == ['840'], '...and which countries need a postcode', cart)
        _, plain = s.api('GET', '/cart')
        check('tax_address' not in plain, 'other cart requests stay small', plain)
        email = 'tax.buyer@example.com'
        details = {'email': email, 'first_name': 'Tax', 'last_name': 'Payer'}
        status, r = s.api('POST', '/checkout/start', details)
        check(status == 422 and r['code'] == 'tax_address' and set(r['fields']) == {'country_id'}, 'the country is needed before the code', r)
        check(r['tax_address']['fields'] and ['276', 'Germany'] in r['tax_address']['countries'], '...and from the error, with the countries to show', r)
        check(not mails(email), 'no code yet')
        details.update(country_id='276', postal_code='12#45')
        status, r = s.api('POST', '/checkout/start', details)
        check(status == 422 and list(r['fields']) == ['postal_code'], 'a postcode that is given must look like one', r)
        # The postcode is optional: many countries (UAE, Hong Kong, Qatar…) have none
        status, r = shopper().api('POST', '/checkout/start', {'email': 'no.postcode@example.com', 'first_name': 'No', 'last_name': 'Postcode', 'country_id': '276'})
        check(status == 200 and r['next'] == 'code', 'no postcode goes on to the code', r)
        # A US buyer's sales tax depends on the state, which Invoice Ninja finds from the ZIP code
        status, r = shopper().api('POST', '/checkout/start', {'email': 'no.zip@example.com', 'first_name': 'No', 'last_name': 'Zip', 'country_id': '840'})
        check(status == 422 and r['code'] == 'tax_address' and list(r['fields']) == ['postal_code'] and r['tax_address']['postcode_required'] == ['840'], 'a US buyer must give a ZIP code', r)
        status, r = shopper().api('POST', '/checkout/start', {'email': 'no.zip@example.com', 'first_name': 'No', 'last_name': 'Zip', 'country_id': '840', 'postal_code': 'SW1A 1AA'})
        check(status == 422 and list(r['fields']) == ['postal_code'], '...that looks like one', r)
        status, r = shopper().api('POST', '/checkout/start', {'email': 'no.zip@example.com', 'first_name': 'No', 'last_name': 'Zip', 'country_id': '840', 'postal_code': '94103'})
        check(status == 200 and r['next'] == 'code', 'a US buyer with a ZIP code goes on to the code', r)
        details.update(country_id='999', postal_code='10115')
        status, r = s.api('POST', '/checkout/start', details)
        check(status == 422 and list(r['fields']) == ['country_id'], 'an unknown country is refused', r)
        details.update(country_id='276')
        status, r = s.api('POST', '/checkout/start', details)
        check(status == 200 and r['next'] == 'code', 'a valid tax address goes on to the code', r)
        details['code'] = last_code(email)
        status, r = s.api('POST', '/checkout/confirm', details)
        check(status == 200 and r['ok'], 'order placed', r)
        clients = client_bodies()
        check(len(clients) == 1 and clients[0].get('country_id') == '276' and clients[0].get('postal_code') == '10115', 'the new client has the tax address', clients)
        check(clients[0].get('settings', {}).get('currency_id') == '1' and clients[0]['settings'].get('language_id') == '1', 'and the store currency and the buyer\'s language', clients)
        inv = invoice_bodies()[-1]
        check(inv['uses_inclusive_taxes'] is False and r['number'] in inv['public_notes'] and '#key=' in inv['public_notes'], 'the invoice says how taxes work and links the order status', inv)
    finally:
        option('in_mock_calculate_taxes', '0')
        b.api('POST', '/connection/refresh', {})


@test
def inclusive_tax_prices_total_like_the_invoice():
    store_ready()
    b = admin()
    wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', 'P1'); update_post_meta($m['wp_id'], 'tax_name1', 'VAT'); update_post_meta($m['wp_id'], 'tax_rate1', 10);")
    try:
        s = shopper()
        _, cart = s.api('GET', '/cart')
        check(cart['total'] == 22.0 and cart['tax'] == 2.0 and not cart['inclusive'], 'tax added on top when prices exclude it', cart)
        option('in_mock_inclusive_taxes', '1')
        b.api('POST', '/connection/refresh', {})
        _, cart = s.api('GET', '/cart')
        check(cart['total'] == 20.0 and cart['tax'] == 1.82 and cart['inclusive'], 'tax backed out of the price when prices include it', cart)
        status, r = checkout(s, 'inclusive.buyer@example.com')
        check(status == 200 and r['total'] == 20.0, 'the order total is the price paid', r)
        inv = invoice_bodies()[-1]
        check(inv['uses_inclusive_taxes'] is True, 'the invoice is told prices include tax', inv)
        receipt = [m for m in mails('inclusive.buyer@example.com') if 'complete your payment' in m['subject']][0]
        check('$20.00' in receipt['body'], 'the email shows money in the store currency', receipt['body'][:600])
    finally:
        option('in_mock_inclusive_taxes', '0')
        b.api('POST', '/connection/refresh', {})
        wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', 'P1'); update_post_meta($m['wp_id'], 'tax_rate1', 0); update_post_meta($m['wp_id'], 'tax_name1', '');")


@test
def queued_orders_survive_deactivation_and_paused_sites_change_nothing():
    store_ready()
    option('in_mock_mode', 'down')
    try:
        _, q = checkout(shopper(), 'parked.buyer@example.com')
    finally:
        option('in_mock_mode', 'up')
    oid = str(order_row(q['number'])['id'])
    pending = lambda: int(wp_eval("echo count(as_get_scheduled_actions(array('hook' => 'invoiceninja_place_order', 'status' => 'pending', 'search' => '\"order_id\":%s}', 'per_page' => 10), 'ids'));" % oid))
    check(pending() == 1, 'a retry job is waiting', pending())
    wp_eval("InvoiceNinja\\Core\\Queue::park();")
    check(pending() == 0 and wp_eval("echo count((array) get_option('invoiceninja_parked_jobs'));") != '0', 'deactivating sets the job aside')
    wp_eval("InvoiceNinja\\Core\\Queue::restore();")
    check(pending() == 1 and not wp_eval("echo get_option('invoiceninja_parked_jobs') ? 'left' : '';"), 'reactivating puts it back')
    # A queued order whose job was lost is found by the daily sweep
    wp_eval("as_unschedule_all_actions('invoiceninja_place_order'); global $wpdb; $wpdb->update(InvoiceNinja\\Commerce\\Orders::table(), array('updated_at' => gmdate('Y-m-d H:i:s', time() - 3600)), array('id' => %s));" % oid)
    check(oid in wp_eval("echo implode(',', InvoiceNinja\\Plugin::instance()->orders->staleClaims());").split(','), 'the daily sweep finds the queued order without a job')
    wp_eval("InvoiceNinja\\Plugin::instance()->daily();")
    check(pending() == 1, 'and queues it again', pending())
    # A paused copy of the site can't cancel invoices through Change order
    b, pages = blocks_store()
    try:
        s = shopper()
        _, r = checkout(s, 'paused.change@example.com')
        wp('invoiceninja', 'environment', 'pause')
        try:
            status, res = s.api('POST', '/orders/last/change', {})
            check(status == 409 and res['code'] == 'unavailable' and order_row(r['number'])['status'] == 'awaiting_payment', 'Change order is refused while orders are paused', (status, res))
        finally:
            wp('invoiceninja', 'environment', 'confirm')
        # Without an Order Status page, a status link shows the order without creating a page
        wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('pages' => array('status' => 0)));")
        try:
            before = wp('post', 'list', '--post_type=page', '--format=count')
            st, loc, html = Browser().get('/?invoiceninja_order=' + r['number'])
            after = wp('post', 'list', '--post_type=page', '--format=count')
            check(st == 200 and 'data-invoiceninja-status' in html and before == after, 'a visitor never creates a page', (st, loc, before, after))
        finally:
            wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('pages' => array('status' => %d)));" % pages['status']['id'])
    finally:
        b.api('POST', '/store/display', {'action': 'legacy'})
        # Leave no queued order behind for other flows (or the next run).
        wp_eval("as_unschedule_all_actions('invoiceninja_place_order'); global $wpdb; $wpdb->query('DELETE FROM ' . InvoiceNinja\\Commerce\\Orders::table() . \" WHERE email IN ('parked.buyer@example.com', 'paused.change@example.com')\");")


# ---------- M2: store front end ----------

def blocks_store():
    store_ready()
    b = admin()
    b.api('POST', '/settings', {'store': {'mode': 'cart', 'require_login': False}})
    status, r = b.api('POST', '/store/display', {'action': 'blocks'})
    check(status == 200 and r['ok'], 'switched to blocks', r)
    status, r = b.api('POST', '/pages', {'pages': ['shop', 'checkout', 'status']})
    check(status == 200 and set(r['pages']) == {'shop', 'checkout', 'status'}, 'store pages exist', r)
    return b, r['pages']


@test
def store_pages_blocks_and_shortcodes_render():
    b, pages = blocks_store()
    try:
        _, _, html = Browser().get(pages['shop']['url'].split('8089', 1)[-1])
        check('invoiceninja-products__grid' in html and 'Widget' in html, 'Shop page lists synced products', html[:300])
        check('data-invoiceninja-add="P1"' in html, 'with Add to cart', html[:300])
        check('store.js' in html and 'window.invoiceNinjaStore' in html, 'store script loads on store pages')
        check('invoiceninja-cart' not in html, 'the 1.x cart is not injected in blocks display')
        _, _, home = Browser().get('/')
        check('store.js' not in home and 'store.css' not in home, 'no store assets on pages without store blocks')
        _, _, checkout = Browser().get(pages['checkout']['url'].split('8089', 1)[-1])
        check('data-invoiceninja-checkout' in checkout, 'Checkout page renders the checkout block')
        out = wp_eval("echo do_shortcode('[invoiceninja_product product_id=\"P1\" show=\"price\"]');")
        check('invoiceninja-price' in out, 'shortcodes match the blocks', out)
        status, products = b.api('GET', '/admin/products')
        check(status == 200 and any(p['id'] == 'P1' for p in products), 'product picker list for the editor', products)
        status, _ = Browser().api('GET', '/admin/products')
        check(status in (401, 403), 'product picker needs a logged-in editor', status)
    finally:
        b.api('POST', '/store/display', {'action': 'legacy'})


@test
def status_links_last_order_change_and_find():
    b, pages = blocks_store()
    try:
        s = shopper()
        email = 'banner.buyer@example.com'
        status, r = checkout(s, email)
        check(status == 200 and r['status'] == 'awaiting_payment', 'order placed', r)
        check('/order-status/?order=' in r['status_url'] and '#key=' in r['status_url'], 'status link opens the Order Status page', r['status_url'])
        names = [c.name for c in s.jar]
        check('invoiceninja_order' in names and 'invoiceninja_order_flag' in names, 'the browser remembers its latest order')
        _, last = s.api('GET', '/orders/last')
        check(last['ok'] and last['number'] == r['number'] and last['pay_link'], 'latest order with its payment link', last)
        _, nobody = Browser().api('GET', '/orders/last')
        check(not nobody['ok'], 'another browser sees nothing', nobody)
        # Back from paying without paying: change the order
        status, changed = s.api('POST', '/orders/last/change', {})
        check(status == 200 and changed['ok'], 'Change order works', changed)
        _, cartnow = s.api('GET', '/cart')
        check([l['product'] for l in cartnow['lines']] == ['P1'] and cartnow['lines'][0]['quantity'] == 2, 'items are back in the cart', cartnow)
        o = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['status'];" % r['number'])
        check(o == 'cancelled', 'and the old order is cancelled', o)
        # Find my order: same reply either way, link emailed only on a match
        reset_mock_log()
        _, a = Browser().api('POST', '/orders/find', {'number': r['number'], 'email': email})
        _, z = Browser().api('POST', '/orders/find', {'number': r['number'], 'email': 'wrong@example.com'})
        check(a == z == {'ok': True}, 'the same reply for a match and a miss', (a, z))
        check(not mails(email), 'the link is emailed in the background, so the reply takes as long either way')
        run_jobs('invoiceninja_find_order')
        check(len(mails(email)) == 1 and not mails('wrong@example.com'), 'link emailed only to the buyer')
        # The status page sends the key in a header, never in the address
        key = r['status_url'].split('key=')[1]
        import urllib.request
        req = urllib.request.Request(anon_base() + '/wp-json/invoiceninja/v1/orders/' + r['number'], headers={'X-Order-Key': key})
        with urllib.request.urlopen(req, timeout=30) as resp:
            check(resp.status == 200 and json_loads(resp.read())['number'] == r['number'], 'order status with the key in X-Order-Key')
        import os
        from lib import ROOT
        with open(os.path.join(ROOT, 'assets', 'store', 'store.js')) as f:
            js = f.read()
        check("'X-Order-Key'" in js and "?key=" not in js, 'store.js sends the key as a header')
        # Old-style link redirects to the page
        st, loc, _ = Browser().get('/?invoiceninja_order=' + r['number'])
        check(st in (301, 302) and 'order-status' in (loc or ''), 'old status links redirect to the Order Status page', (st, loc))
    finally:
        b.api('POST', '/store/display', {'action': 'legacy'})


@test
def welcome_display_switch_and_orders_bulk():
    store_ready()
    b = admin()
    try:
        # A 1.x products page and the welcome flag, as left by the upgrade
        page = wp_eval("$id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Products', 'post_content' => '<p>1.x grid</p>')); update_option('invoiceninja_product_page_id', $id); update_option('invoiceninja_welcome_pending', 1); echo $id;")
        b.api('POST', '/store/display', {'action': 'legacy'})
        st, loc, _ = b.get('/wp-admin/index.php')
        check(st == 302 and 'invoiceninja-welcome' in (loc or ''), 'the first admin visit after the upgrade opens Welcome', (st, loc))
        st, loc, html = b.get('/wp-admin/admin.php?page=invoiceninja-welcome')
        check(st == 200 and 'data-screen="welcome"' in html, 'Welcome renders')
        st2, loc2, _ = b.get('/wp-admin/index.php')
        check(st2 == 200, 'and only once', (st2, loc2))
        _, r = b.api('POST', '/store/display', {'action': 'preview'})
        check(r['ok'] and 'preview=true' in r['url'], 'a draft preview with blocks', r)
        _, r = b.api('POST', '/store/display', {'action': 'blocks'})
        check(r['ok'] and r['pages']['checkout'] and r['pages']['status'], 'switching creates Checkout and Order Status', r)
        content = wp_eval("echo get_post_field('post_content', %s);" % page)
        check('wp:invoiceninja/products' in content, 'the Shop page now holds the Products block', content)
        wp_eval('InvoiceNinja\\Controllers\\ProductController::loadProducts();')
        check('wp:invoiceninja/products' in wp_eval("echo get_post_field('post_content', %s);" % page), 'a 1.x import no longer overwrites it')
        _, r = b.api('POST', '/store/display', {'action': 'legacy'})
        check('1.x grid' in wp_eval("echo get_post_field('post_content', %s);" % page), 'switching back restores the 1.x page')
        # Bulk resend and the month filter
        _, a = checkout(shopper(), 'bulk.one@example.com')
        _, c = checkout(shopper(), 'bulk.two@example.com')
        ids = [wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['id'];" % o['number']) for o in (a, c)]
        reset_mock_log()
        _, r = b.api('POST', '/admin/orders/bulk', {'action': 'resend', 'ids': [int(i) for i in ids]})
        check(r['done'] == 2 and len([m for m in mails() if 'complete your payment' in m['subject']]) == 2, 'bulk resend', r)
        import datetime
        month = datetime.datetime.utcnow().strftime('%Y-%m')
        _, r = b.api('GET', '/admin/orders?month=' + month)
        _, old = b.api('GET', '/admin/orders?month=2001-01')
        check(r['total'] >= 2 and old['total'] == 0, 'month filter', (r['total'], old['total']))
    finally:
        b.api('POST', '/store/display', {'action': 'legacy'})
        wp('option', 'delete', 'invoiceninja_welcome_pending', check_ok=False)


@test
def browser_store_smoke():
    """Real browser (Playwright image in Docker) through shop, cart, code, pay link and status. Opt-in: BROWSER=1."""
    import os
    import subprocess
    if os.environ.get('BROWSER') != '1':
        return
    b, pages = blocks_store()
    try:
        path = lambda key: '/' + pages[key]['url'].split('8089/', 1)[-1]
        from lib import ROOT
        cmd = ['docker', 'run', '--rm', '--network', 'container:invoiceninja-dev-wp-loopback-1',
               '-v', os.path.join(ROOT, 'tests', 'browser') + ':/t:ro', 'mcr.microsoft.com/playwright/python:v1.48.0-jammy',
               'sh', '-c', 'pip install -q playwright==1.48.0 >/dev/null 2>&1 && python /t/store_smoke.py "$0" "$1" "$2"', 'http://localhost:8089', path('shop'), path('checkout')]
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=600)
        check(result.returncode == 0 and 'browser smoke ok' in result.stdout, 'store works in a real browser', (result.stdout[-1500:], result.stderr[-3000:]))
    finally:
        b.api('POST', '/store/display', {'action': 'legacy'})
