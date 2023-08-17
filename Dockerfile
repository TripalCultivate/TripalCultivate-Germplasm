FROM tripalproject/tripaldocker:latest

COPY . /var/www/drupal9/web/modules/contrib/TripalCultivate-Germplasm

WORKDIR /var/www/drupal9/web/modules/contrib/TripalCultivate-Germplasm

## RUN service postgresql restart \
##  && drush en trpgeno_genetics trpgeno_genotypes trpgeno_genomatrix trpgeno_qtl trpgeno_vcf --yes
