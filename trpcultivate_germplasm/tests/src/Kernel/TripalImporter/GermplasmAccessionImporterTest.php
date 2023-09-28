<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\TripalImporter;

use Drupal\Core\Url;
use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;

/**
 * Tests the functionality of the Germplasm Accession Importer.
 */
class GermplasmAccessionImporterTest extends ChadoTestKernelBase {

	protected $defaultTheme = 'stark';

	protected static $modules = ['system', 'user', 'file', 'tripal', 'tripal_chado', 'trpcultivate_germplasm'];

  protected $importer;

  protected $definitions = [
    'test-germplasm-accession' => [
      'id' => 'trpcultivate-germplasm-accession',
      'label' => 'Tripal Cultivate: Germplasm Accessions',
      'description' => 'Imports germplasm accessions into Chado with metadata meeting BrAPI standards.',
      'file_types' => ["tsv", "txt"],
      'use_analysis' => FALSE,
      'require_analysis' => FALSE,
      'upload_title' => 'Germplasm Accession Import',
      'upload_description' => 'This should not be visible!',
      'button_text' => 'Import Germplasm Accessions',
      'file_upload' => True,
      'file_load' => True,
      'file_remote' => True,
      'file_required' => True,
      'cardinality' => 1,
    ],
  ];

	/**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

		// Open connection to Chado
		$this->connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Ensure we can access file_managed related functionality from Drupal.
    // ... users need access to system.action config?
    $this->installConfig('system');
    // ... managed files are associated with a user.
    $this->installEntitySchema('user');
    // ... Finally the file module + tables itself.
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);

    // We need to mock the logger to test the progress reporting.
    $container = \Drupal::getContainer();
    $mock_logger = $this->getMockBuilder(\Drupal\tripal\Services\TripalLogger::class)
      ->onlyMethods(['notice','error'])
      ->getMock();
    // $mock_logger->method('notice')
    //   ->willReturnCallback(function($message, $context, $options) {
    //     print str_replace(array_keys($context), $context, $message);
    //     return NULL;
    //   });
    $mock_logger->method('error')
      ->willReturnCallback(function($message, $context, $options) {
        print str_replace(array_keys($context), $context, $message);
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    $this->importer = new \Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmAccessionImporter(
      [],
      'trpcultivate-germplasm-accession',
      $this->definitions
    );
  }

	/**
   * Tests focusing on the Germplasm Accession Importer form.
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterForm() {

		$plugin_id = 'trpcultivate-germplasm-accession';

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

    // Now that we have provided a plugin_id, we expect it to have...
    // title matching our importer label.
    $this->assertArrayHasKey('#title', $form,
      "The form should have a title set.");
    $this->assertEquals('Tripal Cultivate: Germplasm Accessions', $form['#title'],
      "The title should match the label annotated for our plugin.");
    // the plugin_id stored in a value form element.
    $this->assertArrayHasKey('importer_plugin_id', $form,
      "The form should have an element to save the plugin_id.");
    $this->assertEquals($plugin_id, $form['importer_plugin_id']['#value'],
      "The importer_plugin_id[#value] should be set to our plugin_id.");
    // a submit button.
    $this->assertArrayHasKey('button', $form,
      "The form should not have a submit button since we indicated a specific importer.");

    // We should also have our importer-specific form elements added to the form!
    $this->assertArrayHasKey('instructions', $form,
      "The form should include an instructions form element.");
		$this->assertArrayHasKey('genus_name', $form,
      "The form should include a genus_name form element.");
		$this->assertArrayHasKey('file', $form,
      "The form should include a file form element.");

    // Our default annotation indicates there should be no analysis element.
    $this->assertArrayNotHasKey('analysis_id', $form,
      "The from should not include analysis element, yet one exists.");
	}

  /**
   * Tests focusing on the Germplasm Accession Importer getOrganismID() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterGetOrganismID() {

    // Insert an organism
    $subtaxa_cvterm_id = $this->getCVtermID('TAXRANK', '0000023');

    $organism_id = $this->connection->insert('1:organism')
      ->fields([
        'genus' => 'Tripalus',
        'species' => 'databasica',
        'infraspecific_name' => 'chadoii',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    $grabbed_organism_id = $this->importer->getOrganismID('Tripalus', 'databasica', 'subspecies chadoii');
    $this->assertEquals($grabbed_organism_id, $organism_id, "The organism ID grabbed by the importer does not match the one that was inserted into the database.");

    // Try an organism that does not currently exist
    //$non_existent_organism_id = $this->importer->getOrganismID('Nullus', 'organismus');
    //assertTrue($this->importer->error_tracker);

  }
}
