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
from test_v2 import admin, connect, last_code, run_jobs  # noqa: E402
import run as flows  # noqa: E402

PLUGIN = '/var/www/html/wp-content/plugins/invoiceninja'
IMAGES = {
    'SPECKLEDMUG': 'mug', 'SERVINGBOWL': 'bowl', 'BUDVASE': 'vase', 'DINNERPLATES': 'plates',
    'TEAPOT': 'teapot', 'PLANTER': 'planter', 'POUROVER': 'cone', 'BUTTERDISH': 'butter',
}


# The theme's header with the Mini Cart block beside the navigation (a site override, removed in teardown).
HEADER = ('<!-- wp:group {"align":"full","layout":{"type":"default"}} --><div class="wp-block-group alignfull">'
          '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
          '<!-- wp:group {"align":"wide","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|30"}}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"space-between"}} -->'
          '<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--30)">'
          '<!-- wp:site-title {"level":0} /-->'
          '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","flexWrap":"nowrap","justifyContent":"right"}} --><div class="wp-block-group">'
          '<!-- wp:navigation {"overlayBackgroundColor":"base","overlayTextColor":"contrast","layout":{"type":"flex","justifyContent":"right","flexWrap":"wrap"}} /-->'
          '<!-- wp:invoiceninja/mini-cart /-->'
          '</div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group --></div><!-- /wp:group -->')


def wide_title(title, intro=''):
    """A page heading as wide as a wide block, for pages on the theme's no-title template:
    the theme's own title sits in the narrow content column, out of line with a wide block."""
    para = '<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->' % intro if intro else ''
    return ('<!-- wp:group {"align":"wide","layout":{"type":"default"}} --><div class="wp-block-group alignwide">'
            '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">%s</h1><!-- /wp:heading -->%s'
            '</div><!-- /wp:group -->') % (title, para)


def page(title, content, slug):
    existing = wp('post', 'list', '--post_type=page', '--name=' + slug, '--field=ID', check_ok=False)
    if existing:
        wp('post', 'update', existing.split()[0], '--post_content=' + content, '--post_status=publish')
        return existing.split()[0]
    return wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=' + title,
              '--post_name=' + slug, '--post_content=' + content, '--porcelain')


def setup():
    # A fresh stack has no WordPress yet: install it the way the flow tests do.
    flows.setup()
    wp('plugin', 'deactivate', 'woocommerce', check_ok=False)
    # The demo orders are placed by script, faster than checkout's anti-bot minimum.
    option('in_mock_checkout_min_seconds', '0')
    option('in_mock_showcase', '1')
    option('blogname', 'Larch & Pine Ceramics')
    option('blogdescription', 'Small-batch stoneware')
    b = admin()
    connect(b)
    wp('invoiceninja', 'environment', 'confirm')
    b.api('POST', '/settings', {'modules': {'store': True, 'account': True, 'quote_form': True, 'subs': True, 'woo': False},
                                'store': {'mode': 'cart', 'require_login': False, 'delivery_address': True}})
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
    run_jobs('invoiceninja_sideload_gallery')  # the teapot's extra photos
    b.api('POST', '/store/display', {'action': 'blocks'})
    wp('rewrite', 'flush')
    b.api('POST', '/pages', {'pages': ['shop', 'checkout', 'status', 'account']})
    pages = json.loads(wp_eval("$o = InvoiceNinja\\Plugin::instance()->options; echo wp_json_encode($o->setting('pages'));"))
    wp('post', 'update', str(pages['account']), '--post_content=' + wide_title('Account') + '<!-- wp:invoiceninja/account {"align":"wide"} /-->')
    wp('post', 'meta', 'update', str(pages['account']), '_wp_page_template', 'page-no-title')
    option('in_mock_credit_balance', '15')
    option('in_showcase_header', wp('post', 'create', '--post_type=wp_template_part', '--post_status=publish', '--post_name=header',
                                    '--post_title=Header', '--post_content=' + HEADER, '--porcelain'))
    wp_eval("$id = (int) get_option('in_showcase_header'); wp_set_object_terms($id, get_stylesheet(), 'wp_theme'); wp_set_object_terms($id, 'header', 'wp_template_part_area');")
    wp('post', 'update', str(pages['shop']), '--post_title=Shop', '--post_content=<!-- wp:paragraph --><p>Small-batch stoneware, made and glazed in our studio.</p><!-- /wp:paragraph --><!-- wp:invoiceninja/products {"columns":4,"perPage":8,"align":"wide","descriptor":"custom_value1"} /-->')
    b.api('POST', '/admin/forms', {'forms': {'reply_days': 2, 'redirect_url': '', 'autoreply': False, 'autoreply_subject': '', 'tags': 'Quote request',
                                             'retention_days': 0, 'products': 'TEAPOT,DINNERPLATES,SERVINGBOWL'}, 'enabled': True, 'autoreply_text': ''})
    quote = page('Request a quote', '<!-- wp:paragraph --><p>Planning a café, a wedding or a gift order? Tell us what you need.</p><!-- /wp:paragraph --><!-- wp:invoiceninja/quote-form {"showQuantity":true,"showNeededBy":true} /-->', 'request-a-quote')
    b.api('POST', '/admin/subscriptions/sync')
    plans = page('Membership', wide_title('Membership', 'Join the studio: a new piece every season, and first pick of every kiln.') + '<!-- wp:invoiceninja/pricing-table {"ids":"SUBT,SUBM,SUBW","yearly":"SUBM:SUBY","highlight":"SUBM","align":"wide","planDetails":{"SUBT":{"description":"For collectors getting started.","perks":"Members-only kiln previews\\nEarly access to new glazes"},"SUBM":{"name":"Studio","description":"Our most-loved plan.","perks":"A new piece every season\\nFirst pick of every kiln\\nMembers-only kiln previews"},"SUBW":{"description":"For shops and cafés stocking our work.","perks":"Priority on custom orders\\nA named contact at the studio"}}} /-->', 'membership')
    # A tidy header menu (the theme's navigation uses the newest menu).
    link = lambda post_id: wp_eval("echo wp_make_link_relative(get_permalink(%s));" % post_id)
    links = ''.join('<!-- wp:navigation-link {"label":"%s","url":"%s","kind":"custom"} /-->' % (label, url) for label, url in (
        ('Shop', link(pages['shop'])), ('Request a quote', link(quote)), ('Membership', link(plans)), ('Account', link(pages['account']))))
    nav = wp('post', 'create', '--post_type=wp_navigation', '--post_status=publish', '--post_title=Showcase', '--post_content=' + links, '--porcelain')
    option('in_showcase_nav', nav)
    wp('post', 'meta', 'update', str(plans), '_wp_page_template', 'page-no-title')
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
    addresses = ['14 Rua Augusta', '3 Cedar Road', '88 Lake Street', '201 Hill Avenue', '9 Calle Mayor']
    for (email, first, last, items), street in zip(people, addresses):
        address = {'shipping_address1': street, 'shipping_city': 'Portland', 'shipping_state': 'OR',
                   'shipping_postal_code': '97201', 'shipping_country_id': '840'}
        s = Browser()
        for product, qty in items.items():
            s.api('POST', '/cart/items', {'product': product, 'quantity': qty})
        _, started = s.api('POST', '/checkout/start', {'email': email, 'first_name': first, 'last_name': last, **address})
        if not started.get('ok'):
            raise RuntimeError('checkout start failed for %s: %s' % (email, started))
        _, r = s.api('POST', '/checkout/confirm', {'email': email, 'first_name': first, 'last_name': last, **address, 'code': last_code(email)})
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
    wp('option', 'delete', 'in_mock_checkout_min_seconds', check_ok=False)
    wp('option', 'delete', 'in_mock_credit_balance', check_ok=False)
    wp_eval("$p = InvoiceNinja\\Plugin::instance()->options->setting('pages'); if (!empty($p['account'])) { delete_post_meta((int) $p['account'], '_wp_page_template'); }")
    wp_eval("InvoiceNinja\\Plugin::instance()->options->saveSettings(array('store' => array('delivery_address' => false)));")
    header = wp('option', 'get', 'in_showcase_header', check_ok=False)
    if header:
        wp('post', 'delete', header, '--force', check_ok=False)
        wp('option', 'delete', 'in_showcase_header', check_ok=False)
    wp('option', 'delete', 'in_mock_paid', check_ok=False)
    wp_eval("$u = get_user_by('login', 'member'); if ($u) { wp_update_user(array('ID' => $u->ID, 'user_email' => 'a+b@x.com', 'display_name' => 'Mem Ber', 'first_name' => 'Mem', 'last_name' => 'Ber')); foreach (array('_invoiceninja_client_id', '_invoiceninja_contact_id') as $k) { delete_user_meta($u->ID, $k); } }")
    option('blogname', 'Invoice Ninja Tests')
    hidden = json.loads(wp('option', 'get', 'in_showcase_hidden', check_ok=False) or '[]')
    wp_eval("foreach (json_decode('%s', true) as $id) { wp_update_post(array('ID' => $id, 'post_status' => 'publish')); }"
            "foreach (array('P1', 'P2') as $p) { $m = InvoiceNinja\\Plugin::instance()->mapping->find('product', $p); if ($m) { wp_update_post(array('ID' => $m['wp_id'], 'post_status' => 'publish')); } }"
            "foreach (array('SPECKLEDMUG','SERVINGBOWL','BUDVASE','DINNERPLATES','TEAPOT','PLANTER','POUROVER','BUTTERDISH') as $p) {"
            " $m = InvoiceNinja\\Plugin::instance()->mapping->find('product', $p); if ($m) { foreach ((array) get_post_meta($m['wp_id'], '_invoiceninja_gallery', true) as $ph) { if (is_array($ph) && !empty($ph['attachment'])) { wp_delete_attachment((int) $ph['attachment'], true); } } wp_delete_post($m['wp_id'], true); } }"
            "global $wpdb; $wpdb->query(\"DELETE FROM \" . InvoiceNinja\\Sync\\Mapping::table() . \" WHERE in_id IN ('SPECKLEDMUG','SERVINGBOWL','BUDVASE','DINNERPLATES','TEAPOT','PLANTER','POUROVER','BUTTERDISH')\");" % json.dumps(hidden))
    wp('option', 'delete', 'in_showcase_hidden', check_ok=False)
    nav = wp('option', 'get', 'in_showcase_nav', check_ok=False)
    if nav:
        wp('post', 'delete', nav, '--force', check_ok=False)
        wp('option', 'delete', 'in_showcase_nav', check_ok=False)
    b = admin()
    b.api('POST', '/store/display', {'action': 'legacy'})
    # Drop the showcase company (stock tracking on) from the cached connection state.
    wp_eval("InvoiceNinja\\Plugin::instance()->connection->refresh();")
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
