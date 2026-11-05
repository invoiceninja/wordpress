"""End-to-end flows for updating live 1.0.16 sites (2.0.1): hosted address left blank, private
hosts, a failed upgrade, the staging guess, products 1.x re-created after a downgrade, email
confirmation without an Account page, the display before the upgrade ran, and the catch-up job."""

import json

from lib import Browser, check, form_nonce, wp, wp_eval
from test_v1 import INVITE_LINKS, set_mode, unverify
from test_v2 import admin, connect, last_code, sync

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


# Hosted Invoice Ninja is answered by the mock at in.test inside the same process: nothing
# is sent to invoicing.co.
HOSTED_TO_MOCK = (
    "add_filter('invoiceninja_pre_resolve_host', function ($ips, $host) { return 'invoicing.co' === $host ? array('93.184.216.34') : $ips; }, 10, 2);"
    "add_filter('pre_http_request', function ($pre, $args, $url) {"
    " if (false === $pre && 0 === strpos($url, 'https://invoicing.co/')) { return apply_filters('pre_http_request', false, $args, str_replace('https://invoicing.co/', 'https://in.test/', $url)); }"
    " return $pre; }, 1, 3);"
)

KEPT = ('invoiceninja_api_url', 'invoiceninja_api_token', 'invoiceninja_connection', 'invoiceninja_environment',
        'invoiceninja_settings', 'invoiceninja_upgraded_from')

SAVE = ("$keep = array(); foreach (%s as $o) { $keep[$o] = get_option($o); } update_option('in_flow_saved', $keep);"
        % ('array(' + ', '.join("'%s'" % o for o in KEPT) + ')'))

RESTORE = ("foreach ((array) get_option('in_flow_saved', array()) as $o => $v) { false === $v ? delete_option($o) : update_option($o, $v); }"
           "delete_option('in_flow_saved'); delete_option('invoiceninja_upgrade_failed');"
           "update_option('invoiceninja_version', INVOICENINJA_VERSION, true); update_option('invoiceninja_schema_version', InvoiceNinja\\Core\\Installer::SCHEMA, true);"
           # Jobs queued while pretending (they'd run later against the restored connection anyway).
           "foreach (array('invoiceninja_after_upgrade', 'invoiceninja_webhooks_register', 'invoiceninja_sideload_image') as $h) { as_unschedule_all_actions($h, array(), 'invoiceninja'); }")

# A 1.0.16 site that has just been updated: no 2.x version yet, never connected through 2.0.
AS_1X = ("delete_option('invoiceninja_version'); delete_option('invoiceninja_upgraded_from'); delete_option('invoiceninja_connection');"
         "delete_option('invoiceninja_upgrade_failed'); delete_option('invoiceninja_environment'); update_option('invoiceninja_schema_version', 9);")


def restore():
    wp_eval(RESTORE)


@test
def hosted_1x_site_with_a_blank_address_connects_and_syncs_after_the_update():
    # 1.x said "leave this blank for the hosted platform".
    try:
        out = wp_eval(SAVE + AS_1X + "update_option('invoiceninja_api_url', ''); update_option('invoiceninja_api_token', 'goodtoken-hosted-1x');" + HOSTED_TO_MOCK
                      + "$p = InvoiceNinja\\Plugin::instance(); $p->installer->maybeUpgrade();"
                      + "$before = $p->credentials->source(); $p->afterUpgrade();"
                      + "$c = (new InvoiceNinja\\Rest\\Routes($p))->connectionPayload();"
                      + "echo wp_json_encode(array('before' => $before, 'url' => get_option('invoiceninja_api_url'), 'status' => $p->state->get()['status'],"
                      + " 'payload_url' => $c['url'], 'hosted' => $c['hosted'], 'source' => $c['source'],"
                      + " 'sync_queued' => (bool) as_has_scheduled_action('invoiceninja_sync_products', null, 'invoiceninja'),"
                      + " 'token_encrypted' => 0 === strpos(get_option('invoiceninja_api_token'), 'in1:')));"
                      + "as_unschedule_all_actions('invoiceninja_sync_products', array(), 'invoiceninja');")
        r = json.loads(out)
        check(r['before'] == 'options', 'a token with a blank address counts as configured', r)
        check(r['url'] == 'https://invoicing.co', 'the hosted address is saved', r)
        check(r['payload_url'] == 'https://invoicing.co' and r['hosted'] and r['source'] == 'options', 'Settings > Connection shows hosted', r)
        check(r['status'] == 'connected', 'the site connected itself in the background, without the wizard', r)
        check(r['sync_queued'], 'products sync after connecting', r)
        check(r['token_encrypted'], 'the 1.x token is encrypted', r)
    finally:
        restore()


@test
def private_1x_host_is_still_allowed_after_the_update():
    try:
        out = wp_eval(SAVE + AS_1X + "update_option('invoiceninja_api_url', 'http://192.168.65.9:9000/');"
                      + "$p = InvoiceNinja\\Plugin::instance(); $p->installer->maybeUpgrade();"
                      + "echo wp_json_encode(array('host' => $p->state->get()['private_host'], 'allowed' => $p->state->allowsPrivate($p->credentials->url()),"
                      + " 'docker' => InvoiceNinja\\Api\\BaseUrl::normalize('http://invoice_ninja:8000')));")
        r = json.loads(out)
        check(r['host'] == '192.168.65.9' and r['allowed'], 'the address chosen in 1.x keeps working', r)
        check(r['docker'] == 'http://invoice_ninja:8000', 'Docker service names with underscores are accepted', r)
    finally:
        restore()


@test
def a_failed_upgrade_keeps_the_site_up_and_tells_admins():
    try:
        out = wp_eval(SAVE + AS_1X + "add_action('invoiceninja_create_tables', function () { throw new RuntimeException('Simulated failure'); });"
                      + "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();"
                      + "echo wp_json_encode(array('failed' => get_option('invoiceninja_upgrade_failed'), 'version' => get_option('invoiceninja_version', '')));")
        r = json.loads(out)
        check(r['failed'] and 'Simulated failure' in r['failed']['message'] and r['version'] == '', 'the failure is recorded, not fatal', r)
        status, _, _ = Browser().get('/shop/')
        check(status == 200, 'the site keeps serving', status)
        _, _, html = Browser().login('admin', 'admin').get('/wp-admin/')
        check("The update didn&#039;t finish" in html or "The update didn't finish" in html, 'admins see a notice', html[:300])
        check(wp('option', 'get', 'invoiceninja_version', check_ok=False) == '', 'not retried for ten minutes')
    finally:
        restore()


@test
def live_1x_shop_keeps_taking_orders_and_paused_copies_are_visible():
    try:
        out = wp_eval(SAVE + "add_filter('pre_option_home', function () { return 'https://dev-tools.com'; });"
                      + "$e = new InvoiceNinja\\Core\\Environment(InvoiceNinja\\Plugin::instance()->options); echo $e->looksLive() ? 'live' : 'copy';")
        check(out == 'live', 'a word like dev in the domain name is not a staging copy', out)
        out = wp_eval(SAVE + AS_1X + "add_filter('pre_option_home', function () { return 'https://shop.example.com'; });"
                      + "$p = InvoiceNinja\\Plugin::instance(); $p->installer->maybeUpgrade(); echo $p->environment->state() ? 'allowed' : 'paused';")
        check(out == 'allowed', 'a 1.x site keeps taking orders after the update, as in 1.x', out)
        restore()
        out = wp_eval(SAVE + AS_1X + "add_filter('pre_option_home', function () { return 'https://staging-shop.example.com'; });"
                      + "$p = InvoiceNinja\\Plugin::instance(); $p->installer->maybeUpgrade(); echo $p->environment->writesAllowed() ? 'allowed' : 'paused';")
        check(out == 'paused', 'a staging address waits for an admin, even from 1.x', out)
        restore()
        # 2.0.1 allowed orders on a staging copy of a site that came from 1.x: undone
        out = wp_eval(SAVE + "add_filter('pre_option_home', function () { return 'https://staging.example.com'; });"
                      + "update_option('invoiceninja_upgraded_from', '1.x'); update_option('invoiceninja_version', '2.0.1'); update_option('invoiceninja_schema_version', 10);"
                      + "$p = InvoiceNinja\\Plugin::instance(); $p->environment->confirm(0); $p->installer->maybeUpgrade(); echo $p->environment->writesAllowed() ? 'allowed' : 'paused';")
        check(out == 'paused', "2.0.1's automatic approval of a staging copy is undone", out)
        # localhost looks like a copy: an admin sees that orders are paused
        wp_eval("delete_option('invoiceninja_environment');")
        _, _, html = Browser().login('admin', 'admin').get('/wp-admin/')
        check('orders are paused on this site' in html, 'the paused state is shown to admins', html[:300])
    finally:
        restore()


@test
def downgrade_to_1x_and_update_again_relinks_products_and_encrypts_the_token():
    b = admin()
    connect(b)
    sync(b)
    # 1.0.16's hourly import deleted every product post and created new ones; the token was
    # entered again in plain text.
    wp_eval("foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'any', 'numberposts' => -1)) as $p) { wp_delete_post($p->ID, true); }"
            "foreach (array('P1' => 'Widget', 'P2' => 'Gadget') as $id => $title) { wp_insert_post(array('post_type' => 'invoiceninja_product', 'post_status' => 'publish', 'post_title' => $title, 'meta_input' => array('product_id' => $id))); }"
            "update_option('invoiceninja_api_token', 'goodtoken1234567890');")
    out = wp_eval("update_option('invoiceninja_version', '2.0.0'); $p = InvoiceNinja\\Plugin::instance(); $p->installer->maybeUpgrade(); $p->afterUpgrade();"
                  "echo 0 === strpos(get_option('invoiceninja_api_token'), 'in1:') ? 'encrypted' : 'plain';"
                  "as_unschedule_all_actions('invoiceninja_after_upgrade', array(), 'invoiceninja');")
    check(out == 'encrypted', 'the re-entered token is encrypted again', out)
    r = sync(b)
    check(r['result']['created'] == 0, "1.x's posts are linked, not duplicated", r)
    counts = wp_eval("$n = array(); foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'publish', 'numberposts' => -1)) as $p) {"
                     " $id = get_post_meta($p->ID, 'product_id', true); $n[$id] = ($n[$id] ?? 0) + 1; if (str_ends_with($p->post_name, '-2')) { $n['dash2'] = 1; } } echo wp_json_encode($n);")
    n = json.loads(counts)
    check(n.get('P1') == 1 and n.get('P2') == 1 and 'dash2' not in n, 'one published post per product, same addresses', n)


@test
def unverified_1x_buyer_confirms_the_email_inline_without_an_account_page():
    try:
        wp_eval(SAVE + "InvoiceNinja\\Plugin::instance()->options->saveSettings(array('pages' => array('account' => 0), 'store' => array('display' => 'legacy')));")
        set_mode('single')
        unverify('member')
        status, location, _ = Browser().get('/?invoiceninja=verify')
        check(status in (301, 302) and 'wp-login.php' in (location or ''), 'logged-out visitors log in first', (status, location))
        m = Browser().login('member', 'pass')
        _, _, html = m.get('/shop/')
        status, location, body = m.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1',
                                                    'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
        target = location or ''
        if 'invoiceninja=verify' not in target:
            # The theme had already sent output: the notice links to the same place.
            check('invoiceninja=verify' in body and 'lostpassword' not in body, 'the notice links to confirming the email', body[:400])
            start = body.rfind('href="', 0, body.find('invoiceninja=verify')) + 6
            target = body[start:].split('"', 1)[0].replace('&#038;', '&').replace('&amp;', '&')
        check('return=' in target, 'and back to the product afterwards', target)
        path = target.split('8089', 1)[-1] if '8089' in target else target
        status, _, page = m.get(path)
        check(status == 200 and 'data-invoiceninja-account' in page and 'data-return="http' in page, 'the Account block on a plain page', page[:400])
        check('noindex' in page, 'not indexed', page[:400])
        status, r = m.api('POST', '/account/verify/start', {})
        check(status == 200 and r['ok'], 'code sent', r)
        status, r = m.api('POST', '/account/verify/confirm', {'code': last_code('a+b@x.com')})
        check(status == 200 and r['ok'], 'code confirmed', r)
        _, _, html = m.get('/shop/')
        status, location, _ = m.post('/shop/', {'invoiceninja_purchase': '1', 'product_id': 'P1',
                                                'invoiceninja_nonce': form_nonce(html, 'invoiceninja_purchase')})
        check(location in INVITE_LINKS, 'then the purchase goes through', (status, location))
    finally:
        restore()


@test
def first_request_after_the_update_keeps_the_1x_display():
    try:
        out = wp_eval(SAVE + "$p = InvoiceNinja\\Plugin::instance(); $p->options->saveSettings(array('store' => array('display' => 'blocks')));"
                      "delete_option('invoiceninja_version'); update_option('invoiceninja_online_purchases', 'multiple');"
                      "echo $p->store->legacy() ? 'legacy' : 'blocks';")
        check(out == 'legacy', 'before migration 7 runs, a 1.x site is shown as 1.x', out)
    finally:
        restore()


@test
def any_version_change_queues_the_catch_up_job():
    try:
        out = wp_eval(SAVE + "as_unschedule_all_actions('invoiceninja_after_upgrade', array(), 'invoiceninja'); update_option('invoiceninja_version', '2.0.0-beta.3');"
                      "InvoiceNinja\\Plugin::instance()->installer->maybeUpgrade();"
                      "echo (int) as_has_scheduled_action('invoiceninja_after_upgrade', null, 'invoiceninja');")
        check(out == '1', 'the connection and company details are refreshed in the background', out)
    finally:
        restore()
