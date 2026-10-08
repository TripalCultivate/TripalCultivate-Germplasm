<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoOrganismBuddy;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoStockBuddy;
use Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCrossImporter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests functionality of the run() method of Germplasm Cross Importer.
 *
 * @group tripal-importer
 * @group chado-importer
 * @group importer-germplasmcross
 */
#[Group('tripal-importer')]
#[Group('chado-importer')]
#[Group('importer-germplasmcross')]
#[RunTestsInSeparateProcesses]
class GermplasmCrossImporterRunTest extends ChadoTestKernelBase {

  use UserCreationTrait;
  use TripalCultivateImporterTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'tripal',
    'tripal_chado',
    'tripal_layout',
    'trpcultivate',
    'trpcultivate_germplasm',
  ];

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * An instance of the organism Chado Buddy.
   *
   * @var Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoOrganismBuddy
   */
  protected ChadoOrganismBuddy $organism_buddy;

  /**
   * An instance of the stock Chado Buddy.
   *
   * @var Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoStockBuddy
   */
  protected ChadoStockBuddy $stock_buddy;

  /**
   * Our instance of the Cross Importer for testing.
   *
   * @var Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCrossImporter
   */
  protected GermplasmCrossImporter $importer;

  /**
   * A default listing of annotations associated with our importer.
   *
   * @var array
   */
  protected array $definitions = [
    'test-cross-importer' => [
      'id' => 'trpcultivate-germplasm-cross-importer',
      'label' => 'Tripal Cultivate: Germplasm Cross Importer',
      'description' => 'Loads germplasm crosses into the system. This is useful for large datasets to ease the upload process.',
      'file_types' => ["tsv"],
      'use_analysis' => FALSE,
      'require_analysis' => FALSE,
      'upload_title' => 'Germplasm Cross Data File*',
      'upload_description' => 'This should not be visible!',
      'button_text' => 'Import',
      'file_upload' => TRUE,
      'file_load' => FALSE,
      'file_remote' => FALSE,
      'file_required' => FALSE,
      'cardinality' => 1,
    ],
  ];

  /**
   * The path to tripalcultivate_germplasm module.
   *
   * @var string
   */
  private string $module_path;

  /**
   * {@inheritDoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Open connection to Chado.
    $this->chado_connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Ensure we can access file_managed related functionality from Drupal.
    // ... users need access to system.action config?
    $this->installConfig(['system', 'trpcultivate_germplasm', 'trpcultivate']);
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
    $mock_logger = $this->getMockBuilder(TripalLogger::class)
      ->onlyMethods(['error'])
      ->getMock();

    $mock_logger->method('error')
      ->willReturnCallback(function ($message, $context, $options) {
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    $this->module_path = $this->container->get('module_handler')
      ->getModule('trpcultivate_germplasm')
      ->getPath();

    $buddy_manager = $container->get('tripal_chado.chado_buddy');

    $this->importer = new GermplasmCrossImporter(
      [],
      'trpcultivate-germplasm-cross-importer',
      $this->definitions,
      $this->chado_connection,
      $buddy_manager,
      $container->get('plugin.manager.trpcultivate_validator'),
      $container->get('trpcultivate.template_generator'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
      $container->get('tripal.logger'),
      $container->get('tripal.fileretriever'),
      $container->get('tripal.backend_publish'),
    );

    // Insert an organism.
    $buddy_service = \Drupal::service('tripal_chado.chado_buddy');
    $this->organism_buddy = $buddy_service->createInstance('chado_organism_buddy', []);
    $organism_record = $this->organism_buddy->insertOrganism([
      'organism.genus' => 'Tripalus',
      'organism.species' => 'databasica',
    ]);
    $organism_id = $organism_record->getValue('organism.organism_id');
    $this->assertIsNumeric($organism_id, 'We were not able to create an organism for testing');

    // Insert a Program ID.
    // Use a CVterm ChadoBuddy to get the cvterm_id for inserting Program IDs.
    $cvterm_buddy = $buddy_service->createInstance('chado_cvterm_buddy', []);
    $cvterm_record = $cvterm_buddy->getCvterm([
      'cv.name' => 'rdfs',
      'cvterm.name' => 'type',
    ]);
    $cvterm_id = $cvterm_record[0]->getValue('cvterm.cvterm_id');
    $this->assertIsNumeric($cvterm_id,
    'We were not able to get the cvterm_id we need for creating Program ID dbprop records.');

    // Use a dbxref buddy to get the db_id for inserting Program IDs.
    $program_name = 'Test Program';
    $dbxref_buddy = $buddy_service->createInstance('chado_dbxref_buddy', []);
    $program_record = $dbxref_buddy->insertDb([
      'db.name' => $program_name,
    ]);
    $program_record_id = $program_record->getValue('db.db_id');
    $this->assertIsNumeric($program_record_id,
      'We were not able to create the program "' . $program_name . '" in the db table for testing.');
    // Create the dbprop record and link it to the db record.
    $dbprop_id = $this->chado_connection->insert('1:dbprop')
      ->fields([
        'db_id' => $program_record_id,
        'type_id' => $cvterm_id,
        'value' => 'Program ID',
      ])
      ->execute();
    $this->assertIsNumeric($dbprop_id,
          'We were not able to create the dbprop record for program "' . $program_name . '" for testing.');
  }

  /**
   * Tests the Germplasm Cross Importer run() using a simple example file.
   */
  public function testRunWithSimpleExampleFile() {

    $filename = 'crosses_simple.tsv';
    $file = $this->createTestFile([
      'filename' => $filename,
      'content' => [
        'file' => $filename,
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/CrossImporterFiles/',
      ],
    ]);

    $run_args = [
      'genus' => 'Tripalus',
      'program_id' => 'Test Program',
    ];

    $file_details = ['fid' => $file->id()];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    try {
      $this->importer->run();
    }
    catch (\Exception $e) {
    }
  }

}
