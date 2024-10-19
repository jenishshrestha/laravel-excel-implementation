<?php

namespace App\Console\Commands;

use DOMDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class People extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:people';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command to process and export people data with UTF-8 encoding support.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Load and format news data
        $oldNewsExcel = $this->formatExcelData('app/public/our-people/Our-People.csv');

        // Process the data, extract Position and Company from 'our_people_title' column
        $finalData = $oldNewsExcel->map(function ($item) {
            // if ($item['ID'] == '42877') {

            // Parse the 'our_people_title' column
            if (! empty($item['our_people_title'])) {
                [$position, $company] = $this->extractPositionAndCompany($item['our_people_title']);
                $item['Position'] = $position;
                $item['Company'] = $company;
            } else {
                $item['Position'] = null;
                $item['Company'] = null;
            }
            // }
            return $item;
        });

        // Convert data to array with headers
        $rows = $this->prepareForExcel($finalData);

        // Define a temporary file path
        $tempFilePath = 'public/temp_build.csv';
        $finalFilePath = 'public/build-people.csv';

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
        $csvContent = mb_convert_encoding(Storage::get($tempFilePath), 'UTF-8', 'auto');
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
     * Ensures the file is properly converted to UTF-8 if necessary.
     * @param mixed $filePath
     * @return \Illuminate\Support\Collection
     */
    private function formatExcelData($filePath)
    {
        // Read the file contents
        $fileContent = file_get_contents(storage_path($filePath));

        // Detect encoding and convert to UTF-8 if necessary
        if (! mb_detect_encoding($fileContent, 'UTF-8', true)) {
            $fileContent = mb_convert_encoding($fileContent, 'UTF-8', 'auto');
        }

        // Save the content into a temporary file
        $tempFilePath = storage_path('temp_utf8.csv');
        file_put_contents($tempFilePath, $fileContent);

        // Use the temp file to load the CSV data
        $data = collect(data_get(Excel::toArray([], $tempFilePath), '0', []));
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
        $rows = $collection->map(function ($item) : array {
            return array_values($item);
        })->toArray();

        // Prepend headers to rows
        array_unshift($rows, $headers);
        return $rows;
    }

    /**
     * Extract position and company from the HTML content in the our_people_title column.
     * 
     * Handles cases where there are:
     * - Two paragraphs: the first for position and the second for company.
     * - One paragraph with a line break: the first part as position and the second part as company.
     * 
     * @param string $html
     * @return array
     */
    private function extractPositionAndCompany($html)
    {
        // Ensure the input is correctly encoded as UTF-8
        $html = mb_convert_encoding($html, 'UTF-8', 'auto');

        // Create a new DOMDocument instance
        $dom = new DOMDocument();

        // Load HTML with UTF-8 encoding support
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        // Retrieve the <p> elements
        $paragraphs = $dom->getElementsByTagName('p');

        $position = null;
        $company = null;

        if ($paragraphs->length > 0) {
            // Handle case with a single <p> containing a line break (<br />)
            $firstParagraphContent = $paragraphs->item(0)->textContent;

            // Check if the paragraph has <br /> (line breaks)
            if ($paragraphs->item(0)->getElementsByTagName('br')->length > 0) {
                // If <br> exists, split the content by the line breaks
                $parts = explode("\n", trim($firstParagraphContent));

                // Assign position and company based on the split content
                $position = trim($parts[0] ?? '');
                $company = trim($parts[1] ?? '');
            } else {
                // If there's no <br>, treat it as a single position (case: multiple <p> tags)
                $position = trim($firstParagraphContent);

                // Check if there's a second paragraph for the company
                if ($paragraphs->length > 1) {
                    $company = trim($paragraphs->item(1)->textContent);
                }
            }
        }

        return [$position, $company];
    }
}