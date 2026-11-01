#!/usr/bin/env python3
"""Runs the end-to-end flow tests against the Docker stack in dev/docker-compose.yml.

    docker compose -f dev/docker-compose.yml up -d
    python3 tests/flows/run.py            # all tests
    python3 tests/flows/run.py cart       # only tests whose name contains "cart"

Exit code is non-zero if any test fails or the plugin wrote PHP warnings/notices.
"""

import os
import sys
import time
import traceback

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import lib  # noqa: E402
import test_v1
import test_v2  # noqa: E402

PLUGIN_PATH_MARKER = '/plugins/invoiceninja/'


def setup():
    lib.wait_for_http()
    if not lib.wp_ok('core', 'is-installed'):
        lib.wp('core', 'install', '--url=' + lib.BASE, '--title=Invoice Ninja Tests', '--admin_user=admin',
               '--admin_password=admin', '--admin_email=admin@example.com', '--skip-email')
    wp_version = os.environ.get('WP_VERSION')
    if wp_version:
        lib.wp('core', 'update', '--version=' + wp_version, '--force')
        lib.wp('core', 'update-db')
        # WordPress 6.8+ hashes passwords with bcrypt, which older versions can't verify
        lib.wp('user', 'update', 'admin', '--user_pass=admin', '--skip-email')
    lib.wp('rewrite', 'structure', '/%postname%/')
    lib.wp('plugin', 'activate', 'invoiceninja')
    # localhost looks like a copy, so orders are paused until confirmed
    lib.wp('invoiceninja', 'environment', 'confirm')

    for name, value in {
        'invoiceninja_api_url': 'https://in.test',
        'invoiceninja_api_token': 'goodtoken1234567890',
        'invoiceninja_online_purchases': 'multiple',
        'invoiceninja_sync_products': '1',
        'in_mock_mode': 'up',
        'in_mock_track_inventory': '0',
    }.items():
        lib.option(name, value)

    for user, email, first, last in (('member', 'a+b@x.com', 'Mem', 'Ber'), ('newbie', 'newbie@x.com', 'New', 'Bie')):
        if lib.wp('user', 'get', user, '--field=ID', check_ok=False) == '':
            lib.wp('user', 'create', user, email, '--user_pass=pass', '--first_name=' + first, '--last_name=' + last)

    for slug, content in (
        ('shop', '[invoiceninja_purchase product_id="P1"] [invoiceninja_client_portal label="Portal" sso="true"]'),
        ('stock', '[invoiceninja_purchase product_id="P2"]'),
    ):
        if lib.wp('post', 'list', '--post_type=page', '--name=' + slug, '--field=ID') == '':
            lib.wp('post', 'create', '--post_type=page', '--post_status=publish', '--post_title=' + slug.title(),
                   '--post_name=' + slug, '--post_content=' + content)

    lib.wp_eval('InvoiceNinja\\Controllers\\ProductController::loadProducts();')
    lib.clear_debug_log()


def plugin_warnings():
    lines = [line for line in lib.debug_log().splitlines()
             if PLUGIN_PATH_MARKER in line or 'PHP Fatal' in line]
    return lines


def main():
    pattern = sys.argv[1] if len(sys.argv) > 1 else ''
    print('Setting up WordPress at %s ...' % lib.BASE, flush=True)
    setup()

    tests = [t for t in test_v1.TESTS + test_v2.TESTS if pattern in t.__name__]
    failures = []
    started = time.time()
    for fn in tests:
        t0 = time.time()
        try:
            fn()
            print('  PASS  %s (%.1fs)' % (fn.__name__, time.time() - t0), flush=True)
        except lib.Failure as e:
            failures.append(fn.__name__)
            print('  FAIL  %s\n      %s' % (fn.__name__, e), flush=True)
        except Exception:
            failures.append(fn.__name__)
            print('  ERROR %s\n%s' % (fn.__name__, traceback.format_exc()), flush=True)

    warnings = plugin_warnings()
    if warnings:
        failures.append('php-warnings')
        print('  FAIL  plugin wrote PHP warnings/notices:\n      ' + '\n      '.join(warnings[:30]))
    else:
        print('  PASS  no PHP warnings/notices from the plugin')

    print('\n%d tests, %d failed (%.0fs)' % (len(tests) + 1, len(failures), time.time() - started))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
