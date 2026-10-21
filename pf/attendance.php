<?php include 'inc/config.php'; // Configuration php file ?>
<?php $_SESSION['face_csrf']=$_SESSION['face_csrf'] ?? bin2hex(random_bytes(32)); ?>
<?php
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';
require_once dirname(__DIR__) . '/classes/services/FaceSelectionGate.php';
require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
$faceConfig = FaceAttendanceConfig::get();
$face_attendance_ui_enabled = !empty($faceConfig['FACE_ATTENDANCE_ENABLED']);
$face_selection_ui_enabled = false;
$sessionDbName = !empty($_SESSION['db_name']) ? $_SESSION['db_name'] : 'ccc_database';
if ($face_attendance_ui_enabled && preg_match('/^ccc_database(?:_test)?$/', (string)$sessionDbName)) {
    try {
        $selectionDb = (new dbconnection())->connect($sessionDbName);
        $face_selection_ui_enabled = FaceSelectionGate::enabled($selectionDb);
        $selectionDb->close();
    } catch (Throwable $exception) { $face_selection_ui_enabled = false; }
}
$face_punch_out_ui_enabled  = !empty($faceConfig['FACE_PUNCH_OUT_ENABLED']);
$face_enrollment_ui_enabled = !empty($faceConfig['FACE_ENROLLMENT_ENABLED']);
$face_guided_adaptive_v2_enabled = !empty($faceConfig['FACE_GUIDED_ADAPTIVE_V2_ENABLED']);
$face_recalibration_ui_enabled = !empty($faceConfig['FACE_RECALIBRATION_ENABLED']);
$browser_face_pilot=false;
$browser_face_available=false;
try{
    require_once dirname(__DIR__).'/classes/services/BrowserFacePolicy.php';
    $browserDb=(new dbconnection)->connect($sessionDbName);
    $browser_face_available=BrowserFacePolicy::installed($browserDb);$browserDb->close();
}catch(Throwable $e){$browser_face_available=false;}
if($browser_face_available)$face_enrollment_ui_enabled=true;
if(($_GET['face_pilot'] ?? '')==='1'){
    require_once dirname(__DIR__).'/classes/services/BrowserFacePilot.php';
    try{$pilot=BrowserFacePilot::load($sessionDbName,(int)$_SESSION['bid']);$pilotDb=(new dbconnection)->connect($pilot['database']);BrowserFacePilot::verify($pilotDb,$pilot);$pilotDb->close();$browser_face_pilot=true;}
    catch(Throwable $e){http_response_code(503);echo 'Test camera is not available. Contact the administrator.';exit;}
}
?>
<?php if($browser_face_pilot)$_GET['mod']=0; // Practice camera has no legacy write dialogs. ?>
<?php include 'inc/top.php'; // Meta data and header ?>
<?php include 'inc/side.php'; // Navigation content ?>
<?php if($browser_face_pilot): ?><p class="alert alert-info">Practice attendance — consenting test members only. These results do not change real attendance or memberships.</p><?php endif; ?>
<script>window.browserFacePilot=<?php echo $browser_face_pilot?'true':'false'; ?>;window.browserFaceEnabled=<?php echo $browser_face_available?'true':'false'; ?>;</script>

<link rel="stylesheet" href="css/browser-face-embedded.css?v=<?php echo filemtime(__DIR__ . '/css/browser-face-embedded.css'); ?>">
<?php if($browser_face_available): // Fetch the face module graph in parallel instead of import-by-import. Keep in step with the import URLs. ?>
<?php foreach(['attendance.mjs?v=frictionless-20261016-1','embedded.mjs?v=frictionless-20261016-1','messages.mjs?v=frictionless-20261016-1','capture.mjs?v=testing-20261013-1','facing.mjs?v=testing-20261013-1','api.mjs?v=stability-20261010-final-2','core.mjs?v=stability-20261010-final','continuity.mjs?v=testing-20261012-2'] as $faceModule): ?>
<link rel="modulepreload" href="js/browser-face/<?php echo $faceModule; ?>">
<?php endforeach; endif; ?>
<style>
/* Uncompiled AngularJS markup must never be visible. AngularJS injects its own
   copy of this rule only after angular.min.js has executed, so a slow, failed
   or blocked script would otherwise leave raw {{ }} and every ng-if branch on
   screen. Keep this rule in the page itself, ahead of the markup it protects. */
[ng-cloak], [data-ng-cloak], [x-ng-cloak], .ng-cloak, .x-ng-cloak { display:none !important; }
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
[data-input="radio"]:checked + label[data-value="skip"]:after,
[data-input="radio"]:not(:checked) + label[data-value="skip"]:after {
    content: '';
    width: 12px;
    height: 12px;
    background: orange;
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
[data-input="radio"]:disabled + label {
    cursor: not-allowed;
    opacity: 0.65;
    pointer-events: none;
}

.face-scan-card {
    clear: both;
    margin: 18px 0 22px;
    padding: 18px;
    border: 1px solid #dce5ef;
    border-radius: 12px;
    background: #f8fbff;
    box-shadow: 0 6px 20px rgba(37, 72, 110, .08);
}
.face-camera-frame {
    position: relative;
    width: 100%;
    max-width: 460px;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    border: 3px solid #d9e3ed;
    border-radius: 12px;
    background: #101820;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,.08);
}
.face-camera-frame video {
    display: block;
    width: 100%;
    height: 100%;
    object-fit:contain;
    background: #101820;
    transition: transform 0.15s ease-in-out;
}
.face-camera-frame video.face-video-mirrored {
    transform: scaleX(-1);
    -webkit-transform: scaleX(-1);
}
.face-enrollment-tracker-overlay { position:absolute; inset:0; width:100%; height:100%; z-index:3; pointer-events:none; }
.face-enrollment-card {
    clear: both;
    margin: 22px 0;
    padding: 28px 18px 30px;
    border: 0;
    border-radius: 28px;
    background: radial-gradient(circle at 50% 0%, #f5fbff 0, #edf4f8 46%, #f8fafc 100%);
    box-shadow: 0 16px 40px rgba(23, 43, 77, .12);
}
.face-enrollment-experience { width: 100%; max-width: 680px; margin: 0 auto; }
.face-enrollment-heading { display: flex; align-items: center; justify-content: space-between; gap: 14px; margin: 0 auto 18px; }
.face-enrollment-heading h3 { margin: 0; font-size: 21px; }
.faceid-viewport { width: 100%; max-width: 600px; margin: 0 auto; }
.face-enrollment-card #faceEnrollmentFrame {
    width: 100%;
    max-width: 600px;
    margin: 0 auto;
    aspect-ratio: 1 / 1;
    border: 0;
    border-radius: 24px;
    background: #0a121b;
    box-shadow: 0 22px 42px rgba(13, 28, 45, .28), inset 0 0 0 1px rgba(255,255,255,.14);
    isolation: isolate;
}
.face-enrollment-card #faceEnrollmentFrame video { object-fit: cover; }
.face-enrollment-card .face-enrollment-tracker-overlay { z-index: 3; }
.faceid-background-scrim {
    position: absolute;
    z-index: 2;
    inset: 0;
    pointer-events: none;
    background: rgba(7, 17, 29, .44);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    -webkit-mask-image: radial-gradient(ellipse 35% 43% at 50% 49%, transparent 0 91%, #000 100%);
    mask-image: radial-gradient(ellipse 35% 43% at 50% 49%, transparent 0 91%, #000 100%);
}
@supports not ((mask-image: radial-gradient(#000, transparent)) or (-webkit-mask-image: radial-gradient(#000, transparent))) {
    /* Preserve a clear camera view on older browsers rather than blur the face. */
    .faceid-background-scrim { background: transparent; backdrop-filter: none; -webkit-backdrop-filter: none; }
}
.faceid-ring {
    position: absolute;
    z-index: 4;
    inset: 11%;
    width: 78%;
    height: 78%;
    overflow: visible;
    pointer-events: none;
}
.faceid-ring-segment {
    fill: none;
    stroke: rgba(255,255,255,.30);
    stroke-width: 8px;
    stroke-linecap: round;
    vector-effect: non-scaling-stroke;
    transform-origin: 160px 160px;
    transition: stroke .3s ease, stroke-width .3s ease, filter .3s ease, opacity .3s ease;
}
.faceid-ring-segment.is-active { stroke: rgba(255,255,255,.92); stroke-width: 9px; filter: drop-shadow(0 0 7px rgba(255,255,255,.38)); }
.faceid-ring-segment.is-complete { stroke: #34C759; stroke-width: 10px; filter: drop-shadow(0 0 8px rgba(52,199,89,.85)); }
.faceid-guidance-pill {
    position: absolute;
    z-index: 5;
    right: 18px;
    bottom: 18px;
    left: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 9px 16px;
    border: 1px solid rgba(255,255,255,.14);
    border-radius: 22px;
    color: #fff;
    background: rgba(0,0,0,.60);
    box-shadow: 0 6px 18px rgba(0,0,0,.22);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.25;
    text-align: center;
}
.faceid-guidance-pill.is-ready { background: rgba(20, 111, 58, .72); }
.faceid-guidance-pill.is-warning { background: rgba(0, 0, 0, .67); }
.faceid-guidance-pill.is-stopped { background: rgba(44, 55, 68, .80); }
.faceid-progress-label { margin: 14px 0 8px; color: #35485b; font-size: 14px; font-weight: 700; text-align: center; }
.faceid-hold-meter { width: min(360px, 90%); height: 5px; margin: 0 auto 12px; overflow: hidden; border-radius: 999px; background: rgba(26, 61, 91, .16); }
.faceid-hold-meter-fill { height: 100%; border-radius: inherit; background: #34C759; transition: width .12s linear; }
.face-enrollment-card .face-enrollment-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 9px; margin: 18px auto 0; }
.face-enrollment-card .face-enrollment-actions .btn { min-width: 132px; border-radius: 22px; }
.face-enrollment-support { max-width: 480px; margin: 18px auto 0; }
.face-enrollment-support .face-reference-badge { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border: 1px solid #c2e7d0; border-radius: 14px; background: rgba(240, 249, 244, .88); }
.face-enrollment-support .face-reference-badge img,
.face-reference-avatar { width: 52px; height: 52px; flex: 0 0 52px; border: 2px solid #27ae60; border-radius: 50%; object-fit: cover; }
.face-reference-avatar { display: flex; align-items: center; justify-content: center; color: #27ae60; background: #e8f8f0; font-size: 24px; }
.face-enrollment-support .face-reference-badge strong,
.face-enrollment-support .face-reference-badge small { display: block; }
.face-enrollment-support .face-reference-badge small { margin-top: 2px; color: #a06a00; }
.faceid-notice { margin-top: 12px; padding: 10px 14px; border: 1px solid #d7e5ef; border-radius: 14px; color: #31506b; background: rgba(255,255,255,.82); text-align: center; }
.faceid-notice.is-danger { border-color: #efb8b8; color: #9f2222; background: #fff4f4; }
.faceid-notice .btn-link { padding: 0 3px; color: inherit; font-weight: 700; }
@media (max-width: 600px) {
    .face-enrollment-card { padding: 20px 10px 24px; border-radius: 18px; }
    .face-enrollment-heading { align-items: flex-start; flex-direction: column; }
    .faceid-ring { inset: 8%; width: 84%; height: 84%; }
    .faceid-guidance-pill { right: 12px; bottom: 12px; left: 12px; font-size: 13px; }
}
.face-capture-freeze {
    position: absolute;
    z-index: 1;
    inset: 0;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: contain;
    pointer-events: none;
    background: #101820;
}
.face-capture-freeze.is-mirrored {
    transform: scaleX(-1);
    -webkit-transform: scaleX(-1);
}
.face-camera-guide {
    position: absolute;
    z-index: 2;
    /* Safe fallback before camera metadata/JS sizing is available. */
    left: 20%;
    top: 8%;
    width: 60%;
    height: 68%;
    box-sizing: border-box;
    border: 3px dashed rgba(255,255,255,.95);
    border-radius: 50%;
    box-shadow: 0 0 0 999px rgba(0,0,0,.18);
    pointer-events: none;
    transition: border 0.15s ease, box-shadow 0.15s ease;
}
.face-camera-guide.is-ready {
    border: 3px solid #25d366 !important;
    box-shadow: 0 0 0 999px rgba(0,0,0,.22), 0 0 16px rgba(37,211,102,0.5) !important;
}
.face-camera-guide.is-warning {
    border: 3px dashed #f0ad4e !important;
}
.face-camera-frame.is-adaptive-mode .face-camera-guide {
    border: 1.5px dashed rgba(255,255,255,0.18) !important;
    box-shadow: none !important;
    background: transparent !important;
    transition: border-color 0.25s ease, opacity 0.25s ease;
}
.face-camera-frame.is-adaptive-mode .face-camera-guide.is-ready {
    border-color: rgba(37,211,102,0.35) !important;
    box-shadow: none !important;
}
.face-camera-frame.is-adaptive-mode .face-camera-guide.is-warning {
    border-color: rgba(240,173,78,0.3) !important;
    box-shadow: none !important;
}
#faceEnrollmentDetectionBox { display: none !important; }
#faceAttendanceFrame .face-camera-guide { display: none; }
#faceAttendanceDetectionBox {
    position: absolute;
    z-index: 3;
    box-sizing: border-box;
    border: 3px solid #f0ad4e;
    border-radius: 12px;
    box-shadow: 0 0 18px rgba(240,173,78,.55);
    pointer-events: none;
    transition: left .10s linear, top .10s linear, width .10s linear, height .10s linear, border-color .15s ease;
}
#faceAttendanceDetectionBox.is-ready { border-color: #25d366; box-shadow: 0 0 18px rgba(37,211,102,.60); }
.face-camera-guide:after {
    content: 'Position face here';
    position: absolute;
    left: 50%;
    bottom: -32px;
    padding: 3px 9px;
    border-radius: 12px;
    background: rgba(0,0,0,.7);
    color: #fff;
    font-size: 11px;
    white-space: nowrap;
    transform: translateX(-50%);
}
.face-enrollment-card .face-camera-guide:after { display: none; }
.face-camera-guide.is-leave-guide:after {
    display: none !important;
}
.face-leave-guide-banner {
    padding: 10px 14px;
    border-radius: 6px;
    background: #fcf8e3;
    border: 1px solid #faebcc;
    color: #8a6d3b;
    font-size: 13px;
    font-weight: 600;
    text-align: center;
    margin-bottom: 8px;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.face-leave-guide-banner.is-ready {
    background: #dff0d8;
    border-color: #d6e9c6;
    color: #3c763d;
}
.face-leave-guide-hold-bar {
    width: 100%;
    height: 6px;
    background: rgba(0, 0, 0, 0.1);
    border-radius: 3px;
    overflow: hidden;
}
.face-leave-guide-hold-fill {
    height: 100%;
    background: #f0ad4e;
    transition: width 0.1s linear;
}
.face-leave-guide-banner.is-ready .face-leave-guide-hold-fill {
    background: #5cb85c;
}
.face-camera-feedback {
    position: absolute;
    z-index: 3;
    left: 10px;
    right: 10px;
    bottom: 10px;
    padding: 9px 12px;
    border-radius: 8px;
    color: #fff;
    background: rgba(33, 47, 61, .92);
    font-size: 13px;
    font-weight: 600;
}
.face-camera-feedback.is-ready { background: rgba(24, 126, 72, .94); }
.face-camera-feedback.is-warning { background: rgba(181, 105, 0, .94); }
.face-camera-feedback.is-stopped { background: rgba(70, 80, 90, .94); }
.face-enrollment-card .face-camera-feedback {
    position: static;
    margin-top: 8px;
    min-height: 44px;
    display: flex;
    align-items: center;
}
.face-pose-visual {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 12px;
    width: 100%;
    min-height: 68px;
    margin-top: 8px;
    padding: 6px;
    border: 1px solid #d8e7df;
    border-radius: 8px;
    background: #fff;
    color: #176a3a;
}
.face-pose-frame { display: flex; flex-direction: column; align-items: center; gap: 2px; font-size: 12px; font-weight: 600; }
.face-pose-frame svg { width: 48px; height: 48px; flex: none; }
.face-pose-frame.is-target .face-pose-features { animation: face-pose-turn 1.8s ease-in-out infinite alternate; }
.face-pose-visual.is-left .is-target .face-pose-features { --pose-x: -5px; --pose-y: 0px; }
.face-pose-visual.is-right .is-target .face-pose-features { --pose-x: 5px; --pose-y: 0px; }
.face-pose-visual.is-up .is-target .face-pose-features { --pose-x: 0px; --pose-y: -4px; }
.face-pose-visual.is-down .is-target .face-pose-features { --pose-x: 0px; --pose-y: 4px; }
.face-pose-visual.is-centre .is-target .face-pose-features { --pose-x: 0px; --pose-y: 0px; }
@keyframes face-pose-turn { from { transform: translate(0, 0); } to { transform: translate(var(--pose-x), var(--pose-y)); } }
.face-pose-arrow { font-size: 25px; line-height: 1; font-weight: 700; }
.face-pose-markers { display: flex; flex-wrap: wrap; gap: 6px 10px; margin-top: 5px; }
.face-pose-marker-group { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; }
.face-pose-marker-group strong { margin-right: 2px; }
.face-pose-dot { border: 2px solid #60736a; border-radius: 50%; width: 20px; height: 20px; text-align: center; line-height: 16px; font-size: 11px; }
.face-pose-dot.is-done { background: #176a3a; border-color: #176a3a; color: white; }
.face-pose-tip { margin: 6px 0; padding: 8px; border-left: 3px solid #176a3a; background: #f1f8f3; }
.face-pose-tip svg { width: 26px; height: 26px; margin-right: 6px; vertical-align: middle; color: #176a3a; }
@media (prefers-reduced-motion: reduce) { .face-pose-frame.is-target .face-pose-features { animation: none; transform: translate(var(--pose-x), var(--pose-y)); } }
.face-camera-flip-btn {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 4;
    background: rgba(15, 23, 42, 0.78);
    color: #ffffff !important;
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 20px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 600;
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: all 0.15s ease-in-out;
}
.face-camera-flip-btn:hover,
.face-camera-flip-btn:focus {
    background: rgba(15, 23, 42, 0.95);
    color: #ffffff !important;
    border-color: rgba(255, 255, 255, 0.7);
}
.face-camera-flip-btn:active {
    transform: scale(0.96);
}
.face-camera-toggle-btn {
    font-size: 11.5px;
    font-weight: 600;
    border-radius: 14px;
    padding: 3px 10px;
}
.face-heading-actions {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.face-scan-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 10px;
}
.face-scan-heading h3 { margin: 0; font-size: 20px; }
.face-status-pill {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    background: #e8eef5;
    color: #455565;
    font-size: 12px;
    font-weight: 700;
}
.face-status-pill.is-ready, .face-status-pill.is-success { background: #dff3e7; color: #176a3a; }
.face-status-pill.is-working { background: #dff1fb; color: #176789; }
.face-status-pill.is-warning { background: #fff0cf; color: #855600; }
.face-status-pill.is-danger { background: #fbe2e2; color: #9b2929; }
.face-status-pill.is-stopped { background: #e9ecef; color: #59636c; }
.face-capture-steps, .face-enrollment-steps {
    margin: 14px 0;
    padding: 12px 14px 12px 34px;
    border-radius: 8px;
    background: #fff;
    color: #415365;
}
.face-capture-steps li, .face-enrollment-steps li { margin: 5px 0; padding-left: 3px; }
.face-enrollment-card { border-color: #d8e7df; background: #f8fcfa; }
.face-enrollment-card .face-enrollment-actions {
    margin-top: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.face-enrollment-card .face-enrollment-actions .btn {
    width: 100%;
    min-height: 44px;
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    display: flex;
    align-items: center;
    justify-content: center;
}
.face-enrollment-actions .btn { margin: 0 6px 6px 0; }
.face-enrollment-progress { height: 22px; margin: 10px 0 14px; background: #e8edf1; }
.face-enrollment-progress .progress-bar { line-height: 22px; min-width: 2em; }
.face-result-list { margin: 10px 0 0; padding: 10px 12px; border-radius: 7px; background: #fff; }
.face-result-list.is-review { border-left: 4px solid #f0ad4e; }
.face-result-list.is-accepted { border-left: 4px solid #2ca45c; }
.face-safety-note { margin-top: 12px; color: #687887; font-size: 12px; }
.face-result-dialog { border-top: 5px solid #3498db; }
.face-result-dialog.is-success { border-top-color: #2ca45c; }
.face-result-dialog.is-warning { border-top-color: #f0ad4e; }
.face-result-dialog.is-danger { border-top-color: #d9534f; }
.face-enrollment-issue {
    background: #ffffff;
    border: 1px solid #f1c3c7;
    border-left: 5px solid #d9534f;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(217, 83, 79, 0.1);
    padding: 14px 16px;
    margin: 10px 0 16px;
    color: #2c3e50;
}
.face-enrollment-issue .issue-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
}
.face-enrollment-issue .issue-title-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.face-enrollment-issue .issue-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #fde8e8;
    color: #d9534f;
    font-size: 13px;
}
.face-enrollment-issue .issue-title {
    font-size: 15px;
    font-weight: 700;
    color: #b02a37;
    margin: 0;
}
.face-enrollment-issue .issue-conflict-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    background: #fbe2e2;
    color: #a71d2a;
    font-size: 12px;
    font-weight: 600;
}
.face-enrollment-issue .close {
    font-size: 20px;
    color: #a71d2a;
    opacity: 0.6;
    transition: opacity .15s ease-in-out;
}
.face-enrollment-issue .close:hover {
    opacity: 1;
}
.face-enrollment-issue .issue-summary {
    font-size: 13.5px;
    color: #495057;
    margin-bottom: 10px;
    line-height: 1.4;
}
.face-enrollment-issue .issue-actions-box {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}
.face-enrollment-issue .issue-action-item {
    flex: 1;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 6px;
    background: #fdf6f6;
    border: 1px solid #fae1e3;
    font-size: 12.5px;
    color: #333;
}
.face-enrollment-issue .issue-action-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #d9534f;
    color: #fff;
    font-weight: 700;
    font-size: 11px;
    flex-shrink: 0;
}
.face-enrollment-issue .issue-action-text {
    line-height: 1.35;
}
.face-enrollment-issue .issue-help-note {
    font-size: 11.5px;
    color: #721c24;
    background: #fbf0f1;
    padding: 6px 10px;
    border-radius: 5px;
    line-height: 1.35;
}
.face-enrollment-issue .issue-help-note i {
    margin-right: 4px;
    color: #d9534f;
}

/* ========================================================
   Native Mobile & Tablet Design System for Attendance Page
   ======================================================== */

.attendance-filter-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 18px 20px 14px;
    margin: 10px 0 16px;
    box-shadow: 0 2px 10px rgba(37, 72, 110, .05);
}
.attendance-filter-card .form-group {
    margin-bottom: 0;
}
.attendance-filter-grid {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 12px;
}
.attendance-filter-grid .filter-col {
    flex: 1 1 200px;
    min-width: 160px;
}
.attendance-filter-grid .filter-btn-col {
    flex: 0 0 auto;
    min-width: 160px;
}
.attendance-filter-grid .form-control,
.attendance-filter-grid .input-group-btn .btn {
    height: 42px;
    border-radius: 8px;
    font-size: 13.5px;
}
.attendance-filter-grid .input-group {
    width: 100%;
}
.attendance-filter-grid .input-group .input-group-btn:first-child .btn {
    border-top-right-radius: 0 !important;
    border-bottom-right-radius: 0 !important;
    border-top-left-radius: 8px !important;
    border-bottom-left-radius: 8px !important;
}
.attendance-filter-grid .input-group .form-control:last-child {
    border-top-left-radius: 0 !important;
    border-bottom-left-radius: 0 !important;
    border-top-right-radius: 8px !important;
    border-bottom-right-radius: 8px !important;
}
.attendance-filter-grid .btn-show-attendees {
    height: 42px;
    font-weight: 700;
    border-radius: 8px;
    padding: 0 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.attendance-actions-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin: 12px 0 18px;
    padding: 2px 0;
}
.attendance-actions-left {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
}
.attendance-actions-right {
    display: none !important;
}
.attendance-actions-bar .block-options-left,
.attendance-actions-bar .block-options {
    float: none !important;
    margin: 0 !important;
    padding: 0 !important;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.attendance-actions-bar .btn {
    min-height: 40px;
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13.5px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    transition: all .15s ease-in-out;
}
.attendance-actions-bar .btn:active {
    transform: scale(0.98);
}

.attendance-table-wrapper {
    width: 100% !important;
    max-width: 100% !important;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
    border: 1px solid #dce5ef;
    border-radius: 10px;
    background: #ffffff;
    box-shadow: 0 2px 10px rgba(37, 72, 110, .04);
    margin-bottom: 20px;
}
.attendance-table-wrapper table {
    margin-bottom: 0 !important;
    min-width: 720px;
}
.attendance-table-wrapper td,
.attendance-table-wrapper th {
    vertical-align: middle !important;
}
.attendance-table-wrapper td.attendance-name-cell {
    min-width: 240px;
    white-space: normal;
    text-align: left !important;
}
.attendance-table-wrapper td.attendance-name-cell .btn,
.attendance-table-wrapper td.attendance-name-cell .label {
    white-space: nowrap;
    margin: 2px 0 2px 4px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.attendance-scroll-hint {
    display: none;
    font-size: 12px;
    color: #475569;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 6px 12px;
    border-radius: 20px;
    margin-bottom: 8px;
    width: fit-content;
}

/* Tablet Viewport (768px - 991px) */
@media (min-width: 768px) and (max-width: 991px) {
    html, body {
        overflow-x: hidden !important;
        max-width: 100vw !important;
    }
    body.header-fixed-top #page-container {
        padding-top: 48px !important;
    }
    #page-container,
    #page-container.full-width {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }
    #page-content {
        margin: 0 !important;
        padding: 14px 18px !important;
        overflow-x: hidden;
    }
    .attendance-filter-grid {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        gap: 12px !important;
    }
    .attendance-filter-grid:before,
    .attendance-filter-grid:after {
        display: none !important;
        content: none !important;
    }
    .attendance-filter-grid .filter-col,
    .attendance-filter-grid .filter-btn-col {
        width: 100% !important;
        min-width: 0 !important;
    }
    .attendance-filter-grid .btn-show-attendees {
        width: 100% !important;
        height: 42px !important;
        font-size: 14px !important;
    }
    .face-scan-card {
        padding: 16px;
    }
    .face-camera-frame {
        max-width: 100%;
        margin: 0 auto;
    }
    .face-scan-heading h3 {
        font-size: 18px;
    }
    .attendance-scroll-hint {
        display: inline-block;
    }
}

/* Mobile Viewport (320px - 767px) */
@media (max-width: 767px) {
    html, body {
        overflow-x: hidden !important;
        width: 100% !important;
        max-width: 100vw !important;
    }
    body.header-fixed-top #page-container {
        padding-top: 85px !important;
        padding-left: 0 !important;
        padding-right: 0 !important;
        padding-bottom: 0 !important;
    }
    #page-container,
    #page-container.full-width {
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
    }
    #page-content {
        margin: 0 !important;
        padding: 10px 12px !important;
        overflow-x: hidden;
    }
    .attendance-filter-card {
        padding: 14px 12px;
        margin: 6px 0 12px;
        border-radius: 10px;
    }
    .attendance-filter-grid {
        display: flex !important;
        flex-direction: column !important;
        gap: 10px !important;
    }
    .attendance-filter-grid:before,
    .attendance-filter-grid:after {
        display: none !important;
        content: none !important;
    }
    .attendance-filter-grid .filter-col,
    .attendance-filter-grid .filter-btn-col {
        width: 100% !important;
        flex: 1 1 100% !important;
        min-width: 0 !important;
    }
    .attendance-filter-grid .form-control,
    .attendance-filter-grid .input-group-btn .btn {
        height: 44px !important;
        font-size: 14.5px !important;
    }
    .attendance-filter-grid .btn-show-attendees {
        width: 100% !important;
        height: 44px !important;
        font-size: 15px !important;
        margin: 0 !important;
    }
    .attendance-actions-bar {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        margin: 10px 0 14px !important;
        padding: 0 !important;
    }
    .attendance-actions-left,
    .attendance-actions-right {
        width: 100% !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .attendance-actions-bar .block-options-left,
    .attendance-actions-bar .block-options {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        float: none !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 8px !important;
    }
    .attendance-actions-bar .btn {
        width: 100% !important;
        min-height: 44px !important;
        font-size: 14.5px !important;
        justify-content: center !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    .attendance-actions-bar .btn-group {
        width: 100% !important;
        margin: 0 !important;
    }
    .attendance-actions-bar .btn-group > .btn,
    .attendance-actions-bar .btn-group > select,
    .attendance-actions-bar .btn-group > .form-control {
        width: 100% !important;
        min-height: 44px !important;
        border-radius: 8px !important;
        margin: 0 !important;
        box-sizing: border-box !important;
    }
    .attendance-actions-right .dropdown-menu {
        width: 100%;
        min-width: 100%;
        float: none;
        position: static;
        margin-top: 4px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,.08);
    }
    .face-scan-card {
        padding: 14px 12px;
        margin: 10px 0 16px;
        border-radius: 10px;
    }
    .face-camera-frame {
        max-width: 100%;
        margin: 0 auto 12px;
    }
    .face-camera-feedback {
        left: 6px;
        right: 6px;
        bottom: 6px;
        padding: 7px 8px;
        font-size: 11.5px;
        text-align: center;
    }
    .face-camera-flip-btn {
        top: 8px;
        right: 8px;
        padding: 4px 10px;
        font-size: 11px;
    }
    .face-heading-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        width: 100%;
    }
    .face-scan-heading {
        align-items: flex-start;
        flex-direction: column;
        gap: 6px;
    }
    .face-scan-heading h3 {
        font-size: 17px;
    }
    .face-capture-steps, .face-enrollment-steps {
        margin: 10px 0;
        padding: 10px 12px 10px 24px;
        font-size: 12px;
    }
    .face-enrollment-actions {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    .face-enrollment-actions .btn {
        width: 100%;
        min-height: 44px;
        margin: 0;
        font-size: 14px;
        justify-content: center;
    }
    .face-enrollment-issue .issue-actions-box {
        flex-direction: column;
    }
    .face-enrollment-issue {
        padding: 12px;
    }
    .attendance-scroll-hint {
        display: inline-block;
    }
    [data-input="radio"] + label {
        padding-left: 28px;
        min-height: 30px;
        display: inline-flex;
        align-items: center;
        font-size: 13px;
        margin: 2px 0;
    }
    .attendance-table-wrapper .table > thead > tr > th,
    .attendance-table-wrapper .table > tbody > tr > td {
        padding: 8px 6px;
        font-size: 12.5px;
    }
}

.attendance-field-label { display:block; margin:0 0 6px; color:#40505f; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.attendance-selection-help { margin-top:12px; color:#506171; }
.attendance-test-reset { display:flex; align-items:center; gap:10px; margin-top:14px; padding-top:12px; border-top:1px solid #e5eaee; color:#6c4b4b; font-size:12px; }
.attendance-stage-panel, .attendance-review-card, .attendance-complete-card { margin:16px 0; padding:20px; border:1px solid #dce4ea; border-radius:10px; background:#fff; }
.attendance-stage-panel.is-loading { color:#176789; text-align:center; }
.attendance-stage-panel.is-error { color:#9b2929; border-color:#efcaca; background:#fff7f7; }
.attendance-primary-actions { display:flex; margin:16px 0; padding:14px; border-radius:10px; background:#f5f8fa; }
.attendance-primary-actions .btn, .attendance-review-actions .btn, .attendance-scan-actions .btn { min-height:44px; margin:3px 6px 3px 0; }
.attendance-secondary-action { color:#687887; }
.face-current-instruction { min-height:44px; margin:16px 0; font-size:17px; line-height:1.5; }
.face-help { margin:12px 0; color:#5d6c78; }
.face-help summary { cursor:pointer; font-weight:600; }
.face-live-totals { display:flex; gap:12px; margin:14px 0; }
.face-live-totals span { flex:1; padding:10px; border-radius:8px; background:#f5f8fa; text-align:center; }
.attendance-summary-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin:14px 0; }
.attendance-summary-grid > div { padding:14px; border-radius:8px; text-align:center; }
.attendance-summary-grid strong { display:block; font-size:26px; }
.attendance-summary-grid span { display:block; font-size:12px; text-transform:uppercase; }
.attendance-summary-grid .is-present { background:#e5f6ec; color:#176a3a; }
.attendance-summary-grid .is-absent { background:#fbeaea; color:#9b2929; }
.attendance-summary-grid .is-not-ready { background:#fff3d6; color:#855600; }
.attendance-complete-card { text-align:center; border-color:#bde1c9; background:#f4fcf7; }
.attendance-complete-card > .fa { color:#2ca45c; font-size:44px; }
.attendance-roster-summary { display:flex; gap:8px; flex-wrap:wrap; margin:10px 0; }
.attendance-roster-summary span, .attendance-status { display:inline-block; padding:6px 10px; border-radius:999px; font-weight:600; }
.attendance-status.is-present, .attendance-roster-summary .is-present { color:#176a3a; background:#e5f6ec; }
.attendance-status.is-absent, .attendance-roster-summary .is-absent { color:#9b2929; background:#fbeaea; }
.attendance-status.is-not-ready, .attendance-roster-summary .is-not-ready { color:#855600; background:#fff3d6; }
.attendance-status.is-recorded { color:#176789; background:#e4f3fa; }
.attendance-table-wrapper .attendance-focused-table { min-width: 0; width: 100%; }
.attendance-focused-table th, .attendance-focused-table td { vertical-align:middle !important; }
.attendance-focused-table th:first-child { width:45%; }
.attendance-manual-controls label { margin-right:14px; }
@media (max-width: 767px) {
    .attendance-primary-actions, .attendance-primary-actions > div, .attendance-review-actions, .attendance-scan-actions { display:flex; flex-direction:column; width:100%; }
    .attendance-primary-actions .btn, .attendance-review-actions .btn, .attendance-scan-actions .btn { width:100%; margin:4px 0; }
    .attendance-summary-grid { grid-template-columns:1fr; }
	.attendance-test-reset { align-items:stretch; flex-direction:column; }
    .attendance-focused-table th:first-child { width:auto; }
    .attendance-status { white-space:normal; }
}


/* Attendance page polish (testing-20261012-3): one visual system for filters,
   actions, the member table and status pills. Overrides earlier rules above. */
.attendance-filter-card { padding:16px 18px; }
.attendance-filter-grid { align-items:flex-end; gap:12px 16px; }
.attendance-field-label { margin:0 0 6px; color:#475569; font-size:12px; font-weight:600; letter-spacing:.03em; }
.attendance-filter-grid .form-control, .attendance-filter-grid .input-group-btn .btn, .attendance-field-placeholder { height:44px; }
.attendance-field-placeholder { display:flex; align-items:center; gap:8px; padding:0 12px; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b; font-size:13.5px; background:#f8fafc; }
.attendance-field-placeholder.is-error { color:#9b2929; border-color:#efcaca; background:#fff7f7; justify-content:space-between; }
.attendance-selection-help { margin-top:10px; color:#64748b; font-size:13px; }
.attendance-inline-alert { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin:12px 0; border-radius:8px; }
.attendance-stage-panel { display:flex; align-items:center; justify-content:center; gap:10px; flex-wrap:wrap; min-height:72px; margin:16px 0; padding:16px 20px; border-radius:10px; font-size:15px; }
.attendance-stage-panel.is-loading { color:#334155; background:#f8fafc; border-color:#e2e8f0; }
.attendance-stage-panel.is-empty { color:#475569; }
.attendance-stage-panel.is-error { justify-content:space-between; }

.attendance-primary-actions { display:block; margin:16px 0; padding:14px 16px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; }
.attendance-action-buttons { display:flex; flex-wrap:wrap; gap:10px; }
.attendance-action-meta { display:flex; flex-wrap:wrap; align-items:center; gap:0 12px; color:#64748b; font-size:13px; }
.attendance-action-meta > * { margin-top:10px; }
.attendance-action-status { margin:8px 0 0; color:#14532d; font-weight:600; }
.attendance-mode-pill { display:inline-flex; align-items:center; padding:2px 10px; border-radius:999px; background:#fef3c7; color:#854d0e; font-size:12px; font-weight:700; letter-spacing:.02em; }

/* Buttons: one height, radius and weight; clear disabled and focus states. */
.attendance-primary-actions .btn, .attendance-stage-panel .btn { min-height:44px; margin:0; padding:10px 18px; border-radius:8px; font-size:14px; font-weight:600; }
#page-content .btn:focus-visible { outline:3px solid #93c5fd; outline-offset:2px; }
#page-content .btn[disabled], #page-content .btn.disabled { opacity:.55; box-shadow:none; cursor:not-allowed; }
.attendance-primary-actions .btn-default, .attendance-primary-actions .btn-default[disabled] { background:#fff !important; color:#334155 !important; border:1px solid #cbd5e1 !important; }
.attendance-primary-actions .btn:active:not([disabled]) { transform:translateY(1px); }

/* Member table: quiet header, light row dividers, status pills instead of
   full-row colour. */
.attendance-table-wrapper { border-color:#e2e8f0; transition:opacity .15s ease; }
.attendance-table-wrapper.is-refreshing { opacity:.7; }
.attendance-table-wrapper .attendance-focused-table { min-width:0; }
.attendance-table-wrapper .attendance-focused-table > thead > tr > th { background:#f8fafc !important; color:#475569 !important; border:0 !important; border-bottom:1px solid #e2e8f0 !important; padding:10px 14px !important; font-size:12px !important; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
.attendance-table-wrapper .attendance-focused-table > tbody > tr > th,
.attendance-table-wrapper .attendance-focused-table > tbody > tr > td { border:0 !important; border-top:1px solid #eef2f6 !important; padding:10px 14px !important; background:transparent; font-size:14px; }
.attendance-focused-table > tbody > tr:first-child > th, .attendance-focused-table > tbody > tr:first-child > td { border-top:0 !important; }
.attendance-table-wrapper .attendance-focused-table > tbody > tr > th.attendance-name-cell { color:#0f172a; font-weight:600; background:transparent !important; }
.attendance-focused-table > tbody > tr { box-shadow:inset 3px 0 0 transparent; }
.attendance-focused-table > tbody > tr.is-row-present { box-shadow:inset 3px 0 0 #22c55e; }
.attendance-focused-table > tbody > tr.is-row-pending { box-shadow:inset 3px 0 0 #f59e0b; background:#fffdf5; }
.attendance-focused-table th:first-child { width:auto; }
.attendance-sessions-cell { color:#475569; white-space:nowrap; }
.attendance-face-action { text-align:right; white-space:nowrap; }
.attendance-status { padding:3px 10px; font-size:13px; white-space:nowrap; }
.attendance-status.is-pending { color:#854d0e; background:#fef3c7; }
.attendance-status.is-neutral { color:#475569; background:#f1f5f9; font-weight:500; }
.attendance-status.is-checking { color:#94a3b8; background:transparent; font-weight:500; padding-left:0; }
.attendance-test-tag { display:inline-block; margin-left:6px; padding:1px 7px; border-radius:999px; border:1px solid #e2e8f0; color:#64748b; font-size:11px; font-weight:600; vertical-align:middle; }
.face-status { display:inline-flex; align-items:center; gap:6px; font-size:13.5px; color:#475569; white-space:nowrap; }
.face-status:before { content:""; width:8px; height:8px; border-radius:50%; background:#cbd5e1; }
.face-status.is-enrolled:before { background:#22c55e; }
.face-status.is-saved:before { background:#3b82f6; }
.face-status.is-attention { color:#92400e; }
.face-status.is-attention:before { background:#f59e0b; }
.face-status.is-checking { color:#94a3b8; }
.face-status.is-checking:before { display:none; }
.face-status-note { display:block; margin-top:2px; color:#64748b; font-size:12px; }
.attendance-face-btn { min-height:36px; padding:6px 12px; border-radius:8px; font-weight:600; color:#1d4ed8; border-color:#bfdbfe; background:#fff; }
.attendance-face-btn:hover, .attendance-face-btn:focus { color:#1e40af; background:#eff6ff; border-color:#93c5fd; }
.attendance-consent-note { margin:-10px 0 16px; color:#64748b; font-size:12px; }


body.browser-face-modal-open { overflow:hidden; }

@media (max-width: 767px) {
    .attendance-action-buttons { flex-direction:column; }
    .attendance-primary-actions .btn { width:100%; }
    .attendance-stage-panel.is-error { justify-content:center; text-align:center; }
}
/* Phones: each member becomes a compact card; nothing scrolls sideways. */
@media (max-width: 640px) {
    .attendance-table-wrapper { overflow-x:visible !important; }
    .attendance-focused-table > thead { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0); white-space:nowrap; }
    .attendance-focused-table, .attendance-focused-table > tbody { display:block; width:100%; }
    .attendance-focused-table > tbody > tr { display:grid; grid-template-columns:1fr auto; align-items:center; gap:6px 12px; padding:12px 14px; border-top:1px solid #eef2f6; }
    .attendance-focused-table > tbody > tr:first-child { border-top:0; }
    .attendance-table-wrapper .attendance-focused-table > tbody > tr > th,
    .attendance-table-wrapper .attendance-focused-table > tbody > tr > td { display:block; border:0 !important; padding:0 !important; font-size:14px !important; }
    .attendance-focused-table > tbody > tr > th.attendance-name-cell { grid-column:1 / -1; min-width:0; font-size:15px !important; }
    .attendance-focused-table > tbody > tr > td.attendance-cell:before { content:attr(data-label); display:block; color:#94a3b8; font-size:11px; font-weight:600; letter-spacing:.03em; text-transform:uppercase; }
    .attendance-focused-table > tbody > tr > td.attendance-sessions-cell { text-align:right; }
    .attendance-focused-table > tbody > tr > td.attendance-face-action { grid-column:1 / -1; text-align:left; white-space:normal; }
    .attendance-focused-table > tbody > tr > td.attendance-face-action:before { display:none; }
    .attendance-focused-table > tbody > tr > td.attendance-face-action:empty { display:none; }
    .attendance-face-btn { width:100%; min-height:44px; }
    .attendance-status { white-space:normal; }
    .attendance-manual-controls label { min-height:40px; display:inline-flex; align-items:center; gap:6px; }
}
@media (prefers-reduced-motion: reduce) { .attendance-table-wrapper { transition:none; } }

/* Start-up placeholder, loading skeleton and failure fallback (plain CSS: these
   must render before, and without, AngularJS). */
.attendance-boot { margin:10px 0 16px; }
.attendance-booted .attendance-boot { display:none; }
.attendance-boot [hidden] { display:none !important; }
.attendance-boot-failed .attendance-boot-wait { display:none; }
html.attendance-boot-failed #list-content { display:none !important; }
.attendance-boot-failed-box, #attendanceBootFailed { padding:22px 20px; border:1px solid #efcaca; border-radius:10px; background:#fff7f7; color:#9b2929; text-align:center; }
#attendanceBootFailed p { margin:0 0 12px; font-size:16px; font-weight:600; }
.attendance-boot-reload { min-height:44px; padding:10px 20px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#334155; font-size:14px; font-weight:600; cursor:pointer; }
.attendance-boot-reload:hover, .attendance-boot-reload:focus { background:#f1f5f9; }
.attendance-skeleton-filters { display:flex; flex-wrap:wrap; gap:12px 16px; }
.attendance-skeleton-filters .sk-bar { flex:1 1 200px; height:44px; border-radius:8px; }
.roster-skeleton, .roster-skeleton-card { margin:0; }
.roster-skeleton { margin:16px 0; }
.roster-skeleton-card { border:1px solid #e2e8f0; border-radius:10px; background:#fff; overflow:hidden; }
.roster-skeleton-card .sk-row { display:flex; align-items:center; gap:16px; padding:16px 14px; border-top:1px solid #eef2f6; }
.roster-skeleton-card .sk-row:first-child { border-top:0; }
.attendance-boot .roster-skeleton-card { margin-top:16px; }
.sk-bar { display:block; height:12px; border-radius:6px; background:linear-gradient(90deg,#eef2f6 25%,#e2e8f0 37%,#eef2f6 63%); background-size:400% 100%; animation:attendance-shimmer 1.4s ease infinite; }
.sk-w60 { flex:0 0 38%; } .sk-w40 { flex:0 0 24%; } .sk-w30 { flex:0 0 18%; }
.roster-skeleton-text { margin:10px 0 0; text-align:center; color:#64748b; font-size:14px; }
@keyframes attendance-shimmer { 0% { background-position:100% 50%; } 100% { background-position:0 50%; } }
@media (prefers-reduced-motion: reduce) { .sk-bar { animation:none; } }

</style>


<!-- Pre Page Content -->
<div ng-controller = "batchAttendanceController">
    <!-- <div id="pre-page-content" >
        <h1 ng-cloak><i class="fa fa-calendar-o"></i>Batch attendance<br><small>Ease of attendance</small></h1>
    </div> -->
    

    <!-- Page Content -->
    <div id="page-content" >
        <!-- Breadcrumb -->
        <!-- <ul class="breadcrumb breadcrumb-top">
            <li>
                <a href="dashboard.php"><i class="gi gi-display"></i></a>
            </li>
            <li>
                Batch attendance
            </li>
        </ul> -->

        
      
        <!-- <br> -->
        
        <div id="attendanceBoot" class="attendance-boot" role="status" aria-live="polite">
            <div class="attendance-boot-wait">
                <div class="attendance-filter-card attendance-boot-filters" aria-hidden="true">
                    <div class="attendance-skeleton-filters"><span class="sk-bar"></span><span class="sk-bar"></span><span class="sk-bar"></span></div>
                </div>
                <div class="roster-skeleton-card" aria-hidden="true">
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                </div>
                <p class="roster-skeleton-text">Loading attendance…</p>
            </div>
            <div id="attendanceBootFailed" class="attendance-boot-failed" role="alert" hidden>
                <p>Unable to start attendance.</p>
                <button type="button" class="attendance-boot-reload" onclick="window.location.reload()">Reload Page</button>
            </div>
        </div>
        <script>
        /* Start-up guard. Plain ES5; it must keep working when AngularJS does not. */
        (function () {
            var root = document.documentElement, failedBox = document.getElementById('attendanceBootFailed');
            var booted = false, timers = [];
            // Scripts AngularJS and this page cannot run without; others (QR, barcode...) may fail harmlessly.
            var REQUIRED = /\/(angular|angular-sanitize|angular-strap|angular-strap\.tpl|ui-bootstrap-tpls|ngInfiniteScroll|angular-chosen|ng-ckeditor|angular-chart|Chart|mainController|api_url|batchAttendanceController)(\.min)?\.js(\?|$)/;
            function clear() { while (timers.length) clearTimeout(timers.pop()); }
            function ready() {
                booted = true; clear();
                root.classList.remove('attendance-boot-failed'); root.classList.add('attendance-booted');
                if (failedBox) failedBox.hidden = true;
            }
            function fail() {
                if (booted) return;
                clear(); root.classList.add('attendance-boot-failed');
                if (failedBox) failedBox.hidden = false;
            }
            window.attendanceBoot = { ready: ready, fail: fail };
            // A required script that fails to download can never be recovered by waiting.
            window.addEventListener('error', function (event) {
                var target = event.target;
                if (target && target.tagName === 'SCRIPT' && target.src && REQUIRED.test(target.src)) fail();
            }, true);
            // AngularJS and the module registry are loaded in <head>: if either is absent now, they never will be.
            if (typeof window.angular === 'undefined' || typeof window.app === 'undefined') fail();
            // AngularJS bootstraps at DOMContentLoaded, before "load". If it has no injector by
            // then (for example a missing module) or the controller never reported ready, stop waiting.
            window.addEventListener('load', function () {
                if (booted) return;
                if (typeof window.angular === 'undefined' || !window.angular.element(document.documentElement).injector()) { fail(); return; }
                timers.push(setTimeout(fail, 1500));
            });
            // A hung download is still bounded. If the page later finishes starting, ready() wins.
            timers.push(setTimeout(fail, 45000));
        })();
        </script>

        <div id="list-content" class="" ng-cloak>
          <div id="rosterRegion" ng-attr-data-roster-state="{{rosterState}}">

            <div class="attendance-filter-card">
                <form id="batchAttForm" name="batchAttForm" class="form-horizontal" ng-cloak>
                    <div class="attendance-filter-grid">
                        <div class="filter-col">
                            <label for="batchDate" class="attendance-field-label">Date</label>
                            <div class="input-group">
                                <span class="input-group-btn"><button class="btn btn-default" type="button" tabindex="-1"><i class="fa fa-calendar"></i></button></span>
                                <input type="text" id="batchDate" ng-style="{'border-color': dateStyle}" placeholder="dd-mm-yyyy" name="batchDate" ng-change="dateChange()" ng-model="batch.date" datepicker class="form-control date-typing digits" ng-disabled="support_mode || attendanceStage == 'COMMITTING' || faceTestPunchBusy || faceTestPunchRequest">
                            </div>
                            <span ng-show="showDateError" class="text-danger" ng-cloak>{{dateError}}</span>
                        </div>
                        <div class="filter-col" ng-show="!batch.batches.length && !showDateError">
                            <label class="attendance-field-label">Class</label>
                            <div class="attendance-field-placeholder" ng-if="batchesLoading" aria-live="polite"><i class="fa fa-spinner fa-spin"></i> <span>Loading classes…</span></div>
                            <div class="attendance-field-placeholder is-error" ng-if="!batchesLoading && batchLoadError" role="alert"><span ng-bind="batchLoadError"></span> <button type="button" class="btn btn-default btn-sm" ng-if="batchLoadKind !== 'session'" ng-click="retryBatchLoad()">Retry</button><button type="button" class="btn btn-default btn-sm" ng-if="batchLoadKind === 'session'" ng-click="reloadPage()">Reload Page</button></div>
                            <div class="attendance-field-placeholder" ng-if="!batchesLoading && !batchLoadError">No classes scheduled on this date.</div>
                        </div>
                        <div class="filter-col" ng-show="batch.batches.length">
                            <label for="selectBatch" class="attendance-field-label">Class</label>
                            <select id="selectBatch" name="selectBatch" ng-style="{'border-color': batchStyle}" ng-change="selectedBatch(batch.id)" ng-model="batch.id" class="form-control" ng-disabled="support_mode || attendanceStage == 'COMMITTING' || faceTestPunchBusy || faceTestPunchRequest">
                                <option value="">Choose class</option>
                                <option ng-repeat="batch in batch.batches" value="{{batch.id}}">{{batch.batch}}</option>
                            </select>
                        </div>
                        <div class="filter-col" ng-show="batch.id && batch.times.length">
                            <label for="selectTime" class="attendance-field-label">Time</label>
                            <select id="selectTime" name="selectTime" ng-model="timeIndex" ng-change="timeSelected()" class="form-control" ng-disabled="support_mode || attendanceStage == 'COMMITTING' || faceTestPunchBusy || faceTestPunchRequest">
                                <option value="-1">Choose time</option>
                                <option ng-repeat="time in batch.times" value="{{$index}}">{{time.start_time_string}} - {{time.end_time_string}}</option>
                            </select>
                        </div>
                    </div>
                    <div class="attendance-selection-help" ng-if="attendanceStage == 'SELECTING' && !showDateError && batch.batches.length">
                        <span ng-if="!batch.id">Choose a class.</span>
                        <span ng-if="batch.id && timeIndex == '-1'">Choose a time.</span>
                    </div>
                    <div class="alert alert-danger attendance-inline-alert" ng-if="cancelledBatch"><i class="fa fa-ban"></i> This class was cancelled or rescheduled. Choose another class.</div>
                </form>
            </div>

            <!-- Roster state: exactly one of INITIAL, LOADING, SLOW_LOADING, LOADED, EMPTY, ERROR. -->
            <div class="roster-skeleton" ng-if="showRosterSkeleton()" role="status" aria-live="polite" aria-busy="true">
                <div class="roster-skeleton-card" aria-hidden="true">
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                    <div class="sk-row"><span class="sk-bar sk-w60"></span><span class="sk-bar sk-w40"></span><span class="sk-bar sk-w30"></span></div>
                </div>
                <p class="roster-skeleton-text" ng-bind="rosterState === 'SLOW_LOADING' ? 'Still loading attendance…' : 'Loading attendance…'"></p>
            </div>
            <div class="roster-error attendance-stage-panel is-error" ng-if="rosterState === 'ERROR' && !cancelledBatch" role="alert">
                <span><i class="fa fa-exclamation-circle" aria-hidden="true"></i> <span ng-bind="rosterLoadError"></span></span>
                <button type="button" class="btn btn-default" ng-if="rosterErrorKind !== 'session'" ng-click="retryRosterLoad()"><i class="fa fa-refresh" aria-hidden="true"></i> Retry</button>
                <button type="button" class="btn btn-default" ng-if="rosterErrorKind === 'session'" ng-click="reloadPage()"><i class="fa fa-refresh" aria-hidden="true"></i> Reload Page</button>
            </div>
            <div class="roster-empty attendance-stage-panel is-empty" ng-if="rosterState === 'EMPTY'">
                <i class="fa fa-users" aria-hidden="true"></i> <span>No members found for this class.</span>
            </div>
            <div class="attendance-stage-panel is-loading attendance-saving" ng-if="savingAttendance" role="status" aria-live="assertive">
                <i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span>Saving attendance…</span>
            </div>

            <div class="attendance-primary-actions" ng-if="attendanceStage == 'READY'" ng-cloak>
                <div class="attendance-action-buttons">
                    <button type="button" class="btn btn-primary" ng-if="(faceUiEnabled || browserFaceUiEnabled) && !faceSetupMode && faceEnrollmentState == 'IDLE' && browserFaceOperatingMode != 'DISABLED' && browserFaceOperatingMode != 'CAPTURE_ONLY'" ng-disabled="disablePunchBtn || rosterRefreshing || !memberRows().length" ng-click="openAutomaticFace('attendance')"><i class="fa fa-camera" aria-hidden="true"></i> Scan Arrivals</button>
                    <button type="button" class="btn btn-success" ng-if="facePunchOutUiEnabled && faceSelectionUiEnabled && !faceSetupMode && faceEnrollmentState == 'IDLE'" ng-disabled="disablePunchBtn || !memberRows().length" ng-click="startFacePunchOut()"><i class="fa fa-sign-out" aria-hidden="true"></i> Scan Departures</button>
                    <button type="button" class="btn btn-success" ng-if="!browserFacePilot && browserFaceOperatingMode != 'LIVE'" ng-disabled="disablePunchBtn || action_disable || rosterRefreshing || !memberRows().length || faceStatusPending" aria-describedby="punchAttendanceHint" ng-click="punchAttendance()"><i class="fa fa-check" aria-hidden="true"></i> Punch Attendance</button>
                </div>
                <div class="attendance-action-meta">
                    <span class="attendance-mode-pill" ng-if="browserFaceOperatingMode == 'TEST'" title="Test attendance is saved separately. Real attendance and memberships are unchanged.">Test mode</span>
                    <span class="attendance-action-hint text-warning" ng-if="action_disable"><i class="fa fa-lock"></i> Past month attendance editing requires supervisor or admin privileges.</span>
                    <span id="punchAttendanceHint" class="attendance-action-hint" ng-if="browserFaceOperatingMode == 'TEST' && !faceTestPunchBusy && !faceTestPunchRequest && !faceTestPending.length">Scan arrivals to enable Punch Attendance.</span>
                    <span class="attendance-action-hint" ng-if="browserFaceOperatingMode == 'TEST' && !faceTestPunchBusy && faceTestPending.length">{{faceTestPending.length}} pending punch</span>
                    <span class="attendance-action-hint" ng-if="rosterRefreshing" role="status"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span ng-bind="rosterSlow ? 'Still refreshing…' : 'Refreshing…'"></span></span>
                </div>
                <p class="attendance-action-status" role="status" aria-live="polite" ng-if="faceTestPunchMessage && !faceTestPunchBusy">{{faceTestPunchMessage}}</p>
            </div>

            <div class="row face-scan-card" ng-if="attendanceStage == 'SCANNING'" ng-cloak>
                <div class="col-sm-6">
                    <div id="faceAttendanceFrame" class="face-camera-frame">
                        <video id="faceAttendanceVideo" autoplay muted playsinline ng-class="{'face-video-mirrored': cameraFacingMode === 'user'}"></video>
                        <div id="faceAttendanceGuide" class="face-camera-guide" ng-class="faceAttendanceDetectionStatusClass" aria-hidden="true"></div>
                        <div id="faceAttendanceDetectionBox" ng-style="faceAttendanceBoxStyle" ng-class="faceAttendanceDetectionStatusClass" aria-hidden="true"></div>
                        <button type="button" class="btn btn-sm face-camera-flip-btn" ng-click="switchFaceCamera('attendance')" aria-label="Switch between front and rear camera"><i class="fa fa-refresh"></i> {{cameraFacingMode == 'environment' ? 'Rear Camera' : 'Front Camera · Mirror view'}}</button>
                        <div class="face-camera-feedback" ng-class="faceAttendanceDetectionStatusClass" role="status" aria-live="polite">{{faceAttendanceDetectionMessage}}</div>
                    </div>
                </div>
                <div class="col-sm-6 face-scan-copy">
                    <div class="face-scan-heading"><h3><i class="fa fa-camera"></i> Scanning attendance</h3><span class="face-status-pill" ng-class="faceScanStatusClass">{{faceScanStatusLabel}}</span></div>
                    <p class="face-current-instruction" role="status" aria-live="polite">{{faceMessage || 'Show one child at a time and wait for the green guide.'}}</p>
                    <div class="alert alert-warning" ng-if="faceChallengeActive" role="alert" aria-live="assertive"><strong>{{faceChallengePrompt}}</strong><button type="button" class="btn btn-default btn-sm" ng-click="useManualFaceFallback()">Leave Absent &amp; Continue</button></div>
                    <details class="face-help"><summary>How to get a clear scan</summary><p>Keep one full face inside the oval, facing the camera in even light. Move the child out of view after the result.</p></details>
                    <div class="face-live-totals"><span><strong>{{rosterPresentCount()}}</strong> present</span><span><strong>{{rosterAbsentCount()}}</strong> absent</span></div>
                    <div class="attendance-scan-actions">
                        <button type="button" class="btn btn-danger" ng-if="faceManualFallbackAvailable" ng-click="useManualFaceAttendance()"><i class="fa fa-list"></i> Switch to Manual Attendance</button>
                        <button type="button" class="btn btn-warning" ng-if="faceRetryAvailable" ng-click="retryFaceAttempt()"><i class="fa fa-refresh"></i> Check Again</button>
                        <button type="button" class="btn btn-primary" ng-click="finishFaceScan()"><i class="fa fa-check-circle"></i> Finish Scanning</button>
                        <button type="button" class="btn btn-default" ng-click="cancelFaceScan()">Cancel Scan</button>
                    </div>
                </div>
            </div>

            <div class="attendance-review-card" ng-if="attendanceStage == 'REVIEW'" ng-cloak>
                <h3><i class="fa fa-check-square-o"></i> Attendance summary</h3>
                <div class="attendance-summary-grid">
                    <div class="is-present"><strong>{{rosterPresentCount()}}</strong><span>Present</span></div>
                    <div class="is-absent"><strong>{{rosterAbsentCount()}}</strong><span>Absent</span></div>
                    <div class="is-not-ready"><strong>{{rosterNotReadyCount()}}</strong><span>Face not ready</span></div>
                </div>
                <div class="alert alert-warning" ng-if="reviewWithoutScan"><strong>No scan was run.</strong> Every unscanned student will be recorded Absent. Review each student's status before saving.</div>
                <div class="alert alert-warning" ng-if="dupsMemPunchWarning"><strong>Attendance already exists for:</strong> {{members || 'one or more attendees'}}. Confirm only if you intend to continue.</div>
                <div class="alert alert-danger" ng-if="commitError">{{commitError}}</div>
                <p>{{faceSelectionUiEnabled && !reviewWithoutScan ? 'Face scanning is complete.' : 'Attendance is ready for review.'}} Punch Attendance saves the current result.</p>
                <div class="attendance-review-actions">
                    <button type="button" class="btn btn-default" ng-if="faceSelectionUiEnabled" ng-click="continueFaceScanning()"><i class="fa fa-camera"></i> Continue Scanning</button>
                    <button type="button" class="btn btn-primary" ng-if="!continueBtn" ng-disabled="disablePunchBtn" ng-click="punchAttendance()"><i class="fa fa-check"></i> Punch Attendance</button>
                    <button type="button" class="btn btn-warning" ng-if="continueBtn" ng-click="punch()">Confirm &amp; Punch Attendance</button>
                </div>
            </div>

            <div class="attendance-complete-card" ng-if="attendanceStage == 'COMPLETE'" ng-cloak>
                <i class="fa fa-check-circle"></i><h3>{{faceLiveWriteMode ? 'Face attendance recorded' : 'Attendance saved'}}</h3><p ng-if="faceLiveWriteMode">{{faceMatched.length}} face matches recorded · unscanned children unchanged</p><p ng-if="!faceLiveWriteMode">{{rosterPresentCount()}} present · {{rosterAbsentCount()}} absent</p><button type="button" class="btn btn-primary" ng-click="finishAttendanceFlow()">Done</button>
            </div>

            <!-- TEMPORARILY HIDDEN FACE ENROLLMENT EXPERIENCE
                 Re-enable this section together with the Manage Face Setup button,
                 the admin review section, and the roster Face setup column below. -->

<?php include 'inc/browser-face-panel.php'; ?>
            <div class="attendance-inline-alert alert alert-warning attendance-notice" ng-if="noticeText()" role="alert">
                <span ng-bind="noticeText()"></span> <button type="button" class="btn btn-default btn-sm" ng-click="runNotice()">Retry</button>
            </div>
            <div ng-show="rosterState === 'LOADED' && postLoadDivShow && list.length > 0" class="attendance-roster" ng-cloak>
                <div class="attendance-roster-summary" ng-if="faceUiEnabled"><span class="is-present"><strong>{{rosterPresentCount()}}</strong> Present</span><span class="is-absent"><strong>{{rosterAbsentCount()}}</strong> Absent</span><span class="is-not-ready"><strong>{{rosterNotReadyCount()}}</strong> Face not ready</span></div>
                <div class="attendance-table-wrapper" ng-class="{'is-refreshing': rosterRefreshing}">
                    <table class="table attendance-focused-table">
                        <thead><tr><th scope="col">Name</th><th scope="col">Attendance</th><th scope="col">Sessions</th><th scope="col" ng-if="faceEnrollmentUiEnabled && browserFaceOperatingMode != 'DISABLED'">Face Status</th><th scope="col" ng-if="faceEnrollmentUiEnabled && browserFaceOperatingMode != 'DISABLED'"><span class="sr-only">Face Action</span></th></tr></thead>
                        <tbody>
                            <tr ng-repeat="row in list track by $index" ng-if="row.type == 'M'" ng-class="rosterRowClass(row)">
                                <th scope="row" class="attendance-name-cell">{{row.fullname}}</th>
                                <td class="attendance-cell" data-label="Attendance">
                                    <span class="attendance-status is-checking" ng-if="faceStatusPending">Checking…</span>
                                    <span ng-if="!faceStatusPending && browserFaceOperatingMode == 'TEST'"><span class="attendance-status" ng-class="row.browser_face_test_attendance_state == 'PRESENT_PUNCHED_TEST' ? 'is-present' : row.browser_face_test_attendance_state == 'PRESENT_PENDING_PUNCH' ? 'is-pending' : 'is-neutral'">{{testAttendanceLabel(row)}}</span><span class="attendance-test-tag" ng-if="row.browser_face_test_attendance_state == 'PRESENT_PUNCHED_TEST'" title="Saved as test attendance">Test</span></span>
                                    <span class="attendance-status is-neutral" ng-if="!faceStatusPending && browserFaceOperatingMode == 'LIVE' && !(row.attendance_id > 0)">Not yet marked</span>
                                    <span ng-if="!faceStatusPending && browserFaceOperatingMode != 'TEST' && (faceUiEnabled || row.attendance_id > 0) && (browserFaceOperatingMode != 'LIVE' || row.attendance_id > 0)" class="attendance-status" ng-class="rosterStatusClass(row)">{{rosterStatusLabel(row)}}</span>
                                    <div ng-if="!faceStatusPending && !faceUiEnabled && browserFaceOperatingMode != 'LIVE' && browserFaceOperatingMode != 'TEST' && (!(row.attendance_id > 0) || row.negative == 1)" class="attendance-manual-controls" role="radiogroup" aria-label="Attendance for {{row.fullname}}"><label><input type="radio" ng-model="row.selected" ng-value="true" ng-change="selectDeselectItem(row.type)"> Present</label><label><input type="radio" ng-model="row.selected" ng-value="false" ng-change="selectDeselectItem(row.type)"> Absent</label></div>
                                </td>
                                <td class="attendance-cell attendance-sessions-cell" data-label="Sessions">{{rosterSessionsLabel(row)}}</td>
                                <td class="attendance-cell" data-label="Face Status" ng-if="faceEnrollmentUiEnabled && browserFaceOperatingMode != 'DISABLED'">
                                    <span class="face-status" ng-class="faceStatusClass(row)">{{faceStatusLabel(row)}}</span>
                                    <small class="face-status-note" ng-if="!faceStatusPending && row.browser_face_latest_attempt == 'REJECTED_DUPLICATE' && row.browser_face_state == 'PENDING'">Last update rejected; earlier face kept.</small>
                                </td>
                                <td class="attendance-cell attendance-face-action" ng-if="faceEnrollmentUiEnabled && browserFaceOperatingMode != 'DISABLED'">
                                    <button type="button" class="btn btn-default btn-sm attendance-face-btn" ng-if="!faceStatusPending && (!row.browser_face_enrolled || browserFaceOperatingMode == 'TEST') && !row.browser_face_legacy" ng-click="openAutomaticFace('enroll', row)"><i class="fa fa-camera" aria-hidden="true"></i> {{row.browser_face_test_ready && !row.browser_face_excluded && !row.browser_face_conflict ? 'Update Face' : 'Enroll Face'}}</button>
                                    <button type="button" class="btn btn-default btn-sm" ng-if="facePracticeControls" ng-click="openAutomaticFace('validation', row)">Check face</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="attendance-consent-note" ng-if="faceEnrollmentUiEnabled && browserFaceOperatingMode != 'DISABLED'">Capture faces only with consent (a guardian's for children). Face samples are encrypted; test samples expire after 30 days.</p>
            </div>

          </div><!-- /#rosterRegion -->
        </div>

    </div>

<!-- END Page Content -->

<?php include 'inc/footer.php'; // Footer and scripts ?>

<script>
window.browserFaceCsrf = <?php echo json_encode($_SESSION['face_csrf']); ?>;
window.faceAttendanceFeatureEnabled = <?php echo $face_attendance_ui_enabled ? 'true' : 'false'; ?>;
window.faceTeacherSelectionEnabled = <?php echo $face_selection_ui_enabled ? 'true' : 'false'; ?>;
window.facePunchOutFeatureEnabled = <?php echo $face_punch_out_ui_enabled ? 'true' : 'false'; ?>;
window.faceEnrollmentFeatureEnabled = <?php echo $face_enrollment_ui_enabled ? 'true' : 'false'; ?>;
window.faceGuidedAdaptiveV2Enabled = <?php echo $face_guided_adaptive_v2_enabled ? 'true' : 'false'; ?>;
window.faceRecalibrationFeatureEnabled = <?php echo $face_recalibration_ui_enabled ? 'true' : 'false'; ?>;
// Workers are fetched independently of their parent scripts. Version this URL so
// an open kiosk cannot retain an older landmark/quality implementation in cache.
window.faceLandmarkerWorkerUrl = <?php echo json_encode('js/faceEnrollmentLandmarker.worker.js?v=' . filemtime(__DIR__ . '/js/faceEnrollmentLandmarker.worker.js')); ?>;
</script>
<script src="js/controllers/attendanceEligibility.js?v=<?php echo $template['version']; ?>"></script>
<script src="js/controllers/faceAttendanceController.js?v=<?php echo $template['version']; ?>&t=<?php echo filemtime(__DIR__ . '/js/controllers/faceAttendanceController.js'); ?>"></script>
<script src="js/faceEnrollmentTracker.js?v=<?php echo filemtime(__DIR__ . '/js/faceEnrollmentTracker.js'); ?>"></script>
<script src="js/controllers/batchAttendanceController.js?v=<?php echo $template['version']; ?>&t=<?php echo filemtime(__DIR__ . '/js/controllers/batchAttendanceController.js'); ?>"></script>
<?php include 'inc/bottom.php'; // Close body and html tags ?>
