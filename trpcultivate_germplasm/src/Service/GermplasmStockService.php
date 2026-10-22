<?php

namespace Drupal\trpcultivate_germplasm\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal\Services\TripalLogger;

/**
 * Class GermplasmStockService.
 *
 * @package Drupal\trpcultivate_germplasm\Service
 */
class GermplasmStockService {

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The Tripal logger service.
   *
   * @var \Drupal\tripal\Services\TripalLogger
   */
  protected TripalLogger $tripal_logger;

  /**
   * Constructs a new GermplasmStockService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The Chado database connection.
   * @param \Drupal\tripal\Services\TripalLogger $tripal_logger
   *   The Tripal logger service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ChadoConnection $chado_connection, TripalLogger $tripal_logger) {

    // Chado database.
    $this->chado_connection = $chado;

    // Tripal Logger service.
    $this->logger = $logger;
  }

}
