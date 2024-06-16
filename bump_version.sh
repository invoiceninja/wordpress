#!/bin/bash

current_version=$(grep "0.1." invoiceninja.php | cut -f3 -d "." )
new_version=$((current_version+1))
date_today=$(date +%F)

echo "Bump version... $current_version => $new_version"

sed -i -e "s/* Version:            0.1.$current_version/* Version:            0.1.$new_version/g" ./invoiceninja.php
sed -i -e "s/automatic_release_tag: \"v0.1.$current_version/automatic_release_tag: \"v0.1.$new_version/g" .github/workflows/release.yml