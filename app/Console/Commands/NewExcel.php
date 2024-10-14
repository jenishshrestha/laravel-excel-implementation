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

        //         $testContent = '<p>تستند هذه البيانات الاستشرافية إلى التوقعات الحالية للإدارة، وبالتالي لا تمثل وعودًا ولا ضمانات، ولكنها تنطوي على مخاطر وشكوك وعوامل مهمة أخرى معروفة وغير معروفة قد تؤدي إلى اختلاف نتائجنا الفعلية أو أدائنا أو إنجازاتنا عن أي نتائج أو أداء أو إنجازات مستقبلية تم الإفصاح عنها صراحة أو ضمنيًا من خلال البيانات الاستشرافية، بما في ذلك، على سبيل المثال لا الحصر، ما يلي: تأثير جائحة فيروس كورونا المستجد على عملياتنا، بما في ذلك دراساتنا قبل السريرية والتجارب السريرية، واستمرارية أعمالنا؛ وغيرها من العبارات التي تشير إلى تكبدنا خسائر كبيرة، ولم نحقق أرباحًا حاليًا وقد لا نحقق أرباحًا أبدًا؛ وحاجتنا إلى تمويل إضافي؛ وتاريخنا التشغيلي المحدود؛ ونهجنا غير المثبت للتدخل العلاجي؛ والإجراءات المطولة والمكلفة وغير المؤكدة لتطوير الأدوية السريرية، بما في ذلك التأخير المحتمل في الموافقات التنظيمية؛ واعتمادنا على الأطراف الثالثة والمتعاونين لتوسيع مكتبتنا الميكروبية، وإجراء تجاربنا السريرية، وتصنيع المنتجات المرشحة لدينا، وتطوير المنتجات المرشحة وتسويقها تجاريًا، والموافقة عليها؛ وضعف خبرتنا في التصنيع والبيع والتسويق وتوزيع المنتجات المرشحة لدينا؛ الفشل في التنافس مع شركات الأدوية الأخرى؛ وحماية التكنولوجيا الخاصة بنا وسرية أسرارنا التجارية؛ والدعاوى القضائية المحتملة، أو الدعاوى المتعلقة بانتهاك الملكية الفكرية لطرف ثالث أو الطعون المقدمة ضد ملكية ملكيتنا الفكرية؛ والقرارات الصادرة بشأن بطلان أو عدم قابلية تنفيذ براءات الاختراع الخاصة بنا؛ والمخاطر المرتبطة بالعمليات الدولية؛ وقدرتنا على الاحتفاظ بالموظفين الرئيسيين وإدارة نمونا؛ والتقلبات المحتملة في أسعار أسهمنا؛ وتمتع إدارتنا ومساهمونا الرئيسيون بالقدرة على التحكم في أعمالنا أو التأثير عليها بشكل كبير؛ وتكاليف وموارد العمل كشركة عامة؛ والأبحاث أو التقارير غير المواتية؛ والدعاوى الجماعية المقامة ضدنا بشأن الأوراق المالية.</p>
// <p>هذه العوامل وغيرها من العوامل المهمة التي تمت مناقشتها تحت مسمى "عوامل المخاطرة" في تقريرنا ربع السنوي في النموذج 10 للربع المنتهي في 30 سبتمبر 2020، وتقاريرنا الأخرى المقدمة إلى لجنة الأوراق المالية والبورصات، قد تتسبب في اختلاف النتائج الفعلية ماديًا عن تلك المشار إليها في البيانات الاستشرافية الواردة في هذا البيان الصحفي. تمثل أي بيانات استشرافية من هذا القبيل تقديرات من قبل الإدارة اعتبارًا من تاريخ هذا البيان الصحفي. وقد نقوم بتحديث هذه البيانات الاستشرافية في وقت ما في المستقبل، وباستثناء ما يقتضيه القانون، فإننا نخلي مسؤوليتنا عن أي التزام للقيام بذلك، حتى إذا تسببت الأحداث اللاحقة في تغيير وجهات نظرنا. لا ينبغي الاعتماد على هذه البيانات الاستشرافية باعتبارها تمثل وجهات نظرنا في أي تاريخ لاحق لتاريخ هذا البيان الصحفي.</p>
// <p><a href="#_ftnref1" name="_ftn1">[1]</a> A. Leke and L. Signé, “Spotlighting opportunities for business in Africa and strategies to succeed in the world’s next big growth market,” Brookings, Feb. 11, 2019. <a href="https://www.brookings.edu/research/spotlighting-opportunities-for-business-in-africa-and-strategies-to-succeed-in-the-worlds-next-big-growth-market/">https://www.brookings.edu/research/spotlighting-opportunities-for-business-in-africa-and-strategies-to-succeed-in-the-worlds-next-big-growth-market/</a>.</p>
// <p><a href="#_ftnref2" name="_ftn2">[2]</a> UHC in Africa: A Framework for Action,” World Bank and World Health Organization. <a href="https://www.who.int/health_financing/documents/uhc-in-africa-a-framework-for-action.pdf">https://www.who.int/health_financing/documents/uhc-in-africa-a-framework-for-action.pdf</a>.</p>
// <p><a href="#_ftnref3" name="_ftn3">[3]</a> “Population Total – MENA,” The World Bank, <a href="https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false">https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false</a>.</p>
// <p><a href="#_ftnref4" name="_ftn4">[4]</a> “Policy Brief: The Impact of COVID-19 on the Arab Region, An Opportunity to Build Back Better,” United Nations, July 2020. <a href="https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false">https://data.worldbank.org/indicator/SP.POP.TOTL?locations=ZQ&amp;name_desc=false</a>.</p>';

        $parentHtml = $this->extractFootnotes($content);

        // dd($parentHtml);

        // if ($parentHtml) {

        //     dd($parentHtml);
        // }

        return $parentHtml;

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