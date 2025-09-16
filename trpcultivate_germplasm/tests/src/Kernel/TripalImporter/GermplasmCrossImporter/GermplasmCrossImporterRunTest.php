<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter\GermplasmCrossImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCrossImporter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the functionality of the run() method of the Cross Importer.
 *
 * @group crossImporter
 */
#[Group('crossImporter')]
class GermplasmCrossImporterRunTest extends ChadoTestKernelBase {

  use UserCreationTrait;
  use TripalCultivateImporterTestTrait;

  /**
   * Theme used in the test environment.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

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
   * Our instance of the Cross Importer for testing.
   *
   * @var Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCrossImporter
   */
  protected GermplasmCrossImporter $importer;

  /**
   * The organism ID used by this instance of the Cross Importer for testing.
   *
   * @var int
   */
  protected int $organism_id;

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
  private $module_path;

  /**
   * {@inheritdoc}
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
      ->onlyMethods(['notice', 'error'])
      ->getMock();
    $mock_logger->method('notice')
      ->willReturnCallback(function ($message, $context, $options) {
         print str_replace(array_keys($context), $context, $message);
         return NULL;
      });
    $mock_logger->method('error')
      ->willReturnCallback(function ($message, $context, $options) {
        print str_replace(array_keys($context), $context, $message);
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    // Create our organism.
    $this->organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
      ])
      ->execute();
    $this->assertIsNumeric($this->organism_id,
      "We were not able to create an organism for testing.");

    // Grab the type ID for 'accession'.
    // @todo Should this be 'Breeding Cross Progeny'?
    $type_id = $this->chado_connection->select('1:cvterm', 'c')
      ->fields('c', ['cvterm_id'])
      ->condition('c.name', 'accession', '=')
      ->execute()
      ->fetchField();
    $this->assertIsNumeric($type_id, 'We were not able to grab the type_id for accession.');

    // Enter our stocks that will be the parents in our test file.
    $stock_1 = '121S';
    $stock_id_1 = $this->chado_connection->insert('1:stock')
      ->fields([
        'name' => $stock_1,
        'organism_id' => $this->organism_id,
        'uniquename' => $stock_1,
        'type_id' => $type_id,
      ])
      ->execute();
    $this->assertIsNumeric($stock_id_1, "We were not able to create a stock for $stock_1.");

    $stock_2 = '122S';
    $stock_id_2 = $this->chado_connection->insert('1:stock')
      ->fields([
        'name' => $stock_2,
        'organism_id' => $this->organism_id,
        'uniquename' => $stock_2,
        'type_id' => $type_id,
      ])
      ->execute();
    $this->assertIsNumeric($stock_id_2, "We were not able to create a stock for $stock_2.");

    $stock_3 = '124S';
    $stock_id_3 = $this->chado_connection->insert('1:stock')
      ->fields([
        'name' => $stock_3,
        'organism_id' => $this->organism_id,
        'uniquename' => $stock_3,
        'type_id' => $type_id,
      ])
      ->execute();
    $this->assertIsNumeric($stock_id_3, "We were not able to create a stock for $stock_3.");

    $this->importer = new GermplasmCrossImporter(
      [],
      'trpcultivate-germplasm-cross-importer',
      $this->definitions,
      $this->chado_connection,
      $this->container->get('plugin.manager.trpcultivate_validator'),
      $this->container->get('trpcultivate.template_generator'),
      $this->container->get('entity_type.manager'),
      $this->container->get('renderer'),
      $this->container->get('messenger'),
    );

    $this->module_path = $this->container->get('module_handler')
      ->getModule('trpcultivate_germplasm')
      ->getPath();
  }

  /**
   * Tests the run() function using a simple example file.
   *
   * Example file located at:
   *   tests/src/Fixtures/CrossImporterFiles/crosses_simple.tsv.
   */
  public function testCrossImporterRunSimple() {

    $file = $this->createTestFile([
      'filename' => 'crosses_simple.tsv',
      'content' => [
        'file' => 'crosses_simple.tsv',
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/CrossImporterFiles/',
      ],
    ]);

    $run_args = ['organism' => $this->organism_id];
    $file_details = ['fid' => $file->id()];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    $this->importer->run();
    $this->importer->postRun();
  }

}
