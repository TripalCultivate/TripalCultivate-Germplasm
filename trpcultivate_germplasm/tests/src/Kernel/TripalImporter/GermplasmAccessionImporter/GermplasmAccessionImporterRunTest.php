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
    $organism_id = $this->connection->insert('1:organism')
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
    $this->assertEquals($stock_record[0]->organism_id, $organism_id, "The inserted organism ID and the selected organism ID for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->name, 'Test1', "The inserted stock.name and the selected name for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->uniquename, 'T1', "The inserted stock.uniquename and the selected uniquename for stock Test1 don't match.");
    $this->assertEquals($stock_record[0]->type_id, 9, "The inserted type_id and the selected type_id for stock Test1 don't match.");
    // Stock: Test2
    $this->assertEquals($stock_record[1]->organism_id, $organism_id, "The inserted organism ID and the selected organism ID for stock Test2 don't match.");
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
    ob_start();
    $this->importer->run();
    $printed_output = ob_get_clean();
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

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    ob_start();
    $this->importer->run();
    $printed_output = ob_get_clean();
    //$this->assertStringContainsString('Column 2 is required and cannot be empty for line # 7', $printed_output, "Did not get the expected output regarding line #7 when running the run() method on missing_required_example.txt.");
    //$this->assertStringContainsString('Insufficient number of columns detected (<4) for line # 8', $printed_output, "Did not get the expected output regarding line #8 when running the run() method on missing_required_example.txt.");
  }
}
