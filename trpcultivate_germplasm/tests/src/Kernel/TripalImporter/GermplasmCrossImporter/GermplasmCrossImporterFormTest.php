<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter\GermplasmCrossImporter;

use Drupal\Core\Form\FormState;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the form + form-related functionality of the Germplasm Cross Importer.
 *
 * @group crossImporter
 */
#[Group('tripal-importer')]
#[Group('chado-importer')]
#[Group('importer-germplasmcross')]
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
  }

  /**
   * Tests building the importer form when all should be well.
   */
  public function testCrossImporterFormValid() {

    $plugin_id = 'trpcultivate-germplasm-cross-importer';
    $importer_label = 'Tripal Cultivate: Germplasm Cross Importer';

    // Configure the module.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id,
      "We were not able to create an organism for testing.");

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

    // Check the Organism form element.
    $this->assertArrayHasKey('organism', $form,
      "We expect there to be an organism form element but there is not.");
    $this->assertEquals('select', $form['organism']['#type'],
      "We expect the organism element in the form to be a select list.");
    // Since one organism was created, we expect it to be selected by default.
    $this->assertEquals($organism_id, $form['organism']['#default_value'],
      "We expect the organism element in the form to default to the organism ID of the one organism we created.");
  }

  /**
   * Tests submitting the importer form when all should be well.
   */
  public function testCrossImporterFormSubmitValid() {
    $plugin_id = 'trpcultivate-germplasm-cross-importer';

    // Configure the module.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id,
      "We were not able to create an organism for testing.");

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
    $form_state->setValue('organism', $organism_id);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that we didn't get an error about the organism not being valid.
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
   * Tests building the importer form when there are no organisms.
   */
  public function testCrossImporterFormNoOrganism() {

    $plugin_id = 'trpcultivate-germplasm-cross-importer';
    $importer_label = 'Tripal Cultivate: Germplasm Cross Importer';

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

    // Expect no Drupal warnings at this point.
    $warnings = \Drupal::messenger()->messagesByType('warning');
    $this->assertCount(0, $warnings,
      "We expect no warnings when the form for this importer is first built.");

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

    // Check the Organism form element.
    $this->assertArrayHasKey('organism', $form,
      "We expect there to be an organism form element but there is not.");
    $this->assertEquals('select', $form['organism']['#type'],
      "We expect the organism element in the form to be a select list.");
    // Since no organism was created, we expected there to be no default.
    $this->assertEquals(0, $form['organism']['#default_value'],
      "We expect the organism element in the form to default to organism ID of 0 since no organisms are available to select.");
  }

  /**
   * Tests submitting the importer form when organism select box is empty.
   *
   * NOTE: Currently being skipped until we handle validation for organism.
   */
  public function testCrossImporterFormSubmitNoOrganism() {
    // Skip this test until we handle validation for organism.
    $this->markTestSkipped('Revisit once organism form field is being validated by a validator plugin.');

    $plugin_id = 'trpcultivate-germplasm-cross-importer';

    // Set our non-existing organism to an unrealistic ID.
    $non_existent_organism_id = 1000;

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => 'crosses_simple.tsv',
      'content' => [
        'file' => 'crosses_simple.tsv',
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/CrossImporterFiles/',
      ],
    ]);

    // INVALID ORGANISM.
    // Setup the form_state.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('organism', $non_existent_organism_id);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that we got an error about the organism not being valid.
    $this->assertTrue($form_state->isValidationComplete(),
      "We expect the form state to have been updated to indicate that validation is complete.");
    // Looking for form validation errors.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . " => " . (string) $markup;
    }
    $this->assertCount(1, $form_validation_messages,
      "We should have exactly one validation error but instead we have: " . implode(" AND ", $helpful_output));
    $this->assertArrayHasKey('organism', $form_validation_messages,
      "We expected the organism form element specifically to have a validation error but instead we have: " . implode(" AND ", $helpful_output));

    // NO ORGANISM.
    // Setup the form_state.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('organism', 0);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that we got an error about the organism not being valid.
    $this->assertTrue($form_state->isValidationComplete(),
      "We expect the form state to have been updated to indicate that validation is complete.");
    // Looking for form validation errors.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . " => " . (string) $markup;
    }
    $this->assertCount(1, $form_validation_messages,
      "We should have exactly one validation error but instead we have: " . implode(" AND ", $helpful_output));
    $this->assertArrayHasKey('organism', $form_validation_messages,
      "We expected the organism form element specifically to have a validation error but instead we have: " . implode(" AND ", $helpful_output));
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
      'Uniquename',
      'Maternal Parent',
      'Paternal Parent',
      'Cross Type',
      'Seed Type',
      'Cotyledon Colour',
      'Comment',
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
    // Construct the templage file filename.
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
