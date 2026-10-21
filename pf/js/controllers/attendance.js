app.controller("attendanceCtrl",function($scope,$http,apiCall,subscription){

    // if(!subscription.check(6,2)) history.go(-1);

    $scope.attendance={event_type:"PUNCH_IN"};
    $scope.attendance.date=moment().format(viewDateFormat);
    $scope.attendance.time=moment().format("hh:mm A");
    if(session.admin != 1) $scope.disableDatetime = true;
    if(session.admin != 1) $scope.disabledpunchMultiple = true;
    $scope.punchBtn=1;
    $scope.punchMultiple = false;
    $scope.names=[];
    var code_name ;
    $scope.datetime=moment($scope.attendance.date,viewDateFormat).format("YYYY-MM-DD")+" "+moment($scope.attendance.time,"hh:mm A").format("HH:mm:ss");
    // Request for member code & name 
    $scope.update=function(id){
        $scope.noBatchErrorShow = false;
        $scope.attDateErrorShow = false;
        if(session.admin != 1) $scope.disableDatetime = true;
        if(session.admin != 1) $scope.disabledpunchMultiple = true;
    	$scope.attendance.date=moment().format(viewDateFormat);
    	$scope.attendance.time=moment().format("hh:mm A");
    	 $scope.skipBtn=false;
        var requestData={"session_id":session_id,"searchString":"","contact_id":id};
        apiCall.callEndpoint(requestData,"attendanceRoute.php","search",json_header).then(function(response){
            var responseData=response.data.data[0];
            if(response.data.error==0){
                $scope.attendance.code=code_name = responseData.code +" - "+responseData.fullname;
                $scope.code=responseData.code;
                $scope.disabledName=1;
                $scope.code=response.data.data[0].code;
                $scope.personType=responseData.type;  // Employee or member (E OR M)
                hasBatch(response.data.data[0].code);
            }
        });
    }
    
    //Function to request for "hasBatch" 
    function hasBatch(code){
        var searchBatchData={"session_id":session_id, "datetime":$scope.datetime ,"code":code,"bid":session.bid,"source":"1","batch_id":""};
        apiCall.callEndpoint(searchBatchData,"attendanceRoute.php","hasBatch",json_header).then(function(response){
            var responseData=response.data;
            if(responseData.error==0){
                
                //code for batch dropdown
                if(responseData.data.batch.length>0){
                    //Show batch dropdown
                    $scope.batchDivShow=1;
                    $scope.noBatchErrorShow=false;
                    $scope.batches=responseData.data.batch;
                    $scope.attendance.batch_id=$scope.batches[0].id;
                    $scope.attendance.item_id=$scope.batches[0].item_id;
                    $scope.session=$scope.batches[0].sessions;
                }else if(responseData.data.batch.length==0){
                    //No batch found
                    $scope.noBatchErrorShow=1; //Show no batch found error div
                    $scope.noBatchError="No batch available";
                    $scope.batchDivShow=0; //Hide batch if visible
                    $scope.attendance.batch_id="";
                    $scope.attendance.item_id="";
                    $scope.session="0";
                }
                
                //Code for no_batch (Only for member)
                if($scope.personType=="M"){
                    if(responseData.data.no_batch.length>0){
                        //Show skip
                        $scope.skipBtn=true;
                        $scope.no_batch=responseData.data.no_batch;
                    }else {
                        //Hide skip
                        $scope.skipBtn=false;
                    }
                }
                
            }else{
                //Hide batch
                $scope.noBatchErrorShow=1; //Show no batch found error div
                $scope.noBatchError="No batch available";
                $scope.batchDivShow=0; //Hide batch if visible
                $scope.attendance.batch_id="";
                $scope.attendance.item_id="";
                $scope.session="0";
            }
        });
    }
    /*************** SEARCH MEMBER | BATCH operation ************************/
    $scope.search = function (term) {
        $scope.btnSubmitDis=true;
        $scope.names.length=0;
        var requestData={"session_id":session_id,"searchString":term,"contact_id":""};
        return apiCall.callEndpoint(requestData,"attendanceRoute.php","search",json_header).then(function (response) {
            if(response.data.error==0){
                response.data.data.forEach(function(item,index){
                    item.codeName=item.code+" - "+item.fullname;  
                    $scope.names.push({"id":item.id,"codeName":item.codeName,"fullname":item.fullname,"code":item.code,"type":item.type});
                });
            }
            return $scope.names;
        });
    }
    
    $scope.selectMember=function(item){
        //Request for hasBatch
        //console.log($scope.attDateErrorShow)
        if($scope.attDateErrorShow == false || $scope.attDateErrorShow == undefined || $scope.attDateErrorShow == null)
            $scope.btnSubmitDis = false;
        
        $scope.personType=item.type;
        //Request for has batch 
        hasBatch(item.code);
        $scope.code=item.code;
    }  
    
    
    $scope.backspacePress=function(event){

        if(event.keyCode == 8 && $scope.attendance.code.length == 0){
            $scope.code=0;
            $scope.attendance.code="";
            $scope.batchDivShow=$scope.noBatchErrorShow=$scope.punchBtn=0;
            $scope.punchBtn=1; //Show confirm button
           
        }
    }
    $scope.batchChange=function(batch_id){
        $scope.batches.forEach(function(item){
            if(item.id==batch_id){
                $scope.attendance.item_id=item.item_id;
            }
        })
    }
    /**********************END of search ***********************************/
    

    /*******************DATE change event **********************************/
    $scope.dateChange=function(date){
        $scope.datetime=moment(date,viewDateFormat).format("YYYY-MM-DD")+" "+moment($scope.attendance.time,"hh:mm A").format("HH:mm:ss");
        if(moment(date,"DD/MM/YYYY").isAfter(moment())){
            $scope.attDateErrorShow=$scope.btnSubmitDis=1; //Show date error 
            $scope.attDateError="No future date."; 
        }else{
            //Request for batch
            if($scope.code != 0){
                $scope.attendance.code=code_name;
                if (code_name != null && code_name.length > 0) {
                    hasBatch($scope.code);
                    $scope.batchDivShow = true;
                } else {
                    $scope.batchDivShow = false;
                }
            }
            $scope.attDateErrorShow=$scope.btnSubmitDis=0; //Hide date error
        }
    }
    /******************END**************************************************/
    $scope.resetData = function(){
        $scope.attendance.date=moment().format(viewDateFormat);
        $scope.attendance.time=moment().format("hh:mm A");
        $scope.attendance.code="";
        $scope.batchDivShow=false;
        $scope.btnSubmitDis=false;
        $scope.skipBtn=false;
        $scope.noBatchErrorShow = false;
        $scope.attDateErrorShow = false;
    }

    $scope.resetModal=function(){
        $scope.attendance.date=moment().format(viewDateFormat);
        $scope.attendance.time=moment().format("hh:mm A");
        $scope.attendance.code=null;
        $scope.batchDivShow = false;
        $scope.btnSubmitDis = false;
        $scope.noBatchErrorShow = false;
        $scope.attDateErrorShow = false;

        $("#modal-attendance").modal("hide");
        $scope.skipBtn=false;
    }

    /****************** SAVE | CONFIRM button ******************************/
    $scope.btnClick=function(type){
        if(type=='save'){
            $scope.punchBtn=0;
            $scope.confirmBtn=1;
        }else if(type=='skip'){
            //Punch attendance 
            if($scope.no_batch.length==1){
                $scope.attendance.batch_id="";
                $scope.attendance.item_id=$scope.no_batch[0].item_id;
                $scope.session=$scope.no_batch[0].sessions;
                
            }else if($scope.no_batch.length>1){
                $scope.attendance.batch_id="";
                $scope.attendance.item_id="";
                $scope.session="0";
            }
            $scope.datetime=moment($scope.attendance.date,viewDateFormat).format("YYYY-MM-DD")+" "+moment($scope.attendance.time,"hh:mm A").format("HH:mm:ss");
            var punchAttendanceData={"session_id":session_id,"datetime":$scope.datetime,"event_type":$scope.attendance.event_type || "PUNCH_IN","batch_id":$scope.attendance.batch_id,"session":$scope.session,"code":$scope.code,"user_id":session.user_id,"bid":session.bid,"source":"1","item_id":$scope.attendance.item_id,"check_month":check_month};
            apiCall.callEndpoint(punchAttendanceData,"attendanceRoute.php","insert",json_header).then(function(response){
                if(response.data.error==0){
                    //Attendance punched
                    show_snack(response.data.message);
                    $scope.resetData();
                    if (!$scope.punchMultiple) {
                        $("#modal-attendance").modal("hide");    
                    }
                    
                    $scope.confirmBtn=0;
                    $scope.punchBtn=1;
                }
                else
                    show_snack(response.data.message);
            });
        }else{
            $("#confirm").button('loading');
            //session : how many session to deduct in attendance
            $scope.datetime=moment($scope.attendance.date,viewDateFormat).format("YYYY-MM-DD")+" "+moment($scope.attendance.time,"hh:mm A").format("HH:mm:ss");
            var punchAttendanceData={"session_id":session_id,"datetime":$scope.datetime,"event_type":$scope.attendance.event_type || "PUNCH_IN","batch_id":$scope.attendance.batch_id,"session":$scope.session,"code":$scope.code,"user_id":session.user_id,"bid":session.bid,"source":"1","item_id":$scope.attendance.item_id,"check_month":check_month};
            apiCall.callEndpoint(punchAttendanceData,"attendanceRoute.php","insert",json_header).then(function(response){
                $("#confirm").button('reset');
                $scope.noBatchErrorShow=false;
                $scope.confirmBtn=0;
                $scope.punchBtn=1;
                if(response.data.error==0){
                    //Attendance punched
                    show_snack(response.data.message);
                    $scope.resetData();
                    if (!$scope.punchMultiple) {
                        $("#modal-attendance").modal("hide");    
                    }
                    
                }else{
                     show_snack(response.data.message);
                }
                
            });
        }
    }
    /*********************** END *******************************************/
});