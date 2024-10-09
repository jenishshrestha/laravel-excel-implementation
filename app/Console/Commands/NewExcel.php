<?php

namespace App\Console\Commands;

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
        // Load and format old data
        $oldBusinessSectorExcel = $this->formatExcelData('app/public/old-business-sector.csv');

        // Load and format news data
        $oldNewsExcel = $this->formatExcelData('app/public/old-news.csv');

        // Step 1: Match the ID from the business sector with the news_business_sector
        // from the news, and create a new column called news_business_sector_title.
        $finalData = $oldNewsExcel->map(function ($item) use ($oldBusinessSectorExcel) {
            $sector = $oldBusinessSectorExcel->firstWhere('ID', trim($item['news_business_sector']));
            $item['news_business_sector_title'] = $sector['Title'] ?? '';
            return $item;
        });

        // Step 2: Extract footnotes from the content and add to new column called footnotes
        $finalData = $finalData->map(function ($item) {
            $item['footnotes'] = $this->extractFootnotesAndCleanContent($item['Content'] ?? '');
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
        }, 'public/finalNews.csv');
    }

    /**
     * Format Excel data from a file.
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
     * @return string
     */
    private function extractFootnotesAndCleanContent($content)
    {
        /// Regular expression to match <a> tags with href="#_ftnref" and the following <a> with the URL
        //preg_match_all('/<a href="#_ftnref\d+"[^>]*>.*?<\/a>\s*<a href="[^"]+">[^<]+<\/a>/', $content, $matches);

        // Concatenate the matched footnote HTML into a single string
        // dd(implode(' ', $matches[0]));

        //return implode(' ', $matches[0]); // Return all matched footnote HTML as one string

        // Regular expression to match <a> tags with href="#_ftnref" and the following <a> with the URL
        $pattern = '/<a href="#_ftnref\d+"[^>]*>.*?<\/a>\s*<a href="[^"]+">[^<]+<\/a>/';

        // Extract the footnotes
        preg_match_all($pattern, $content, $matches);

        // Remove the footnotes from the content
        $cleanedContent = preg_replace($pattern, '', $content);

        // Concatenate the matched footnote HTML into a single string
        $footnotes = implode(' ', $matches[0]);

        // Concatenate the matched footnote HTML into a single string
        // Return all matched footnote HTML as one string
        return $footnotes;

        // Return both the cleaned content and the footnotes
        // return [
        //     'cleaned_content' => trim($cleanedContent),
        //     'footnotes' => $footnotes
        // ];
    }
}