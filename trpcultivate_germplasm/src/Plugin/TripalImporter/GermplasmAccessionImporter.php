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
   * An associative array of cvterms as the key and the cvterm_id as the value.
   */
  protected $cvterms = [
    'accession',
    'subtaxa',
    'institute_code',
    'institute_name',
    'country_of_origin_code',
    'biological_status_of_accession_code',
    'breeding_method_DbId',
    'pedigree',
    'synonym',
    'stock_relationship_type_synonym'
  ];

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

    $output = "The input file should be a tab separated file (<b>" . implode(', ', $file_types) . "</b>) with the following columns. ";
    $output .= "For more detailed information on this format including links to lookup various codes, please see ";
    $output .= '<a href="https://tripalcultivate.github.io/docs/docs/curation/germplasm-data/germplasm-accessions-importer">the official documentation</a>.';

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
      'Synonyms' => 'Any synonyms of the accession, separated by a comma. (e.g. Redberry)',
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
   * Set a cvterm with its cvterm_id
   *
   * @param string $key
   *   A key used in the config settings.yml
   * @param int $cvterm_id
   * @return TRUE
   */
  public function setCVterm($key, $cvterm_id) {
    $this->cvterms[$key] = $cvterm_id;
    return TRUE;
  }

  /**
   * Get a cvterm ID, given a key that maps to the config settings.yml
   *
   * @param string $key
   *   The cvterm name
   * @return int
   *   The cvterm ID
   */
  public function getCVterm($key) {
    return $this->cvterms[$key];
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
        <h2>Import Germplasm Accessions</h2>
        <p>Use this form to import germplasm accessions into Chado with metadata that meet the BrAPI standards. <b>Please confirm the file format and column order before upload as this will insert records into your Chado database.</b> </p>
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
   * Checks if our terms have been set already from the config file.
   * This is helpful for automated test functionality where terms are
   * set there using our setCVterm() function.
   * If not already set, then the value of the term is set using setCVterm()
   * here.
   */
  public function setUpCVterms(){

    $germplasm_config = $this->config_factory->get('trpcultivate_germplasm.settings');
    // Iterate through our cvterms
    // If it hasn't been set before, set it now
    foreach($this->cvterms as $term){
      if (!isset($this->cvterms[$term])){
        $terms_string = 'terms.' . $term;
        $this->setCVterm($term, $germplasm_config->get($terms_string));
      }
    }
  }

  /**
   * @see TripalImporter::run()
   */
  public function run(){

    // Grabbing our arguments from the form
    $arguments = $this->getArguments();

    // The path to the uploaded file is always made available using the
    // 'files' argument. The importer can support multiple files, therefore
    // this is an array of files, where each has a 'file_path' key specifying
    // where the file is located on the server.
    $file_path = $arguments['files'][0]['file_path'];
    if (!file_exists($file_path)) {
      throw new \Exception(
        t("File does not exist: @file", ['@file' => $file_path])
      );
    }

    // Grab the genus name
    $genus_name = $arguments['run_args']['genus_name'];

    // Make sure our CVterms are all set
    $this->setUpCVterms();

    // Check if the stock_synonym table exists before moving forward
    if (!$this->connection->schema()->tableExists('stock_synonym')) {
      throw new \Exception(
        t("Could not find stock_synonym table in the current database schema.")
      );
    }

    // Set up the ability to track progress so we can report it to the user
    $filesize = filesize($file_path);
    $this->setTotalItems($filesize);
    $this->setItemsHandled(0);
    $bytes_read = 0;
    $line_count = 0;

    // Open the file and start iterating through each line
    $GERMPLASM_FILE = fopen($file_path, 'r');
    if(!$GERMPLASM_FILE) {
      throw new \Exception(
        t("Could not open file: @file", ['@file' => $file_path])
      );
    }

    while (!feof($GERMPLASM_FILE)){
      $current_line = fgets($GERMPLASM_FILE);
      $line_count++;

      // Calculate how many bytes we have read from the file and let the
      // importer know how many have been processed so it can provide a
      // progress indicator.
      $bytes_read += mb_strlen($current_line);
      $this->setItemsHandled($bytes_read);

      // Check for empty lines, comment lines and a header line
      $current_line = trim($current_line);
      if ($current_line == '') continue;
      if (preg_match('/^#/', $current_line)) continue;
      if (preg_match('/^Germplasm/i', $current_line)) continue;

      // Split our columns into an array for easier processing
      $germplasm_columns = explode("\t", $current_line);
      $num_columns = count($germplasm_columns);
      if (count($germplasm_columns) < 4) {
        $this->logger->error("Insufficient number of columns detected (<4) for line # @line", ['@line' => $line_count]);
        $this->error_tracker = TRUE;
				// Continue to next line since we already know this will cascade into
				// further errors
				continue;
      }

      // Collect our values from our current line into variables
      // Since the 1st 4 columns are required, make sure there are values there
      for($i=0; $i<4; $i++) {
        $column = $i+1;
        if ($germplasm_columns[$i] == '') {
          $this->logger->error("Column @column is required and cannot be empty for line # @line", ['@column' => $column, '@line' => $line_count]);
          $this->error_tracker = TRUE;
					// Continue to next line since we already know this will cascade into
					// further errors
					continue 2;
        }
      }
      $germplasm_name = $germplasm_columns[0];
      $external_database = $germplasm_columns[1];
      $accession_number = $germplasm_columns[2];
      $germplasm_species = $germplasm_columns[3];
      $germplasm_subtaxa = $germplasm_columns[4] ?? '';
      $stock_properties = [
        'institute_code' => $germplasm_columns[5] ?? '',
        'institute_name' => $germplasm_columns[6] ?? '',
        'country_of_origin_code' => $germplasm_columns[7] ?? '',
        'biological_status_of_accession_code' => $germplasm_columns[8] ?? '',
        'breeding_method_DbId' => $germplasm_columns[9] ?? '',
        'pedigree' => $germplasm_columns[10] ?? ''
      ];
      $synonyms = $germplasm_columns[11] ?? '';

      // Here we are calling 5 separate functions to check for and insert various
      // parts of the input file. Everything is wrapped in a try-catch to ensure
      // a useful error message can be passed onto the user and that all errors
      // that the file encounters can be reported at one time and not committed
      // to the database.
      try {
        // STEP 1: Pull out the organism ID for the current germplasm
        $organism_id = $this->getOrganismID($genus_name, $germplasm_species, $germplasm_subtaxa);

        // STEP 2: Check/Insert this germplasm into the Chado stock table
        if ($organism_id) {
          $stock_id = $this->getStockID($germplasm_name, $accession_number, $organism_id);
        }

        if (isset($stock_id) && ($stock_id != null)) {
          // STEP 3: Load the external database info into Chado dbxref table
          $dbxref_id = $this->getDbxrefID($external_database, $stock_id, $accession_number);

          // STEP 4: Load stock properties
          $load_props = $this->loadStockProperties($stock_id, $stock_properties);

          // STEP 5: Load synonyms
          $load_synonyms = $this->loadSynonyms($stock_id, $synonyms, $organism_id);
        }
      } catch ( \Exception $e ) {
        $this->logger->error("An unusual error occurred when processing germplasm \"@germplasm\". Here is the stack trace: \n" . $e->getMessage() . "\n", ['@germplasm' => $germplasm_name] );
        $this->error_tracker = TRUE;
      }
    }
    // Check the error flag
    // If true, throw an exception explaining that nothing will be added to the database
    // unless errors are resolved
    if ($this->error_tracker) {
      throw new \Exception(
        t("The database transaction was not commited due to the presence of one or more errors. Please fix all errors and try the import again.")
      );
    }
    else {
      $this->logger->notice("Reached end of file without encountering any errors. Transaction will be committed to the database.");
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

    $accession_type_id = $this->getCVterm('accession');

    // First query the stock table:
    // 1. Using a regular condition to ensure the organism_id is a match
    // 2. Create an OR condition group to look for records that match germplasm name OR
    //    the uniquename. Since the unique constraint is organism_id/uniquename/type_id,
    //    we have to make sure this combo doesn't already exist with a different germplasm
    //    name.
    $query = $this->connection->select('1:stock', 's')
      ->fields('s', ['stock_id', 'name', 'uniquename', 'type_id'])
      ->condition('s.organism_id', $organism_id, '=');

    $orGroup = $query->orConditionGroup()
      ->condition('s.name', $germplasm_name, '=')
      ->condition('s.uniquename', $accession_number, '=');

    // Now add the OR condition group to the query
    $query->condition($orGroup);
    $record = $query->execute()->fetchAll();

    // We may have retrieved 1+ records that share the germplasm name and/or 1+ records that
    // share the accession_number. In this case, throw an error since there's no way to
    // enter a new record with a unique organism_id/uniquename/type_id combo in this scenario
    if (sizeof($record) >= 2) {
      $stock_string_array = [];
      foreach ($record as $stock_hit) {
        $stock_string = $stock_hit->name . " (uniquename=" . $stock_hit->uniquename . "; stock_id=" . $stock_hit->stock_id . ")";
        array_push($stock_string_array, $stock_string);
      }

      $this->logger->error("Found more than one stock ID for \"@germplasm_name\" and/or \"@accession\". The existing stocks are: @stock_list", ['@germplasm_name' => $germplasm_name, '@accession' => $accession_number, '@stock_list' => implode(", ", $stock_string_array)]);
      $this->error_tracker = TRUE;
      return false;
    }

    elseif (sizeof($record) == 1) {
      // Handle the situation where a stock record exists
      // Here we are individually checking that our uniquename, name and type_id all match
      // what is in the input file. This is to provide an informative error message if one
      // of these don't match. In the future, we may want to handle each case differently.
      // For example, some groups may want to allow the same germplasm name but a different
      // type_id to be allowed.
      // 1. Check the uniquename matches the accession_number column in the file
      if ($accession_number != $record[0]->uniquename) {
        $this->logger->error("A stock already exists for \"@germplasm_name\" but with an accession of \"@accession\" which does not match the input file.", ['@germplasm_name' => $germplasm_name, '@accession' => $record[0]->uniquename]);
        $this->error_tracker = TRUE;
        return false;
      }
      // 2. Check that our germplasm name matches
      if ($germplasm_name != $record[0]->name) {
        $this->logger->error("A stock already exists for accession \"@accession\" but with a germplasm name of \"@germplasm_name\" which does not match the input file.", ['@germplasm_name' => $record[0]->name, '@accession' => $accession_number]);
        $this->error_tracker = TRUE;
        return false;
      }
      // 3. Check the type_id is of type accession
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
      $values = [
        'organism_id' => $organism_id,
        'name' => $germplasm_name,
        'uniquename' => $accession_number,
        'type_id' => $accession_type_id
      ];

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
    // -------------------------------------------------
    // Check if the external database exists in chado.db
    // If not, report an error
    // -------------------------------------------------
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
      $this->logger->error("Unable to find \"@external_db\" in chado.db.", ['@external_db' => $external_database]);
      $this->error_tracker = TRUE;
      return false;
    }

    // Confirmed that a single record of this external database exists
    $db_id = $db_record[0]->db_id;

    // -------------------------------------------------
    // Check if the dbxref for this stock already exists
    // If not, insert it
    // -------------------------------------------------
    $dbx_query = $this->connection->select('1:dbxref', 'dbx')
      ->fields('dbx', ['dbxref_id']);
    $dbx_query->condition('dbx.accession', $accession_number, '=')
      ->condition('dbx.db_id', $db_id, '=');
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
      else {
        $dbxref_id = $result;
      }
    }

    // ------------------------------------------------------------------------
    // Update the stock table to include the dbxref_id
    // After making sure a different one doesn't already exist (throw an error)
    // ------------------------------------------------------------------------
    $stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['dbxref_id']);
    $stock_query->condition('s.stock_id', $stock_id, '=');
    $stock_record = $stock_query->execute()->fetchAll();

    if ($stock_record[0]->dbxref_id == "") {
      $update_stock = $this->connection->update('1:stock')
        ->fields(['dbxref_id' => $dbxref_id])
        ->condition('stock_id', $stock_id, '=')
        ->execute();

      // Since update queries return the number of rows affected, check that only one row was changed
      if ($update_stock != 1) {
        $this->logger->error("An attempt to update the dbxref_id of \"@stock\" reported that \"@number\" rows were affected.", ['@stock' => $accession_number, '@number' => $update_stock]);
        $this->error_tracker = TRUE;
        return false;
      }
      else {
        return $dbxref_id;
      }
    }
    // Otherwise, the correct dbxref_id might already be set so we're good to go
    elseif ($stock_record[0]->dbxref_id == $dbxref_id) {
      return $dbxref_id;
    }
    // OR, it is something entirely different - so report an error
    else {
      $this->logger->error("There is already a primary dbxref_id for stock ID \"@stock\" that does not match the external database and accession provided in the file (@external_db:@accession).", ['@stock' => $stock_id, '@external_db' => $external_database, '@accession' => $accession_number]);
      $this->error_tracker = TRUE;
      return false;
    }

  }

  /**
   * Checks each property within an array and inserts them into chado.stockprop.
   * Returns true if the insert was successful.
   *
   * @param int $stock_id
   *   The value of the primary key for the stock record in Chado.
   * @param array $stock_properties
   *   An array of optional properties to be attached to a stock
   * @return boolean
   *   Returns true if inserting all the properties was successful,
   *   including if there are no properties
   */
  public function loadStockProperties($stock_id, $stock_properties) {

    foreach ($stock_properties as $property => $prop_value) {

      // Skip if the value of this property is empty
      if (($prop_value !== '0') && empty($prop_value)) { continue; }
      // Lookup the CV term
      $cvterm_id = $this->getCVterm($property);
      if ($cvterm_id) {
        // Try to lookup the stockprop_id in Chado
        $stockprop_query = $this->connection->select('1:stockprop', 'sp')
          ->fields('sp', ['stockprop_id', 'value', 'rank'])
          ->condition('sp.stock_id', $stock_id, '=')
          ->condition('sp.type_id', $cvterm_id, '=');
        $stockprop_record = $stockprop_query->execute()->fetchAll();
        // If one or more record(s) exists for this stock, check if one is the same as in
        // the file. If not, then add it but increase the rank by 1
        if (sizeof($stockprop_record) >= 1) {
          $maxrank = 0;
          foreach ($stockprop_record as $record) {
            $found = false;
            if ($record->value == $prop_value) {
              $found = true;
              break;
            }
            else {
              $rank = $record->rank;
              if ($rank > $maxrank) { $maxrank = $rank; }
            }
          }
          if ($found == false) {
            // Insert this property into the stockprop table and increment the max rank by one
            $values = [
              'stock_id' => $stock_id,
              'type_id' => $cvterm_id,
              'value' => $prop_value,
              'rank' => ++$maxrank
            ];
            $result = $this->connection->insert('1:stockprop')
              ->fields($values)
              ->execute();

            // If the primary key is not available, then the insert failed
            if (!$result) {
              $this->logger->error("Insertion of stock property \"@property\" into chado.stockprop failed.", ['@property' => $property]);
              $this->error_tracker = TRUE;
              return false;
            }
          }
        }

        // If no records exist, then insert the property as normal
        else {
          $values = [
            'stock_id' => $stock_id,
            'type_id' => $cvterm_id,
            'value' => $prop_value
          ];
          $result = $this->connection->insert('1:stockprop')
            ->fields($values)
            ->execute();

          // If the primary key is not available, then the insert failed
          if (!$result) {
            $this->logger->error("Insertion of stock property \"@property\" into chado.stockprop failed.", ['@property' => $property]);
            $this->error_tracker = TRUE;
            return false;
          }
        }
      }
      else {
        $this->logger->error("Unable to retrieve the cvterm_id of property \"@property\"", ['@property' => $property]);
        $this->error_tracker = TRUE;
        return false;
      }
    }
  }

  /**
   * Loads each synonym into the chado.synonym and chado.stock_synonym
   * tables. Returns true if the insert was successful.
   *
   * @param int $stock_id
   *   The value of the primary key for the stock record in Chado.
   * @param string $stock_properties
   *   The name that is a synonym of the current germplasm. Multiple
   *   synonyms may be specified, in which case they are expected to
   *   be separated using a comma or semicolon.
   * @return boolean
   *   Returns true if inserting all the properties was successful,
   *   including if there are no properties
   */
  public function loadSynonyms($stock_id, $synonyms, $organism_id) {

    if ($synonyms) {
      // Separate out multiple synonyms if we have them by either
      // semicolons or commas. Whitespace is optional
      $all_synonyms = preg_split("/[;,]\s*/", $synonyms);

      foreach ($all_synonyms as $synonym) {
        $synonym = trim($synonym);
        $synonym_type_id = $this->getCVterm('synonym');

        // Check for and load any synonyms to chado.synonym
        $synonym_query = $this->connection->select('1:synonym', 's')
          ->fields('s', ['synonym_id'])
          ->condition('s.name', $synonym, '=')
          ->condition('s.type_id', $synonym_type_id, '=');
        $synonym_ids = $synonym_query->execute()->fetchCol();

        // Make sure there aren't 2 or more records for this synonym
        if (sizeof($synonym_ids) >= 2) {
          $this->logger->error("Found more than one synonym for \"@synonym\" in chado.synonym (synonym_ids @ids).", ['@synonym' => $synonym, '@ids' => implode(', ', $synonym_ids)]);
          $this->error_tracker = TRUE;
          return false;
        }
        elseif (sizeof($synonym_ids) == 1) {
          $synonym_id = $synonym_ids[0];
        }
        // Can't find a synonym in the chado.synonym table, so insert it
        else {
          $values = [
            'name' => $synonym,
            'type_id' => $synonym_type_id,
            'synonym_sgml' => ''
          ];
          $result = $this->connection->insert('1:synonym')
            ->fields($values)
            ->execute();

          // If the primary key is not available, then the insert failed
          if (!$result) {
            $this->logger->error("Insertion of \"@synonym\" into chado.synonym failed.", ['@synonym' => $synonym]);
            $this->error_tracker = TRUE;
            return false;
          }
          else {
            $synonym_id = $result;
          }
        }
        // ------------------------------------------------------------------------
        // Create a synonym-stock connection via chado.stock_synonym
        // ------------------------------------------------------------------------
        // First check if this stock-synonym connection already exists
        $synonym_stock_query = $this->connection->select('1:stock_synonym', 'ss')
          ->fields('ss', ['stock_synonym_id'])
          ->condition('ss.synonym_id', $synonym_id, '=')
          ->condition('ss.stock_id', $stock_id, '=');
        $synonym_stock_record = $synonym_stock_query->execute()->fetchAll();

        // Make sure there aren't 2 or more records
        // Should not be possible due to a unique constraint
        if (sizeof($synonym_stock_record) >= 2) {
          $this->logger->error("Found more than one stock-synonym connection for stock ID \"@stock\" and synonym \"@synonym\" in chado.stock_synonym.", ['@stock' => $stock_id, '@synonym' => $synonym]);
          $this->error_tracker = TRUE;
          return false;
        }
        // If 1 result was returned, just ignore it and move on

        // Otherwise, create it
        elseif (sizeof($synonym_stock_record) == 0) {
          $values = [
              'synonym_id' => $synonym_id,
              'stock_id'=> $stock_id,
              'pub_id'=> '1', // Set to the NULL publication and hopefully someone will update it later :)
          ];
          $result = $this->connection->insert('1:stock_synonym')
            ->fields($values)
            ->execute();

          // If the primary key is not available, then the insert failed
          if (!$result) {
            $this->logger->error("Insertion of stock ID \"@stock\" and synonym \"@synonym\" into chado.stock_synonym failed.", ['@stock' => $stock_id, '@synonym' => $synonym]);
            $this->error_tracker = TRUE;
            return false;
          }
        }

        // ------------------------------------------------------------------------
        // Lastly, check if our synonym name is in the stock table. If yes, THEN create
        // a stock_relationship to connect these 2 stocks (ie: the current stock and the
        // stock matching the name of the synonym).
        // ------------------------------------------------------------------------
        $stock_relationship_type_id = $this->getCVterm('stock_relationship_type_synonym');

        $stock_query = $this->connection->select('1:stock', 'st')
          ->fields('st', ['stock_id'])
          ->condition('st.name', $synonym, '=')
          ->condition('st.organism_id', $organism_id, '=');
        $stock_record = $stock_query->execute()->fetchAll();

        // Make sure there aren't 2 or more records
        if (sizeof($stock_record) >= 2) {
          $this->logger->notice("Found more than one match for synonym name \"@synonym\" in chado.stock.", ['@synonym' => $synonym]);
        }
        elseif (sizeof($stock_record) == 1) {
          // Query the stock_relationship table to see if this relationship already exists
          $stock_id_of_synonym = $stock_record[0]->stock_id;
          $stock_relationship_query = $this->connection->select('1:stock_relationship', 'str')
            ->fields('str', ['stock_relationship_id'])
            ->condition('str.subject_id', $stock_id_of_synonym, '=')
            ->condition('str.object_id', $stock_id, '=')
            ->condition('type_id', $stock_relationship_type_id, '=');
          $stock_relationship_record = $stock_relationship_query->execute()->fetchAll();
          // If 2+ relationships exist, then report an error
          if (sizeof($stock_relationship_record) >= 2) {
            $this->logger->error("Found more than one stock relationship for synonym name \"@synonym\" and stock ID \"@stock\" in chado.stock_relationship.", ['@synonym' => $synonym, '@stock' => $stock_id]);
            $this->error_tracker = TRUE;
            return false;
          }
          // If 1 result, carry on

          // If no results, create the stock relationship
          if (sizeof($stock_relationship_record) == 0) {
            $values = [
              'subject_id' => $stock_id_of_synonym,
              'type_id' => $stock_relationship_type_id,
              'object_id' => $stock_id
            ];
            $result = $this->connection->insert('1:stock_relationship')
              ->fields($values)
              ->execute();

            // If the primary key is not available, then the insert failed
            if (!$result) {
              $this->logger->error("Insertion of stock ID \"@stock\" and stock ID of its synonym \"@sid_synonym\" into chado.stock_relationship failed.", ['@stock' => $stock_id, '@sid_synonym' => $stock_id_of_synonym]);
              $this->error_tracker = TRUE;
              return false;
            }
          }
        }
        else {
          $this->logger->notice("Synonym \"@synonym\" was not found in the stock table, so no stock_relationship was made with stock ID \"@stock\".", ['@synonym' => $synonym, '@stock' => $stock_id]);
        }
      }
      // Cycled through all the synonyms by this point, and if false hasn't been
      // returned, then return true
      return true;
    }
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
