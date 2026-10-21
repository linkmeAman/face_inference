<?php
session_start();
require_once dirname(__DIR__).'/classes/session.class.php';
$actor=(int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if($actor<1 || empty($_SESSION['db_name']) || empty($_SESSION['bid'])){header('Location: /signin.html?link=/pf/face-calibration.php');exit;}
$security=new session;
$pos = isset($_SESSION['position_id']) ? (int)$_SESSION['position_id'] : 0;
$isAdmin = isset($_SESSION['admin']) ? (int)$_SESSION['admin'] : 0;
$isSuperAdmin = ($isAdmin === 1) || in_array($pos, [1, 10, 11], true);
if(!$isSuperAdmin || !$security->access($actor,$security->module('Attendance'),1)){http_response_code(403);exit('Super Administrator access required');}
$_SESSION['face_csrf']=$_SESSION['face_csrf'] ?? bin2hex(random_bytes(32));
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; worker-src 'self'; style-src 'self'; img-src 'self' blob:; media-src 'self' blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'none'; form-action 'self'");
$csrf=htmlspecialchars($_SESSION['face_csrf'],ENT_QUOTES,'UTF-8');session_write_close();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="face-csrf" content="<?php echo $csrf; ?>"><title>Private face calibration</title><link rel="stylesheet" href="css/browser-face.css"></head>
<body><main><a href="attendance.php">Back to attendance</a><h1>Private face calibration</h1>
<p>Collect consenting people across independent visits, devices and lighting. Observations are encrypted for 30 days; no enrollment or attendance is created. Target at least 20 identities and 100 observations, including separate validation visits. Counts alone do not prove accuracy.</p>
<label>Consenting person <select id="person"><option value="">Select a person</option></select></label>
<label>Visit <input id="visit" maxlength="100"></label><label>Physical device <input id="device" maxlength="100"></label><label>Lighting <input id="lighting" maxlength="100"></label>
<label>Dataset split <select id="split"><option value="calibration">Calibration</option><option value="validation">Independent validation</option></select></label>
<label class="consent"><input id="consent" type="checkbox"> The person consented to private biometric evaluation, 30-day storage and authorized export. Guardian consent was obtained where applicable.</label>
<button id="collect">Collect automatically</button><button id="erase">Erase this person's calibration observations</button>
<section class="camera"><video id="video" autoplay muted playsinline></video><canvas id="overlay" aria-hidden="true"></canvas></section>
<p id="status" role="status"></p><progress id="progress" max="1" value="0"></progress><p id="error" role="alert"></p>
<button id="stop">Close camera</button><button id="retry" hidden>Retry</button><p id="counts"></p><ul id="observations"></ul>
<p class="notice">Private export and expiry cleanup are operator CLI commands documented in the setup guide. No raw images are stored. Photos/replayed videos may pass; no validated spoofing defense.</p>
</main><script type="module" src="js/browser-face/calibration.mjs"></script></body></html>
