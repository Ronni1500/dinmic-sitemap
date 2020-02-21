<?php

    namespace Utlab;

    use Bitrix\Main\Application;
    use Bitrix\Main\Web\HttpClient;
    

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
            $appInstance = null;
            try {
                $appInstance = Application::getInstance();
            } catch (\Exception $e) {
                echo $e->getMessage();
            }     
            $context = $appInstance->getContext();
            $request = $context->getRequest();
            $this->host = ($request->isHttps() ? 'https' : 'http' ) .'://'.$context->getServer()->getHttpHost();              
        }

        public function generateXML(){
            $fileName = 'sitemap.xml';
            $filePath = $_SERVER['DOCUMENT_ROOT'].'/'.$fileName;
            file_put_contents(
                $filePath,
                ''
            );
            
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

            file_put_contents(
                $filePath,
                str_replace(array('	', "\n"), '', $xml)
            );
        }

        private function str2one($url) {
            return '<url><loc>'.$this->host.$url.'</loc><lastmod>'.$this->day3.'</lastmod><changefreq>weekly</changefreq><priority>0.8</priority></url>';
        }

        private function fillAddreses() {             
            foreach($this->arParam['IB_LIST'] as $ib){
                $this->getListUrlIB($ib);
            }                        
        }


        /**
         * �������� / �������� ������� �������;
         */
        private function checkTable() {
            /** ToDo: */
            
            return true;
        }
        
        /*�������� ������ ����� �� ��*/
        private function getListUrlIB($ib){
            $arLink = array();            
            /*������� � ����������*/
            $dbSection = \CIBlockSection::GetList(
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
                //��������� �������� �� �������� � ������� ����������
                $arSection['SECTION_PAGE_URL'] = $this->checkRedirect($arSection['SECTION_PAGE_URL']);                
                //���������� ������ ���� disallow � ������
                if(!$this->checkRobots($arSection['SECTION_PAGE_URL'])) continue;
                $this->arLink[] = [
                    'LINK' => $arSection['SECTION_PAGE_URL']
                ];
            }            
            $dbElements = \CIBlockElement::GetList(
                array('SORT' => 'ASC'),
                array('IBLOCK_ID' => $ib, 'ACTIVE' => 'Y'),
                false,
                false,
                array('ID', 'DETAIL_PAGE_URL')
            );            
            while ($arElement = $dbElements->GetNext()) {
                //��������� �������� �� �������� � ������� ����������
                $arElement['DETAIL_PAGE_URL'] = $this->checkRedirect($arElement['DETAIL_PAGE_URL']);
                //���������� ������ ���� disallow � ������
                if(!$this->checkRobots($arElement['DETAIL_PAGE_URL'])) continue;
                $this->arLink[] = [
                    'LINK' => $arElement['DETAIL_PAGE_URL']
                ];
            }           
        }

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

        /*�������� ������� ��������*/
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
         * ������ robots.txt, ���������� Disallow ���������;
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
    }