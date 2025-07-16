<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager;
use Drupal\trpcultivate\Service\ImportValidationHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tripal_chado\Controller\ChadoCVTermAutocompleteController;
use Drupal\tripal_chado\Controller\ChadoGenericAutocompleteController;
use Drupal\trpcultivate\Plugin\Validators\EmptyCell;

/**
 * This is a Germplasm Collection Importer.
 *
 * @TripalImporter(
 *   id = "trpcultivate-germplasm-population-importer",
 *   label = @Translation("Tripal Importer: Germplasm Collection Importer"),
 *   description = @Translation("Imports germplasm populations (i.e. RIL, NAM, cross progeny) into testchado."),
 *   file_types = {"tsv", "txt"},
 *   upload_description = @Translation("Germplasm file should be a <strong>TAB Separated Values</strong> file (.tsv) containing a header with the following columns:<ol><li><strong>Name</strong>: The name of the RIL individual.</li><li><strong>Type</strong>: The vocabulary database name + : + cvterm (ie. schema:F1) which should be used for the stock record, must exist.</li><li><strong>Scientific Name</strong>: The genus + species of the organism to be used for the stock record, must exist.</li><li><strong>Uniquename</strong>: (optional) The uniquename to use if you do not want to use the pattern/prefix below. Custom value for this column must be unique for every line.</li></ol><p>Each row in the file should describe a specific individual to be created and linked to the Population Entry with the specified relationship.</p><p><strong>NOTE:</strong> This importer will not permit duplicate lines with identical Name + Type + Scientific Name + Uniquename in the file.<br />A warning will be issued when duplicate line, with the exception Uniquename has been detected and the Importer may proceed.</p>"),
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
class GermplasmCollectionImporter extends ChadoImporterBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  /**
   * Mapping of validation cases to messages.
   *
   * @var array
   */
  protected static array $mapping = [
    'case-empty-germplasm' => [
      'token' => 'case-empty-germplasm',
      'dev-case' => 'Unable to lookup germplasm with empty values',
      'default-msg' => 'One or more cells which are required to contain germplasm names were empty. Please ensure that you have entered existing germplasm names for all cells in the following columns: [column-headers]',
    ],
    'case-missing-germplasm' => [
      'token' => 'case-missing-germplasm',
      'dev-case' => 'Missing germplasm name(s) in the database',
      'default-msg' => 'The following germplasm names do not match any existing in this site. Please make sure you have entered the names exactly as they appear on the germplasm pages or [contact-admin] to have them added if they do not yet exist.',
    ],
    'case-duplicate-germplasm' => [
      'token' => 'case-duplicate-germplasm',
      'dev-case' => 'Duplicate(s) found in the database for germplasm name(s)',
      'default-msg' => 'The following germplasm names in your file have been duplicated in this site (i.e. there are two or more pages for the same germplasm). If there is a more specific germplasm already existing in the site, then use that in your file. Regardless, [contact-admin] to have the duplications resolved in the site.',
    ],
    'case-missing-and-duplicate-germplasm' => [
      'token' => 'case-missing-and-duplicate-germplasm',
      'dev-case' => 'Missing germplasm name(s) and found duplicate(s) in the database',
    ],
    'case-valid' => [
      'token' => 'case-valid',
      'dev-case' => 'Germplasm name(s) exist(s) in the database',
    ],
    'contact-admin' => [
      'token' => 'contact-admin',
      'default-msg' => 'contact your administrator',
    ],
  ];

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
    'name' => [
      'name' => 'Name',
      'description' => 'The name of the germplasm individual.',
      'type' => 'required',
    ],
    'type' => [
      'name' => 'Type',
      'description' => 'The type of the germplasm individual, as a controlled vocabulary term.',
      'type' => 'required',
    ],
    'scientific_name' => [
      'name' => 'Scientific Name',
      'description' => 'The scientific name of the germplasm individual.',
      'type' => 'required',
    ],
    'uniquename' => [
      'name' => 'Uniquename',
      'description' => '(optional) A unique identifier for the germplasm individual.',
      'type' => 'optional',
    ],
  ];

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
   * @param Drupal\Core\Entity\EntityTypeManager $service_entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The Drupal messenger service.
   */
  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    ChadoConnection $chado_connection,
    TripalCultivateValidatorManager $service_validatorPluginManager,
    EntityTypeManager $service_entityTypeManager,
    MessengerInterface $messenger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $chado_connection);

    $this->service_validatorPluginManager = $service_validatorPluginManager;
    $this->service_entityTypeManager = $service_entityTypeManager;
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
      $container->get('entity_type.manager'),
      $container->get('messenger'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function configureValidators(array $form_values, string $file_mime_type) {

    $validators = [];

    // Configure the valid delimitted file validator.
    $instance_delimited = $this->service_validatorPluginManager->createInstance('valid_delimited_file');
    $instance_delimited->setExpectedColumns(3, FALSE);
    $instance_delimited->setFileMimeType($file_mime_type);
    $validators['raw-row']['valid_delimited_file'] = $instance_delimited;

    // Configure the header row validator.
    $instance_header_row = $this->service_validatorPluginManager->createInstance('valid_headers');

    $instance_header_row->setHeaders($this->headers);
    $instance_header_row->setExpectedColumns(count($this->headers), TRUE);
    $validators['header-row']['valid_headers'] = $instance_header_row;

    // Configure the empty cell validator.
    $instance_empty_cell = $this->service_validatorPluginManager->createInstance('empty_cell');
    $instance_empty_cell->setIndices([0, 1, 2]);
    $validators['data-row']['empty_cell'] = $instance_empty_cell;

    // Configure the Germplasm Name Exists validator.
    $instance_name_exists = $this->service_validatorPluginManager->createInstance('germplasm_name_exists');

    $organism_id = 1;
    $indices = [0];
    $instance_name_exists->setOrganismID($organism_id);
    $instance_name_exists->setIndices($indices);
    $validators['data-row']['germplasm_name_exists'] = $instance_name_exists;

    return $validators;
  }

  /**
   * {@inheritDoc}
   */
  public function processValidationMessages($failures) {
    $messages = [];
    // Create a $messages entry for valid delimited file.
    $messages['valid_delimited_file'] = [
      'title' => 'File is delimitted correctly',
      'status' => 'todo',
      'details' => '',
    ];

    // Call the processValidDelimitedFileFailures() method to check if there
    // are any failures for the valid delimited file validator.
    if (array_key_exists('valid_delimited_file', $failures)) {
      if (!empty($failures['valid_delimited_file'])) {
        $messages['valid_delimited_file']['status'] = 'fail';
        $messages['valid_delimited_file']['details'] = $this->processValidDelimitedFileFailures($failures['valid_delimited_file']);
      }
      else {
        $messages['valid_delimited_file']['status'] = 'pass';
      }
    }

    // Create a $messages entry for valid headers.
    $messages['valid_headers'] = [
      'title' => 'File has valid headers',
      'status' => 'todo',
      'details' => '',
    ];

    // Call the processValidHeadersFailures() method to check if there
    // are any failures for the valid headers validator.
    if (array_key_exists('valid_headers', $failures)) {
      if (!empty($failures['valid_headers'])) {
        $messages['valid_headers']['status'] = 'fail';
        $messages['valid_headers']['details'] = $this->processValidHeadersFailures($failures['valid_headers']);
      }
      else {
        $messages['valid_headers']['status'] = 'pass';
      }
    }

    // Configure the metadata with valid headers.
    $metadata = [
      'column_headers' => [
        0 => 'Name',
        1 => 'Type',
        2 => 'Scientific Name',
        3 => 'Uniquename',
      ],
    ];

    // Create a $messages entry for empty cells.
    $messages['empty_cell'] = [
      'title' => 'No Empty Cells',
      'status' => 'todo',
      'details' => '',
    ];

    // Call the processListWithDescribedTable() method to check if there
    // are any failures for the empty cell validator.
    if (array_key_exists('empty_cell', $failures)) {
      if (!empty($failures['empty_cell'])) {
        $messages['empty_cell']['status'] = 'fail';
        $messages['empty_cell']['details'] = EmptyCell::processListWithDescribedTable($failures['empty_cell'], $metadata);
      }
      else {
        $messages['empty_cell']['status'] = 'pass';
      }
    }

    // Create a $messages entry for germplasm name exists.
    $messages['germplasm_name_exists'] = [
      'title' => 'Germplasm Name exists in the database',
      'status' => 'todo',
      'details' => '',
    ];

    // Call the processListWithDescribedTable() method to check if there
    // are any failures for the germplasm name exists validator.
    if (array_key_exists('germplasm_name_exists', $failures)) {
      if (!empty($failures['germplasm_name_exists'])) {
        $messages['germplasm_name_exists']['status'] = 'fail';
        $messages['germplasm_name_exists']['details'] = self::processListWithDescribedTable($failures['germplasm_name_exists'], $metadata);
      }
      else {
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
      throw new \Exception('Germplasm does not exist. Please enter a valid germplasm in Population Entry.');
    }

    $fld_name_relationship_verb = 'fld_select_relationship_verb';
    $fld_value_relationship_verb = $form_values[$fld_name_relationship_verb];

    $relationship_verb = ChadoCVTermAutocompleteController::getCVtermId($fld_value_relationship_verb);

    if ($relationship_verb == 0) {
      throw new \Exception('Please select a value in Relationship Type.');
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
      if ($feedback_item['status'] == 'todo' || $feedback_item['status'] == 'fail') {
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
    $form['info'] = [
      '#type' => 'item',
      '#weight' => -3000,
      '#markup' => $this->t('This importer will create individuals of a population and
        relate them back to the population stock. More specifically, for every line
        in the file, a new chado stock record with that information will be created.
        Then a relationship as specified in this form will be made between that new
        stock record and the population stock selected in this form. As such this
        importer can be used in any case where you want to create a number of new
        stock records related to an existing stock. Examples of such situations are
        recombinant inbred line populations or nested association mapping panels.'),
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

    // Population Entry.
    // FIELDSET: Population Entry fieldset.
    $form['fieldset_population_entry'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Population Entry*'),
      '#weight' => -2000,
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
      '#title' => $this->t('Relationship Verb*'),
      '#weight' => -1000,
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

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function formSubmit($form, &$form_state) {

    // Display successful message to user if file import was without any error.
    $this->service_Messenger
      ->addStatus('<b>Your file import was successful and a Job Process Request has been created to securely save your data.</b>');
  }

  /**
   * {@inheritDoc}
   */
  public function run() {}

  /**
   * {@inheritdoc}
   */
  public function postRun() {}

  /**
   * Process failed validation from GermplasmNameExists into a render array.
   *
   * This process method renders up to 2 tables, one for germplasm missing from
   * the database, and one for duplicate germplasm entries based on the name.
   * NOTE: The rendered validation result does NOT include information on the
   * duplicate records, but only lists the germplasm names. Future work may
   * include a separate process method that displays the information stored in
   * 'duplicates' of the 'failedItems' array.
   *
   * @param array $validation_results
   *   An associative array that stores the validation failures by the
   *   GermplasmNameExists validator. It is keyed by the line number of the
   *   input file where validation failed, and the value is an associative
   *   array returned by the validator. The overall structure is:
   *   - [LINE NUMBER]:
   *     - 'case': a developer-focused string describing the case checked.
   *     - 'valid': FALSE to indicate that validation failed.
   *     - 'failedItems': an array of items that failed, where the key => value
   *       pairs map to the index => cell value(s) that failed validation.
   *       @see validateRow()
   * @param array $metadata
   *   An array of additional metadata (or contextual information) needed by the
   *   process method. Here, the following keys are expected:
   *   - 'column_headers': This contains an array of headers for columns that
   *     are expected to contain germplasm names. The index in this array MUST
   *     match the position (starting with 0) of the column in the input file.
   *     Eg: 'column_headers' => [
   *           '2' => 'Maternal Germplasm Name', // Header of column #3
   *           '4' => 'Paternal Germplasm Name', // Header of column #5
   *         ];.
   * @param array $tokens
   *   [OPTIONAL] An array of values to use for token replacement.
   *   @see $mapping
   *   The following tokens can be specfied as keys, with value as the
   *   replacement value for the token. These apply to all failure cases.
   *   - 'contact-admin': the phrase to use when the user needs a privileged
   *     administrator to fix the problem.
   *   The following token keys will substitute the entire existing case message
   *   to the user with the value of that token.
   *   - 'case-empty-germplasm': the message when a cell that should contain a
   *    germplasm name is empty.
   *   - 'case-missing-germplasm': the message when a germplasm name is missing
   *     in the database.
   *   - 'case-duplicate-germplasm': the message when a germplasm name is
   *     duplicated in the database.
   *
   * @return array
   *   A render array of type "unordered list" used to display feedback to the
   *   user about the validation failure, where each item is a markup block
   *   containing:
   *   - A message describing the case triggered
   *   - A table that lists the row and column combinations with failures for
   *     this case.
   *   Each case triggered will have its own markup block. The table headers for
   *   each case are:
   *     - Germplasm name is empty: 'Row Number', 'Column Header'
   *     - Duplicate germplasm name seen in the database:
   *       'Row Number', 'Column Header', 'Germplasm Name'
   *     - Missing germplasm name from the database:
   *       'Row Number', 'Column Header', 'Germplasm Name'
   *
   * @throws \Exception
   *   - If key 'column_headers' is missing from $metadata
   *   - If a validation status array was not formatted properly.
   *   - If the message for token 'case-empty-germplasm' is an empty string.
   *   - If the case string returned by the validator implied validation passed.
   *   - If the case string returned by the validator is not recognized.
   */
  public function processListWithDescribedTable(array $validation_results, array $metadata, array $tokens = []) {

    // Validate that metadata contains the expected keys.
    if (!array_key_exists('column_headers', $metadata)) {
      throw new \Exception("Expected metadata to contain 'column_headers' when processing failures from GermplasmNameExists, but it does not.");
    }

    // We use the Tripal Token Parser service to ensure that more complicated
    // tokens are supported.
    // NOTE: Dependency injection is NOT used since this is a static method.
    $service_TripalTokensParser = \Drupal::service('tripal.token_parser');
    // Grab the default messages for all of our tokens (ones with default-msg).
    $default_tokens = array_column(self::$mapping, 'default-msg', 'token');
    // Combine our provided and our default token arrays. Because array_merge
    // will overwrite values in the first array with values from the second
    // array for the same keys, we provide our default tokens first.
    $combined_tokens = array_merge($default_tokens, $tokens);

    // For this validator there can be up to 2 tables:
    // - 'table'->'missing_cells': Germplasm name not found in the database.
    // - 'table'->'duplicate_cells': Germplasm name has multiple records.
    $table = [];

    // Loop through each row in the $failures array and piece apart the
    // different cases into different tables.
    foreach ($validation_results as $line_no => $validation_status) {
      // Check the format of this line's validation status.
      ImportValidationHelper::checkValidationStatusArray($validation_status, 'GermplasmNameExists', $line_no);

      // If any cells were found to be empty, this case takes presendence over
      // any other cases, and we return a warning message right away.
      if ($validation_status['case'] == 'Unable to lookup germplasm with empty values') {
        // Add a token for the column header names of the germplasm columns.
        $combined_tokens['column-headers'] = implode(', ', $metadata['column_headers']);
        $message = $service_TripalTokensParser->replaceTokens(
          $combined_tokens['case-empty-germplasm'],
          $combined_tokens
        );
        return self::renderSimpleWarningMessage(
          $message,
          ['case-message', 'tc-germplasm-name-exists-empty'],
        );
      }
      // Keeps track of which table this one line's validation result gets added
      // to based on the case it triggered.
      $table_case = [];
      if ($validation_status['case'] == 'Missing germplasm name(s) in the database') {
        $table_case = ['missing_cells'];
      }
      elseif ($validation_status['case'] == 'Duplicate(s) found in the database for germplasm name(s)') {
        $table_case = ['duplicate_cells'];
      }
      elseif ($validation_status['case'] == 'Missing germplasm name(s) and found duplicate(s) in the database') {
        $table_case = ['missing_cells', 'duplicate_cells'];
      }
      elseif ($validation_status['case'] == 'Germplasm name(s) exist(s) in the database') {
        throw new \Exception("The case string returned by the GermplasmNameExists validator at line #$line_no implies validation passed, but valid is set to FALSE.");
      }
      else {
        throw new \Exception("The case string returned by the GermplasmNameExists validator at line #$line_no is not recognized as a potential case.");
      }
      // Now set values that should appear for this row in the table(s) for this
      // particular case.
      foreach ($table_case as $case) {
        // Declare the array storing content for this table, if not already.
        if (!array_key_exists($case, $table)) {
          // Set the first column to hold the line number of the failure.
          // Use -1 to ensure it is the first column and doesn't conflict with
          // column indices in the input file.
          $table[$case]['header'][-1] = 'Line Number';
          $table[$case]['rows'] = [];
        }
        // Define a new row in our table for this line number.
        $table[$case]['rows'][$line_no][-1] = $line_no;
        // For each index with an failed germplasm, grab the column name from
        // $metadata and add it to our table header.
        foreach ($validation_status['failedItems'][$case] as $index => $germplasm) {
          // Grab the column name based on the index of the germplasm
          // and add it to this table header if it's not already there.
          $column_name = $metadata['column_headers'][$index];
          if (!array_key_exists($column_name, $table[$case]['header'])) {
            $table[$case]['header'][$index] = $column_name;
          }
          // Now add a cell to the table to indicate this germplasm.
          // We reuse the index from the original file as the key to preserve
          // the same order of the columns. We also key the row with the line
          // number to ensure that a line with more then one failure is
          // compiled into a single row.
          $table[$case]['rows'][$line_no][$index] = $germplasm['germplasm_name'];
        }
      }
    }
    // Check which tables were created, and assign the correct message.
    // Note that both tables can exist at the same time, hence not an 'elseif'.
    if (array_key_exists('missing_cells', $table)) {
      $table['missing_cells']['message'] = $combined_tokens['case-missing-germplasm'];
    }
    if (array_key_exists('duplicate_cells', $table)) {
      $table['duplicate_cells']['message'] = $combined_tokens['case-duplicate-germplasm'];
    }

    // Finally, loop through our tables and build our render array.
    $tables = [];
    foreach ($table as $table_key => &$table_case) {
      // If our table(s) have more than 2 columns with failed values, then
      // iterate through and pad each table with empty strings where necessary.
      self::fillTableGaps($table_case['header'], $table_case['rows']);
      array_push($tables, [
        [
          '#prefix' => '<div class="case-message case-' . $table_key . '">',
          // Replace any tokens that are in our table message.
          '#markup' => $service_TripalTokensParser->replaceTokens($table_case['message'], $combined_tokens),
          '#suffix' => '</div>',
        ],
        [
          '#type' => 'table',
          '#header' => $table_case['header'],
          '#attributes' => [
            'class' => [
              'table-case-' . $table_key,
            ],
          ],
          '#rows' => $table_case['rows'],
        ],
      ]);
    }
    $render_array = [
      '#theme' => 'item_list',
      '#type' => 'ul',
      '#attributes' => [
        'class' => [
          'tc-germplasm-name-exists-failures',
        ],
      ],
      '#items' => $tables,
    ];

    return $render_array;
  }

  /**
   * Fill missing cells in a table's rows with empty cells.
   *
   * Since the params are passed in by reference, they are updated as follows:
   * - Headers are sorted by index.
   * - Rows are sorted by index and have column keys added with an empty value
   *   where missing cells were previously.
   *
   * @param array $header
   *   The contents of the table's header, where key = index of the column
   *   header, and value = content of the column header.
   *   ie. $header[COLUMN INDEX][COLUMN VALUE].
   * @param array $rows
   *   The contents of the table's rows. Each row is keyed by the line number of
   *   the original input file that triggered validation failure, followed by
   *   the index of the column, followed by the column's contents.
   *   ie. [LINE NUMBER][COLUMN INDEX][COLUMN VALUE].
   *
   * @return void
   *   NOTE: $header and $rows are passed in by reference, meaning that the
   *   original arrays are modified directly and thus there is no return value.
   */
  public static function fillTableGaps(array &$header, array &$rows) {
    // Sort the table header.
    ksort($header);
    if (count($header) > 2) {
      foreach (array_keys($rows) as $line_no) {
        foreach (array_keys($header) as $index) {
          if (!array_key_exists($index, $rows[$line_no])) {
            $rows[$line_no][$index] = '';
          }
        }
        // Finally, sort the row by keys.
        ksort($rows[$line_no]);
      }
    }
  }

  /**
   * A helper method that will process any simple message into a render array.
   *
   * @param string $message
   *   A non-empty string that is the message to be displayed to the user. If
   *   desired, this string may include HTML tags.
   * @param array $classes
   *   [OPTIONAL] An array of strings to give to '#wrapper_attributes' of the
   *   render array as a set of css classes. By default, this method adds the
   *   class:
   *   - 'simple-validation-warning'.
   *
   * @return array
   *   A render array of type "html_tag", used to display a warning to the user
   *   regarding a failed validation result.
   *
   * @throws \Exception
   *   - If $message is an empty string.
   */
  public static function renderSimpleWarningMessage(string $message, array $classes = []) {

    if (empty($message)) {
      throw new \Exception('Expected a non-empty string for the message passed into renderSimpleWarningMessage().');
    }

    // Add our universal class for simple validation warning messages.
    $classes[] = 'simple-validation-warning';

    return [
      '#type' => 'html_tag',
      '#tag' => 'div',
      '#value' => $message,
      '#attributes' => [
        'class' => $classes,
      ],
    ];
  }

  /**
   * Valid delimited file process message.
   *
   * @todo Remove this method and any reference when the process method for
   * ValidDelimitedFile becomes available.
   *
   * REMOVE IF NOT REQUIRED.
   *
   * @param array $failures
   *   Failures array.
   *
   * @return array
   *   A render array.
   */
  public function processValidDelimitedFileFailures(array $failures) {

    // Define our table headers.
    $table_header = ['Line Number', 'Line Contents'];

    // For this validator there can be up to 2 tables:
    // - 'table'->'unsupported': Empty rows or no supported delimiters present.
    // - 'table'->'delimited': Rows that don't delimit to the expected number of
    //   columns.
    $table = [];

    // Loop through each row in the $failures array and piece apart the
    // different cases into different tables.
    foreach ($failures as $line_no => $validation_result) {
      // Check the format of the validation_result parameter.
      ImportValidationHelper::checkValidationStatusArray($validation_result, 'ValidDelimitedFile', $line_no);
      // Keeps track of which table this one line's validation result gets added
      // to based on the case it triggered.
      $table_case = '';
      if (($validation_result['case'] == 'Raw row is empty') ||
          ($validation_result['case'] == 'None of the delimiters supported by the file type was used')) {
        $table_case = 'unsupported';
      }
      elseif (($validation_result['case'] == 'Raw row exceeds number of strict columns') ||
            ($validation_result['case'] == 'Raw row has insufficient number of columns')) {
        $table_case = 'delimited';
        if (!isset($num_expected_columns)) {
          $num_expected_columns = $validation_result['failedItems']['expected_columns'];
          $strict = $validation_result['failedItems']['strict'];
        }
      }
      elseif (($validation_result['case'] == 'Raw row has expected number of columns') ||
             ($validation_result['case'] == 'Raw row is delimited')) {
        throw new \Exception("The case string returned by the ValidDelimitedFile validator at line #$line_no implies validation passed, but valid is set to FALSE.");
      }
      else {
        throw new \Exception("The case string returned by the ValidDelimitedFile validator at line #$line_no is not recognized as a potential case.");
      }

      // Checked all cases, now add a row to our appropriate table.
      if (!array_key_exists($table_case, $table)) {
        // Declare the array storing rows for this table, if not already.
        $table[$table_case]['rows'] = [];
      }
      $table[$table_case]['rows'][] = [
        $line_no,
        $validation_result['failedItems']['raw_row'],
      ];
    }
    // Check which tables were created, and assign the correct message.
    // Note that both tables can exist at the same time.
    if (array_key_exists('unsupported', $table)) {
      $table['unsupported']['message'] = 'The following lines in the input file do not contain a valid delimiter supported by this importer.';
    }
    if (array_key_exists('delimited', $table)) {
      // Check if number of columns is strict, then set the message accordingly.
      if ($strict) {
        $strict_or_min = 'strict';
      }
      else {
        $strict_or_min = 'minimum';
      }
      $message = "This importer requires a $strict_or_min number of $num_expected_columns columns for each line. The following lines do not contain the expected number of columns.";
      $table['delimited']['message'] = $message;
    }

    // Finally, loop through our tables and build our render array.
    $tables = [];
    foreach ($table as $table_key => $table_case) {
      $tables[] = [
        [
          '#prefix' => '<div class="case-message case-' . $table_key . '">',
          '#markup' => $table_case['message'],
          '#suffix' => '</div>',
        ],
        [
          '#type' => 'table',
          '#header' => $table_header,
          '#attributes' => [
            'class' => [
              'tcp-raw-row',
              'table-case-' . $table_key,
            ],
          ],
          '#rows' => $table_case['rows'],
        ],
      ];
    }

    $render_array = [
      '#theme' => 'item_list',
      '#type' => 'ul',
      '#attributes' => [
        'class' => [
          'tcp-valid-delimited-file-failures',
        ],
      ],
      '#items' => $tables,
    ];

    return $render_array;
  }

  /**
   * Processes failed validation from ValidHeaders into a render array.
   *
   * @param array $validation_result
   *   An associative array that was returned by the ValidHeaders validator in
   *   the event of failed validation. It contains the following keys:
   *   - 'case': a developer-focused string describing the case checked.
   *   - 'valid': FALSE to indicate that validation failed.
   *   - 'failedItems': an array of items that failed, either:
   *     - 'headers': A string indicating the header row is empty.
   *     - an array of column headers that was in the input file.
   *
   * @return array
   *   A render array of type unordered list which is used to display feedback
   *   to the user about the case that failed and the failed items from the
   *   input file. This unordered list will include a table with a row of the
   *   expected headers followed by a row of the provided headers.
   *
   * @throws \Exception
   *   - If the validation_result parameter was not formatted properly.
   *   - If the case string returned by the validator implied validation passed.
   *   - If the case string returned by the validator is not recognized.
   */
  public function processValidHeadersFailures(array $validation_result) {
    // Check the format of the validation_result parameter.
    ImportValidationHelper::checkValidationStatusArray($validation_result, 'ValidHeaders');

    if ($validation_result['case'] == 'Header row is an empty value') {
      $message = 'The file has an empty row where the header was expected.';
      $provided_headers = [];
    }
    elseif ($validation_result['case'] == 'Headers do not match expected headers') {
      $message = 'One or more of the column headers in the input file does not match what was expected. Please check if your column header is in the correct order and matches the template exactly.';
      $provided_headers = $validation_result['failedItems'];
    }
    elseif ($validation_result['case'] == 'Headers provided does not have the expected number of headers') {
      $num_expected_columns = count($this->headers);
      $message = "This importer requires a strict number of $num_expected_columns column headers. Please ensure your column header matches the template exactly and remove any additional column headers from the file.";
      $provided_headers = $validation_result['failedItems'];
    }
    elseif ($validation_result['case'] == 'Headers exist and match expected headers') {
      throw new \Exception('The case string returned by the ValidHeaders validator implies validation passed, but valid is set to FALSE.');
    }
    else {
      throw new \Exception('The case string returned by the ValidHeaders validator is not recognized as a potential case.');
    }
    // Get the expected and actual headers to build the rows in our table render
    // array.
    $expected_headers = array_column($this->headers, 'name');

    // Build the render array.
    $render_array = [
      '#theme' => 'item_list',
      '#type' => 'ul',
      '#attributes' => [
        'class' => [
          'tcp-valid-headers-failures',
        ],
      ],
      '#items' => [
        [
          [
            '#prefix' => '<div class="case-message">',
            '#markup' => $message,
            '#suffix' => '</div>',
          ],
          [
            '#type' => 'table',
            '#attributes' => [],
            '#rows' => [
              [
                'data' => [
                  'header' => [
                    'data' => 'Expected Headers',
                    'header' => TRUE,
                  ],
                ] + $expected_headers,
                'class' => ['expected-headers'],
              ],
              [
                'data' => [
                  'header' => [
                    'data' => 'Provided Headers',
                    'header' => TRUE,
                  ],
                ] + $provided_headers,
                'class' => ['provided-headers'],
              ],
            ],
          ],
        ],
      ],
    ];

    return $render_array;
  }

}
