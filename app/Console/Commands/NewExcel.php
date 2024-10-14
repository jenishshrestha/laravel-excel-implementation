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
        $oldBusinessSectorExcel = $this->formatExcelData('app/public/Business-Sector-2.0.csv');

        // Load and format news data
        $oldNewsExcel = $this->formatExcelData('app/public/Press-Release-2.0.csv');

        // Step 1: Adding new column news_business_sector_title after matching ID and news_business_sector
        $finalData = $this->businessSectorMapping($oldNewsExcel, $oldBusinessSectorExcel);

        // Step 2: Extract footnotes from the content and add to new column called footnotes
        $finalData = $finalData->map(function ($item) {
            $modifiedContent = $this->extractFootnotesAndCleanContent($item['Content'] ?? '');


            // $item['Content'] = $modifiedContent['cleaned_content'];
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
        }, 'public/generatedNews.csv');

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
        $modifiedData = $oldNewsExcel->map(function ($item) use ($oldBusinessSectorExcel) {
            $businessSectorKeys = ['EN', 'AR', 'CHN', 'FR', 'JP', 'ESP', 'TRK'];
            $businessSectorNewValue = [
                'Corporate' => 'Corporate',
                'Health' => 'Health',
                'Transportation' => 'Mobility',
                'Passenger Vehicle' => 'Mobility',
                'Commercial Vehicles and Equipment' => 'Mobility',
                'Logistics' => 'Mobility',
                'Expanded Vehicle Services' => 'Mobility',
                'Engineering and Manufacturing' => 'Mobility',
                'Financial Services' => 'Financial Services',
                'Land and Real Estate' => 'Diversified',
                'Energy and Environmental Services' => 'Energy and Environmental Services',
                'Solar Power Solutions' => 'Energy and Environmental Services',
                'Wind Power Solutions' => 'Energy and Environmental Services',
                'Water and Environmental Solutions' => 'Diversified',
                'Consumer Products' => 'Diversified',
                'Advertising and Media' => 'Diversified',
            ];
            $sector = null;

            foreach ($businessSectorKeys as $key) {
                // Check if the trimmed news_business_sector exists in the current key
                $sector = $oldBusinessSectorExcel->firstWhere($key, trim($item['news_business_sector']));

                // If a match is found, break the loop
                if ($sector) {
                    break; // Exit the loop if a match is found
                }
            }

            // If a sector is found, check and replace its 'Business Sector' value
            if ($sector) {
                $businessSector = $sector['Business Sector'] ?? '';

                // Check if the business sector is in the $businessSectorNewValue array
                if (isset($businessSectorNewValue[$businessSector])) {
                    // Replace with new value if found in $businessSectorNewValue
                    $item['news_business_sector_title'] = $businessSectorNewValue[$businessSector];
                } else {
                    // If not found, use the original value
                    $item['news_business_sector_title'] = $businessSector;
                }
            } else {
                // If no matching sector is found, default to empty string
                $item['news_business_sector_title'] = '';
            }

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
     * Extract footnotes from the content.
     * @param mixed $content
     * @return []
     */
    private function extractFootnotesAndCleanContent($content)
    {
        // Regular expression to match <a> tags with href="#_ftnref" and the following <a> with the URL
        // $pattern = '/<a href="#_ftnref\d+"[^>]*>.*?<\/a>\s*<a href="[^"]+">[^<]+<\/a>/';
        // $pattern = '/<a href="#_ftnref\d+"[^>]*>(.*?)<\/a>\s*(.*?)\s*(?=<a href="https?:\/\/[^"]+"[^>]*>.*?<\/a>|$)/is';

        // Extract the footnotes
        // preg_match_all($pattern, $content, $matches);
        // preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);


        // Remove the footnotes from the content
        // $cleanedContent = preg_replace($pattern, '', $content);

        // Concatenate the matched footnote HTML into a single string
        // $footnotes = implode(' ', $matches[0]);

        // $footnotes = '';
        // foreach ($matches as $match) {
        //     // Combine the anchor tag with the text content after it
        //     $footnotes .= $match[0] . "\n";
        // }

        // if ($footnotes) {
        //     dd($footnotes);
        // }

        // Return both the cleaned content and the footnotes
        // return [
        //     'cleaned_content' => trim($cleanedContent),
        //     'footnotes' => $footnotes
        // ];


        //============================== 
        //new experimental code
        //==============================

        //         $testContent = '<p><span style="font-size: 12px;"><a href="#_ftnref1" name="_ftn1">[1]</a> A. Leke and L. Signé, “Spotlighting opportunities for business in Africa and strategies to succeed in the world’s next big growth market,” Brookings, Feb. 11, 2019, </span><span style="font-size: 12px;"><a href="https://www.brookings.edu/research/spotlighting-opportunities-for-business-in-africa-and-strategies-to-succeed-in-the-worlds-next-big-growth-market/.%20%20">https://www.brookings.edu/research/spotlighting-opportunities-for-business-in-africa-and-strategies-to-succeed-in-the-worlds-next-big-growth-market/. </a> </span><span style="font-size: 12px;">UHC in Africa: A Framework for Action,” World Bank and World Health Organization. <a href="https://www.worldbank.org/en/topic/universalhealthcoverage/publication/universal-health-coverage-in-africa-a-framework-for-action">https://www.worldbank.org/en/topic/universalhealthcoverage/publication/universal-health-coverage-in-africa-a-framework-for-action</a></span></p>
// <p><span style="font-size: 12px;"><a href="#_ftnref2" name="_ftn2">[2]</a> “Population Total – MENA,” The World Bank, <a href="https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false">https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false</a>.  </span></p>
// <p><span style="font-size: 12px;"><a href="#_ftnref3" name="_ftn3">[3]</a> “Policy Brief: The Impact of COVID-19 on the Arab Region, An Opportunity to Build Back Better,” United Nations, July 2020. <a href="https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false">https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false</a>.  </span></p>
// <p>&nbsp;</p>';

        $parentHtml = $this->extractFootnotes($content);

        // if ($parentHtml) {

        //     dd($parentHtml);
        // }

        return [
            'cleaned_content' => '',
            'footnotes' => $parentHtml,
        ];

    }


    function extractFootnotes($htmlContent)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($htmlContent, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Find all <p> elements that contain links with href starting with #_ftnref
        // $footnoteParagraphs = $xpath->query("//p[a[starts-with(@href, '#_ftnref')]]");
        // $footnoteParagraphs = $xpath->query("//p[a[starts-with(@href, '#_ftnref')]]");
        // $footnoteParagraphs = $xpath->query("//p[.//a[starts-with(@href, '#_ftnref')]]");

        // Find any element containing <a> with href starting with #_ftnref
        $footnoteElements = $xpath->query("//*[a[starts-with(@href, '#_ftnref')]]");
        $footnotes = [];

        foreach ($footnoteElements as $paragraph) {
            $footnotes[] = $dom->saveHTML($paragraph); // Save the entire <p> element as HTML
        }

        return implode('<br>', $footnotes); // Return concatenated footnote HTML
    }
}