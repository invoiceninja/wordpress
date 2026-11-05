"""End-to-end flows for M3: verified emails, the Account block, verified redirects, Pay Invoice, privacy."""

from lib import Browser, api_calls, check, describe, reset_mock_log, wp, wp_eval
from test_v2 import admin, checkout, last_code, mails, shopper, store_ready

TESTS = []


def test(fn):
    TESTS.append(fn)
    return fn


def member():
    wp_eval("$u = get_user_by('login', 'member'); foreach (array('_invoiceninja_verified_at', '_invoiceninja_verified_email_hash', '_invoiceninja_client_id', '_invoiceninja_contact_id', '_invoiceninja_link_email_hash') as $k) { delete_user_meta($u->ID, $k); }")
    return Browser().login('member', 'pass')


def pay_form(ago=10):
    """A Pay Invoice form token as if the block was rendered `ago` seconds ago."""
    return wp_eval("echo InvoiceNinja\\Portal\\Account::formToken(time() - %d, InvoiceNinja\\Plugin::instance()->secrets->get('secret'));" % ago)


def pay_invoice(number, email, **extra):
    data = {'number': number, 'email': email, 'in_leave_empty': '', 'form': pay_form(), 'elapsed': 10}
    data.update(extra)
    return Browser().api('POST', '/pay-invoice', data)


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
    status, again = m.api('POST', '/account/verify/start', {})
    check(status == 200 and again['ok'] and (r['sent'] is False or again['sent'] is False) and again['retry_after'] > 0,
          'Resend within the minute says no new code was sent, and when one can be', (r, again))
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
    m.api('GET', '/account')  # links the client found for a+b@x.com
    check(wp_eval("echo get_user_meta(%s, '_invoiceninja_client_id', true);" % uid) == 'CLIENT_PLUS', 'linked by email')
    wp('user', 'update', uid, '--user_email=changed@x.com')
    try:
        _, me = Browser().login('member', 'pass').api('GET', '/account')
        check(not me['verified'], 'a new email must be verified again', me)
        check(wp_eval("echo get_user_meta(%s, '_invoiceninja_client_id', true);" % uid) == '', 'the link made for the old email is gone')
    finally:
        wp('user', 'update', uid, '--user_email=a+b@x.com')


@test
def a_link_made_for_another_email_never_opens_its_invoices():
    # The takeover: an account registered with someone else's email gets linked to their
    # client (e.g. by a guest order), then switches to an email it can verify.
    store_ready()
    m = member()
    uid = member_id()
    wp_eval("InvoiceNinja\\Plugin::instance()->module('portal')->links->link(%s, 'a+b@x.com', 'CLIENT_PLUS', 'C1');" % uid)
    wp('user', 'update', uid, '--user_email=mallory@x.com')
    try:
        wp_eval("InvoiceNinja\\Plugin::instance()->module('portal')->verification->mark(%s, 'admin');" % uid)
        m = Browser().login('member', 'pass')
        _, me = m.api('GET', '/account')
        check(me['verified'] and not me['linked'], "verified for the new email, but not linked to the old email's client", me)
        _, rows = m.api('GET', '/account/invoices')
        check(rows.get('rows') == [], 'no invoices of the old client', rows)
        status, location, _ = m.get('/?invoiceninja=portal')
        check('in.test' not in (location or ''), "never redirected into the old client's portal", (status, location))
        # Even a link someone wrote straight into user meta for the old email is ignored
        wp_eval("update_user_meta(%s, '_invoiceninja_client_id', 'CLIENT_PLUS'); update_user_meta(%s, '_invoiceninja_contact_id', 'C1');" % (uid, uid))
        status, location, _ = m.get('/?invoiceninja=portal')
        check('in.test' not in (location or ''), 'a link without the email it was made for is not trusted', (status, location))
    finally:
        wp('user', 'update', uid, '--user_email=a+b@x.com')


@test
def bulk_verify_needs_the_form_nonce():
    b = admin()
    uid = member_id()
    member()
    # A forged link (what users.php passes on after dropping the nonce)
    status, _, _ = b.get('/wp-admin/users.php?action=invoiceninja_verify&users[]=%s' % uid)
    verified = wp_eval("echo InvoiceNinja\\Plugin::instance()->module('portal')->verification->isVerified(%s) ? 'yes' : 'no';" % uid)
    check(verified == 'no', 'a link without the bulk-users nonce verifies nobody', (status, verified))
    _, _, html = b.get('/wp-admin/users.php')
    import re
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', html).group(1)
    status, location, _ = b.get('/wp-admin/users.php?_wpnonce=%s&_wp_http_referer=%%2Fwp-admin%%2Fusers.php&action=invoiceninja_verify&users[]=%s' % (nonce, uid))
    verified = wp_eval("echo InvoiceNinja\\Plugin::instance()->module('portal')->verification->isVerified(%s) ? 'yes' : 'no';" % uid)
    check(verified == 'yes' and 'invoiceninja_verified=1' in (location or ''), 'the real bulk action still works', (status, location, verified))


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
    _, a = pay_invoice(number, 'a+b@x.com')
    _, z = pay_invoice(number, 'wrong@x.com')
    check(a == z == {'ok': True}, 'the same reply', (a, z))
    sent = mails('a+b@x.com')
    check(len(sent) == 1 and 'INVITE' in sent[0]['body'], 'the payment link goes to the email on the invoice', (sent, describe(api_calls())))
    check([c for c in api_calls() if 'invoices?' in c['url'] and 'number=' in c['url'] and 'filter=' not in c['url']],
          "the invoice is looked up by its exact number", describe(api_calls()))


@test
def pay_invoice_drops_bots_and_rushed_submits():
    store_ready()
    _, order = checkout(shopper(), 'a+b@x.com')
    number = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['invoice_number'];" % order['number'])
    reset_mock_log()
    _, r = pay_invoice(number, 'a+b@x.com', in_leave_empty='http://spam.example')
    check(r == {'ok': True}, 'the spam trap gets the usual reply', r)
    status, r = pay_invoice(number, 'a+b@x.com', elapsed=1)
    check(status == 422 and r['code'] == 'too_fast', 'too fast to be a person', (status, r))
    status, r = pay_invoice(number, 'a+b@x.com', form='1.forged')
    check(status == 400, 'a forged form token is refused', (status, r))
    # Pay Invoice has its own CAPTCHA filter
    wp('option', 'update', 'in_mock_pay_invoice_captcha_fail', '1')
    try:
        status, r = pay_invoice(number, 'a+b@x.com')
        check(status == 422 and r['code'] == 'captcha', 'invoiceninja_pay_invoice_captcha can refuse a request', (status, r))
    finally:
        wp('option', 'delete', 'in_mock_pay_invoice_captcha_fail', check_ok=False)
    check(not api_calls() and not mails('a+b@x.com'), 'nothing looked up or sent', describe(api_calls()))


@test
def pay_invoice_ignores_autofill_and_quote_form_captchas():
    store_ready()
    _, order = checkout(shopper(), 'a+b@x.com')
    number = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['invoice_number'];" % order['number'])
    html = wp_eval("echo do_blocks('<!-- wp:invoiceninja/pay-invoice /-->');")
    check('name="in_leave_empty"' in html and 'name="website"' not in html and 'autocomplete="off"' in html and 'tabindex="-1"' in html and 'aria-hidden="true"' in html,
          'the spam trap has a name autofill leaves alone', html)
    reset_mock_log()
    # A CAPTCHA hooked to the quote form (ignoring its context) and an autofilled "website" field
    wp('option', 'update', 'in_mock_captcha_fail', '1')
    try:
        _, r = pay_invoice(number, 'a+b@x.com', website='https://buyer.example')
        check(r == {'ok': True}, 'the usual reply', r)
    finally:
        wp('option', 'delete', 'in_mock_captcha_fail', check_ok=False)
    check(len(mails('a+b@x.com')) == 1, 'and the payment link is sent', describe(api_calls()))
