var app = angular.module('papafit', ['angular.chosen', 'infinite-scroll', 'ngCkeditor', 'ui.bootstrap', 'mgcrea.ngStrap', 'ngSanitize', 'chart.js']);

app.run(['$rootScope', function($rootScope) {
    $rootScope.isImageUrl = function(url) {
        if (!url || typeof url !== 'string') return false;
        url = url.trim();
        if (!url || url === '0.00' || url === '0' || url === 'null' || url === 'undefined') return false;
        return /\.(jpe?g|png|gif|webp|svg)(\?.*)?$/i.test(url) || /^https?:\/\/.*\/content\//i.test(url) || /^data:image\//i.test(url);
    };
}]);

// app.directive('aplayer',function($interval) {
//     return {
//         restrict:'A',
//         scope: {
//             audobj: '='
//         },
//         templateUrl: '/pf/partial/audioplayer.html', 
//         link: function($scope, element, attrs){
//         },
//         controller: function($scope){
//             $scope.audio = new Audio();
//             console.log($scope.audobj);
//             if(typeof $scope.audobj == "string"){
//               console.log("init audio");
//               $scope.audio.src = $scope.audobj;
//               $scope.vol = 0.6;
//               $scope.audio.volume = 0.6;
//             }
//             $scope.play = function(){
//                 $scope.audio.play();
//             };
//             $scope.pause = function(){
//                 $scope.audio.pause();
//             };
//             $interval(function(){
//               if ($scope.audio)
//                 $scope.ctime = $scope.audio.currentTime.toFixed(1);
//             },100);
//             $scope.$watch('audio.duration', function(newval){
//                 $scope.duration = $scope.audio.duration.toFixed(1);
//             });
//             $scope.changetime = function(t){
//                 $scope.audio.currentTime = $scope.ctime;
//             };
//             $scope.changevol = function(t){
//                 $scope.audio.volume = $scope.vol;
//             };
//             $scope.ntot = function(secs) {
//               var hr  = Math.floor(secs / 3600);
//               var min = Math.floor((secs - (hr * 3600))/60);
//               var sec = Math.floor(secs - (hr * 3600) -  (min * 60));
//               if (min < 10){ 
//                 min = "0" + min; 
//               }
//               if (sec < 10){ 
//                 sec  = "0" + sec;
//               }
//               return min + ':' + sec;
//             }
//         }
//     };
// });
app.config(function($sceDelegateProvider) {
    $sceDelegateProvider.resourceUrlWhitelist([
      // Allow same origin resource loads.
      'self',
      // Allow loading from our assets domain. **.
      'https://admin.creativeculturalcentre.in/**'
    ]);
  });

// --- CCC Telemetry Angular Exception & HTTP Interceptor ---
app.config(['$provide', '$httpProvider', function($provide, $httpProvider) {
    $provide.decorator('$exceptionHandler', ['$delegate', function($delegate) {
        return function(exception, cause) {
            $delegate(exception, cause);
            try {
                if (window.CCCTelemetry) {
                    var msg = exception && exception.message ? exception.message : String(exception);
                    window.CCCTelemetry.addBreadcrumb('angular.digest_error', msg, { cause: cause });
                    window.CCCTelemetry.captureException(exception, {
                        error_type: 'AngularDigestError',
                        severity: 'FATAL',
                        cause: cause
                    });
                }
            } catch (e) {}
        };
    }]);

    $httpProvider.interceptors.push(['$q', function($q) {
        return {
            'request': function(config) {
                if (config && config.url && config.url.indexOf('telemetryRoute.php') === -1) {
                    var traceId = window.CCCTelemetry ? window.CCCTelemetry.generateTraceId() : ('tr_' + Date.now());
                    config.headers = config.headers || {};
                    config.headers['X-Request-ID'] = traceId;
                    config._telemetryTraceId = traceId;
                    config._startTime = Date.now();
                }
                return config;
            },
            'responseError': function(rejection) {
                if (rejection && rejection.config && rejection.config.url && rejection.config.url.indexOf('telemetryRoute.php') === -1) {
                    var duration = rejection.config._startTime ? (Date.now() - rejection.config._startTime) : 0;
                    var status = rejection.status || 0;
                    var statusText = rejection.statusText || (status === 0 || status === -1 ? 'Network/Offline/Timeout' : 'HTTP Error');
                    var url = rejection.config.url || '';
                    if (window.CCCTelemetry) {
                        window.CCCTelemetry.addBreadcrumb('http.fail', (rejection.config.method || 'GET') + ' ' + url + ' [' + status + ']');
                        window.CCCTelemetry.captureMessage(
                            'HTTP Request Failed: ' + status + ' ' + statusText + ' (' + url + ')',
                            'API_FAIL',
                            {
                                trace_id: rejection.config._telemetryTraceId,
                                status: status,
                                statusText: statusText,
                                url: url,
                                duration_ms: duration
                            }
                        );
                    }
                }
                return $q.reject(rejection);
            }
        };
    }]);
}]);
  
// Global filter for converting any string to camelcase 
app.filter('titleCase', function() {
    return function(input) {
        input = input || '';
        return input.replace(/\w\S*/g, function(txt) { return txt.charAt(0).toUpperCase() + txt.substr(1).toLowerCase(); });
    };
});

app.filter('myDateFormat', function($filter) {
    var angularDateFilter = $filter('date');
    return function(theDate) {
        return angularDateFilter(theDate, 'dd MMM yyyy');
    }
});

app.directive('numbersOnly', function() {
    return {
        require: 'ngModel',
        link: function(scope, element, attr, ngModelCtrl) {
            function fromUser(text) {
                if (text) {
                    var transformedInput = text.replace(/[^0-9]/g, '');

                    if (transformedInput !== text) {
                        ngModelCtrl.$setViewValue(transformedInput);
                        ngModelCtrl.$render();
                    }
                    return transformedInput;
                }
                return undefined;
            }
            ngModelCtrl.$parsers.push(fromUser);
        }
    };
});

// Global apiCall service 
app.service('apiCall', ['$http', function($http) {
    var operationKeys={};

    this.callEndpoint = function(postData, postUrl, action, headers) {
        // var loading = $('#loading');
        // loading.fadeIn(250);
        if(postUrl==='invoiceRoute.php' && action==='insert' && postData && !postData.idempotency_key){
            var payload=angular.copy(postData);delete payload.session_id;delete payload.uid;
            var fingerprint=JSON.stringify(payload), storageKey='ccc_invoice_draft_'+fingerprint;
            var key=operationKeys[fingerprint];
            if(!key){try{key=sessionStorage.getItem(storageKey);}catch(e){}}
            if(!key){var bytes=new Uint8Array(16);window.crypto.getRandomValues(bytes);key=Array.from(bytes,function(x){return x.toString(16).padStart(2,'0');}).join('');}
            operationKeys[fingerprint]=key;postData.idempotency_key=key;
            try{sessionStorage.setItem(storageKey,key);}catch(e){}
        }
        postUrl = api_url + postUrl;
        action_type = { action: action };
        headers = { headers, "uid": session.user_id, "bid": session.bid, "token": session.token };
        return $http({
            method: 'POST',
            url: postUrl,
            data: postData,
            headers: headers,
            params: action_type
        }).then(function(response){
            if(Number(response.data.error)===2){window.location.href='logout.php';}
            if(action==='insert' && Number(response.data.error)===0 && typeof storageKey!=='undefined'){
                try{sessionStorage.removeItem(storageKey);}catch(e){}
            }
            return response;
        },function(){
            return {data:{error:1,error_msg:'NETWORK_ERROR',data:[],message:'Connection failed. Retry the same action; your operation key is preserved.'}};
        });
    }
}]);

app.service('autoRefresh', [function() {
    this.refresh = function(targetScope) {
        targetScope.setPage(targetScope.pager.currentPage,targetScope.pageSize,1);
    }
}]);

session.branch_type = session.branch.reduce((acc, branch) => {
    if (branch.id === session.bid) return branch.type;
    return acc;
}, "");

// Global subscription check service
app.service('subscription', ['$http', function($http) {

    this.check = function($module_id, $permission) {
        var flag = false;

        for (var i = 0; i < session.permissions.length; i++) {
            if (Number(session.permissions[i].module_id) == $module_id && Number(session.permissions[i].permission) >= $permission) {
                flag = true;
                break;
            }
        }

        return flag;
    }
    this.getCreateUrl = function(module_id) {
        if (this.check(module_id, 2)) {
            switch (module_id) {
                // ADR-029: '+ Add New' button for Invoices (2) and Members (18) routes to invoice creation
                case '2':
                case '18':
                    return ['1', 'invoice.php'];
                case '3':
                    return ['1', 'inquiry.php'];
                case '4':
                    return ['0', 'expense'];
                case '5':
                    return ['0', 'sms-template'];
                case '6': //Attendance
                    return ['0', 'attendance'];
                case '8':
                    return ['1', 'batch.php'];
                case '9':
                    return ['1', 'service.php'];
                case '10':
                    return ['0', 'category'];
                case '11':
                    return ['1', 'employee.php'];
                case '12':
                    return ['1', 'contact.php'];
                case '41':
                    return ['1', 'addProduct.php'];
                case '43':
                    return ['0', 'session-master'];
                case '44':
                    return ['0', 'module'];
                case '49':
                    return ['0', 'exam_add'];
                case '50':
                    return ['0', 'assignment_add'];
                // case '54':
                //     return ['0', 'asset-form'];
                case '66':
                    return ['0', 'inquiry-source'];
                case '70':
                    return ['1', 'inventory.php'];
                case '75':
                    return ['0', 'addTeacher'];
                case '76':
                    return ['0', 'venue'];
                default: //No add action for module.
                    return ['2'];

            }
        } else {
            return false;
        }
    }
}]);

app.service('moduleLabel', ['$http', function($http) {
    this.getModule = function(module_id) {
        for (var i = session.permissions.length - 1; i >= 0; i--) {
            var permission = session.permissions[i];
            if (permission.module_id == module_id) {
                return permission.module;
            }
        }
        return "";
    }
    this.getAbbreviation = function(module_id) {
        for (var i = session.permissions.length - 1; i >= 0; i--) {
            var permission = session.permissions[i];
            if (permission.module_id == module_id) {
                return permission.abbreviation;
            }
        }
        return "";
    }
}]);

//Global new category service
app.service('category', ['$http', function($http) {
    this.addNewCat = function() {
        // open("https://papa.fit/pf/dashboard.php#modal-category");
        $("#modal-category").modal("show");
    }
}]);

// Open new tab 
app.service('newTab', function() {
    this.open = function(url) {
        return window.open(domain_url + "pf/" + url);
    }
})

// datepicker Directive
app.directive('datepicker', function() {
    return {
        restrict: 'A',
        require: 'ngModel',
        link: function(scope, element, attrs, ctrl) {
            // let something = $.datepicker();
            // console.log(something);
            $(element).datepicker({
                format: 'dd-mm-yyyy',
                todayHighlight: true,
                autoclose: true,
                minViewMode: 2
            }).on("changeDate", function(date) {
                $(this).datepicker("hide");
                function formatDate(date) {
                    var d = new Date(date),
                        month = '' + (d.getMonth() + 1),
                        day = '' + d.getDate(),
                        year = d.getFullYear();
                
                    if (month.length < 2) 
                        month = '0' + month;
                    if (day.length < 2) 
                        day = '0' + day;
                    return [day, month, year].join('-');
                }
                val = formatDate(date.date);
                ctrl.$setViewValue(val);
                ctrl.$render();
                scope.$apply();
            });
        }
    };
});

app.directive('timepicker', function() {
    return {
        restrict: 'A',
        require: 'ngModel',
        link: function(scope, element, attrs, ngModelCtrl) {
            $(function() {
                element.timepicker({
                    onSelect: function(time) {
                        ngModelCtrl.$setViewValue(time);
                        scope.$apply();
                    }
                });
            });
        }
    }
});




/**************** Side menu controller ******************/
app.controller('sideCtrl', function($scope, apiCall, $window) {
    $scope.id = session.bid;
    $scope.branches = branches;
    $scope.side = {};

    $scope.changeBranch = changeBranch;
    //$scope.triggerUpload = triggerUpload;
    $scope.resetDefault = resetDefault;
    $scope.goToReport = goToReport;

    $scope.showTraining = false;
    $scope.showSystemConfig = false;
    $scope.showTrash = false;
    $scope.showProfile = false;
    $scope.showStats = false;
    $scope.showRefer = false;

    initController();

    function initController() {
        if (session.user_type == 'E') {
            $scope.showTraining = true;
            $scope.showSystemConfig = true;
            $scope.showTrash = true;
            $scope.showProfile = true;
            $scope.showStats = true;
            $scope.showRefer = false;
        }
        if (session.firstLogin == 1 && session.inst_logo == '') {
            apiCall.callEndpoint({ "client_id": session.client_id }, "commonRoute.php", "getInstLogo", json_header).then(function(response) {
                if (response.data.error == 0 && response.data.data.inst_logo != '') {
                    document.cookie = "inst_logo = " + response.data.data.inst_logo;
                    window.location.reload();
                }
            });
        }

        // apiCall.callEndpoint({"session_id":session_id},"dashboardRoute.php","selectStats",json_header).then(function(response){
        //     $scope.side=response.data.stats;
        // });
        //Sent from fcm-app.js
        // apiCall.callEndpoint({"fcm_token":localStorage.getItem('fcm_token')},"userRoute.php","updateFCM",json_header).then(function(response){

        // });

    }

    var start_date = moment().format("YYYY-MM-DD");
    var end_date = moment().format("YYYY-MM-DD");


    function goToReport(module, id) {
        var url = "";

        switch (module) {
            case 'attendance':
                url += "report.php?id=" + id + "&start_date=" + start_date + "&end_date=" + end_date;
                break;
            case 'followup':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
            case 'payment':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
            case 'sms':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
        }

        $window.location = url;
    }



    function changeBranch(id) {
        var req_json = {
            "session_id": session.session_id,
            "client_id": session.client_id,
            "user_id": session.user_id,
            "bid": id
        };
        apiCall.callEndpoint(req_json, "commonRoute.php", "changeBranch", json_header).then(function(response) {
            if (response.data.error == 0) {
                window.location.reload();
            }
        });
    }



    function resetDefault(called_url) {
        if (!called_url) return;
        called_url = called_url.trim();
        console.log(called_url);
        if (called_url === '#modal-sms-template') {
            if (typeof reset_sms_save_modal === 'function') reset_sms_save_modal();
        } else if (called_url === '#modal-expense') {
            if (typeof reset_expense_modal === 'function') reset_expense_modal();
        } else if (called_url === '#modal-batch_finder') {
            var bf = angular.element("#modal-batch_finder");
            if (bf && bf.length && bf.scope() && typeof bf.scope().update === 'function') {
                bf.scope().update("");
            }
        } else if (called_url.indexOf('#') === 0) {
            var el = angular.element(called_url);
            if (el && el.length && el.scope() && typeof el.scope().update === 'function') {
                el.scope().update("");
            }
        }
    }

});
/****************END****************************/

app.controller('topController', function($scope, $http, apiCall, $timeout) {
    $scope.notifications = [];
    $scope.count = 0;
    $scope.branches = branches;
    $scope.branch = "";
    $scope.countShow = true;
    $scope.showNotification = false;
    $scope.showSeeAll = false;
    $scope.showSearch = false;
    $scope.redirectUrl = session.home_page.replace("/pf/", "");
    // $scope.redirectUrl = session.home_page;
    $scope.showTutorial = (session.client_id == 269 && session.user_type != 'M');
    $scope.showBranch = session.user_type != 'M';
    $scope.isHeadquarter = false;
    $scope.glob_show = 0;
    
    /* functions declaration */
    $scope.loadMoreNotifications = loadMoreNotifications;
    $scope.addNewNotification = addNewNotification;
    $scope.hideCount = hideCount;
    $scope.logMeOut = logMeOut;
    $scope.open_modal = open_modal;
    $scope.branchChange = branchChange;
    
    //set default branch name
    branches.forEach(function(item, position) {
        if (item.id == session.bid) {
            $scope.branch = item.branch;
        }
    })

    // if (session.position_id != "1" && session.position_id != "2" && session.position_id != "7" && session.position_id != "9" && session.position_id != "8") {
    // if (session.position_id != "1" && session.position_id != "2" && session.position_id != "3" && session.position_id != "4" && session.position_id != "5" && session.position_id != "6" && session.position_id != "7" && session.position_id != "8" && session.position_id != "9" && session.position_id != "10" && session.position_id != "11" && session.position_id != "17" && session.position_id != "20" && session.position_id != "23" && session.position_id != "24") 

    // Removed access for these employee position   NO need of this code 
    // if(session.position_id == "14" || session.position_id == "15" || session.position_id == "16" || session.position_id == "18" || session.position_id == "19" || session.position_id == "25"){

    //     $scope.branches = $scope.branches.filter(value => {
    //         return value.id != "27";
    //     });
       
    // }
    for($i=0; $i<branches.length;$i++){
        if(branches[$i]['id'] == '27'){
            $scope.isHeadquarter = true;
        }
    }

    if(localStorage.first_login== "1" && session.user_type == 'E' && $scope.isHeadquarter==true){
        window.localStorage.setItem('first_login','0');
        // branchChange(27);
    }

    function branchChange(bid) {
        branches.forEach(function(item, position) {
            if (item.id == bid) {
                $scope.branch = item.branch;
            }
        });
        var req_json = {
            "session_id": session.session_id,
            "client_id": session.client_id,
            "user_id": session.user_id,
            "bid": bid
        };
        var loading = $('#loading');
        // Fade In loading
        // $('header .navbar-brand').hide();
        loading.fadeIn(250);

        // Hide all badges from links
        $('header li > a > .badge').fadeOut(250);
        apiCall.callEndpoint(req_json, "commonRoute.php", "changeBranch", json_header).then(function(response) {
            if (response.data.error == 0) {
                // window.location.reload();
                window.location.href = $scope.redirectUrl;
                loading.fadeOut(100, function() {

                });
            }
        });
    }
    //Show notification for all
    // $scope.showNotification = true;
    if (session.user_type == 'E') {
        if(session.position_id=='1' || session.position_id=='2' || session.position_id=='3' || session.position_id=='4' || session.position_id=='5' ||session.position_id=='6' || session_id.position_id=='8' || session.position_id =='10'){
            $scope.showSeeAll = true;
        }
            $scope.showSearch = true;
    }
    session.advancedPrivilege = false;
    if (session.user_type == 'E') {
        if(parseInt(session.position_id) <= 6 || session.position_id=='8' || session.position_id=='10'){
            session.advancedPrivilege = true;
        }
            
    }

    function open_modal(data, modal) {
        openModal(data, modal);
    }

    function logMeOut() {
        window.localStorage.setItem('token', "");
        window.localStorage.setItem('user_id', "");
        apiCall.callEndpoint({}, "userRoute.php", "logout", json_header).then(function(response) {

        });
    }

    function loadMoreNotifications() {   
        // var data = { "session_id": session.session_id, "limit": "10","position_id":session.position_id,"employee_id":session.employee_id, "offset": $scope.notifications.length };
        // apiCall.callEndpoint(data, "notificationRoute.php", "selectUserNoti", json_header).then(function(response) {
        //     if (response.data.error == 0) {
        //         for (var i = 0; i < response.data.data.length; i++) {
        //             var notification = response.data.data[i];
        //             if (notification.title == undefined || (notification.title != undefined && notification.title == "")) {
        //                 notification.title = notification.employee + " " + notification.action + " " + notification.element + " " + notification.record_value;
        //             }
        //             $scope.notifications.push(notification);
        //         }

        //         $scope.count = Number(response.data.count);
        //     }
        // });
    }

    function addNewNotification(new_noti) {
        var obj = { "action": new_noti.data.action, "element": new_noti.data.element, "record_value": new_noti.data.record_value, "time_string": new_noti.data.time_string, "employee": new_noti.data.employee };
        $timeout(function() {
            $scope.notifications.unshift(obj);
            $scope.countShow = true;
            $scope.count = Number($scope.count) + 1;
        });

    }

    function hideCount() {
        var data = {
            "session_id": session.session_id,
            "u": [{ "col": "status", "val": "1" }],
            "filter": [
                ["user_id", "=", session.user_id]
            ]
        };
        apiCall.callEndpoint(data, "notificationRoute.php", "updateNotiStatus", json_header).then(function(response) {
            if (response.data.error == 0) {
                $scope.countShow = false;
            }
        });
    }
});

app.controller('footerCtrl', function($scope, apiCall, $window) {

    $scope.goToReport = goToReport;

    $scope.showTraining = session.user_type == 'E';
    $scope.showSupport = session.user_type == 'E';
    $scope.showStats = session.user_type == 'E';

    var start_date = moment().format("YYYY-MM-DD");
    var end_date = moment().format("YYYY-MM-DD");

    // apiCall.callEndpoint({ "session_id": session_id }, "dashboardRoute.php", "selectStats", json_header).then(function(response) {
    //     $scope.stats = response.data.stats;
    // });

    function goToReport(module, id) {
        var url = "";
        switch (module) {
            case 'attendance':
                url += "report.php?id=" + id + "&start_date=" + start_date + "&end_date=" + end_date;
                break;
            case 'followup':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
            case 'payment':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
            case 'sms':
                url += "report.php?id=" + id + "&start_date=2017-01-01&end_date=" + end_date;
                break;
        }
        $window.location = url;
    }

});