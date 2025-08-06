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

    // Install module configuration.
    $this->installConfig(['trpcultivate_germplasm']);
  }

  /**
   * Test trpcultivate_germplasm_install_terms() method.
   */
  public function testInstallOntologyTerms() {

    trpcultivate_germplasm_install_terms();

    // Call defineTerms in Term Service.
    $terms = \Drupal::service('trpcultivate_germplasm.terms')
      ->defineTerms();

    foreach ($terms as $term) {
      // Check if the term exists in the database.
      $exists = $this->chado_connection->query('SELECT * FROM {1:cvterm} WHERE name = :name', [':name' => $term['name']])->fetchField();

      // Assert that the term exists.
      $this->assertNotEmpty($exists, "The term '{$term['name']}' should exist in the database.");
    }
  }

}
