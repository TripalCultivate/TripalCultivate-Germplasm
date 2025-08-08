<?php

namespace Drupal\trpcultivate_germplasm\Event;

/**
 * Defines event.
 */
final class InstallTermEvent {

  /**
   * Name of event just after TripalCultivate Germplasm module install.
   * 
   * This event allows this module to install terms required.
   * 
   * @Event
   * 
   * @var string
   */
  const INSTALL_TERM = 'trpcultivate_germplasm.preparer';

}
