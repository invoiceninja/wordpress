#!/bin/bash

current_version=$(grep "1.0." invoiceninja.php | cut -f3 -d "." )
new_version=$((current_version+1))
date_today=$(date +%F)

echo "Bump version... $current_version => $new_version"

sed -i -e "s/* Version:            1.0.$current_version/* Version:            1.0.$new_version/g" ./invoiceninja.php
sed -i -e "s/automatic_release_tag: \"v1.0.$current_version/automatic_release_tag: \"v1.0.$new_version/g" .github/workflows/release.yml