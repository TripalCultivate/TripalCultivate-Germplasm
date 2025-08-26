<?php

namespace Drupal\Tests\trpcultivate_germplasm\Kernel;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\trpcultivate_germplasm\Traits\TripalMviewQueriesTestTrait;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\user\Entity\User;

/**
 * Tests terms added by trpcultivate_germplasm_install_terms() during install.
 *
 * @group TripalCultivate-Germplasm
 * @group Installation
 */
#[Group('TripalCultivate-Germplasm')]
#[Group('Installation')]
class GermplasmTermInstallTest extends ChadoTestKernelBase {

  /**
   * Test Trait for setting up necessary materialized views in Drupal.
   */
  use TripalMviewQueriesTestTrait;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'file',
    'user',
    'path_alias',
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
   * A Database query interface for querying Drupal database.
   *
   * @var \Drupal\core\Database\Database
   */
  protected $drupal_connection;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Set test environment.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Create a test chado instance as needed by our service.
    $this->chado_connection = $this->createTestSchema(ChadoTestKernelBase::PREPARE_TEST_CHADO);

    // Grab our drupal connection.
    $this->drupal_connection = $this->container->get('database');

    // Setup test evironment.
    $this->prepareEnvironment(['TripalTerm']);

    $this->installSchema('tripal', ['tripal_import', 'tripal_jobs']);
    $this->installSchema('tripal_chado', [
      'tripal_cv_obo',
      'tripal_custom_tables',
      'tripal_mviews',
    ]);

    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');

    $this->installConfig([
      'system',
      'trpcultivate_germplasm',
    ]);

    $this->container->get('module_installer')->install([
      'tripal',
      'tripal_chado',
      'trpcultivate_germplasm',
    ]);

    // Create test user.
    $user = User::create([
      'name' => 'user-collector',
      'roles' => ['authenticated user'],
    ]);
    $user->save();

    $this->container->get('current_user')
      ->setAccount($user);

    // Set up our necessary materialized views.
    // This is needed because Tripal Core does not yet setup the Drupal side of
    // the test environment correctly when it comes to materialized views and
    // custom tables. Hopefully that will be resolved in the future.
    $this->materializedViewSetUp();
  }

  /**
   * Test trpcultivate_germplasm_install_terms() method.
   */
  public function testInstallOntologyTerms() {

    // Call our install method.
    // @see trpcultivate_germplasm.module
    trpcultivate_germplasm_install_terms();

    // Test proper install of config type terms (YML):
    $config = \Drupal::service('config.factory')
      ->get('tripal.tripal_content_terms.trpcultivate_germ_terms');

    $vocabs = $config->get('vocabularies');
    foreach ($vocabs as $vocab_info) {
      $source_terms = array_column($vocab_info['terms'], 'name');
      $inserted_terms = $this->chado_connection->select('1:cvterm', 'c')
        ->fields('c', ['cvterm_id', 'name'])
        ->condition('c.name', array_values($source_terms), 'IN')
        ->execute()
        ->fetchAllKeyed();

      $inserted_terms = array_values($inserted_terms);
      $this->assertEquals(
        count($source_terms),
        count($inserted_terms),
        'Install failed to insert the expected number of terms'
      );

      $this->assertEquals(
        $source_terms,
        $inserted_terms,
        'Install failed to insert config terms'
      );
    }

    // Test proper install of ontology terms (OBO):
    $ontologies = [
      'multicrop passport ontology' => [
        'ontology' => 'CO_020',
        'count' => 111,
        'sample' => ['collecting number', 'genus', 'species', '13 long term', '99 other'],
        'obo_path' => '{trpcultivate_germplasm}/ontologies/mcpd_v2.1_151215.obo',
      ],
      'Tripal Cultivate Germplasm Ontology' => [
        'ontology' => 'TRPC',
        'count' => 54,
        'sample' => ['Germplasm Types', 'recurrent parent', 'F5 Seed Count', 'Public', 'Breeding Method'],
        'obo_path' => '{trpcultivate_germplasm}/ontologies/TripalCultivateGermplasmOntology.v1.obo',
      ],
    ];

    foreach ($ontologies as $ontology => $expected) {
      // Test proper install of cv-ontology.
      $obo_path = $this->drupal_connection->select('tripal_cv_obo', 't')
        ->fields('t', ['path'])
        ->condition('t.name', $ontology, '=')
        ->execute()
        ->fetchField();

      $this->assertEquals(
        $obo_path,
        $expected['obo_path'],
        'Install failed to set the correct obo file path for obo: ' . $ontology
      );

      // All sample terms are found.
      $terms = $this->chado_connection->select('1:cvterm', 'c')
        ->fields('c', ['dbxref_id', 'cv_id', 'name'])
        ->condition('c.name', $expected['sample'], 'IN')
        ->execute()
        ->fetchAllAssoc('name');

      $term_names = array_keys($terms);
      $this->assertEmpty(
        array_diff($term_names, $expected['sample']),
        'Install failed to install ontology terms'
      );

      // Pick one term from sample and inspect cv, count, db and ontology setup.
      $a_term = $term_names[mt_rand(0, count($term_names) - 1)];

      $term_cv_name = $this->chado_connection->select('1:cv', 'c')
        ->fields('c', ['name'])
        ->condition('c.cv_id', $terms[$a_term]->cv_id, '=')
        ->execute()
        ->fetchField();

      $this->assertEquals(
        $term_cv_name,
        $ontology,
        'Install failed to set the correct cv name to term'
      );

      $terms_in_cv_count = $this->chado_connection->select('1:cvterm', 'c')
        ->condition('c.cv_id', $terms[$a_term]->cv_id, '=')
        ->countQuery()
        ->execute()
        ->fetchField();

      $this->assertEquals(
        $terms_in_cv_count,
        $expected['count'],
        'Install failed to insert the expected number of terms'
      );

      $subquery = $this->chado_connection->select('1:dbxref', 'x')
        ->fields('x', ['db_id'])
        ->condition('x.dbxref_id', $terms[$a_term]->dbxref_id, '=');

      $term_db = $this->chado_connection->select('1:db', 'd')
        ->fields('d', ['name'])
        ->condition('d.db_id', $subquery, '=')
        ->execute()
        ->fetchField();

      $this->assertEquals(
        $term_db,
        $expected['ontology'],
        'Install failed to set the correct ontology to term'
      );
    }
  }

}
