#!/usr/bin/env python3
"""Checks the dev stack with a real Invoice Ninja (profile "in"):
the same URLs work from this machine, from WordPress and from Invoice Ninja, the API
works, and Invoice Ninja can render a PDF. Python stdlib only.

    docker compose -f dev/docker-compose.yml --profile in up -d --wait
    python3 dev/in/smoke.py
"""
import json
import os
import subprocess
import sys
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
WP = 'http://wp.localhost:8089'
IN = 'http://in.localhost:8089'
EMAIL = 'admin@example.com'
PASSWORD = 'Dev-Only-Pass-123!'
HEADERS = {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}

failures = 0


def report(name, ok, detail=''):
    global failures
    failures += 0 if ok else 1
    print('  %s  %s %s' % ('PASS' if ok else 'FAIL', name, detail), flush=True)


def fetch_inside(service, url):
    code = '$c=curl_init(%s); curl_setopt($c, CURLOPT_RETURNTRANSFER, 1); curl_setopt($c, CURLOPT_TIMEOUT, 10);' \
           ' echo curl_exec($c) ?: "ERROR " . curl_error($c);' % json.dumps(url)
    out = subprocess.run(['docker', 'compose', '-f', os.path.join(ROOT, 'dev', 'docker-compose.yml'),
                          'exec', '-T', service, 'php', '-r', code], capture_output=True, text=True)
    return out.stdout.strip() or out.stderr.strip()


def api(method, path, body=None, token=None, raw=False):
    headers = dict(HEADERS)
    if token:
        headers['X-API-TOKEN'] = token
    req = urllib.request.Request(IN + '/api/v1/' + path, method=method, headers=headers,
                                 data=json.dumps(body).encode() if body is not None else None)
    with urllib.request.urlopen(req, timeout=120) as resp:
        data = resp.read()
        return (data if raw else json.loads(data)), resp.headers


def main():
    healthy = '"status":"ok"'
    report('this machine -> Invoice Ninja', healthy in urllib.request.urlopen(IN + '/health', timeout=10).read().decode())
    report('this machine -> WordPress', urllib.request.urlopen(WP + '/proxy-health', timeout=10).status == 200)
    report('WordPress container -> Invoice Ninja', healthy in fetch_inside('wordpress', IN + '/health'))
    report('Invoice Ninja container -> WordPress', fetch_inside('in-app', WP + '/proxy-health') == 'ok')

    login, headers = api('POST', 'login', {'email': EMAIL, 'password': PASSWORD})
    token = login['data'][0]['token']['token']
    report('API login', bool(token), 'Invoice Ninja %s' % headers.get('X-APP-VERSION'))

    client, _ = api('POST', 'clients', {'name': 'Smoke Test', 'contacts': [{'email': 'smoke@example.com'}]}, token)
    invoice, _ = api('POST', 'invoices?mark_sent=true', {
        'client_id': client['data']['id'],
        'line_items': [{'product_key': 'Smoke', 'notes': 'Smoke test', 'cost': 10, 'quantity': 1}],
    }, token)
    link = invoice['data']['invitations'][0]['link']
    report('portal links use the shared hostname', link.startswith(IN + '/'), link.split('?')[0])

    pdf, _ = api('GET', 'invoices/%s/download' % invoice['data']['id'], token=token, raw=True)
    report('PDF rendering (Chromium)', pdf[:5] == b'%PDF-', '%d bytes' % len(pdf))

    api('POST', 'invoices/bulk', {'action': 'delete', 'ids': [invoice['data']['id']]}, token)
    api('POST', 'clients/bulk', {'action': 'delete', 'ids': [client['data']['id']]}, token)

    print('\n%s' % ('All checks passed' if not failures else '%d check(s) failed' % failures))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
