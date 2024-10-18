<?php

namespace App\Console\Commands;

use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class spotlight extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:spotlight';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Load and format business sector data
        $oldBusinessSectorExcel = $this->formatExcelData('app/public/business-sector/business-sector.csv');

        // Load and format news data
        $oldNewsExcel = $this->formatExcelData('app/public/spotlight/spotlight.csv');

        $indexedSectors = $this->preIndexSectors($oldBusinessSectorExcel);

        $finalData = $oldNewsExcel->map(function ($item) use ($indexedSectors) {

            /**
             * ==============================================================
             * Task 1: Create Custom Publish Status column to map with acf field
             * ==============================================================
             */
            $status = 'Status';
            $new_publish_column = 'custom_publish_status';

            if (isset($item[$status])) {
                if (strtolower($item[$status]) === 'draft') {
                    $item[$new_publish_column] = 'private';
                } else {
                    $item[$new_publish_column] = $item[$status];
                }
            } else {
                $item[$new_publish_column] = '';
            }

            /**
             * ==============================================================
             * Task 2: Business sector mapping
             * ==============================================================
             */
            // if (! empty($item['news_business_sector'])) {
            $item = $this->mapBusinessSector($item, $indexedSectors);
            // }


            /**
             * ==============================================================
             * Task 3: Footnote extraction
             * ==============================================================
             */
            // if (! empty($item['Content'])) {
            $modifiedContent = $this->extractFootnotes($item['Content']);
            $item['Content'] = $modifiedContent['cleaned_content'];
            $item['footnotes'] = $modifiedContent['footnotes'];

            // Replace old domain URL with new domain URL in 'Content'
            $item['Content'] = str_replace('https://alj.com/', 'https://media.alj.com/', $item['Content']);
            // }


            /**
             * ==============================================================
             * Task 4: Timestamp conversion
             * ==============================================================
             */
            if (! empty($item['wpcf-perspective-published-date'])) {
                $item['wpcf-perspective-published-date'] = $this->convertTimestampFormat($item['wpcf-perspective-published-date']);
            }


            /**
             * ==============================================================
             * Task 5: Clean HTML from news_summary and convert it to plain text
             * ==============================================================
             */
            if (! empty($item['perspective_summury'])) {
                // Use strip_tags to remove any HTML tags from the summary
                $item['perspective_summury'] = strip_tags($item['perspective_summury']);
            }

            /**
             * ==============================================================
             * Task 6: Handle Push notification column
             * ==============================================================
             */
            if (! empty($item['Push notification'])) {
                $item['Push notification'] = strtolower($item['Push notification']) === 'yes' ? '1' : '';
            }

            /**
             * ==============================================================
             * Task 7: Handle Publish on mobile column
             * ==============================================================
             */
            if (! empty($item['Publish on mobile ?'])) {
                $item['Publish on mobile ?'] = strtolower($item['Publish on mobile ?']) === 'yes' ? '1' : '';
            }

            return $item;
        });

        // dd($finalData);

        // Convert data to array with headers
        $rows = $this->prepareForExcel($finalData);

        // Define a temporary file path
        $tempFilePath = 'public/temp_build.csv';
        $finalFilePath = 'public/build-spotlight.csv';

        // Store the CSV using Maatwebsite Excel to a temporary file
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
        }, $tempFilePath);

        // Add BOM for UTF-8 compatibility with Excel
        $csvContent = Storage::get($tempFilePath);
        $csvContentWithBom = "\xEF\xBB\xBF" . $csvContent;

        // Delete the old file if it exists
        if (Storage::exists($finalFilePath)) {
            Storage::delete($finalFilePath);
        }

        // Store the new CSV with BOM in the final location
        Storage::put($finalFilePath, $csvContentWithBom);

        // Optionally delete the temporary file
        Storage::delete($tempFilePath);

        print_r('New file has been generated with BOM - ' . $finalFilePath);
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
     * Pre-index business sectors for faster lookup.
     * 
     * @param \Illuminate\Support\Collection $oldBusinessSectorExcel
     * @return array
     */
    private function preIndexSectors($oldBusinessSectorExcel)
    {
        $indexedSectors = [];
        $businessSectorKeys = ['EN', 'AR', 'CHN', 'FR', 'JP', 'ESP', 'TRK'];

        foreach ($oldBusinessSectorExcel as $sector) {
            foreach ($businessSectorKeys as $key) {
                $trimmedValue = trim($sector[$key]);
                if (! empty($trimmedValue)) {
                    $indexedSectors[$trimmedValue] = $sector[$key . '_new'];
                }
            }
        }

        return $indexedSectors;
    }

    /**
     * Map business sector for a single item.
     * 
     * @param array $item
     * @param array $indexedSectors
     * @return array
     */
    private function mapBusinessSector($item, $indexedSectors)
    {
        $newIDs = [];
        $sectors = explode('|', $item['perspective_business_sector']);

        foreach ($sectors as $sectorValue) {
            $trimmedSectorValue = trim($sectorValue);
            if (isset($indexedSectors[$trimmedSectorValue])) {
                $newIDs[] = $indexedSectors[$trimmedSectorValue];
            }
        }

        $item['updated_business_sector_id'] = implode('|', $newIDs);

        return $item;
    }

    /**
     * Prepare the final data for Excel export by adding headers.
     * @param mixed $collection
     * @return array
     */
    private function prepareForExcel($collection)
    {
        $headers = array_keys($collection->first() ?? []);
        $rows = $collection->map(function ($item) : array {
            return array_values($item);
        })->toArray();

        // Prepend headers to rows
        array_unshift($rows, $headers);
        return $rows;
    }

    /**
     * Convert timestamp format to desired format using DateTime.
     * 
     * @param string $timestamp
     * @return string
     */
    private function convertTimestampFormat($timestamp)
    {
        // Parse the Unix timestamp using Carbon
        $date = \Carbon\Carbon::createFromTimestamp($timestamp);

        // Convert to the desired format: 'Y-m-d' for ACF fields
        return $date->format('Y-m-d');
    }

    /**
     * Extraction of footnotes from WYSIWIG Content
     * 
     * @param mixed $htmlContent
     * @return string[]
     */
    private function extractFootnotes($htmlContent)
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
                if ($parent->parentNode) {
                    $parent->parentNode->removeChild($parent);
                }
            } else {
                // If not a <p> tag, find the closest parent that is a <p>
                $pParent = $parent;
                while ($pParent && $pParent->nodeName !== 'p') {
                    $pParent = $pParent->parentNode;
                }
                // If found, add the HTML of the <p> tag to output
                if ($pParent) {
                    $output[] = $dom->saveHTML($pParent);

                    // Remove the parent from the DOM if it exists
                    if ($pParent->parentNode) {
                        $pParent->parentNode->removeChild($pParent);
                    }
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
            'footnotes' => implode('', $output),
        ];
    }
}