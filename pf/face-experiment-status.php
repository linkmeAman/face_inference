<?php
ob_start();
require_once 'inc/check_session.php';
if (!isset($_SESSION['position_id']) || (int)$_SESSION['position_id'] !== 1) {
    ob_end_clean();
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Position-1 administrator access is required.</p>');
}
/* Read-only shell; data comes only from routes/faceExperimentStatusRoute.php. */
?><!doctype html><meta charset="utf-8"><title>Experiment Status</title><main><h1>Face Experiment Status</h1><pre id="status">Loading…</pre></main><script>fetch('../routes/faceExperimentStatusRoute.php?action=status',{method:'POST',credentials:'same-origin'}).then(r=>r.json()).then(x=>document.getElementById('status').textContent=JSON.stringify(x.data||x,null,2)).catch(()=>document.getElementById('status').textContent='Unavailable');</script>
