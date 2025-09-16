<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager;
use Drupal\Core\Render\Renderer;
use Drupal\trpcultivate\Service\TripalCultivateFileTemplateService;
use Drupal\trpcultivate\Service\ImportValidationHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
use Drupal\trpcultivate\Plugin\Validators\EmptyCell;
use Drupal\trpcultivate\Plugin\Validators\GermplasmNameExists;
use Drupal\trpcultivate\Plugin\Validators\ValidDataFile;
use Drupal\trpcultivate\Plugin\Validators\ValidDelimitedFile;
use Drupal\trpcultivate\Plugin\Validators\ValidHeaders;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\trpcultivate\TripalImporter\Attribute\TripalImporter;

/**
 * This is a Germplasm Collection Importer.
 *
 * @TripalImporter(
 *   id = "trpcultivate-germplasm-population-importer",
 *   label = @Translation("Tripal Importer: Germplasm Collection Importer"),
 *   description = @Translation("Imports germplasm populations (i.e. RIL, NAM, cross progeny) into testchado."),
 *   file_types = {"tsv", "txt"},
 *   upload_description = @Translation("Please provide a data file."),
 *   upload_title = @Translation("<strong>Population Individuals*</strong>"),
 *   use_analysis = FALSE,
 *   require_analysis = FALSE,
 *   use_button = True,
 *   submit_disabled = FALSE,
 *   button_text = "Import",
 *   file_upload = TRUE,
 *   file_local = FALSE,
 *   file_remote = FALSE,
 *   file_required = TRUE,
 *   cardinality = 1,
 *   menu_path = "",
 *   callback = "",
 *   callback_module = "",
 *   callback_path = "",
 * )
 */
#[TripalImporter(
  id: 'trpcultivate-germplasm-population-importer',
  label: new TranslatableMarkup('Tripal Importer: Germplasm Collection Importer'),
  description: new TranslatableMarkup('Imports germplasm populations (i.e. RIL, NAM, cross progeny) into testchado.'),
  file_types: ['tsv', 'txt'],
  upload_description: new TranslatableMarkup('Please provide a data file.'),
  upload_title: new TranslatableMarkup('<strong>Population Individuals*</strong>'),
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
  callback_module: '',
  callback_path: '',
)]
class GermplasmCollectionImporter extends ChadoImporterBase implements ContainerFactoryPluginInterface {

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
   * @var \Drupal\Core\Messenger\MessengerInterface
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
      'description' => 'The name of the germplasm individual.',
      'type' => 'required',
    ],
    [
      'name' => 'Type',
      'description' => 'The type of the germplasm individual, as a controlled vocabulary term.',
      'type' => 'required',
    ],
    [
      'name' => 'Scientific Name',
      'description' => 'The scientific name of the germplasm individual.',
      'type' => 'required',
    ],
    [
      'name' => 'Uniquename',
      'description' => 'A unique identifier for the germplasm individual.',
      'type' => 'optional',
    ],
  ];

  /**
   * Looked up organism ids, keyed by scientific name.
   *
   * @var array
   */
  protected $organism_ids = [];

  /**
   * Constructs the Germpalsm Collection importer.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param Drupal\tripal_chado\Database\ChadoConnection $chado_connection
   *   The connection to the Chado database.
   * @param Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager $service_validatorPluginManager
   *   The TripalCultivate validator plugin manager.
   * @param Drupal\trpcultivate\Service\TripalCultivateFileTemplateService $service_FileTemplate
   *   The service used to generate the termplate file.
   * @param Drupal\Core\Entity\EntityTypeManager $service_entityTypeManager
   *   The entity type manager.
   * @param Drupal\Core\Render\Renderer $renderer
   *   The Drupal renderer service.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Drupal messenger service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ChadoConnection $chado_connection,
    TripalCultivateValidatorManager $service_validatorPluginManager,
    TripalCultivateFileTemplateService $service_FileTemplate,
    EntityTypeManager $service_entityTypeManager,
    Renderer $renderer,
    MessengerInterface $messenger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $chado_connection);

    $this->service_validatorPluginManager = $service_validatorPluginManager;
    $this->service_FileTemplate = $service_FileTemplate;
    $this->service_entityTypeManager = $service_entityTypeManager;
    $this->service_Renderer = $renderer;
    $this->service_Messenger = $messenger;
    // Chado database.
    $this->chado_connection = $chado_connection;
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
      $container->get('plugin.manager.trpcultivate_validator'),
      $container->get('trpcultivate.template_generator'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('messenger'),
    );
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
    // Filter the headers with type = 'required' then Count the result.
    $required_column_count = count(array_filter($this->headers, function ($h) {
      return $h['type'] == 'required';
    }));
    $instance_delimited->setExpectedColumns($required_column_count, FALSE);
    $instance_delimited->setFileMimeType($file_mime_type);
    $validators['raw-row']['valid_delimited_file'] = $instance_delimited;

    // Configure the header row validator.
    $instance_header_row = $this->service_validatorPluginManager->createInstance('valid_headers');

    $instance_header_row->setHeaders($this->headers);
    $instance_header_row->setExpectedColumns(count($this->headers), TRUE);
    $validators['header-row']['valid_headers'] = $instance_header_row;

    // Configure the empty cell validator.
    $instance_empty_cell = $this->service_validatorPluginManager->createInstance('empty_cell');
    $indices = [
      $header_index['Name'],
      $header_index['Type'],
      $header_index['Scientific Name'],
    ];
    $instance_empty_cell->setIndices($indices);
    $validators['data-row']['empty_cell'] = $instance_empty_cell;

    // Configure the Germplasm Name Exists validator only if the
    // create relationship only toggle is on.
    if ($form_values['relationship_toggle'] == 1) {
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

    // Filter the headers with type = 'required' then Count the result.
    $required_column_count = count(array_filter($this->headers, function ($h) {
      return $h['type'] == 'required';
    }));

    // Configure the valid_delimited_file metadata.
    $valid_delimited_file_metadata = [
      'strict_flag' => TRUE,
      'number_of_columns' => $required_column_count,
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
        // Configure the metadata.
        $metadata = [
          'column_headers' => [
            0 => $header_names[0],
            1 => $header_names[1],
            2 => $header_names[2],
          ],
        ];
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

    // Validate Population Entry.
    $fld_name_population_entry = 'fld_text_population_entry';
    $fld_value_population_entry = $form_values[$fld_name_population_entry];

    $parsed_stock_id = ChadoGenericAutocompleteController::getPkeyId($fld_value_population_entry);

    // Failed to locate the poplation stock field element.
    if ($parsed_stock_id == 0) {
      $form_state->setErrorByName($fld_name_population_entry, 'Germplasm does not exist. Please enter a valid germplasm in Population Entry.');
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
      if (($feedback_item['status'] == 'todo' && $form_values['relationship_toggle'] != 0) || $feedback_item['status'] == 'fail') {
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
    $info = $this->t('This importer will create individuals of a population and
        relate them back to the population stock. More specifically, for every line
        in the file, a new chado stock record with that information will be created.
        Then a relationship as specified in this form will be made between that new
        stock record and the population stock selected in this form. As such this
        importer can be used in any case where you want to create a number of new
        stock records related to an existing stock. Examples of such situations are
        recombinant inbred line populations or nested association mapping panels.');
    $this->service_Messenger->addStatus($info);

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

    // Population Entry.
    // FIELDSET: Population Entry fieldset.
    $form['fieldset_population_entry'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Population Entry'),
      '#weight' => -2000,
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
    $form['fieldset_population_entry']['fld_text_population_entry'] = [
      '#type' => 'textfield',
      '#autocomplete_route_name' => 'tripal_chado.generic_autocomplete',
      '#autocomplete_route_parameters' => $options,
      '#maxlength' => 1000,
      '#placeholder' => $this->t('Germplasm / Stock Name'),
      '#disabled' => FALSE,
      '#id' => 'population-importer-fld-text-population-entry',
    ];

    // FIELD: Autocomplete search.
    // Search germplasm/stock as the population entry.
    // Relationship Verb.
    // FIELDSET: Relationship Verb fieldset.
    $form['fieldset_relationship_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Relationship Verb'),
      '#weight' => -1000,
      '#required' => TRUE,
    ];

    $cv_autocomplete = new ChadoCVTermAutocompleteController();
    $cv_term_id = $cv_autocomplete->getCvTermID('SIO', '0000059');
    $term_autocomplete_default = $cv_autocomplete->formatCVterm($cv_term_id);

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
        'evi' => $this->t('Population Stock as SUBJECT and Population Individuals as OBJECT of the relationship.'),
        'ive' => $this->t('Population Individuals as SUBJECT and Population Stock as OBJECT of the relationship.'),
      ],
    ];

    $form['relationship_toggle'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Population individuals must already exist'),
      '#default_value' => 0,
      '#weight' => -100,
    ];

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

    // Get the population entry stock id.
    $population_entry_stock_id = ChadoGenericAutocompleteController::getPkeyId($arguments['run_args']['fld_text_population_entry']);

    // Form values.
    $population = [
      'entry' => $population_entry_stock_id,
      'verb' => $arguments['run_args']['fld_select_relationship_verb'],
      'position' => $arguments['run_args']['fld_radio_stock_position'],
      'relationship_only' => $arguments['run_args']['relationship_toggle'],
      'individuals' => $this->arguments['files'][0]['fid'],
    ];

    $this->importPopulation($population);
  }

  /**
   * Function callback, create population.
   *
   * @param array $population
   *   Array, with the following keys:
   *   entry: Form field value for Population Entry field.
   *   verb : Form field value for Relationship Verb field.
   *   position: Form field value for Stock Position field.
   *   individuals: Form file field value for Population Individuals Field.
   */
  public function importPopulation($population) {
    $file = $this->service_entityTypeManager->getStorage('file')->load($population['individuals']);
    $this->setTotalItems($file->filesize);
    $this->setItemsHandled(0);
    // Get the mime type which is used to validate the file and split the rows.
    $file_mime_type = $file->getMimeType();

    $temp_lines = [];
    $temp_uname = [];
    $duplicate  = [];

    if ($file) {
      $file_uri = $file->getFileUri();
      $handle = fopen($file_uri, 'r');
      if ($handle) {
        $i = 0;

        // Fetch the last stock_id auto-increment inserted, increment
        // the value each time a stock is added. This value is concatenated
        // to the prefix (when provided) that will make up the uniquename of
        // the stock.
        // Skip this when file has provided a custom uniquename.
        $id = $this->chado_connection->select('1:stock', 's')
          ->fields('s', ['stock_id'])
          ->orderBy('stock_id', 'DESC')
          ->execute()
          ->fetchField();
        $last_id = $id[0] ?? 0;

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

            // Construct uniquename:
            // If line has no uniquename by using the prefix system
            // configuration and next sequence id of stock.
            $uniquename = ($val_uniqname == '')
              ? 'uniquename' . $population['entry'] . ($last_id + $i) : $val_uniqname;
            // Always encode in uppercase form.
            $uniquename = strtoupper($uniquename);

            // Germplasm Name:
            // Use the germplasm name to determine if the germplasm
            // exists in the database.
            // If it does not exist, throw an exception.
            $stock_id = $this->parseStock($val_name);

            if ($stock_id == NULL && $population['relationship_only'] == 1) {
              throw new \Exception('Germplasm Name: ' . $val_name . ' does not exists. Please provide a valid Germplasm Name.');
            }

            // Organism:
            // If Scientific Name is present, lookup the organism ID.
            if ($val_sciname) {
              $organism_id = $this->getOrganismIds($val_sciname);
            }
            else {
              $organism_id = NULL;
            }
            // Throw exception if organism is not valid.
            if ($organism_id == NULL) {
              throw new \Exception('Scientific Name: ' . $val_sciname . ' is not valid. Please provide a valid Scientific Name.');
            }

            // Type:
            // Use the database name and cvterm encoded in Type to determine
            // the stock type.
            if ($val_type) {
              $type_id = $this->parseTerm($val_type);
            }
            else {
              $type_id = NULL;
            }

            // Throw exception if type is not valid.
            if ($type_id == NULL) {
              throw new \Exception('Type: ' . $val_type . ' is not valid. Please provide a valid Type.');
            }

            // DUPLICATE LINE:
            // Line name+type+organism+uniquename must be unique regardless of
            // the case format of the name ie. Germ1 GERM1 or GerM1.
            // Note: uniquename is always uppercase.
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

            // Uniquename:
            // If provided in the file, check if the uniquename
            // already exists in the database.
            // If it does, throw an exception.
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
                throw new \Exception('Uniquename is already used by another germplasm.');
              }
              else {
                // Check if the uniquename is duplicated
                // in the file. If it is, throw an exception.
                if (in_array($val_uniqname, $temp_uname)) {
                  // Duplicate uniquename in file.
                  $duplicate_uname = array_search($val_uniqname, $temp_uname);
                  throw new \Exception('Duplicate Uniquename in lines: #' . $duplicate_uname . ' and ' . ($i + 1));
                }
                else {
                  // If uniquename is not duplicated, add it to the temp array
                  // to check against for future lines in the file.
                  $temp_uname[$i + 1] = $val_uniqname;
                }
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
              throw new \Exception('Term already exists in the database.');
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

            // Create Relationship:
            $relation = [];
            // Verb.
            $relation['type_id'] = $this->parseTerm($population['verb']);

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
   * Parse form values for cvterm name (Type).
   *
   * Fetch the matching row in chado.cvterm..
   *
   * @param string $value
   *   String, containing the the type.
   *
   * @return int
   *   Cvterm id number that matched the resolved cvterm id
   *   from the input string.
   */
  public function parseTerm($value) {
    $result = '';

    // Capture the database name and cvterm name.
    preg_match('/^(.*)\s\(([A-Za-z0-9_]+:\d+)\)$/', $value, $match);
    if ($match !== FALSE && ($match[1] && $match[2])) {
      $values = [
        'name' => $match[1],
      ];

      // Fetch cvterm that match the dbname and cvterm name
      // in the input string form value. The dbname will
      // ensure that a specific term will be returned.
      $result = $this->chado_connection->select('1:cvterm', 'c')
        ->fields('c', ['cvterm_id'])
        ->condition('c.name', $values['name'], '=')
        ->execute()
        ->fetchField();
    }

    return $result;
  }

  /**
   * Parse form values for Stock/Germplasm (Population Entry).
   *
   * Fetch the matching row in chado.stock table.
   *
   * @param string $value
   *   String, containing the stock name and  in
   *   the following notation: Stock Name.
   *
   * @return int
   *   Stock id number that matched the resolved stock id
   *   from the input string.
   */
  public function parseStock($value) {
    $result = '';

    // Fetch the germplasm name and return
    // the stock_id number.
    $query = $this->chado_connection->select('1:stock', 's')
      ->fields('s', ['stock_id'])
      ->condition('s.name', $value, '=')
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
   */
  public function getOrganismIds($organism) {
    $organism_id = '';
    // Organism:
    // Use the genus and species encoded in the Scientific Name to
    // determine the organism.
    if (array_key_exists($organism, $this->organism_ids)) {
      $organism_id = $this->organism_ids[$organism];
    }
    else {
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

        $organism_id = NULL;
        $organism_id_array = chado_get_organism_id_from_scientific_name($scientific_name);
        if (array_key_exists(0, $organism_id_array)) {
          $organism_id = $organism_id_array[0];
        }
      }
      $this->organism_ids[$organism] = $organism_id;
    }
    return $organism_id;
  }

  /**
   * {@inheritdoc}
   */
  public function postRun() {}

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
    $notes = $this->t('Each row in the file should describe a specific individual to be created and linked to the Population Entry with the specified relationship.</p><p><strong>NOTE:</strong> This importer will not permit duplicate lines with identical Name + Type + Scientific Name + Uniquename in the file.<br />A warning will be issued when duplicate line, with the exception Uniquename has been detected and the Importer may proceed.</p>');

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

    return $this->service_Renderer->renderPlain($build);
  }

}
