#!/bin/bash

current_version=$(grep "0.1." invoiceninja.php | cut -f3 -d "." )
new_version=$((current_version+1))
date_today=$(date +%F)

echo "Bump version... $current_version => $new_version"

sed -i -e "s/* Version:            0.1.$current_version/* Version:            0.1.$new_version/g" ./invoiceninja.php
sed -i -e "s/automatic_release_tag: \"v0.1.$current_version/automatic_release_tag: \"v0.1.$new_version/g" .github/workflows/release.yml

#sed -i -e "s/version: 5.0.$current_version+$current_version/version: 5.0.$new_vesion+$new_vesion/g" ./pubspec.yaml
#sed -i -e "s/version: 5.0.$current_version+$current_version/version: 5.0.$new_vesion+$new_vesion/g" ./pubspec.foss.yaml
#sed -i -e "s/v5.0.$current_version/v5.0.$new_vesion/g" ./.github/workflows/flatpak.yml
#sed -i -e 's/<releases>/<releases>\n    <release version="5.0.'$new_vesion'" date="'$date_today'"\/>/g' ./flatpak/com.invoiceninja.InvoiceNinja.metainfo.xml
#sed -i -e "s/kClientVersion = '5.0.$current_version'/kClientVersion = '5.0.$new_vesion'/g" ./lib/constants.dart
#sed -i -e "s/version: '5.0.$current_version'/version: '5.0.$new_vesion'/g" ./snap/snapcraft.yaml

#rm lib/flutter_version.dart
#echo "const FLUTTER_VERSION = const <String, String>" > lib/flutter_version.dart
#flutter --version --machine >> lib/flutter_version.dart
#echo ";" >> lib/flutter_version.dart
#sed -i "y/\"/'/" lib/flutter_version.dart
#dart dartfmt lib