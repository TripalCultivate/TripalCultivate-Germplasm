# Tripal Cultivate: Germplasm

**Developed by the University of Saskatchewan, Pulse Crop Bioinformatics team.**

**NOTE: This package will replace the following Tripal v3 module: [uofspb_germplasm](https://github.com/UofS-Pulse-Binfo/uofspb_germplasm/tree/7.x-3.x).**

<!-- Summarize the main features of this package in point form below. -->

- Germplasm importers
    - bulk import of germplasm crosses and accessions into the database
- Germplasm collections
    - supports groupings of germplasm into collections
    - a specialized field for a table listing of a germplasm collection
    - a field to list germplasm collection(s) on a project page
- RIL Summary
    - provides a tabular germplasm matrix summarizing the number of RILs available for each species used as a parent
    - A RIL listing for a specific species combination that includes the current progress of RIL development
    - A field for RIL germplasm pages to summarize development progression

## Citation

If you use this module in your Tripal site, please use this citation to reference our work any place where you described your resulting Tripal site. For example, if you publish your site in a journal then this citation should be in the reference section and anywhere functionality provided by this module is discussed in the above text should reference it.

> Lacey-Anne Sanderson, Carolyn T Caron and Reynold Tan (2023). TripalCultivate Germplasm: Specialized Tripal fields and importers for germplasm. Development Version. University of Saskatchewan, Pulse Crop Research Group, Saskatoon, SK, Canada.

## Install

Using composer, add this package to your Drupal site by using the following command in the root of your Drupal site:

```
composer require tripalcultivate/germplasm
```

This will download the most recent release in the modules directory. You can see more information in [the Drupal Docs](https://www.drupal.org/docs/develop/using-composer/manage-dependencies).

Then you can install it using Drush or the Extensions page on your Drupal site.

```
drush en trpcultivate_germplasm
```

## Technology Stack

*See specific version compatibility in the automated testing section below.*

- Drupal
- Tripal 4.x
- PostgreSQL
- PHP
- Apache2

### Automated Testing

This package is dedicated to a high standard of automated testing. We use
PHPUnit for testing and QLTY Cloud to ensure good test coverage and maintainability.
There are more details on [our QLTY Cloud project page] describing our specific
maintainability issues and test coverage.

[![Maintainability](https://qlty.sh/gh/TripalCultivate/projects/TripalCultivate/maintainability.svg)](https://qlty.sh/gh/TripalCultivate/projects/TripalCultivate-Germplasm)
[![Code Coverage](https://qlty.sh/gh/TripalCultivate/projects/TripalCultivate/coverage.svg)](https://qlty.sh/gh/TripalCultivate/projects/TripalCultivate-Germplasm)

The following compatibility is proven via automated testing workflows.

| PHP\Drupal | 10.5.x-dev          | 10.6.x-dev          | 11.2.x-dev          | 11.3.x-dev          |
|------------|---------------------|---------------------|---------------------|---------------------|
| **PHP8.2** | ![Grid82-105-Badge] | ![Grid82-106-Badge] |                     |                     |
| **PHP8.3** | ![Grid83-105-Badge] | ![Grid83-106-Badge] | ![Grid83-112-Badge] | ![Grid83-113-Badge] |
| **PHP8.4** | ![Grid84-105-Badge] | ![Grid84-106-Badge] | ![Grid84-112-Badge] | ![Grid84-113-Badge] |
| **PHP8.5** |                     |                     |                     | ![Grid85-113-Badge] |

[our QLTY Cloud project page]: https://qlty.sh/gh/TripalCultivate/projects/TripalCultivate-Germplasm

[Grid82-105-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.2_D10_5x.yml/badge.svg
[Grid82-106-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.2_D10_6x.yml/badge.svg
[Grid83-105-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.3_D10_5x.yml/badge.svg
[Grid83-106-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.3_D10_6x.yml/badge.svg
[Grid83-112-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.3_D11_2x.yml/badge.svg
[Grid83-113-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.3_D11_3x.yml/badge.svg
[Grid84-105-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.4_D10_5x.yml/badge.svg
[Grid84-106-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.4_D10_6x.yml/badge.svg
[Grid84-112-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.4_D11_2x.yml/badge.svg
[Grid84-113-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.4_D11_3x.yml/badge.svg
[Grid85-113-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-php8.5_D11_3x.yml/badge.svg
