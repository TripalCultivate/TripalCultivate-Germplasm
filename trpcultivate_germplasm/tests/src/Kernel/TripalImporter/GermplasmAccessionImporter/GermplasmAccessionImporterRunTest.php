<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Core\Url;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\trpcultivate_germplasm\Traits\GermplasmAccessionImporterTestTrait;

/**
 * Tests the functionality of the Germplasm Accession Importer.
 */
class GermplasmAccessionImporterRunTest extends ChadoTestKernelBase {

	protected $defaultTheme = 'stark';

	protected static $modules = ['system', 'user', 'file', 'tripal', 'tripal_chado', 'trpcultivate_germplasm'];

  use UserCreationTrait;
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

  // Make the organism ID accessible by all the functions
  public $organism_id;

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
    // Ensure we have our tripal import tables.
    $this->installSchema('tripal', ['tripal_import', 'tripal_jobs']);
    // Create and log-in a user.
    $this->setUpCurrentUser();

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

    // Create the stock_synonym table
    $this->createStockSynonymTable();

    // Insert our organism
    $subtaxa_cvterm_id = $this->importer->getCVterm('subtaxa');
    $this->organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    // Insert an external db
    $db_id = $this->connection->insert('1:db')
    ->fields([
      'name' => 'TestDB',
    ])
    ->execute();
  }

  /**
   * Tests focusing on the Germplasm Accession Importer run() function
   * using a simple example file that only populates required columns
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterRunSimple() {

    $simple_example_file = __DIR__ . '/../../../Fixtures/simple_example.txt';

    $genus = 'Tripalus';
    $run_args = ['genus_name' => $genus];
    $file_details = ['file_local' => $simple_example_file];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    ob_start();
    $this->importer->run();
    $printed_output = ob_get_clean();
    $this->assertStringContainsString('Inserting "Test2".', $printed_output, "Did not get the expected output when running the run() method on simple_example.txt.");

    // Now check the db for our 2 new stocks
    $stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['organism_id', 'name', 'uniquename', 'type_id']);
    $stock_record = $stock_query->execute()->fetchAll();

    // Stock: Test1
    $this->assertEquals($stock_record[0]->organism_id, $this->organism_id, "The inserted organism ID and the selected organism ID for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->name, 'Test1', "The inserted stock.name and the selected name for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->uniquename, 'T1', "The inserted stock.uniquename and the selected uniquename for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->type_id, 9, "The inserted type_id and the selected type_id for stock Test1 don't match.");
    // Stock: Test2
    $this->assertEquals($stock_record[1]->organism_id, $this->organism_id, "The inserted organism ID and the selected organism ID for stock Test2 don't match.");
    $this->assertEquals($stock_record[1]->name, 'Test2', "The inserted stock.name and the selected name for stock Test2 don't match.");
    $this->assertEquals($stock_record[1]->uniquename, 'T2', "The inserted stock.uniquename and the selected uniquename for stock Test2 don't match.");
    $this->assertEquals($stock_record[1]->type_id, 9, "The inserted type_id and the selected type_id for stock Test2 don't match.");

    // Make sure that the stockprop and synonyms table are empty
    $stockprop_count_query = $this->connection->select('1:stockprop', 'sp')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($stockprop_count_query, 0, "The row count of the stockprop table is not empty, despite there be no stock properties to insert from simple_example.txt.");

    $synonym_count_query = $this->connection->select('1:synonym', 'syn')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($synonym_count_query, 0, "The row count of the synonym table is not empty, despite there be no syonyms to insert from simple_example.txt.");
  }

  /**
   * Tests focusing on the Germplasm Accession Importer run() function
   * using a file where some required columns are missing
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterRunMissing() {

    $problem_example_file = __DIR__ . '/../../../Fixtures/missing_required_example.txt';

    $genus = 'Tripalus';
    $run_args = ['genus_name' => $genus];
    $file_details = ['file_local' => $problem_example_file];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();

    // Need a try-catch since errors in this file will trigger the error flag exception
    $exception_caught = FALSE;
    try {
      ob_start();
      $this->importer->run();
    }
    catch ( \Exception $e ) {
      $exception_caught = TRUE;
    }
    $printed_output = ob_get_clean();
    $this->assertTrue($exception_caught, 'Did not catch exception that should have occurred due to missing required columns.');
    $this->assertStringContainsString('Column 2 is required and cannot be empty for line # 7', $printed_output, "Did not get the expected output regarding line #7 when running the run() method on missing_required_example.txt.");
    $this->assertStringContainsString('Insufficient number of columns detected (<4) for line # 8', $printed_output, "Did not get the expected output regarding line #8 when running the run() method on missing_required_example.txt.");

    // Double check that neither germplasm made it to the database
    $stock_count_query = $this->connection->select('1:stock', 's')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($stock_count_query, 0, "The row count of the stock table is not empty, despite expecting to skip stocks in missing_required_example.txt.");
  }

  /**
   * Tests focusing on the Germplasm Accession Importer run() function
   * using a more complicated file where some optional columns are specified
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterRunComplex() {

    $problem_example_file = __DIR__ . '/../../../Fixtures/props_syns_example.txt';

    $genus = 'Tripalus';
    $run_args = ['genus_name' => $genus];
    $file_details = ['file_local' => $problem_example_file];

    $stock_type_id = $this->importer->getCVterm('accession');
    $stockprop_bsoac_type_id = $this->importer->getCVterm('biological_status_of_accession_code');
    $stockprop_bmDbId_type_id = $this->importer->getCVterm('breeding_method_DbId');
    $stock_relationship_type_id = $this->importer->getCVterm('stock_relationship_type_synonym');

    // Insert one of the synonyms in our test file into the database as a stock
    // This way we can ensure a stock_relationship record is created
    $stock_id_of_synonym = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $this->organism_id,
        'name' => 'synonym2',
        'uniquename' => 'synonym2',
        'type_id' => $stock_type_id,
      ])
      ->execute();

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    ob_start();
    $this->importer->run();
    $printed_output = ob_get_clean();
    $this->assertStringContainsString('Inserting "Test5".Synonym "synonym1" was not found in the stock table, so no stock_relationship was made with stock ID "2".Synonym "synonym3" was not found in the stock table, so no stock_relationship was made with stock ID "2".', $printed_output, "Did not get the expected output regarding synonyms when running the run() method on props_syns_example.txt.");

    // Now check that the stock properties inserted correctly
    $stockprop_count_query = $this->connection->select('1:stockprop', 'sp')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($stockprop_count_query, 2, "The row count of the stockprop table after inserting 2 stock properties values is not correct.");

    // Grab the stock ID
    $stock_query = $this->connection->select('1:stock', 's')
      ->fields('s', ['stock_id'])
      ->condition('name', 'Test5');
    $stock_record = $stock_query->execute()->fetchAll();
    $stock_id = $stock_record[0]->stock_id;

    $stockprop_query = $this->connection->select('1:stockprop', 'sp')
      ->fields('sp', ['stock_id', 'type_id', 'value']);
    $stockprop_records = $stockprop_query->execute()->fetchAll();
    $this->assertEquals($stockprop_records[0]->stock_id, $stock_id, 'The inserted stock_id and the existing stock_id for the first stockprop does not match for stock Test5.');
    $this->assertEquals($stockprop_records[0]->type_id, $stockprop_bsoac_type_id, 'The inserted type_id and the existing type_id for the first stockprop does not match for stock Test5.');
    $this->assertEquals($stockprop_records[0]->value, 500, 'The value of the inserted stock property "Biological Status of Accession" does not match what was in the file for stock Test5.');
    $this->assertEquals($stockprop_records[1]->stock_id, $stock_id, 'The inserted stock_id and the existing stock_id for the second stockprop does not match for stock Test5.');
    $this->assertEquals($stockprop_records[1]->type_id, $stockprop_bmDbId_type_id, 'The inserted type_id and the existing type_id for the second stockprop does not match for stock Test5.');
    $this->assertEquals($stockprop_records[1]->value, 'Breeder line', 'The value of the inserted stock property "Breeding Method" does not match what was in the file for stock Test5.');
    
    // Lastly, check on our synonyms
    // Count number of synonyms in the synonym table
    $synonym_count_query = $this->connection->select('1:synonym', 'sy')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($synonym_count_query, 3, "Expected there to be 3 synonyms in the synonym table after inserting stock Test5.");

    // Count the number of records in stock_synonym
    $stock_synonym_count_query = $this->connection->select('1:stock_synonym', 'ssy')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($stock_synonym_count_query, 3, "Expected there to be 3 records in the stock_synonym table after inserting stock Test5.");

    // Count the number of record in stock_relationship
    $stock_relationship_count_query = $this->connection->select('1:stock_relationship', 'sr')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($stock_relationship_count_query, 1, "Expected there to be 1 record in the stock_relationship table after inserting stock Test5.");

    $stock_relationship_query = $this->connection->select('1:stock_relationship', 'sr')
      ->fields('sr', ['subject_id', 'object_id', 'type_id', 'value']);
    $stock_relationship_record = $stock_relationship_query->execute()->fetchAll();
    $this->assertEquals($stock_relationship_record[0]->subject_id, $stock_id_of_synonym, 'The subject ID of the stock_relationship that was inserted is not the expected stock ID of synonym2');
    $this->assertEquals($stock_relationship_record[0]->object_id, $stock_id, 'The object ID of the stock_relationship that was inserted is not the expected stock ID of Test5');
    $this->assertEquals($stock_relationship_record[0]->type_id, $stock_relationship_type_id, 'The type ID of the stock_relationship that was inserted is not of type synonym');
  }
}
