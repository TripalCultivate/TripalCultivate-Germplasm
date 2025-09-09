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
 * @group collectionImporter
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
   * Germplasm Collection Importer plugin instance.
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

  /**
   * Data Provider: provides files with expected validation result.
   *
   * @return array
   *   Each scenario is an array with the following:
   *   - The population entry that gets entred in the textfield of the form
   *   - The relationship verb that gets entred in the textfield of the form
   *   - The filename of the test file used for this scenario (test files are
   *     located in: tests/src/Fixtures/GermplasmCollectionImporterFiles/)
   *   - An array indicating the expected validation results:
   *     - Each key is the unique name of a feedback line provided to the UI
   *       through processValidationMessages(). Currently, there is a feedback
   *       line for each unique validator instance that was instantiated by the
   *       configureValidators() method in the Germplasm Collection Importer.
   *       - 'status': [REQUIRED] One of 'pass', 'todo', or 'fail'
   *       - 'title': [REQUIRED if 'status' = 'fail'] A string that matches the
   *         title set in processValidationMessages() method in the Traits
   *         Importer class for this validator instance.
   *       - 'details': [REQUIRED if 'status' = 'fail'] A string that is ideally
   *         unique to the scenario that is expected to be in the render array.
   */
  public static function provideFilesForRunExceptions() {
    $valid_population_entry = 'my_term_1 [cultivar] (1)';
    $valid_relationship_verb = 'cultivar (CO_010:0000029)';

    $scenarios = [];

    // #0: Type does not exist.
    $scenarios[] = [
      'Type does not exist.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_type_dne.tsv',
      [
        'expected_message' => 'Type: type_dne (CO_010:00010) is not valid. Please provide a valid Type.',

      ],
    ];

    // #1: Organism not exist.
    $scenarios[] = [
      'Organism does not exist.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_organism_dne.tsv',
      [
        'expected_message' => 'Scientific Name: Lens databasica is not valid. Please provide a valid Scientific Name.',

      ],
    ];

    // #2: Uniquename already exists.
    $scenarios[] = [
      'Uniquename already exist.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_uniquename_exists.tsv',
      [
        'expected_message' => 'Uniquename is already used by another germplasm.',

      ],
    ];

    // #3: Duplicate Term in file with same uniquename.
    $scenarios[] = [
      'Duplicate Term in file with same uniquename.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_duplicate_term.tsv',
      [
        'expected_message' => 'Duplicate in lines: #2 and #3',

      ],
    ];

    // #4: Duplicate Term in file without a uniquename.
    $scenarios[] = [
      'Duplicate Term in file without a uniquename.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_duplicate_term_no_uname.tsv',
      [
        'expected_message' => 'Duplicate in lines: #2 and #3',

      ],
    ];

    // #5: Term already exists.
    $scenarios[] = [
      'Term already exists.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_term_exists.tsv',
      [
        'expected_message' => 'Term already exists in the database.',

      ],
    ];

    // #6: Germplasm does not exist.
    $scenarios[] = [
      'Germplasm does not exist.',
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_germplasm_dne.tsv',
      [
        'expected_message' => 'Germplasm Name: my_term_3 does not exists. Please provide a valid Germplasm Name.',

      ],
    ];

    return $scenarios;
  }

  /**
   * Tests the validation aspect of the trait importer form.
   *
   * @param string $scenario
   *   The test case scenario.
   * @param string $population_entry
   *   The population entry that is submitted with the form.
   * @param string $relationship_verb
   *   The relationship verb that is submitted with the form.
   * @param string $filename
   *   The name of the file being tested. (Test files are located in
   *   tests/src/Fixtures/TraitImporterFiles/)
   * @param array $case
   *   An array containing the expected exception message.
   *
   * @dataProvider provideFilesForRunExceptions
   */
  #[DataProvider('provideFilesForRunExceptions')]
  public function testRunExceptions(
    string $scenario,
    string $population_entry,
    string $relationship_verb,
    string $filename,
    array $case,
  ) {

    $file = $this->createTestFile([
      'filename' => $filename,
      'content' => [
        'file' => $filename,
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/GermplasmCollectionImporterFiles/',
      ],
    ]);

    $run_args = [
      'fld_text_population_entry' => $population_entry,
      'fld_select_relationship_verb' => $relationship_verb,
      'fld_radio_stock_position' => 'evi',
    ];

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
