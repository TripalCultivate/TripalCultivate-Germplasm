<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal\Services\TripalLogger;
use Drupal\Core\Form\FormState;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests the formValidate() functionality of the Germplasm Collection Importer.
 *
 * @group collectionImporter
 */
#[Group('collectionImporter')]
class GermplasmCollectionImporterFormValidateTest extends ChadoTestKernelBase {

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
  protected function setup(): void {
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
   *   - an integer indicating the number of form validation messages we expect
   *     to see when the form is submitted.
   *     NOTE: These validation messages are produced by the form via Drupal and
   *     are not related to this module's use of validator plugins.
   */
  public static function provideFilesForValidation() {

    // @todo Once we have a validator set up to check if the population entry
    // and the relationship verb exists, we would add test cases here to
    // test those
    // $invalid_population_entry = '';
    // $invalid_relationship_verb = '';
    $valid_population_entry = 'my_term_1 [cultivar] (1)';
    $valid_relationship_verb = 'cultivar (CO_010:0000029)';

    // Set our number of expected validation messages to 0, since none of
    // validators should cause this number to change at this moment, since we
    // don't have validators set up for population entry and relationship verb
    // yet.
    $num_form_validation_messages = 0;

    $scenarios = [];

    // 0: File is empty.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'empty_file.tsv',
      [
        'valid_data_file' => [
          'title' => 'File is valid and not empty',
          'status' => 'fail',
          'details' => 'The file provided has no contents in it to import. Please ensure your file has the expected header row and at least one row of data.',
        ],
        'valid_delimited_file' => ['status' => 'todo'],
        'valid_headers' => ['status' => 'todo'],
        'empty_cell' => ['status' => 'todo'],
        'germplasm_name_exists' => ['status' => 'todo'],
      ],
      $num_form_validation_messages,
    ];

    // #1: Header is improperly delimited, with proper data rows.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_header_incorrectly_delimited.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => [
          'title' => 'Lines are properly delimited',
          'status' => 'fail',
          'details' => 'This importer requires a strict number of 3 columns for each line. The following lines do not contain the expected number of columns.',
        ],
        'valid_headers' => ['status' => 'todo'],
        'empty_cell' => ['status' => 'todo'],
        'germplasm_name_exists' => ['status' => 'todo'],
      ],
      $num_form_validation_messages,
    ];

    // #2: 2nd row of file is improperly delimited.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_incorrectly_delimited.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => [
          'title' => 'Lines are properly delimited',
          'status' => 'fail',
          'details' => 'This importer requires a strict number of 3 columns for each line. The following lines do not contain the expected number of columns.',
        ],
        // Since the header row has the correct number of columns, validation
        // for valid_header is expected to pass.
        'valid_headers' => ['status' => 'pass'],
        'empty_cell' => ['status' => 'todo'],
        'germplasm_name_exists' => ['status' => 'todo'],
      ],
      $num_form_validation_messages,
    ];

    // #3: Contains correct header but no data.
    // Never reaches the validators for data-row since file content is empty.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_correct_header_no_data.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => ['status' => 'pass'],
        'valid_headers' => ['status' => 'pass'],
        'empty_cell' => ['status' => 'todo'],
        'germplasm_name_exists' => ['status' => 'todo'],
      ],
      $num_form_validation_messages,
    ];

    // #4: Contains incorrect header and one line of correct data.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_invalid_header.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => ['status' => 'pass'],
        'valid_headers' => [
          'title' => 'File has all of the column headers expected',
          'status' => 'fail',
          'details' => 'One or more of the column headers in the input file does not match what was expected. Please check if your column header is in the correct order and matches the template exactly.',
        ],
        'empty_cell' => ['status' => 'todo'],
        'germplasm_name_exists' => ['status' => 'todo'],
      ],
      $num_form_validation_messages,
    ];

    // #5: Contains correct header but data row contains an empty cell.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_empty_cell.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => ['status' => 'pass'],
        'valid_headers' => ['status' => 'pass'],
        'empty_cell' => [
          'title' => 'Required cells contain a value',
          'status' => 'fail',
          'details' => 'The following line number and column header combinations were empty, but a value is required.',
        ],
        'germplasm_name_exists' => ['status' => 'pass'],
      ],
      $num_form_validation_messages,
    ];

    // #6: Contains a germplasm name+ scientific name combination that
    // doesn't exists in the database
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_unmatched_organism.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => ['status' => 'pass'],
        'valid_headers' => ['status' => 'pass'],
        'empty_cell' => ['status' => 'pass'],
        'germplasm_name_exists' => [
          'title' => 'Germplasm exist(s) in the database',
          'status' => 'fail',
          'details' => 'The following germplasm names do not match any existing in this site. Please make sure you have entered the names exactly as they appear on the germplasm pages or contact your administrator to have them added if they do not yet exist.',
        ],
      ],
      $num_form_validation_messages,
    ];

    // #7: Contains a germplasm that does not exists in the
    // database.
    $scenarios[] = [
      $valid_population_entry,
      $valid_relationship_verb,
      'collection_importer_germplasm_dne.tsv',
      [
        'valid_data_file' => ['status' => 'pass'],
        'valid_delimited_file' => ['status' => 'pass'],
        'valid_headers' => ['status' => 'pass'],
        'empty_cell' => ['status' => 'pass'],
        'germplasm_name_exists' => [
          'title' => 'Germplasm exist(s) in the database',
          'status' => 'fail',
          'details' => 'The following germplasm names do not match any existing in this site. Please make sure you have entered the names exactly as they appear on the germplasm pages or contact your administrator to have them added if they do not yet exist.',
        ],
      ],
      $num_form_validation_messages,
    ];

    return $scenarios;

  }

  /**
   * Tests the validation aspect of the trait importer form.
   *
   * @param string $population_entry
   *   The population entry that is submitted with the form.
   * @param string $relationship_verb
   *   The relationship verb that is submitted with the form.
   * @param string $filename
   *   The name of the file being tested. (Test files are located in
   *   tests/src/Fixtures/TraitImporterFiles/)
   * @param array $expected_validator_results
   *   An array that is keyed by the unique name of each validator instance
   *   (these names are declared in the configureValidators() method in the
   *   Traits Importer class). Each validator instance in the array is further
   *   keyed by the following. Some are required but others are optional,
   *   dependent upon the expected validation results.
   *   - 'status': [REQUIRED] One of 'pass', 'todo', or 'fail'.
   *   - 'title': [REQUIRED if 'status' = 'fail'] A string that matches the
   *     title set in processValidationMessages() method in the Trait Importer
   *     class for this validator instance.
   *   - 'details': [REQUIRED if 'status' = 'fail'] A string that is ideally
   *     unique to the scenario that is expected to be in the render array.
   * @param int $expected_num_form_validation_errors
   *   The number of form validation messages we expect to see when the form is
   *   submitted. NOTE: These validation messages are produced by the form via
   *   Drupal and are not related to this module's use of validator plugins.
   *
   * @dataProvider provideFilesForValidation
   */
  #[DataProvider('provideFilesForValidation')]
  public function testGermplasmCollectionImporterFormValidation(
    string $population_entry,
    string $relationship_verb,
    string $filename,
    array $expected_validator_results,
    int $expected_num_form_validation_errors,
  ) {
    $formBuilder = \Drupal::formBuilder();
    $form_id = 'Drupal\tripal\Form\TripalImporterForm';
    $plugin_id = 'trpcultivate-germplasm-population-importer';

    // Create our organism and configure it.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Lens',
        'species' => 'culinaris',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id,
      "We were not able to create an organism for testing.");
    $organism_id_2 = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id_2,
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

    $file = $this->createTestFile([
      'filename' => $filename,
      'content' => [
        'file' => $filename,
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/GermplasmCollectionImporterFiles/',
      ],
    ]);

    // Setup the form_state .
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);

    // Submit the population entry.
    $form_state->setValue('fld_text_population_entry', $population_entry);

    // Submit the relationship verb.
    $form_state->setValue('fld_select_relationship_verb', $relationship_verb);

    // Submit our file.
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    $formBuilder->submitForm($form_id, $form_state);
    // And retrieve the form that would be shown after the above submit.
    $form = $formBuilder->retrieveForm($form_id, $form_state);

    // Check that we did validation.
    $this->assertTrue($form_state->isValidationComplete(),
      "We expect the form state to have been updated to indicate that validation is complete.");

    // Looking for form validation errors.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . " => " . (string) $markup;
    }

    // Compare number of form validation errors received to the number expected.
    $this->assertCount(
      $expected_num_form_validation_errors,
      $form_validation_messages,
      "The number of form state errors we expected (" . $expected_num_form_validation_errors . ") does not match what we received: " . implode(" AND ", $helpful_output)
    );
    // Confirm that there is a validation window open.
    $this->assertArrayHasKey('validation_result', $form,
      "We expected a validation failure reported via our plugin setup but it's not showing up in the form.");
    $validation_element_data = $form['validation_result']['#data']['validation_result'];

    // Now check our expectations are met.
    foreach ($expected_validator_results as $validation_plugin => $expected) {
      // Check status.
      $this->assertEquals(
        $expected['status'],
        $validation_element_data[$validation_plugin]['status'],
        "We expected the form validation element to indicate the $validation_plugin plugin had the specified status."
      );
      // We don't want the value of 'details' in $expectations (from the data
      // provider) to be empty since assertStringContainsString() will evaluate
      // to true in that scenario. It can be tempting to set it to empty and
      // then come back to it when you figure out what the expected string
      // should be- just don't do it!
      if (array_key_exists('details', $expected)) {
        $this->assertNotEmpty(
          $expected['details'],
          "An empty string was provided with a 'details' key within the data provider - trust me, don't do that!"
        );

        // Now check details.
        $this->assertIsArray(
          $validation_element_data[$validation_plugin]['details'],
          "We expected the details for $validation_plugin to be an array, but it is not."
        );

        // Check for the key #type which is common in all render arrays.
        $this->assertArrayHasKey('#type', $validation_element_data[$validation_plugin]['details'], "We expected the details for $validation_plugin to be a render array by having the #type key, but it does not.");

        // Walk recursively through the render array, and report whether our
        // 'details' item is present in the array or not.
        $item_to_find = $expected['details'];
        $found = FALSE;
        array_walk_recursive(
          $validation_element_data[$validation_plugin]['details'],
          function ($item, $key) use (&$found, $item_to_find) {
            if ($item == $item_to_find) {
              $found = TRUE;
            }
          }
        );

        $this->assertTrue($found, "We expected to find \"$item_to_find\" in the
        resulting render array for $validation_plugin failures, but did not.");
      }
    }

    // If the form was not submitted due to validation error, check to ensure
    // that no Tripal Job was created in the process.
    $tripal_jobs = $this->chado_connection->query(
      'SELECT job_id FROM {tripal_jobs} ORDER BY job_id DESC LIMIT 1'
    )
      ->fetchField();

    $this->assertFalse(
      $tripal_jobs,
      'A failed import due to validation error that did not submit should not create a job request.'
    );

  }

}
