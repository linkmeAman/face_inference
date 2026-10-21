<div id="modal-edit_attendance" class="modal fade" data-backdrop="static" ng-controller="editAttendanceController">
<style>
    [data-input="radio"]:checked,
    [data-input="radio"]:not(:checked) {
        position: absolute;
        left: -9999px;
    }
    [data-input="radio"]:checked + label,
    [data-input="radio"]:not(:checked) + label
    {
        position: relative;
        padding-left: 28px;
        cursor: pointer;
        line-height: 20px;
        display: inline-block;
        color: #666;
    }
    [data-input="radio"]:checked + label:before,
    [data-input="radio"]:not(:checked) + label:before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        width: 18px;
        height: 18px;
        border: 1px solid #ddd;
        border-radius: 100%;
        background: #fff;
    }
    [data-input="radio"]:checked + label[data-value="prs"]:after,
    [data-input="radio"]:not(:checked) + label[data-value="prs"]:after {
        content: '';
        width: 12px;
        height: 12px;
        background: green;
        position: absolute;
        top: 3px;
        left: 3px;
        border-radius: 100%;
        -webkit-transition: all 0.2s ease;
        transition: all 0.2s ease;
    }

    [data-input="radio"]:checked + label[data-value="abs"]:after,
    [data-input="radio"]:not(:checked) + label[data-value="abs"]:after {
        content: '';
        width: 12px;
        height: 12px;
        background: red;
        position: absolute;
        top: 3px;
        left: 3px;
        border-radius: 100%;
        -webkit-transition: all 0.2s ease;
        transition: all 0.2s ease;
    }
    [data-input="radio"]:not(:checked) + label:after {
        opacity: 0;
        -webkit-transform: scale(0);
        transform: scale(0);
    }
    [data-input="radio"]:checked + label:after {
        opacity: 1;
        -webkit-transform: scale(1);
        transform: scale(1);
    }


</style>
    <!-- Modal Dialog -->
    <div class="modal-dialog">
        <!-- Modal Content -->
        <div class="modal-content">
            <!-- Modal Body -->
            <div class="modal-body remove-padding">
                <!-- Modal Tabs -->
                <div class="block-tabs block-themed">
                    <div class="block-options">
                        <a href="javascript:void(0)" class="btn btn-option" ng-click = "closeModal()">×</a>
                    </div>
                    <ul class="nav nav-tabs" data-toggle="tabs">
                        <li class="active"><a href="#modal-inquiry_transfer"><i class="gi gi-trash"></i> Mark Absent</a></li>
                    </ul>
                    <div class="tab-content" >
                        <!-- Account Tab Content -->
                        <div class="tab-pane active" id="modal-delete-confirmation">
                            <form class="form-horizontal">
                                <div class="form-group">
                                    <label class="control-label col-md-3">Code :</label>
                                    <div class="col-md-2">
                                        <span>{{code}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label col-md-3">Name :</label>
                                    <div class="col-md-9">
                                        <span>{{name}}</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label class="control-label col-md-3">Attendance :</label>
                                    <div class="col-md-9">
                                            <span>
                                                <input type="radio" id="test1{{$index}}" name="radio-group" data-input="radio" ng-model = "attendance" ng-value="0">
                                                <label for="test1" data-value="prs">Present</label>
                                            </span><br>
                                            <span>
                                                <input type="radio" id="test2{{$index}}" name="radio-group" data-input="radio"  ng-model = "attendance" ng-value="1">
                                                <label for="test2"  data-value="abs">Absent</label>
                                            </span>
                                            <span style="color:red" ng-show="attendance_err" class="col-md-offset-2 col-md-10">Please select attendance.</span>
                                    </div>
                                </div>
                                <div class="form-group">
                                        <label class="control-label col-md-3">Comment<span style='color:red;'>*</span></label>
                                        <div class="col-md-9">
                                            <textarea type="text" id="comment"  name="comment" ng-model="comment"  class="form-control" placeholder="Please Enter Comment" required></textarea>
                                        </div>
                                        <span style="color:red" ng-show="comment_error" class="col-md-offset-2 col-md-10">Please enter comment.</span>
                                </div>
                                <div>
                                    <div class="modal-footer" ng-cloak>
                                        <button class="btn btn-success" id = "" ng-click="mark_absent()">Confirm</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <!-- END Account Tab Content -->
                    </div>
                </div>
                <!-- END Modal Tabs -->
            </div>
            <!-- END Modal Body -->

            <!-- Modal footer -->
            
            <!-- END Modal footer -->
        </div>
        <!-- END Modal Content -->
    </div>
    <!-- END Modal Dialog -->
</div>

<!-- END User Modal Settings -->
<script src="js/controllers/editAttendanceController.js?v=<?php echo $template['version']; ?>"></script>