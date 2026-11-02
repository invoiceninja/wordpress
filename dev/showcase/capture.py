"""Take the README screenshots (runs inside the Playwright image; see screenshots.py)."""
import json
import sys
import urllib.request

from playwright.sync_api import sync_playwright, expect

BASE = 'http://localhost:8089'
urls = json.loads(sys.argv[1])
SCALE = 1.5
HIDE_ADMIN_BAR = '#wpadminbar{display:none!important} html{margin-top:0!important}'


def shot(page, name, full=False, selector=None):
    # Lazy images below the fold: load them all before the picture.
    page.evaluate("document.querySelectorAll('img[loading=lazy]').forEach(function (i) { i.loading = 'eager'; })")
    # Scroll through so every image is requested, then wait until each has pixels.
    page.evaluate("(async () => { for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 60)); } window.scrollTo(0, 0); })()")
    page.wait_for_function("Array.from(document.images).every(function (i) { return i.complete && i.naturalWidth > 0; })", timeout=15000)
    page.evaluate("Promise.all(Array.from(document.images).map(function (i) { i.decoding = 'sync'; return i.decode().catch(function () {}); }))")
    if full:
        # Chromium paints off-screen images late in stitched full-page shots: make the viewport the whole page.
        size = page.viewport_size
        page.set_viewport_size({'width': size['width'], 'height': page.evaluate('document.documentElement.scrollHeight')})
        page.wait_for_timeout(600)
    page.wait_for_timeout(400)
    if selector:
        page.locator(selector).first.screenshot(path='/out/%s.png' % name)
    else:
        page.screenshot(path='/out/%s.png' % name, full_page=full)
    if full:
        page.set_viewport_size(size)
    print('saved', name)


def login(page, user, password):
    page.goto(BASE + '/wp-login.php')
    page.fill('#user_login', user)
    page.fill('#user_pass', password)
    page.click('#wp-submit')
    page.wait_for_load_state('networkidle')


with sync_playwright() as p:
    browser = p.chromium.launch()

    # ---------- Shopper ----------
    ctx = browser.new_context(viewport={'width': 1280, 'height': 860}, device_scale_factor=SCALE)
    page = ctx.new_page()
    page.goto(BASE + urls['shop'])
    page.wait_for_selector('.invoiceninja-products__grid')
    shot(page, 'shop', full=True)

    page.goto(BASE + urls['product'])
    page.locator('.invoiceninja-add button').first.click()
    expect(page.locator('.invoiceninja-toast').first).to_contain_text('added to cart')
    shot(page, 'product')

    page.goto(BASE + urls['shop'])
    page.locator('[data-invoiceninja-add="SPECKLEDMUG"] button').first.click()
    page.wait_for_selector('.invoiceninja-toast')
    page.goto(BASE + urls['checkout'])
    expect(page.get_by_role('heading', name='Your cart')).to_be_visible()
    page.get_by_role('button', name='One more Speckled mug').click()
    page.wait_for_timeout(600)
    shot(page, 'checkout-cart')

    page.get_by_role('button', name='Checkout', exact=True).click()
    email = 'jane.cooper@example.com'
    page.get_by_label('Email').fill(email)
    page.get_by_label('First name').fill('Jane')
    page.get_by_label('Last name').fill('Cooper')
    shot(page, 'checkout-details')

    page.get_by_role('button', name='Continue').click()
    expect(page.get_by_role('heading', name='Check your email')).to_be_visible()
    shot(page, 'checkout-code')

    code = json.load(urllib.request.urlopen(BASE + '/wp-json/in-mock/v1/code?email=' + email))['code']
    page.get_by_label('6-digit code').fill(code)
    expect(page.get_by_role('heading', name='Thanks. Your order is ready to pay.')).to_be_visible(timeout=20000)
    shot(page, 'checkout-pay')

    page.get_by_role('link', name='View your order').click()
    page.wait_for_selector('.invoiceninja-pill')
    shot(page, 'order-status')

    page.goto(BASE + urls['quote'])
    page.wait_for_load_state('networkidle')
    shot(page, 'quote-form', full=True)

    page.goto(BASE + urls['plans'])
    page.wait_for_load_state('networkidle')
    shot(page, 'pricing-table', full=True)
    ctx.close()

    # ---------- Phone ----------
    ctx = browser.new_context(viewport={'width': 390, 'height': 844}, device_scale_factor=2, is_mobile=True, has_touch=True)
    page = ctx.new_page()
    page.goto(BASE + urls['shop'])
    page.locator('[data-invoiceninja-add="TEAPOT"] button').first.click()
    page.wait_for_selector('.invoiceninja-toast')
    page.goto(BASE + urls['checkout'])
    expect(page.get_by_role('heading', name='Your cart')).to_be_visible()
    shot(page, 'mobile-checkout')
    ctx.close()

    # ---------- Customer account ----------
    ctx = browser.new_context(viewport={'width': 1280, 'height': 860}, device_scale_factor=SCALE)
    page = ctx.new_page()
    login(page, 'member', 'pass')
    page.goto(BASE + urls['account'])
    page.add_style_tag(content=HIDE_ADMIN_BAR)
    page.wait_for_selector('.invoiceninja-table', timeout=20000)
    shot(page, 'account')
    ctx.close()

    # ---------- Admin ----------
    ctx = browser.new_context(viewport={'width': 1440, 'height': 900}, device_scale_factor=SCALE)
    page = ctx.new_page()
    login(page, 'admin', 'admin')
    for name, path, wait in (
        ('admin-home', '/wp-admin/admin.php?page=invoiceninja', '.in-stats'),
        ('admin-orders', '/wp-admin/admin.php?page=invoiceninja-orders', '.in-orders tbody tr'),
        ('admin-status', '/wp-admin/admin.php?page=invoiceninja-status', '.in-checks li'),
        ('admin-store-settings', '/wp-admin/admin.php?page=invoiceninja-settings&tab=store', 'input[name="in-mode"]'),
        ('admin-quotes', '/wp-admin/admin.php?page=invoiceninja-quotes', 'table'),
        ('admin-welcome', '/wp-admin/admin.php?page=invoiceninja-welcome', '.in-tools'),
        ('admin-setup', '/wp-admin/admin.php?page=invoiceninja-setup', '.in-stepper'),
    ):
        page.goto(BASE + path)
        try:
            page.wait_for_selector(wait, timeout=20000)
        except Exception as e:  # noqa: BLE001
            print('wait failed', name, e)
        page.wait_for_timeout(800)
        shot(page, name)

    page.goto(BASE + urls['editor'])
    try:
        page.wait_for_selector('iframe[name="editor-canvas"]', timeout=30000)
        canvas = page.frame_locator('iframe[name="editor-canvas"]')
        canvas.locator('.invoiceninja-card').first.wait_for(timeout=30000)
        # Select the Products block so the sidebar shows its settings.
        page.evaluate("(function () { var b = wp.data.select('core/block-editor').getBlocks().find(function (x) { return x.name === 'invoiceninja/products'; }); if (b) { wp.data.dispatch('core/block-editor').selectBlock(b.clientId); } })()")
        page.wait_for_timeout(2500)
    except Exception as e:  # noqa: BLE001
        print('editor wait failed', e)
    shot(page, 'editor')
    browser.close()
print('capture ok')
