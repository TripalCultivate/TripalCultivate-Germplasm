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
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config_factory;

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
    $this->installSchema('tripal_chado', ['tripal_cv_obo', 'tripal_custom_tables', 'tripal_mviews']);
    $this->prepareEnvironment(['TripalTerm', 'TripalImporter']);
    // Create and log-in a user.
    $this->setUpCurrentUser();
    // We need to add the cv/db materialized views for OboImporter:postRun().
    $this->populateMviewSql();
    $this->container->get('trpcultivate_germplasm.setup_module')->installTerms();

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
    $this->config_factory = \Drupal::configFactory();

    $this->importer = new GermplasmCrossImporter(
      [],
      'trpcultivate-germplasm-cross-importer',
      $this->definitions,
      $this->chado_connection,
      $buddy_manager,
      $this->config_factory,
      $container->get('plugin.manager.trpcultivate_validator'),
      $container->get('trpcultivate.template_generator'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
      $container->get('tripal.logger'),
      $container->get('tripal.fileretriever'),
      $container->get('tripal.backend_publish'),
    );

    // Configure our cvterms.
    // @todo Get rid of the buddy here and then call
    // $this->getCvtermID(db.name, dbxref.accession) in the loop
    $buddy_service = \Drupal::service('tripal_chado.chado_buddy');
    $cvterm_buddy = $buddy_service->createInstance('chado_cvterm_buddy', []);
    $cvterm_values = [
      'progeny' => [
        'cvterm.name' => 'progeny',
        'db.name' => 'PBO',
        'dbxref.accession' => '0000065',
      ],
      'maternal_parent' => [
        'cvterm.name' => 'maternal parent',
        'db.name' => 'TRPC',
        'dbxref.accession' => '0022',
      ],
      'paternal_parent' => [
        'cvterm.name' => 'paternal parent',
        'db.name' => 'TRPC',
        'dbxref.accession' => '0023',
      ],
      'crossing_block_year' => [
        'cvterm.name' => 'Crossing Block Year',
        'db.name' => 'TRPC',
        'dbxref.accession' => '0047',
      ],
      'crossing_block_season' => [
        'cvterm.name' => 'Crossing Block Season',
        'db.name' => 'TRPC',
        'dbxref.accession' => '0046',
      ],
      'cross_type' => [
        'cvterm.name' => 'additionalType',
        'db.name' => 'schema',
        'dbxref.accession' => 'additionalType',
      ],
    ];
    foreach ($cvterm_values as $cvterm => $values) {
      $cvterm_record = $cvterm_buddy->getCVterm($values);
      $cvterm_id = $cvterm_record[0]->getValue('cvterm.cvterm_id');
      $this->importer->setCvtermId($cvterm, $cvterm_id);
    }

    // Insert an organism.
    $this->organism_buddy = $buddy_service->createInstance('chado_organism_buddy', []);
    $organism_record = $this->organism_buddy->insertOrganism([
      'organism.genus' => 'Tripalus',
      'organism.species' => 'databasica',
    ]);
    $organism_id = $organism_record->getValue('organism.organism_id');
    $this->assertIsNumeric($organism_id, 'We were not able to create an organism for testing');

    // Insert a Program ID.
    // Use a CVterm ChadoBuddy to get the cvterm_id for inserting Program IDs.
    $cvterm_record = $cvterm_buddy->getCvterm([
      'cv.name' => 'rdfs', // same as db.name
      'cvterm.name' => 'type', // same as dbxref.accession
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
    // DO NOT CHANGE TO A BUDDY
    $dbprop_id = $this->chado_connection->insert('1:dbprop')
      ->fields([
        'db_id' => $program_record_id,
        'type_id' => $cvterm_id,
        'value' => 'Program ID',
      ])
      ->execute();
    $this->assertIsNumeric($dbprop_id,
          'We were not able to create the dbprop record for program "' . $program_name . '" for testing.');

    // Insert the parent stocks.
    $this->stock_buddy = $buddy_service->createInstance('chado_stock_buddy', []);
    $parental_stocks = ['121S', '122S', '124S'];
    foreach ($parental_stocks as $stock_name) {
      $parental_stock = $this->stock_buddy->insertStock([
        'stock.organism_id' => $organism_id,
        'stock.name' => $stock_name,
        'stock.dbxref_id' => 1,
        'stock.uniquename' => 'STOCK:' . $stock_name,
        'stock.description' => '',
        'stock.type_id' => 1,
        'stock.is_obsolete' => 'f',
      ]);

      // Insert the cross type as a stockprop record.
      // @todo Update this to use the ChadoPropertyBuddy when Tripal issue #2565
      // is resolved.
      $this->chado_connection->insert('1:stockprop')
        ->fields([
          'stock_id' => $parental_stock->getValue('stock.stock_id'),
          'type_id' => $this->importer->getCvtermId('cross_type'),
          'value' => 'single',
        ])
        ->execute();
    }

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
      $this->fail('The Germplasm Cross Importer run() method threw an exception: ' . $e->getMessage());
    }

    $numExpectedCrosses = 1;

    // Check that our crosses were inserted.
    if ($numExpectedCrosses > 0) {
      // Grab our crosses.
      /*
      // Check that the parental relationships were inserted.
      foreach ($crosses as $cross) {
      $cross_id = $cross->getValue('stock.stock_id');
      $maternal_parent = $this->chado_connection->select('stock_relationship', 'sr')
      ->fields('sr')
      ->execute();
      }
       */
    }
  }

  /**
   * Tests the Germplasm Cross Importer run() with files that should fail.
   */
  public function testRunWithFailingFiles() {
  }

  /**
   * Sets up the two materialized views needed for the importer.
   *
   * The mviews are populated when the importer postRun() is called.
   */
  protected function populateMviewSql() {
    $records = [
      0 => [
        'mview_id' => 1,
        'table_id' => 1,
        'name' => 'cv_root_mview',
        'query' => 'SELECT DISTINCT CVT.name, CVT.cvterm_id, CV.cv_id, CV.name FROM cvterm CVT
  LEFT JOIN cvterm_relationship CVTR ON CVT.cvterm_id = CVTR.subject_id
  INNER JOIN cvterm_relationship CVTR2 ON CVT.cvterm_id = CVTR2.object_id
  INNER JOIN cv CV on CV.cv_id = CVT.cv_id
  WHERE CVTR.subject_id is NULL and CVT.is_relationshiptype = 0 and CVT.is_obsolete = 0',
        'last_update' => 1234567890,
        'status' => 'test',
        'comment' => 'test',
      ],
      1 => [
        'mview_id' => 2,
        'table_id' => 2,
        'name' => 'db2cv_mview',
        'query' => 'SELECT DISTINCT CV.cv_id, CV.name as cvname, DB.db_id, DB.name as dbname, COUNT(CVT.cvterm_id) as num_terms FROM cv CV
  INNER JOIN cvterm CVT on CVT.cv_id = CV.cv_id
  INNER JOIN dbxref DBX on DBX.dbxref_id = CVT.dbxref_id
  INNER JOIN db DB on DB.db_id = DBX.db_id
  WHERE CVT.is_relationshiptype = 0 and CVT.is_obsolete = 0
  GROUP BY CV.cv_id, CV.name, DB.db_id, DB.name ORDER BY DB.name',
        'last_update' => 1234567890,
        'status' => 'test',
        'comment' => 'test',
      ],
    ];

    foreach ($records as $record) {
      $query = $this->container->get('database')->insert('tripal_mviews');
      $query->fields($record);
      $mview_id = $query->execute();
      $this->assertEquals($record['mview_id'], $mview_id, 'Prepared MView record not added.');
    }
  }

}
