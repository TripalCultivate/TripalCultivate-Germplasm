<?php

namespace Drupal\trpcultivate_germplasm\EventSubscriber;

use Drupal\Core\State\State;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\tripal\Services\TripalJob;
use Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Services\ChadoTermsInit;
use Drupal\trpcultivate_germplasm\Event\InstallTermEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Prepare module term requirements.
 */
class Preparer implements EventSubscriberInterface {

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected State $state;

  /**
   * Drupal user service.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $user;

  /**
   * Tripal Chado connection.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected $chado_connection;

  /**
   * Chado terms initialize service.
   *
   * @var \Drupal\tripal_chado\Services\ChadoTermsInit
   */
  protected $terms_init;

  /**
   * Drupal database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * Tripal importer plugin manager.
   *
   * @var \Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager
   */
  protected $importer;

  /**
   * Tripal job service.
   *
   * @var \Drupal\Services\TripalJob
   */
  protected $job;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   Drupal user service.
   * @param \Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   Tripal Chado connection.
   * @param \Drupal\tripal_chado\Services\ChadoTermsInit $terms_init
   *   Chado terms initialize service.
   * @param \Drupal\Core\Database\Connection $db
   *   Drupal database connection service.
   * @param \Drupal\tripal\TripalImporter\PluginManagers\TripalImporterManager $importer
   *   Tripal importer plugin manager.
   * @param \Drupal\Services\TripalJob $job
   *   Tripal job service.
   */
  public function __construct(
    State $state,
    AccountInterface $user,
    ChadoConnection $chado_connection,
    ChadoTermsInit $terms_init,
    Connection $db,
    TripalImporterManager $importer,
    TripalJob $job,
  ) {

    $this->state = $state;
    $this->user = $user;
    $this->chado_connection = $chado_connection;
    $this->terms_init = $terms_init;
    $this->db = $db;
    $this->importer = $importer;
    $this->job = $job;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      InstallTermEvent::INSTALL_TERM => ['insertTerms'],
    ];
  }

  /**
   * Subscriber event callback - insert terms (yml and obo) reqiured.
   *
   * @param \Drupal\package_manager\Event\SandboxEvent $event
   *   The event object.
   */
  public function insertTerms(InstallTermEvent $event) {

    // Install terms in config (yml).
    $this->terms_init->installTerms('trpcultivate_germ_terms');

    // Install terms in obo file (obo).
    $ontologies = [
      [
        'name' => 'Multi-Crop Passport Ontology',
        'path' => '{trpcultivate_germplasm}/ontologies/mcpd_v2.1_151215.obo',
      ],
      [
        'name' => 'TripalCultivate Germplasm Ontology',
        'path' => '{trpcultivate_germplasm}/ontologies/TripalCultivateGermplasmOntology.v1.obo',
      ],
    ];

    $schema = $this->chado_connection->getSchemaName();

    foreach ($ontologies as $ontology) {
      // Make sure an OBO with the same name doesn't already exist .
      $obo_id = $this->db->select('tripal_cv_obo', 'tco')
        ->fields('tco', ['obo_id'])
        ->condition('name', $ontology['name'])
        ->execute()
        ->fetchField();

      if ($obo_id) {
        $this->db->update('tripal_cv_obo')
          ->fields([
            'path' => $ontology['path'],
          ])
          ->condition('name', $ontology['name'])
          ->execute();
      }
      else {
        $obo_id = $this->db->insert('tripal_cv_obo')
          ->fields([
            'name' => $ontology['name'],
            'path' => $ontology['path'],
          ])
          ->execute();
      }

      $import_id = $this->db->insert('tripal_import')
        ->fields(
          [
            'uid' => $this->user->id(),
            'class' => 'chado_obo_loader',
            'submit_date' => time(),
            'arguments' => base64_encode(serialize([
              'run_args' => [
                'obo_id' => $obo_id,
                'schema_name' => $schema,
              ],
              'files' => [
                'file_local' => $ontology['path'],
                'file_path' => $ontology['path'],
              ],
            ])),
          ]
        )
        ->execute();

      $this->job->create([
        'job_name' => 'Insert TripalCultivate Germplasm Terms: OBO - ' . $ontology['name'],
        'modulename' => 'trpcultivate_germplasm',
        'callback' => 'tripal_run_importer',
        'arguments' => [$import_id],
        'uid' => $this->user->id(),
      ]);

      $this->job->run();

      /*
      NOTE:
      The create job sequence above does not work if called directly from
      hook_install. ERROR: Call to a member function getPattern() on null

      Does not create a job in hook_install nor in this event subscriber.
      An error is thrown in install hook relating to user entity not ready.

      Setup:
      - add @tripal_chado.connection to service
      - add ChadoConnection $chado to the constructor.

      $schema = $this->chado->getSchemaName();
      $loader = $this->importer->createInstance('chado_obo_loader');
      $loader->createImportJob([
      'obo_id' => $obo_id,
      'schema_name' => $schema,
      ]);

      $loader->run();
      $loader->postRun();
        */

      // Important step - once terms are inserted signal this event
      // has completed its task.
      $this->state->delete('trpcultivate_germplasm.preparer');
    }
  }

}
