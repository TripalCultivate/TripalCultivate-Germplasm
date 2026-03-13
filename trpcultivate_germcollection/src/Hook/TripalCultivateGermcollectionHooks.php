<?php

namespace Drupal\trpcultivate_germcollection\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Implements hooks for the Tripal Cultivate Germcollection module.
 */
class TripalCultivateGermcollectionHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      // Provides the module overview in the help tab.
      case 'help.page.trpcultivate_germcollection':
        $output = '';
        $output .= '<h3>' . $this->t('About') . '</h3>';

        $output .= '<p>' . $this->t('This module provides support for grouping germplasm into collections and specialized Tripal fields.') . '</p>';

        return $output;

      default:
    }
  }

}
