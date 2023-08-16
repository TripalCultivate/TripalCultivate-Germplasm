# Template Instructions

This document describes the changes you should make when using this template to customize it for your specific module.

## Create a new repo using this template

Create a new repository using this one as the template by clicking the green "Use this template" button at the top of this repository and then selecting "Create a new repository" frorm the drop-down. Fill in the details ensuring it is in the correct organization and then click "create repository from template". This will give you a copy of this repository with the basic details you provided.

Repository Name: TripalCultivate-DATATYPE (where DATATYPE is replaced with a single word describing the type of data this module handles)

![use-this-template](https://user-images.githubusercontent.com/1566301/225162517-484b597f-7f2b-4f26-9c06-45ea4e9d4034.png)

## 1. Clone locally and create a branch to Customize

```
git clone https://github.com/TripalCultivate/TripalCultivate-DATATYPE.git
cd TripalCultivate-DATATYPE
git checkout -b customize-template
git rm TEMPLATE-INSTRUCTIONS.md
```

**NOTE: At the end of this set of instructions we will create a PR and squash all the following commits into a single commit. As such, I suggest committing often but there is no need to worry about the commit messages :-)**

## 2. Change all the placeholder text in the template

### README.md

I find starting with the Lorem ipsum text difficult so I replace things in this order:

- The title.

- The list of Tripal v3 modules.

    At the top of the file there is a bolded NOTE. Replace the text saying `ADD LINKS TO MODULES THIS WILL REPLACE` with a comma-separated list of links this module will replace. For example, `[Raw Phenotypes](https://github.com/UofS-Pulse-Binfo/rawphenotypes), [AnalyzedPhenotypes](https://github.com/uofs-pulse-binfo/analyzedphenotypes/)`.
 
- The text in the citation. 

    Make sure to put in the author list to the best of your knowledge and put in the module name with a tagline. For example `TripalCultivate Phenotypes: Large-scale trait and phenotypic data integration for Tripal`. Author names should be full first and last name; if you include a middle initial then do not use a period after it.
 
- The composer command.

    This should be `composer require tripalculativate/datatype` where datatype is the lowercase version of your data type. For example, `composer require tripalcultivate/phenotypes`.
    
- The Drush command.

    This should be a list of the machine name of the modules contained in this package. If you do not know this yet then just include the base api module: `drush en trpcultivate_datatype` where datatype is the lowercase version of your datatype. For example, `drush en trpcultivate_phenotypes`.

- The Technology Stack.

    This is essentially the dependencies so if your package depends on any other Tripal or Drupal modules, you should add them here as new bullets under Tripal 4.x.

- Links at the bottom.

    For all these links, just replace `Template` with the name of your repository. Ignore the maintainability and test coverage badges for now.

- Lorem ipsum package description text.

    This should be a small number of points describing the main features of the whole package. This will be very different for each package but a couple of things you may want to include: content type pages it focuses on, fields it provides, any tools or visualizations, a note about data import. See existing repository READMEs for examples.

### phpunit.xml

Change the word `modulename` with the main api module for your package. For example, `trpcultivate_phenotypes`.

### Dockerfile

Change the word `template` to the name of your repository. For example, `TripalCultivate-Phenotypes`.

Change the list of modules to match yours. In the beginning this will likely just be the main apit module for your package. For example, `trpcultivate_phenotypes`.

### GitHub Workflows

In each workflow file at `.github/workflows/` change the package name and modules list to match that in your dockerfile.

In the phenotypes example we've been using that means replace `template` with `TripalCultivate-Phenotypes` and `modulename` with `trpcultivate_phenotypes`.

### modulename directory and containing files.

Change the name of the `modulename` directory, the modulename.info.yml and the modulename.module files to match your main api module (e.g. `trpcultivate_phenotypes`.

```
git mv modulename trpcultivate_phenotypes
git mv trpcultivate_phenotypes/modulename.info.yml trpcultivate_phenotypes/trpcultivate_phenotypes.info.yml
git mv trpcultivate_phenotypes/modulename.module trpcultivate_phenotypes/trpcultivate_phenotypes.module
```

Now open the `.info.yml` file and update all the information to match your module. For example,

```yml
name: Phenotypic Data API
type: module
description: Provides services and plugins to support common functionality within this package.
package: "TripalCultivate: Phenotypes"
core_version_requirement: ^9 || ^10
dependencies:
  - tripal
  - tripal_chado
```

Open the `.module` file and replace all occurances of `modulename` with your api module name (e.g. `trpcultivate_phenotypes`). Update the information in hook_help to match the content you included in your README -specifically that replacing the lorem ipsum.

Finally open the `tests/src/Functional/InstallTest.php` and change all instances of `modulename` to match your api module name (e.g. `trpcultivate_phenotypes`). Replace the value of `$module_name` with the name used in your `.info.yml` file (e.g. `Phenotypic Data API`). Change the `$help_text_excerpt` to include a part of your text in the `.module` `hook_help` implementation.

**NOTE: This is a good place to commit and push. At this point the automated testing should pass. The code coverage will fail though.**

## 3. Create a composer.json file

This file is not in the template on purpose. Create a file called `composer.json` and use the following as a template. Make sure to only fill in details correct for this new package. The format and name are extremely important so be careful.

```yml
{
  "name": "tripalcultivate/phenotypes",
  "type": "drupal-module",
  "description": "A Tripal extension module that provides generic support for large-scale phenotypic data and traits with importers, content pages and visualizations.",
  "keywords": ["tripal", "drupal", "biological-data", "phenotypic-data", "visualization", "data-import"],
  "homepage": "https://github.com/TripalCultivate/TripalCultivate-Phenotypes",
  "support": {
    "issues": "https://github.com/TripalCultivate/TripalCultivate-Phenotypes/issues"
  },
  "license": "GPL-3.0-or-later",
  "minimum-stability": "dev",
  "authors": [
        {
            "name": "Lacey-Anne Sanderson",
            "homepage": "https://github.com/laceysanderson/",
            "role": "Lead Developer"
        },
        {
            "name": "Reynold Tan",
            "homepage": "https://github.com/reynoldtan",
            "role": "Lead Developer"
        }
  ],
  "require": {
    "php": "^8.0",
    "drupal/core": "^9.4",
    "tripal/tripal": "^4.0-alpha1"
  }
}
```

## 4. Sign your repository up for Zenodo

Sign into [Zenodo](https://zenodo.org) using Github (remember not to use your own account) and go to https://zenodo.org/account/settings/github/. Follow the instructions on this page to turn on automatic preservation of your software. This will create a DOI when you eventually release an official version that can be used in a more official citation.

![zenodo-instructions](https://user-images.githubusercontent.com/1566301/222283278-39546c13-ea29-4882-b1a8-5c37243d9e0b.png)

## 5. Setup Code Climate automated testing

Sign into [Code Climate](https://codeclimate.com/login) using Github (remember not to use your own account). Click on "Add repository" within the open source section and then select your new templated repo. You may need to use the sync button if it is not already in the list. This will then create a new build of your repo on code climate.

Next, you can get the badge information by going to "Repo Settings" in the top menu and then clicking on "Badges" under the "Extra" section in the left sidebar. The choose restructured text for both the Maintainability and Code Coverage Badges. Save this information for the next step.


![codeclimate-badge-info](https://user-images.githubusercontent.com/1566301/222281319-4303fd84-9817-4498-85e4-2ec2a95baaca.png)

In another window, edit the README and scroll to the very bottom of the page. Change the URLs so the one mentioned for `image` above is in the badge as follows:

```
[our CodeClimate project page]: https://codeclimate.com/github/PLACEHOLDER-TRIPAL/Template
[MaintainabilityBadge]: https://api.codeclimate.com/v1/badges/5d139ad7af5a3e2564ab/maintainability
[TestCoverageBadge]: https://api.codeclimate.com/v1/badges/5d139ad7af5a3e2564ab/test_coverage
```

Next you will need to create a github secret for the code climate reporter id. Specifically, you will want to look up the code climate test reporter ID by going to the the code climate page for your repository, clicking on "Repo Settings" and then "Test Coverage" in the left sidebar. Then scroll down to the bottom, there will be a "Test reporter ID" with a textfield containing a long alpha-numerical code. 

![codeclimate-test-reporter-id](https://user-images.githubusercontent.com/1566301/223853565-23c95db0-b133-4028-969e-989485b3a8b4.png)

This long code should be the value of the github secret, CODECLIMATE_TEST_REPORTER_ID, and will be used in the Test Coverage workflow to update our test coverage stats on Code Climate. To create the github secret you will want to follow [these instructions by GitHub](https://docs.github.com/en/actions/security-guides/encrypted-secrets#creating-encrypted-secrets-for-a-repository). The name of the secret should be "CODECLIMATE_TEST_REPORTER_ID" and the value should be the long code you looked up above.

![github-new-secret](https://user-images.githubusercontent.com/1566301/223853576-81301f13-ec22-4533-b20f-694102a8789d.png)

**NOTE: This is another good place to commit and push. At this point ALL the automated testing including code coverage should pass.**

**NOTE: The code climate page will still say "There are no builds to show for this repository." until this customization is merged into the main branch. Don't worry -you are almost there!**

## 6. Make a PR and merge into 4.x

Noww you can make a PR to merge all the changes from the customize-template branch into 4.x. This needs to be done before setting up Packagist because that step needs the composer.json file to be in 4.x.

Do not worry about adding a description for the PR and leave the title at the default.

![Screen Shot 2023-03-15 at 2 40 54 PM](https://user-images.githubusercontent.com/1566301/225436847-656ce8cc-0ef0-4f25-885d-796f66c6659f.png)

Then in the PR, choose teh arrow beside "Merge Pull Request" and select "Squash and merge". You an leave the default information in place for the commit message and delete the branch afterwards.

![Screen Shot 2023-03-15 at 2 41 38 PM](https://user-images.githubusercontent.com/1566301/225437092-b0e874b9-13a8-4512-ba08-8c33b91058a0.png)

## 7. Setup Packagist

This is needed so the composer command in the readme works and, thus, so people can install this module according to Drupal best practices.

Sign into [Packagist](https://packagist.org/login/) using username and password (remember not to use your own account).

Then click submit in the top menu bar and fill in the URL for your repository on the resulting page.

![Screen Shot 2023-03-15 at 2 48 27 PM](https://user-images.githubusercontent.com/1566301/225438818-bd05b9d0-595c-49b1-894d-41e7a2c85b92.png)

Click check, confirm that the name found for your repository is correct and then click submit.

![Screen Shot 2023-03-15 at 2 49 42 PM](https://user-images.githubusercontent.com/1566301/225439238-3d8e1f23-dd50-401f-8cb9-64a96d4969d7.png)

This will create a new package page on packagist for your module and automatically sync's with Github for branches, releases and README. That is also why this is basically the last step. ;-p

## 8. Add branch protection rules

Go to Settings and then click "Branches" in the left sidebar. 

![Screen Shot 2023-03-15 at 3 07 43 PM](https://user-images.githubusercontent.com/1566301/225443339-15f3e09d-c261-42a3-b80d-90c57523f587.png)

Next click "Add branch protection rule". The "Branch name pattern" is `4.x` and you will want to select the following protections:

 - "Require a pull request before merging"
   - unselect "Require approvals"
 - "Require status checks to pass before merging"
   - "Require branches to be up to date before merging"
   - In the textfield saying "Search for status checks..." find the following: "run-tests (8.0, 13, 9.5.x-dev)" and "run-tests (8.1, 13, 9.5.x-dev)"
 - "Do not allow bypassing the above settings"

Finally click create. On the resulting page make sure that your rule says "Currently applies to 1 branch".

![branch-protections-rules](https://user-images.githubusercontent.com/1566301/225450379-3c536fbe-9a36-4c32-8727-9821fa7d8326.png)
