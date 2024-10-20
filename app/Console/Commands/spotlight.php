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
        $oldNewsExcel = $this->formatExcelData('app/public/spotlight/Spotlight.csv');

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
            $item['footnotes'] = '';

            if (! empty($item['Content'])) {

                // Replace old domain URL with new domain URL in 'Content' for img tags only
                $item['Content'] = $this->replaceImageSrcDomain($item['Content'], 'https://alj.com/', 'https://media.alj.com/');
                $item['Content'] = $this->replaceImageSrcDomain($item['Content'], 'https://www.alj.com/', 'https://media.alj.com/');

                // process data
                $modifiedContent = $this->extractFootnotes($item['Content']);
                $item['Content'] = $modifiedContent['cleaned_content'];
                $item['footnotes'] = $modifiedContent['footnotes'];

                // $item['Content'] = '<img class=""wp-image-53743 size-full"" src=""https://www.alj.com/app/uploads/2019/11/Global-surface-temperature-relative-to-1951-1980-average-temperatures.png"" alt=""This graph illustrates the change in global surface temperature relative to 1951-1980 average temperatures.  Eighteen of the 19 warmest years all have occurred since 2001, with the exception of 1998.  The year 2016 ranks as the warmest on record."" width=""590"" height=""300""><img class=""size-full wp-image-95668"" src=""https://alj.com/app/uploads/2022/02/James-Mnyupe.jpg"" alt="""" width=""228"" height=""228"">';

                // dd($item['Content']);
            }


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

            // Case 4: Fallback for when all of the footnotes share common parent
            // In this case parents gets already removed after first footnote is extracted so this fallback is necessary
            $footnotes = $xpath->query('//a[contains(@href, "#_ftnref")]');

            if ($footnotes->length === 0) {
                break;
            }


            $parent = $footnote->parentNode;

            // Case 1: If the footnote is inside a <p> tag, capture the whole <p> content
            if ($parent && $parent->nodeName === 'p') {
                $output[] = $dom->saveHTML($parent);
                if ($parent->parentNode) {
                    $parent->parentNode->removeChild($parent);
                }
            } else if ($parent && $parent->nodeName === 'body') {
                // Case 2: If the footnote is not wrapped inside <p> or another block-level element

                // Capture the footnote and its immediate sibling <a> link or text
                $siblingData = $this->getNextSiblingContent($footnote);

                $output[] = '<p>' . $dom->saveHTML($footnote) . ' ' . $siblingData['content'] . '</p>';

                // Remove the footnote and its associated text from the DOM
                if ($footnote->parentNode) {
                    $footnote->parentNode->removeChild($footnote);
                }

                // Remove the extracted sibling nodes from the DOM
                foreach ($siblingData['nodes'] as $nodeToRemove) {
                    if ($nodeToRemove instanceof \DOMNode && $nodeToRemove->parentNode) {
                        $nodeToRemove->parentNode->removeChild($nodeToRemove);  // Remove sibling node
                    }
                }
            } else {
                // Case 3: Other cases where the footnote is wrapped in something else (e.g., <span>)

                $blockTags = ['p', 'div'];
                while ($parent && ! in_array($parent->nodeName, $blockTags)) {
                    $parent = $parent->parentNode;
                }

                // If found a block-level parent, process it
                if ($parent) {
                    $output[] = $dom->saveHTML($parent);
                    if ($parent->parentNode) {
                        $parent->parentNode->removeChild($parent);
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

        $cleanedHtml = $this->cleanHtmlEnd($bodyContent);

        // Return footnotes as HTML with line breaks
        return [
            'cleaned_content' => trim($cleanedHtml),
            'footnotes' => implode('', $output),
        ];
    }

    private function getNextSiblingContent($node)
    {
        $next = $node->nextSibling;
        $content = '';
        $nodesToRemove = [];  // To store nodes to be removed

        // Loop over next siblings until we find a valid node (like <a> or text) or reach the end
        while ($next && ($next->nodeType === XML_TEXT_NODE || $next->nodeName === 'a')) {
            // Stop as soon as text (including spaces) is found
            if ($next->nodeType === XML_TEXT_NODE && trim($next->textContent) !== '') {
                // $content .= $next->textContent;
                $content .= htmlspecialchars($next->textContent, ENT_QUOTES, 'UTF-8') . ' ';
                $nodesToRemove[] = $next;
                break;
            } else if ($next->nodeType === XML_TEXT_NODE && trim($next->textContent) === '') {
                // If it's whitespace, append it but don't stop
                $content .= $next->textContent;
                $nodesToRemove[] = $next;

            } else if ($next->nodeName === 'a') {
                $content .= $next->ownerDocument->saveHTML($next);
                $nodesToRemove[] = $next;
                break;
            }

            $next = $next->nextSibling;
        }

        return [
            'content' => $content,
            'nodes' => $nodesToRemove
        ];
    }

    /**
     * Summary of cleanHtmlEnd
     * @param mixed $htmlContent
     * @return array|string|null
     */
    private function cleanHtmlEnd($htmlContent)
    {
        // Correct pattern with \x{A0} for non-breaking spaces and match trailing newlines, spaces, or stray characters
        $htmlContent = preg_replace('/(\s*[\x{A0}\n?]+)$/u', '', $htmlContent);  // Only clean the end of the content

        return $htmlContent;
    }


    /**
     * Replace old domain in img tags (both src and srcset) with a new domain.
     *
     * @param string $content The content containing HTML with img tags.
     * @param string $oldDomain The old domain URL to search for (e.g., 'https://alj.com/').
     * @param string $newDomain The new domain URL to replace with.
     * @return string Processed content with updated img tag URLs.
     */
    private function replaceImageSrcDomain($content, $oldDomain, $newDomain)
    {
        // Define the regular expression to find <img> tags with src attributes
        return preg_replace_callback(
            '/<img\s+[^>]*src=(["\']{1,2})(' . preg_quote($oldDomain, '/') . '[^"\']+)\1/i',
            function ($matches) use ($oldDomain, $newDomain) {
                // Replace the old domain with the new one in the src attribute
                return str_replace($matches[2], str_replace($oldDomain, $newDomain, $matches[2]), $matches[0]);
            },
            $content
        );
    }
}