<?php

namespace Drupal\Tests\trpcultivate_germcollection\Kernel\TripalImporter;

use Drupal\Core\Form\FormState;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use PHPUnit\Framework\Attributes\Group;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal\Services\TripalLogger;
use Drupal\Tests\trpcultivate\Traits\TripalCultivateImporterTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests form + form-related functionality of Germplasm Relationship Importer.
 *
 * @group tripal-importer
 * @group chado-importer
 * @group importer-germrelationship
 */
#[Group('tripal-importer')]
#[Group('chado-importer')]
#[Group('importer-germrelationship')]
#[RunTestsInSeparateProcesses]
class GermplasmRelationshipImporterFormTest extends ChadoTestKernelBase {

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
   * Germplasm Relationship Importer plugin instance.
   *
   * @var \Drupal\trpcultivate_germcollection\Plugin\TripalImporter\GermplasmRelationshipImporter
   */
  protected $germplasm_Relationship_importer;

  /**
   * A default listing of annotations associated with the importer.
   *
   * @var array
   */
  protected array $definitions = [
    'test-relationship-importer' => [
      'id' => 'trpcultivate-germplasm-relationship-importer',
      'label' => 'Tripal Cultivate: Relate Germplasm',
      'description' => 'Creates relationships between a single primary accession and related germplasm individuals (both new and existing).',
      'file_types' => ['tsv', 'txt'],
      'upload_title' => 'Related Germplasm*',
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

    $this->module_path = $this->container->get('module_handler')
      ->getModule('trpcultivate_germcollection')
      ->getPath();
  }

  /**
   * Tests building the importer form.
   */
  public function testRelationshipImporterFormValid() {
    $plugin_id = 'trpcultivate-germplasm-relationship-importer';
    $importer_label = 'Tripal Cultivate: Relate Germplasm';

    // Configure the module.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Lens',
        'species' => 'culinaris',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id, 'We were not able to create an organism.');

    // Build the form using Drupal's form builder.
    $form = \Drupal::formBuilder()->getForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $plugin_id
    );
    $this->assertIsArray($form, 'We expect the form builder to return a form but it did not.');
    $this->assertEquals('tripal_admin_form_tripalimporter', $form['#form_id'], 'We did not get the form id we expected.');

    // We also expect the full form to be rendered, so check that now.
    // Now that we have provided a plugin_id, we expect it to have...
    // title matching our importer label.
    $this->assertArrayHasKey('#title', $form, "The form should have a title set.");
    $this->assertEquals($importer_label, $form['#title'], 'The title should match the label annotated for the importer.');
    // The plugin_id stored in a value from element.
    $this->assertArrayHasKey('importer_plugin_id', $form, 'The form should have an element to save the plugin_id.');
    $this->assertEquals($plugin_id, $form['importer_plugin_id']['#value'], 'The importer_plugin_id[#value] should be set to our plugin_id.');

    // Check the file fieldset contents.
    $this->assertArrayHasKey('note', $form, 'We expect there to be a note element on the form but there is not.');
    $this->assertEquals('html_tag', $form['note']['#type'], 'We expect the note element in the form to be an HTML tag.');

    // Check the file fieldset contents.
    $this->assertArrayHasKey('file', $form, 'We expect there to be a file fieldset on the form but there is not.');
    $this->assertEquals('fieldset', $form['file']['#type'], 'We expect the file element in the form to be a fieldset.');
    // We expect there to be an upload description including a template link
    // and numbered column description.
    $this->assertArrayHasKey('upload_description', $form['file'], 'We expect the upload description to be added by TripalImport base class.');
    $this->assertStringContainsString('<a href', $form['file']['upload_description']['#markup'], "We expected the upload description to have a link in it.");
    $this->assertStringContainsString('<ol id="tcp-header-notes">', $form['file']['upload_description']['#markup'], "We expected the upload description to have an ordered list in it.");
    // We also expect the file upload HTML5 element provided by Tripal
    // and not the file local/remote.
    $this->assertArrayHasKey('file_upload', $form['file'],
      "We expect the file upload element to be added by the Tripal Importer base class.");
    $this->assertArrayNotHasKey('file_local', $form['file'],
      "The local file element should not be available.");
    $this->assertArrayNotHasKey('file_remote', $form['file'],
      "The remote file element should not be available.");

    // Check the Relationship toggle element.
    $this->assertArrayHasKey('relationship_toggle', $form['file'], 'We expect there to be an checkbox for relationship toggle field.');
    $this->assertEquals('checkbox', $form['file']['relationship_toggle']['#type'], 'We expect the relationship toggle element in the form to be a checkbox.');
    $this->assertEquals(0, $form['file']['relationship_toggle']['#default_value'], 'We expect the relationship toggle element in the form to be set to false by default.');

    // Check the primary germplasm field element.
    $this->assertArrayHasKey('fieldset_primary_germplasm', $form,
      'We expect there to be a primary germplasm form element on the form but there is not.');
    $this->assertEquals('fieldset', $form['fieldset_primary_germplasm']['#type'],
      'We expect the population element for the title in the form to be a fieldset.');
    $this->assertArrayHasKey('fld_text_primary_germplasm', $form['fieldset_primary_germplasm'], 'We expect there to be a textfield for entering the primary germplasm.');
    $this->assertEquals('textfield', $form['fieldset_primary_germplasm']['fld_text_primary_germplasm']['#type'],
      'We expect the primary germplasm element in the form to be a textfield.');

    // Check the Relationship type element.
    $this->assertArrayHasKey('fieldset_relationship_type', $form,
      'We expect there to be a relationship type form element on the form but there is not.');
    $this->assertEquals('fieldset', $form['fieldset_relationship_type']['#type'],
      'We expect the relationship type element in the form to be a fieldset.');
    $this->assertArrayHasKey('fld_select_relationship_verb', $form['fieldset_relationship_type'], 'We expect there to be an textfield for entering the relationship type.');
    $this->assertEquals('textfield', $form['fieldset_relationship_type']['fld_select_relationship_verb']['#type'],
      'We expect the relationship type element in the form to be a textfield.');
    $this->assertArrayHasKey('fld_radio_stock_position', $form['fieldset_relationship_type'], 'We expect there to be a radio button for selecting the stock position.');
    $this->assertEquals('radios', $form['fieldset_relationship_type']['fld_radio_stock_position']['#type'], 'We expect the stock position element in the form to be a set of radio buttons.');
  }

  /**
   * Tests submitting the importer form with valid input.
   */
  public function testRelationshipImporterFormSubmitValid() {
    $plugin_id = 'trpcultivate-germplasm-relationship-importer';

    // Configure the module.
    $organism_id = $this->chado_connection->insert('1:organism')
      ->fields([
        'genus' => 'Lens',
        'species' => 'culinaris',
      ])
      ->execute();
    $this->assertIsNumeric($organism_id, 'We were not able to cretae an organism.');

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

    // Create a file to upload.
    $file = $this->createTestFile([
      'filename' => 'relationship_importer_example.tsv',
      'content' => [
        'file' => 'relationship_importer_example.tsv',
        'fixturepath' => $this->module_path . '/tests/src/Fixtures/GermplasmRelationshipImporterFiles/',
      ],
    ]);

    // Setup the form_state.
    $form_state = new FormState();
    $form_state->addBuildInfo('args', [$plugin_id]);
    $form_state->setValue('fld_text_primary_germplasm', 'my_stock_1 [cultivar] (1)');
    $form_state->setValue('fld_select_relationship_verb', 'cultivar (EFO:0005136)');
    $form_state->setValue('fld_radio_stock_position', 'evi');
    $form_state->setValue('relationship_toggle', TRUE);
    $form_state->setValue('file_upload', $file->id());

    // Now try validation!
    \Drupal::formBuilder()->submitForm(
      'Drupal\tripal\Form\TripalImporterForm',
      $form_state
    );

    // Check that we got an error about the validation.
    $this->assertTrue($form_state->isValidationComplete(), 'We expect the form state to be updated to indicate that the validation is complete.');
    // Looking for form validation errors.
    $form_validation_messages = $form_state->getErrors();
    $helpful_output = [];
    foreach ($form_validation_messages as $element => $markup) {
      $helpful_output[] = $element . " => " . (string) $markup;
    }
    $this->assertCount(0, $form_validation_messages,
      "We should not have any errors but instead we have: " . implode(" AND ", $helpful_output));
  }

}
