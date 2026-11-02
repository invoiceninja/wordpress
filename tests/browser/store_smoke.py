"""Browser smoke test of the store front end (store.js), run inside the Playwright image.

Usage (from tests/flows): BROWSER=1 python3 run.py browser
Inside the container: python store_smoke.py <base> <shop path> <checkout path>
"""
import json
import sys
import urllib.request

from playwright.sync_api import sync_playwright, expect

base, shop, checkout_path = sys.argv[1], sys.argv[2], sys.argv[3]
email = 'browser.buyer@example.com'
errors = []

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page()
    # A wrong order link answers 404 by design; the browser logs that as a failed resource.
    page.on('console', lambda m: errors.append(m.text) if m.type == 'error' and 'Failed to load resource' not in m.text else None)
    page.on('pageerror', lambda e: errors.append(str(e)))

    page.goto(base + shop)
    page.locator('[data-invoiceninja-add="P1"] button').first.click()
    expect(page.locator('.invoiceninja-toast').first).to_contain_text('added to cart')

    page.goto(base + checkout_path)
    expect(page.get_by_role('heading', name='Your cart')).to_be_visible()
    page.get_by_role('button', name='One more Widget').click()
    expect(page.locator('.invoiceninja-stepper__value').first).to_contain_text('2')
    page.get_by_role('button', name='Checkout', exact=True).click()
    page.get_by_label('Email').fill(email)
    page.get_by_label('First name').fill('Bea')
    page.get_by_label('Last name').fill('Browser')
    page.get_by_role('button', name='Continue').click()
    expect(page.get_by_role('heading', name='Check your email')).to_be_visible()

    code = json.load(urllib.request.urlopen(base + '/wp-json/in-mock/v1/code?email=' + email))['code']
    assert code, 'no code emailed'
    page.get_by_label('6-digit code').fill(code)  # submits by itself at 6 digits
    expect(page.get_by_role('heading', name='Thanks. Your order is ready to pay.')).to_be_visible(timeout=20000)
    pay = page.locator('a.invoiceninja-pay').first
    assert 'in.test/client/invoice/' in pay.get_attribute('href'), pay.get_attribute('href')

    page.get_by_role('link', name='View your order').click()
    expect(page.locator('.invoiceninja-pill').first).to_have_text('Waiting for payment')

    # Back on the checkout with an empty cart: the unpaid order is offered
    page.goto(base + checkout_path)
    expect(page.get_by_role('heading', name='is waiting for payment')).to_be_visible()

    # Find my order with a bad link
    page.goto(base + '/order-status/?order=WEB-XX-1#key=' + '0' * 32)
    expect(page.get_by_role('heading', name='Find my order')).to_be_visible()

    browser.close()

assert not errors, errors
print('browser smoke ok')
