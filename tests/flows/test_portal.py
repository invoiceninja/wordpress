"""End-to-end flows for M3: verified emails, the Account block, verified redirects, Pay Invoice, privacy."""

from lib import Browser, api_calls, check, describe, reset_mock_log, wp, wp_eval
from test_v2 import admin, checkout, last_code, mails, shopper, store_ready

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


def member():
    wp_eval("$u = get_user_by('login', 'member'); foreach (array('_invoiceninja_verified_at', '_invoiceninja_verified_email_hash', '_invoiceninja_client_id', '_invoiceninja_contact_id') as $k) { delete_user_meta($u->ID, $k); }")
    return Browser().login('member', 'pass')


def member_id():
    return wp_eval("echo get_user_by('login', 'member')->ID;")


@test
def account_verifies_the_email_inline_then_shows_invoices():
    store_ready()
    m = member()
    status, me = m.api('GET', '/account')
    check(status == 200 and me['email'] == 'a+b@x.com' and not me['verified'], 'a logged-in user starts unverified', me)
    status, r = m.api('GET', '/account/invoices')
    check(status == 403, 'tabs need a verified email', (status, r))
    status, _ = Browser().api('GET', '/account')
    check(status in (401, 403), 'logged-out visitors get nothing', status)
    status, r = m.api('POST', '/account/verify/start', {})
    check(status == 200 and r['ok'], 'code sent to the account email', r)
    status, r = m.api('POST', '/account/verify/confirm', {'code': last_code('a+b@x.com')})
    check(status == 200 and r['ok'], 'code confirmed', r)
    _, me = m.api('GET', '/account')
    check(me['verified'] and me['linked'], 'verified and linked to the client by email', me)
    # An order for this client shows in Invoices with Pay and PDF links through the site
    _, order = checkout(shopper(), 'a+b@x.com')
    wp_eval("delete_transient('invoiceninja_acct_%s_invoices');" % member_id())
    _, rows = m.api('GET', '/account/invoices')
    check(rows['rows'] and rows['rows'][0]['links']['pay'].startswith('http') and '?invoiceninja=pay' in rows['rows'][0]['links']['pay'], 'unpaid invoice with a Pay link through the site', rows)
    check('key_login' not in str(rows) and 'INVITE' not in str(rows), 'no invitation or portal links in the reply', rows)
    pay = rows['rows'][0]['links']['pay'].split('8089', 1)[-1]
    status, location, _ = m.get(pay)
    check(status == 302 and location.startswith('https://in.test/client/invoice/INVITE'), "Pay goes to the buyer's own invitation", (status, location))
    pdf = rows['rows'][0]['links']['pdf'].split('8089', 1)[-1]
    status, _, body = m.get(pdf)
    check(status == 200 and body.startswith('%PDF'), 'PDF comes through the site', (status, body[:40]))
    # Someone else's invoice id is refused
    status, location, _ = m.get('/?invoiceninja=pay&type=invoice&id=NOTMINE')
    check(status == 302 and 'in.test' not in (location or ''), "another client's invoice never redirects to Invoice Ninja", (status, location))


@test
def changing_the_email_clears_verification():
    m = member()
    uid = member_id()
    wp_eval("InvoiceNinja\\Plugin::instance()->module('portal')->verification->mark(%s, 'admin');" % uid)
    _, me = m.api('GET', '/account')
    check(me['verified'], 'verified by an admin')
    wp('user', 'update', uid, '--user_email=changed@x.com')
    try:
        _, me = Browser().login('member', 'pass').api('GET', '/account')
        check(not me['verified'], 'a new email must be verified again', me)
    finally:
        wp('user', 'update', uid, '--user_email=a+b@x.com')


@test
def verified_customers_check_out_without_a_code():
    store_ready()
    m = member()
    wp_eval("InvoiceNinja\\Plugin::instance()->module('portal')->verification->mark(%s, 'admin');" % member_id())
    m.api('POST', '/cart/items', {'product': 'P1', 'quantity': 1})
    status, r = m.api('POST', '/checkout/start', {'email': 'a+b@x.com'})
    check(status == 200 and r['next'] == 'confirm', 'no code for a verified account email', r)
    status, r = m.api('POST', '/checkout/confirm', {'email': 'a+b@x.com', 'first_name': 'Mem', 'last_name': 'Ber'})
    check(status == 200 and r['ok'], 'order placed', r)


@test
def admin_tools_profile_panel_bulk_and_privacy():
    store_ready()
    b = admin()
    uid = member_id()
    st, _, html = b.get('/wp-admin/user-edit.php?user_id=%s' % uid)
    check(st == 200 and 'invoiceninja_verified' in html, 'profile panel', st)
    st, _, users = b.get('/wp-admin/users.php')
    check('Invoice Ninja' in users, 'Users column')
    status, r = b.api('POST', '/customers/verify-role', {'role': 'subscriber'})
    check(status == 200 and r['verified'] >= 1, 'verify a whole role', r)
    status, r = b.api('POST', '/customers/export', {})
    check(status == 200 and 'queued' in r, 'export queued', r)
    # Privacy: an order is exported, then anonymised
    _, order = checkout(shopper(), 'privacy.buyer@example.com')
    out = wp_eval("$p = new InvoiceNinja\\Privacy\\Privacy(InvoiceNinja\\Plugin::instance()); echo wp_json_encode($p->export('privacy.buyer@example.com'));")
    check(order['number'] in out, 'orders appear in the export', out[:300])
    wp_eval("$p = new InvoiceNinja\\Privacy\\Privacy(InvoiceNinja\\Plugin::instance()); $p->erase('privacy.buyer@example.com');")
    left = wp_eval("echo wp_json_encode(InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'));" % order['number'])
    check('privacy.buyer' not in left and order['number'] in left, 'erased: email gone, order number kept', left[:300])


@test
def pay_invoice_emails_the_link_with_the_same_reply_either_way():
    store_ready()
    _, order = checkout(shopper(), 'a+b@x.com')
    number = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['invoice_number'];" % order['number'])
    reset_mock_log()
    _, a = Browser().api('POST', '/pay-invoice', {'number': number, 'email': 'a+b@x.com'})
    _, z = Browser().api('POST', '/pay-invoice', {'number': number, 'email': 'wrong@x.com'})
    check(a == z == {'ok': True}, 'the same reply', (a, z))
    sent = mails('a+b@x.com')
    check(len(sent) == 1 and 'INVITE' in sent[0]['body'], 'the payment link goes to the email on the invoice', describe(sent))
