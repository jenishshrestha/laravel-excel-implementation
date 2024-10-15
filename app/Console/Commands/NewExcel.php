<?php

namespace App\Console\Commands;

use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
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

        $finalData = $oldNewsExcel->map(function ($item) use ($oldBusinessSectorExcel) {
            // Step 1: Business sector mapping
            $indexedSectors = $this->preIndexSectors($oldBusinessSectorExcel);
            $item = $this->mapBusinessSector($item, $indexedSectors);

            // Step 2: Footnote extraction
            $modifiedContent = $this->extractFootnotes($item['Content'] ?? '');
            $item['Content'] = $modifiedContent['cleaned_content'];
            $item['footnotes'] = $modifiedContent['footnotes'];

            // Step 3: Timestamp conversion
            if (! empty($item['wpcf-news-publish_date'])) {
                $item['wpcf-news-publish_date'] = $this->convertTimestampFormat($item['wpcf-news-publish_date']);
            }

            // Step 4: Clean HTML from news_summary and convert it to plain text
            if (! empty($item['news_summary'])) {
                // Use strip_tags to remove any HTML tags from the summary
                $item['news_summary'] = strip_tags($item['news_summary']);
            }

            return $item;
        });

        // Convert data to array with headers
        $rows = $this->prepareForExcel($finalData);

        // Define the file path
        $filePath = 'public/build.csv';

        // Delete the file if it already exists
        if (Storage::exists($filePath)) {
            Storage::delete($filePath);
        }

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
        }, $filePath);

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
        $sectors = explode('|', $item['news_business_sector']);

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
            'footnotes' => implode('', $output),
        ];
    }
}