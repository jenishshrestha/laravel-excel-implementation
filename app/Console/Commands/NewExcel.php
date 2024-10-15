<?php

namespace App\Console\Commands;

use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;

class NewExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:new-excel';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     * @return void
     */
    public function handle()
    {
        // Load and format business sector data
        $oldBusinessSectorExcel = $this->formatExcelData('app/public/business-sector.csv');

        // Load and format news data
        $oldNewsExcel = $this->formatExcelData('app/public/Press-Release-2.0.csv');

        // Step 1: Adding new column news_business_sector_title after matching ID and news_business_sector
        $finalData = $this->businessSectorMapping($oldNewsExcel, $oldBusinessSectorExcel);

        // Step 2: Extract footnotes from the content and add to new column called footnotes
        $finalData = $finalData->map(function ($item) {
            $modifiedContent = $this->extractFootnotes($item['Content'] ?? '');


            $item['Content'] = $modifiedContent['cleaned_content'];
            $item['footnotes'] = $modifiedContent['footnotes'];

            // if ($item['ID'] == '21653') {
            //     dd($modifiedContent);
            // }
            return $item;
        });

        // Convert data to array with headers
        $rows = $this->prepareForExcel($finalData);

        // Store the final Excel file
        Excel::store(new class ($rows) implements \Maatwebsite\Excel\Concerns\FromArray {
            protected $finalData;
            public function __construct(array $finalData)
            {
                $this->finalData = $finalData;
            }

            public function array() : array
            {
                return $this->finalData;
            }
        }, 'public/modified-press-release.csv');

        print_r('New file has been generated');
    }

    /**
     * Format Excel data from a file.
     * 
     * formats into proper object of array format
     * @param mixed $filePath
     * @return \Illuminate\Support\Collection
     */
    private function formatExcelData($filePath)
    {
        $data = collect(data_get(Excel::toArray([], storage_path($filePath)), '0', []));
        $headers = $data->first() ?? [];

        return $data->skip(1)->map(function ($item) use ($headers) {
            return array_combine($headers, $item);
        });
    }

    /**
     * Manipulate new data into CSV
     * @param mixed $oldNewsExcel
     * @param mixed $oldBusinessSectorExcel
     * @return mixed
     */
    private function businessSectorMapping($oldNewsExcel, $oldBusinessSectorExcel)
    {

        // Pre-index the oldBusinessSectorExcel for faster lookups
        $indexedSectors = [];

        // Pre-process the oldBusinessSectorExcel to create an index for all keys
        $businessSectorKeys = ['EN', 'AR', 'CHN', 'FR', 'JP', 'ESP', 'TRK'];

        // Build a dictionary for faster lookup of new IDs
        // generates an array of mapped old id's with new id's
        foreach ($oldBusinessSectorExcel as $sector) {
            foreach ($businessSectorKeys as $key) {
                // Index sectors by their old value (trimmed) for each language key
                $trimmedValue = trim($sector[$key]);
                if (! empty($trimmedValue)) {
                    $indexedSectors[$trimmedValue] = $sector[$key . '_new'];
                }
            }
        }

        // dd($indexedSectors); // output values for test


        // Map over the oldNewsExcel and update the business sector IDs
        $modifiedData = $oldNewsExcel->map(function ($item) use ($indexedSectors) {
            $newIDs = [];

            // Split the news_business_sector if it contains multiple values separated by a pipe
            $sectors = explode('|', $item['news_business_sector']);

            // Iterate over each sector in the item
            foreach ($sectors as $sectorValue) {
                $trimmedSectorValue = trim($sectorValue);

                // Check if the sector value exists in the indexedSectors map
                if (isset($indexedSectors[$trimmedSectorValue])) {
                    $newIDs[] = $indexedSectors[$trimmedSectorValue]; // Add new ID to the list
                }
            }

            // Join the new IDs as a pipe-separated string (or handle how you prefer)
            $item['updated_business_sector_id'] = implode('|', $newIDs);

            return $item;
        });

        return $modifiedData;
    }

    /**
     * Prepare the final data for Excel export by adding headers.
     * @param mixed $collection
     * @return array
     */
    private function prepareForExcel($collection)
    {
        $headers = array_keys($collection->first() ?? []);
        $rows = $collection->map(function ($item) {
            return array_values($item);
        })->toArray();

        // Prepend headers to rows
        array_unshift($rows, $headers);
        return $rows;
    }


    /**
     * Extraction of footnotes from WYSIWIG Content
     * 
     * @param mixed $htmlContent
     * @return string[]
     */
    function extractFootnotes($htmlContent)
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true); // Suppress parsing errors
        $dom->loadHTML(mb_convert_encoding($htmlContent, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // XPath to find all footnote links
        $footnotes = $xpath->query('//a[contains(@href, "#_ftnref")]');

        $output = [];
        foreach ($footnotes as $footnote) {
            // Get the parent node of the footnote link
            $parent = $footnote->parentNode;

            // Check if the parent is a <p> tag
            if ($parent->nodeName === 'p') {
                $output[] = $dom->saveHTML($parent);

                // Remove the parent from the DOM
                $parent->parentNode->removeChild($parent);
            } else {
                // If not a <p> tag, find the closest parent that is a <p>
                $pParent = $parent;
                while ($pParent && $pParent->nodeName !== 'p') {
                    $pParent = $pParent->parentNode;
                }
                // If found, add the HTML of the <p> tag to output
                if ($pParent) {
                    $output[] = $dom->saveHTML($pParent);

                    // Remove the parent from the DOM
                    $pParent->parentNode->removeChild($pParent);
                }
            }
        }

        // Get the modified HTML after removing footnotes
        $bodyContent = '';
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $bodyContent = $dom->saveHTML($body);
        }

        // Remove the <body> tags to leave only the inner content
        $bodyContent = preg_replace('/^<body[^>]*>|<\/body>$/', '', $bodyContent);


        // Return footnotes as HTML with line breaks
        return [
            'cleaned_content' => trim($bodyContent),
            'footnotes' => implode('<br>', $output),
        ];

    }
}