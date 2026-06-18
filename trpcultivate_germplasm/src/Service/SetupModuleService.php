<?php

namespace Drupal\trpcultivate_germplasm\Service;

use Drupal\Core\Database\Connection;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Services\ChadoCustomTableManager;
use Drupal\tripal_chado\Services\ChadoTermsInit;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager;

/**
 * Service class for installing terms and ontologies.
 */
class SetupModuleService {

  /**
   * The database connection for querying Chado.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The drupal database connection.
   *
   * @var Drupal\Core\Database\Connection
   */
  protected Connection $drupal_connection;

  /**
   * The Tripal Chado custom tables service.
   *
   * @var Drupal\tripal_chado\Services\ChadoCustomTableManager
   */
  protected ChadoCustomTableManager $custom_tables;

  /**
   * The Tripal Chado terms init service.
   *
   * @var Drupal\tripal_chado\Services\ChadoTermsInit
   */
  protected ChadoTermsInit $terms_init;

  /**
   * The TripalLogger service.
   *
   * @var Drupal\tripal\Services\TripalLogger
   */
  protected $logger;

  /**
   * The Tripal Importer Manager.
   *
   * @var Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager
   */
  protected TripalImporterManager $importer_manager;

  /**
   * Constructor for the service.
   *
   * @param Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The database connection for querying Chado.
   * @param Drupal\Core\Database\Connection $drupal_connection
   *   The drupal database connection.
   * @param Drupal\tripal_chado\Services\ChadoCustomTableManager $custom_tables
   *   The Tripal Chado custom tables service.
   * @param Drupal\tripal_chado\Services\ChadoTermsInit $terms_init
   *   The Tripal Chado terms init service.
   * @param Drupal\tripal\Services\TripalLogger $logger
   *   The TripalLogger service.
   * @param Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager $importer_manager
   *   The Tripal Importer manager.
   */
  public function __construct(
    ChadoConnection $chado_connection,
    Connection $drupal_connection,
    ChadoCustomTableManager $custom_tables,
    ChadoTermsInit $terms_init,
    TripalLogger $logger,
    TripalImporterManager $importer_manager,
  ) {
    $this->chado_connection = $chado_connection;
    $this->drupal_connection = $drupal_connection;
    $this->custom_tables = $custom_tables;
    $this->terms_init = $terms_init;
    $this->logger = $logger;
    $this->importer_manager = $importer_manager;
  }

  /**
   * Create the custom tables for this module.
   */
  public function createCustomTables() {
    // Create the stock_synonym linking table.
    $table = 'stock_synonym';
    $schema = [
      'table' => 'stock_synonym',
      'description' => 'Linking table between stock and synonym.',
      'fields' => [
        'stock_synonym_id' => [
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'synonym_id' => [
          'size' => 'big',
          'type' => 'int',
          'not null' => TRUE,
        ],
        'stock_id' => [
          'size' => 'big',
          'type' => 'int',
          'not null' => TRUE,
        ],
        'pub_id' => [
          'size' => 'big',
          'type' => 'int',
          'not null' => TRUE,
        ],
        'is_current' => [
          'type' => 'int',
          'default' => 0,
        ],
        'is_internal' => [
          'type' => 'int',
          'default' => 0,
        ],
      ],
      'primary key' => [
        'stock_synonym_id',
      ],
      'unique_keys' => [
        'stock_synonym_c1' => ['synonym_id', 'stock_id', 'pub_id'],
      ],
      'indexes' => [
        'stock_synonym_idx1' => [
          0 => 'synonym_id',
        ],
        'stock_synonym_idx2' => [
          0 => 'stock_id',
        ],
        'stock_synonym_idx3' => [
          0 => 'pub_id',
        ],
      ],
      'foreign keys' => [
        'synonym' => [
          'table' => 'synonym',
          'columns' => [
            'synonym_id' => 'synonym_id',
          ],
        ],
        'stock' => [
          'table' => 'stock',
          'columns' => [
            'stock_id' => 'stock_id',
          ],
        ],
        'pub' => [
          'table' => 'pub',
          'columns' => [
            'pub_id' => 'pub_id',
          ],
        ],
      ],
    ];

    $custom_table = $this->custom_tables->create($table, $this->chado_connection);
    $custom_table->setTableSchema($schema);
    $custom_table->setLocked(TRUE);
  }

  /**
   * Import ontologies and insert terms needed by this module.
   *
   * Expected but not required to be run by a Tripal Job.
   */
  public function installTerms() {
    // Insert our config terms (YML file).
    $config_id = 'trpcultivate_germ_terms';
    $this->terms_init->installTerms($config_id);

    // Insert our ontologies (OBO file).
    $schema_name = $this->chado_connection->getSchemaName();

    $obo_importer = $this->importer_manager->createInstance('chado_obo_loader');

    $ontologies = [
      [
        'name' => 'multicrop passport ontology',
        'path' => '{trpcultivate_germplasm}/ontologies/mcpd_v2.1_151215.obo',
      ],
      [
        'name' => 'Tripal Cultivate Germplasm Ontology',
        'path' => '{trpcultivate_germplasm}/ontologies/TripalCultivateGermplasmOntology.v1.obo',
      ],
    ];

    // Iterate through each ontology and install them with the OBO Importer.
    foreach ($ontologies as $ontology) {
      // Make sure an OBO with the same name doesn't already exist.
      $obo_id = $this->drupal_connection->select('tripal_cv_obo', 'tco')
        ->fields('tco', ['obo_id'])
        ->condition('name', $ontology['name'])
        ->execute()
        ->fetchField();

      if ($obo_id) {
        $this->drupal_connection->update('tripal_cv_obo')
          ->fields([
            'path' => $ontology['path'],
          ])
          ->condition('name', $ontology['name'])
          ->execute();
      }
      else {
        $obo_id = $this->drupal_connection->insert('tripal_cv_obo')
          ->fields([
            'name' => $ontology['name'],
            'path' => $ontology['path'],
          ])
          ->execute();
      }

      if ($obo_id) {
        $this->logger->notice("Importing " . $ontology['name']);

        $obo_importer->createImportJob(
          [
            'obo_id' => $obo_id,
            'schema_name' => $schema_name,
          ],
          [
            'file_path' => $ontology['path'],
            'file_local' => $ontology['path'],
          ]
        );

        $obo_importer->run();
        $obo_importer->postRun();
      }
      else {
        $this->logger->error("Tripal Cultivate Germplasm could not insert or find an obo record for ontology " . $ontology['name']);
      }
    }
  }

  /**
   * Runs all setup tasks for this module.
   *
   * Expected to be run by a Tripal Job.
   */
  public static function runSetupModuleTripalJob($job_id) {

    // Get the service.
    $service = \Drupal::service('trpcultivate_germplasm.setup_module');

    // Create custom tables needed by this module.
    $service->createCustomTables();

    // Submit job to install terms needed by this module.
    $service->installTerms();
  }

}
