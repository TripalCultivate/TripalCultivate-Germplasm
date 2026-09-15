<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter\GermplasmCrossImporter;

use Drupal\Core\Form\FormState;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoCvtermBuddy;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoDbxrefBuddy;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the form + form-related functionality of the Germplasm Cross Importer.
 *
 * @group crossImporter
 */
#[Group('tripal-importer')]
#[Group('chado-importer')]
#[Group('importer-germplasmcross')]
#[RunTestsInSeparateProcesses]
class GermplasmCrossImporterFormTest extends ChadoTestKernelBase {

  use UserCreationTrait;
  use TripalCultivateImporterTestTrait;

  /**
   * Theme used in the test environment.
   *
   * @var string
   */
  protected string $defaultTheme = 'stark';

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
   * An instance of the ChadoCvtermBuddy.
   *
   * @var \Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoCvtermBuddy
   */
  protected ChadoCvtermBuddy $cvterm_buddy;

  /**
   * An instance of the ChadoDbxrefBuddy.
   *
   * @var \Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoDbxrefBuddy
   */
  protected ChadoDbxrefBuddy $dbxref_buddy;

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
      ->onlyMethods(['error'])
      ->getMock();
    $mock_logger->method('error')
      ->willReturnCallback(function ($message, $context, $options) {
        // @todo Revisit print out of log messages, but perhaps setting an
        // option for log messages to not print to the UI?
        // print str_replace(array_keys($context), $context, $message);
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    $this->module_path = $this->container->get('module_handler')
      ->getModule('trpcultivate_germplasm')
      ->getPath();

    // Setup our ChadoBuddies.
    $buddy_service = \Drupal::service('tripal_chado.chado_buddy');
    $this->cvterm_buddy = $buddy_service->createInstance('chado_cvterm_buddy', []);
    $this->dbxref_buddy = $buddy_service->createInstance('chado_dbxref_buddy', []);
  }

  /**
   * Data Provider: provides values to test the Cross Importer form's dropdowns.
   *
   * @return array
   *   Each scenario is an array with the following:
   *   - A nested array with information to test the "genus" form field, with
   *     the following keys:
   *     - 'organisms': A nested array of organisms to insert into the database,
   *       contains the genus to create the form field dropdown. Each organism
   *       array has the following keys:
   *       - 'genus': A string of the genus to insert.
   *       - 'species': A string of the species to insert.
   *     - 'default': The genus expected to be shown to the user by default.
   *     - 'message': The string message to be passed into assertEquals when
   *       evaluating the default genus.
   *   - A nested array with information to test the "program" form field, with
   *     the following keys:
   *     - 'programs': A nested array of Program IDs to insert into the
   *       database, contains the program name to create the form field
   *       dropdown. Each program array has the following keys:
   *       - 'name': A string of the program "code" to insert.
   *     - 'default': The program name expected to be shown to the user by
   *       default.
   *     - 'message': The string message to be passed into assertEquals when
   *       evaluating the default program name.
   */
  public static function provideValuesForForm() {
    $scenarios = [];

    // #0: No organisms or program IDs exist in the database.
    $scenarios[] = [
      [
        'organisms' => [],
        'default' => '',
        'message' => 'We expect the genus element in the form to default to empty option since no organisms are available.',
      ],
      [
        'programs' => [],
        'default' => '',
        'message' => 'We expect the program element in the form to default to empty option since no programs are available.',
      ],
    ];

    // #1: 1 valid organism + 1 valid program ID
    $scenarios[] = [
      [
        'organisms' => [
          [
            'genus' => 'Tripalus',
            'species' => 'databasica',
          ],
        ],
        'default' => 'Tripalus',
        'message' => 'We expect the genus element in the form to default to Tripalus as it is the genus of the organism we created.',
      ],
      [
        'programs' => [
          [
            'name' => 'Program 1',
          ],
        ],
        'default' => 'Program 1',
        'message' => 'We expect the program element in the form to default to Program 1 as it is the only program available.',
      ],
    ];

    // #2: 3 valid organisms + 3 valid Programs.
    $scenarios[] = [
      [
        'organisms' => [
          [
            'genus' => 'Tripalus',
            'species' => 'databasica',
          ],
          [
            'genus' => 'Tripalus',
            'species' => 'chadoii',
          ],
          [
            'genus' => 'Lorem',
            'species' => 'ipsum',
          ],
        ],
        // Since there is more than one organism, the default option is expected
        // to be the -Select- text, therefore empty value.
        'default' => '',
        'message' => 'We expect the genus element in the form to default to empty option since more than one organism is available.',
      ],
      [
        'programs' => [
          [
            'name' => 'Program 1',
          ],
          [
            'name' => 'Program 2',
          ],
          [
            'name' => 'Program 3',
          ],
        ],
        // Since there is more than one program, the default option is expected
        // to be the -Select- text, therefore empty value.
        'default' => '',
        'message' => 'We expect the program element in the form to default to empty option since more than one program is available.',
      ],
    ];

    return $scenarios;
  }

  /**
   * Tests building the importer form with a variable number of organisms.
   *
   * @param array $genus
   *   A nested array with information to test the "genus" form field, with the
   *   following keys:
   *   - 'organisms': A nested array of organisms to insert into the database,
   *   contains the genus to create the form field dropdown. Each organism array
   *   has the following keys:
   *     - 'genus': A string of the genus to insert.
   *     - 'species': A string of the species to insert.
   *   - 'default': The genus expected to be shown to the user by default.
   *   - 'message': The string message to be passed into assertEquals when
   *     evaluating the default genus.
   * @param array $programs
   *   A nested array with information to test the "Program IDs" form field,
   *   with the following keys:
   *   - 'programs': A nested array of Program IDs to insert into the database,
   *     contains the program name to create the form field dropdown. Each
   *     program array has the following keys:
   *     - 'name': A string of the program "code" to insert.
   *   - 'default': The program name expected to be shown by default.
   *   - 'message': The string message to be passed into assertEquals when
   *     evaluating the default program ID name.
   *
   * @dataProvider provideValuesForForm
   */
  #[DataProvider('provideValuesForForm')]
  public function testCrossImporterForm(array $genus, array $programs) {

    $plugin_id = 'trpcultivate-germplasm-cross-importer';
    $importer_label = 'Tripal Cultivate: Germplasm Cross Importer';

    // Insert any organisms if available.
    if ($genus['organisms']) {
      foreach ($genus['organisms'] as $organism) {
        $organism_id = $this->chado_connection->insert('1:organism')
          ->fields($organism)
          ->execute();
        $this->assertIsNumeric($organism_id,
        'We were not able to create the organism "' . $organism['genus'] . $organism['species'] . '" for testing.');
      }
    }

    // Use a CVterm ChadoBuddy to get the cvterm_id for inserting Program IDs.
    $cvterm_record = $this->cvterm_buddy->getCvterm([
      'cv.name' => 'rdfs',
      'cvterm.name' => 'type',
    ]);
    $cvterm_id = $cvterm_record[0]->getValue('cvterm.cvterm_id');
    $this->assertIsNumeric($cvterm_id,
    'We were not able to get the cvterm_id we need for creating Program ID dbprop records.');

    // Insert any Program IDs if available.
    if ($programs['programs']) {
      foreach ($programs['programs'] as $program) {
        // Create the db record for this Program ID.
        $program_record = $this->dbxref_buddy->insertDb([
          'db.name' => $program['name'],
        ]);
        $program_record_id = $program_record->getValue('db.db_id');
        $this->assertIsNumeric($program_record_id,
          'We were not able to create the program "' . $program['name'] . '" in the db table for testing.');
        // Create the dbprop record and link it to the db record.
        $dbprop_id = $this->chado_connection->insert('1:dbprop')
          ->fields([
            'db_id' => $program_record_id,
            'type_id' => $cvterm_id,
            'value' => 'Program ID',
          ])
          ->execute();
        $this->assertIsNumeric($dbprop_id,
          'We were not able to create the dbprop record for program "' . $program['name'] . '" for testing.');
      }
    }

    // Build the form using the Drupal form builder.
    $form = \Drupal::formBuilder()->getForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $plugin_id
    );

    // Ensure we are able to build the form.
    $this->assertIsArray($form,
      'We expect the form builder to return a form but it did not.');
    $this->assertEquals('tripal_admin_form_tripalimporter', $form['#form_id'],
      'We did not get the form id we expected.');

    // Expect there to be no Drupal warnings for this importer.
    $warnings = \Drupal::messenger()->messagesByType('warning');
    $this->assertCount(0, $warnings,
      "We expect no warnings with this importer when the form is first built.");

    // We also expect the full form to be rendered, so check that now.
    // Now that we have provided a plugin_id, we expect it to have a
    // title matching our importer label.
    $this->assertArrayHasKey('#title', $form,
      "The form should have a title set.");
    $this->assertEquals($importer_label, $form['#title'],
      "The title should match the label annotated for our plugin.");
    // The plugin_id stored in a value form element.
    $this->assertArrayHasKey('importer_plugin_id', $form,
      "The form should have an element to save the plugin_id.");
    $this->assertEquals($plugin_id, $form['importer_plugin_id']['#value'],
      "The importer_plugin_id[#value] should be set to our plugin_id.");

    // Check the file fieldset contents.
    $this->assertArrayHasKey('file', $form,
      "We expect there to be a file fieldset but there is not.");
    $this->assertEquals('fieldset', $form['file']['#type'],
      "We expect the file element in the form to be a fieldset.");
    // We expect there to be an upload description including a template link
    // and numbered column description.
    $this->assertArrayHasKey('upload_description', $form['file'],
      "We expect the upload description to have been added to the form by the TripalImporter base class.");
    $this->assertStringContainsString('<a href', $form['file']['upload_description']['#markup'],
      "We expected the upload description to have a link in it.");
    $this->assertStringContainsString('<ol id="tcp-header-notes">', $form['file']['upload_description']['#markup'],
      "We expected the upload description to have an ordered list in it.");
    // We also expect the file upload HTML5 element provided by Tripal
    // and not the file local/remote.
    $this->assertArrayHasKey('file_upload', $form['file'],
      "We expect the file upload element to be added by the Tripal Importer base class.");
    $this->assertArrayNotHasKey('file_local', $form['file'],
      "The local file element should not be available.");
    $this->assertArrayNotHasKey('file_remote', $form['file'],
      "The remote file element should not be available.");

    // Check the Genus form element.
    $this->assertArrayHasKey('genus', $form,
      "We expect there to be an genus form element but there is not.");
    $this->assertEquals('select', $form['genus']['#type'],
      "We expect the genus element in the form to be a select list.");
    // Check that the select list contains all of our genus.
    foreach ($genus['organisms'] as $organism) {
      $this->assertArrayHasKey($organism['genus'], $form['genus']['#options'],
        "We expect the genus select list to contain the genus" . $organism['genus'] . ".");
    }
    // Check the select list's default value.
    $this->assertEquals($genus['default'], $form['genus']['#default_value'],
      $genus['message']);

    // Check the Program ID form element.
    $this->assertArrayHasKey('program_id', $form,
      "We expect there to be an program_id form element but there is not.");
    $this->assertEquals('select', $form['program_id']['#type'],
      "We expect the program_id element in the form to be a select list.");
    // Check that the select list contains all of our program IDs.
    foreach ($programs['programs'] as $program) {
      $this->assertArrayHasKey($program['name'], $form['program_id']['#options'],
        "We expect the program_id select list to contain the program " . $program['name'] . ".");
    }
    // Check the select list's default value.
    $this->assertEquals($programs['default'], $form['program_id']['#default_value'],
      $programs['message']);

    // If there are no programs to select from, we expect an error message to be
    // displayed at the top of the form.
    if (!$programs['programs']) {
      $errors = \Drupal::messenger()->messagesByType('error');
      $this->assertCount(1, $errors,
        "We expect there to be an error message about no Program IDs being available.");
      $this->assertStringContainsString('No Program IDs were found in the database', $errors[0],
        "We expect the error message to contain a warning about no Program IDs being available.");
    }
  }

  /**
   * Tests submitting the importer form when all should be well.
   */
  public function testCrossImporterFormSubmitValid() {
    $plugin_id = 'trpcultivate-germplasm-cross-importer';

    $genus = 'Tripalus';
    $species = 'databasica';

    // Configure the module.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => $genus,
        'species' => $species,
      ])
      ->execute();
    $this->assertIsNumeric($organism_id,
      "We were not able to create an organism for testing.");

    // Use a CVterm ChadoBuddy to get the cvterm_id for inserting Program IDs.
    $buddy_service = \Drupal::service('tripal_chado.chado_buddy');
    $cvterm_buddy = $buddy_service->createInstance('chado_cvterm_buddy', []);
    $cvterm_record = $cvterm_buddy->getCvterm([
      'cv.name' => 'rdfs',
      'cvterm.name' => 'type',
    ]);
    $cvterm_id = $cvterm_record[0]->getValue('cvterm.cvterm_id');
    $this->assertIsNumeric($cvterm_id,
    'We were not able to get the cvterm_id we need for creating Program ID dbprop records.');

    $program_name = 'Test Program';
    $program_id = $this->chado_connection->insert('1:db')
      ->fields([
        'name' => $program_name,
      ])
      ->execute();
    // Create the dbprop record and link it to the db record.
    $dbprop_id = $this->chado_connection->insert('1:dbprop')
      ->fields([
        'db_id' => $program_id,
        'type_id' => $cvterm_id,
        'value' => 'Program ID',
      ])
      ->execute();
    $this->assertIsNumeric($program_id,
          'We were not able to create the program "' . $program_name . '" in the db table for testing.');
    $this->assertIsNumeric($dbprop_id,
          'We were not able to create the dbprop record for program "' . $program_name . '" for testing.');

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => 'crosses_simple.tsv',
      'content' => [
        'file' => 'crosses_simple.tsv',
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/CrossImporterFiles/',
      ],
    ]);

    // Setup the form_state.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('genus', $genus);
    $form_state->setValue('program_id', $program_name);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that we didn't get an error about the genus not being valid.
    $this->assertTrue($form_state->isValidationComplete(),
      "We expect the form state to have been updated to indicate that validation is complete.");
    // Looking for form validation errors.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . " => " . (string) $markup;
    }
    $this->assertCount(0, $form_validation_messages,
      "We should not have any errors but instead we have: " . implode(" AND ", $helpful_output));
  }

  /**
   * Test describeUploadFileFormat() method in the importer.
   */
  public function testDescribeUploadFileFormat() {
    // Create a user.
    $user_username = 'user-collector';
    $user = User::create([
      'name' => $user_username,
      'roles' => ['authenticated user'],
    ]);
    $user->save();

    \Drupal::currentUser()->setAccount($user);

    // Fire up Germplasm Cross Importer Plugin.
    $importer_plugin_manager = \Drupal::service('tripal.importer');
    $plugin_id = 'trpcultivate-germplasm-cross-importer';
    $cross_importer = $importer_plugin_manager->createInstance($plugin_id);

    // Create a file format description section.
    $rendered_file_format_description = $cross_importer->describeUploadFileFormat();

    // Assert headers matched the headers defined by Cross Importer.
    $expected_headers = [
      'Year',
      'Season',
      'Cross Number',
      'Species',
      'Maternal Parent',
      'Paternal Parent',
      'Cross Type',
    ];

    // Pull all the headers in the rendered description.
    preg_match_all('/<strong>(.*?)<\/strong>/', $rendered_file_format_description, $matches);
    $this->assertEquals(
      $expected_headers,
      $matches[1],
      'The headers defined by the importer does not match the headers rendered by describeUploadFileFormat()'
    );

    // Assert admin notes were incorporated into the description section.
    $expected_notes = 'The order of the above columns is important and your file must include a header!';

    $this->assertStringContainsString(
      $expected_notes,
      $rendered_file_format_description,
      'The rendered markup of the method describeUploadFileFormat() does not contain expected file format importer notes'
    );

    // Assert a download link was provided.
    // Construct the template file filename.
    // Only the first item in the 'file_types' importer annotation is used as
    // default file extension of the template file.
    $importer_annotations = $importer_plugin_manager->getDefinitions();
    $expected_file_extension = $importer_annotations['trpcultivate-germplasm-cross-importer']['file_types'][0];
    $expected_template_filename = $plugin_id . '-data-collection-template-file-' . $user_username . '.' . $expected_file_extension;

    $this->assertStringContainsString(
      $expected_template_filename,
      $rendered_file_format_description,
      'The rendered markup of the method describeUploadFileFormat() does not contain the expected file template filename.'
    );
  }

}
