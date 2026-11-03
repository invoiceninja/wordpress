"""End-to-end flows for the Quote Request Form (M5): leads, draft quotes, tags, spam protection,
emails, the Quote requests screen and privacy tools. Invoice Ninja's quotes and tags are faked
by dev/mu-plugins/invoiceninja-mock-forms.php."""

import json
import re

from lib import Browser, api_calls, check, describe, option, reset_mock_log, wp, wp_eval
from test_v2 import admin, mails, store_ready

TESTS = []

M = "InvoiceNinja\\Plugin::instance()->module('forms')"


def test(fn):
    TESTS.append(fn)
    return fn


def php_json(code):
    out = wp_eval(code)
    try:
        return json.loads(out)
    except ValueError:
        raise AssertionError('not JSON: ' + out[-2000:])


def privacy_page():
    found = wp('post', 'list', '--post_type=page', '--name=quote-privacy', '--field=ID')
    if found:
        return int(found.split()[0])
    return int(wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Privacy Policy',
                  '--post_name=quote-privacy', '--porcelain'))


def forms_ready(**settings):
    """Store connected and synced, the form on, no leads, quotes or tags yet, fresh counters."""
    store_ready()
    b = admin()
    forms = {'reply_days': 2, 'redirect_url': '', 'autoreply': False, 'autoreply_subject': '',
             'tags': 'Quote request, Web', 'retention_days': 0, 'products': 'P1,P2'}
    forms.update(settings)
    status, r = b.api('POST', '/admin/forms', {'forms': forms, 'enabled': True, 'autoreply_text': ''})
    check(status == 200 and r['enabled'], 'form turned on', r)
    b.api('POST', '/settings', {'notifications': {'leads': 'instant', 'recipients': ''}})
    for name in ('in_mock_quotes', 'in_mock_tags', 'in_mock_quote_timeout', 'invoiceninja_quote_tags'):
        wp('option', 'delete', name, check_ok=False)
    wp_eval("foreach (get_posts(array('post_type' => 'invoiceninja_lead', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $id) { wp_delete_post($id, true); }"
            "global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%' OR option_name LIKE 'invoiceninja\\\\_lead\\\\_claim\\\\_%'\");")
    reset_mock_log()
    return b


def token(age=60, **attributes):
    """A form token as the block renders it, made `age` seconds ago."""
    attrs = json.dumps(attributes).replace("'", "\\'")
    return wp_eval("$m = %s; $c = InvoiceNinja\\Forms\\FormConfig::fromAttributes(json_decode('%s', true) ?: array(), $m->form->defaultProducts()); echo $m->form->token($c, time() - %d);"
                   % (M, attrs, age))


def submit(s, form, **fields):
    data = {'form': form, 'elapsed': 12, 'website': '', 'first_name': 'Quinn', 'last_name': 'Quote',
            'email': 'quinn.new@example.com', 'phone': '+1 555 0100', 'product': 'P1',
            'message': 'Please quote 20 widgets, delivered.'}
    data.update(fields)
    return s.api('POST', '/quote-requests', data)


def leads(email):
    return php_json("$m = %s; echo wp_json_encode(array_map(array($m->leads, 'find'), $m->leads->idsByEmail('%s')));" % (M, email))


def lead(email):
    found = leads(email)
    check(len(found) == 1, 'one lead for %s' % email, found)
    return found[0]


def run_job(lead_id, attempt=1):
    """What Action Scheduler does when the queued job runs."""
    wp_eval("do_action('invoiceninja_lead_to_quote', array('payload' => array('lead_id' => %d), 'attempt' => %d));" % (lead_id, attempt))


def mock_quotes():
    return php_json("echo wp_json_encode(array_values(get_option('in_mock_quotes', array())));")


def mock_tags():
    return php_json("echo wp_json_encode(get_option('in_mock_tags', array()) ?: new stdClass());")


@test
def quote_form_renders_and_refuses_instant_submits():
    forms_ready()
    page = privacy_page()
    slug = 'quote-form-flow'
    content = '[invoiceninja_quote_form privacy_page="%d" require_phone="true" heading="Get a quote"]' % page
    existing = wp('post', 'list', '--post_type=page', '--name=' + slug, '--field=ID')
    if existing:
        wp('post', 'update', existing.split()[0], '--post_content=' + content)
    else:
        wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=Quote', '--post_name=' + slug, '--post_content=' + content)
    status, _, html = Browser().get('/' + slug + '/')
    check(status == 200 and 'data-invoiceninja-quote-form' in html, 'the form renders', html[:1500])
    for needle in ('name="first_name"', 'name="email"', 'name="message"', 'Not sure yet', '>Widget<', '>Gadget<',
                   'name="privacy"', 'Privacy Policy', 'name="website"', 'assets/forms/forms.js', "within 2 days"):
        check(needle in html.replace('&#039;', "'"), 'form has %s' % needle)
    check(re.search(r'name="phone"[^>]*required', html), 'phone is required when the block says so')
    form = re.search(r'name="form" value="([^"]+)"', html).group(1)
    status, r = submit(Browser(), form, privacy=True)
    check(status == 422 and r['code'] == 'too_fast', 'a form sent the moment it loaded is refused', r)
    status, r = submit(Browser(), form, privacy=True, elapsed=9999)
    check(status == 422 and r['code'] == 'too_fast', "the time is signed into the form: a made-up 'elapsed' changes nothing", r)
    # Pages may be cached for days: an old token is refused with a fresh one, and forms.js swaps
    # in a fresh one as soon as someone starts typing
    old = token(age=13 * 3600, privacyPage=page)
    status, r = submit(Browser(), old, privacy=True, email='old.page@example.com')
    check(status == 422 and r['code'] == 'form_expired' and r.get('form'), 'a token older than 12 hours gets a fresh one back', r)
    check(not leads('old.page@example.com'), 'and nothing is saved yet')
    status, s = Browser().api('POST', '/quote-requests/start', {'form': old})
    check(status == 200 and s.get('form') and s['form'] != old, 'starting to fill the form in gets a fresh token', s)
    status, r = submit(Browser(), s['form'], privacy=True, email='old.page@example.com')
    check(status == 422 and r['code'] == 'too_fast', "whose clock starts then, so the minimum time still holds", r)
    status, s = Browser().api('POST', '/quote-requests/start', {'form': old + 'x'})
    check(status == 400 and s['code'] == 'form_changed', 'only a genuine form gets a fresh token', s)
    status, r = submit(Browser(), form + 'x', privacy=True)
    check(status == 400 and r['code'] == 'form_changed', 'a forged or damaged form is refused', r)
    status, r = submit(Browser(), token(privacyPage=page), email='not-an-email', message='', privacy=False)
    check(status == 422 and set(r['errors']) >= {'email', 'message', 'privacy'}, 'each problem has a message', r)
    status, r = submit(Browser(), token(), product='P999')
    check(status == 422 and 'product' in r['errors'], 'only the offered products can be chosen', r)
    check(not leads('quinn.new@example.com'), 'nothing saved for refused requests')


@test
def submit_saves_the_lead_then_creates_a_draft_quote_with_tags():
    forms_ready()
    page = privacy_page()
    s = Browser()
    status, r = submit(s, token(privacyPage=page), privacy=True)
    check(status == 200 and r['ok'] and 'within 2 days' in r['message'], 'request accepted with the reply time', r)
    saved = lead('quinn.new@example.com')
    check(saved['status'] == 'queued', 'saved locally first, queued for Invoice Ninja', saved)
    check(saved['fields']['message'] == 'Please quote 20 widgets, delivered.' and saved['fields']['product_title'] == 'Widget', 'fields stored', saved['fields'])
    check(saved['consents']['privacy']['agreed'] and saved['consents']['privacy']['at'] > 0, 'consent stored with its time', saved['consents'])
    check(any('New quote request from Quinn Quote' in m['subject'] for m in mails()), 'admin notified at once')

    run_job(saved['id'])
    saved = lead('quinn.new@example.com')
    check(saved['status'] == 'in_ninja' and saved['quote_status'] == 'draft' and not saved['matched'], 'draft quote created for a new client', saved)
    calls = api_calls()
    creates = [c for c in calls if c['method'] == 'POST' and c['url'].endswith('/clients')]
    check(len(creates) == 1, 'one client created', describe(calls))
    tags = mock_tags()
    check(sorted(t['name'] for t in tags.values()) == ['Quote request', 'Web'], 'tags created first (commas separate them)', tags)
    quotes = mock_quotes()
    check(len(quotes) == 1, 'one quote', quotes)
    q = quotes[0]
    check(q['client_id'] == 'NEWCLIENT' and q['status_id'] == '1', 'draft quote for the client', q)
    check(q['public_notes'] == 'Please quote 20 widgets, delivered.', 'the message is the public notes', q)
    line = q['line_items'][0] if len(q['line_items']) == 1 else {}
    check(line.get('product_key') == 'Widget' and line.get('cost') == 10 and line.get('quantity') == 1, 'one line item priced from the synced product', q['line_items'])
    check(sorted(q['tag_ids']) == sorted(tags.keys()), 'quote carries the tag ids', q)
    check('Ref: wp_' in q['private_notes'] and 'Agreed to Privacy Policy' in q['private_notes'], 'private notes carry the marker and consent', q['private_notes'])

    # An existing client is matched and never changed
    reset_mock_log()
    status, r = submit(Browser(), token(), email='a+b@x.com', first_name='Changed', last_name='Name', phone='999', product='not_sure')
    check(status == 200, 'second request accepted', r)
    match = lead('a+b@x.com')
    run_job(match['id'])
    match = lead('a+b@x.com')
    calls = api_calls()
    check(match['status'] == 'in_ninja' and match['matched'] and match['client_id'] == 'CLIENT_PLUS', 'existing client matched', match)
    writes = [c for c in calls if c['method'] in ('POST', 'PUT') and '/clients' in c['url']]
    check(not writes, "the existing client's name and phone are never overwritten", describe(writes))
    check(not [c for c in calls if c['url'].endswith('/tags') and c['method'] == 'POST'], 'tag ids come from the cache', describe(calls))
    q = [x for x in mock_quotes() if x['client_id'] == 'CLIENT_PLUS'][0]
    check(q['line_items'] == [] and 'Product: Not sure yet' in q['private_notes'], '"Not sure yet" adds no line item', q)

    b = admin()
    _, listing = b.api('GET', '/admin/quote-requests')
    row = [i for i in listing['items'] if i['email'] == 'a+b@x.com'][0]
    check(row['matched'] and row['status'] == 'in_ninja' and row['quote_status'] == 'draft', 'list shows the matched-client tag and status', row)
    check('/app/quotes/' + q['id'] in row['quote_url'], 'Open in Invoice Ninja link on 5.13.44+', row['quote_url'])
    _, found = b.api('GET', '/admin/quote-requests?search=quinn')
    check(found['total'] == 1 and found['items'][0]['email'] == 'quinn.new@example.com', 'search by name or email', found)


@test
def invoice_ninja_down_queues_the_lead_and_the_retry_creates_it_once():
    forms_ready()
    option('in_mock_mode', 'down')
    try:
        status, r = submit(Browser(), token(), email='down.lead@example.com')
        check(status == 200 and r['ok'], 'request accepted while Invoice Ninja is down', r)
        saved = lead('down.lead@example.com')
        run_job(saved['id'])
        saved = lead('down.lead@example.com')
        check(saved['status'] == 'queued' and saved['reason'].startswith('retrying:'), 'still queued, retrying', saved)
    finally:
        option('in_mock_mode', 'up')
    run_job(saved['id'], 2)
    run_job(saved['id'], 3)
    saved = lead('down.lead@example.com')
    check(saved['status'] == 'in_ninja', 'sent once Invoice Ninja is back', saved)
    check(len(mock_quotes()) == 1, 'exactly one quote after the retries', mock_quotes())

    # Unknown result: Invoice Ninja saved the quote but the reply was lost
    option('in_mock_quote_timeout', '1')
    try:
        submit(Browser(), token(), email='timeout.lead@example.com')
        lost = lead('timeout.lead@example.com')
        run_job(lost['id'])
    finally:
        wp('option', 'delete', 'in_mock_quote_timeout')
    lost = lead('timeout.lead@example.com')
    run_job(lost['id'], 2)
    lost = lead('timeout.lead@example.com')
    check(lost['status'] == 'in_ninja', 'found the quote created despite the timeout', lost)
    check(len(mock_quotes()) == 2, 'no duplicate quote', mock_quotes())

    # Invoice Ninja refuses the data: failed with a reason, then Retry from the admin list
    submit(Browser(), token(), email='fail.lead@example.com')
    failing = lead('fail.lead@example.com')
    wp_eval("add_filter('invoiceninja_quote_request_body', function ($b) { $b['client_id'] = ''; return $b; }); do_action('invoiceninja_lead_to_quote', array('payload' => array('lead_id' => %d), 'attempt' => 1));" % failing['id'])
    failing = lead('fail.lead@example.com')
    check(failing['status'] == 'failed' and 'rejected' in failing['reason'], 'refused data marks the lead failed with a reason', failing)
    b = admin()
    status, row = b.api('POST', '/admin/quote-requests/%d/retry' % failing['id'])
    check(status == 200 and row['status'] == 'in_ninja', 'Retry sends it', row)


@test
def honeypot_and_rate_limits_stop_spam():
    forms_ready()
    status, r = submit(Browser(), token(), email='bot@example.com', website='http://spam.example')
    check(status == 200 and r['ok'], 'bots get the normal reply', r)
    check(not leads('bot@example.com'), 'but nothing is saved')
    form = token()
    for i in range(3):
        status, r = submit(Browser(), form, email='busy@example.com', message='Request %d' % i)
        check(status == 200, 'request %d accepted' % (i + 1), r)
    status, r = submit(Browser(), form, email='busy@example.com')
    check(status == 429 and r['code'] == 'rate_limited', 'a fourth request from one email in an hour is refused', r)
    check(len(leads('busy@example.com')) == 3, 'three leads saved')
    for i in range(7):
        submit(Browser(), form, email='ip%d@example.com' % i)
    status, r = submit(Browser(), form, email='ip-last@example.com')
    check(status == 429, 'more than 10 requests an hour from one address are refused', r)
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")
    held = wp_eval("add_filter('invoiceninja_captcha', '__return_false'); $r = new WP_REST_Request('POST', '/invoiceninja/v1/quote-requests'); $r->set_header('origin', home_url()); $r->set_body_params(array('form' => '%s', 'elapsed' => 9, 'email' => 'captcha@example.com', 'first_name' => 'C', 'message' => 'Hi')); echo rest_do_request($r)->get_status();" % form)
    check(held == '422', 'the invoiceninja_captcha filter can refuse a request', held)

    # A flood from many addresses can't lock real people out: past the site-wide limit requests
    # are still saved, but held (nothing sent to Invoice Ninja, nobody emailed)
    site = "'invoiceninja_ctr_quotesite_' . (int) floor(time() / HOUR_IN_SECONDS)"
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")
    wp_eval("update_option(%s, 200, false);" % site)
    reset_mock_log()
    status, r = submit(Browser(), form, email='flood.real@example.com')
    check(status == 200 and r['ok'], 'past the site-wide limit a request still gets the normal reply', r)
    saved = lead('flood.real@example.com')
    check(saved['status'] == 'spam', 'held for review', saved)
    check(not mails(), 'and nobody is emailed', mails())
    check(not [c for c in api_calls() if '/quotes' in c['url']], 'and nothing is sent to Invoice Ninja')
    wp_eval("update_option(%s, 1000, false);" % site)
    status, r = submit(Browser(), form, email='flood.more@example.com')
    check(status == 429, 'far past it, requests are refused', r)
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")

    # Held requests don't pile up forever, even with retention off
    wp_eval("$m = %s; foreach ($m->leads->idsByEmail('flood.real@example.com') as $id) { wp_update_post(array('ID' => $id, 'post_date' => '2020-01-01 00:00:00', 'post_date_gmt' => '2020-01-01 00:00:00')); } $m->daily();" % M)
    check(not leads('flood.real@example.com'), 'held requests older than 30 days are deleted daily')


@test
def quote_approved_webhook_and_daily_refresh_update_the_lead():
    forms_ready()
    submit(Browser(), token(), email='approve.me@example.com')
    submit(Browser(), token(), email='send.me@example.com')
    for email in ('approve.me@example.com', 'send.me@example.com'):
        run_job(lead(email)['id'])
    approve = lead('approve.me@example.com')
    send = lead('send.me@example.com')
    wp_eval("do_action('invoiceninja_webhook_quote_approved', array('id' => '%s', 'status_id' => '3'), 21);" % approve['quote_id'])
    check(lead('approve.me@example.com')['quote_status'] == 'approved', 'quote-approved event marks the lead approved')
    wp_eval("$q = get_option('in_mock_quotes'); $q['%s']['status_id'] = '2'; update_option('in_mock_quotes', $q);" % send['quote_id'])
    wp_eval("%s->creator->daily();" % M)
    check(lead('send.me@example.com')['quote_status'] == 'sent', 'the daily refresh picks up sent quotes')


@test
def daily_digest_and_auto_reply():
    b = forms_ready(autoreply=True, autoreply_subject='Thanks {first_name}')
    b.api('POST', '/admin/forms', {'autoreply_text': 'Hi {first_name},\nwe reply within {days} days.'})
    b.api('POST', '/settings', {'notifications': {'leads': 'daily'}})
    submit(Browser(), token(), email='digest.me@example.com', first_name='Dana')
    reply = [m for m in mails('digest.me@example.com')]
    check(len(reply) == 1 and reply[0]['subject'] == 'Thanks Dana' and 'we reply within 2 days' in reply[0]['body'], 'auto-reply sent with the admin text', reply)
    check('Please quote' not in reply[0]['body'], 'the auto-reply never repeats the message')
    check(not [m for m in mails() if 'New quote request' in m['subject']], 'no instant email in digest mode')
    wp_eval("%s->emails->digest();" % M)
    digest = [m for m in mails() if '1 new quote request' in m['subject']]
    check(len(digest) == 1 and 'digest.me@example.com' in digest[0]['body'], 'one digest with the request', digest)
    wp_eval("%s->emails->digest();" % M)
    check(len([m for m in mails() if 'new quote request' in m['subject']]) == 1, 'each request is in one digest only')
    b.api('POST', '/settings', {'notifications': {'leads': 'instant'}})


@test
def privacy_exporter_eraser_and_retention():
    forms_ready()
    submit(Browser(), token(privacyPage=privacy_page()), email='private.person@example.com', privacy=True)
    exported = php_json("$e = apply_filters('wp_privacy_personal_data_exporters', array()); echo wp_json_encode(call_user_func($e['invoiceninja-quote-requests']['callback'], 'private.person@example.com', 1));")
    check(exported['done'] and len(exported['data']) == 1, 'one exported item', exported)
    values = {d['name']: d['value'] for d in exported['data'][0]['data']}
    check(values.get('Email') == 'private.person@example.com' and 'widgets' in values.get('Message', ''), 'export has the fields', values)
    check(any(k.startswith('Agreed to') for k in values), 'export has the consent', values)
    erased = php_json("$e = apply_filters('wp_privacy_personal_data_erasers', array()); echo wp_json_encode(call_user_func($e['invoiceninja-quote-requests']['callback'], 'private.person@example.com', 1));")
    check(erased['items_removed'] and erased['done'], 'eraser removed it', erased)
    check(not leads('private.person@example.com'), 'lead deleted')

    submit(Browser(), token(), email='old.lead@example.com')
    old = lead('old.lead@example.com')
    wp_eval("wp_update_post(array('ID' => %d, 'post_date_gmt' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS), 'post_date' => gmdate('Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS)));" % old['id'])
    submit(Browser(), token(), email='new.lead@example.com')
    admin().api('POST', '/admin/forms', {'forms': {'retention_days': 2}})
    wp_eval("%s->daily();" % M)
    check(not leads('old.lead@example.com') and leads('new.lead@example.com'), 'retention deletes only leads older than the setting')


@test
def admin_quote_request_routes_need_capabilities():
    forms_ready()
    anon = Browser()
    for method, path in (('GET', '/admin/quote-requests'), ('POST', '/admin/quote-requests/1/retry'),
                         ('DELETE', '/admin/quote-requests/1'), ('GET', '/admin/forms'), ('POST', '/admin/forms')):
        status, _ = anon.api(method, path, {} if method != 'GET' else None)
        check(status in (401, 403), '%s %s refuses anonymous users' % (method, path), status)
    member = Browser().login('member', 'pass')
    _, _, nonce = member.get('/wp-admin/admin-ajax.php?action=rest-nonce')
    member.nonce = nonce.strip()
    status, _ = member.api('GET', '/admin/quote-requests')
    check(status == 403, 'a subscriber can not list quote requests', status)
    b = admin()
    status, r = b.api('GET', '/admin/quote-requests')
    check(status == 200 and set(r) >= {'items', 'total', 'counts'}, 'admins can', r)
    status, r = b.api('GET', '/admin/quote-requests?page=2')
    check(status == 200 and r['items'] == [], 'pagination past the end is empty', r)
    _, _, types = Browser().get('/wp-json/wp/v2/types')
    check('"rest_base"' in types and 'invoiceninja_lead' not in types, 'the lead post type is not in REST', types[:500])
    status, _, html = Browser().login('admin', 'admin').get('/wp-admin/admin.php?page=invoiceninja-quotes')
    check(status == 200 and 'data-screen="quotes"' in html and 'assets/admin/forms.js' in html, 'Quote requests screen loads')
