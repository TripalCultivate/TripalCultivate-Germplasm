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
    'tripal_biodb',
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

    // Create a test chado instance as needed by our service.
    $this->chado_connection = $this->createTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    $this->prepareEnvironment(['TripalTerm']);
    $this->installSchema('tripal_chado', ['tripal_cv_obo']);

    // Install module configuration.
    $this->installConfig(['trpcultivate_germplasm']);
  }

  /**
   * Test trpcultivate_germplasm_install_terms() method.
   */
  public function testInstallOntologyTerms() {

    trpcultivate_germplasm_install_terms();

    // Install terms install 2 sets of term - config (YML) and ontologies (OBO).
    // Test config type terms:
    $config = \Drupal::service('config.factory')
      ->get('tripal.tripal_content_terms.trpcultivate_germ_terms');

    $vocabs = $config->get('vocabularies');
    foreach ($vocabs as $vocab_info) {
      foreach ($vocab_info['terms'] as $term) {
        $term_name = $term['name'];

        $term_name_ins = $this->chado_connection->select('1:cvterm', 'c')
          ->fields('c', ['name'])
          ->condition('c.name', $term_name, '=')
          ->execute()
          ->fetchField();

        $this->assertEquals(
          $term_name,
          $term_name_ins,
          'Install failed to insert the term: ' . $term_name
        );
      }
    }

    // Test ontology type terms:
  }

}
