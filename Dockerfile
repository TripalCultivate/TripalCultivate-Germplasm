FROM tripalproject/tripaldocker:latest
MAINTAINER Lacey-Anne Sanderson <lacey.sanderson@usask.ca>

COPY . /var/www/drupal9/web/modules/template

WORKDIR /var/www/drupal9/web/modules/template

## RUN service postgresql restart \
##  && drush en trpgeno_genetics trpgeno_genotypes trpgeno_genomatrix trpgeno_qtl trpgeno_vcf --yes
