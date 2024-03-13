ARG drupalversion='10.2.x-dev'
ARG phpversion='8.3'
ARG pgsqlversion='13'
FROM knowpulse/tripalcultivate:baseonly-drupal${drupalversion}-php${phpversion}-pgsql${pgsqlversion}

COPY . /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm
WORKDIR /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm

RUN service postgresql restart \
  && drush en trpcultivate_germplasm trpcultivate_germcollection --yes \
  && drush cr
