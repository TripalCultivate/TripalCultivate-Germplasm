<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel\Service;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\tripal_chado\Database\ChadoConnection;

/**
 * Tests the setup of the Tripal Cultivate Germplasm module.
 */
#[RunTestsInSeparateProcesses]
class SetupModuleServiceTest extends ChadoTestKernelBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path',
    'path_alias',
    'views',
    'field',
    'file',
    'field_ui',
    'field_group',
    'tripal',
    'tripal_chado',
    'tripal_layout',
    'trpcultivate',
    'trpcultivate_germplasm',
  ];

  /**
   * Connection to Chado schema.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The service.
   *
   * @var \Drupal\trpcultivate_germplasm\Service\SetupModuleService
   */
  protected $setupService;

  /**
   * Sets up the test.
   */
  protected function setUp(): void {
    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Add any schema needed for the functionality I am testing.
    $this->prepareEnvironment(['TripalEntity', 'TripalTerm']);

    $this->installConfig(['trpcultivate', 'trpcultivate_germplasm']);

    $this->installSchema('tripal', ['tripal_jobs', 'tripal_collection']);
    $this->installSchema('tripal_chado', ['tripal_custom_tables']);
    $this->installConfig('tripal_chado');
    // Initialize the chado instance with all the records
    // that would be present after running prepare.
    $this->chado_connection = $this->getTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // ... we need our own modules config.
    $this->setupService = \Drupal::service('trpcultivate_germplasm.setup_module');
  }

  /**
   * Tests the creation of custom tables.
   */
  public function testCreateCustomTables() {

    // Ensure the table doesn't exist before running the method.
    $this->assertFalse(
      $this->chado_connection->schema()->tableExists('stock_synonym'),
      'The stock_synonym table already exists.'
    );

    // Run the method to create custom tables.
    $this->setupService->createCustomTables();

    // Check that the table was created.
    $this->assertTrue(
      $this->chado_connection->schema()->tableExists('stock_synonym'),
      'The stock_synonym table was not created.'
    );
  }

}
