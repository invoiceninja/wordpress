"""M4 flows: subscriptions (Pricing Table, Subscribe, Configurator, Callback, Lifecycle, relay).

Invoice Ninja is faked by dev/mu-plugins/invoiceninja-mock.php, -webhooks.php and -subscriptions.php.
"""

import hashlib
import hmac
import json
import urllib.error
import urllib.parse
import urllib.request

from lib import BASE, Browser, api_calls, check, describe, mock_log, option, reset_mock_log, wp, wp_eval
from test_v2 import admin, connect, store_ready
from test_webhooks import pending, run_jobs, secret as webhook_secret, send as send_webhook

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


LEGACY = {'post_purchase_url': 'https://legacy.test/hook', 'post_purchase_rest_method': 'post',
          'post_purchase_headers': {'X-Legacy': 'yes'}, 'return_url': 'https://legacy.test/thanks'}


def php(text):
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def callback_secret():
    return wp_eval("echo InvoiceNinja\\Plugin::instance()->secrets->get('callback_secret');")


def callback(body, key='', method='POST'):
    """Call /subscription-callback like Invoice Ninja does ({"body": {...}}). Returns (status, json)."""
    headers = {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}
    headers['X-Invoiceninja-Secret'] = key if key else callback_secret()
    req = urllib.request.Request(BASE + '/wp-json/invoiceninja/v1/subscription-callback', data=json.dumps({'body': body}).encode(),
                                 headers=headers, method=method)
    try:
        resp = urllib.request.urlopen(req, timeout=60)
        return resp.status, json.loads(resp.read().decode() or 'null')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or 'null')


SUCCESS = {'message': 'Success', 'status_code': 200}

# The relay outbox: one option row per waiting event, oldest first (plus, with the prefix
# shortened to 'invoiceninja_subscriptions_relay', the sequence and the lane locks).
RELAY_ROWS = ("global $wpdb; $rows = $wpdb->get_col($wpdb->prepare(\"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name\","
              " $wpdb->esc_like('invoiceninja_subscriptions_relay_') . '%'));")


def outbox():
    """Relay events waiting to be sent: [{'key', 'id', 'event', 'attempts', 'next_at'}], oldest first."""
    return json.loads(wp_eval(RELAY_ROWS + " $out = array(); foreach ($rows as $n) { if (!preg_match('/_\\d{12}$/', $n)) { continue; }"
                              " $v = get_option($n); $out[] = array('key' => $n, 'id' => $v['payload']['id'], 'event' => $v['payload']['event'],"
                              " 'attempts' => $v['attempts'], 'next_at' => $v['next_at']); } echo wp_json_encode($out);") or '[]')


def mock_config(sub_id):
    return json.loads(wp_eval("$c = get_option('in_mock_subscription_config', array()); echo wp_json_encode($c[%s] ?? null);" % php(sub_id)))


def events():
    return json.loads(wp_eval("echo wp_json_encode(array_values((array) get_option('invoiceninja_subscriptions_events', array())));") or '[]')


def named(event, sub=None):
    return [e for e in events() if e['event'] == event and (sub is None or e['subscription'] == sub)]


def page(content):
    pid = wp_eval("echo wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Plans', 'post_content' => %s));" % php(content))
    return wp_eval("echo wp_make_link_relative(get_permalink(%s));" % pid), pid


def seed():
    """Recurring invoices and subscription invoices in the mock."""
    wp_eval(
        "$ago = function ($d) { return gmdate('Y-m-d', time() - $d * DAY_IN_SECONDS); };"
        "update_option('in_mock_recurring', array("
        " 'RI1' => array('id' => 'RI1', 'number' => 'R-0001', 'client_id' => 'CLIENT_PLUS', 'subscription_id' => 'SUBM', 'status_id' => '2', 'frequency_id' => '5', 'next_send_date' => '2026-12-01', 'amount' => 10, 'remaining_cycles' => -1, 'line_items' => array(array('product_key' => 'VPS', 'notes' => 'Small VPS', 'quantity' => 1, 'cost' => 10))),"
        " 'RI2' => array('id' => 'RI2', 'number' => 'R-0002', 'client_id' => 'CLIENT_MEMBER', 'subscription_id' => 'SUBY', 'status_id' => '2', 'frequency_id' => '10', 'next_send_date' => '2027-01-01', 'amount' => 100, 'remaining_cycles' => -1, 'line_items' => array()),"
        " 'RI3' => array('id' => 'RI3', 'number' => 'R-0003', 'client_id' => 'CLIENT_MEMBER', 'subscription_id' => 'SUBY', 'status_id' => '2', 'frequency_id' => '10', 'next_send_date' => '2027-01-01', 'amount' => 100, 'remaining_cycles' => -1, 'line_items' => array())));"
        "$inv = function ($id, $client, $sub, $ri, $due, $status, $amount) { return array('id' => $id, 'number' => 'S-' . $id, 'client_id' => $client, 'subscription_id' => $sub, 'recurring_id' => $ri, 'status_id' => $status, 'amount' => $amount, 'balance' => '4' === $status ? 0 : $amount, 'paid_to_date' => '4' === $status ? $amount : 0, 'due_date' => $due, 'po_number' => '', 'created_at' => time(), 'line_items' => array(), 'invitations' => array()); };"
        "$all = (array) get_option('in_mock_invoices', array());"
        "$all['INVS1'] = $inv('INVS1', 'CLIENT_PLUS', 'SUBM', 'RI1', $ago(10), '2', 10);"
        "$all['INVS2'] = $inv('INVS2', 'CLIENT_MEMBER', 'SUBT', '', $ago(0), '4', 5);"
        "$all['INVP1'] = $inv('INVP1', 'CLIENT_PLUS', 'SUBM', 'RI1', $ago(40), '4', 10);"
        "$all['INVU1'] = $inv('INVU1', 'CLIENT_MEMBER', 'SUBT', '', $ago(0), '2', 5);"
        "update_option('in_mock_invoices', $all);"
    )


def subs_ready():
    store_ready()
    b = admin()
    status, s = b.api('POST', '/settings', {'modules': {'subs': True}, 'subscriptions': {'suspend_days': 7}})
    check(status == 200 and s['modules']['subs'] and s['subscriptions']['suspend_days'] == 7, 'Subscriptions on', s)
    for name in ('in_mock_subscription_config', 'in_mock_subscription_put_fail', 'in_mock_recurring', 'in_mock_relay_fail',
                 'in_mock_legacy_reply', 'in_mock_legacy_down', 'in_mock_eligible', 'in_mock_payments',
                 'in_mock_subscription_fields', 'in_mock_paid'):
        wp('option', 'delete', name, check_ok=False)
    for name in ('config', 'state', 'fired', 'recent', 'events'):
        wp('option', 'delete', 'invoiceninja_subscriptions_' + name, check_ok=False)
    wp_eval(RELAY_ROWS.replace("'invoiceninja_subscriptions_relay_'", "'invoiceninja_subscriptions_relay'") + " foreach ($rows as $n) { delete_option($n); }")
    wp_eval("foreach (array('invoiceninja_subscription_callback', 'invoiceninja_subscription_relay', 'invoiceninja_subscription_forward', 'invoiceninja_webhook_dispatch') as $h) { as_unschedule_all_actions($h); }"
            "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\\\_transient\\\\_invoiceninja\\\\_subrows\\\\_%'\");")
    status, r = b.api('POST', '/admin/subscriptions/sync')
    check(status == 200 and sorted(i['id'] for i in r['items']) == ['SUBM', 'SUBT', 'SUBW', 'SUBY'], 'subscriptions synced', r)
    reset_mock_log()
    return b


@test
def pricing_table_and_subscribe_button_render():
    b = subs_ready()
    path, pid = page('[invoiceninja_pricing_table ids="SUBM,SUBT" yearly="SUBM:SUBY" highlight="SUBM"] [invoiceninja_subscribe id="SUBT" show_price="true" label="Try it"]')
    status, _, html = Browser().get(path)
    check(status == 200, 'page loads', status)
    for text in ('Pro Monthly', 'Pro Yearly', 'Starter', '14-day free trial', 'per month', 'per year', 'data-invoiceninja-toggle', '>Monthly<', '>Yearly<', 'is-featured', 'Try it', 'assets/subscriptions/subscriptions.js'):
        check(text in html, 'pricing table shows %r' % text, html[:3000])
    import re
    check(re.search(r'invoiceninja=subscribe(&#038;|&amp;|&)s=SUBM', html), 'buttons go to our own endpoint', html[:3000])
    check('in.test' not in html and 'key_login' not in html, 'no Invoice Ninja link in the (cacheable) HTML')

    single, pid2 = page('<!-- wp:invoiceninja/pricing-table {"ids":"SUBT"} /-->')
    _, _, html = Browser().get(single)
    check('is-single' in html and 'data-invoiceninja-toggle' not in html, 'one plan: a single card, no toggle')
    empty, pid3 = page('[invoiceninja_pricing_table]')
    _, _, html = Browser().get(empty)
    check('invoiceninja-pricing' not in html, 'no plans chosen: nothing for visitors')
    _, _, html = Browser().login('admin', 'admin').get(empty)
    check('Choose the plans' in html, 'editors are told to choose plans')
    unknown, pid4 = page('[invoiceninja_pricing_table ids="NOPE"]')
    _, _, html = Browser().get(unknown)
    check('Plans are not available right now' in html, 'unknown plans: a neutral message')

    b.api('POST', '/settings', {'modules': {'subs': False}})
    _, _, html = Browser().get(path)
    check('invoiceninja-pricing__plans' not in html, 'feature off: not rendered')
    b.api('POST', '/settings', {'modules': {'subs': True}})
    for p in (pid, pid2, pid3, pid4):
        wp('post', 'delete', p, '--force')


@test
def subscribe_sends_guests_to_prefilled_purchase_page_and_verified_users_through_key_login():
    subs_ready()
    guest = Browser()
    status, loc, _ = guest.get('/?invoiceninja=subscribe&s=SUBM&email=gina%40example.com&first_name=Gina&last_name=Guest')
    check(status == 302 and loc.startswith('https://in.test/client/subscriptions/SUBM/purchase?'), 'guest: to the purchase page', (status, loc))
    q = urllib.parse.parse_qs(urllib.parse.urlparse(loc).query)
    check(q.get('email') == ['gina@example.com'] and q.get('first_name') == ['Gina'] and q.get('last_name') == ['Guest'], 'prefilled', q)
    check(any(c.name == 'invoiceninja_sub' for c in guest.jar), 'return-page context cookie set')
    status, _, _ = guest.get('/?invoiceninja=subscribe&s=NOPE')
    check(status == 404, 'unknown plan: 404')

    uid = wp('user', 'get', 'member', '--field=ID')
    wp('user', 'meta', 'update', uid, '_invoiceninja_client_id', 'CLIENT_PLUS')
    wp('user', 'meta', 'update', uid, '_invoiceninja_contact_id', 'C1')
    wp('user', 'meta', 'update', uid, 'in_mock_verified', '1')
    # The Portal module's own verification (left by other flows) must not count for the unverified step.
    for key in ('_invoiceninja_verified_at', '_invoiceninja_verified_email_hash', '_invoiceninja_verified_by'):
        wp('user', 'meta', 'delete', uid, key, check_ok=False)
    try:
        member = Browser().login('member', 'pass')
        reset_mock_log()
        status, loc, _ = member.get('/?invoiceninja=subscribe&s=SUBM')
        expected = 'https://in.test/client/key_login/KEY_CLIENT_PLUS?next=' + urllib.parse.quote('https://in.test/client/subscriptions/SUBM/purchase', safe='')
        check(status == 302 and loc == expected, 'verified: logged in through key_login?next=', (status, loc))
        check(any(c['url'].endswith('/clients/CLIENT_PLUS') for c in api_calls()), "the contact's link is fetched live", describe(api_calls()))

        # Account tab rows and their links
        seed()
        rows = json.loads(wp_eval("echo wp_json_encode(apply_filters('invoiceninja_account_subscriptions', array(), 'CLIENT_PLUS', %s));" % uid))
        check(len(rows) == 1 and rows[0]['id'] == 'RI1' and rows[0]['name'] == 'Pro Monthly', 'one row for the subscription', rows)
        check(rows[0]['status'] == 'overdue' and rows[0]['next_date'] == '2026-12-01' and '10' in rows[0]['amount'], 'status, next date and amount', rows)
        check('in.test' not in json.dumps(rows), 'links are our own endpoint', rows)
        change = rows[0]['links']['change']
        check('invoiceninja=subscription' in change and 'do=change' in change and 'r=RI1' in change, 'change link', rows[0]['links'])
        status, loc, _ = member.get(change.replace(BASE, '') if change.startswith(BASE) else urllib.parse.urlparse(change).path + '?' + urllib.parse.urlparse(change).query)
        check(status == 302 and loc == 'https://in.test/client/key_login/KEY_CLIENT_PLUS?next=' + urllib.parse.quote('https://in.test/client/recurring_invoices/RI1', safe=''), 'manage: into the recurring invoice page', (status, loc))
        status, _, _ = member.get('/?invoiceninja=subscription&do=cancel&r=RI2')
        check(status == 404, "someone else's subscription: refused", status)
        status, loc, _ = member.get('/?invoiceninja=subscription&do=payment_method&r=RI1')
        check(status == 302 and urllib.parse.quote('/client/payment_methods', safe='') in loc, 'payment methods page', loc)

        # Not verified: prefill only, never key_login
        wp('user', 'meta', 'delete', uid, 'in_mock_verified')
        status, loc, _ = member.get('/?invoiceninja=subscribe&s=SUBM')
        check(status == 302 and 'key_login' not in loc and 'email=a%2Bb%40x.com' in loc, 'unverified: purchase page with their email', loc)
        status, _, _ = member.get('/?invoiceninja=subscription&do=change&r=RI1')
        check(status == 403, 'unverified: no portal link', status)
    finally:
        for key in ('_invoiceninja_client_id', '_invoiceninja_contact_id', 'in_mock_verified'):
            wp('user', 'meta', 'delete', uid, key, check_ok=False)


@test
def opting_in_sets_and_restores_webhook_configuration():
    b = subs_ready()
    status, r = b.api('POST', '/admin/subscriptions/SUBM', {'connected': True})
    check(status == 200, 'opted in', r)
    cfg = mock_config('SUBM')
    ours = wp_eval("echo rest_url('invoiceninja/v1/subscription-callback');")
    check(cfg['post_purchase_url'] == ours, 'callback URL set', cfg)
    check(cfg['post_purchase_rest_method'] == 'post', 'REST method set to post', cfg)
    check(cfg['post_purchase_headers'] == {'X-Invoiceninja-Secret': callback_secret()}, 'secret header (an array)', cfg)
    check(cfg['return_url'].endswith('?invoiceninja=subscribed'), 'return page set', cfg)
    puts = [c for c in api_calls() if c['method'] == 'PUT' and c['url'].endswith('/subscriptions/SUBM')]
    check(puts and json.loads(puts[0]['body']).get('steps'), 'steps sent with the update (Invoice Ninja requires them)', describe(puts))
    item = next(i for i in r['items'] if i['id'] == 'SUBM')
    check(item['connected'] and item['callback'] == 'here', 'shown as connected', item)

    status, r = b.api('POST', '/admin/subscriptions/SUBW', {'connected': True})
    check(status == 409 and r['code'] == 'other_wordpress', 'refused: it reports to another WordPress site', r)
    left = mock_config('SUBW')
    check((left is None or 'live.example.com' in left['post_purchase_url']) and not [c for c in api_calls() if c['method'] == 'PUT' and c['url'].endswith('/subscriptions/SUBW')], 'and left alone', left)

    status, r = b.api('POST', '/admin/subscriptions/SUBM', {'connected': False})
    check(status == 200 and mock_config('SUBM') == LEGACY, 'opt-out restores the original', mock_config('SUBM'))

    # A restore that fails is loud, and can be retried
    b.api('POST', '/admin/subscriptions/SUBY', {'connected': True})
    option('in_mock_subscription_put_fail', '1')
    try:
        status, r = b.api('POST', '/admin/subscriptions/SUBY', {'connected': False})
        check(status == 502, 'restore failure reported', (status, r))
        _, _, dash = Browser().login('admin', 'admin').get('/wp-admin/index.php')
        check('subscription settings not restored' in dash, 'loud admin notice', dash[:500])
        _, d = b.api('GET', '/diagnostics')
        check(any(c['id'] == 'subscriptions' and c['status'] == 'fail' for c in d['checks']), 'Diagnostics fails', [c for c in d['checks'] if c['id'] == 'subscriptions'])
    finally:
        wp('option', 'delete', 'in_mock_subscription_put_fail')
    status, r = b.api('POST', '/admin/subscriptions/restore')
    check(status == 200 and mock_config('SUBY')['post_purchase_url'] == '', 'retried and restored', mock_config('SUBY'))
    _, _, dash = Browser().login('admin', 'admin').get('/wp-admin/index.php')
    check('subscription settings not restored' not in dash, 'notice gone')

    # Disconnecting restores
    b.api('POST', '/admin/subscriptions/SUBY', {'connected': True})
    check(mock_config('SUBY')['post_purchase_url'] == ours, 'connected again')
    b.api('DELETE', '/connection')
    check(mock_config('SUBY')['post_purchase_url'] == '', 'disconnect restores', mock_config('SUBY'))
    connect(b)

    # Deactivating restores
    b.api('POST', '/admin/subscriptions/SUBY', {'connected': True})
    wp('plugin', 'deactivate', 'invoiceninja')
    try:
        check(mock_config('SUBY')['post_purchase_url'] == '', 'deactivation restores', mock_config('SUBY'))
    finally:
        wp('plugin', 'activate', 'invoiceninja')


@test
def every_callback_context_is_answered_and_handled():
    b = subs_ready()
    seed()
    status, r = b.api('POST', '/admin/subscriptions/SUBM', {'connected': True, 'relay_url': 'https://relay.test/provision'})
    check(status == 200, 'opted in with a relay', r)
    relay_secret = r['relay_secret']
    reset_mock_log()
    base = {'subscription': 'SUBM', 'client': 'CLIENT_PLUS', 'contact': 'C1', 'contact_email': 'a+b@x.com', 'account_key': '', 'db': 'db-ninja-01'}

    status, r = callback(dict(base, context='recurring_purchase', recurring_invoice='RI1'), key='wrong')
    # Any 4xx becomes status_code 500 in Invoice Ninja, which blocks free purchases on its v1 page
    check(status == 200 and r == dict(SUCCESS, code='ignored'), 'wrong secret: ignored, still "Success" (never blocks a buyer)', (status, r))
    check(pending('invoiceninja_subscription_callback') == 0, 'and nothing queued')
    for _ in range(31):
        callback(dict(base, context='is_eligible'), key='wrong')
    status, r = callback(dict(base, context='is_eligible'))
    check(status == 200 and r == SUCCESS, 'the right secret is never locked out by wrong ones from the same address', (status, r))

    # is_eligible: inline, with the status_code Invoice Ninja's free-purchase step reads
    status, r = callback(dict(base, context='is_eligible'))
    check(status == 200 and r == SUCCESS, 'eligible', r)
    legacy = [e for e in mock_log() if e.get('event') == 'legacy']
    check(legacy and float(legacy[-1]['timeout']) <= 1.0, 'original URL asked within 1s', legacy)
    sent = json.loads(legacy[-1]['body'])
    check(sent['body']['context'] == 'is_eligible' and 'db' not in sent['body'] and legacy[-1]['headers'].get('X-Legacy') == 'yes', 'with its own headers, without the database name', legacy[-1])
    option('in_mock_legacy_reply', '{"message":"Only one Pro plan per customer"}')
    status, r = callback(dict(base, context='is_eligible'))
    check(r == {'message': 'Only one Pro plan per customer', 'status_code': 403}, "the original URL's answer is passed on, as a refusal", r)
    wp('option', 'delete', 'in_mock_legacy_reply')
    option('in_mock_legacy_down', '1')
    status, r = callback(dict(base, context='is_eligible'))
    check(r == SUCCESS, 'original URL down: fails open', r)
    wp('option', 'delete', 'in_mock_legacy_down')
    for answer, expected in (('false', "Sorry, this plan isn't available for your account."), ('Members only', 'Members only'), ('throw', 'Success')):
        option('in_mock_eligible', answer)
        status, r = callback(dict(base, context='is_eligible'))
        code = 200 if expected == 'Success' else 403
        check(status == 200 and r == {'message': expected, 'status_code': code}, 'filter %r -> %r (%d)' % (answer, expected, code), r)
    wp('option', 'delete', 'in_mock_eligible')
    check(pending('invoiceninja_subscription_callback') == 0, 'is_eligible queues nothing')

    def trials_on():
        wp_eval("update_option('in_mock_subscription_fields', array('SUBM' => array('trial_enabled' => true, 'trial_duration' => 7 * 86400)));")
        b.api('POST', '/admin/subscriptions/sync')

    def paid_overdue():
        wp_eval("update_option('in_mock_paid', array('INVS1'));")

    def stopped():
        # What Invoice Ninja does on cancel: stop (paused) and archive the recurring invoice
        wp_eval("$r = get_option('in_mock_recurring'); $r['RI1']['status_id'] = '3'; $r['RI1']['archived_at'] = time(); update_option('in_mock_recurring', $r);")

    # Each callback with what Invoice Ninja's records look like by the time it's sent
    flow = [
        ('recurring_purchase', {'recurring_invoice': 'RI1', 'invoice': 'INVP1'}, ['purchased'], None),
        ('trial', {'recurring_invoice': 'RI1'}, ['trial'], trials_on),
        ('change_plan', {'recurring_invoice': 'RI1'}, ['plan_changed'], None),
        ('plan_expired', {'invoice': 'INVS1'}, ['suspended'], None),
        ('plan_paid', {'recurring_invoice': 'RI1', 'invoice': 'INVS1'}, ['paid', 'resumed'], paid_overdue),
        ('cancellation', {'recurring_invoice': 'RI1'}, ['cancelled'], stopped),
    ]
    for context, extra, expected, prepare in flow:
        if prepare:
            prepare()
        before = len(events())
        status, r = callback(dict(base, context=context, **extra))
        check(status == 200 and r == SUCCESS, '%s answered "Success" at once' % context, (status, r))
        check(pending('invoiceninja_subscription_callback') == 1, '%s queued' % context)
        run_jobs('invoiceninja_subscription_callback')
        got = [e['event'] for e in events()[before:]]
        check(got == expected, '%s -> %s' % (context, expected), events())
    state = json.loads(wp_eval("echo wp_json_encode(get_option('invoiceninja_subscriptions_state'));"))
    check(state['ri:RI1']['status'] == 'cancelled', 'state follows', state)

    # A one-off purchase of another plan
    callback({'context': 'single_purchase', 'subscription': 'SUBT', 'client': 'CLIENT_MEMBER', 'invoice': 'INVS2', 'db': 'x'})
    run_jobs('invoiceninja_subscription_callback')
    check(named('purchased', 'SUBT'), 'single_purchase -> purchased')
    # Repeats and forgeries fire nothing
    before = len(events())
    callback(dict(base, context='recurring_purchase', recurring_invoice='RI1', invoice='INVP1'))
    callback(dict(base, context='recurring_purchase', client='CLIENT_MEMBER', recurring_invoice='RI1'))
    callback(dict(base, context='trial', recurring_invoice='NOPE'))
    run_jobs('invoiceninja_subscription_callback')
    check(len(events()) == before, 'repeated, mismatched or unknown records fire nothing', events()[before:])

    # The secret is shared by every subscription and visible in Invoice Ninja: a call with it
    # is only believed when Invoice Ninja's records agree
    member = {'client': 'CLIENT_MEMBER', 'contact': 'C2', 'account_key': '', 'db': 'x'}
    forged = [
        dict(member, context='single_purchase', subscription='SUBT'),                                   # no invoice at all
        dict(member, context='single_purchase', subscription='SUBT', invoice='INVU1'),                  # an unpaid invoice
        dict(member, context='cancellation', subscription='SUBY', recurring_invoice='RI2'),             # still billing
        dict(member, context='trial', subscription='SUBY', recurring_invoice='RI2'),                    # no trials on SUBY
        dict(member, context='recurring_purchase', subscription='SUBY', recurring_invoice='RI2', invoice='INVS2'),  # another plan's invoice
    ]
    for body in forged:
        status, r = callback(body)
        check(status == 200 and r == SUCCESS, 'a forged %s is still answered "Success"' % body['context'], r)
    run_jobs('invoiceninja_subscription_callback')
    check(len(events()) == before, 'forged purchases, cancellations and trials fire nothing', events()[before:])
    _, logs = b.api('GET', '/logs?channel=subscriptions&per_page=50')
    check(any('is not paid' in e['message'] for e in logs['items']) and any('still active' in e['message'] for e in logs['items']),
          'and say why in the log', [e['message'] for e in logs['items']][:10])

    # Paying another invoice doesn't resume a subscription suspended for an unpaid one
    wp_eval("$all = get_option('in_mock_invoices'); $o = $all['INVS1']; $o['id'] = 'INVO9'; $o['client_id'] = 'CLIENT_MEMBER'; $o['subscription_id'] = 'SUBY'; $o['recurring_id'] = 'RI2';"
            " $o['due_date'] = gmdate('Y-m-d', time() - 9 * DAY_IN_SECONDS); $all['INVO9'] = $o;"
            " $p = $o; $p['id'] = 'INVP9'; $p['status_id'] = '4'; $p['balance'] = 0; $all['INVP9'] = $p; update_option('in_mock_invoices', $all);")
    callback(dict(member, context='plan_expired', subscription='SUBY', invoice='INVO9'))
    run_jobs('invoiceninja_subscription_callback')
    check(named('suspended', 'SUBY'), 'suspended for INVO9')
    callback(dict(member, context='plan_paid', subscription='SUBY', recurring_invoice='RI2', invoice='INVP9'))
    run_jobs('invoiceninja_subscription_callback')
    check(named('paid', 'SUBY') and not named('resumed', 'SUBY'), 'paid, but not resumed while INVO9 is unpaid', events()[-3:])

    # The original integration still hears about purchases
    run_jobs('invoiceninja_subscription_forward')
    forwarded = [json.loads(e['body'])['body']['context'] for e in mock_log() if e.get('event') == 'legacy' and e['method'] == 'POST']
    check('recurring_purchase' in forwarded and 'cancellation' in forwarded, 'callbacks passed on to the original URL', forwarded)

    # The relay: signed, versioned, one delivery per event, in order. The jobs carry only the
    # client's lane (Action Scheduler refuses arguments over 8,000 characters); events wait in the outbox.
    args = wp_eval("echo wp_json_encode(array_map(function ($a) { return $a->get_args(); }, array_values(as_get_scheduled_actions(array('hook' => 'invoiceninja_subscription_relay', 'status' => 'pending'), 'objects'))));")
    check(len(args) < 400 and 'payload' not in args and 'lane' in args, 'relay jobs carry only the lane', args)
    check([e['event'] for e in outbox()] == ['purchased', 'trial', 'plan_changed', 'suspended', 'paid', 'resumed', 'cancelled'], 'the outbox holds them in order', outbox())
    run_jobs('invoiceninja_subscription_relay')
    deliveries = [e for e in mock_log() if e.get('event') == 'relay']
    kinds = [json.loads(d['body'])['event'] for d in deliveries]
    check(kinds == ['purchased', 'trial', 'plan_changed', 'suspended', 'paid', 'resumed', 'cancelled'], 'every SUBM event relayed once, in order', kinds)
    sequences = [json.loads(d['body'])['sequence'] for d in deliveries]
    check(sequences == sorted(sequences) and len(set(sequences)) == len(sequences), 'with a rising sequence', sequences)
    check(outbox() == [], 'and the outbox is empty', outbox())
    for d in deliveries:
        ts = d['headers']['X-Invoiceninja-Timestamp']
        good = 'sha256=' + hmac.new(relay_secret.encode(), (ts + '.' + d['body']).encode(), hashlib.sha256).hexdigest()
        check(hmac.compare_digest(d['headers']['X-Invoiceninja-Signature'], good), 'signature verifies', d['headers'])
        payload = json.loads(d['body'])
        check(payload['version'] == 1 and payload['subscription']['id'] == 'SUBM' and payload['client']['id'] == 'CLIENT_PLUS'
              and payload['recurring_invoice']['id'] == 'RI1' and payload['occurred_at'] and payload['sent_at'], 'payload', payload)

    # A failed delivery is retried later, and that client's later events wait behind it
    relay = "InvoiceNinja\\Plugin::instance()->module('subscriptions')->relay"
    client = "'client' => array('id' => 'CLIENT_PLUS')"
    reset_mock_log()
    option('in_mock_relay_fail', '1')
    try:
        wp_eval("%s->queue('SUBM', array('version' => 1, 'id' => 'evt_retry', 'event' => 'suspended', %s));" % (relay, client))
        run_jobs('invoiceninja_subscription_relay')
        waiting = outbox()
        check(len(waiting) == 1 and waiting[0]['attempts'] == 1 and waiting[0]['next_at'] > 0, 'kept for a retry (attempt 1 failed)', waiting)
        check(pending('invoiceninja_subscription_relay') == 1, 'one retry scheduled')
        wp('option', 'delete', 'in_mock_relay_fail')
        wp_eval("%s->queue('SUBM', array('version' => 1, 'id' => 'evt_after', 'event' => 'resumed', %s));" % (relay, client))
        check(pending('invoiceninja_subscription_relay') == 1, 'the later event joins the scheduled retry, not a job of its own')
        run_jobs('invoiceninja_subscription_relay')
        sent = [json.loads(e['body'])['id'] for e in mock_log() if e.get('event') == 'relay']
        check(sent == ['evt_retry'], 'before the retry time nothing more is sent: "resumed" never overtakes "suspended"', sent)
        wp_eval(RELAY_ROWS + " foreach ($rows as $n) { $v = get_option($n); if (is_array($v) && isset($v['next_at'])) { $v['next_at'] = 0; update_option($n, $v, false); } }")
        run_jobs('invoiceninja_subscription_relay')
        sent = [json.loads(e['body'])['id'] for e in mock_log() if e.get('event') == 'relay']
        check(sent == ['evt_retry', 'evt_retry', 'evt_after'], 'at the retry time both go, in order', sent)
        check(outbox() == [], 'delivered', outbox())
    finally:
        wp('option', 'delete', 'in_mock_relay_fail')
        wp_eval("as_unschedule_all_actions('invoiceninja_subscription_relay');")
    _, logs = b.api('GET', '/logs?channel=subscriptions&per_page=50')
    check(any('provisioning system' in e['message'] for e in logs['items']), 'deliveries are logged', logs)
    b.api('POST', '/admin/subscriptions/SUBM', {'connected': False, 'relay_url': ''})


@test
def return_page_states():
    subs_ready()
    seed()
    guest = Browser()
    guest.get('/?invoiceninja=subscribe&s=SUBY')
    status, _, html = guest.get('/?invoiceninja=subscribed')
    check(status == 200 and 'Setting up your subscription' in html, 'pending at first', html[:2000])
    _, r = guest.api('GET', '/subscriptions/return')
    check(r['state'] == 'pending', 'the page polls a pending state', r)
    callback({'context': 'recurring_purchase', 'subscription': 'SUBY', 'client': 'CLIENT_MEMBER', 'recurring_invoice': 'RI2', 'db': 'x'})
    _, _, html = guest.get('/?invoiceninja=subscribed')
    check('Your subscription is ready' in html, 'done once the purchase callback arrived', html[:2000])
    _, r = guest.api('GET', '/subscriptions/return')
    check(r['state'] == 'done', 'done', r)

    _, _, html = Browser().get('/?invoiceninja=subscribed')
    check('Thanks for subscribing' in html and 'Check your email' in html, 'no context: check your email', html[:2000])

    slow = Browser()
    slow.get('/?invoiceninja=subscribe&s=SUBT')
    slow.get('/?invoiceninja=subscribed')
    token = next(c.value for c in slow.jar if c.name == 'invoiceninja_sub')
    wp_eval("$k = 'invoiceninja_subctx_' . hash('sha256', '%s'); $c = get_transient($k); $c['returned_at'] = time() - 40; set_transient($k, $c, 3600);" % token)
    _, _, html = slow.get('/?invoiceninja=subscribed')
    check('Payment received' in html and "We'll email you" in html.replace('&#039;', "'").replace('&rsquo;', "'"), 'still setting up after 30s', html[:2000])

    uid = wp('user', 'get', 'member', '--field=ID')
    wp('user', 'meta', 'update', uid, '_invoiceninja_client_id', 'CLIENT_PLUS')
    wp('user', 'meta', 'update', uid, '_invoiceninja_contact_id', 'C1')
    wp('user', 'meta', 'update', uid, 'in_mock_verified', '1')
    try:
        member = Browser().login('member', 'pass')
        member.get('/?invoiceninja=subscription&do=change&r=RI1')
        callback({'context': 'change_plan', 'subscription': 'SUBY', 'client': 'CLIENT_PLUS', 'recurring_invoice': 'RI1', 'db': 'x'})
        _, _, html = member.get('/?invoiceninja=subscribed')
        check('Your plan has changed' in html, 'plan changed', html[:2000])
    finally:
        for key in ('_invoiceninja_client_id', '_invoiceninja_contact_id', 'in_mock_verified'):
            wp('user', 'meta', 'delete', uid, key, check_ok=False)
    run_jobs('invoiceninja_subscription_callback')


@test
def overdue_invoice_suspends_then_payment_resumes():
    b = subs_ready()
    seed()
    wp_eval("$all = get_option('in_mock_invoices'); $all['INVO1'] = array('id' => 'INVO1', 'number' => 'S-INVO1', 'client_id' => 'CLIENT_MEMBER', 'subscription_id' => 'SUBY', 'recurring_id' => 'RI3', 'status_id' => '2', 'amount' => 100, 'balance' => 100, 'paid_to_date' => 0, 'due_date' => gmdate('Y-m-d', time() - 10 * DAY_IN_SECONDS), 'po_number' => '', 'line_items' => array(), 'invitations' => array()); update_option('in_mock_invoices', $all);")
    poll = "InvoiceNinja\\Plugin::instance()->module('subscriptions')->lifecycle->pollOverdue();"

    b.api('POST', '/settings', {'subscriptions': {'suspend_days': 30}})
    wp_eval(poll)
    check(not named('suspended', 'SUBY'), 'not suspended before the configured days')

    b.api('POST', '/settings', {'subscriptions': {'suspend_days': 7}})
    wp_eval(poll)
    wp_eval(poll)
    check(len(named('suspended', 'SUBY')) == 1, 'suspended once by the daily poll', events())
    rows = json.loads(wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->module('subscriptions')->redirects->accountRows(array(), 'CLIENT_MEMBER', 0));"))
    check(any(r['id'] == 'RI3' and r['status'] == 'suspended' for r in rows), 'Account shows it suspended', rows)

    wp_eval("update_option('in_mock_paid', array('INVO1'));")
    wp_eval(poll)
    check(len(named('resumed', 'SUBY')) == 1, 'resumed once paid', events())

    # The late-invoice webhook (event 22) is a hint; a payment webhook resumes
    wp_eval("$all = get_option('in_mock_invoices'); $x = $all['INVO1']; $x['id'] = 'INVO2'; $x['due_date'] = gmdate('Y-m-d', time() - 8 * DAY_IN_SECONDS); $all['INVO2'] = $x; update_option('in_mock_invoices', $all);")
    key = webhook_secret()
    send_webhook(22, {'id': 'INVO2'}, key)
    run_jobs('invoiceninja_webhook_dispatch')
    check(len(named('suspended', 'SUBY')) == 2, 'late invoice webhook suspends', events())
    wp_eval("update_option('in_mock_payments', array('PAY2' => array('id' => 'PAY2', 'invoices' => array(array('id' => 'INVO2'))))); update_option('in_mock_paid', array('INVO1', 'INVO2'));")
    send_webhook(4, {'id': 'PAY2'}, key)
    run_jobs('invoiceninja_webhook_dispatch')
    check(len(named('resumed', 'SUBY')) == 2, 'payment webhook resumes', events())
    wp('option', 'delete', 'in_mock_payments')
    wp('option', 'delete', 'in_mock_paid')
