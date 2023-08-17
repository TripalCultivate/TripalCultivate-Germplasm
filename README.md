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
PHPUnit for testing and CodeClimate to ensure good test coverage and maintainability.
There are more details on [our CodeClimate project page] describing our specific
maintainability issues and test coverage.

![MaintainabilityBadge]
![TestCoverageBadge]

The following compatibility is proven via automated testing workflows.

| Drupal | 9.3.x | 9.4.x | 9.5.x | 10.0.x |
|--------|-------|-------|-------|--------|
| **PHP 8.0** | ![Grid1A-Badge] | ![Grid1B-Badge] | ![Grid1C-Badge] |  |
| **PHP 8.1** | ![Grid2A-Badge] | ![Grid2B-Badge] | ![Grid2C-Badge] |  |

[our CodeClimate project page]: https://codeclimate.com/github/TripalCultivate/TripalCultivate-Germplasm
[MaintainabilityBadge]: https://api.codeclimate.com/v1/badges/0619dcf991bd5e5114fb/maintainability
[TestCoverageBadge]: https://api.codeclimate.com/v1/badges/0619dcf991bd5e5114fb/test_coverage

[Grid1A-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid1A.yml/badge.svg
[Grid1B-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid1B.yml/badge.svg
[Grid1C-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid1C.yml/badge.svg

[Grid2A-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid2A.yml/badge.svg
[Grid2B-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid2B.yml/badge.svg
[Grid2C-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid2C.yml/badge.svg
[Grid2D-Badge]: https://github.com/TripalCultivate/TripalCultivate-Germplasm/actions/workflows/MAIN-phpunit-Grid2D.yml/badge.svg
