ARG drupalversion=11.3.x-dev
ARG phpversion=8.5
ARG postgresqlversion=18
ARG buildplatform='linux/amd64'
FROM --platform=${buildplatform} knowpulse/tripalcultivate-base:drupal${drupalversion}-php${phpversion}-pgsql${postgresqlversion}

COPY . /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm
WORKDIR /var/www/drupal/web/modules/contrib/TripalCultivate-Germplasm

RUN rm ./phpunit.xml
RUN bash /var/www/drupal/web/modules/contrib/tripal/set_phpunit_config.sh

RUN service postgresql restart \
  && drush en trpcultivate_germplasm trpcultivate_germcollection --yes \
  && drush tripal:trp-run-jobs --username=drupaladmin \
  && drush cr
