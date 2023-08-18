<?php

namespace Drupal\trpcultivate_germplasm\Plugin\TripalImporter;

use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;

/**
 * Provides an importer for loading germplasm accessions from a tab-delimited
 * file.
 *
 * @TripalImporter(
 *   id = "trpcultivate-germplasm-accession",
 *   label = @Translation("Tripal Cultivate: Germplasm Accessions"),
 *   description = @Translation("Imports germplasm accessions into Chado with metadata meeting BrAPI standards."),
 *   file_types = {"tsv", "txt"},
 *   use_analysis = FALSE,
 *   require_analysis = FALSE,
 *   upload_description = "Germplasm file should be a tab separated file with the following colums:<ol>
 *     <li>Germplasm Name: Name of this germplasm accession.</li>
 *     <li>External Database: The institution who assigned the following accession.</li>
 *     <li>Accession Number: A unique identifier for the accession. </li>
 *     <li>Germplasm Genus: The genus of the accession.</li>
 *     <li>Germplasm Species: The species of the accession.</li>
 *     <li>Germplasm Subtaxa: Subtaxon can be used to store any additional taxonomic identifier.</li>
 *     <li>Institute Code: The code for the Institute that has bred the material.</li>
 *     <li>Institute Name: The name of the Institute that has bred the material.</li>
 *     <li>Country of Origin Code: 3-letter ISO 3166-1 code of the country in which the sample was originally.</li>
 *     <li>Biological Status of Accession: The 3 digit code representing the biological status of the accession.</li>
 *     <li>Breeding Method: The unique identifier for the breeding method used to create this germplasm.</li>
 *     <li>Pedigree: The cross name and optional selection history.</li>
 *     <li>Synonyms: The synonyms of the accession.</li>
 *     </ol>",
 *   file_upload = True,
 *   file_load = True,
 *   file_remote = True,
 *   file_required = True,
 *   cardinality = 1,
 *
 * )
 */
class GermplasmAccessionImporter extends ChadoImporterBase {

}
