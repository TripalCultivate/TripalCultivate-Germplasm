<?php
namespace Drupal\Tests\trpcultivate_germplasm\Traits;

use Drupal\Tests\tripal_chado\Kernel\ChadoTestKernelBase;
use Drupal\Tests\user\Traits\UserCreationTrait;

trait GermplasmAccessionImporterTestTrait {

  // Creates a table in chado called stock_synonym
  // This table links synonym names to their associated stocks in the
  // chado.stock table
  protected function createStockSynonymTable() {
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

    $custom_tables = \Drupal::service('tripal_chado.custom_tables');
    $custom_table = $custom_tables->create($table, $this->connection->getSchemaName());
    $custom_table->setTableSchema($schema);
  }
}
