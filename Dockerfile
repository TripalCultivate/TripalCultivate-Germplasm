FROM tripalproject/tripaldocker:latest
MAINTAINER Lacey-Anne Sanderson <lacey.sanderson@usask.ca>

COPY . /var/www/drupal9/web/modules/TripalCultivate-Germplasm

WORKDIR /var/www/drupal9/web/modules/TripalCultivate-Germplasm

## RUN service postgresql restart \
##  && drush en trpgeno_genetics trpgeno_genotypes trpgeno_genomatrix trpgeno_qtl trpgeno_vcf --yes
