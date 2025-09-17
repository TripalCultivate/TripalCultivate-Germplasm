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
   * The path to tripalcultivate_germplasm module.
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
    $this->chado_connection->insert('1:organism')
    ->fields([
      'genus' => 'Lens',
      'species' => 'ervoides',
    ])
    ->execute();

    $type_id = $this->chado_connection->select('1:cvterm', 'c')
      ->fields('c', ['cvterm_id'])
      ->condition('c.name', 'cultivar', '=')
      ->execute()
      ->fetchField();

    $stock_id = $this->chado_connection->insert('1:stock')
      ->fields([
        'name' => 'my_stock_1',
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
   * Data Provider: provides data and the expected results for simple run.
   *
   * @return array
   *   Each scenario is an array with the following:
   *   - The population entry that gets entred in the textfield of the form
   *   - The relationship verb that gets entred in the textfield of the form
   *   - The stock position that goes as the value of radio button
   *   - The toggle value.
   *   - The filename of the test file used for this scenario (test files are
   *     located in: tests/src/Fixtures/GermplasmCollectionImporterFiles/
   *   - An array indicating the expected validation results:
   *        - expected_stock_id: the exception stock id in that
   *          specific scenario.
   *        - expected_subject_id: the exception subject id in that
   *          specific scenario.
   *        - expected_object_id: the exception object id in that
   *          specific scenario.
   */
  public static function provideDataForRunSimple() {
    $valid_population_entry = 'my_stock_1 [cultivar] (1)';
    $valid_relationship_verb = 'cultivar (CO_010:0000029)';

    $scenarios = [];

    // #1: Entry-verb-individual relatoinship with toggle on.
    $scenarios[] = [
      'entry-verb-individual relationship with toggle on',
      $valid_population_entry,
      $valid_relationship_verb,
      'evi',
      1,
      'collection_importer_example.tsv',
      [
        'expected_stock_id' => 2,
        'expected_subject_id' => 1,
        'expected_object_id' => 2,
      ],
    ];

    // #2: Individual-verb-entry relatoinship with toggle on.
    $scenarios[] = [
      'individual-verb-entry relationship with toggle on',
      $valid_population_entry,
      $valid_relationship_verb,
      'ive',
      1,
      'collection_importer_example.tsv',
      [
        'expected_stock_id' => 2,
        'expected_subject_id' => 2,
        'expected_object_id' => 1,
      ],
    ];
    // #3: Individual-verb-entry relatoinship with toggle off.
    $scenarios[] = [
      'individual-verb-entry relationship with toggle off',
      $valid_population_entry,
      $valid_relationship_verb,
      'ive',
      0,
      'collection_importer_insert_example.tsv',
      [
        'expected_stock_id' => 2,
        'expected_subject_id' => 2,
        'expected_object_id' => 1,
      ],
    ];
    // #4: Individual-verb-entry relatoinship with toggle off.
    $scenarios[] = [
      'individual-verb-entry relationship with toggle off',
      $valid_population_entry,
      $valid_relationship_verb,
      'ive',
      0,
      'collection_importer_insert_example.tsv',
      [
        'expected_stock_id' => 2,
        'expected_subject_id' => 2,
        'expected_object_id' => 1,
      ],
    ];

    return $scenarios;
  }

  /**
   * Tests the run() function using the provided test case scenarios.
   *
   * @param string $scenario
   *   The test case scenario.
   * @param string $population_entry
   *   The population entry that is submitted with the form.
   * @param string $stock_position
   *   The stock position that goes as the value of radio button.
   * @param int $toggle_value
   *   The toggle value.
   * @param string $relationship_verb
   *   The relationship verb that is submitted with the form.
   * @param string $filename
   *   The name of the file being tested. (Test files are located in
   *   tests/src/Fixtures/GermplasmCollectionImporterFiles/)
   * @param array $case
   *   An array containing the expected results.
   *
   * @dataProvider provideDataForRunSimple
   */
  #[DataProvider('provideDataForRunSimple')]
  public function testGermplasmCollectionImporterRunSimple(
    string $scenario,
    string $population_entry,
    string $relationship_verb,
    string $stock_position,
    int $toggle_value,
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
      'fld_radio_stock_position' => $stock_position,
      'relationship_toggle' => $toggle_value,
    ];

    $file_details = ['fid' => $file->id()];

    $this->importer->createImportJob($run_args, $file_details);
    $this->importer->prepareFiles();
    $this->importer->run();
    $this->importer->postRun();

    // Check if the stock is inserted into the database correctly.
    $stock_query = $this->chado_connection->query(
      'SELECT stock_id FROM {1:stock} ORDER BY stock_id DESC LIMIT 1'
    )
      ->fetchField();
    $this->assertEquals(
      $case['expected_stock_id'],
      $stock_query,
      'We expected the stock to be inserted but it was not.'
    );

    // Check if the relationship is created and inserted correctly.
    $relationship_query_evi = $this->chado_connection->query(
      'SELECT subject_id, object_id FROM {1:stock_relationship} ORDER BY stock_relationship_id DESC LIMIT 1'
    )
      ->fetchAll();
    $this->assertEquals(
      $case['expected_subject_id'],
      $relationship_query_evi[0]->subject_id,
      'We expected the inserted stock relationship to have a subject id of' . $case['expected_subject_id'] . ', but it was ' . $relationship_query_evi[0]->subject_id . '.',
    );
    $this->assertEquals(
      $case['expected_object_id'],
      $relationship_query_evi[0]->object_id,
      'We expected the inserted stock relationship to have a object id of' . $case['expected_object_id'] . ', but it was ' . $relationship_query_evi[0]->object_id . '.',
    );
  }

  /**
   * Data Provider: provides files and the expected results for run exceptions.
   *
   * @return array
   *   Each scenario is an array with the following:
   *   - The population entry that gets entred in the textfield of the form
   *   - The relationship verb that gets entred in the textfield of the form
   *   - The filename of the test file used for this scenario (test files are
   *     located in: tests/src/Fixtures/GermplasmCollectionImporterFiles/)
   *   - An array indicating the expected validation results:
   *        - expected_message: the exception message that's expcted in that
   *          specific scenario.
   */
  public static function provideFilesForRunExceptions() {
    $valid_population_entry = 'my_stock_1 [cultivar] (1)';
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
        'expected_message' => 'Germplasm Name: my_stock_3 does not exists. Please provide a valid Germplasm Name.',

      ],
    ];

    return $scenarios;
  }

  /**
   * Test the exceptions caused at run method of the collection importer form.
   *
   * @param string $scenario
   *   The test case scenario.
   * @param string $population_entry
   *   The population entry that is submitted with the form.
   * @param string $relationship_verb
   *   The relationship verb that is submitted with the form.
   * @param string $filename
   *   The name of the file being tested. (Test files are located in
   *   tests/src/Fixtures/GermplasmCollectionImporterFiles/)
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
      'relationship_toggle' => 1,
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
