"""Helpers for the end-to-end flow tests (Python stdlib only).

The tests drive a real WordPress (dev/docker-compose.yml) over HTTP and WP-CLI,
with the Invoice Ninja API faked by dev/mu-plugins/invoiceninja-mock.php.
"""

import http.cookiejar
import json
import os
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
COMPOSE = ['docker', 'compose', '-f', os.path.join(ROOT, 'dev', 'docker-compose.yml')]
BASE = os.environ.get('WP_URL', 'http://localhost:' + os.environ.get('WP_PORT', '8089'))
CONTENT = '/var/www/html/wp-content'
MOCK_LOG = CONTENT + '/in-mock-requests.log'
DEBUG_LOG = CONTENT + '/debug.log'
IN_BASE = 'https://in.test/api/v1/'


class Failure(AssertionError):
    pass


def check(condition, message, detail=''):
    if not condition:
        raise Failure(message + (('\n      ' + str(detail).strip().replace('\n', '\n      ')) if detail else ''))


# ---------- docker / wp-cli ----------

def wp(*args, check_ok=True):
    """Run a WP-CLI command in the cli container and return stdout."""
    result = subprocess.run(COMPOSE + ['run', '--rm', '-T', 'cli'] + list(args),
                            capture_output=True, text=True, cwd=ROOT)
    if check_ok and result.returncode != 0:
        raise Failure('wp ' + ' '.join(args) + ' failed', result.stderr[-2000:] + result.stdout[-2000:])
    return result.stdout.strip()


def wp_ok(*args):
    """True if the WP-CLI command exits successfully."""
    return subprocess.run(COMPOSE + ['run', '--rm', '-T', 'cli'] + list(args),
                          capture_output=True, text=True, cwd=ROOT).returncode == 0


def wp_eval(php):
    return wp('eval', php)


def in_container(cmd):
    result = subprocess.run(COMPOSE + ['exec', '-T', 'wordpress', 'sh', '-c', cmd],
                            capture_output=True, text=True, cwd=ROOT)
    return result.stdout


def option(name, value):
    wp('option', 'update', name, str(value))


def reset_mock_log():
    in_container(': > %s && chmod 666 %s' % (MOCK_LOG, MOCK_LOG))


def mock_log():
    entries = []
    for line in in_container('cat %s 2>/dev/null' % MOCK_LOG).splitlines():
        line = line.strip()
        if line:
            entries.append(json.loads(line))
    return entries


def api_calls():
    """Requests the plugin sent to the (mocked) Invoice Ninja API."""
    return [e for e in mock_log() if e.get('event') == 'http' and e['url'].startswith(IN_BASE)]


def describe(calls):
    return '\n'.join('%s %s %s' % (c['method'], c['url'], c.get('body') or '') for c in calls) or '(no API calls)'


def debug_log():
    return in_container('cat %s 2>/dev/null' % DEBUG_LOG)


def clear_debug_log():
    in_container('rm -f %s' % DEBUG_LOG)


# ---------- http ----------

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Browser:
    """A cookie-keeping HTTP client that does not follow redirects."""

    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), _NoRedirect())

    def request(self, path, data=None, headers=None):
        body = urllib.parse.urlencode(data).encode() if data is not None else None
        req = urllib.request.Request(BASE + path, data=body, headers=headers or {})
        try:
            resp = self.opener.open(req, timeout=60)
            return resp.status, resp.headers.get('Location'), resp.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get('Location'), e.read().decode('utf-8', 'replace')

    def get(self, path):
        return self.request(path)

    def post(self, path, data):
        return self.request(path, data)

    def login(self, user, password):
        self.get('/wp-login.php')
        status, location, body = self.request(
            '/wp-login.php',
            {'log': user, 'pwd': password, 'wp-submit': 'Log In', 'testcookie': '1'},
            {'Cookie': 'wordpress_test_cookie=WP%20Cookie%20check'},
        )
        check(status == 302, 'login as %s failed' % user, '%s %s' % (status, location))
        return self


def wait_for_http(timeout=180):
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            Browser().get('/wp-login.php')
            return
        except Exception:
            time.sleep(2)
    raise Failure('WordPress did not respond at ' + BASE)


# ---------- html helpers ----------

def forms(html):
    return re.findall(r'<form method="post".*?</form>', html, re.S)


def form_nonce(html, marker):
    """Nonce of the first form containing marker."""
    for form in forms(html):
        if marker in form:
            match = re.search(r'name="invoiceninja_nonce" value="([^"]+)"', form)
            if match:
                return match.group(1)
    raise Failure('no form containing %r found' % marker, html[:1500])


def cart_nonce(html):
    match = re.search(r'id="invoiceninja_cart">.*?name="invoiceninja_nonce" value="([^"]+)"', html, re.S)
    check(match, 'cart form not found', html[:1500])
    return match.group(1)


def selected_quantities(html):
    return re.findall(r'<option value="(\d+)"SELECTED', html)
