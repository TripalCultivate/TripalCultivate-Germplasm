<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\Core\Config\ConfigFactoryInterface;

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
   * Used to track whether an error is logged during the import process. If it
   * is set to TRUE, then the db transaction will not be committed.
   */
  protected $error_tracker = FALSE;

  /**
   * The service for retreiving configuration values.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config_factory;

  /**
   * Implements ContainerFactoryPluginInterface->create().
   *
   * OVERRIDES create() from the parent, ChadoImporterBase.php, in order to introduce the
   * config factory
   *
   * Since we have implemented the ContainerFactoryPluginInterface this static function
   * will be called behind the scenes when a Plugin Manager uses createInstance(). Specifically
   * this method is used to determine the parameters to pass to the contructor.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return static
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tripal_chado.database'),
      $container->get('config.factory')
    );
  }

  /**
   * Implements __contruct().
   *
   * OVERRIDES __construct() from the parent, ChadoImporterBase.php, in order to introduce
   * the config factory
   *
   * Since we have implemented the ContainerFactoryPluginInterface, the constructor
   * will be passed additional parameters added by the create() function. This allows
   * our plugin to use dependency injection without our plugin manager service needing
   * to worry about it.
   *
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   * @param Drupal\tripal_chado\Database\ChadoConnection $connection
   * @param
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ChadoConnection $connection, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $connection);

    $this->config_factory = $config_factory;
  }

  /**
   * @{inheritdoc}
   */
  public function describeUploadFileFormat() {

    $file_types = $this->plugin_definition['file_types'];

    $output = "Germplasm file should be a tab separated file (<b>" . implode(', ', $file_types) . "</b>) with the following columns:";

    $columns = [
      'Germplasm Name' => 'Name of this germplasm accession (e.g. CDC Redberry)',
      'External Database' => 'The institution who assigned the accession. (e.g. KnowPulse Germplasm)',
      'Accession Number' => 'A unique identifier for the accession (e.g. KP:GERM58)',
      'Germplasm Species' => 'The species of the accession (e.g. culinaris)',
      'Germplasm Subtaxa' => 'The rank below species is specified first, followed by the name (e.g. var. medullare)',
      'Institute Code' => 'The code for the Institute that bred the material (e.g. CUAC)',
      'Institute Name' => 'The name of the Institute that bred the material (e.g. "Crop Development Center, University of Saskatchewan")',
      'Country of Origin Code' => '3-letter ISO 3166-1 code of the country in which the sample was originally sourced (e.g. 124)',
      'Biological Status of Accession' => 'The 3 digit code representing the biological status of the accession (e.g. 410)',
      'Breeding Method' => 'The unique identifier for the breeding method used to create this germplasm (e.g. "Recurrent selection")',
      'Pedigree' => 'The cross name and optional selection history (e.g. 1049F^3/819-5R)',
      'Synonyms' => 'Any synonyms of the accession. (e.g. Redberry)',
    ];

    $required_col = ['Germplasm Name', 'External Database', 'Accession Number', 'Germplasm Species'];
    // @TODO: Make this red, but Drupal makes it difficult :)
    $required_markup = '*';

    $output .= '<ol>';
    foreach ($columns as $title => $definition) {
      if (in_array($title, $required_col)) {
        $output .= '<li><b>' . $title . $required_markup . '</b>: ' . $definition . '</li>';
      }
      else {
        $output .= '<li><b>' . $title . '</b>: ' . $definition . '</li>';
      }
    }
    $output .= '</ol>';

    return $output;
  }

  /**
   * {@inheritDoc}
   */
  public function form($form, &$form_state) {
    $form = parent::form($form, $form_state);

    // Select the entire genus field and make sure it is sorted and distinct
    $genus_query = $this->connection->select('1:organism', 'o')
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
      $organism_id = $this->getOrganismID($genus_name, $germplasm_species, $germplasm_subtaxa);

      // STEP 2: Check/Insert this germplasm into the Chado stock table
      if ($organism_id) {
        $stock_id = $this->getStockID($germplasm_name, $accession_number, $organism_id);
      }

      // STEP 3: Load the external database info into Chado dbxref table
      if ($stock_id) {
        $dbxref_id = $this->getDbxrefID($external_database, $stock_id, $accession_number);
      }

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
   *   The genus of the organism.
   * @param string $germplasm_species
   *   The species of the organism.
   * @param string $germplasm_subtaxa
   *   Optional. Must consist of two strings, one of the subtaxon type
   *   followed by the name. For example: "subspecies chadoii".
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
      $this->logger->error("Could not find an organism \"@organism_name\" in the database.", ['@organism_name' => $organism_name]);
      $this->error_tracker = TRUE;
      return false;
    }
    // We also want to check if we were given only one value back, as there is
    // potential to retrieve multiple organism IDs
    if (is_array($organism_array) && (count($organism_array) > 1)) {
      $this->logger->error("Found more than one organism ID for \"@organism_name\" when only 1 was expected.", ['@organism_name' => $organism_name]);
      $this->error_tracker = TRUE;
      return false;
    }

    return $organism_array[0];
  }

  /**
   * Checks if a stock exists in Chado and if not, inserts it and returns the primary
   * key in the stock table. If the stock already exists, logs an error
   *
   * @param string $germplasm_name
   *   The name of the germplasm.
   * @param string $accession_number
   *   A unique identifier for the germplasm accession.
   * @param int $organism_id
   *   The primary key of the stock's organism in the organism table
   * @return int|false
   *   The value of the primary key for the stock record in Chado. If the stock already
   *   exists and does not match the accession number or type, or it cannot be inserted,
   *   then FALSE is returned.
   */
  public function getStockID($germplasm_name, $accession_number, $organism_id) {

    $germplasm_config = $this->config_factory->get('trpcultivate_germplasm.settings');
    $accession_type_id = $germplasm_config->get('terms.accession');

    // First query the stock table just using the germplasm name and organism ID
    $query = $this->connection->select('1:stock', 's')
      ->fields('s', ['stock_id', 'uniquename', 'type_id']);
    $query->condition('s.name', $germplasm_name, '=')
      ->condition('s.organism_id', $organism_id, '=');
    $record = $query->execute()->fetchAll();

    if (sizeof($record) >= 2) {
      $this->logger->error("Found more than one stock ID for \"@germplasm_name\".", ['@germplasm_name' => $germplasm_name]);
      $this->error_tracker = TRUE;
      return false;
    }

    elseif (sizeof($record) == 1) {
      // Handle the situation where a stock record exists
      // Check the uniquename matches the accession_number column in the file
      if ($accession_number != $record[0]->uniquename) {
        $this->logger->error("A stock already exists for \"@germplasm_name\" but with an accession of \"@accession\" which does not match the input file.", ['@germplasm_name' => $germplasm_name, '@accession' => $record[0]->uniquename]);
        $this->error_tracker = TRUE;
        return false;
      }
      // Check the type_id is of type accession
      if ($accession_type_id != $record[0]->type_id) {
        $this->logger->error("A stock already exists for \"@germplasm_name\" but with a type ID of \"@type\" which is not of type \"accession\".", ['@germplasm_name' => $germplasm_name, '@type' => $accession_type_id]);
        $this->error_tracker = TRUE;
        return false;
      }

      // Confirmed that the selected record matches what's in the upload file, so return the stock_id
      return $record[0]->stock_id;
    }
    // Confirmed that a stock record doesn't yet exist, so now we create one
    else {
      $values = array(
        'organism_id' => $organism_id,
        'name' => $germplasm_name,
        'uniquename' => $accession_number,
        'type_id' => $accession_type_id
      );

      $this->logger->notice("Inserting \"@germplasm_name\".", ['@germplasm_name' => $germplasm_name]);

      $result = $this->connection->insert('1:stock')
        ->fields($values)
        ->execute();

      // If the primary key is available, then the insert worked and we can return it
      if ($result) {
        return $result;
      }
      else {
        $this->logger->error("Insertion of \"@germplasm_name\" failed.", ['@germplasm_name' => $germplasm_name]);
        $this->error_tracker = TRUE;
        return false;
      }
    }
  }

  /**
   * Checks if a dbxref exists in Chado and if not, inserts it. Then, updates
   * the stock table to include the dbxref_id. Returns the primary
   * key in the dbxref table.
   *
   * @param string $external_database
   *   The name of the institution who assigned the accession.
   * @param int $stock_id
   *   The value of the primary key for the stock record in Chado.
   * @param string $accession_number
   *   A unique identifier for the germplasm accession.
   * @return int|false
   *   The value of the primary key for the dbxref record in Chado.
   */
  public function getDbxrefID($external_database, $stock_id, $accession_number) {
    // Check if the external database exists in chado.db
    $db_query = $this->connection->select('1:db', 'db')
      ->fields('db', ['db_id']);
    $db_query->condition('db.name', $external_database, '=');
    $db_record = $db_query->execute()->fetchAll();

    if (sizeof($db_record) >= 2) {
      $this->logger->error("Found more than one db ID for \"@external_db\".", ['@external_db' => $external_database]);
      $this->error_tracker = TRUE;
      return false;
    }

    elseif (sizeof($db_record) == 0) {
      $this->logger->error("Couldn't find \"@external_db\" in chado.db.", ['@external_db' => $external_database]);
      $this->error_tracker = TRUE;
      return false;
    }

    // Confirmed that a single record of this external database exists
    $db_id = $db_record[0]->db_id;

    // Now to check for the dbxref record
    $dbx_query = $this->connection->select('1:dbxref', 'dbx')
      ->fields('dbx', ['dbxref_id']);
    $dbx_query->condition('dbx.accession', $accession_number, '=')
      ->condition('dbxdb_id', $db_id, '=');
    $dbx_record = $dbx_query->execute()->fetchAll();

    if (sizeof($dbx_record) >= 2) {
      $this->logger->error("Found more than one dbxref ID for \"@accession\".", ['@accession' => $accession_number]);
      $this->error_tracker = TRUE;
      return false;
    }
    elseif (sizeof($dbx_record) == 1) {
      $dbxref_id = $dbx_record[0]->dbxref_id;
    }
    // Couldn't find the dbxref_id for this accession, so insert it
    else {
      $values = [
        'db_id' => $db_id,
        'accession' => $accession_number
      ];
      $result = $this->connection->insert('1:dbxref')
        ->fields($values)
        ->execute();

      // If the primary key is not available, then the insert failed
      if (!$result) {
        $this->logger->error("Insertion of \"@accession\" into chado.dbxref failed.", ['@accession' => $accession_number]);
        $this->error_tracker = TRUE;
        return false;
      }
    }

    // Now update the stock table to include the dbxref_id

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
