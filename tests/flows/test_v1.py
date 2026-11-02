"""End-to-end tests for the 1.x plugin behaviour.

These double as the upgrade-regression suite for 2.0: everything here must keep
working (or be deliberately replaced) after migrating.
"""

from lib import (Browser, IN_BASE, api_calls, check, cart_nonce, describe, form_nonce, in_container,
                 mock_log, option, reset_mock_log, selected_quantities, wp, wp_eval)

TESTS = []
INVITE_LINKS = ['https://in.test/client/invoice/INVITE%d' % n for n in range(1, 200)]
INVITE_LINK = INVITE_LINKS[0]


def test(fn):
    TESTS.append(fn)
    return fn


def product_count():
    return int(wp('post', 'list', '--post_type=invoiceninja_product', '--format=count'))


def import_products():
    return wp_eval('echo InvoiceNinja\\Controllers\\ProductController::loadProducts();')


def set_mode(mode):
    option('invoiceninja_online_purchases', mode)


# ---------- performance / per-request behaviour ----------

@test
def front_end_requests_make_no_http_calls_or_rewrite_flushes():
    Browser().get('/shop/')  # warm up (a pending activation flush may run once)
    reset_mock_log()
    for _ in range(3):
        status, _, _ = Browser().get('/shop/')
        check(status == 200, 'shop page should load', status)
    log = mock_log()
    check(not [e for e in log if e['event'] == 'flush_rewrite_rules'], 'rewrite rules flushed on a normal request')
    check(not [e for e in log if e['event'] == 'http'], 'front-end request made outbound HTTP calls',
          describe([e for e in log if e['event'] == 'http']))


@test
def admin_page_loads_with_inline_icon_and_no_loopback_request():
    browser = Browser().login('admin', 'admin')
    reset_mock_log()
    status, _, html = browser.get('/wp-admin/admin.php?page=invoiceninja')
    check(status == 200, 'settings page should load', status)
    check('data:image/svg+xml;base64' in html, 'menu icon should be an inline SVG')
    check(not [e for e in mock_log() if 'logo.svg' in e.get('url', '')], 'logo fetched over HTTP')


@test
def cron_uses_prefixed_hook_and_clears_legacy_event_once():
    wp('cron', 'event', 'schedule', 'auto_refresh', 'now', 'hourly')
    option('invoiceninja_legacy_cron_cleared', '0')
    wp_eval('delete_option("invoiceninja_legacy_cron_cleared");')
    wp('cron', 'event', 'list', '--fields=hook')  # loads the plugin -> init -> cleanup
    hooks = wp('cron', 'event', 'list', '--fields=hook', '--format=csv')
    check('invoiceninja_auto_refresh' not in hooks, '2.0 replaces the 1.x delete-and-reimport cron', hooks)
    check('\nauto_refresh' not in '\n' + hooks.replace('invoiceninja_auto_refresh', ''),
          'legacy auto_refresh event should be cleared', hooks)


# ---------- logged-out visitors ----------

@test
def logged_out_portal_redirects_to_login_without_client_lookup():
    # 2.0: the 1.x shortcode links to the site's verified redirect
    browser = Browser()
    _, _, html = browser.get('/shop/')
    check('?invoiceninja=portal' in html and 'key_login' not in html, 'the portal shortcode never prints a portal link', html[:400])
    reset_mock_log()
    status, location, _ = browser.get('/?invoiceninja=portal')
    check(status in (301, 302) and 'wp-login.php' in (location or ''), 'should redirect to login', '%s %s' % (status, location))
    check(not api_calls(), 'no API calls expected', describe(api_calls()))


@test
def logged_out_cart_works_but_checkout_requires_login():
    set_mode('multiple')
    browser = Browser()
    _, _, html = browser.get('/shop/')
    browser.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1',
                            'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
    _, _, html = browser.get('/shop/')
    check('invoiceninja-cart' in html, 'cart should show after adding a product')
    reset_mock_log()
    status, location, _ = browser.post('/shop/', {'cart_action': 'checkout',
                                                  'invoiceninja_nonce': form_nonce(html, 'value="checkout"')})
    check(status in (301, 302) and 'wp-login.php' in (location or ''), 'checkout should redirect to login (#12 headers)',
          '%s %s' % (status, location))
    check(not api_calls(), 'no API calls expected', describe(api_calls()))


@test
def cart_quantities_are_validated():
    set_mode('multiple')
    browser = Browser()
    _, _, html = browser.get('/shop/')
    browser.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1',
                            'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
    _, _, html = browser.get('/shop/')
    nonce = cart_nonce(html)

    browser.post('/shop/', {'cart_action': 'update', 'product_id': 'P1', 'quantity': '500', 'invoiceninja_nonce': nonce})
    _, _, html = browser.get('/shop/')
    check(selected_quantities(html) == ['5'], 'quantity 500 should clamp to max_quantity 5', selected_quantities(html))

    browser.post('/shop/', {'cart_action': 'update', 'product_id': 'P1', 'quantity': '-5', 'invoiceninja_nonce': nonce})
    _, _, html = browser.get('/shop/')
    check('invoiceninja-cart' not in html, 'negative quantity should remove the item')

    _, _, html = browser.get('/shop/')
    browser.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'NOPE',
                            'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
    _, _, html = browser.get('/shop/')
    check('invoiceninja-cart' not in html, 'unknown product id should not be added')


# ---------- logged-in buyers ----------

def buy_now(user):
    set_mode('single')
    browser = Browser().login(user, 'pass')
    _, _, html = browser.get('/shop/')
    reset_mock_log()
    status, location, _ = browser.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1',
                                                  'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
    return status, location, api_calls()


@test
def buy_now_existing_client_with_plus_email():
    status, location, calls = buy_now('member')
    check(location in INVITE_LINKS, 'should redirect to the invoice', '%s %s' % (status, location))
    urls = [c['url'] for c in calls]
    check(IN_BASE + 'clients?email=a%2Bb%40x.com' in urls, 'email must be URL-encoded (#11 duplicates)', describe(calls))
    invoice = [c for c in calls if c['url'].startswith(IN_BASE + 'invoices')]
    check(invoice and '"client_id":"CLIENT_PLUS"' in invoice[0]['body'], 'invoice should be for the matching client', describe(calls))
    check(not [c for c in calls if c['url'] == IN_BASE + 'clients?email='], 'never look up an empty email')


@test
def buy_now_new_client_is_created_correctly():
    status, location, calls = buy_now('newbie')
    check(location in INVITE_LINKS, 'should redirect to the invoice', '%s %s' % (status, location))
    created = [c for c in calls if c['method'] == 'POST' and c['url'] == IN_BASE + 'clients']
    check(created and '"email":"newbie@x.com"' in created[0]['body'] and '"first_name":"New"' in created[0]['body'],
          'client should be created from the WP user', describe(calls))
    invoice = [c for c in calls if c['url'].startswith(IN_BASE + 'invoices')]
    check(invoice and '"client_id":"NEWCLIENT"' in invoice[0]['body'], 'invoice should use the new client', describe(calls))


@test
def multi_item_checkout_creates_one_invoice():
    set_mode('multiple')
    browser = Browser().login('member', 'pass')
    _, _, html = browser.get('/shop/')
    nonce = form_nonce(html, 'invoiceninja_purchase')
    for _ in range(2):
        browser.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1', 'invoiceninja_nonce': nonce})
    _, _, html = browser.get('/shop/')
    reset_mock_log()
    status, location, _ = browser.post('/shop/', {'cart_action': 'checkout',
                                                  'invoiceninja_nonce': form_nonce(html, 'value="checkout"')})
    calls = api_calls()
    check(location in INVITE_LINKS, 'checkout should redirect to the invoice', '%s %s' % (status, location))
    invoices = [c for c in calls if c['url'].startswith(IN_BASE + 'invoices')]
    check(len(invoices) == 1 and '"quantity":2' in invoices[0]['body'], 'exactly one invoice with quantity 2', describe(calls))


@test
def portal_sso_for_matching_contact():
    # 2.0: only a verified email opens the portal, and only the user's own contact
    wp_eval("$u = get_user_by('login', 'member'); delete_user_meta($u->ID, '_invoiceninja_verified_at'); delete_user_meta($u->ID, '_invoiceninja_client_id');")
    browser = Browser().login('member', 'pass')
    status, location, _ = browser.get('/?invoiceninja=portal')
    check(status in (301, 302) and 'invoiceninja_verify=1' in (location or ''), 'an unverified email is sent to confirm it first', '%s %s' % (status, location))
    wp_eval("$u = get_user_by('login', 'member'); InvoiceNinja\\Plugin::instance()->module('portal')->verification->mark($u->ID, 'admin');")
    status, location, _ = browser.get('/?invoiceninja=portal')
    check(location == 'https://in.test/client/key_login/KEY_CLIENT_PLUS', "a verified customer goes to their own contact's portal login", '%s %s' % (status, location))


# ---------- connection / sync resilience ----------

@test
def failed_connection_test_keeps_the_token():
    b = Browser().login('admin', 'admin').rest_nonce()
    before = wp('option', 'get', 'invoiceninja_api_token')
    _, r = b.api('POST', '/connection', {'url': 'https://in.test', 'token': 'badtoken123456'})
    check(not r['saved'] and r['error']['kind'] == 'auth', 'a bad token is reported, not saved (#3 #8 #14)', r)
    check(wp('option', 'get', 'invoiceninja_api_token') == before, 'the working token is kept')
    wp('option', 'update', 'invoiceninja_profile', '')
    _, r = b.api('POST', '/connection', {'url': 'https://in.test', 'token': 'goodtoken1234567890'})
    check(r['saved'], 'good token saved', r)
    check('Mock Company' in wp('option', 'get', 'invoiceninja_profile'), '1.x profile reloads after connecting')


@test
def product_import_survives_an_outage():
    import_products()
    before = product_count()
    check(before == 2, 'expected 2 imported products', before)
    option('in_mock_mode', 'down')
    try:
        check(import_products() == '0', 'import should report 0 when IN is down')
        check(product_count() == before, 'products must not be deleted when IN is down (1.0.16)', product_count())
        wp_eval('InvoiceNinja\\Plugin::instance()->products->run(true);')
        check(product_count() == before, 'hourly refresh must not delete products when IN is down', product_count())
    finally:
        option('in_mock_mode', 'up')


@test
def product_import_ignores_an_empty_response():
    import_products()
    option('in_mock_mode', 'empty')
    try:
        import_products()
        check(product_count() == 2, 'an empty API response must not wipe the catalog', product_count())
    finally:
        option('in_mock_mode', 'up')


@test
def product_import_replaces_products_when_successful():
    import_products()
    titles = wp('post', 'list', '--post_type=invoiceninja_product', '--field=post_title')
    check('Widget' in titles and 'Gadget' in titles, 'imported products should exist', titles)


@test
def legacy_code_uses_the_decrypted_2x_token():
    wp_eval("InvoiceNinja\\Plugin::instance()->credentials->save('https://in.test', 'goodtoken-encrypted-1');")
    try:
        stored = wp('option', 'get', 'invoiceninja_api_token')
        check(stored.startswith('in1:') and 'goodtoken' not in stored, 'token stored encrypted', stored)
        reset_mock_log()
        import_products()
        calls = api_calls()
        check(calls and all(c.get('token') == 'goodtoken-encrypted-1' for c in calls), '1.x requests carry the decrypted token', describe(calls))
        check(all(c.get('redirection') == 0 for c in calls), '1.x requests never follow redirects')
        check(product_count() == 2, 'products imported', product_count())
    finally:
        option('invoiceninja_api_token', 'goodtoken1234567891')


@test
def inventory_tracking_limits_and_out_of_stock():
    option('in_mock_track_inventory', '1')
    wp_eval("do_action('invoiceninja_connected', array());")  # reloads the 1.x profile
    try:
        set_mode('multiple')
        _, _, html = Browser().get('/stock/')
        check('Out of Stock' in html, 'product with no stock should show the out of stock label', html[:800])
        max_p1 = wp_eval('$p = new InvoiceNinja\\WordPress\\PostApi(); $m = new ReflectionMethod($p, "getMaxQuantity");'
                         ' $m->setAccessible(true); echo $m->invoke($p, "P1");')
        check(max_p1 == '3', 'max quantity should be limited by stock (3)', max_p1)
    finally:
        option('in_mock_track_inventory', '0')
        option('invoiceninja_api_token', 'goodtoken1234567893')
        wp_eval("do_action('invoiceninja_connected', array());")  # the cached profile must forget inventory tracking too


@test
def deactivation_unschedules_cron():
    wp('plugin', 'deactivate', 'invoiceninja')
    try:
        hooks = wp('cron', 'event', 'list', '--fields=hook', '--format=csv')
        check('invoiceninja_auto_refresh' not in hooks, 'cron should be removed on deactivation', hooks)
    finally:
        wp('plugin', 'activate', 'invoiceninja')
