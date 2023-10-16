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
    $mock_logger->method('notice')
       ->willReturnCallback(function($message, $context, $options) {
         print str_replace(array_keys($context), $context, $message);
         return NULL;
       });
    $mock_logger->method('error')
      ->willReturnCallback(function($message, $context, $options) {
        print str_replace(array_keys($context), $context, $message);
        return NULL;
      });
    $container->set('tripal.logger', $mock_logger);

    $this->importer = new \Drupal\trpcultivate_germplasm\Plugin\TripalImporter\GermplasmAccessionImporter(
      [],
      'trpcultivate-germplasm-accession',
      $this->definitions,
      $this->connection
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
    ob_start();
    $non_existent_organism_id = $this->importer->getOrganismID('Nullus', 'organismus', '');
    $printed_output = ob_get_clean();
    $this->assertTrue($printed_output == 'Could not find an organism "Nullus organismus" in the database.', "Did not get the expected error message when testing for a non-existant organism.");

    // Not testing if multiple organisms are retrieved, since Chado should be preventing such a situation
  }

  /**
   * Tests focusing on the Germplasm Accession Importer getStockID() function
   *
   * @group germ_accession_importer
   */
  public function testGermplasmAccessionImporterGetStockID() {

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

    // Insert a stock
    // @TODO: FIND THE APPROPRIATE CVTERM ID FOR ACCESSION
    $accession_cvterm_id = $this->getCVtermID('TAXRANK', '0000024');

    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => $accession_cvterm_id,
      ])
      ->execute();

    // Test that the stock just inserted gets selected
    $grabbed_stock_id = $this->importer->getStockID('stock1', 'TEST:1', $organism_id);
    $this->assertEquals($grabbed_stock_id, $stock_id, "The stock ID grabbed by the importer does not match the one that was inserted into the database.");

    // Test that a stock not in the database successfully gets inserted
    //ob_start();
    //$created_stock_id = $this->importer->getStockID('stock2', 'TEST:2', $organism_id);
    //$printed_output = ob_get_clean();
    //$this->assertTrue($printed_output == 'Inserting "stock2".', "Did not get the expected notice message when inserting a new stock.");

    // Test for a stock name + organism that already exists but has a different accession

    ob_start();
    $grabbed_dup_stock_name = $this->importer->getStockID('stock1', 'TEST:1000', $organism_id);
    $printed_output = ob_get_clean();
    $this->assertTrue($printed_output == 'A stock already exists for "stock1" but with an accession of "TEST:1" which does not match the input file.', "Did not get the expected error message when testing for duplicate stock names.");

    // Now test for multiple stocks with the same name and accession, but different type_id
    $stock_id = $this->connection->insert('1:stock')
      ->fields([
        'organism_id' => $organism_id,
        'name' => 'stock1',
        'uniquename' => 'TEST:1',
        'type_id' => $subtaxa_cvterm_id,
      ])
      ->execute();

    ob_start();
    $grabbed_dup_stock_id = $this->importer->getStockID('stock1', 'TEST:1', $organism_id);
    $printed_output = ob_get_clean();
    $this->assertTrue($printed_output == 'Found more than one stock ID for "stock1".', "Did not get the expected error message when testing for duplicate stock IDs.");

  }

}
