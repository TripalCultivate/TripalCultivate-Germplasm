<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\tripal_chado\Database\ChadoConnection;

/**
 * Tests term install by the trpcultivate_germplasm.module.
 *
 * @group trpcultivate_germplasm
 */
class GermplasmTermInstallTest extends ChadoTestKernelBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'tripal',
    'tripal_chado',
    'trpcultivate_germplasm',
  ];

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set test environment.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // // Create a test chado instance as needed by our service.
    $this->chado_connection = $this->createTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);
    $this->prepareEnvironment(['TripalTerm']);
    $this->installSchema('tripal_chado', ['tripal_cv_obo']);
    $this->installSchema('tripal', ['tripal_import', 'tripal_jobs']);

    $this->installConfig(['trpcultivate_germplasm']);
    $this->container->get('module_installer')
      ->install(['trpcultivate_germplasm']);
  }

  /**
   * Test trpcultivate_germplasm_install_terms() method.
   */
  public function testInstallOntologyTerms() {

    // Install terms install 2 sets of term - config(YML) and ontologies(OBO) .
    // Test config type terms:
    /*

    // This test will fail due to terms not being created, likely the install
    // reoutine was not executed in this test.

    // The line to install trpcultivate_germplasm at line 50 is not triggering
    // the install term routine.

    // Note: The functional test InstallTest.php executes the install routine.

    $config = \Drupal::service('config.factory')
      ->get('tripal.tripal_content_terms.trpcultivate_germ_terms');

    $vocabs = $config->get('vocabularies');
    foreach ($vocabs as $vocab_info) {
      $source_terms = array_column($vocab_info['terms'], 'name');
      $inserted_terms = $this->chado_connection->select('1:cvterm', 'c')
        ->fields('c', ['name'])
        ->condition('c.name', $source_terms, 'IN')
        ->execute()
        ->fetchAllKeyed();

      $inserted_terms = array_keys($inserted_terms);
      $this->assertEquals(
        count($source_terms),
        count($inserted_terms),
        'Install failed to insert the expected number of terms'
      );

      $this->assertEquals(
        $source_terms,
        $inserted_terms,
        'Install failed to insert terms: ' . array_diff($source_terms, $inserted_terms)
      );
    }
    */
  }

}
