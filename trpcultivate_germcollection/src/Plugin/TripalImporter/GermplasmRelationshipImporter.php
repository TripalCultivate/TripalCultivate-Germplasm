<?php

namespace Drupal\trpcultivate_germcollection\Plugin\TripalImporter;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\Services\TripalFileRetriever;
use Drupal\tripal\Services\TripalLogger;
use Drupal\tripal\TripalBackendPublish\PluginManager\TripalBackendPublishManager;
use Drupal\tripal\TripalImporter\Attribute\TripalImporter;
use Drupal\tripal_chado\ChadoBuddy\PluginManagers\ChadoBuddyPluginManager;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
use Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoOrganismBuddy;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Drupal\trpcultivate\Plugin\Validators\EmptyCell;
use Drupal\trpcultivate\Plugin\Validators\GermplasmNameExists;
use Drupal\trpcultivate\Plugin\Validators\ValidDataFile;
use Drupal\trpcultivate\Plugin\Validators\ValidDelimitedFile;
use Drupal\trpcultivate\Plugin\Validators\ValidHeaders;
use Drupal\trpcultivate\Service\TripalCultivateFileTemplateService;
use Drupal\trpcultivate\Service\ImportValidationHelper;
use Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 *
 */
#[TripalImporter(
  id: 'trpcultivate-germplasm-relationship-importer',
  label: new TranslatableMarkup('Tripal Cultivate: Relate Germplasm'),
  description: new TranslatableMarkup('Creates relationships between a single primary accession and related germplasm individuals (both new and existing).'),
  file_types: ['tsv', 'txt'],
  upload_description: new TranslatableMarkup('Please provide a data file.'),
  upload_title: new TranslatableMarkup('<strong>Related Germplasm*</strong>'),
  use_analysis: FALSE,
  require_analysis: FALSE,
  use_button: TRUE,
  submit_disabled: FALSE,
  button_text: new TranslatableMarkup('Import'),
  file_upload: TRUE,
  file_local: FALSE,
  file_remote: FALSE,
  file_required: TRUE,
  cardinality: 1,
  menu_path: '',
  callback: '',
  callback_path: '',
)]
class GermplasmRelationshipImporter extends ChadoImporterBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * The key to reference the validation result array in Drupal storage system.
   *
   * @var string
   */
  private const VALIDATION_RESULT = 'validation_result';

  /**
   * The Drupal Messenger Service.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $service_Messenger;

  /**
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $service_entityTypeManager;

  /**
   * The TripalCultivate validator plugin manager.
   *
   * @var \Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager
   */
  protected TripalCultivateValidatorManager $service_validatorPluginManager;

  /**
   * The TripalCultivate File Template Service.
   *
   * @var \Drupal\trpcultivate\Service\TripalCultivateFileTemplateService
   */
  protected TripalCultivateFileTemplateService $service_FileTemplate;

  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected Renderer $service_Renderer;

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var \Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

  /**
   * The Chado Buddy service manager.
   *
   * @var Drupal\tripal_chado\ChadoBuddy\PluginManagers\ChadoBuddyPluginManager
   */
  protected ChadoBuddyPluginManager $buddy_manager;

  /**
   * An instance of the organism Chado Buddy.
   *
   * @var Drupal\tripal_chado\Plugin\ChadoBuddy\ChadoOrganismBuddy
   */
  protected ChadoOrganismBuddy $organism_buddy;

  /**
   * Headers required by this importer.
   *
   * @var array
   *
   * The following keys are required:
   * - 'name': The column header name as it should appear in the input file.
   * - 'description': A user-friendly description of the header that will be
   *   displayed to the user through the form.
   * - 'type': one of "required" or "optional" to indicate whether the column
   *   needs to have values present or not.
   *
   * NOTE: Order MUST reflect the desired order of headers in the input file.
   */
  protected array $headers = [
    [
      'name' => 'Name',
      'description' => 'The name of the germplasm individual. For existing germplasm individuals, this must match the name in this site exactly including capitalization and spaces.',
      'type' => 'required',
    ],
    [
      'name' => 'Type',
      'description' => 'The type of the germplasm individual. This must be an existing ontology term in the site and follows the format "TERM NAME (ID SPACE:ACCESSION)". For example, if the individual is a germplasm accession then type would be "germplasm (EFO:0007059)", whereas, if it is a cross, the type would be "progeny (PBO:0000065)".',
      'type' => 'required',
    ],
    [
      'name' => 'Scientific Name',
      'description' => 'The scientific name of the germplasm individual. Specifically, this will include the genus, species and infraspecies (when present).',
      'type' => 'required',
    ],
    [
      'name' => 'Unique Name',
      'description' => "A name that uniquely identifies this germplasm individual within its species. This is usually its accession in a genebank.",
      'type' => 'optional',
    ],
  ];

  /**
   * Number of required columns.
   *
   * @var int
   */
  protected int $required_column_count;

  /**
   * Looked up organism ids, keyed by scientific name.
   *
   * @var array
   */
  protected $organism_ids = [];

  /**
   * Constructs the Germpalsm Relationship importer.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The connection to the Chado database.
   * @param Drupal\tripal_chado\ChadoBuddy\PluginManagers\ChadoBuddyPluginManager $buddy_manager
   *   The ChadoBuddy plugin manager.
   * @param Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager $service_validatorPluginManager
   *   The TripalCultivate validator plugin manager.
   * @param Drupal\trpcultivate\Service\TripalCultivateFileTemplateService $service_FileTemplate
   *   The service used to generate the termplate file.
   * @param Drupal\Core\Entity\EntityTypeManager $service_entityTypeManager
   *   The entity type manager.
   * @param Drupal\Core\Render\Renderer $renderer
   *   The Drupal renderer service.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The Drupal messenger service.
   * @param Drupal\tripal\Services\TripalLogger $logger
   *   Tripal Logger service.
   * @param Drupal\tripal\Services\TripalFileRetriever $fileretriever
   *   Tripal File Retriever service.
   * @param Drupal\tripal\TripalBackendPublish\PluginManager\TripalBackendPublishManager $publish_manager
   *   Tripal Backend Publish plugin manager.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ChadoConnection $chado_connection,
    ChadoBuddyPluginManager $buddy_manager,
    TripalCultivateValidatorManager $service_validatorPluginManager,
    TripalCultivateFileTemplateService $service_FileTemplate,
    EntityTypeManager $service_entityTypeManager,
    Renderer $renderer,
    Messenger $messenger,
    TripalLogger $logger,
    TripalFileRetriever $fileretriever,
    TripalBackendPublishManager $publish_manager,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $chado_connection,
      $messenger,
      $logger,
      $fileretriever,
      $publish_manager,
    );

    $this->service_validatorPluginManager = $service_validatorPluginManager;
    $this->service_FileTemplate = $service_FileTemplate;
    $this->service_entityTypeManager = $service_entityTypeManager;
    $this->service_Renderer = $renderer;
    $this->service_Messenger = $messenger;
    // Chado database.
    $this->chado_connection = $chado_connection;
    $this->buddy_manager = $buddy_manager;
    $this->organism_buddy = $this->buddy_manager->createInstance('chado_organism_buddy', []);
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('tripal_chado.database'),
      $container->get('tripal_chado.chado_buddy'),
      $container->get('plugin.manager.trpcultivate_validator'),
      $container->get('trpcultivate.template_generator'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
      $container->get('tripal.logger'),
      $container->get('tripal.fileretriever'),
      $container->get('tripal.backend_publish'),
    );
  }

  /**
   * Get the number of required columns.
   *
   * @return int
   *   The number of required columns.
   */
  public function getRequiredColumnsCount() {
    $this->required_column_count = count(array_filter($this->headers, function ($h) {
      return $h['type'] == 'required';
    }));
    return $this->required_column_count;
  }

  /**
   * {@inheritDoc}
   */
  public function configureValidators(array $form_values, string $file_mime_type) {

    $validators = [];

    // Make the header columns into a simplified array for easy reference:
    // - Keyed by the column header name.
    // - Values are the column header's position in the $headers property (ie.
    //   its index if we assume no keys were assigned).
    $header_index = [];
    $headers = $this->headers;
    foreach ($headers as $i => $column_details) {
      $header_index[$column_details['name']] = $i;
    }

    // Configure the valid data file validator.
    $instance_data_file = $this->service_validatorPluginManager->createInstance('valid_data_file');
    $instance_data_file->setFileMimeType($file_mime_type);
    $supported_file_extensions = $this->plugin_definition['file_types'];
    $instance_data_file->setSupportedMimeTypes($supported_file_extensions);
    $validators['file']['valid_data_file'] = $instance_data_file;

    // Configure the valid delimitted file validator.
    $instance_delimited = $this->service_validatorPluginManager->createInstance('valid_delimited_file');

    $this->getRequiredColumnsCount();
    $instance_delimited->setExpectedColumns($this->required_column_count, FALSE);
    $instance_delimited->setFileMimeType($file_mime_type);
    $validators['raw-row']['valid_delimited_file'] = $instance_delimited;

    // Configure the header row validator.
    $instance_header_row = $this->service_validatorPluginManager->createInstance('valid_headers');

    $instance_header_row->setHeaders($this->headers);
    $instance_header_row->setExpectedColumns(count($this->headers), TRUE);
    $validators['header-row']['valid_headers'] = $instance_header_row;

    // Configure the empty cell validator.
    // If the relationship toggle indicates that related germplasm must already
    // exist (i.e. is TRUE) then the uniquename is optional; however, if we may
    // need to insert related germplasm then the uniquename is also required.
    $instance_empty_cell = $this->service_validatorPluginManager->createInstance('empty_cell');

    $indices = array_keys(array_filter($this->headers, function ($h) {
      return $h['type'] == 'required';
    }));

    $instance_empty_cell->setIndices($indices);
    $validators['data-row']['empty_cell'] = $instance_empty_cell;

    // Create relationship only toggle is on as in this case
    // the related germplasm must already exist which is what
    // this validator checks.
    if ($form_values['relationship_toggle']) {
      $instance_name_exists = $this->service_validatorPluginManager->createInstance('germplasm_name_exists');

      $indices = [
        $header_index['Name'],
      ];
      $instance_name_exists->setIndices($indices);
      $validators['data-row']['germplasm_name_exists'] = $instance_name_exists;
    }

    return $validators;
  }

  /**
   * {@inheritDoc}
   */
  public function processValidationMessages($failures) {
    $messages = [
      'valid_data_file' => [
        'title' => 'File is valid and not empty',
        'status' => 'todo',
        'details' => '',
      ],
      'valid_delimited_file' => [
        'title' => 'Lines are properly delimited',
        'status' => 'todo',
        'details' => '',
      ],
      'valid_headers' => [
        'title' => 'File has all of the column headers expected',
        'status' => 'todo',
        'details' => '',
      ],
      'empty_cell' => [
        'title' => 'Required cells contain a value',
        'status' => 'todo',
        'details' => '',
      ],
    ];

    // Get the header names from the headers array.
    $header_names = array_column($this->headers, 'name');

    // A flag to indicate whether any data row level validation can be set to
    // pass or remains as 'todo' if there are no failures at that stage. This is
    // because we don't want to mislead the user to think all data rows pass
    // validation if there are raw rows that failed, since they haven't been
    // looked at yet by data row validators.
    $raw_row_failed = FALSE;

    // Call the processItemWithSimpleList() method in ValidDataFile
    // class to check if there are any failures for the valid data
    // file validator.
    if (array_key_exists('valid_data_file', $failures)) {
      if (!empty($failures['valid_data_file'])) {
        $messages['valid_data_file']['status'] = 'fail';
        $messages['valid_data_file']['details'] = ValidDataFile::processItemWithSimpleList($failures['valid_data_file']);
      }
      else {
        $messages['valid_data_file']['status'] = 'pass';
      }
    }

    // Configure the valid_delimited_file metadata.
    $strict = ($this->headers[3]['type'] == 'required') ? TRUE : FALSE;

    $valid_delimited_file_metadata = [
      'strict_flag' => $strict,
      'number_of_columns' => $this->required_column_count,
    ];

    // Call the processListWithDescribedTable() method in ValidDelimitedFile
    // class to check if there are any failures for the valid delimited
    // file validator.
    if (array_key_exists('valid_delimited_file', $failures)) {
      if (!empty($failures['valid_delimited_file'])) {
        $raw_row_failed = TRUE;
        $messages['valid_delimited_file']['status'] = 'fail';
        $messages['valid_delimited_file']['details'] = ValidDelimitedFile::processListWithDescribedTable($failures['valid_delimited_file'], $valid_delimited_file_metadata);
      }
      else {
        $messages['valid_delimited_file']['status'] = 'pass';
      }
    }

    // Call the processListWithDescribedTable() method in ValidHeaders class
    // to check if there are any failures for the valid headers validator.
    if (array_key_exists('valid_headers', $failures)) {
      if (!empty($failures['valid_headers'])) {
        $messages['valid_headers']['status'] = 'fail';

        // Configure the metadata.
        $metadata = [
          'column_headers' => $header_names,
        ];
        $messages['valid_headers']['details'] = ValidHeaders::processListWithDescribedTable($failures['valid_headers'], $metadata);
      }
      else {
        $messages['valid_headers']['status'] = 'pass';
      }
    }

    // Call the processListWithDescribedTable() method in EmptyCell class to
    // check if there are any failures for the empty cell validator.
    if (array_key_exists('empty_cell', $failures)) {
      if (!empty($failures['empty_cell'])) {
        $messages['empty_cell']['status'] = 'fail';
        // If the 'uniquename' column is required, then tell the process
        // message method that the column headers include all columns.
        if ($this->headers[3]['type'] == 'required') {
          $metadata = [
            'column_headers' => $header_names,
          ];
        }
        // Otherwise, exclude the uniquename header.
        else {
          $metadata = [
            'column_headers' => [
              0 => $header_names[0],
              1 => $header_names[1],
              2 => $header_names[2],
            ],
          ];
        }
        $messages['empty_cell']['details'] = EmptyCell::processListWithDescribedTable($failures['empty_cell'], $metadata);
      }
      elseif (!$raw_row_failed) {
        $messages['empty_cell']['status'] = 'pass';
      }
    }

    // Call the processListWithDescribedTable() method in GermplasmNameExists
    // class to check if there are any failures for the germplasm name exists
    // validator.
    if (array_key_exists('germplasm_name_exists', $failures)) {

      $messages['germplasm_name_exists'] = [
        'title' => 'Germplasm exist(s) in the database',
        'status' => 'todo',
        'details' => '',
      ];

      if (!empty($failures['germplasm_name_exists'])) {
        $messages['germplasm_name_exists']['status'] = 'fail';
        // Configure the metadata.
        $metadata = [
          'column_headers' => [
            0 => $header_names[0],
          ],
        ];
        $messages['germplasm_name_exists']['details'] = GermplasmNameExists::processListWithDescribedTable($failures['germplasm_name_exists'], $metadata);
      }
      elseif (!$raw_row_failed) {
        $messages['germplasm_name_exists']['status'] = 'pass';
      }
    }

    return $messages;
  }

  /**
   * {@inheritdoc}
   */
  public function formValidate($form, &$form_state) {

    $form_values = $form_state->getValues();

    // Validate Primary Germplasm.
    $fld_name_primary_germplasm = 'fld_text_primary_germplasm';
    $fld_value_primary_germplasm = $form_values[$fld_name_primary_germplasm];

    $parsed_stock_id = ChadoGenericAutocompleteController::getPkeyId($fld_value_primary_germplasm);

    // Failed to locate the poplation stock field element.
    if ($parsed_stock_id == 0) {
      $form_state->setErrorByName($fld_name_primary_germplasm, 'Germplasm does not exist. Please enter a valid germplasm in Primary Germplasm.');
    }

    $fld_name_relationship_verb = 'fld_select_relationship_verb';
    $fld_value_relationship_verb = $form_values[$fld_name_relationship_verb];

    $relationship_verb = ChadoCVTermAutocompleteController::getCVtermId($fld_value_relationship_verb);

    if ($relationship_verb == 0) {
      $form_state->setErrorByName($fld_name_relationship_verb, 'Please select a value in Relationship Type.');
    }

    $file_id = $form_values['file_upload'];
    // Load our file object.
    $file = $this->service_entityTypeManager->getStorage('file')->load($file_id);

    // Get the mime type which is used to validate the file and split the rows.
    $file_mime_type = $file->getMimeType();

    foreach ($this->headers as $key => $value) {
      if ($value['name'] == 'Unique Name') {
        if ($form_values['relationship_toggle']) {
          // If relationship only is selected, then the uniquename
          // is required in the file.
          $this->headers[$key]['type'] = 'optional';
        }
        else {
          $this->headers[$key]['type'] = 'required';
        }
      }
    }

    $this->getRequiredColumnsCount();

    // Configure the validators.
    $validators = $this->configureValidators($form_values, $file_mime_type);

    // A Flag to keep track if any validator fails.
    $failed_validator = FALSE;

    // Holds failed items.
    $failures = [];

    // File validation.
    if ($failed_validator === FALSE && isset($validators['file'])) {
      foreach ($validators['file'] as $validator_name => $validator) {
        $failures[$validator_name] = [];
        $result = $validator->validateFile($file_id);

        if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
          $failed_validator = TRUE;
          $failures[$validator_name] = $result;
        }
      }
    }

    // Data validation.
    if ($failed_validator === FALSE) {
      $file_uri = $file->getFileUri();
      $handle = fopen($file_uri, 'r');
      $line_no = 0;

      while (!feof($handle)) {

        $row_has_failed = FALSE;
        $line = fgets($handle);
        $line_no++;
        if (empty(trim($line))) {
          continue;
        }

        // Valid Delimited File.
        if (isset($validators['raw-row'])) {
          foreach ($validators['raw-row'] as $validator_name => $validator) {
            if (!array_key_exists($validator_name, $failures)) {
              $failures[$validator_name] = [];
            }

            $result = $validator->validateRawRow($line);

            if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
              $row_has_failed = TRUE;
              $failures[$validator_name][$line_no] = $result;
            }
          }
        }

        if ($row_has_failed === TRUE) {
          $failed_validator = TRUE;
          continue;
        }

        // Header row validation.
        if ($line_no == 1 && isset($validators['header-row'])) {
          $header_row = ImportValidationHelper::splitRowIntoColumns($line, $file_mime_type);

          foreach ($validators['header-row'] as $validator_name => $validator) {
            if (!array_key_exists($validator_name, $failures)) {
              $failures[$validator_name] = [];
            }

            $result = $validator->validateRow($header_row);

            if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
              $row_has_failed = TRUE;
              $failures[$validator_name] = $result;
            }
          }

          // If any header-row validators failed, skip validation of the data
          // rows.
          if ($row_has_failed === TRUE) {
            $failed_validator = TRUE;
            break;
          }
        }

        if ($line_no > 1) {
          // Split line into an array using the delimiter supported by this
          // importer.
          $data_row = ImportValidationHelper::splitRowIntoColumns($line, $file_mime_type);

          // Call each validator on this row of the file.
          foreach ($validators['data-row'] as $validator_name => $validator) {
            // Set failures for this validator name to an empty array to signal
            // that this validator has been run, but ONLY if it doesn't exist.
            // (ie. this validator may have already failed on a previous row, so
            // we don't want to overwrite previous validation failures.)
            if (!array_key_exists($validator_name, $failures)) {
              $failures[$validator_name] = [];
            }
            if ($validator_name == 'germplasm_name_exists') {
              // @todo Validate that the unique name is not duplicated with in
              // the file (currently, run method checks the database to check
              // whether the unique name is in the database, but there's no
              // validator to check for duplicates within the file).
              // @todo Validate the organism first, once we have a organism
              // validator plugin, then only set the organism ID if the organism
              // is valid.
              // Organism ID:
              // If Scientific Name is present, lookup the organism ID
              // and set it in the validator.
              if ($data_row[2]) {
                $organism_id = $this->getOrganismIds($data_row[2]);
              }
              else {
                $organism_id = NULL;
              }
              if ($organism_id == NULL) {
                // Manually set failures array.
                $failed_validator = TRUE;
                $failures[$validator_name][$line_no] = [
                  'case' => 'Unable to lookup germplasm with empty values',
                  'valid' => FALSE,
                  'failedItems' => ['empty_cells' => $line_no],
                ];
                continue;
              }
              $validator->setOrganismID($this->organism_ids[$data_row[2]]);
            }
            $result = $validator->validateRow($data_row);
            // Check if validation failed.
            if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
              $failed_validator = TRUE;
              $failures[$validator_name][$line_no] = $result;
            }
          }
        }
      }

      fclose($handle);
    }

    $validation_feedback = $this->processValidationMessages($failures);
    $storage = $form_state->getStorage();
    $storage[self::VALIDATION_RESULT] = $validation_feedback;
    $form_state->setStorage($storage);

    $submit_form = TRUE;

    foreach ($validation_feedback as $feedback_item) {
      // The uniquename is not required when relationship only
      // toggle is off, so the status will stay as 'todo' in that case.
      // Thus, we only submit the form when the status is 'todo' and
      // relationship only toggle is on, or when the status is 'fail'.
      if (($feedback_item['status'] == 'todo' && $form_values['relationship_toggle']) || $feedback_item['status'] == 'fail') {
        $submit_form = FALSE;
        break;
      }
    }

    if ($submit_form === FALSE) {
      $this->service_Messenger
        ->addError('Your file import was not successful. Please check the Validation Result Window for errors and try again.');

      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * {@inheritDoc}
   */
  public function form($form, &$form_state) {

    $form = parent::form($form, $form_state);

    // INFO:
    $info = $this->t('This importer will relate a group of germplasm to an existing
        germplasm. More specifically, for every line in the file, the importer will
        look up if the germplasm already exists or create the germplasm if
        allowed to do so. Then, a relationship, with the type specified in this
        form, will be made between that new germpasm and the primary germplasm
        selected in this form. An example use case of this importer is to relate
        multiple individuals to a single breeding cross.');
    $form['note'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $info,
      '#weight' => -101,
    ];

    $storage = $form_state->getStorage();
    if (isset($storage[self::VALIDATION_RESULT])) {
      $validation_result = $storage[self::VALIDATION_RESULT];

      $form['validation_result'] = [
        '#type' => 'inline_template',
        '#theme' => 'validation_result_window',
        '#data' => [
          'validation_result' => $validation_result,
        ],
        '#weight' => -100,
      ];
    }

    // Primary Germplasm.
    // FIELDSET: Primary Germplasm fieldset.
    $form['fieldset_primary_germplasm'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Primary Germplasm'),
      '#markup' => $this->t('<p>Choose the germplasm individual whom you would like to create 1+ relationships with. For example, if you want to create a number of germplasm selections (e.g. 1234S-red, 1234S-green, 1234S-black) and relate them to the original germplasm individual (e.g. 1234S) then the "Primary Germplasm" would be the original germplasm individual (e.g. 1234S) and the selections would be documented in the "Related Germplasm" file.</p>'),
      '#weight' => -99,
      '#required' => TRUE,
    ];

    // Options used to search for germplasm/stock names.
    $options = [
      'base_table' => 'stock',
      'column_name' => 'name',
      'type_column' => 'type_id',
      'property_table' => 'stock',
      'type_id' => 0,
      'match_limit' => 10,
    ];

    // Get the autocomplete search for the population stock.
    $form['fieldset_primary_germplasm']['fld_text_primary_germplasm'] = [
      '#type' => 'textfield',
      '#autocomplete_route_name' => 'tripal_chado.generic_autocomplete',
      '#autocomplete_route_parameters' => $options,
      '#maxlength' => 1000,
      '#placeholder' => $this->t('Germplasm / Variety / Cultivar'),
      '#disabled' => FALSE,
      '#id' => 'population-importer-fld-text-population-entry',
    ];

    // FIELD: Autocomplete search.
    // Search germplasm/stock as the primary germplasm.
    // Relationship Verb.
    // FIELDSET: Relationship Verb fieldset.
    $form['fieldset_relationship_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Relationship Verb'),
      '#weight' => -98,
      '#required' => TRUE,
    ];

    $term_autocomplete_default = 'Has Member (SIO:000059)';

    $form['fieldset_relationship_type']['fld_select_relationship_verb'] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#default_value' => $term_autocomplete_default,
      '#disabled' => FALSE,
      '#autocomplete_route_name' => 'tripal.cvterm_autocomplete',
      '#autocomplete_route_parameters' => ['count' => 10],
      '#id' => 'population-importer-fld-select-relationship-verb',
    ];

    // FIELD: Radio buttons.
    // Set stock position in the relationship.
    // evi: entry-verb-individual.
    // ive: individual-verb-entry.
    $form['fieldset_relationship_type']['fld_radio_stock_position'] = [
      '#type' => 'radios',
      '#default_value' => 'evi',
      '#options' => [
        'evi' => $this->t('Primary Germplasm as SUBJECT and Related Germplasm as OBJECT of the relationship.'),
        'ive' => $this->t('Related Germplasm as SUBJECT and Primary Germplasm as OBJECT of the relationship.'),
      ],
    ];

    // IMAGE: Population load illustration.
    $path = base_path() . \Drupal::service('extension.list.module')->getPath('trpcultivate_germcollection');
    $form['fieldset_relationship_type']['image_illustration'] = [
      '#markup' => '<div style="margin-top: 20px"><img src="' . $path . '/theme/images/relationship_importer.png" style="max-width: 70%" /></div>',
    ];

    $form['file']['relationship_toggle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Related germplasm must already exist'),
      '#default_value' => 0,
      '#weight' => -97,
    ];

    // This importer does not support using file sources from existing field.
    // #access: (bool) Whether the element is accessible or not; when FALSE,
    // the element is not rendered and the user submitted value is not taken
    // into consideration.
    $form['file']['file_upload_existing']['#access'] = FALSE;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function formSubmit($form, &$form_state) {

    // Display successful message to user if file import was without any error.
    $this->service_Messenger
      ->addStatus('Your file import was successful and a Job Process Request has been created to securely save your data.');
  }

  /**
   * {@inheritDoc}
   */
  public function run() {

    // Get the arguments from the form.
    $arguments = $this->getArguments();

    // Get the primary germplasm stock id.
    $primary_germplasm_stock_id = ChadoGenericAutocompleteController::getPkeyId($arguments['run_args']['fld_text_primary_germplasm']);

    // Form values.
    $population = [
      'entry' => $primary_germplasm_stock_id,
      'verb' => $arguments['run_args']['fld_select_relationship_verb'],
      'position' => $arguments['run_args']['fld_radio_stock_position'],
      'relationship_only' => $arguments['run_args']['relationship_toggle'],
      'individuals' => $this->arguments['files'][0]['fid'],
    ];

    $this->importPopulation($population);
  }

  /**
   * Relate individuals in the file with the entry.
   *
   * Germplasm individuals may be created if they do not exist and the importer
   * is configured to allow it. Individuals are related with the entry by
   * creating a stock_relationship record linking the individual and the entry.
   *
   * @param array $population
   *   Details for the import as an associative array with the following keys:
   *   - entry (int): stock.stock_id of the primary germplasm.
   *   - verb (string): the term to be used for the stock relationship.
   *     This will match the format "TERM NAME (ID SPACE:ACCESSION)" such as
   *     that returned by the cvterm autocomplete controller.
   *     @see 'fld_select_relationship_verb'
   *   - position (string): the position of the primary germplasm in the stock
   *     relationship. Specifically, 'evi' if it's the subject and 'ive' if
   *     it's the object.
   *     @see 'fld_radio_stock_position'
   *   - individuals (int): the FID of a managed file describing the related
   *     germplasm individuals. The file consists of 4 columns, see the
   *     headers property for more details.
   */
  public function importPopulation(array $population) {
    $file = $this->service_entityTypeManager->getStorage('file')->load($population['individuals']);
    $this->setTotalItems($file->filesize);
    $this->setItemsHandled(0);
    // Get the mime type which is used to validate the file and split the rows.
    $file_mime_type = $file->getMimeType();

    $temp_lines = [];
    $duplicate  = [];

    if ($file) {
      $file_uri = $file->getFileUri();
      $handle = fopen($file_uri, 'r');
      if ($handle) {
        $i = 0;

        while ($cur_line = fgets($handle)) {
          // Add all individuals in file into stock table
          // and simultaneously creating the Relationship verb.
          if ($i == 0) {
            // This is the header row.
            $i++;
            continue;
          }

          // Data rows.
          if ($cur_line) {
            $data_row = ImportValidationHelper::splitRowIntoColumns($cur_line, $file_mime_type);
            $val_name = $data_row[0];
            $val_type = $data_row[1];
            $val_sciname = $data_row[2];
            $val_uniqname = $data_row[3] ?? NULL;

            // Organism:
            // If Scientific Name is present, lookup the organism ID.
            if ($val_sciname) {
              $organism_id = $this->getOrganismIds($val_sciname);
            }
            else {
              $organism_id = 0;
            }
            // Throw exception if organism is not valid.
            if (!is_int($organism_id) || $organism_id <= 0) {
              throw new \Exception('Scientific Name: ' . $val_sciname . ' is not valid. Please provide a valid Scientific Name.');
            }

            // Type:
            // Use the database name and cvterm encoded in Type to determine
            // the stock type.
            if ($val_type) {
              $type_id = ChadoCVTermAutocompleteController::getCVtermId($val_type);
            }
            else {
              $type_id = NULL;
            }

            // Throw exception if type is not valid.
            if (!is_int($type_id) || $type_id <= 0) {
              throw new \Exception('Type: ' . $val_type . ' is not valid. Please provide a valid Type.');
            }

            // Germplasm Name:
            // Use the germplasm name to determine if the germplasm
            // exists in the database.
            // If it does not exist, throw an exception.
            $stock_id = $this->parseStock($val_name, $type_id, $organism_id);

            if ($stock_id == NULL && $population['relationship_only']) {
              throw new \Exception('Germplasm with name: ' . $val_name . ' + type: ' . $val_type . ' + scientific name: ' . $val_sciname . ' does not exist. Please provide a valid Germplasm.');
            }

            if (!$population['relationship_only']) {
              if (!$val_uniqname) {
                throw new \Exception('Unique Name is required in line #' . ($i + 1) . ' when Related germplasm must already exist option is selected.');
              }
              $uniquename = $val_uniqname;
            }
            else {
              $uniquename = $val_uniqname;
            }

            // DUPLICATE LINE:
            // Line name+type+organism+uniquename must be unique regardless of
            // the case format of the name ie. Germ1 GERM1 or GerM1.
            $line_text = strtolower($val_name . $val_type . $val_sciname);
            if (in_array($line_text . $val_uniqname, $temp_lines)) {
              // Inspect if this name has same type, ogranism and uniquename.
              $duplicate_line = array_search($line_text . $val_uniqname, $temp_lines);
              throw new \Exception('Duplicate in lines: #' . $duplicate_line . ' and #' . ($i + 1));
            }
            else {
              if (preg_grep("/$line_text.*/", $temp_lines)) {
                $duplicate[] = $val_name;
              }

              // Record instance.
              $temp_lines[$i + 1] = $line_text . $val_uniqname;
            }

            // Unique Name:
            // Check if the uniquename already exists in the database.
            // If it does, throw an exception.
            if (!$population['relationship_only']) {
              if ($val_uniqname) {
                $query = $this->chado_connection->select('1:stock', 's')
                  ->fields('s', ['stock_id'])
                  ->condition('s.uniquename', $val_uniqname, '=')
                  ->execute();

                $result = NULL;
                if ($stock_id = $query->fetchField()) {
                  $result = $stock_id;
                }
                if ($result) {
                  // A uniquename is already used in database.
                  // This exception will also be thrown if the uniquename
                  // is duplicated in the file.
                  throw new \Exception('Unique Name is already used by another germplasm.');
                }
              }

              // Stock Name+Type+Scientific Name combination must be unique.
              // Check if the combination already exists in the database.
              // If it does, throw an exception.
              $query = $this->chado_connection->select('1:stock', 's')
                ->fields('s', ['stock_id'])
                ->condition('s.name', $val_name, '=')
                ->condition('s.type_id', $type_id, '=')
                ->condition('s.organism_id', $organism_id, '=')
                ->execute();
              $result = NULL;
              if ($stock_id = $query->fetchField()) {
                $result = $stock_id;
              }
              if ($result) {
                throw new \Exception('Germplasm with name: ' . $val_name . ' + type: ' . $val_type . ' + scientific name: ' . $val_sciname . ' already exists in the database, but the toggle was set to create new individuals.');
              }

              // STOCK:
              $stock = [
                'name' => $val_name,
                'uniquename' => $uniquename,
                'organism_id' => $organism_id,
                'type_id' => $type_id,
              ];

              // Save the id of the individual being added
              // and use it in the relationship below.
              $individual_query = $this->chado_connection->insert('1:stock')
                ->fields($stock)
                ->execute();

              // Fetch the inserted stock_id (if needed)
              $individual = NULL;
              if ($individual_query) {
                $individual = $individual_query;
              }
            }
            else {
              // Relationship only:
              // Fetch the stock_id of the individual using
              // the uniquename provided in the file.
              $individual = NULL;
              $query = $this->chado_connection->select('1:stock', 's')
                ->fields('s', ['stock_id'])
                ->condition('s.name', $val_name, '=')
                ->condition('s.type_id', $type_id, '=')
                ->condition('s.organism_id', $organism_id, '=')
                ->execute();

              if ($stock_id = $query->fetchField()) {
                $individual = $stock_id;
              }
            }

            // Create Relationship:
            $relation = [];
            // Verb.
            $relation['type_id'] = ChadoCVTermAutocompleteController::getCVtermId($population['verb']);

            // Position.
            if ($population['position'] == 'evi') {
              // Entry - Verb - Individual.
              $relation['subject_id'] = $population['entry'];
              $relation['object_id'] = $individual;
            }
            elseif ($population['position'] == 'ive') {
              // Individual - Verb - Entry.
              $relation['object_id'] = $population['entry'];
              $relation['subject_id'] = $individual;
            }

            $this->chado_connection->insert('1:stock_relationship')
              ->fields($relation)
              ->execute();
            unset($individual);

            $i++;
          }
        }

        // Close file.
        fclose($handle);
      }
    }
  }

  /**
   * Parse form values for Stock/Germplasm (Primary Germplasm).
   *
   * Fetch the matching row in chado.stock table.
   *
   * @param string $stock_name
   *   String, containing the stock name and  in
   *   the following notation: Stock Name.
   * @param int $type_id
   *   Integer, containing the cvterm type id.
   * @param int $organism_id
   *   Integer, containing organism_id of the scientific name.
   *
   * @return int|null
   *   The stock id number that matched the resolved stock id
   *   from the input string, otherwise NULL.
   */
  public function parseStock($stock_name, $type_id, $organism_id) {

    // Fetch the germplasm name and return
    // the stock_id number.
    $query = $this->chado_connection->select('1:stock', 's')
      ->fields('s', ['stock_id'])
      ->condition('s.name', $stock_name, '=')
      ->condition('s.type_id', $type_id, '=')
      ->condition('s.organism_id', $organism_id, '=')
      ->execute();

    $result = NULL;
    if ($stock_id = $query->fetchField()) {
      $result = $stock_id;
    }

    return $result;
  }

  /**
   * Get organism ids, or use the previously looked up organisms.
   *
   * @param string $organism
   *   The scientific name of the organism.
   *
   * @return int
   *   The organism_id number. This will be 0 if the organism
   *   could not be found.
   */
  public function getOrganismIds(string $organism) : int {
    $organism_id = 0;
    // Organism:
    // Use the genus and species encoded in the Scientific Name to
    // determine the organism.
    if (array_key_exists($organism, $this->organism_ids)) {
      $organism_id = $this->organism_ids[$organism];
    }
    else {
      // @todo Once the ChadoOrganismBuddy service is declared and available to
      // use here, switch to using getOrganismFromScientificName() to lookup
      // organism IDs.
      // Not previously looked up, so do it now.
      // Capture the genus and species from Scientific Name.
      preg_match('/^(\w+)\s{1}(.*)/', $organism, $match);
      if ($match !== FALSE && ($match[1] && $match[2])) {
        $values = [
          'genus' => $match[1],
          'species' => $match[2],
        ];

        // Fetch organism using the genus+species and get
        // the organism_id number.
        $scientific_name = $values['genus'] . ' ' . $values['species'];

        $organism_id = 0;
        $organism_records = $this->organism_buddy->getOrganismFromScientificName($scientific_name);
        if (array_key_exists(0, $organism_records)) {
          $organism_id = $organism_records[0]->getValue('organism.organism_id');
        }
      }
      $this->organism_ids[$organism] = $organism_id;
    }
    return $organism_id;
  }

  /**
   * Describe the upload format including column descriptions + template file.
   *
   * Class TripalImporterBase is the parent class of this method and additional
   * documentation is available in reference link below.
   *
   * NOTE: This method supports full HTML markup output.
   *
   * All relevant information relating to expected column headers and usage
   * notes are laid out using the theme 'importer_header'. This is rendered
   * using the referenced TWIG file below.
   *
   * A template geneartor service is utilized to provide a downloadable file
   * template, pre-configured to contain all headers required. The link to
   * this template file is also formatted using the theme 'importer_header'.
   *
   * @return string
   *   The fully rendered HTML string produced by the 'importer_header' theme
   *   with the pertinent variables supplied by this method.
   *
   * @see Drupal\tripal\TripalImporter\TripalImporterBase::describeUploadFileFormat()
   * @see templates\trpcultivate-phenotypes-template-importer-header.html.twig
   */
  public function describeUploadFileFormat() {
    // A template file has been generated and is ready for download.
    $importer_id = $this->pluginDefinition['id'];

    // Only the header names are needed for making the template file, so pull
    // them out into a new array.
    $column_headers = array_column($this->headers, 'name');

    // File types 'file_types' annotation definition of this importer.
    // The first item in the definition list will be used as the primary
    // file extension of the template file.
    // File MIME type and delimiter are based on mapping information defined
    // in the validator base and file types validator trait.
    $file_extensions = $this->plugin_definition['file_types'];

    $file_link = $this->service_FileTemplate
      ->generateFile($importer_id, $column_headers, $file_extensions);

    // Additional notes to the headers.
    $notes = $this->t('Each row in the file should describe a specific individual to be linked to the primary germplasm with the specified relationship. If the toggle is set for germplasm individuals to already exist, Unique Name will be looked up if not provided. Otherwise, Unique Name is required to insert individuals.</p><p><strong>NOTE:</strong> This importer will not permit duplicate lines with identical Name + Type + Scientific Name + Unique Name in the file.</p>');

    // Render the header and notes/lists in a template and use the file link as
    // the value to href attribute of the link to download a template file.
    $supported_file_extensions = implode(', ', $file_extensions);

    $build = [
      '#theme' => 'describe_header_window',
      '#data' => [
        'headers' => $this->headers,
        'file_extensions' => $supported_file_extensions,
        'notes' => $notes,
        'template_file' => $file_link,
      ],
    ];

    return $this->service_Renderer->renderInIsolation($build);
  }

}
