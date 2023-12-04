ARG drupalversion='10.1.x-dev'
FROM tripalproject/tripaldocker:drupal${drupalversion}-php8.1-pgsql13-noChado

ARG chadoschema='testchado'
COPY . /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm

WORKDIR /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm

RUN service postgresql restart \
  && drush trp-install-chado --schema-name=${chadoschema} \
  && drush trp-prep-chado --schema-name=${chadoschema} \
  && drush tripal:trp-import-types --username=drupaladmin --collection_id=general_chado \
  && drush tripal:trp-import-types --username=drupaladmin --collection_id=germplasm_chado \
  && drush en trpcultivate_germplasm trpcultivate_germcollection --yes
