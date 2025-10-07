<?php

namespace Drupal\Tests\trpcultivate_germcollection\Kernel\TripalImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal\Services\TripalLogger;
use Drupal\trpcultivate_germcollection\Plugin\TripalImporter\GermplasmCollectionImporter;
use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;

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
    'trpcultivate_germcollection',
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
   * @var \Drupal\trpcultivate_germcollection\Plugin\TripalImporter\GermplasmCollectionImporter
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
    $this->installConfig(['system', 'trpcultivate_germcollection', 'trpcultivate']);
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

    $type_id = ChadoCVTermAutocompleteController::getCVtermId('cultivar (EFO:0005136)');

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
      ->getModule('trpcultivate_germcollection')
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
    $valid_relationship_verb = 'cultivar (EFO:0005136)';

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
        'expected_stocks' =>
          [
            [
              'stock_id' => 1,
              'subject_id' => 1,
              'object_id' => 1,
            ],
          ],
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
        'expected_stocks' =>
          [
            [
              'stock_id' => 1,
              'subject_id' => 1,
              'object_id' => 1,
            ],
          ],
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
        'expected_stocks' =>
          [
            [
              'stock_id' => 2,
              'name' => 'my_stock_5',
              'type' => 'generated germplasm (CO_010:0000255)',
              'organism' => 'Lens ervoides',
              'has_uniquename' => FALSE,
              'uniquename' => 'UNIQUENAME12',
              'subject_id' => 2,
              'object_id' => 1,
            ],
            [
              'stock_id' => 3,
              'name' => 'my_stock_6',
              'type' => 'accession (CO_010:0000044)',
              'organism' => 'Lens culinaris',
              'has_uniquename' => TRUE,
              'uniquename' => 'UNIQUENAME6',
              'subject_id' => 3,
              'object_id' => 1,
            ],
          ],
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
        'expected_stocks' =>
          [
            [
              'stock_id' => 2,
              'name' => 'my_stock_5',
              'type' => 'generated germplasm (CO_010:0000255)',
              'organism' => 'Lens ervoides',
              'has_uniquename' => FALSE,
              'uniquename' => 'UNIQUENAME12',
              'subject_id' => 2,
              'object_id' => 1,
            ],
            [
              'stock_id' => 3,
              'name' => 'my_stock_6',
              'type' => 'accession (CO_010:0000044)',
              'organism' => 'Lens culinaris',
              'has_uniquename' => TRUE,
              'uniquename' => 'UNIQUENAME6',
              'subject_id' => 3,
              'object_id' => 1,
            ],
          ],
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
   * @param string $relationship_verb
   *   The relationship verb that is submitted with the form.
   * @param string $stock_position
   *   The stock position that goes as the value of radio button.
   * @param int $toggle_value
   *   The toggle value.
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

    // Get the population entry stock id and the relationship verb cvterm id.
    $population_entry_stock_id = ChadoGenericAutocompleteController::getPkeyId($population_entry);
    $relationship_verb_type_id = ChadoCVTermAutocompleteController::getCVtermId($relationship_verb);

    // Get the number of stocks we expect to be created.
    $number_of_stocks = count($case['expected_stocks']);

    // Query the stocks created.
    $stock_query = $this->chado_connection->query('WITH last_stocks AS (
      SELECT stock_id, organism_id, name, uniquename, type_id FROM {1:stock} ORDER BY stock_id DESC LIMIT :limit)
      SELECT * FROM last_stocks ORDER BY stock_id ASC', [':limit' => $number_of_stocks])
      ->fetchAll();

    // Query the relationships created.
    $relationship_query = $this->chado_connection->query('WITH last_stocks AS (
      SELECT stock_relationship_id, subject_id, object_id, type_id FROM {1:stock_relationship} ORDER BY stock_relationship_id DESC LIMIT :limit)
      SELECT * FROM last_stocks ORDER BY stock_relationship_id ASC', [':limit' => $number_of_stocks])
      ->fetchAll();

    // Check the stock creation.
    foreach ($case['expected_stocks'] as $index => $expected_stock) {
      // Check if the stock is inserted into the database correctly.
      $this->assertEquals(
      $expected_stock['stock_id'],
      $stock_query[$index]->stock_id,
      'We expected the stock id of the inserted stock to be ' . $expected_stock['stock_id'] . ', but it was ' . $stock_query[$index]->stock_id . '.',
      );

      if ($toggle_value == 0) {
        // Check if the name is inserted correctly.
        $this->assertEquals(
          $expected_stock['name'],
          $stock_query[$index]->name,
          'We expected the inserted stock to have a name of ' . $expected_stock['name'] . ', but it was ' . $stock_query[$index]->name . '.',
        );
        // Check if the type is inserted correctly.
        $this->assertEquals(
          ChadoCVTermAutocompleteController::getCVtermId($expected_stock['type']),
          $stock_query[$index]->type_id,
          'We expected the inserted stock to have a type id of ' . ChadoCVTermAutocompleteController::getCVtermId($expected_stock['type']) . ', but it was ' . $stock_query[$index]->type_id . '.',
        );
        // Check if the organism is inserted correctly.
        $this->assertEquals(
          chado_get_organism_id_from_scientific_name($expected_stock['organism'])[0],
          $stock_query[$index]->organism_id,
          'We expected the inserted stock to have an organism id of ' . chado_get_organism_id_from_scientific_name($expected_stock['organism'])[0] . ', but it was ' . $stock_query[$index]->organism_id . '.',
        );
        // Check if the uniquename is inserted correctly.
        if ($expected_stock['has_uniquename']) {
          $this->assertNotEmpty(
            $stock_query[$index]->uniquename,
            'We expected the inserted stock to have a uniquename, but it does not.',
          );
          $this->assertEquals(
            $expected_stock['uniquename'],
            $stock_query[$index]->uniquename,
            'We expected the inserted stock to have a uniquename of ' . $expected_stock['uniquename'] . ', but it was ' . $stock_query[$index]->uniquename . '.',
          );
        }
        else {
          // If the user did not provide a uniquename, check if a uniquename
          // is generated correctly.
          $this->assertNotEmpty(
            $stock_query[$index]->uniquename,
            'We expected the inserted stock to have a uniquename, but it does not.',
          );
          $this->assertEquals(
            $expected_stock['uniquename'],
            $stock_query[$index]->uniquename,
            'We expected the inserted stock to have a uniquename of ' . $expected_stock['uniquename'] . ', but it was ' . $stock_query[$index]->uniquename . '.',
          );
        }
      }

      // Check if the stock relationship is inserted into database correctly.
      $this->assertEquals(
      $expected_stock['subject_id'],
      $relationship_query[$index]->subject_id,
      'We expected the inserted stock relationship to have a subject id of ' . $expected_stock['subject_id'] . ', but it was ' . $relationship_query[$index]->subject_id . '.',
      );
      $this->assertEquals(
      $expected_stock['object_id'],
      $relationship_query[$index]->object_id,
      'We expected the inserted stock relationship to have a object id of' . $expected_stock['object_id'] . ', but it was ' . $relationship_query[$index]->object_id . '.',
      );

      $this->assertEquals(
      $relationship_verb_type_id,
      $relationship_query[$index]->type_id,
      'We expected the inserted stock relationship to have a type id that is the same as the cvterm id of the relationship verb, but it was not.',
      );

      if ($stock_position == 'evi') {
        $this->assertEquals(
          $population_entry_stock_id,
          $relationship_query[$index]->subject_id,
          'We expected the inserted stock relationship to have a subject id that is the same as the population entry id when the relationship is set to evi, but it was not.',
        );
      }
      elseif ($stock_position == 'ive') {
        $this->assertEquals(
          $population_entry_stock_id,
          $relationship_query[$index]->object_id,
          'We expected the inserted stock relationship to have a object id that is the same as the population entry id when the relationship is set to ive, but it was not.',
        );
      }
    }
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
    $valid_relationship_verb = 'cultivar (EFO:0005136)';

    $scenarios = [];

    // #0: Type does not exist.
    $scenarios[] = [
      'Type does not exist.',
      $valid_population_entry,
      $valid_relationship_verb,
      0,
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
      0,
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
      0,
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
      0,
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
      0,
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
      0,
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
      1,
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
   * @param int $toggle
   *   The toggle value.
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
    int $toggle,
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
      'relationship_toggle' => $toggle,
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
