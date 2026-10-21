
    <!-- Footer -->
    <footer ng-controller="footerCtrl">
        <div class="pull-right">
            Crafted with <i class="fa fa-heart text-danger"></i> by <strong>Creative Cultural Centre</strong>
        </div>
        <div class="pull-left" ng-cloak>
            <!-- <span>2019</span> &copy; <strong><a href="https://papa.fit" target="_blank"><?php echo $template['name'] . ' ' . $template['version']; ?></a></strong> -->

            <!-- <span ng-if="(stats.biometric_device.permission || stats.sms_credits.permission || stats.floor_attendance.permission || stats.total_payment_due.permission || stats.missed_followups.permission) && showStats">
                <div class="btn-group dropup">
                    <a class="btn dropdown-toggle" data-toggle="dropdown" href="javascript:void(0)">Statistics</a>
                    <ul class="dropdown-menu">
                        <li ng-show="stats.sms_credits.permission"><a href="javascript:void(0);"  ng-click = "goToReport('sms',stats.sms_credits.report_id)" class="side-link">SMS Count - {{stats.sms_credits.credits}}</a></li>
                        <li ng-show="stats.floor_attendance.permission"><a href="javascript:void(0)" ng-click = "goToReport('attendance',side.floor_attendance.report_id)" class="side-link">Floor Attendance - {{stats.floor_attendance.count}}</a></li>
                        <li ng-show="stats.total_payment_due.permission"><a href="javascript:void(0)" ng-click = "goToReport('payment',stats.total_payment_due.report_id)" class="side-link">Total Balance - {{stats.total_payment_due.balance}}</a></li>
                        <li ng-show="stats.missed_followups.permission"><a href="javascript:void(0)" ng-click = "goToReport('followup',stats.missed_followups.report_id)" class="side-link">Missed FollowUps - {{stats.missed_followups.count}}</a></li>
                        <li class="divider"></li>
                        <li ng-show="stats.biometric_device.permission">
                            <i class="fa fa-circle text-success" ng-if="stats.biometric_device.status == 'Connected'"></i>
                            <i class="fa fa-circle text-danger" ng-if="stats.biometric_device.status == 'Disconnected'"></i>&nbsp;&nbsp;Biometric Device 
                        </li> -->
                        <!-- <li class="disabled"><a href="javascript:void(0)">Disabled</a></li>
                        <li class="divider"></li>
                        <li><a href="javascript:void(0)">Another Option</a></li> -->
                    <!-- </ul>
                </div>
            </span> -->
            <!-- <span ng-if="showTraining"><a class="btn" href="training.php" target="_blank">Training</a></span> -->
            <!-- <span ng-if="showSupport"><a class="btn " href="https://tawk.to/chat/58ef83b030ab263079b5fae8/1biefms4v/?$_tawk_popout=true" target="_blank">Support</a></span> -->
            <span><a class="btn" href="../terms.html" target="_blank">Terms & Conditions</a></span>
        </div>
    </footer>
    <!-- END Footer -->
</div>
<!-- END Page Container -->

<!-- Scroll to top link, check main.js - scrollToTop() -->
<a href="#" id="to-top"><i class="fa fa-chevron-up"></i></a>


<!-- Bootstrap.js -->
<script src="js/vendor/bootstrap.min.js"></script>
<?php 
    $modal = isset($_GET['mod']) ?  $_GET['mod']: 1;
    if($modal){
 ?>
<!-- Added by Anuj line no. 46, to create a new modal for interest in followup row -->
<?php include 'modal/form_create.php'; ?>
<?php include 'modal/bigModal.php'; ?>
<?php include 'modal/interest.php'; ?>
<?php include 'modal/batchCancellation.php'; ?>
<?php include 'modal/deleteVendor.php'; ?>
<?php include 'modal/paymentsReport.php'; ?>
<?php include 'modal/heardFrom.php'; ?>
<?php include 'modal/edit_interest.php'; ?>
<?php include 'modal/edit_interest_followup.php'; ?>
<?php include 'modal/category.php'; ?>
<?php include 'modal/followup.php'; ?>
<?php include 'modal/expense.php' ; ?>
<?php include 'modal/sms.php'; ?>
<?php include 'modal/attendance.php'; ?>
<?php include 'modal/add_sms_template.php'; ?>
<?php include 'modal/payment.php'; ?>
<?php include 'modal/sms_status.php'; ?>
<?php include 'modal/delete.php'; ?>
<?php include 'modal/change_password.php'; ?>
<?php include 'modal/image_upload.php'; ?>
<?php include 'modal/play_video.php'; ?>
<?php include 'modal/extend_modal.php'; ?>
<?php include 'modal/dropout_modal.php'; ?>
<?php include 'modal/freeze_modal.php'; ?>
<?php include 'modal/graduation_certificate_modal.php'; ?>
<?php include 'modal/upgrade_modal.php'; ?>
<?php include 'modal/batch_transfer.php'; ?>
<?php include 'modal/membership_transfer.php'; ?>
<?php include 'modal/add_target.php'; ?>
<?php include 'modal/payroll.php'; ?>
<?php include 'modal/payroll_edit.php'; ?>
<?php include 'modal/addpay.php'; ?>
<?php include 'modal/add_asset2.php'; ?>
<?php include 'modal/todo.php'; ?>
<?php include 'modal/to-do.php'; ?>
<?php include 'modal/addToDo.php'; ?>
<?php include 'modal/assignTodo.php'; ?>
<?php include 'modal/rebatch.php'; ?>
<?php include 'modal/assess.php'; ?>
<?php include 'modal/member.php'; ?>
<?php include 'modal/prevmonth_report.php'; ?>
<?php include 'modal/edit_inventory.php'; ?>
<?php include 'modal/addTeacher.php'; ?>
<?php include 'modal/form.php'; ?>
<?php include 'modal/teacherReject.php'; ?>
<?php include 'modal/interview_observation.php'; ?>
<?php include 'modal/create_observation.php'; ?>
<?php include 'modal/assignTeacherApplicant.php'; ?>
<?php include 'modal/available_time.php'; ?>
<?php include 'modal/zoom_meeting.php'; ?>
<?php include 'modal/formRender.php'; ?>
<?php include 'modal/addTrainingVideo.php'; ?>
<?php include 'modal/form_feedback.php'; ?>
<?php // include 'modal/topic.php'; ?>
<?php // include 'modal/module.php'; ?>
<?php // include 'modal/session_action.php'; ?>
<?php // include 'modal/session_master.php'; ?>
<?php // include 'modal/session_link.php'; ?>
<?php // include 'modal/session_transfer_request.php'; ?>
<?php // include 'modal/exam.php'; ?>
<?php // include 'modal/assignment.php'; ?>
<?php // include 'modal/exam_add.php'; ?>
<?php // include 'modal/exam_question.php'; ?>
<?php // include 'modal/assignmentAdd.php'; ?>
<?php // include 'modal/transfer_request.php'; ?>
<?php // include 'modal/mou.php'; ?>
<?php // include 'modal/feedback.php'; ?>
<?php // include 'modal/feedback_msg.php'; ?>
<?php // include 'modal/viewRefMaterial.php'; ?>
<?php include 'modal/batchClassLevelUpdate.php'; ?>
<?php // include 'modal/assignmentCheck.php'; ?>
<?php // include 'modal/batchReport.php'; ?>
<?php include 'modal/inquiry_transfer.php'; ?>
<?php include 'modal/addRemark.php'; ?>
<?php include 'modal/quick_comment.php'; ?>
<?php include 'modal/classActivity.php'; ?>
<?php include 'modal/classMaterial.php'; ?>
<?php include 'modal/invoiceEdit.php'; ?>
<?php include 'modal/batchFinder.php'; ?>
<?php include 'modal/batchFinderStudent.php'; ?>
<?php include 'modal/assetForm.php'; ?>
<?php include 'modal/assignCounsellor.php'; ?>
<?php include 'modal/assignCounsellorMember.php'; ?>
<?php include 'modal/transfer_membership.php'; ?>
<?php include 'modal/student_tutorial.php'; ?>
<?php include 'modal/copyPackage.php'; ?>
<?php include 'modal/employeeZoomLink.php'; ?>
<?php include 'modal/receipt_upload.php'; ?>
<?php include 'modal/inquirySource.php';?>
<?php include 'modal/inquiryLog.php'; ?>
<?php include 'modal/attendance_invalid.php'; ?>
<?php include 'modal/employee.php'; ?>
<?php include 'modal/batchClassLevelForward.php'; ?>
<?php include 'modal/venue.php'; ?>
<?php include 'modal/venueBranch.php'; ?>
<?php include 'modal/inventory_quantity.php'; ?>
<?php include 'modal/inventory_bulk_quantity.php'; ?>
<?php include 'modal/inventory_printed.php'; ?>
<?php include 'modal/print_view.php'; ?>
<?php include 'modal/demoFormResponse.php'; ?>
<?php include 'modal/attRequest.php'; ?>
<?php include 'modal/attRequestUpdate.php'; ?>
<?php include 'modal/teacherDoc.php'; ?>
<?php include 'modal/product_log.php'; ?>
<?php include 'modal/teacher_form.php'; ?>
<?php include 'modal/editSchedule.php'; ?>
<?php include 'modal/createInquiryLink.php'; ?>
<?php include 'modal/createInquiryLinkBatchPackage.php'; ?>
<?php include 'modal/editTeacherApplicantBatch.php'; ?>
<?php include 'modal/leaveDetails.php'; ?>
<?php include 'modal/transfer_memebership_approval.php'; ?>
<?php include 'modal/generateRenewalLink.php'; ?>
<?php include 'modal/venueRentDetail.php'; ?>
<?php include 'modal/addTrainingBatch.php'; ?>
<?php include 'modal/assignmentRatings.php'; ?>
<?php include 'modal/edit_attendance.php'; ?>
<?php include 'modal/dropout_students_details.php'; ?>
<?php include 'modal/updateInventoryStock.php'; ?>
<?php include 'modal/createInvoice.php'; ?>
<?php include 'modal/createIMInvoice.php'; ?>
<?php include 'modal/couponAcutalPaymentReport.php'; ?>
<?php include 'modal/employeeExpense.php' ; ?>
<?php include 'modal/addNewOwner.php' ; ?>
<?php include 'modal/job_applicant.php' ; ?>

<!-- TT modals -->
<?php include 'modal/editDueDate.php'; ?>
<?php include 'modal/teacherNdaSend.php'; ?>
<?php include 'modal/assignBranch.php'; ?>
<?php include 'modal/preserveAttribute.php'; ?>

<?php } ?>

<!-- issue modal -->
<!-- Jquery plugins and custom javascript code -->
<script src="js/plugins.js"></script>
<script src="js/ckeditor/ckeditor.js"></script>
<!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/ng-ckeditor/0.2.1/ng-ckeditor.min.js"></script> -->
<script src="js/lib/ng-ckeditor.min.js"></script>
<!-- <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.2.1/Chart.js" ></script> -->
<!-- <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js"></script> -->
<script src="js/lib/Chart.min.js"></script>
<!-- <script type="text/javascript" src="js/Chart.bundle.js"></script> -->
<!-- <script src="https://cdn.jsdelivr.net/angular.chartjs/latest/angular-chart.js"></script>  -->
<script src="js/lib/angular-chart.js"></script>
<script src="js/main.js"></script>
<script type="text/javascript" src="js/intro.js"></script>

<!-- disable button if request is submitted for all pages Yogesh -->
<script>
var fewSeconds = 4;
$('#sbtn').click(function(){
    // Ajax request
    var btn = $(this);
    btn.prop('disabled', true);
    setTimeout(function(){
        btn.prop('disabled', false);
    }, fewSeconds*1000);
    // $(this).button('loading');
    // data-loading-text="<i class='fa fa-spinner fa-spin'></i> Processing"
});
$('.sbtn').click(function(){
    // Ajax request
    var btn = $(this);
    btn.prop('disabled', true);
    setTimeout(function(){
        btn.prop('disabled', false);
    }, fewSeconds*1000);
    // $(this).button('loading');
    // data-loading-text="<i class='fa fa-spinner fa-spin'></i> Processing"
});
</script>

<script type="text/javascript">

    function openModal(id,type,permission = 1,action,url = 'dashboard.php',redirect = 0, scope = undefined, identifier = undefined){
        if(action == 1){
            $("#modal-delete").modal("show");
            angular.element("#modal-delete").scope().initDelete(id,type,permission,url,redirect,1);
        }
        else if(action == 11){
            $("#modal-delete").modal("show");
            angular.element("#modal-delete").scope().initDelete(id,type,permission,url,redirect,0);
        }
        else if(action == 12){
            $("#modal-delete").modal("show");
            angular.element("#modal-delete").scope().initDelete(id,type,permission,url,redirect,2); 
        }
        else if(action == 14){ //Cancel Invoice
            console.log("Over Here");
           $("#modal-delete").modal("show");
            angular.element("#modal-delete").scope().initDelete(id,type,permission,url,redirect,3); 
        }
        else if(action == 15){
            $("#modal-"+type).modal("show");
            angular.element("#modal-"+type).scope().duplicate(id,permission,action, scope, identifier);
        }
        else if(action == 16){
            $("#modal-create_employee").modal("show");
            angular.element("#modal-create_employee").scope().create(id,type,permission,url,redirect,2);
        }
        else if(action == 17){ //Delete Task
           $("#modal-to-do").modal("show");
           angular.element("#modal-to-do").scope().initToDo(id,type,permission,url,redirect,1); 
        }
        else if(action == 18){ //Completed Task
           $("#modal-to-do").modal("show");
           angular.element("#modal-to-do").scope().initToDo(id,type,permission,url,redirect,2); 
        }
        else if(action == 19){ //Approve Task
           $("#modal-to-do").modal("show");
            angular.element("#modal-to-do").scope().initToDo(id,type,permission,url,redirect,3); 
        }
        else if(action == 20){ //Reject Task
            $("#modal-to-do").modal("show");
            angular.element("#modal-to-do").scope().initToDo(id,type,permission,url,redirect,5); 
        }
        else if(action == 23){
            $("#modal-delete").modal("show");
            angular.element("#modal-delete").scope().initDelete(id,type,permission,url,redirect,3);
        }
        else{
            $("#modal-"+type).modal("show");
            var targetScope = angular.element("#modal-"+type).scope();
            if (targetScope && typeof targetScope.update === 'function') {
                targetScope.update(id,permission,action, scope, identifier);
            }
        }
          
    }

    // Multi-modal stacking handler for nested modals
    $(document).on('show.bs.modal', '.modal', function () {
        var visibleModals = $('.modal:visible').length;
        if (visibleModals > 0) {
            var zIndex = 5000 + (20 * visibleModals);
            $(this).css('z-index', zIndex);
            setTimeout(function() {
                $('.modal-backdrop').not('.modal-stack').css('z-index', zIndex - 1).addClass('modal-stack');
            }, 0);
        }
    });
    $(document).on('hidden.bs.modal', '.modal', function () {
        if ($('.modal:visible').length > 0) {
            $('body').addClass('modal-open');
        }
    });
</script>