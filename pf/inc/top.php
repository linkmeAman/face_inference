<!DOCTYPE html>
<!--[if IE 8]>         <html class="no-js lt-ie9"> <![endif]-->
<html class="no-js" ng-app="papafit"> 
    <head>
    <!-- AngularJS only injects this rule after angular.min.js has run. Declare it first so a slow,
         blocked or failed script can never leave raw {{ }} expressions or ng-if branches on screen. -->
    <style>[ng-cloak], [data-ng-cloak], [x-ng-cloak], .ng-cloak, .x-ng-cloak { display:none !important; }</style>
    <!-- CCC Telemetry & Error Tracking SDK -->
    <script src="js/telemetry.js?v=<?php echo $template['version']; ?>"></script>
    <script src="https://cdn.jsdelivr.net/momentjs/2.13.0/moment.min.js"></script>

        <script type="text/javascript"> 
            // Initializing global javascript variable from session array
            var session_id = '<?php echo session_id(); ?>';
            //console.log("Session_id = "+session_id);
            var bid="<?php echo $_SESSION['bid']; ?>";
            var json_header={"Content-type":"application/json"};
            var form_header={'Content-Type':'application/x-www-form-urlencoded'}; 
            var config_array = <?php echo json_encode(isset($primary_nav1) ? $primary_nav1 : $_SESSION['config']); ?>;
            var session=<?php echo json_encode($_SESSION); ?>;
            var viewDateFormat="DD-MM-YYYY";
            var check_month = true;
            if(session.position_id==10 || session.position_id == 6  || (session.position_id == 2 && (moment().format('DD') == '01'))){//for developer and EA-D
                check_month = false;
            }
            //.log(session)
            if (session.user_id != window.localStorage.getItem('user_id') || session.token != window.localStorage.getItem('token')) {
                window.localStorage.setItem('sentToServer','0');
                //console.log("Turning off from top");
            }
            window.localStorage.setItem('token', session.token);
            window.localStorage.setItem('user_id', session.user_id);
            window.localStorage.setItem('bid',bid);
            var $_GET = <?php echo json_encode($_GET); ?>;
        </script>
       
        <meta charset="utf-8">

        <title><?php echo $template['title'] ?></title>

        <meta name="description" content="<?php echo $template['description'] ?>">
        <meta name="author" content="<?php echo $template['author'] ?>">
        <meta name="robots" content="noindex, nofollow">
        
        <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1.0">

        <!-- Icons -->
        <!-- The following icons can be replaced with your own, they are used by desktop and mobile browsers -->
        <link rel="shortcut icon" href="img/logo_ccc1.png">
        <link rel="apple-touch-icon" href="img/icon57.png" sizes="57x57">
        <link rel="apple-touch-icon" href="img/icon72.png" sizes="72x72">
        <link rel="apple-touch-icon" href="img/icon76.png" sizes="76x76">
        <link rel="apple-touch-icon" href="img/icon114.png" sizes="114x114">
        <link rel="apple-touch-icon" href="img/icon120.png" sizes="120x120">
        <link rel="apple-touch-icon" href="img/icon144.png" sizes="144x144">
        <link rel="apple-touch-icon" href="img/icon152.png" sizes="152x152">
        <!-- END Icons -->

        <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">
        <!-- Stylesheets -->
        <!-- Bootstrap is included in its original form, unaltered -->
        <link rel="stylesheet" type="text/css" href="css/bootstrap.css">
        <link rel="stylesheet" type="text/css" href="css/bootstrap-utilities.css">
        <!-- <link  href="http://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/2.3.0/img/glyphicons-halflings.png"> -->
        <!-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.1/css/bootstrap.min.css"> -->

        <!-- Related styles of various icon packs and javascript plugins -->
        <link rel="stylesheet" type="text/css" href="css/plugins.css">
        <link rel="stylesheet" type="text/css" href="css/snackbar.css">
        <link rel="stylesheet" type="text/css" href="css/jquery-ui.css" />
        <!-- The main stylesheet of this template. All Bootstrap overwrites are defined in here -->
        <link rel="stylesheet" type="text/css" href="css/main.css?v=2">
        <!-- Load a specific file here from css/themes/ folder to alter the default theme of the template -->
        <?php if ($template['theme']) { ?>
        <link id="theme-link" rel="stylesheet" href="css/themes/<?php echo $template['theme']; ?>.css">
        <?php } ?>
        <link rel="stylesheet" href="css/packages.css">

        <!-- The themes stylesheet of this template (for using specific theme color in individual elements - must included last) -->
        <link rel="stylesheet" href="css/themes.css">
        <link rel="stylesheet" href="css/animation.css">
        <!-- END Stylesheets -->

        <!-- <link href="../tour/assets/css/bootstrap.min.css" rel="stylesheet"> -->
        <!--<link href="tour/assets/css/demo.css" rel="stylesheet">-->

        <!-- Add IntroJs styles -->
        <link href="content/tour/assets/css/introjs.css" rel="stylesheet">
        <!-- <link href="tour/assets/css/introjs-royal.css" rel="stylesheet"> -->
        <!-- <link href="tour/assets/css/bootstrap-responsive.min.css" rel="stylesheet"> -->
        <!-- Modernizr (browser feature detection library) & Respond.js (Enable responsive CSS code on browsers that don't support it, eg IE8) -->
        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
        <!-- <script src="https://code.jquery.com/ui/1.10.4/jquery-ui.js"></script> -->
        <script src="js/vendor/modernizr-2.7.1-respond-1.4.2.min.js"></script>     
        <!-- <script src="https://cdn.jsdelivr.net/momentjs/2.13.0/moment.min.js"></script> -->
        <script src=https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js></script>
        <script src="https://unpkg.com/html5-qrcode"></script>



        <!-- <script src="js/snowstorm-min.js"></script> -->
        <!-- <script type="text/javascript">
            snowStorm.snowColor = '#99ccff'; // blue-ish snow!?
            snowStorm.flakesMaxActive = 96;  // show more snow on screen at once
            snowStorm.useTwinkleEffect = true; // let the snow flicker in and out of view
        </script> -->

        <!-- Angular.js link -->
        <script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.5.8/angular.min.js"></script>
        <script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.2.0rc1/angular-sanitize.min.js"></script>
        <!-- Angular strap for UI -->
        <script src="js/angular-strap.min.js"></script>
        <script src="js/angular-strap.tpl.min.js"></script>
        <!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/chosen/1.1.0/chosen.jquery.min.js"></script> -->
        <!-- For including angular bootstrap UI -->
        <script src="js//ui-bootstrap-tpls.min.js"></script>
        <script type="text/javascript" src="js/ngInfiniteScroll.js"></script>
        <script src="js/angular-chosen.js"></script>
        <!-- Main controller -->
        <script src='build/pdfmake.min.js'></script>
        <script src='build/vfs_fonts.js'></script>
        <script src="js/controllers/api_url.js"></script>
        <script src="js/controllers/mainController.js?v=1.1.7"></script>
        <script type="text/javascript" src="js/jquery.floatingmessage.js"></script>
        <script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="js/smooth-scrollbar/dist/smooth-scrollbar.js"></script>
        <script src="js/smooth-scrollbar/dist/plugins/overscroll.js"></script>

        <style type="text/css">

            #list-wrapper-noti
            {
                min-height: 350px;
                max-height: 350px;
                
                overflow-y:scroll;
                overflow-x:hidden;
               
            }

            .force-overflow
            {
                min-height: 450px;
            }

            #wrapper
            {
                text-align: center;
                margin: auto;
            }

           .style-3::-webkit-scrollbar-track
            {
                border-radius: 6px;
                -webkit-box-shadow: inset 0 0 6px rgba(0,0,0,0.3);;
                background-color: #F5F5F5;
            }

            .style-3::-webkit-scrollbar
            {
                width: 5px;
                background-color: #F5F5F5;
            }

            .style-3::-webkit-scrollbar-thumb
            {
                border-radius: 10px;
                webkit-box-shadow: inset 0 0 6px rgba(0,0,0,.3);
                background-color: #837E7E;
            }

            .style-3 li:first-child {
                position: fixed;
                background-color: #F5F5F5;
                height: 30px;
                padding: 7px
            }

            a.fa-globe {
              position: relative;
              font-size: 1.5em;
              color: grey;
              cursor: pointer;
            }
            span.fa-comment {
              position: absolute;
              font-size: 0.6em;
              top: -3px;
              color: red;
              right: -3px;
            }
            span.num {
              position: absolute;
              font-size: 0.3em;
              top: 1px;
              color: #fff;
              right: 1px;
            }



<?php 
$xml = simplexml_load_file("http://www.geoplugin.net/xml.gp?ip=".$_SERVER['REMOTE_ADDR']);
if($xml->geoplugin_countryCode == 'IN' && ((date("m") == "08" && date("d") >= "12" && date("d") <= "17" || (date("m")=="1" && date("d")=="26")))) 
// if(date("Y-m-d") <= "2021-08-17") 
{?>
header.navbar.navbar-fixed-top {
    background-image: linear-gradient(0deg, #138808 33%, #ffffff 33%, #ffffff 66%, #FF9933 66% );
}
div#header-nav-section {
    background-color: #4440;
}
ul.navbar-nav-custom * {
    color: #000 !important;
}

.wave {
    position: absolute;
    height: 100%;
    width: 100%;
    background-image: linear-gradient(
        128deg, rgba(89, 72, 21, 0) 39%,
        rgba(250, 245, 245, 0.8474025974025974) 
        46%, rgba(89, 72, 21, 0) 53%);
    animation-name: wavy;
    animation-duration: 10s;
    animation-iteration-count: infinite;
    animation-timing-function: linear;
}
  
@keyframes wavy {
    0% {
        background-position: -400px 0px, 
        -400px 0px, -400px 0px, -400px 0px;
    }
    100% {
        background-position: 800px 0px, 
        800px 0px, 800px 0px, 800px 0px;
    }
}

<?php }?>
            






        </style>
</head>

    <!-- Body -->
    <!-- In the PHP version you can set the following options from the config file -->
    <!-- Add the class .hide-side-content to <body> to hide side content by default -->

    <?php

    $body_classes = '';

    if ($template['header'] == 'fixed-top') {
        $body_classes = 'header-fixed-top';
    } else if ($template['header'] == 'fixed-bottom') {
        $body_classes = 'header-fixed-bottom';
    }

    if ($template['side_content']) {
        $body_classes .= ' ' . $template['side_content'];
    }
    ?>

    <body <?php if ($body_classes) { echo ' class="' . $body_classes . '"'; } ?>>
       
        <div id="snackbar"></div>
        <!-- Page Container -->
        <!-- In the PHP version you can set the following options from the config file -->
        <!-- Add the class .full-width for a full width page -->
        <div id="page-container"<?php if ($template['page'] == 'full-width') { echo ' class="full-width"'; } ?> style="box-shadow: 10px 10px 5px grey;" style="position: relative">
            <!-- Header -->
            <!-- In the PHP version you can set the following options from the config file -->
            <!-- Add the class .navbar-fixed-top or .navbar-fixed-bottom for a fixed header on top or bottom respectively -->
            <!-- If you add the class .navbar-fixed-top remember to add the class .header-fixed-top to <body> element! -->
            <!-- If you add the class .navbar-fixed-bottom remember to add the class .header-fixed-bottom to <body> element! -->
            <!-- <header class="navbar navbar-inverse navbar-fixed-top"> -->
            <!-- <header class="navbar navbar-inverse navbar-fixed-bottom"> -->
            <!-- <header class="navbar navbar-inverse navbar-fixed-top" style="background-color: #ccbcab;position: fixed"> -->
                <!-- div#row -->
                
                <!-- END div#row -->
            <!-- </header> -->
            
            <header class="navbar navbar-inverse<?php if ($template['header'] == 'fixed-top') { echo ' navbar-fixed-top'; } else if ($template['header'] == 'fixed-bottom') { echo ' navbar-fixed-bottom'; } ?> " >                
                <!-- div#row -->
                <div class="row wave" ng-controller="topController" ng-cloak>
                    <!-- Sidebar Toggle Buttons (Desktop & Tablet) -->
                    <div class="col-sm-4 hidden-xs">
                        <ul class="navbar-nav-custom pull-left">
                            <!-- Desktop Button (Visible only on desktop resolutions) -->
                            <li class="visible-md visible-lg">
                                <a href="javascript:void(0)" id="toggle-side-content">
                                    <i class="fa fa-bars"></i>
                                </a>
                            </li>
                            <!-- END Desktop Button -->

                            <!-- Divider -->
                            <li class="divider-vertical"></li>
                        </ul>
                    </div>
                    <!-- END Sidebar Toggle Buttons -->

                    <!-- Brand and Search Section -->
                    <div class="col-sm-4 col-xs-12 text-center">
                        
                        <!-- Loading Indicator, Used for demostrating how loading of notifications could happen, check main.js - uiDemo() -->
                        <div id="loading" class="display-none"><i class="fa fa-spinner fa-spin"></i></div>

                        <!-- Top search -->
                        <div id="top-search" class="pull-left" ng-show="showSearch"><a href="search_results.php" style="text-decoration:none;color:#fff"><i class="fa fa-search" style="padding: 9px 5px 0 5px;"></i></a></div>

                        <!-- <span class="pull-left" ng-show="showSearch"><a href="search_results.php"><i class="fa fa-search"></i></a></span> -->
                        <!-- <form id="top-search" class="pull-left" ng-show="showSearch" action="search_results.php" method="get" data-step="4" data-intro="This is the place where you can search any data across application" data-position='right'>
                            <input type="text" id="search_term" class="form-control" name="search_term" placeholder="Search...">
                        </form> -->
                        <!-- END Top search -->

                        <!-- Logo -->
                        <!-- <a href="{{redirectUrl}}" class="navbar-brand">
                            <img src="img/template/logo.png" alt="logo">
                        </a> -->
                        <!-- END Logo -->

                    </div>
                    <!-- END Brand and Search Section -->

                    <!-- Header Nav Section -->
                    <div id="header-nav-section" class="col-sm-4 col-xs-12 clearfix" data-step="5" data-intro="You can look here for your profile and important notifications." data-position='left'>
                        <!-- Header Nav -->
                        <ul class="navbar-nav-custom pull-right header-profile">
                            <li class="dropdown dropdown-messages" ng-cloak ng-show="showBranch">
                                <a href="javascript:void(0)" class="dropdown-toggle" data-toggle="dropdown"><i class="gi gi-building"></i>&nbsp;&nbsp;{{branch}}</a>

                                <ul class="dropdown-menu " style="width:100px; height:150px; overflow-y: scroll;" ng-if="branches.length > 1">
                                    <!-- Page Options -->
                                    <li style="width:100px;" ng-repeat="branch in branches" ng-cloak ng-click="branchChange(branch.id)">
                                        <a href="" style="width:100px">{{branch.branch}}</a>
                                    </li>                    
                                </ul>
                            </li>

                            <!-- Divider -->
                            <li class="divider-vertical" ng-show="showBranch"></li>

                            <!-- Theme Options, functionality initialized at main.js - templateOptions() -->
                            <li class="dropdown dropdown-theme-options">
                                <a href="javascript:void(0)" class="dropdown-toggle" data-toggle="dropdown"><img src="../pf/img/logo_ccc1.png" class="img-circle" alt="" width="20" height="20"></i>&nbsp; <?php echo $_SESSION['user_name'] ?></a>

                                <ul class="dropdown-menu " style="width:145px">
                                    <!-- Page Options -->
                                    <li class="theme-extra">
                                        <a href="summary.php?contact=<?php echo $_SESSION['contact_id'] ?>" style="width:140px"> <i class="fa fa-user" aria-hidden="true"></i><label for="theme-page-full">&nbsp;&nbsp;My profile</label></a>
                                    </li>

                                    <!-- <li class="divider" style="width: 140px;"></li>
                                    <li class="theme-extra" ng-show="showTutorial">
                                        <a href="tutorial.php" style="width:140px"> <i class="gi gi-book" aria-hidden="true"></i><label for="theme-page-full">&nbsp;&nbsp;Tutorial</label></a>
                                    </li> -->

                                    <!-- END Page Options -->

                                    <li class="divider " style="width:140px"></li>
                                    <li class="theme-extra ">
                                        <a href="logout.php" ng-click="logMeOut()" style="width:140px"> <i class="fa fa-sign-out" aria-hidden="true"></i><label for="theme-page-full">&nbsp;&nbsp;Sign Out</label></a>
                                    </li>

                                   
                                </ul>
                            </li>
                            <!-- END Theme Options -->
                            <!-- Divider -->
                            <li class="divider-vertical"></li>

                            <!-- Messages -->
                            <li class="dropdown dropdown-messages" ng-show="showNotification">
                                <a style="padding-top: 15px;" href="javascript:void(0)" class="dropdown-toggle fa fa-bell fa-lg" ng-click = "hideCount()" data-toggle="dropdown">                                    
                                    <span ng-show = "countShow" ng-if = "count > 0" class="badge badge-danger" ng-cloak>{{count}}</span>
                                </a>
                                <ul  id="list-wrapper-noti" class="dropdown-menu dropdown-menu-right style-3" >
                                    <div infinite-scroll-disabled = "disableScroll" infinite-scroll='loadMoreNotifications()' infinite-scroll-distance='0' infinite-scroll-container="'#list-wrapper-noti'">
                                        <li ng-show="showSeeAll">
                                            <a href="report.php?id=28" style=" color:#3C71C1; padding-top: 3px;"><center>See all</center> </a>
                                            <!-- <a class="pull-right" href="list.php?id=20" style=" color:#3C71C1;">Mark all as read </a> -->
                                        </li>
                                        <li style="height: 30px"  ng-show="showSeeAll"></li>
                                    
                                        <li ng-repeat = "notification in notifications track by $index">
                                            <a ng-if = "notification.onclick == 0" style="text-decoration:none; color:#534D4D" href="{{notification.href}}" >
                                                <div class="media">
                                                   
                                                    <img src="./img/icon57.png" alt="fakeimg" class="img-circle pull-left" style="height:50px; width:50px;">
                                                    
                                                    <div class="media-body">
                                                        <div class="media">{{notification.title}}<!--{{notification.employee}} {{notification.action | lowercase}} {{notification.element}} {{notification.record_value}}--></div>
                                                        <span class="muted">{{notification.time_string}}</span>
                                                    </div>
                                                    
                                                </div>
                                            </a>
                                            <a ng-if = "notification.onclick == 1" style="text-decoration:none; color:#534D4D" ng-click ="open_modal(notification.concat_id,notification.modal)" href="javascript:void(0);" >
                                                <div class="media">
                                                   
                                                    <img src="./img/icon57.png" alt="fakeimg" class="img-circle pull-left" style="height:50px; width:50px;">
                                                    
                                                    <div class="media-body">
                                                        <div class="media">{{notification.title}}<!--{{notification.employee}} {{notification.action | lowercase}} {{notification.element}} {{notification.record_value}}--></div>
                                                        <span class="muted">{{notification.time_string}}</span>
                                                    </div>
                                                    
                                                </div>
                                            </a>
                                            <div class="divider"></div>
                                        </li>
                                        
                                    </div>
                                </ul>
                            </li>
                            <!-- END Messages -->
                        </ul>
                        <!-- END Header Nav -->

                        <!-- Mobile Navigation, Shows up on tables and mobiles -->
                        <ul class="navbar-nav-custom pull-left visible-xs visible-sm" id="mobile-nav">
                            <li>
                                <!-- It is set to open and close the main navigation on tables and mobiles. The class .navbar-main-collapse was added to aside#page-sidebar -->
                                <a href="javascript:void(0)" data-toggle="collapse" data-target=".navbar-main-collapse">
                                    <i class="fa fa-bars"></i>
                                </a>
                            </li>
                            <li class="divider-vertical"></li>
                        </ul>
                        <!-- END Mobile Navigation, Shows up on tables and on mobiles -->
                    </div>
                    <!-- END Header Nav Section -->
                </div>
                <!-- END div#row -->
            </header>

            <?php if(empty($browser_face_pilot)): ?>
            <script src="https://www.gstatic.com/firebasejs/5.7.0/firebase-app.js"></script>
            <script src="https://www.gstatic.com/firebasejs/5.7.0/firebase-messaging.js"></script>
            <script src="js/fcm-app.js"></script>
            <?php endif; ?>
            <script>
                //Universal functions

                function show_snack(msg) { // show snack popup with msg
                    var x = document.getElementById("snackbar");
                    $("#snackbar").html(msg);
                    x.className = "show";
                    setTimeout(function(){ x.className = x.className.replace("show", ""); }, 3000);
                }
                function smooth_scroll(selector, effect='bounce'){ //add smooth scrolling to scrollbar with effects 1.bounce 2.glow
                    var ScrollbarOptions = {
                        damping : 0.1,
                        continuousScrolling : false,
                        renderByPixels: true,
                        thumbMinSize : 10,
                        plugins: {
                        overscroll:{
                                effect: effect,
                                glowColor :'#4285F4',
                                damping : 0.2
                            },
                        }
                    }
                    Scrollbar.use(window.OverscrollPlugin);
                    Scrollbar.init(document.querySelector(selector),ScrollbarOptions);
                }
            </script>
            <!-- END Header -->