<?php

namespace Drupal\Tests\trpcultivate_germplasm\Traits;

/**
 * Needed by tests that rely on materialized views, custom tables and importers.
 *
 * Inserts necessary materialized views in Drupal that are missing in a testing
 * environment.
 *
 * This is needed because Tripal Core does not yet setup the Drupal side of
 * the test environment correctly when it comes to materialized views and
 * custom tables. Hopefully that will be resolved in the future.
 */
trait TripalMviewQueriesTestTrait {

  /**
   * Creates and populates the required materialized views in Drupal by Chado.
   */
  protected function materializedViewSetUp() {
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
  }

}
