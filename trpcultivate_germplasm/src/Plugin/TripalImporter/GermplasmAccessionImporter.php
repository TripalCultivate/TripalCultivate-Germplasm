<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;

/**
 * Provides an importer for loading germplasm accessions from a tab-delimited
 * file.
 *
 * @TripalImporter(
 *   id = "trpcultivate-germplasm-accession",
 *   label = @Translation("Tripal Cultivate: Germplasm Accessions"),
 *   description = @Translation("Imports germplasm accessions into Chado with metadata meeting BrAPI standards."),
 *   file_types = {"tsv", "txt"},
 *   use_analysis = FALSE,
 *   require_analysis = FALSE,
 *   upload_title = "Germplasm Accession Import",
 *   upload_description = "Germplasm file should be a tab separated file with the following colums:<ol>
     <li>Germplasm Name: Name of this germplasm accession.</li>
     <li>External Database: The institution who assigned the following accession.</li>
     <li>Accession Number: A unique identifier for the accession. </li>
     <li>Germplasm Species: The species of the accession.</li>
     <li>Germplasm Subtaxa: Subtaxon can be used to store any additional taxonomic identifier.</li>
     <li>Institute Code: The code for the Institute that has bred the material.</li>
     <li>Institute Name: The name of the Institute that has bred the material.</li>
     <li>Country of Origin Code: 3-letter ISO 3166-1 code of the country in which the sample was originally.</li>
     <li>Biological Status of Accession: The 3 digit code representing the biological status of the accession.</li>
     <li>Breeding Method: The unique identifier for the breeding method used to create this germplasm.</li>
     <li>Pedigree: The cross name and optional selection history.</li>
     <li>Synonyms: The synonyms of the accession.</li>
     </ol>",
 *   button_text = "Import Germplasm Accessions",
 *   file_upload = True,
 *   file_load = True,
 *   file_remote = True,
 *   file_required = True,
 *   cardinality = 1
 * )
 */
class GermplasmAccessionImporter extends ChadoImporterBase {
  /**
   * {@inheritDoc}
   */
  public function form($form, &$form_state) {

    // Select the entire genus field and make sure it is sorted and distinct
    $connection = \Drupal::service('tripal_chado.database');
    $genus_query = $connection->select('1:organism', 'o')
      ->fields('o',['genus'])
      ->orderBy('genus')
      ->distinct();
    $genus = $genus_query->execute()->fetchAllKeyed(0,0);

    $form['instructions'] = [
      '#weight' => -99,
      '#markup' => '
        <h2>Load germplasm into database</h2>
        <p>Please confirm the file format and column order before upload.</p>
      ',
    ];

    $form['genus_name'] = [
      '#weight' => -80,
      '#type' => 'select',
      '#title' => t('Genus'),
      '#options' => $genus,
      '#required' => TRUE,
      '#description' => t('Select the genus of the germplasm accessions in your file. If your file consists of multiple genus, it is best practice to separate it into one file per genus and upload each one individually.'),
    ];

    return $form;
  }

  /**
   * @see TripalImporter::formValidate()
   */
  public function formValidate($form, &$form_state){

  }

  /**
   * @see TripalImporter::run()
   */
  public function run(){
    $arguments = $this->arguments['run_args'];

    // Grab the file path
    $file_path = $arguments['files'][0]['file_path'];
    $genus_name = $arguments['genus_name'];

    // Set up the ability to track progress so we can report it to the user
    $filesize = filesize($file_path);
    $this->setTotalItems($filesize);
    $this->setItemsHandled(0);
    $bytes_read = 0;

    // Open the file and start iterating through each line
    $GERMPLASM_FILE = fopen($file_path, 'r');
    while (!feof($GERMPLASM_FILE)){
      $current_line = fgetcsv($GERMPLASM_FILE, 0, "\t");

      // Check for empty lines, comment lines and a header line
      if (empty($current_line)) continue;
      if (preg_match('/^#/', $current_line)) continue;
      if (preg_match('/^Germplasm/', $current_line)) continue;
    }
  }

  /*
   * {@inheritdoc}
   */
  public function postRun() {

  }

  /**
   * @see TripalImporter::formSubmit()
   */
  public function formSubmit($form, &$form_state) {

  }


}
