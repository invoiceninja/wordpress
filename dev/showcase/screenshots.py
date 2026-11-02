#!/usr/bin/env python3
"""Build the demo ceramics shop on the dev stack, take the README screenshots, then put the
stack back as the flow tests expect it.

    python3 dev/showcase/screenshots.py

Everything runs in Docker: the setup goes through WP-CLI and REST, the browser is the
Playwright image. Images land in docs/images/ and .wordpress-org/screenshot-N.png.
"""

import json
import os
import shutil
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(ROOT, 'tests', 'flows'))

from lib import Browser, option, wp, wp_eval  # noqa: E402
from test_v2 import admin, connect, last_code  # noqa: E402

PLUGIN = '/var/www/html/wp-content/plugins/invoiceninja'
IMAGES = {
    'SPECKLEDMUG': 'mug', 'SERVINGBOWL': 'bowl', 'BUDVASE': 'vase', 'DINNERPLATES': 'plates',
    'TEAPOT': 'teapot', 'PLANTER': 'planter', 'POUROVER': 'cone', 'BUTTERDISH': 'butter',
}


def page(title, content, slug):
    existing = wp('post', 'list', '--post_type=page', '--name=' + slug, '--field=ID', check_ok=False)
    if existing:
        wp('post', 'update', existing.split()[0], '--post_content=' + content, '--post_status=publish')
        return existing.split()[0]
    return wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=' + title,
              '--post_name=' + slug, '--post_content=' + content, '--porcelain')


def setup():
    wp('plugin', 'deactivate', 'woocommerce', check_ok=False)
    option('in_mock_showcase', '1')
    option('blogname', 'Larch & Pine Ceramics')
    option('blogdescription', 'Small-batch stoneware')
    b = admin()
    connect(b)
    wp('invoiceninja', 'environment', 'confirm')
    b.api('POST', '/settings', {'modules': {'store': True, 'account': True, 'quote_form': True, 'subs': True, 'woo': False},
                                'store': {'mode': 'cart', 'require_login': False}})
    # Hide the test catalogue; remember what to restore.
    hidden = wp_eval("$keep = array('SPECKLEDMUG','SERVINGBOWL','BUDVASE','DINNERPLATES','TEAPOT','PLANTER','POUROVER','BUTTERDISH'); $out = array();"
                     "foreach (get_posts(array('post_type' => 'invoiceninja_product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $id) {"
                     " $in = get_post_meta($id, 'product_id', true);"
                     " if (in_array($in, $keep, true)) { wp_update_post(array('ID' => $id, 'post_status' => 'publish')); continue; }"
                     " if ('publish' === get_post_status($id)) { wp_update_post(array('ID' => $id, 'post_status' => 'draft')); $out[] = $id; } }"
                     "echo wp_json_encode($out);")
    option('in_showcase_hidden', hidden)
    b.api('POST', '/sync/products', {'force': True})
    for in_id, image in IMAGES.items():
        post = wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', '%s'); echo $m ? $m['wp_id'] : '';" % in_id)
        if post and not wp_eval("echo get_post_thumbnail_id(%s);" % post).strip('0'):
            wp('media', 'import', '%s/dev/showcase/images/%s.png' % (PLUGIN, image), '--post_id=' + post, '--featured_image', '--title=' + image)
    b.api('POST', '/store/display', {'action': 'blocks'})
    wp('rewrite', 'flush')
    b.api('POST', '/pages', {'pages': ['shop', 'checkout', 'status', 'account']})
    pages = json.loads(wp_eval("$o = InvoiceNinja\\Plugin::instance()->options; echo wp_json_encode($o->setting('pages'));"))
    wp('post', 'update', str(pages['shop']), '--post_title=Shop', '--post_content=<!-- wp:paragraph --><p>Small-batch stoneware, made and glazed in our studio.</p><!-- /wp:paragraph --><!-- wp:invoiceninja/products {"columns":4,"perPage":8,"align":"wide"} /-->')
    b.api('POST', '/admin/forms', {'forms': {'reply_days': 2, 'redirect_url': '', 'autoreply': False, 'autoreply_subject': '', 'tags': 'Quote request',
                                             'retention_days': 0, 'products': 'TEAPOT,DINNERPLATES,SERVINGBOWL'}, 'enabled': True, 'autoreply_text': ''})
    quote = page('Request a quote', '<!-- wp:paragraph --><p>Planning a café, a wedding or a gift order? Tell us what you need.</p><!-- /wp:paragraph --><!-- wp:invoiceninja/quote-form /-->', 'request-a-quote')
    b.api('POST', '/admin/subscriptions/sync')
    plans = page('Membership', '<!-- wp:paragraph --><p>Join the studio: a new piece every season, and first pick of every kiln.</p><!-- /wp:paragraph --><!-- wp:invoiceninja/pricing-table {"ids":"SUBT,SUBM,SUBW","yearly":"SUBM:SUBY","highlight":"SUBM","align":"wide"} /-->', 'membership')
    # A tidy header menu (the theme's navigation uses the newest menu).
    link = lambda post_id: wp_eval("echo wp_make_link_relative(get_permalink(%s));" % post_id)
    links = ''.join('<!-- wp:navigation-link {"label":"%s","url":"%s","kind":"custom"} /-->' % (label, url) for label, url in (
        ('Shop', link(pages['shop'])), ('Request a quote', link(quote)), ('Membership', link(plans)), ('Account', link(pages['account']))))
    nav = wp('post', 'create', '--post_type=wp_navigation', '--post_status=publish', '--post_title=Showcase', '--post_content=' + links, '--porcelain')
    option('in_showcase_nav', nav)
    return b, pages, quote, plans


def orders(b):
    """A few orders in different states for the admin screens and the Account."""
    wp_eval("global $wpdb; $wpdb->query('DELETE FROM ' . InvoiceNinja\\Commerce\\Orders::table());")
    option('in_mock_invoices', '')
    wp('option', 'delete', 'in_mock_invoices', check_ok=False)
    wp_eval("global $wpdb; $wpdb->query(\"DELETE FROM {$wpdb->options} WHERE option_name LIKE 'invoiceninja\\\\_ctr\\\\_%'\");")
    people = [('ana.silva@example.com', 'Ana', 'Silva', {'SPECKLEDMUG': 2, 'BUDVASE': 1}),
              ('omar.haddad@example.com', 'Omar', 'Haddad', {'SERVINGBOWL': 1}),
              ('priya.nair@example.com', 'Priya', 'Nair', {'TEAPOT': 1, 'POUROVER': 1}),
              ('mei.lin@example.com', 'Mei', 'Lin', {'PLANTER': 2, 'SPECKLEDMUG': 1}),
              ('tomas.ruiz@example.com', 'Tomás', 'Ruiz', {'BUTTERDISH': 1})]
    # The member shops as Mei Lin for the pictures (restored in teardown).
    wp_eval("$u = get_user_by('login', 'member'); wp_update_user(array('ID' => $u->ID, 'user_email' => 'mei.lin@example.com', 'display_name' => 'Mei Lin', 'first_name' => 'Mei', 'last_name' => 'Lin')); foreach (array('_invoiceninja_client_id', '_invoiceninja_contact_id') as $k) { delete_user_meta($u->ID, $k); }")
    numbers = []
    for email, first, last, items in people:
        s = Browser()
        for product, qty in items.items():
            s.api('POST', '/cart/items', {'product': product, 'quantity': qty})
        _, started = s.api('POST', '/checkout/start', {'email': email, 'first_name': first, 'last_name': last})
        if not started.get('ok'):
            raise RuntimeError('checkout start failed for %s: %s' % (email, started))
        _, r = s.api('POST', '/checkout/confirm', {'email': email, 'first_name': first, 'last_name': last, 'code': last_code(email)})
        numbers.append(r.get('number'))
    # One paid, one cancelled, one needing attention.
    invoice = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['invoice_id'];" % numbers[2])
    wp_eval("update_option('in_mock_paid', array('%s')); $o = InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s'); delete_transient('invoiceninja_order_check_' . $o['id']); InvoiceNinja\\Plugin::instance()->orderService->refreshStatus($o);" % (invoice, numbers[2]))
    oid = wp_eval("echo InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['id'];" % numbers[4])
    b.api('POST', '/admin/orders/%s/cancel' % oid, {})
    wp_eval("InvoiceNinja\\Plugin::instance()->orders->update(InvoiceNinja\\Plugin::instance()->orders->findBy('number', '%s')['id'], array('status' => 'attention', 'note' => 'error:validation'));" % numbers[1])
    # The member's account: verified and linked.
    wp_eval("$u = get_user_by('login', 'member'); InvoiceNinja\\Plugin::instance()->module('portal')->verification->mark($u->ID, 'admin');")
    # Two quote requests (earlier runs' requests removed first).
    wp_eval("foreach (get_posts(array('post_type' => 'invoiceninja_lead', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $id) { wp_delete_post($id, true); }")
    form = wp_eval("$m = InvoiceNinja\\Plugin::instance()->module('forms'); $c = InvoiceNinja\\Forms\\FormConfig::fromAttributes(array(), $m->form->defaultProducts()); echo $m->form->token($c, time() - 60);")
    for email, first, product, message in (('cafe.owner@example.com', 'Lena', 'TEAPOT', 'Twelve teapots for our new café, in the sage glaze if possible.'),
                                           ('wedding@example.com', 'Sam', 'DINNERPLATES', 'Sixty plates for a wedding in June.')):
        Browser().api('POST', '/quote-requests', {'form': form, 'elapsed': 20, 'website': '', 'first_name': first, 'last_name': 'Example',
                                                  'email': email, 'phone': '', 'product': product, 'message': message})
    wp_eval("$ids = get_posts(array('post_type' => 'invoiceninja_lead', 'post_status' => 'any', 'numberposts' => 5, 'fields' => 'ids'));"
            "foreach (array_slice($ids, 0, 1) as $id) { do_action('invoiceninja_lead_to_quote', array('payload' => array('lead_id' => $id), 'attempt' => 1)); }")
    return numbers


def teardown():
    wp('option', 'delete', 'in_mock_showcase', check_ok=False)
    wp('option', 'delete', 'in_mock_paid', check_ok=False)
    wp_eval("$u = get_user_by('login', 'member'); if ($u) { wp_update_user(array('ID' => $u->ID, 'user_email' => 'a+b@x.com', 'display_name' => 'Mem Ber', 'first_name' => 'Mem', 'last_name' => 'Ber')); foreach (array('_invoiceninja_client_id', '_invoiceninja_contact_id') as $k) { delete_user_meta($u->ID, $k); } }")
    option('blogname', 'Invoice Ninja Tests')
    hidden = json.loads(wp('option', 'get', 'in_showcase_hidden', check_ok=False) or '[]')
    wp_eval("foreach (json_decode('%s', true) as $id) { wp_update_post(array('ID' => $id, 'post_status' => 'publish')); }"
            "foreach (array('P1', 'P2') as $p) { $m = InvoiceNinja\\Plugin::instance()->mapping->find('product', $p); if ($m) { wp_update_post(array('ID' => $m['wp_id'], 'post_status' => 'publish')); } }"
            "foreach (array('SPECKLEDMUG','SERVINGBOWL','BUDVASE','DINNERPLATES','TEAPOT','PLANTER','POUROVER','BUTTERDISH') as $p) {"
            " $m = InvoiceNinja\\Plugin::instance()->mapping->find('product', $p); if ($m) { wp_delete_post($m['wp_id'], true); } }"
            "global $wpdb; $wpdb->query(\"DELETE FROM \" . InvoiceNinja\\Sync\\Mapping::table() . \" WHERE in_id IN ('SPECKLEDMUG','SERVINGBOWL','BUDVASE','DINNERPLATES','TEAPOT','PLANTER','POUROVER','BUTTERDISH')\");" % json.dumps(hidden))
    wp('option', 'delete', 'in_showcase_hidden', check_ok=False)
    nav = wp('option', 'get', 'in_showcase_nav', check_ok=False)
    if nav:
        wp('post', 'delete', nav, '--force', check_ok=False)
        wp('option', 'delete', 'in_showcase_nav', check_ok=False)
    b = admin()
    b.api('POST', '/store/display', {'action': 'legacy'})
    wp('rewrite', 'flush')


def capture(pages, quote, plans, numbers):
    urls = {k: wp_eval("echo wp_make_link_relative(get_permalink(%s));" % v) for k, v in pages.items() if v}
    urls['quote'] = wp_eval("echo wp_make_link_relative(get_permalink(%s));" % quote)
    urls['plans'] = wp_eval("echo wp_make_link_relative(get_permalink(%s));" % plans)
    urls['product'] = wp_eval("$m = InvoiceNinja\\Plugin::instance()->mapping->find('product', 'TEAPOT'); echo wp_make_link_relative(get_permalink($m['wp_id']));")
    urls['editor'] = '/wp-admin/post.php?post=%s&action=edit' % pages['shop']
    out = os.path.join(ROOT, 'docs', 'images')
    os.makedirs(out, exist_ok=True)
    # The block editor's welcome guide would cover the screenshot.
    wp_eval("update_user_meta(1, $GLOBALS['wpdb']->get_blog_prefix() . 'persisted_preferences', array('core/edit-post' => array('welcomeGuide' => false), 'core' => array('welcomeGuide' => false), '_modified' => gmdate('c')));")
    cmd = ['docker', 'run', '--rm', '--network', 'container:invoiceninja-dev-wp-loopback-1',
           '-v', os.path.join(ROOT, 'dev', 'showcase') + ':/s:ro', '-v', out + ':/out',
           'mcr.microsoft.com/playwright/python:v1.48.0-jammy', 'sh', '-c',
           'pip install -q playwright==1.48.0 >/dev/null 2>&1 && python /s/capture.py "$0"', json.dumps(urls)]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=1800)
    print(result.stdout[-3000:], result.stderr[-3000:])
    # WordPress.org screenshots, in the readme's order.
    order = ['shop', 'checkout-pay', 'order-status', 'account', 'pricing-table', 'quote-form', 'admin-home', 'admin-orders', 'admin-status', 'admin-setup']
    os.makedirs(os.path.join(ROOT, '.wordpress-org'), exist_ok=True)
    for n, name in enumerate(order, 1):
        src = os.path.join(out, name + '.png')
        if os.path.exists(src):
            shutil.copy(src, os.path.join(ROOT, '.wordpress-org', 'screenshot-%d.png' % n))


if __name__ == '__main__':
    try:
        b, pages, quote, plans = setup()
        numbers = orders(b)
        capture(pages, quote, plans, numbers)
    finally:
        teardown()
