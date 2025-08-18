<?php

namespace Drupal\Tests\trpcultivate_germplasm\Functional;

use Drupal\Core\Routing\RouteMatch;
use Drupal\Core\Url;
use Drupal\Tests\tripal_chado\Functional\ChadoTestBrowserBase;
use Drupal\tripal_chado\Database\ChadoConnection;

/**
 * Tests to ensure that the module is enabled and terms are installed.
 *
 * @group TripalCultivate-Germplasm
 * @group Installation
 */
class InstallTest extends ChadoTestBrowserBase {

  /**
   * Theme used in the test environment.
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'help',
    'tripal',
    'tripal_chado',
  ];

  /**
   * The name of your module in the .info.yml.
   *
   * @var string
   */
  protected static $module_name = 'Germplasm';

  /**
   * The machine name of this module.
   *
   * @var string
   */
  protected static $module_machinename = 'trpcultivate_germplasm';

  /**
   * A small excerpt from your help page. Do not cross newlines.
   *
   * @var string
   */
  protected static $help_text_excerpt = 'specialized Tripal fields and importers for germplasm';

  /**
   * {@inheritdoc}
   */
  protected function setUp() :void {

    parent::setUp();

    // Ensure we see all logging in tests.
    \Drupal::state()->set('is_a_test_environment', TRUE);

    // Open connection to Chado.
    $this->chado_connection = $this->getTestSchema(ChadoTestBrowserBase::PREPARE_TEST_CHADO);
  }

  /**
   * Tests that a specific set of pages load with a 200 response.
   */
  public function testLoad() {
    $session = $this->getSession();

    // Ensure we have an admin user.
    $user = $this->drupalCreateUser(['access administration pages', 'administer modules']);
    $this->drupalLogin($user);

    $context = '(modules installed: ' . implode(',', self::$modules) . ')';

    // Front Page.
    $this->drupalGet(Url::fromRoute('<front>'));
    $status_code = $session->getStatusCode();
    $this->assertEquals(200, $status_code, "The front page should be able to load $context.");

    // Extend Admin page.
    $this->drupalGet('admin/modules');
    $status_code = $session->getStatusCode();
    $this->assertEquals(200, $status_code, "The module install page should be able to load $context.");
    $this->assertSession()->pageTextContains(self::$module_name);

  }

  /**
   * Tests the module overview help.
   */
  public function testHelp() {
    $session = $this->getSession();

    // Insert the queries required to populate the materialized views.
    $drupal_connection = $this->container->get('database');
    $schema_name = $this->container->get('tripal_chado.database')
      ->getSchemaName();

    $insert_custom_tables = [
      'cv_root_mview' => [
        1,
        'a:4:{s:5:"table";s:13:"cv_root_mview";s:11:"description";s:93:"A list of the root terms for all controlled vocabularies. This is needed for viewing CV trees";s:6:"fields";a:4:{s:4:"name";a:3:{s:4:"type";s:7:"varchar";s:6:"length";i:255;s:8:"not null";b:1;}s:9:"cvterm_id";a:3:{s:4:"size";s:3:"big";s:4:"type";s:3:"int";s:8:"not null";b:1;}s:5:"cv_id";a:3:{s:4:"size";s:3:"big";s:4:"type";s:3:"int";s:8:"not null";b:1;}s:7:"cv_name";a:3:{s:4:"type";s:7:"varchar";s:6:"length";i:255;s:8:"not null";b:1;}}s:7:"indexes";a:2:{s:19:"cv_root_mview_indx1";a:1:{i:0;s:9:"cvterm_id";}s:19:"cv_root_mview_indx2";a:1:{i:0;s:5:"cv_id";}}}',
        1,
        $schema_name,
      ],
      'db2cv_mview' => [
        2,
        'a:4:{s:5:"table";s:11:"db2cv_mview";s:11:"description";s:88:"A table for quick lookup of the vocabularies and the databases they are associated with.";s:6:"fields";a:5:{s:5:"cv_id";a:2:{s:4:"type";s:3:"int";s:8:"not null";b:1;}s:6:"cvname";a:3:{s:4:"type";s:7:"varchar";s:6:"length";s:3:"255";s:8:"not null";b:1;}s:5:"db_id";a:2:{s:4:"type";s:3:"int";s:8:"not null";b:1;}s:6:"dbname";a:3:{s:4:"type";s:7:"varchar";s:6:"length";s:3:"255";s:8:"not null";b:1;}s:9:"num_terms";a:2:{s:4:"type";s:3:"int";s:8:"not null";b:1;}}s:7:"indexes";a:4:{s:9:"cv_id_idx";a:1:{i:0;s:5:"cv_id";}s:10:"cvname_idx";a:1:{i:0;s:6:"cvname";}s:9:"db_id_idx";a:1:{i:0;s:5:"db_id";}s:10:"dbname_idx";a:1:{i:0;s:5:"db_id";}}}',
        1,
        $schema_name,
      ],
    ];

    foreach ($insert_custom_tables as $table => $val) {
      $drupal_connection->insert('tripal_custom_tables')
        ->fields([
          'table_id' => $val[0],
          'table_name' => $table,
          'schema' => $val[1],
          'locked' => $val[2],
          'chado' => $val[3],
        ])
        ->execute();
    }

    $insert_tripal_mviews = [
      'cv_root_mview' => [
        1,
        1,
        'SELECT DISTINCT CVT.name, CVT.cvterm_id, CV.cv_id, CV.name FROM cvterm CVT LEFT JOIN cvterm_relationship CVTR ON CVT.cvterm_id = CVTR.subject_id INNER JOIN cvterm_relationship CVTR2 ON CVT.cvterm_id = CVTR2.object_id INNER JOIN cv CV on CV.cv_id = CVT.cv_id WHERE CVTR.subject_id is NULL and CVT.is_relationshiptype = 0 and CVT.is_obsolete = 0',
        1667003601,
        'Populated with 9 rows',
        'A list of the root terms for all controlled vocabularies. This is needed for viewing CV trees',
      ],
      'db2cv_mview' => [
        2,
        2,
        'SELECT DISTINCT CV.cv_id, CV.name as cvname, DB.db_id, DB.name as dbname, COUNT(CVT.cvterm_id) as num_terms FROM cv CV INNER JOIN cvterm CVT on CVT.cv_id = CV.cv_id INNER JOIN dbxref DBX on DBX.dbxref_id = CVT.dbxref_id INNER JOIN db DB on DB.db_id = DBX.db_id WHERE CVT.is_relationshiptype = 0 and CVT.is_obsolete = 0 GROUP BY CV.cv_id, CV.name, DB.db_id, DB.name ORDER BY DB.name',
        1667003601,
        'Populated with 41 rows',
        'A table for quick lookup of the vocabularies and the databases they are associated with.',
      ],
    ];

    foreach ($insert_tripal_mviews as $table => $val) {
      $drupal_connection->insert('tripal_mviews')
        ->fields([
          'mview_id' => $val[0],
          'table_id' => $val[1],
          'name' => $table,
          'query' => $val[2],
          'last_update' => $val[3],
          'status' => $val[4],
          'comment' => $val[5],
        ])
        ->execute();
    }

    $moduleHandler = $this->container->get('module_handler');
    $moduleInstaller = $this->container->get('module_installer');
    $this->assertFalse($moduleHandler->moduleExists('trpcultivate_germplasm'));
    $this->assertTrue($moduleInstaller->install(['trpcultivate_germplasm']));

    // Ensure we have an admin user.
    $permissions = ['access administration pages', 'administer modules', 'access help pages'];
    $user = $this->drupalCreateUser($permissions);
    $this->drupalLogin($user);

    $context = '(modules installed: ' . implode(',', self::$modules) . ')';

    // Call the hook to ensure it is returning text.
    $some_expected_text = self::$help_text_excerpt;
    $name = 'help.page.' . $this::$module_machinename;
    $match = $this->createStub(RouteMatch::class);
    $hook_name = self::$module_machinename . '_help';
    $output = $hook_name($name, $match);
    $this->assertNotEmpty($output, "The help hook should return output $context.");
    $this->assertStringContainsString($some_expected_text, $output);

    // Help Page.
    $this->drupalGet('admin/help');
    $status_code = $session->getStatusCode();
    $this->assertEquals(200, $status_code, "The admin help page should be able to load $context.");
    $this->assertSession()->pageTextContains(self::$module_name);
    $this->drupalGet('admin/help/' . self::$module_machinename);
    $status_code = $session->getStatusCode();
    $this->assertEquals(200, $status_code, "The module help page should be able to load $context.");
    $this->assertSession()->pageTextContains($some_expected_text);
  }

}
