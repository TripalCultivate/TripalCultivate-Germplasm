<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal\Services\TripalLogger;
use Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCollectionImporter;

/**
 * Tests the functionality of the run() method of Germplasm Collection Importer.
 *
 * @group collectioImporter
 */
#[Group('collectionImporter')]
class GermplasmCollectionImporterRunTest extends ChadoTestKernelBase {

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
   * Phenotypes Share Importer plugin instance.
   *
   * @var \Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmCollectionImporter
   */
  protected GermplasmCollectionImporter $importer;

  /**
   * A default listing of annotations associated with the importer.
   *
   * @var array
   */
  protected array $definitions = [
    'test-collection-importer' => [
      'id' => 'trpcultivate-germplasm-population-importer',
      'label' => 'Tripal Importer: Germplasm Collection Importer',
      'description' => 'Imports germplasm populations (i.e. RIL, NAM, cross progeny) into testchado.',
      'file_types' => ['tsv', 'txt'],
      'upload_title' => 'Population Individuals*',
      'upload_description' => 'This should not be visible!',
      'use_analysis' => FALSE,
      'require_analysis' => FALSE,
      'use_button' => TRUE,
      'submit_disabled' => FALSE,
      'button_text' => 'Import',
      'file_upload' => TRUE,
      'file_local' => FALSE,
      'file_remote' => FALSE,
      'file_required' => TRUE,
      'cardinality' => 1,
      'menu_path' => '',
      'callback' => '',
      'callback_module' => '',
      'callback_path' => '',
    ],
  ];

  /**
   * The path to tripalcultivate_phenotypes module.
   *
   * @var string
   */
  private $module_path;

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

    // Create our organism and configure it.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Lens',
        'species' => 'culinaris',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id,
      "We were not able to create an organism for testing.");

    $type_id = $this->chado_connection->select('1:cvterm', 'c')
      ->fields('c', ['cvterm_id'])
      ->condition('c.name', 'cultivar', '=')
      ->execute()
      ->fetchField();

    $stock_id = $this->chado_connection->insert('1:stock')
      ->fields([
        'name' => 'my_term_1',
        'organism_id' => $organism_id,
        'uniquename' => 'UNIQUENAME1',
        'type_id' => $type_id,
      ])
      ->execute();
    $this->assertIsNumeric($stock_id, 'We were not able to create a cvterm.');

    $this->importer = new GermplasmCollectionImporter(
      [],
      'trpcultivate-germplasm-population-importer',
      $this->definitions,
      $this->chado_connection,
      $container->get('plugin.manager.trpcultivate_validator'),
      $container->get('trpcultivate.template_generator'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
    );

    $this->module_path = $this->container->get('module_handler')
      ->getModule('trpcultivate_germplasm')
      ->getPath();
  }

  /**
   * Tests the run() function using a simple example file.
   *
   * Example file located at:
   *   tests/src/Fixtures/collection_importer_example.tsv.
   */
  public function testGermplasmCollectionImporterRunSimple() {

    $file = $this->createTestFile([
      'filename' => 'collection_importer_example.tsv',
      'content' => [
        'file' => 'collection_importer_example.tsv',
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/',
      ],
    ]);

    $run_args = [
      'fld_text_population_entry' => 'my_term_1 [cultivar] (1)',
      'fld_select_relationship_verb' => 'cultivar (CO_010:0000029)',
      'fld_radio_stock_position' => 'evi',
    ];
    $file_details = ['fid' => $file->id()];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    $this->importer->run();
    $this->importer->postRun();
  }

}
