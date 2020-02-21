<?php
    system('clear');
    define('ROOT_DIR', preg_replace('{/.utlab}i', '', __DIR__));
    require_once ROOT_DIR . '/local/modules/utlab/.functions.php';
    bxProlog(ROOT_DIR);

    error_reporting(E_ERROR);

    $arOptions = [
        'DOMAIN'    => 'https://www.kaleja.ru',
        'SITE_ROOT' => ROOT_DIR,
        'IB_LIST'   => [
            2, // Каталог;
            5, // Статьи;
            19, // Стили;
        ]
    ];

    try {
        $map = new Utlab\Sitemap($arOptions);
        $map->generateXML();        
    } catch (Exception $e) {
        printf("%s\n\n%s: %s\n\n", $e->getMessage(), $e->getFile(), $e->getLine());
    }

    printf ("\n%s: Done... \n\n", preg_replace('{' . ROOT_DIR . '}', '', __FILE__));
    //printf ("\n%s: Done... \n\n", basename(__FILE__));
    ?>   

   
    

    