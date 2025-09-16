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

    // Insert the necessary germplasm cvterms.
    // @todo Remove these once Tripal issue#2287 has been addressed.
    $this->chado_connection->query("INSERT INTO {1:cv} VALUES(33, 'PBO', 'Plant Breeding Ontology')");
    $this->chado_connection->query("INSERT INTO {1:db} VALUES(41, 'PBO', 'Plant Breeding Ontology (PBO): an ontology for the plant breeding community which captures more than 2200 entries where 80 represent the core terms.', 'http://purl.obolibrary.org/obo/PBO/PBO_{accession}', 'http://purl.obolibrary.org/obo/PBO')");
    $this->chado_connection->query("INSERT INTO {1:dbxref} VALUES(3490, 8, '0007059', '', NULL)");
    $this->chado_connection->query("INSERT INTO {1:dbxref} VALUES(3491, 8, '0005136', '', NULL)");
    $this->chado_connection->query("INSERT INTO {1:dbxref} VALUES(3492, 12, '0002076', '', NULL)");
    $this->chado_connection->query("INSERT INTO {1:dbxref} VALUES(3493, 41, '0000065', '', NULL)");
    $this->chado_connection->query("INSERT INTO {1:cvterm} VALUES(3183, 8, 'germplasm', 'Germplasm is the living genetic resources such as seeds or tissue that is maintained for the purpose of animal and plant breeding, preservation, and other research uses. These resources may take the form of seed collections stored in seed banks, trees growing in nurseries, animal breeding lines maintained in animal breeding programs or gene banks, etc. Germplasm collections can range from collections of wild species to elite, domesticated breeding lines that have undergone extensive human selection.', 3490, 0, 0)");
    $this->chado_connection->query("INSERT INTO {1:cvterm} VALUES(3184, 8, 'cultivar', 'A cultivated plant variety selected and given a name because it has desirable characteristics that distinguish it from otherwise similar plants of the same species.', 3491, 0, 0)");
    $this->chado_connection->query("INSERT INTO {1:cvterm} VALUES(3185, 12, 'collection of specimens', 'A material entity that has two or more specimens as its parts.', 3492, 0, 0)");
    $this->chado_connection->query("INSERT INTO {1:cvterm} VALUES(3186, 33, 'progeny', '', 3493, 0, 0)");

    // Grab the name of our chado schema.
    $schema_name = $this->chado_connection->getSchemaName();
    $this->chado_connection->query("SELECT pg_catalog.setval('$schema_name.cv_cv_id_seq', 33, TRUE)");
    $this->chado_connection->query("SELECT pg_catalog.setval('$schema_name.cvterm_cvterm_id_seq', 3186, TRUE)");
    $this->chado_connection->query("SELECT pg_catalog.setval('$schema_name.db_db_id_seq', 41, TRUE)");
    $this->chado_connection->query("SELECT pg_catalog.setval('$schema_name.dbxref_dbxref_id_seq', 3493, TRUE)");

    // Create our organism.
    $this->organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
      ])
      ->execute();
    $this->assertIsNumeric($this->organism_id,
      "We were not able to create an organism for testing.");

    // Grab the type ID for 'progeny'.
    $type_id = $this->getCvtermID('PBO', '0000065');
    $this->assertIsNumeric($type_id, 'We were not able to grab the type_id for progeny.');

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

  /**
   * Data Provider: provides files and the expected results for run exceptions.
   *
   * @return array
   *   Each scenario is an array with the following:
   *   - The organism ID that gets selected in the form.
   *   - The filename of the test file used for this scenario (test files are
   *     located in: tests/src/Fixtures/CrossImporterFiles/)
   *   - An array indicating the expected validation results:
   *        - expected_message: the exception message that's expected in that
   *          specific scenario.
   */
  public static function provideFilesForRunExceptions() {

    $scenarios = [];

    //$valid_organism_id = $this->organism_id;
    $invalid_organism_id = 12345;

    // #0: Organism ID does not exist
    $scenarios[] = [
      $invalid_organism_id,
      'crosses_simple.tsv',
      [
        'expected_message' => 'The organism ID 12345 is not valid. Please check that the organism you selected in the form is still in the database.',
      ],
    ];

    return $scenarios;
  }

  /**
  * Test the exceptions caused by the run method of the cross importer.
  *
  * @param int $organism_id
  *   The ID of the organism selected in the form field of the importer.
  * @param string $filename
  *   The name of the file being tested. (Test files are located in
  *   tests/src/Fixtures/CrossImporterFiles/)
  * @param array $case
  *   An array containing the expected exception message.
  *
  * @dataProvider provideFilesForRunExceptions
  */
  #[DataProvider('provideFilesForRunExceptions')]
  public function testRunExceptions(int $organism_id, string $filename, array $case) {

    $file = $this->createTestFile([
      'filename' => $filename,
      'content' => [
        'file' => $filename,
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/CrossImporterFiles/',
      ],
    ]);

    $run_args = ['organism' => $this->organism_id];

    $file_details = ['fid' => $file->id()];

    // Test with a passed validation case string.
    $exception_caught = FALSE;
    $exception_message = 'NONE';
    try {
      $this->importer->createImportJob($run_args, $file_details);
      $this->importer->prepareFiles();
      $this->importer->run();
    }
    catch (\Exception $e) {
      $exception_caught = TRUE;
      $exception_message = $e->getMessage();
    }
    $this->assertTrue(
      $exception_caught,
      "We expected an exception to be caught for " . $scenario . " scenario, but one wasn't thrown.",
    );
    $this->assertEquals(
      $case['expected_message'],
      $exception_message,
      "We expected the exception message to indicate that a passed validation string was provided to " . $scenario . "  scenario, but it does not match what was expected.",
    );
  }

}
