


<!-- Left Sidebar -->
<!-- In the PHP version you can set the following options from the config file -->
<!-- Add the class .sticky for a sticky sidebar -->

<style type="text/css">
    #primary-nav li #hover_element:hover {
        text-decoration: none;
        background-color: #616161;
        border-color: #616161;
        width:75%;
    }
</style>

<aside id="page-sidebar" class="collapse navbar-collapse navbar-main-collapse<?php if ($template['sidebar'] == 'sticky') { echo ' sticky'; } ?>">
    <!--
    Wrapper for scrolling functionality
    Used only if the .sticky class added above. You can remove it and you will have a sticky sidebar
    without scrolling enabled when you set the sidebar to be sticky
    -->
    <div class="" style="max-height: 100%;overflow: auto;"  ng-controller="sideCtrl">
        <!-- Mini Profile -->
        <div class="mini-profile">
            <!-- <div class="mini-profile-options" ng-cloak>
                <a ng-show="showTraining" href="training.php" class="badge badge-info" data-toggle="tooltip" data-placement="right" onclick="" title="Training">
                    <i class="fa fa-life-saver"></i>
                </a>
                <a ng-show="showSystemConfig" href="<?php echo !empty($_SESSION['admin']) ? 'settings.php' : '#'; ?>" class="badge badge-warning enable-tooltip" role="button" data-placement="right" title="System Config">
                    <i class="gi gi-cogwheel"></i>
                </a>
                
                <a ng-show="showTrash" href="<?php echo !empty($_SESSION['admin']) ? 'trash.php' : '#'; ?>" class="badge badge-danger" data-toggle="tooltip" data-placement="right" title="Trash" >
                    <i class="gi gi-bin"></i>
                </a>

                <a ng-show="showProfile" href="summary.php?contact=<?php echo $_SESSION['contact_id'] ?>" class="badge badge-success" data-toggle="tooltip" role="button" data-placement="right" title="My Profile">
                    <i class="fa fa-user"></i>
                </a> -->
                <!-- Modal div is at the bottom of the page before including javascript code, we use .enable-tooltip class for the tooltip because data-toggle is used for modal -->
                
                
            <!-- </div> -->
            <a href="#" style="padding-left: 30px;">
               <!-- add this function on img tag if upload want to work   ng-click = "triggerUpload()" -->
                <!-- <img src="https://papa.fit/content/inst_logo/<?php echo !empty($_SESSION['inst_logo']) ? $_SESSION['inst_logo'] : 'default.png '; ?>"  alt="Avatar" class="img-circle"> -->
                <img src="<?php
                $date = date('d');
                if ((date('m') == 12 && $date > 23) || (date('m') == 1 && $date < 3)) {
                    echo "https://admin.creativeculturalcentre.in/content/contact_images/xmas_2019/{$date}.gif";
                } else if (date('m') == 1 && $date >= 23 && $date <= 31) {
                	echo "https://admin.creativeculturalcentre.in/content/contact_images/republic/republicDay.gif";
                } else {
                    echo "img/logo_ccc1.png"; 
                }
                ?>"  alt="Avatar" class="img-circle">
                 <!-- <img src="./https://admin.creativeculturalcentre.in/content/contact_images/diwali/day_<?php echo date('Y-m-d'); ?>.gif"  alt="Avatar" class="img-circle"> -->

                <!-- <img src="./https://admin.creativeculturalcentre.in/content/contact_images/xmas/hny1.gif"  alt="Avatar" class="img-circle">                <input id="fileInput" style="display:none" type="file" instfile-model="instFile" name="logo" /> -->
            </a>
            <!-- <div style="position: absolute; left: 60px; top: -20px;"><img src="./https://admin.creativeculturalcentre.in/content/contact_images/cap.png" alt="Merry Christmas"></div> -->
            <!-- <div style="padding-left: 30px;"> -->
                <!-- <label style="color:white ;padding-top: 10px;padding-left: 10px;text-align: center;" ><?php echo !empty($_SESSION['inst_name']) ? $_SESSION['inst_name'] : ''; ?></label><br> -->
                <!-- <select style="background-color: #353535;padding-left: 10px;color:white;height:30px;width:100px; border: 0;" name="example-datatables2_length"  ng-model="id" aria-controls="example-datatables2" ng-change = "changeBranch(id)" class="form-control" ng-cloak>
                    <option style="color:white;border: 0; text-align:center;" ng-repeat="branch in branches" value="{{branch.id}}" ng-cloak>{{branch.branch}}</option>
                </select>
               
            </div> -->
        </div>
        <!-- END Mini Profile -->
        <!-- Sidebar Tabs -->
        <div class="sidebar-tabs-con" ng-cloak>
            <!-- <ul class="sidebar-tabs" data-toggle="tabs" ng-show="showStats">
                <li class="active">
                    <a href="#side-tab-menu" data-toggle="tooltip" title="Menu"><i class="gi gi-list"></i></a>
                </li>
                <li>
                    <a href="#side-tab-extra" data-toggle="tooltip" title="Statistics"><i class="gi gi-stats"></i></a>
                </li>
            </ul> -->
            <div class="tab-content" >
                <div class="tab-pane active" id="side-tab-menu">
                    <!-- Primary Navigation -->
                    
                    <nav id="primary-nav">
                        <?php if(isset($primary_nav1)) {?>
                            <ul>
                                <?php foreach ($primary_nav1 as $key => $link) {
                                    
                                    if($link['hide']==1 || $link['hide']==2){
                                        // Break the iteration
                                        continue;
                                    }

                                    $link_class = '';
                                    // Get link's vital info
                                    $url = (isset($link['url']) && $link['url']) ? $link['url'] : '#';
                                    $url_parameter=(isset($link['report_id']) && !empty($link['report_id'])) ? "?id=".$link['report_id'] : '';
                                    $url =$url.$url_parameter; //Appending parameter to url

                                    $target=$link['target']; 

                                    // Following logic is used for finding the current page on url
                                    $url_path = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']; //https://papa.fit/folder1/xyz.php
                                    $active_url=basename($url_path); //xyz.php


                                    //var_dump($active_url);

                                    $active = (isset($link['url']) && ($active_url == $url)) ? ' active' : '';
                                    $icon = (isset($link['icon']) && $link['icon']) ? '<i class="' . $link['icon'] . '"></i>' : '';
                                    // Check if we need add the class active to the li element (only if a sublink is active)
                                    $li_active = '';
                                    $menu_link = ''; 
                                    
                                   	/*
                                   	Used for making <li> active if sub element is selected 
                                   	*/
                                    if (isset($link['sub']) && count($link['sub'])>1) {
                                    	// store url's from each array under settings array

                                    	$url_array=array(); //Storing sub elemets root url
                                    	$suburl_array=array(); //Storing sub elements plus url

                                    	// 1st foreach is used for storing sub elemets root url 
                                    	// 2nd  sub foreach is used for storing sub elemets plus url
                                        foreach ($link['sub'] as $sub_link) {
                                        	
                                        	$sub_url= ($sub_link['report_id'] != 0)?($sub_link['url']."?id=".$sub_link['report_id']) : $sub_link['url'];
                                        	array_push($url_array, $sub_url);

                                        	if(isset($sub_link['sub'])){
                                        		foreach ($sub_link['sub'] as $sub_link2) {
                                        			# code...
                                        			$sub_url2=$sub_link2['url'];
                                        			array_push($suburl_array, $sub_url2);
                                        		}
                                        	}
                                        }	
                                        foreach ($link['sub'] as $test) {
                                        	# code...
                                        	if(in_array($active_url, $url_array)){
                                        		$li_active=' class="active"';
                                        		break;
                                        	}

                                        	if(in_array($active_url, $suburl_array)){
                                        		$li_active=' class="active"';
                                        		break;
                                        	}
                                        }
                                    }

                                	$menu_link = 'menu-link';
                                    if ($menu_link || $active)
                                        $link_class = ' class="'. $menu_link . $active .'"';

                                ?>
                                    <li <?php echo $li_active; ?>>

                                    <?php if(empty($link['sub']) || !is_array($link['sub']) || count($link['sub'])==0 ) { ?>

                                    	<!-- If sub array contains no data -->
                                        <a target="<?php echo $target; ?>" href="<?php echo $url; ?>" class="<?php echo $active; ?>" style="padding-right: 20px;"> <?php echo $icon .$link['name']; ?></a>

                                    <?php }elseif(!empty($link['sub']) && is_array($link['sub']) && count($link['sub'])==1 && !empty($link['sub'][0]['type']) && $link['sub'][0]['type']==1 ) {
                                        // Details of plus icon side 
                                        $plus_url=$link['sub'][0]['url'];
                                        $plus_url_parameter=(!empty($link['sub'][0]['report_id'])) ? "?id=".$plus_url : '';
                                        $plus_url=$plus_url.$plus_url_parameter;
                                        $plus_icon=(isset($link['sub'][0]['icon'])) ? '<i class="' . $link['sub'][0]['icon'] . '"></i>' : '';
                                        $plus_tooltip=$link['sub'][0]['name'];
                                        $plus_target=$link['sub'][0]['target'];
                                        $plus_active=($active_url==$plus_url) ? 'active' : '';
                                    ?>
                                    	<!-- If sub_array contains only one object -->
                                        <a target="<?php echo $target; ?>" href="<?php echo $url; ?>" class="<?php echo $active; ?>" style='display: inline-block; width: 75% '> <?php echo $icon . $link['name']; ?></a>

                                        <a target="<?php echo $plus_target; ?>" href="<?php echo $plus_url; ?>" class="<?php echo $plus_active; ?>" style="display: inline-block; float:right; width:40px;" ng-click="resetDefault('<?php echo $plus_url; ?> ')" align="right" <?php if( strpos($link['sub'][0]['url'],"#") !== false) echo 'data-toggle="modal"'; else echo ""; ?> data-toggle="tooltip" title="<?php echo $plus_tooltip; ?>"><?php echo $plus_icon; ?></i></a>
                                        

                                    <?php }elseif(!empty($link['sub']) && is_array($link['sub']) && count($link['sub']) > 0){?>

                                    	<!-- If sub_array contains more than one object data -->
                                        <a target="<?php echo $target; ?>" href="<?php echo $url; ?>"   <?php echo $link_class; ?> style="padding-right: 20px;"> <?php echo $icon . $link['name']; ?></a>
                                        <ul >

                                        <?php foreach ($link['sub'] as $sub_link) {

                                            if($sub_link['hide']==1 || $sub_link['hide']==2){
                                                // Skip the iteration
                                                continue;
                                            }
                                            $link_class = '';
                                            $target=$sub_link['target'];
                                            // Get sublink's vital info
                                            $url = (isset($sub_link['url']) && $sub_link['url']) ? $sub_link['url'] : '#';
                                            $url_parameter=(!empty($sub_link['report_id'])) ? '?id='.$sub_link['report_id'] : '';
                                            $url=$url.$url_parameter;
                                            $active = (isset($sub_link['url']) && ($active_url == $url)) ? ' active' : '';
                                            $sub_icon= (isset($sub_link['icon']) && $sub_link['icon']) ? '<i class="' . $sub_link['icon'] . '"></i>' : '';
                                            // Check if we need add the class active to the li element (only if a sublink is active)
                                            $li2_active = '';
                                            $submenu_link = '';

                                            if (isset($sub_link['sub']) && $sub_link['sub']) {
                                                foreach ($sub_link['sub'] as $sub2_link) {
                                                    if (in_array($template['active_page'], $sub2_link)) {
                                                        $li2_active = ' class="active"';
                                                        break;
                                                    }
                                                }
                                                $submenu_link = 'submenu-link';
                                            }

                                            if ($submenu_link || $active)
                                                $link_class = ' class="'. $submenu_link . $active .'"';
                                        ?>
                                            
                                            <li <?php //echo $li2_active; ?>>
                                            	<?php if(count($sub_link['sub'])==0) {?>

                                            	<a target="<?php echo $target; ?>" href="<?php echo $url; ?>"  <?php echo $link_class; ?> style="padding-right: 20px;"> <?php echo $sub_icon . $sub_link['name']; ?></a>

                                                <?php }elseif(count($sub_link['sub'])==1 && $sub_link['sub'][0]['type']==1) {

                                                    $plus_url=$sub_link['sub'][0]['url'];
                                                    $plus_tooltip=$sub_link['sub'][0]['name'];
                                                    $plus_icon=$sub_link['sub'][0]['icon'];
                                                    $plus_target=$sub_link['sub'][0]['target'];
                                                    $plus_active=($active_url == $plus_url) ? 'active' : '';
                                                ?>

                                                    <a target="<?php echo $target; ?>" href="<?php echo $url; ?>" class="<?php echo $active; ?>" <?php //echo $link_class; ?> id="hover_element" style='display: inline-block; width: 65% '> <?php echo $sub_icon . $sub_link['name']; ?></a>

                                                    <a target="<?php echo $plus_target; ?>" href="<?php echo $plus_url ?>" class="<?php echo $plus_active; ?>" style="display: inline-block; float:right;" ng-click="resetDefault('<?php echo $plus_url; ?> ')" align="right" <?php if( strpos($sub_link['sub'][0]['url'],"#") !== false) echo 'data-toggle="modal"'; else echo ""; ?> data-toggle="tooltip" title="<?php echo $plus_tooltip; ?>"><i class="<?php echo $plus_icon; ?>" style="right: 10px;"></i></a>

                                                <?php }?>

                                                <?php if (isset($sub_link['sub']) && $sub_link['sub']) { ?>
                                                    <ul>
                                                        <?php foreach ($sub_link['sub'] as $sub2_link) {
                                                            // Get vital info of sublinks
                                                            $url = (isset($sub2_link['url']) && $sub2_link['url']) ? $sub2_link['url'] : '#';
                                                            $target=$sub2_link['target'];

                                                            $active = (isset($sub2_link['url']) && ($template['active_page'] == $sub2_link['url'])) ? ' class="active"' : '';
                                                        ?>
                                                        <li>
                                                            <a target="<?php echo $target; ?>" href="<?php echo $url; ?>"<?php echo $active ?>><?php echo $sub2_link['name']; ?></a>
                                                        </li>
                                                        <?php } ?>
                                                    </ul>
                                                <?php } ?>
                                            </li>
                                        <?php }//End insser sub array foreach?>  

                                        


                                        </ul>
                                    <?php }//End of $link['sub']>1 elseif?>
                                    </li>
                                <?php } // End of main foreach?>
                            </ul>
                        <?php } //End of Main if?>

                        <?php 
                        // Developer Diagnostics Link: only visible to developer accounts (position 10, 11) or explicit dev mode.
                        // Hidden from non-technical Owner (position 1) and all employees (6, 18, 19, etc.)
                        $pos = isset($_SESSION['position_id']) ? (int)$_SESSION['position_id'] : 0;
                        $isAdmin = isset($_SESSION['admin']) ? (int)$_SESSION['admin'] : 0;
                        if ($pos === 10 || $pos === 11 || !empty($_SESSION['dev_mode'])) {
                        ?>
                            <ul style="border-top: 1px solid #444; margin-top: 10px; padding-top: 5px;">
                                <li>
                                    <a href="system_error_logs.php" class="<?php echo ($template['active_page'] == 'system_error_logs.php') ? 'active' : ''; ?>" style="color: #ff7675;">
                                        <i class="fa fa-bug text-danger"></i> Error Diagnostics
                                    </a>
                                </li>
                            </ul>
                        <?php } ?>
                    </nav>
                    <!-- END Primary Navigation -->
                </div>
                <div class="tab-pane tab-pane-side" id="side-tab-extra">
                    <div ng-show ="side.sms_credits.permission">
                        <h5><i class="fa fa-sms pull-right"></i><a href="javascript:void(0);" ng-click = "goToReport('sms',side.sms_credits.report_id)" class="side-link">SMS Credits</a></h5>
                        <div>{{side.sms_credits.credits}}</div> 
                    </div>
                        
                    <div ng-show="side.biometric_device.permission">
                        <h5><i class="gi gi-global pull-right"></i>Biometric Device</h5>
                        <div ng-if="side.biometric_device.status == 'Connected'" class="text-success" >{{side.biometric_device.status}}</div>
                        <div ng-if="side.biometric_device.status == 'Disconnected'" class="text-danger" >{{side.biometric_device.status}}</div>
                    </div>
                    <div ng-show="side.floor_attendance.permission">
                        <h5><i class="gi gi-alarm pull-right"></i><a href="javascript:void(0)" ng-click = "goToReport('attendance',side.floor_attendance.report_id)" class="side-link">Floor Attendance</a></h5>
                        <div>{{side.floor_attendance.count}}</div>
                    </div>
                    <div ng-show="side.total_payment_due.permission">
                        <h5><i class="fa fa-money pull-right"></i><a href="javascript:void(0)" ng-click = "goToReport('payment',side.total_payment_due.report_id)" class="side-link">Total Balance</a></h5>
                        <div>{{side.total_payment_due.balance}}</div>
                    </div>
                    <div ng-show="side.missed_followups.permission">
                        <h5><i class="fa fa-recycle pull-right"></i><a href="javascript:void(0)" ng-click = "goToReport('followup',side.missed_followups.report_id)" class="side-link">Missed FollowUps</a></h5>
                        <div>{{side.missed_followups.count}}</div>
                    </div>

                    <!-- <h5><i class="fa fa-calendar pull-right"></i><a href="javascript:void(0)" class="side-link">Next Due Date</a></h5> -->
                    <!-- <div>{{}}</div> -->
                </div>
            </div>
        </div>
        <!-- END Sidebar Tabs -->
    </div>
    <!-- END Wrapper for scrolling functionality -->
</aside>
<!-- END Left Sidebar -->

<script src="js/controllers/mainController.js"></script>
<script >
    var branches = [];
    <?php foreach ((array)($_SESSION['branch'] ?? []) as $branch){ ?>;
      branches.push({'id':'<?php echo $branch['id']; ?>','branch': '<?php echo $branch['branch']; ?>'});
    <?php } ?>

</script>
