<?php
 require_once 'check_session.php';
/**
 * config.php
 *
 * Author: pixelcave
 *
 * Configuration php file. It contains variables used in the template and the main menu auto creation function
 *
 */
// Template variables
$template = array(
    'name'          => 'Creative Culture Center',
    'version'       => '1.1.9',
    'author'        => 'Creative Culture Center Method',
    'title'         => 'CCC | Creative Culture Center',
    'description'   => 'Tickle Right holistically works on extracting the unadulterated potential of young children. Our aim is to inculcate a lifelong love for learning in our tiny tots.',
    // 'fixed-top'         for a top fixed header
    // 'fixed-bottom'      for a bottom fixed header
    // ''                  empty for a static header
    'header'        => 'fixed-top',
    // 'sticky'            for a sticky sidebar
    'sidebar'       => 'sticky',
    // 'hide-side-content' for hiding sidebar by default
    'side_content'  => '',
    // 'full-width'        for full width page
    // ''                  empty to remove full width from the page in large resolutions
    'page'          => 'full-width',
    // Available themes: 'fire', 'wood', 'ocean', 'leaf', 'tulip', 'amethyst',
    //                   'dawn', 'city', 'oil', 'deepsea', 'stone', 'grass',
    //                   'army', 'autumn', 'night', 'diamond', 'cherry', 'sun'
    //                   'asphalt'
    'theme'         => 'pink',
    'active_page'   => basename($_SERVER['PHP_SELF'])
);

// Primary navigation array (the primary navigation will be created automatically based on this array, up to 3 level deep)
$primary_nav1=$_SESSION['config'];
// The Face Attendance Settings page is administrator-only (position 1, the same check the page enforces),
// so the menu entry is removed for everyone else.
if(!function_exists('face_settings_menu_filter')){
    function face_settings_menu_filter($items,$admin,$superAdmin=false){
        $kept=[];
        foreach((array)$items as $item){
            if(!is_array($item))continue;
            // ADR-029: Batch Finder is currently non-functional; hide from sidebar navigation
            $url=$item['url']??'';$name=$item['name']??'';$mid=(int)($item['module_id']??0);
            if($url==='#modal-batch_finder'||$name==='Batch Finder'||$mid===61)continue;
            // Parked: Product under settings dropdown unless requested
            if($url==='product.php'||$name==='Product'||$mid===41)continue;
            if(!$admin && $url==='face-attendance-settings.php')continue;
            // ADR-031: Role Templates is super-admin only
            if(!$superAdmin && $url==='role-templates.php')continue;
            if(!empty($item['sub']) && is_array($item['sub']))$item['sub']=face_settings_menu_filter($item['sub'],$admin,$superAdmin);
            $kept[]=$item;
        }
        return $kept;
    }
}
if(is_array($primary_nav1)){
    $faceSettingsAdmin=isset($_SESSION['position_id']) && (int)$_SESSION['position_id']===1;
    $isSuperAdmin=!empty($_SESSION['admin']) || in_array((int)($_SESSION['position_id'] ?? 0), [1, 11], true);
    $primary_nav1=face_settings_menu_filter($primary_nav1,$faceSettingsAdmin,$isSuperAdmin);
    // Keep sidebar navigation and serialized session synced
    $_SESSION['config']=$primary_nav1;
}

// $primary_nav =array(
//     array(
//         'name' => 'Dashboard',
//         'url'  => 'index.php',
//         'icon' => 'gi gi-dashboard',
//         'plus' =>''
//         ),
//     array(
//         'name' => 'Member',
//         'url'  => 'list.php?id=1',
//         'icon' => 'gi gi-vcard'
//         ),
//      array(
//         'name' => 'Invoice',
//         'url'  => 'list.php?id=2',
//         'icon' => 'gi gi-notes',
//         'plus' => 'invoice.php' 
//         ),
//       array(
//         'name' => 'Inquiry',
//         'url'  => 'list.php?id=3',
//         'icon' => 'gi gi-heart',
//         'plus' => 'inquiry.php' 
//         ),
//        array(
//         'name' => 'Expense',
//         'url'  => 'list.php?id=4',
//         'icon' => 'gi gi-credit_card',
//         'plus' => '#modal-expense' 
//         ),
//        array(
//         'name' => 'SMS',
//         'url' => 'list.php?id=5',
//         'icon' => 'gi gi-chat',
//         'plus' => '#modal-sms' 
//         ),
//        array(
//         'name' => 'Reports',
//         'url' => 'list.php?id=6',
//         'icon' => 'gi gi-charts',
//         'plus' => 'report.php' 
//         ),
//        array(
//         'name' => 'Settings',
//         'icon' => 'gi gi-cogwheels',
//         'sub' => array(
//             array(
//                 'name' => 'Employee',
//                 'url' => 'list.php?id=7',
//                 'icon' => 'gi gi-old_man',
//                 'plus' => 'employee.php'
//                 ),
//             array(
//                 'name' => 'Batch',
//                 'url' => 'batch.php',
//                 'icon' => 'gi gi-old_man',
//                 'plus' => 'employee.php'
//                 ),
//             array(
//                 'name' => 'Package',
//                 'url' => 'service.php',
//                 'icon' => 'gi gi-old_man',
//                 'plus' => 'employee.php'
//                 ),
//             array(
//                 'name' => 'Category',
//                 'url' => '#modal-category',
//                 ),
//             array(
//                 'name' => 'System Config',
//                 'url' => 'settings.php',
//                 )
//         )
//     )
// );

?>
