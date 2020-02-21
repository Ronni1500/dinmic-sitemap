<?
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
header("Content-Type: text/xml");
use Bitrix\Main\Application;
use Bitrix\Main\Web\HttpClient;

CModule::IncludeModule('iblock');

 class Sitemap {
    const DIRECTIVE_DISALLOW = 'disallow';
    const DIRECTIVE_HOST = 'host';

    private $arParam;
    private $arRobots;
    private $dbc;
    private $sqlHelper;
    private $arLink;
    private $host;

    var $xmlBegin = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">';
    var $xmlEnd = '</urlset>';

    var $xmlIndexBegin = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    var $xmlIndexEnd = '</sitemapindex>';

    var $day1 = '';
    var $day3 = '';


    public function __construct($arParams = []) {
        $this->arParam = $arParams;

        if (!$this->arParam['DOMAIN']) {
            throw new \RuntimeException('Error: DOMAIN option not defined.');
        }
        if (!$this->arParam['IB_LIST']) {
            throw new \RuntimeException('Error: IB_LIST option not defined.');
        }
        $this->day1 = date("Y-m-d", strtotime('-1 days'));
        $this->day3 = date("Y-m-d", strtotime('-3 days'));

        $this->dbc = Application::getConnection();
        $this->sqlHelper = $this->dbc->getSqlHelper();

        if ($this->checkTable()) {
            $this->setRobots();
        }
        $this->fillAddreses();
        $this->staticPages();
        $this->getMenus();
        
        $this->host = $this->arParam['DOMAIN'];              
    }

    public function generateXML(){
        // $fileName = 'sitemap.xml';
        // $filePath = $_SERVER['DOCUMENT_ROOT'].'/'.$fileName;
        // file_put_contents(
        //     $filePath,
        //     ''
        // );
        
        $xml = $this->xmlBegin;
        $xml .= '<url>
                <loc>'.$this->host.'</loc>
                <lastmod>'.$this->day1.'</lastmod>
                <changefreq>daily</changefreq>
                <priority>1</priority>
            </url>';
        foreach($this->arLink as $link){
            $xml .= $this->str2one($link['LINK']);
        }            
        $xml .= $this->xmlEnd;
        return $xml;
        // file_put_contents(
        //     $filePath,
        //     str_replace(array('	', "\n"), '', $xml)
        // );
    }

    private function str2one($url) {
        if(!$this->checkRobots($url)) return;
        if(!$this->pagesExcept($url)) return;
        return '<url><loc>'.$this->host.$url.'</loc><lastmod>'.$this->day3.'</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>';
    }

    private function fillAddreses() {             
        foreach($this->arParam['IB_LIST'] as $ib){            
           $this->getListUrlIB($ib);
        }                        
    }
    private function staticPages(){
        foreach($this->arParam['STATIC_PAGES'] as $page){
            $this->arLink[] = [
                'LINK' => $page,
            ];
        }
    }
    //Проверка страниц исключения
    private function pagesExcept($link){
        if(in_array($link, $this->arParam['PAGES_EXCEPT'])) return false;
        else return true;
    }

    private function checkTable() {
        /** ToDo: */
        
        return true;
    }
    
    /*Работы с инфоблоками*/
    private function getListUrlIB($ib){
        $arLink = array();            
        /*Получаем разделы*/
        $dbSection = CIBlockSection::GetList(
            Array(
                'LEFT_MARGIN' => 'ASC',
            ),
            array_merge( 
                Array(
                    'IBLOCK_ID' => $ib,
                    'ACTIVE' => 'Y',
                    'GLOBAL_ACTIVE' => 'Y'
                )                    
            ),
            false,
            array_merge(
                Array(
                    'ID',
                    'IBLOCK_SECTION_ID',
                    'SECTION_PAGE_URL',
                )                   
            )
         );             
         while( $arSection = $dbSection->GetNext(true, false) ){                        
            // Проверка disallow 
            if(!$this->checkRobots($arSection['SECTION_PAGE_URL'])) continue;
            $this->arLink[] = [
                'LINK' => $arSection['SECTION_PAGE_URL']
            ];
        }            
        $dbElements = CIBlockElement::GetList(
            array('SORT' => 'ASC'),
            array('IBLOCK_ID' => $ib, 'ACTIVE' => 'Y'),
            false,
            false,
            array('ID', 'DETAIL_PAGE_URL')
        );            
        while ($arElement = $dbElements->GetNext()) {            
            //Проверка disallow 
            if(!$this->checkRobots($arElement['DETAIL_PAGE_URL'])) continue;
            $this->arLink[] = [
                'LINK' => $arElement['DETAIL_PAGE_URL']
            ];
        }           
    }
    //Проверка robots Disallow
    private function checkRobots($url){
        $disallow = false;
        foreach($this->arRobots[self::DIRECTIVE_DISALLOW] as $value){
            if(preg_match('~'.$value.'~', $link)){
                $disallow = true;
                continue;
            }
        }
        return $disallow;
    }

    /*Редиректы из seomod*/
    private function checkRedirect($uri){ 
        $dbRc = $this->dbc->query("SELECT * FROM ut_migrate_redirects WHERE OLD = '".$uri."' ");
        if ($dbRc->getSelectedRowsCount()) { 
            $arFields = $dbRc->Fetch();  
                return $arFields['NEW'];             
        }
        else{
            return $uri;
        }
    }
    /**
     * robots.txt, Disallow 
     */
    private function setRobots() {
        $httpClient = new HttpClient();
        $content = $httpClient->get($this->arParam['DOMAIN'] . '/robots.txt');
        $rows = explode(PHP_EOL, $content);

        foreach ($rows as $row) {
            $row = preg_replace('{#.*}', '', $row);
            $parts = explode(':', $row, 2);
            if (count($parts) < 2) {
                continue;
            }

            $directive = strtolower(trim($parts[0]));
            $value = trim($parts[1]);

            switch ($directive) {
                case self::DIRECTIVE_HOST:
                    $this->arRobots[self::DIRECTIVE_HOST] = $value;
                    break;
                case self::DIRECTIVE_DISALLOW:
                    $this->arRobots[self::DIRECTIVE_DISALLOW][] = trim($value);
                    break;
            }
        }
    }

    /*Меню*/
    private function getMenus(){
        global $APPLICATION;
        $result = array();
        foreach ($this->arParam['MENU_TYPES'] as $type) {
            foreach ($APPLICATION->GetMenu($type)->arMenu as $menuItem) {
                $this->arLink[] = [
                    'LINK' => $menuItem[1]
                ];
            }
        }
    }
}

$arOptions = [
    'DOMAIN'    => 'https://panomero.com',
    'SITE_ROOT' => ROOT_DIR,    
    'IB_LIST'   => [
        2,
        3,
    ],
    'STATIC_PAGES' => [
        '/business-partnership/',
        '/reviews/',
        '/discount-cards/',
        '/blog/'
    ],
    'MENU_TYPES'=>[
        'top'
    ],
    'PAGES_EXCEPT'=>[
        '/uslugi/'
    ]
];

try {
    $map = new Sitemap($arOptions);
    echo $map->generateXML();
} catch (Exception $e) {
    printf("%s\n\n%s: %s\n\n", $e->getMessage(), $e->getFile(), $e->getLine());
}