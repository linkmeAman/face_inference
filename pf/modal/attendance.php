<style type="text/css">
	#scrollable-dropdown-menu .dropdown-menu {
		max-height: 150px;
		overflow-y: auto;
	}
</style>
<div id="modal-attendance" class="modal fade" data-backdrop="static" ng-controller="attendanceCtrl">
    <!-- Modal Dialog -->
    <div class="modal-dialog">
        <!-- Modal Content -->
        <div class="modal-content">
            <!-- Modal Body -->
            <div class="modal-body remove-padding">
                <!-- Modal Tabs -->
                <div class="block-tabs block-themed">
                    <div class="block-options">
                        <a href="javascript:void(0)" class="btn btn-option" ng-click="resetModal()">×</a>
                    </div>
                    <ul class="nav nav-tabs" data-toggle="tabs">
                        <li class="active"><a href="#modal-attendance-punch"><i class="gi gi-alarm"></i> Attendance</a></li>
                    </ul>
                    <div class="tab-content" >
                        <!-- Account Tab Content -->
                        <div class="tab-pane active" id="modal-attendance-punch">
                            <form  class="form-horizontal" name="attendanceForm" id="attendanceForm">
                                <div class="form-group">
                                    <label class="control-label col-md-4">Action</label>
                                    <div class="col-md-6"><select ng-model="attendance.event_type" class="form-control"><option value="PUNCH_IN">Punch in</option><option value="PUNCH_OUT">Punch out</option></select></div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label col-md-4" >Date-time</label>
                                    <div class="col-md-3">
                                        <input ng-disabled = "disableDatetime" type="text" ng-model="attendance.date" ng-change="dateChange(attendance.date)" placeholder="dd/mm/yyyy" class="form-control" datepicker required>
                                        <span ng-show="attDateErrorShow" style="color: red">{{attDateError}}</span>
                                    </div>
                                    <div class="col-md-4 ">
                                        <div class="input-group bootstrap-timepicker" >
                                            <input ng-disabled = "disableDatetime" type="text" id="day1_start" timepicker name="time" ng-model="attendance.time" class="form-control i" value="">
                                            <span class="input-group-addon"><i class="fa fa-clock-o"></i></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label col-md-4" >Code or name</label>
                                    <div class="col-md-6" id="scrollable-dropdown-menu">
                                        <input type="text" id="member_code"  name="code" ng-model="attendance.code" uib-typeahead="names.codeName for names in search($viewValue)" ng-keydown="backspacePress($event)" typeahead-on-select="selectMember($item)" autocomplete="off" ng-disabled="disabledName" class="form-control" placeholder="Type code or name" required>
                                        <span ng-show="memberErrorShow" style="color: red">{{memberError}}</span>
                                    </div>
                                </div>
                                <div class="form-group" ng-show="batchDivShow">
                                    <label class="control-label col-md-4" >Select batch</label>
                                    <div class="col-md-6">
                                        <select name="batch_id" ng-model="attendance.batch_id" class="form-control" ng-change="batchChange(attendance.batch_id)">
                                            <option ng-repeat="batch in batches" value="{{batch.id}}" ng-selected="$first">{{batch.batch}}</option>
                                        </select>
                                    </div>
                                </div>
                                <div ng-show="noBatchErrorShow" align="center">
                                    <span style="color: red">{{noBatchError}}</span>
                                </div>
                                <div class="form-group">
                                    <!-- Modal footer -->
                                    <div class="modal-footer">
			                            <label style="float:left; margin-top: 5px;">
			                                <input type="checkbox" ng-disabled="disabledpunchMultiple" ng-model="punchMultiple"> Punch multiple
			                            </label>
                                        <button class="btn btn-success" ng-disabled="btnSubmitDis" type="submit" ng-show="punchBtn"  ng-click="attendanceForm.$valid && btnClick('save')"><i class="fa fa-check"></i> Punch</button>
                                        <button class="btn btn-info"  ng-readonly="confirmReadonly" data-loading-text="<i class='fa fa-spinner fa-spin '></i> Processing" ng-show="confirmBtn"  ng-click="btnClick('confirm')"><i class="fa fa-check"></i> Confirm</button>
                                        <button class="btn btn-info pull-left"  ng-show="skipBtn"  ng-click="btnClick('skip')"><i class="fa fa-angle-double-right"></i> Skip batch</button>
                                        <!-- <button type="button" class="btn btn-primary btn-lg" onClick="demo()" id="load" data-loading-text="<i class='fa fa-spinner fa-spin '></i> Processing Order">Submit Order</button> -->
                                    </div>
                                    <!-- END Modal footer -->
                                </div>
                            </form>
                        </div>
                        <!-- END Account Tab Content -->
                    </div>
                </div>
                <!-- END Modal Tabs -->
            </div>
            <!-- END Modal Body -->

            
        </div>
        <!-- END Modal Content -->
    </div>
    <!-- END Modal Dialog -->
</div>
<script src="js/controllers/attendance.js?v=<?php echo $template['version']; ?>"></script>
<script>
    $('#modal-attendance').on('show.bs.modal', function (e) {

       angular.element("#modal-attendance").scope().resetData();
    });
</script>
<!-- END User Modal Settings -->
