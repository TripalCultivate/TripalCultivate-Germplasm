<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Core\Url;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate_germplasm\Traits\GermplasmAccessionImporterTestTrait;

/**
 * Tests the functionality of the Germplasm Accession Importer.
 */
class GermplasmAccessionImporterTest extends ChadoTestKernelBase {

	protected $defaultTheme = 'stark';

	protected static $modules = ['system', 'user', 'file', 'tripal', 'tripal_chado', 'trpcultivate_germplasm'];

  use GermplasmAccessionImporterTestTrait;

  protected $importer;

  protected $definitions = [
    'test-germplasm-accession' => [
      'id' => 'trpcultivate-germplasm-accession',
      'label' => 'Tripal Cultivate: Germplasm Accessions',
      'description' => 'Imports germplasm accessions into Chado with metadata meeting BrAPI standards.',
      'file_types' => ["tsv", "txt"],
      'use_analysis' => FALSE,
      'require_analysis' => FALSE,
      'upload_title' => 'Germplasm Accession Import',
      'upload_description' => 'This should not be visible!',
      'button_text' => 'Import Germplasm Accessions',
      'file_upload' => True,
      'file_load' => True,
      'file_remote' => True,
      'file_required' => True,
      'cardinality' => 1,
    ],
  ];

	/**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

		// Open connection to Chado
		$this->connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Ensure we can access file_managed related functionality from Drupal.
    // ... users need access to system.action config?
    $this->installConfig(['system', 'trpcultivate_germplasm']);
    // ... managed files are associated with a user.
    $this->installEntitySchema('user');
    // ... Finally the file module + tables itself.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('tripal_chado', ['tripal_custom_tables']);

    // We need to mock the logger to test the progress reporting.
    $container = \Drupal::getContainer();
    $mock_logger = $this->getMockBuilder(\Drupal\tripal\Services\TripalLogger::class)
      ->onlyMethods(['notice','error'])
      ->getMock();
    $mock_logger->method('notice')
       ->willReturnCallback(function($message, $context, $options) {
         print str_replace(array_keys($context), $context, $message);
         return NULL;
       });
    $mock_logger->method('error')
      ->willReturnCallback(function($message, $context, $options) {
        print str_replace(array_keys($context), $context, $message);
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    $this->config_factory = \Drupal::configFactory();
    $this->importer = new \Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmAccessionImporter(
      [],
      'trpcultivate-germplasm-accession',
      $this->definitions,
      $this->connection,
      $this->config_factory
    );

    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');
    $this->importer->setCVterm('accession', 9);
    $this->importer->setCVterm('subtaxa', $subtaxa_cvterm_id);
    $this->importer->setCVterm('institute_code', 10);
    $this->importer->setCVterm('institute_name', 11);
    $this->importer->setCVterm('country_of_origin_code',12);
    $this->importer->setCVterm('biological_status_of_accession_code', 13);
    $this->importer->setCVterm('breeding_method_DbId', 14);
    $this->importer->setCVterm('pedigree', 15);
    $this->importer->setCVterm('synonym', 16);
    $this->importer->setCVterm('stock_relationship_type_synonym', 17);

    $this->createStockSynonymTable();
  }

	/**
   * Tests focusing on the Germplasm Accession Importer form.
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterForm() {

		$plugin_id = 'trpcultivate-germplasm-accession';

    // Build the form using the Drupal form builder.
    $form = \Drupal::formBuilder()->getForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $plugin_id
    );
    // Ensure we are able to build the form.
    $this->assertIsArray($form,
      'We expect the form builder to return a form but it did not.');
    $this->assertEquals('tripal_admin_form_tripalimporter', $form['#form_id'],
      'We did not get the form id we expected.');

    // Now that we have provided a plugin_id, we expect it to have...
    // title matching our importer label.
    $this->assertArrayHasKey('#title', $form,
      "The form should have a title set.");
    $this->assertEquals('Tripal Cultivate: Germplasm Accessions', $form['#title'],
      "The title should match the label annotated for our plugin.");
    // the plugin_id stored in a value form element.
    $this->assertArrayHasKey('importer_plugin_id', $form,
      "The form should have an element to save the plugin_id.");
    $this->assertEquals($plugin_id, $form['importer_plugin_id']['#value'],
      "The importer_plugin_id[#value] should be set to our plugin_id.");
    // a submit button.
    $this->assertArrayHasKey('button', $form,
      "The form should not have a submit button since we indicated a specific importer.");

    // We should also have our importer-specific form elements added to the form!
    $this->assertArrayHasKey('instructions', $form,
      "The form should include an instructions form element.");
		$this->assertArrayHasKey('genus_name', $form,
      "The form should include a genus_name form element.");
		$this->assertArrayHasKey('file', $form,
      "The form should include a file form element.");

    // Our default annotation indicates there should be no analysis element.
    $this->assertArrayNotHasKey('analysis_id', $form,
      "The from should not include analysis element, yet one exists.");
	}

  /**
   * Tests focusing on the Germplasm Accession Importer getOrganismID() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterGetOrganismID() {

    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    $grabbed_organism_id = $this->importer->getOrganismID('Tripalus', 'databasica', 'subspecies chadoii');
    $this->assertEquals($grabbed_organism_id, $organism_id, "The organism ID grabbed by the importer does not match the one that was inserted into the database.");

    // Try an organism that does not currently exist
    ob_start();
    $non_existent_organism_id = $this->importer->getOrganismID('Nullus', 'organismus', '');
    $printed_output = ob_get_clean();
    $this->assertEquals('Could not find an organism "Nullus organismus" in the database.', $printed_output,
      "Did not get the expected error message when testing for a non-existant organism.");

    // Not testing if multiple organisms are retrieved, since Chado should be preventing such a situation
  }

  /**
   * Tests focusing on the Germplasm Accession Importer getStockID() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterGetStockID() {

    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    // Insert a stock
    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => 9,
      ])
      ->execute();

    // Test that the stock just inserted gets selected
    $grabbed_stock_id = $this->importer->getStockID('stock1', 'TEST:1', $organism_id);
    $this->assertEquals($grabbed_stock_id, $stock_id, "The stock ID grabbed by the importer does not match the one that was inserted into the database.");

    // Test that a stock not in the database successfully gets inserted
    ob_start();
    $created_stock_id = $this->importer->getStockID('stock2', 'TEST:2', $organism_id);
    $printed_output = ob_get_clean();
    $this->assertEquals('Inserting "stock2".', $printed_output,
      "Did not get the expected notice message when inserting a new stock.");

    $stock2_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['stock_id'])
      ->condition('organism_id', $organism_id, '=')
      ->condition('name', 'stock2', '=')
      ->condition('uniquename', 'TEST:2', '=')
      ->condition('type_id', 9, '=');
    $stock2_record = $stock2_query->execute()->fetchAll();
    $this->assertEquals($created_stock_id, $stock2_record[0]->stock_id, "The stock ID inserted for \"stock2\" does not match the stock ID returned by getStockID().");

    // No test for if the insert fails, since most likely will get a complaint from Chado

    // Test for a stock name + organism that already exists but has a different accession
    ob_start();
    $grabbed_dup_stock_name = $this->importer->getStockID('stock1', 'TEST:1000', $organism_id);
    $printed_output = ob_get_clean();
    $this->assertEquals('A stock already exists for "stock1" but with an accession of "TEST:1" which does not match the input file.', $printed_output,
      "Did not get the expected error message when testing for duplicate stock names.");

    // Now test for multiple stocks with the same name and accession, but different type_id
    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => 10,
      ])
      ->execute();

    ob_start();
    $grabbed_dup_stock_id = $this->importer->getStockID('stock1', 'TEST:1', $organism_id);
    $printed_output = ob_get_clean();
    $this->assertStringContainsString('Found more than one stock ID for "stock1"', $printed_output, "Did not get the expected error message when testing for duplicate stock IDs.");
  }

  /**
   * Tests focusing on the Germplasm Accession Importer getDbxrefID() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterGetDbxrefID() {

    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    // Insert a stock
    $accession = 'TEST:1';

    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => $accession,
        'type_id' => 9,
      ])
      ->execute();

    // Attempt to call the function before inserting an external database
    ob_start();
    $non_existing_external_db = $this->importer->getDbxrefID('PRETEND', $stock_id, $accession);
    $printed_output = ob_get_clean();
    $this->assertEquals('Unable to find "PRETEND" in chado.db.', $printed_output,
      "Did not get the expected error message when looking up an external database that does not yet exist.");

    // Verify that the stock has an empty dbxref_id
    $empty_stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['dbxref_id']);
    $empty_stock_query->condition('s.stock_id', $stock_id, '=');
    $empty_stock_record = $empty_stock_query->execute()->fetchAll();

    $this->assertEmpty($empty_stock_record[0]->dbxref_id, "The stock just inserted (stock1) already has a dbxref_id.");

    // Now add an external db
    $db_id = $this->connection->insert('1:db')
      ->fields([
        'name' => 'Test DB',
      ])
      ->execute();

    // ----------------------------- ROUND 1 -------------------------------
    // Call the function and check that dbxref is inserted and stock updated
    $round_one_dbxref = $this->importer->getDbxrefID('Test DB', $stock_id, $accession);

    // Check that the dbxref was inserted successfully
    $r1_dbx_query = $this->connection->select('1:dbxref', 'dbx')
      ->fields('dbx', ['dbxref_id']);
    $r1_dbx_query->condition('dbx.accession', $accession, '=')
      ->condition('dbx.db_id', $db_id, '=');
    $r1_dbx_record = $r1_dbx_query->execute()->fetchAll();

    $this->assertEquals($round_one_dbxref, $r1_dbx_record[0]->dbxref_id, "The dbxref_id that was inserted does not match what was queried.");

    // Check that the stock was updated successfully
    $updated_stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['dbxref_id']);
    $updated_stock_query->condition('s.stock_id', $stock_id, '=');
    $updated_stock_record = $updated_stock_query->execute()->fetchAll();

    $this->assertEquals($round_one_dbxref, $updated_stock_record[0]->dbxref_id, "The stock just inserted (stock1) was not successfully updated with a dbxref_id.");

    // ----------------------------- ROUND 2 -------------------------------
    // Call the function again and check that the results are still the same
    // The purpose of this test is to trigger the elseif statements for both
    // the dbxref check and stock.dbxref_id
    $round_two_dbxref = $this->importer->getDbxrefID('Test DB', $stock_id, $accession);

    // Check that the dbxref was selected successfully
    $r2_dbx_query = $this->connection->select('1:dbxref', 'dbx')
      ->fields('dbx', ['dbxref_id']);
    $r2_dbx_query->condition('dbx.accession', $accession, '=')
      ->condition('dbx.db_id', $db_id, '=');
    $r2_dbx_record = $r2_dbx_query->execute()->fetchAll();

    $this->assertEquals($round_two_dbxref, $r2_dbx_record[0]->dbxref_id, "The dbxref_id has changed unexpectedly in round 2.");

    // Check that the stock was updated successfully
    $r2_updated_stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['dbxref_id']);
    $r2_updated_stock_query->condition('s.stock_id', $stock_id, '=');
    $r2_updated_stock_record = $r2_updated_stock_query->execute()->fetchAll();

    $this->assertEquals($round_two_dbxref, $r2_updated_stock_record[0]->dbxref_id, "The dbxref_id of stock1 was unexpectedly changed in round 2 from round 1.");
    // ---------------------------------------------------------------------
    // Manually insert a dbxref with the same accession but a different db_id
    $second_db_name = 'Second Test DB';
    $second_db_id = $this->connection->insert('1:db')
      ->fields([
        'name' => $second_db_name,
      ])
      ->execute();

    $second_dbxref_id = $this->connection->insert('1:dbxref')
      ->fields([
        'db_id' => $second_db_id,
        'accession' => $accession
      ])
      ->execute();

    ob_start();
    $multiple_dbxref_accessions = $this->importer->getDbxrefID($second_db_name, $stock_id, $accession);
    $printed_output = ob_get_clean();
    $this->assertEquals('There is already a primary dbxref_id for stock ID "1" that does not match the external database and accession provided in the file (Second Test DB:TEST:1).', $printed_output,
      "Did not get the expected error message when inserting a dbxref with an existing accession with a different db.");
  }

  /**
   * Tests focusing on the Germplasm Accession Importer loadStockProperties() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterLoadStockProperties() {
    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    // Insert a stock
    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => 9,
      ])
      ->execute();

    // Declare our stock property variables with empty values
    $empty_string = '';
    $stock_empty_props = [
      'institute_code' => $empty_string,
      'institute_name' => $empty_string,
      'country_of_origin_code' => $empty_string,
      'biological_status_of_accession_code' => $empty_string,
      'breeding_method_DbId' => $empty_string,
      'pedigree' => $empty_string
    ];

    // "Load" our empty values and check that the stockprop table is empty
    // before and after
    $sp_initial_count = $this->connection->select('1:stockprop', 'sp')
      ->condition('sp.stock_id', $stock_id, '=')
      ->countQuery()->execute()->fetchField();
    $this->importer->loadStockProperties($stock_id, $stock_empty_props);
    $sp_empty_count = $this->connection->select('1:stockprop', 'sp')
      ->condition('sp.stock_id', $stock_id, '=')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($sp_initial_count, $sp_empty_count, "The row count of the stockprop table before and after inserting empty values is not the same.");

    // Now load in all 6 stock properties for this stock_id
    $stock_props = [
      'institute_code' => 'CUAC',
      'institute_name' => 'Crop Development Center, University of Saskatchewan',
      'country_of_origin_code' => 124,
      'biological_status_of_accession_code' => 410,
      'breeding_method_DbId' => 'Recurrent selection',
      'pedigree' => '1049F^3/819-5R'
    ];
    $stock_prop_count = count($stock_props);

    $this->importer->loadStockProperties($stock_id, $stock_props);
    $sp_six_count = $this->connection->select('1:stockprop', 'sp')
      ->condition('sp.stock_id', $stock_id, '=')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($sp_six_count, $stock_prop_count, "The row count of the stockprop table after inserting 6 values is not correct.");

    // Select a random property to see if it inserted correctly
    $sp_six_institute_name = $this->connection->select('1:stockprop', 'sp')
      ->fields('sp', ['value'])
      ->condition('sp.stock_id', $stock_id, '=')
      ->condition('sp.type_id', 11, '=');
    $sp_six_institute_name_record = $sp_six_institute_name->execute()->fetchAll();
    $this->assertEquals($sp_six_institute_name_record[0]->value,'Crop Development Center, University of Saskatchewan', "The selected stockprop value for institute name does not match what was inserted.");

    // Now add some new properties for the same stock_id
    $new_stock_props = [
      'biological_status_of_accession_code' => 500, // New value
      'breeding_method_DbId' => 'Breeder line', // New value
      'pedigree' => '1049F^3/819-5R' // Old value
    ];
    $new_sp_count = count($new_stock_props);
    // 2 should be added to the stockprop table, 1 should not
    $total_expected_sp_count = $sp_six_count + $new_sp_count - 1;

    $this->importer->loadStockProperties($stock_id, $new_stock_props);
    $sp_eight_count = $this->connection->select('1:stockprop', 'sp')
      ->condition('sp.stock_id', $stock_id, '=')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($sp_eight_count, $total_expected_sp_count, "The row count of the stockprop table after inserting 2 additional values is not correct.");

    // Select one of our new properties and compare the ranks
    $sp_eight_breeding_method_DbId = $this->connection->select('1:stockprop', 'sp')
      ->fields('sp', ['value', 'rank'])
      ->condition('sp.stock_id', $stock_id, '=')
      ->condition('sp.type_id', 14, '=');
    $sp_eight_breeding_method_DbId_record = $sp_eight_breeding_method_DbId->execute()->fetchAll();
    $first_value = $sp_eight_breeding_method_DbId_record[0]->value;
    $this->assertEquals($first_value,'Recurrent selection', "The selected stockprop value for the first breeding_method_db_id does not match what was inserted.");
    $second_value = $sp_eight_breeding_method_DbId_record[1]->value;
    $this->assertEquals($second_value,'Breeder line', "The selected stockprop value for the second breeding_method_db_id does not match what was inserted.");

    $first_rank = $sp_eight_breeding_method_DbId_record[0]->rank;
    $second_rank = $sp_eight_breeding_method_DbId_record[1]->rank;
    $this->assertGreaterThan($first_rank, $second_rank, "The rank of the second inserted stockprop for breeding_method_db_id is not greater than the first one.");

    // Ensure only one record is retrieved for pedigree since the second array
    // contained an identical value for it
    $sp_eight_pedigree_count = $this->connection->select('1:stockprop', 'sp')
      ->condition('sp.stock_id', $stock_id, '=')
      ->condition('sp.type_id', 15, '=')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($sp_eight_pedigree_count, 1, "The number of records for stockprop pedigree is not 1.");
  }

  /**
   * Tests focusing on the Germplasm Accession Importer loadSynonyms() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterLoadSynonyms() {

    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    // Insert a stock
    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => 9,
      ])
      ->execute();

    // Attempt to load an empty string (ie. an empty column in the file)
    $stock1_synonym_empty = '';
    $this->importer->loadSynonyms($stock_id, $stock1_synonym_empty, $organism_id);

    // Make sure no synonyms were entered
    $synonym_empty_count = $this->connection->select('1:synonym', 's')
      ->fields('s', ['name'])
      ->condition('s.name', $stock1_synonym_empty, '=')
      ->countQuery()->execute()->fetchField();

    $this->assertEquals($synonym_empty_count, 0, "The number of record in the synonym table is not zero despite trying to add an empty string.");

    // ------------------------------------------------------------------------
    // Load a single synonym
    $stock1_synonym_1 = 's1';
    ob_start();
    $this->importer->loadSynonyms($stock_id, $stock1_synonym_1, $organism_id);
    $printed_output = ob_get_clean();

    // STEP 1: Check the synonym table
    $stock1_synonym_query = $this->connection->select('1:synonym', 's')
      ->fields('s', ['synonym_id', 'name'])
      ->condition('s.name', $stock1_synonym_1, '=');
    $stock1_synonym_record = $stock1_synonym_query->execute()->fetchAll();

    $this->assertEquals($stock1_synonym_record[0]->name, $stock1_synonym_1, "The selected synonym in the synonym table does not match what was just inserted.");

    // STEP 2: Check the stock_synonym table
    // Grab the synonym_id to pull it out of the stock_synonym table
    $s1_synonym_id = $stock1_synonym_record[0]->synonym_id;

    $stock1_stock_synonym_query = $this->connection->select('1:stock_synonym', 'ss')
      ->fields('ss', ['stock_id', 'synonym_id'])
      ->condition('ss.synonym_id', $s1_synonym_id, '=');
    $stock1_stock_synonym_record = $stock1_stock_synonym_query->execute()->fetchAll();

    $this->assertEquals($stock1_stock_synonym_record[0]->stock_id, $stock_id, "The synonym s1 in the stock_synonym table does not contain the correct stock_id.");

    // STEP 3: Check the stock_relationship table. We expect to have 0 records
    // since the stock_relationship only gets created if the synonym itself is
    // in the stock table
    $stock1_stock_relationship_count = $this->connection->select('1:stock_relationship', 'sr')
      ->fields('sr', ['subject_id', 'object_id'])
      ->condition('sr.subject_id', $s1_synonym_id, '=')
      ->condition('sr.object_id', $stock_id, '=')
      ->countQuery()->execute()->fetchField();

    $this->assertEquals($stock1_stock_relationship_count, 0, "Did not expect for one or more stock_relationships to be present in the stock_relationship table.");

    // We can also check the output of our command as we expect a notice to be given
    $this->assertEquals('Synonym "s1" was not found in the stock table, so no stock_relationship was made with stock ID "1".', $printed_output,
      "Did not get the expected notice message when adding a synonym that does not exist in the stock table.");

    // ------------------------------------------------------------------------

    // Attempt to insert another synonym for stock1. This time, also add the
    // synonym to the stock table and confirm that we have a stock_relationship
    $stock1_synonym_2 = 's1_2';
    $stock_id_of_synonym = $this->connection->insert('1:stock')
    ->fields([
      'organism_id' => $organism_id,
      'name' => $stock1_synonym_2,
      'uniquename' => 'TEST:2',
      'type_id' => 9,
    ])
    ->execute();

    $this->importer->loadSynonyms($stock_id, $stock1_synonym_2, $organism_id);

    // Make sure this synonym was added to chado.synonym
    $stock1_synonym_2_query = $this->connection->select('1:synonym', 's')
      ->fields('s', ['name'])
      ->condition('s.name', $stock1_synonym_2, '=');
    $stock1_synonym_2_record = $stock1_synonym_2_query->execute()->fetchAll();

    $this->assertEquals($stock1_synonym_2_record[0]->name, $stock1_synonym_2, "The second synonym added to the synonym table does not match what was just inserted.");

    // Check that we now have 2 records in chado.stock_synonym
    $stock_synonym_count = $this->connection->select('1:stock_synonym', 'ss')
      ->fields('ss', ['stock_synonym_id'])
      ->countQuery()->execute()->fetchField();

    $this->assertEquals($stock_synonym_count, 2, "The stock_synonym table does not contain the expected 2 records.");

    // Check that we have exactly 1 record in the stock_relationship table now
    $stock1_stock_relationship_synonym2_count = $this->connection->select('1:stock_relationship', 'sr')
      ->fields('sr', ['subject_id', 'object_id'])
      ->condition('sr.subject_id', $stock_id_of_synonym, '=')
      ->condition('sr.object_id', $stock_id, '=')
      ->condition('sr.type_id', $this->importer->getCVterm('stock_relationship_type_synonym', '='))
      ->countQuery()->execute()->fetchField();

    $this->assertEquals($stock1_stock_relationship_synonym2_count, 1, "Did not count the expected single stock_relationship between stock1 and its 2nd synonym, s1_2.");

    // Add a comma separated list of synonyms
    $stock_comma = 'stock-comma';
    $stock_comma_id = $this->connection->insert('1:stock')
    ->fields([
      'organism_id' => $organism_id,
      'name' => $stock_comma,
      'uniquename' => 'TEST:3',
      'type_id' => 9,
    ])
    ->execute();

    $synonyms_comma = 'syn1, syn2';
    $syn1 = 'syn1';
    $syn2 = 'syn2';
    ob_start();
    $this->importer->loadSynonyms($stock_comma_id, $synonyms_comma, $organism_id);
    ob_end_clean();

    // Check both synonyms are in chado.synonym
    $synonyms_comma_query = $this->connection->select('1:synonym', 's')
      ->fields('s', ['name']);
    $group = $synonyms_comma_query->orConditionGroup()
      ->condition('s.name', $syn1, '=')
      ->condition('s.name', $syn2, '=');
    $synonyms_comma_query->condition($group);
    $synonyms_comma_record = $synonyms_comma_query->execute()->fetchAll();

    $this->assertEquals($synonyms_comma_record[0]->name, $syn1, "The synonym table does not contain the expected synonym syn1");
    $this->assertEquals($synonyms_comma_record[1]->name, $syn2, "The synonym table does not contain the expected synonym syn2");

    // Add a semicolon separated list of synonyms
    $stock_semicolon = 'stock-semicolon';
    $stock_semicolon_id = $this->connection->insert('1:stock')
    ->fields([
      'organism_id' => $organism_id,
      'name' => $stock_semicolon,
      'uniquename' => 'TEST:4',
      'type_id' => 9,
    ])
    ->execute();

    $synonyms_semicolon = 'syn3;syn4';
    $syn3 = 'syn3';
    $syn4 = 'syn4';
    ob_start();
    $this->importer->loadSynonyms($stock_semicolon_id, $synonyms_semicolon, $organism_id);
    ob_end_clean();

    // Check both synonyms are in chado.synonym
    $synonyms_semicolon_query = $this->connection->select('1:synonym', 's')
      ->fields('s', ['name']);
    $group = $synonyms_semicolon_query->orConditionGroup()
      ->condition('s.name', $syn3, '=')
      ->condition('s.name', $syn4, '=');
    $synonyms_semicolon_query->condition($group);
    $synonyms_semicolon_record = $synonyms_semicolon_query->execute()->fetchAll();

    $this->assertEquals($synonyms_semicolon_record[0]->name, $syn3, "The synonym table does not contain the expected synonym syn3");
    $this->assertEquals($synonyms_semicolon_record[1]->name, $syn4, "The synonym table does not contain the expected synonym syn4");

    // @todo: Test the case when a stock_relationship previously exists

    // Do my final queries to ensure everything we expect to be in the tables is there
    // stock table
    // $stock_table_query = $this->connection->select('1:stock', 'st')
    //   ->fields('st', ['stock_id', 'name', 'uniquename', 'organism_id']);
    // $stock_table_records = $stock_table_query->execute();
    // $stock_table_array_actual = $stock_table_records->fetchAllAssoc('stock_id');
    // print_r($stock_table_records->fetchAllAssoc('stock_id'));
  }
}
