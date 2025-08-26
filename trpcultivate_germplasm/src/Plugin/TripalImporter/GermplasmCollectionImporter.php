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
#[TripalImporter(
  id: 'trpcultivate-germplasm-population-importer',
  label: new TranslatableMarkup('Tripal Importer: Germplasm Collection Importer'),
  description: new TranslatableMarkup('Imports germplasm populations (i.e. RIL, NAM, cross progeny) into testchado.'),
  file_types: ['tsv', 'txt'],
  upload_description: new TranslatableMarkup('Germplasm file should be a <strong>TAB Separated Values</strong> file (.tsv) containing a header with the following columns:<ol><li><strong>Name</strong>: The name of the RIL individual.</li><li><strong>Type</strong>: The vocabulary database name + : + cvterm (ie. schema:F1) which should be used for the stock record, must exist.</li><li><strong>Scientific Name</strong>: The genus + species of the organism to be used for the stock record, must exist.</li><li><strong>Uniquename</strong>: (optional) The uniquename to use if you do not want to use the pattern/prefix below. Custom value for this column must be unique for every line.</li></ol><p>Each row in the file should describe a specific individual to be created and linked to the Population Entry with the specified relationship.</p><p><strong>NOTE:</strong> This importer will not permit duplicate lines with identical Name + Type + Scientific Name + Uniquename in the file.<br />A warning will be issued when duplicate line, with the exception Uniquename has been detected and the Importer may proceed.</p>'),
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

    // Configure the valid data file validator.
    $instance_data_file = $this->service_validatorPluginManager->createInstance('valid_data_file');
    $instance_data_file->setFileMimeType($file_mime_type);
    $instance_data_file->setSupportedMimeTypes(['tsv', 'txt']);
    $validators['file']['valid_data_file'] = $instance_data_file;

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

    // Create a $messages entry for valid data file.
    $messages['valid_data_file'] = [
      'title' => 'File is a valid data file',
      'status' => 'todo',
      'details' => '',
    ];

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

    // Create a $messages entry for valid delimited file.
    $messages['valid_delimited_file'] = [
      'title' => 'File is delimitted correctly',
      'status' => 'todo',
      'details' => '',
    ];

    // Configure the valid_delimited_file metadata.
    $valid_delimited_file_metadata = [
      'strict_flag' => TRUE,
      'number_of_columns' => 3,
    ];

    // Call the processListWithDescribedTable() method in ValidDelimitedFile
    // class to check if there are any failures for the valid delimited
    // file validator.
    if (array_key_exists('valid_delimited_file', $failures)) {
      if (!empty($failures['valid_delimited_file'])) {
        $messages['valid_delimited_file']['status'] = 'fail';
        $messages['valid_delimited_file']['details'] = ValidDelimitedFile::processListWithDescribedTable($failures['valid_delimited_file'], $valid_delimited_file_metadata);
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

    // Configure the metadata with valid headers.
    $headers_metadata = [
      'column_headers' => [
        0 => 'Name',
        1 => 'Type',
        2 => 'Scientific Name',
        3 => 'Uniquename',
      ],
    ];

    // Call the processListWithDescribedTable() method in ValidHeaders class
    // to check if there are any failures for the valid headers validator.
    if (array_key_exists('valid_headers', $failures)) {
      if (!empty($failures['valid_headers'])) {
        $messages['valid_headers']['status'] = 'fail';
        $messages['valid_headers']['details'] = ValidHeaders::processListWithDescribedTable($failures['valid_headers'], $headers_metadata);
      }
      else {
        $messages['valid_headers']['status'] = 'pass';
      }
    }

    // Create a $messages entry for empty cells.
    $messages['empty_cell'] = [
      'title' => 'No Empty Cells',
      'status' => 'todo',
      'details' => '',
    ];

    // Call the processListWithDescribedTable() method in EmptyCell class to
    // check if there are any failures for the empty cell validator.
    if (array_key_exists('empty_cell', $failures)) {
      if (!empty($failures['empty_cell'])) {
        $messages['empty_cell']['status'] = 'fail';
        $messages['empty_cell']['details'] = EmptyCell::processListWithDescribedTable($failures['empty_cell'], $headers_metadata);
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

    // Call the processListWithDescribedTable() method in GermplasmNameExists
    // class to check if there are any failures for the germplasm name exists
    // validator.
    if (array_key_exists('germplasm_name_exists', $failures)) {
      if (!empty($failures['germplasm_name_exists'])) {
        $messages['germplasm_name_exists']['status'] = 'fail';
        $messages['germplasm_name_exists']['details'] = GermplasmNameExists::processListWithDescribedTable($failures['germplasm_name_exists'], $headers_metadata);
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
          // Organism:
          // Use the genus and species encoded in the Scientific Name to
          // determine the organism.
          $organism_id = $this->parseOrganism($data_row[2]);

          // Throw exception if organism is not valid.
          if ($organism_id == NULL) {
            $failed_validator = TRUE;
            throw new \Exception('Scientific Name: ' . $data_row[2] . ' is not valid. Please provide a valid Scientific Name.');
          }

          // Type:
          // Use the database name and cvterm encoded in Type to determine
          // the stock type.
          $type_id = $this->parseTerm($data_row[1]);

          // Throw exception if type is not valid.
          if ($type_id == NULL) {
            $failed_validator = TRUE;
            throw new \Exception('Type: ' . $data_row[1] . ' is not valid. Please provide a valid Type.');
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

    if ($file && $file->filesize > 0) {
      $file_uri = $file->getFileUri();
      $handle = fopen($file_uri, 'r');
      if ($handle) {
        $i = 0;

        // Fetch the last stock_id auto-increment inserted, increment
        // the value each time a stock is added. This value is concatenated
        // to the prefix (when provided) that will make up the uniquename of
        // the stock.
        // Skip this when file has provided a custom uniquename.
        $id = $this->chado_connection->select('stock', 's')
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
            [$val_name, $val_type, $val_sciname, $val_uniqname] = $data_row;

            // Construct uniquename:
            // If line has no uniquename by using the prefix system
            // configuration and next sequence id of stock.
            $uniquename = ($val_uniqname == '')
              ? 'uniquename' . $population['entry'] . ($last_id + $i) : $val_uniqname;
            // Always encode in uppercase form.
            $uniquename = strtoupper($uniquename);

            // Organism:
            // Use the genus and species encoded in the Scientific Name to
            // determine the organism.
            $organism_id = $this->parseOrganism($val_sciname);

            // Type:
            // Use the database name and cvterm encoded in Type to determine
            // the stock type.
            $type_id = $this->parseTerm($val_type);

            // STOCK:
            $stock = [
              'name'      => $val_name,
              'uniquename'  => $uniquename,
              'organism_id'  => $organism_id,
              'type_id'   => $type_id,
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
   * Parse form values for Scientific Name (ogranism: genus+species).
   *
   * Fetch the matching row in chado.organism.
   *
   * @param string $value
   *   String, containing the genus and species in the
   *   following notation: Genus\sSpecies. ie. Lens culinaris.
   */
  public function parseOrganism($value) {
    $result = '';

    // Capture the genus and species from Scientific Name value.
    preg_match('/^(\w+)\s{1}(.*)/', $value, $match);
    if ($match !== FALSE && ($match[1] && $match[2])) {
      $values = [
        'genus' => $match[1],
        'species' => $match[2],
      ];

      // Fetch organism using the genus+species and return
      // the organism_id number.
      $query = $this->chado_connection->select('1:organism', 'o')
        ->fields('o', ['organism_id'])
        ->condition('o.genus', $values['genus'], '=')
        ->condition('o.species', $values['species'], '=')
        ->execute();

      $result = NULL;
      if ($organism_id = $query->fetchField()) {
        $result = $organism_id;
      }

    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function postRun() {}

}
