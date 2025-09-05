<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\Renderer;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\tripal_chado\Database\ChadoConnection;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Drupal\trpcultivate\Plugin\Validators\ValidDataFile;
use Drupal\trpcultivate\Plugin\Validators\EmptyCell;
use Drupal\trpcultivate\Plugin\Validators\ValidDelimitedFile;
use Drupal\trpcultivate\Plugin\Validators\ValidHeaders;
use Drupal\trpcultivate\Plugin\Validators\ValueInList;
use Drupal\trpcultivate\TripalCultivateValidator\TripalCultivateValidatorManager;
use Drupal\trpcultivate\Service\TripalCultivateFileTemplateService;
use Drupal\trpcultivate\Service\ImportValidationHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tripal\TripalImporter\Attribute\TripalImporter;

/**
 * Tripal Cultivate Germplasm - Cross Importer.
 *
 * An importer for germplasm crosses developed in a breeding program.
 *
 * @TripalImporter(
 *   id = "trpcultivate-germplasm-cross-importer",
 *   label = @Translation("Tripal Cultivate: Germplasm Cross Importer"),
 *   description = @Translation("Loads germplasm crosses into the system. This is useful for large datasets to ease the upload process."),
 *   file_types = {"tsv"},
 *   upload_description = @Translation("Please provide a data file."),
 *   upload_title = @Translation("Germplasm Cross Data File*"),
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
   id: 'trpcultivate-germplasm-cross-importer',
   label: new TranslatableMarkup('Tripal Cultivate: Germplasm Cross Importer'),
   description: new TranslatableMarkup('Loads germplasm crosses into the system. This is useful for large datasets to ease the upload process.'),
   file_types: ['tsv'],
   upload_description: new TranslatableMarkup('Please provide a data file.'),
   upload_title: new TranslatableMarkup('Germplasm Cross Data File*'),
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
class GermplasmCrossImporter extends ChadoImporterBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

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
  private $headers = [
    [
      'name' => 'Year',
      'description' => 'The year this cross was made in (e.g. 2020).',
      'type' => 'required',
    ],
    [
      'name' => 'Season',
      'description' => 'The season this cross was made in (e.g. Spring, Fall, Winter, Summer).',
      'type' => 'required',
    ],
    [
      'name' => 'Cross Number',
      'description' => 'A unique identifier for this cross (e.g. 1234S).',
      'type' => 'required',
    ],
    [
      'name' => 'Maternal Parent',
      'description' => 'The name of the maternal parent of this cross.',
      'type' => 'required',
    ],
    [
      'name' => 'Paternal Parent',
      'description' => 'The name of the paternal parent of this cross.',
      'type' => 'required',
    ],
    [
      'name' => 'Cross Type',
      'description' => 'The type of cross (e.g. single, double, triple).',
      'type' => 'required',
    ],
    [
      'name' => 'Seed Type',
      'description' => 'Either the market class or the seed coat colour of the seed resulting from this cross.',
      'type' => 'optional',
    ],
    [
      'name' => 'Cotyledon Colour',
      'description' => 'The cotyledon colour of the seed resulting from this cross.',
      'type' => 'optional',
    ],
    [
      'name' => 'Comment',
      'description' => 'A free-text comment about this cross.',
      'type' => 'optional',
    ],
  ];

  /**
   * A Database query interface for querying Chado using Tripal DBX.
   *
   * @var Drupal\tripal_chado\Database\ChadoConnection
   */
  protected ChadoConnection $chado_connection;

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
   * The Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected EntityTypeManager $service_entityTypeManager;

  /**
   * The Drupal Renderer.
   *
   * @var \Drupal\Core\Render\Renderer
   */
  protected Renderer $service_Renderer;

  /**
   * The Drupal Messenger Service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $service_Messenger;

  /**
   * Used to reference the validation result summary in the form.
   *
   * @var string
   */
  private $validation_result = 'validation_result';

  /**
   * Expected column settings.
   *
   * @var array
   */
  private $expected_columns;

  /**
   * Constructs the traits importer.
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
    $this->service_entityTypeManager = $service_entityTypeManager;
    $this->service_FileTemplate = $service_FileTemplate;
    $this->service_entityTypeManager = $service_entityTypeManager;
    $this->service_Renderer = $renderer;
    $this->service_Messenger = $messenger;
  }

  /**
   * {@inheritdoc}
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
   * Configure all the validators this importer uses.
   *
   * @param array $form_values
   *   An array of the importer form values provided to formValidate.
   * @param string $file_mime_type
   *   A string of the MIME type of the input file, usually grabbed from the
   *   file object using $file->getMimeType()
   *
   * @return array
   *   A listing of configured validator objects first keyed by their inputType.
   *   More specifically:
   *   - [inputType]: and array of validator instances. Not an
   *     associative array although the keys do indicate what
   *     order they should be run in.
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

    // -----------------------------------------------------
    // Metadata
    // - Future organism validator goes here

    // -----------------------------------------------------
    // File level
    // - File exists and is the expected type
    $instance = $this->service_validatorPluginManager->createInstance('valid_data_file');
    // Set supported mime-types using the valid file extensions (file_types) as
    // defined in the annotation for this importer on line 25.
    $supported_file_extensions = $this->plugin_definition['file_types'];
    $instance->setSupportedMimeTypes($supported_file_extensions);
    $validators['file']['valid_data_file'] = $instance;

    // -----------------------------------------------------
    // Raw row level
    // - File rows are properly delimited
    $instance = $this->service_validatorPluginManager->createInstance('valid_delimited_file');
    // Configure the number of columns in a single row for this validator. We
    // want a minimum number of 6 columns, so no need to set strict.
    $instance->setExpectedColumns(6, FALSE);
    $this->expected_columns = $instance->getExpectedColumns();
    // Set the MIME type of this input file.
    $instance->setFileMimeType($file_mime_type);
    $validators['raw-row']['valid_delimited_file'] = $instance;

    // -----------------------------------------------------
    // Header Level
    // - All column headers match expected header format
    $instance = $this->service_validatorPluginManager->createInstance('valid_headers');
    // Use our $headers property to configure what we expect for a header in the
    // input file.
    $instance->setHeaders($this->headers);
    // Configure the expected number of columns and set it to be strict.
    $num_columns = count($this->headers);
    $instance->setExpectedColumns($num_columns, TRUE);
    $validators['header-row']['valid_header'] = $instance;

    // -----------------------------------------------------
    // Data Row Level
    // - All data row cells in columns 0-5 are not empty
    $instance = $this->service_validatorPluginManager->createInstance('empty_cell');
    $indices = [
      $header_index['Year'],
      $header_index['Season'],
      $header_index['Cross Number'],
      $header_index['Maternal Parent'],
      $header_index['Paternal Parent'],
      $header_index['Cross Type'],
    ];
    $instance->setIndices($indices);
    $validators['data-row']['empty_cell'] = $instance;

    // - The column 'Season' is one of: Winter, Spring, Summer, Fall
    $instance = $this->service_validatorPluginManager->createInstance('value_in_list');
    $instance->setIndices([$header_index['Season']]);
    $instance->setValidValues([
      'Winter',
      'Spring',
      'Summer',
      'Fall',
    ]);
    $validators['data-row']['valid_season'] = $instance;

    // - @todo Germplasm name exists
    return $validators;
  }

  /**
   * {@inheritDoc}
   */
  public function form($form, &$form_state) {
    // Always call the parent form to ensure Chado is handled properly.
    $form = parent::form($form, $form_state);

    // Validation result.
    $storage = $form_state->getStorage();

    // Full validation result summary.
    if (isset($storage[$this->validation_result])) {
      $validation_result = $storage[$this->validation_result];

      $form['validation_result'] = [
        '#type' => 'inline_template',
        '#theme' => 'validation_result_window',
        '#data' => [
          'validation_result' => $validation_result,
        ],
        '#weight' => -100,
      ];
    }

    // Field Organism:
    // Prepare select options with only active organisms.
    $all_organisms = chado_get_organism_select_options();
    $active_organisms = array_combine($all_organisms, $all_organisms);

    // If there is only one organism, it should be the default.
    $default_organism = 0;
    if ($active_organisms && count($active_organisms) == 1) {
      $default_organism = reset($active_organisms);
    }

    // Field organism.
    $form['organism'] = [
      '#type' => 'select',
      '#title' => 'Organism',
      '#description' => $this->t('The species of the germplasm being imported. If your file contains multiple species, please separate the crosses into one file per species.'),
      '#empty_option' => '- Select -',
      '#options' => $active_organisms,
      '#default_value' => $default_organism,
      '#weight' => -99,
      '#required' => TRUE,
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
      ->addStatus($this->t('<b>Your file import was successful and a Job Process Request has been created to securely save your data.</b>'));
  }

  /**
   * {@inheritdoc}
   */
  public function formValidate($form, &$form_state) {

    $form_values = $form_state->getValues();

    $file_id = $form_values['file_upload'];

    // Load our file object.
    $file = $this->service_entityTypeManager->getStorage('file')->load($file_id);

    // Get the mime type which is used to validate the file and split the rows.
    $file_mime_type = $file->getMimeType();

    // Configure the validators.
    $validators = $this->configureValidators($form_values, $file_mime_type);

    // A FLAG to keep track if any validator fails.
    // We will only continue to the next input-type if all validators of the
    // current input-type pass.
    $failed_validator = FALSE;

    // Keep track of failed items. This is a nested array keyed as follows:
    // - The unique name of a validator instance, which maps to the second level
    //   of the $validators array.
    //   - For row-level input-type validators, this is further keyed by the
    //     row number that the failure for this validator instance occurred.
    // The value (level 1 for non row-level validators, level 2 for row-level
    // validators) is the validation results array returned by the validator.
    $failures = [];

    // ************************************************************************
    // Metadata Validation
    // ************************************************************************
    /*
    foreach ($validators['metadata'] as $validator_name => $validator) {
      // Set failures for this validator name to an empty array to signal that
      // this validator has been run.
      $failures[$validator_name] = [];
      // Validate metadata input value.
      $result = $validator->validateMetadata($form_values);

      // Check if validation failed and save the results if it did.
      if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
        $failed_validator = TRUE;
        $failures[$validator_name] = $result;
      }
    }
    */

    // Check if any previous validators failed before moving on to the next
    // input-type validation.
    if ($failed_validator === FALSE) {
      // **********************************************************************
      // File Validation
      // **********************************************************************
      foreach ($validators['file'] as $validator_name => $validator) {
        // Set failures for this validator name to an empty array to signal that
        // this validator has been run.
        $failures[$validator_name] = [];
        $result = $validator->validateFile($file_id);

        // Check if validation failed and save the results if it did.
        if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
          $failed_validator = TRUE;
          $failures[$validator_name] = $result;
        }
      }
    }

    // Check if any previous validators failed before moving on to the next
    // input-type validation.
    if ($failed_validator === FALSE) {

      // Open and read file in this uri.
      $file_uri = $file->getFileUri();
      $handle = fopen($file_uri, 'r');

      // Line counter.
      $line_no = 0;

      // Begin column and row validation.
      while (!feof($handle)) {
        // This variable will indicate if the validator has failed. It is set to
        // FALSE for every row to indicate that the line is valid to start with,
        // then execute the tests below to prove otherwise.
        $row_has_failed = FALSE;

        // Current row.
        $line = fgets($handle);
        $line_no++;
        // Skip this line if its empty, but line numbers should remain accurate.
        if (empty(trim($line))) {
          continue;
        }

        // ********************************************************************
        // Raw Row Validation
        // ********************************************************************
        foreach ($validators['raw-row'] as $validator_name => $validator) {
          // Set failures for this validator name to an empty array to signal
          // that this validator has been run.
          if (!array_key_exists($validator_name, $failures)) {
            $failures[$validator_name] = [];
          }

          $result = $validator->validateRawRow($line);

          // Check if validation failed and save the results if it did.
          if (array_key_exists('valid', $result) && $result['valid'] === FALSE) {
            $row_has_failed = TRUE;
            $failures[$validator_name][$line_no] = $result;
          }
        }

        // If any raw-row validators failed, skip further validation and move
        // on to the next row in the data file.
        if ($row_has_failed === TRUE) {
          $failed_validator = TRUE;
          continue;
        }

        // ********************************************************************
        // Header Row Validation
        // ********************************************************************
        if ($line_no == 1) {
          // Split line into an array of values.
          $header_row = ImportValidationHelper::splitRowIntoColumns($line, $file_mime_type);

          foreach ($validators['header-row'] as $validator_name => $validator) {
            // Set failures for this validator name to an empty array to signal
            // that this validator has been run.
            if (!array_key_exists($validator_name, $failures)) {
              $failures[$validator_name] = [];
            }

            $result = $validator->validateRow($header_row);

            // Check if validation failed and save the results if it did.
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

        // ********************************************************************
        // Data Row Validation
        // ********************************************************************
        elseif ($line_no > 1) {
          // Split line into an array using the delimiter supported by this
          // importer when it was configured.
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
              $row_has_failed = TRUE;
              $failed_validator = TRUE;
              $failures[$validator_name][$line_no] = $result;
            }
          }
        }
      }
      // Close the file.
      fclose($handle);
    }

    $validation_feedback = $this->processValidationMessages($failures);

    // Save all validation results in Drupal storage to create a summary report.
    $storage = $form_state->getStorage();
    $storage[$this->validation_result] = $validation_feedback;
    $form_state->setStorage($storage);

    // Check if the $validation_feedback contains 'fail' or 'todo' status.
    // If either is found, prevent form submission.
    $submit_form = TRUE;

    foreach ($validation_feedback as $feedback_item) {
      if ($feedback_item['status'] == 'todo' || $feedback_item['status'] == 'fail') {
        $submit_form = FALSE;

        // No need to inspect other validators, a single instance of fail/todo
        // is sufficient to prevent form submission.
        break;
      }
    }

    if ($submit_form === FALSE) {
      // Provide a general error message indicating that input values and/or the
      // data file may contain one or more errors.
      $this->service_Messenger
        ->addError($this->t('Your file import was not successful. Please check the Validation Result Window for errors and try again.'));

      // Prevent this form from submitting and reload form with all the
      // validation failures in the storage system.
      $form_state->setRebuild(TRUE);
    }
  }

  /**
   * Configures and processes validation messages for the user.
   *
   * @param array $failures
   *   An array containing the return values from any failed validators. If
   *   validation was run for a validator instance, this is keyed by the unique
   *   name assigned to each validator-input type combination. This key will
   *   only contain values IF validation failed at any point that it was run. It
   *   is further keyed by row number IF the validator failed on that row as a
   *   row-level validator.
   *   Specifically:
   *   - [VALIDATOR INSTANCE NAME]
   *     - [ROW NUMBER (only if row-level validator)]
   *       - 'case': a developer-focused string describing the case checked.
   *       - 'valid': FALSE to indicate that validation failed.
   *       - 'failedItems': an array of items that failed. Structure of this
   *         array is dependent on the validator.
   *
   * @return array
   *   An array of feedback to provide to the user. It summarizes the validation
   *   results reported by the validators in formValidate (i.e. $failures). This
   *   array is keyed by a validation line, which is a string that is associated
   *   with a line in the validate UI dispalyed to the user. Specifically:
   *   - [VALIDATION LINE]:
   *     - 'title': A user-focused message describing the validation that took
   *       place.
   *     - 'status': One of: 'todo', 'pass', 'fail'.
   *     - 'details': A render array that will display details of any failures
   *       to guide the user to fix problems with their input file. The type of
   *       render array depends on the validator, but the most common types are
   *       item list and table.
   */
  public function processValidationMessages($failures) {
    // Array to hold all the user feedback. Currently this includes an entry for
    // each validator. However, in future designs we may combine more then one
    // validator into a single line in the validate UI and, thus, a single entry
    // in this array. Everything is set to status of 'todo' to start and will
    // only change to one of 'pass' or 'fail' if the $failures[] array is
    // defined for that validator, indicating that validation did take place.
    $messages = [
      // ----------------------------- METADATA --------------------------------
      // ------------------------------- FILE ----------------------------------
      'valid_data_file' => [
        'title' => 'File is valid and not empty',
        'status' => 'todo',
        'details' => '',
      ],
      // ----------------------------- RAW ROW ---------------------------------
      'valid_delimited_file' => [
        'title' => 'Lines are properly delimited',
        'status' => 'todo',
        'details' => '',
      ],
      // ---------------------------- HEADER ROW -------------------------------
      'valid_header' => [
        'title' => 'File has all of the column headers expected',
        'status' => 'todo',
        'details' => '',
      ],
      // ----------------------------- DATA ROW --------------------------------
      'empty_cell' => [
        'title' => 'Required cells contain a value',
        'status' => 'todo',
        'details' => '',
      ],
      'valid_season' => [
        'title' => 'Values in column "Season" are valid',
        'status' => 'todo',
        'details' => '',
      ],
    ];

    $header_names = array_column($this->headers, 'name');

    // A flag to indicate whether any data row level validation can be set to
    // pass or remains as 'todo' if there are no failures at that stage. This is
    // because we don't want to mislead the user to think all data rows pass
    // validation if there are raw rows that failed, since they haven't been
    // looked at yet by data row validators.
    $raw_row_failed = FALSE;

    // ---------------------- Process Validation Results -----------------------
    // For each validator:
    // 1. Check if $failures[$validator_name] exists, which indicates it was
    // run. If it was not run, then do nothing since it has already been marked
    // as "todo" in the $messages array.
    // 2. Check if $failures[$validator_name] is empty, which indicates that
    // validation passed and there are no errors to report for this validator.
    // 3. Otherwise, process failures for this validator with a dedicated method
    // that will build a render array of the feedback for the user.
    // -------------------------------------------------------------------------
    // ValidDataFile.
    $validator_name = 'valid_data_file';
    if (array_key_exists($validator_name, $failures)) {
      if (!empty($failures[$validator_name])) {
        $messages[$validator_name]['status'] = 'fail';
        $messages[$validator_name]['details'] = ValidDataFile::processItemWithSimpleList($failures[$validator_name]);
      }
      else {
        $messages[$validator_name]['status'] = 'pass';
      }
    }

    // ValidDelimitedFile.
    $validator_name = 'valid_delimited_file';
    if (array_key_exists($validator_name, $failures)) {
      if (!empty($failures[$validator_name])) {
        // Set this flag so that data row-level validation doesn't pass.
        $raw_row_failed = TRUE;
        $messages[$validator_name]['status'] = 'fail';

        $metadata = [
          'strict_flag' => $this->expected_columns['strict'],
          'number_of_columns' => $this->expected_columns['number_of_columns'],
        ];
        $messages[$validator_name]['details'] = ValidDelimitedFile::processListWithDescribedTable($failures[$validator_name], $metadata);
      }
      else {
        $messages[$validator_name]['status'] = 'pass';
      }
    }

    // ValidHeaders.
    $validator_name = 'valid_header';
    if (array_key_exists($validator_name, $failures)) {
      if (!empty($failures[$validator_name])) {
        $messages[$validator_name]['status'] = 'fail';

        $metadata = [
          'column_headers' => $header_names,
        ];
        $messages[$validator_name]['details'] = ValidHeaders::processListWithDescribedTable($failures[$validator_name], $metadata);
      }
      else {
        $messages[$validator_name]['status'] = 'pass';
      }
    }

    // EmptyCell.
    $validator_name = 'empty_cell';
    if (array_key_exists($validator_name, $failures)) {
      if (!empty($failures[$validator_name])) {
        $messages[$validator_name]['status'] = 'fail';

        $metadata = [
          'column_headers' => $header_names,
        ];
        $messages[$validator_name]['details'] = EmptyCell::processListWithDescribedTable($failures[$validator_name], $metadata);
      }
      // Only pass if raw row validation didn't fail.
      elseif (!$raw_row_failed) {
        $messages[$validator_name]['status'] = 'pass';
      }
      // Otherwise, leave status as 'todo' since 1+ raw rows failed.
    }

    // Valid Season using the ValueInList validator.
    $validator_name = 'valid_season';
    if (array_key_exists($validator_name, $failures)) {
      if (!empty($failures[$validator_name])) {
        $messages[$validator_name]['status'] = 'fail';

        $metadata = [
          'expected_values' => ['Winter', 'Spring', 'Summer', 'Fall'],
          'column_headers' => $header_names,
        ];
        $messages[$validator_name]['details'] = ValueInList::processListWithDescribedTable($failures[$validator_name], $metadata);
      }
      // Only pass if raw row validation didn't fail.
      elseif (!$raw_row_failed) {
        $messages[$validator_name]['status'] = 'pass';
      }
      // Otherwise, leave status as 'todo' since 1+ raw rows failed.
    }

    return $messages;
  }

  /**
   * {@inheritDoc}
   */
  public function run() {
    // Values provided by user in the importer page.
    $genus = $this->arguments['run_args']['genus'];
    // @todo Lookup genus
    // Traits data file id.
    $file_id = $this->arguments['files'][0]['fid'];
    // Load file object.
    $file = $this->service_entityTypeManager->getStorage('file')->load($file_id);
    // Open and read file in this uri.
    $file_uri = $file->getFileUri();
    $handle = fopen($file_uri, 'r');

    // Line counter.
    $line_no = 0;
    // Headers.
    // Only the header names are needed, so pull them out into a new array.
    $headers = array_column($this->headers, 'name');
    $headers_count = count($headers);

    while (!feof($handle)) {
      // Current row.
      $line = fgets($handle);

      if ($line_no > 0 && !empty(trim($line))) {
        // Line split into individual data point.
        $data_columns = str_getcsv($line, "\t");
        // Sanitize every data in rows and columns.
        $data = array_map(function ($col) {
          return isset($col) ? trim(str_replace(['"', '\''], '', $col)) : '';
        }, $data_columns);

        // @todo Process data into chado tables.
        unset($data);
      }

      // Next line.
      $line_no++;
    }

    // Close the file.
    fclose($handle);
  }

  /**
   * {@inheritdoc}
   */
  public function postRun() {

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
    $notes = $this->t('The order of the above columns is important and your file must include a header!');

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
