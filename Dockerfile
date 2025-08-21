ARG drupalversion=11.1.x-dev
ARG phpversion=8.3
ARG pgsqlversion=16
FROM knowpulse/tripalcultivate-base:drupal${drupalversion}-php${phpversion}-pgsql${pgsqlversion}

COPY . /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm
WORKDIR /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm

RUN service postgresql restart \
  && drush en trpcultivate_germplasm trpcultivate_germcollection --yes \
  && drush tripal:trp-run-jobs --username=drupaladmin \
  && drush cr
