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
 *   upload_description = "Germplasm file should be a tab separated file with the following columns:<ol>
     <li>Germplasm Name: Name of this germplasm accession.</li>
     <li>External Database: The institution who assigned the accession.</li>
     <li>Accession Number: A unique identifier for the accession. </li>
     <li>Germplasm Species: The species of the accession.</li>
     <li>Germplasm Subtaxa: Subtaxon can be specified here or any additional taxonomic identifier.</li>
     <li>Institute Code: The code for the Institute that bred the material.</li>
     <li>Institute Name: The name of the Institute that bred the material.</li>
     <li>Country of Origin Code: 3-letter ISO 3166-1 code of the country in which the sample was originally sourced.</li>
     <li>Biological Status of Accession: The 3 digit code representing the biological status of the accession.</li>
     <li>Breeding Method: The unique identifier for the breeding method used to create this germplasm.</li>
     <li>Pedigree: The cross name and optional selection history.</li>
     <li>Synonyms: Any synonyms of the accession.</li>
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
    // Nothing to validate since the genus field is set to "required".
  }

  /**
   * @see TripalImporter::run()
   */
  public function run(){
    // All values provided by the user in the Importer's form widgets are
    // made available to us here by the Class' arguments member variable.
    $arguments = $this->arguments['run_args'];

    // The path to the uploaded file is always made available using the
    // 'files' argument. The importer can support multiple files, therefore
    // this is an array of files, where each has a 'file_path' key specifying
    // where the file is located on the server.
    $file_path = $arguments['files'][0]['file_path'];

    // Grab the genus name
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

      // Calculate how many bytes we have read from the file and let the
      // importer know how many have been processed so it can provide a
      // progress indicator.
      $bytes_read += drupal_strlen($current_line);
      $this->setItemsHandled($bytes_read);

      // Check for empty lines, comment lines and a header line
      if (empty($current_line)) continue;
      if (preg_match('/^#/', $current_line)) continue;
      if (preg_match('/^Germplasm/', $current_line)) continue;

      // Trim the current line for trailing whitespace and split columns
      // into an array
      $current_line = trim($current_line);
      $germplasm_columns = explode("\t", $current_line);

      // Collect our values from our current line into variables
      $germplasm_name = $germplasm_columns[0];
      $external_database = $germplasm_columns[1];
      $accession_number = $germplasm_columns[2];
      $germplasm_species = $germplasm_columns[3];
      $germplasm_subtaxa = $germplasm_columns[4];
      $institute_code = $germplasm_columns[5];
      $institute_name = $germplasm_columns[6];
      $country_of_origin_code = $germplasm_columns[7];
      $biological_status_of_accession_code = $germplasm_columns[8];
      $breeding_method_DbId = $germplasm_columns[9];
      $pedigree = $germplasm_columns[10];
      $synonyms = $germplasm_columns[11];

      // STEP 1: Pull out the organism ID for the current germplasm
      $organism_ID = $this->getOrganismID($genus_name, $germplasm_species, $germplasm_subtaxa);

      // STEP 2: Check/Insert this germplasm into the Chado stock table
      $stock_id = $this->getStockID($germplasm_name, $accession_number, $organism_ID);

      // STEP 3: If $external_database is provided, then
      // Load the external database info into Chado dbxref table

      // STEP 4: Load stock properties (if provided) for:
      // $institute_code
      // $institute_name
      // $country_of_origin_code
      // $biological_status_of_accession_code
      // $breeding_method_DbId
      // $pedigree

      // STEP 5: Load synonyms

    }
  }

  /**
   * Checks if an organism exists in Chado and returns the primary key,
   * otherwise throws an error if the organism does not exist or there
   * are multiple matches
   *
   * @param string $genus_name
   * @param string $germplasm_species
   * @param string $germplasm_subtaxa
   * @return int|false
   *   The value of the primary key for the organism record in Chado.
   *   If no single primary key can be retrieved, then FALSE is returned.
   */
  public function getOrganismID($genus_name, $germplasm_species, $germplasm_subtaxa) {

    $organism_name = $genus_name . ' ' . $germplasm_species;
    if ($germplasm_subtaxa) {
      $organism_name = $organism_name . ' ' . $germplasm_subtaxa;
    }
    $organism_array = chado_get_organism_id_from_scientific_name($organism_name);

    if (!$organism_array) {
      $this->logMessage("ERROR: Could not find an organism \"@organism_name\" in the database.", ['@organism_name' => $organism_name], TRIPAL_ERROR);
      return false;
    }
    // We also want to check if we were given only one value back, as there is
    // potential to retrieve multiple organism IDs
    if (is_array($organism_array) && (count($organism_array) > 1)) {
      $this->logMessage("ERROR: Found more than one organism ID for \"@organism_name\" when only 1 was expected.", ['@organism_name' => $organism_name], TRIPAL_ERROR);
      return false;
    }

    return $organism_array[0];
  }

  /**
   * Checks if an stock exists in Chado, inserts it and returns the primary
   * key in the stock table
   *
   */
  public function getStockID() {
    return 0;
  }

  /*
   * {@inheritdoc}
   */
  public function postRun() {
    // Nothing to clean up.
  }

  /**
   * @see TripalImporter::formSubmit()
   */
  public function formSubmit($form, &$form_state) {

  }
}
