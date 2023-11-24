<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Core\Url;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use \Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Tests the functionality of the Germplasm Accession Importer.
 */
class GermplasmAccessionImporterRunTest extends ChadoTestKernelBase {

	protected $defaultTheme = 'stark';

	protected static $modules = ['system', 'user', 'file', 'tripal', 'tripal_chado', 'trpcultivate_germplasm'];

  use UserCreationTrait;

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
  }
  
  /**
   * Tests focusing on the Germplasm Accession Importer run() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterRun() {
    
    // Test 1: Test using a file with only the required columns
    $simple_example_file = __DIR__ . '/../../../Fixtures/simple_example.txt';

    $genus = 'Tripalus';
    $run_args = ['genus_name' => $genus];
    $file_details = ['file_local' => $simple_example_file];
    
    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    //$this->importer->run();

  }
}