#!/bin/bash
# Usage: ./bump_version.sh 2.0.0-alpha.2
# Updates the plugin header, the INVOICENINJA_VERSION constant and the readme's Stable tag
# (Stable tag only for final versions, so pre-releases never become the WordPress.org default).
set -euo pipefail

new="${1:-}"
if ! [[ "$new" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$ ]]; then
  echo "Usage: $0 <version>   e.g. $0 2.0.0-alpha.2" >&2
  exit 1
fi

old=$(sed -n 's/^ \* Version: *//p' invoiceninja.php | tr -d '[:space:]')
echo "Bump version: $old => $new"

sed -i.bak -E "s/^( \* Version: +).*/\1$new/" invoiceninja.php
sed -i.bak -E "s/(define\( 'INVOICENINJA_VERSION', ')[^']*(' \);)/\1$new\2/" invoiceninja.php
rm -f invoiceninja.php.bak

if [[ "$new" != *-* ]]; then
  sed -i.bak -E "s/^Stable tag: .*/Stable tag: $new/" readme.txt && rm -f readme.txt.bak
fi

grep -n "Version:\|INVOICENINJA_VERSION', '" invoiceninja.php
grep -n "Stable tag" readme.txt
echo "Now commit, then tag: git tag v$new && git push origin v$new"
