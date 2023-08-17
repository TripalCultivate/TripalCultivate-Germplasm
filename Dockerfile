ARG drupalversion='10.0.x-dev'
FROM tripalproject/tripaldocker:drupal${drupalversion}-php8.1-pgsql13-noChado

ARG chadoschema='testchado'
COPY . /var/www/drupal9/web/modules/contrib/TripalCultivate-Germplasm

WORKDIR /var/www/drupal9/web/modules/contrib/TripalCultivate-Germplasm

RUN service postgresql restart \
  && drush trp-install-chado --schema-name=${chadoschema} \
  && drush trp-prep-chado --schema-name=${chadoschema} \
  && drush en trpcultivate_germplasm trpcultivate_germcollection --yes
