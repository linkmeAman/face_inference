<?php
// ─── Auth: must be position_id == 1 (admin/developer) ───────────────────────
require_once 'inc/check_session.php';
if (!isset($_SESSION['position_id']) || (int)$_SESSION['position_id'] !== 1) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Admin access only.</p>');
}

require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';

// ─── All toggleable flags with labels ────────────────────────────────────────
$TOGGLES = [
    'FACE_ATTENDANCE_ENABLED'              => ['label' => 'Face Attendance Enabled',        'desc' => 'Master switch: shows face UI on attendance page', 'danger' => false],
    'FACE_TEACHER_SELECTION_ENABLED'       => ['label' => 'Teacher Row Selection',           'desc' => 'Allows camera scan to automatically mark student Present in attendance table', 'danger' => false],
    'FACE_ATTENDANCE_SHADOW_MODE'          => ['label' => 'Shadow Mode (no DB write)',       'desc' => 'Recognition only selects the row; teacher must still punch', 'danger' => false],
    'FACE_ENROLLMENT_ENABLED'             => ['label' => 'Enrollment / Map Face',           'desc' => 'Allow face enrollment (calibration) from attendance page', 'danger' => false],
    'FACE_PUNCH_OUT_ENABLED'              => ['label' => 'Punch-Out Enabled',               'desc' => 'Enables the face punch-out feature', 'danger' => false],
    'FACE_ENROLLMENT_UNCALIBRATED_TEST_MODE' => ['label' => 'Uncalibrated Test Mode',       'desc' => 'Skip calibration quality checks (testing only)', 'danger' => true],
    'FACE_RECALIBRATION_ENABLED'           => ['label' => 'Allow Re-calibration',           'desc' => 'Show Re-calibrate button on already calibrated students in register', 'danger' => false],
    'FACE_ATTENDANCE_REQUIRE_HTTPS'        => ['label' => 'Require HTTPS',                  'desc' => 'Block face features on non-HTTPS connections', 'danger' => false],
];

// ─── Read current values directly from Database ──────────────────────────────
$current = FaceAttendanceConfig::get();

$lastUpdated = date('d M Y H:i:s');
$conn = FaceAttendanceConfig::getConnection();
if ($conn) {
    $res = $conn->query("SELECT updated_at FROM face_attendance_config WHERE id = 1");
    if ($res && ($row = $res->fetch_assoc()) && !empty($row['updated_at'])) {
        $lastUpdated = date('d M Y H:i:s', strtotime($row['updated_at']));
    }
}

// ─── Page setup ──────────────────────────────────────────────────────────────
$template = [
    'title'       => 'Face Pilot Config | CCC Admin',
    'description' => 'Database-backed face attendance feature flags',
    'author'      => 'CCC',
    'header'      => 'fixed-top',
    'sidebar'     => 'sticky',
    'side_content'=> '',
    'page'        => 'full-width',
    'theme'       => 'pink',
    'active_page' => basename($_SERVER['PHP_SELF']),
];
?>
<?php include 'inc/top.php'; ?>
<?php include 'inc/side.php'; ?>

<style>
:root {
    --pilot-bg:     #0f1117;
    --card-bg:      #1a1d2e;
    --card-border:  #2a2d3e;
    --accent:       #6c63ff;
    --accent2:      #ff6584;
    --green:        #22d3a0;
    --red:          #ff5757;
    --text:         #e2e8f0;
    --muted:        #8892a4;
}

#face-pilot-wrapper {
    min-height: 100vh;
    background: linear-gradient(135deg, #0f1117 0%, #1a1030 100%);
    padding: 30px 20px 60px;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}
.pilot-header {
    display: flex; align-items: center; gap: 16px; margin-bottom: 28px;
}
.pilot-header-icon {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px;
    box-shadow: 0 0 30px rgba(108,99,255,0.4);
    flex-shrink: 0;
}
.pilot-header h1 { color: var(--text); font-size: 26px; font-weight: 700; margin: 0; }
.pilot-header p  { color: var(--muted); margin: 4px 0 0; font-size: 13px; }

.status-bar {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: 12px; padding: 14px 20px; margin-bottom: 28px;
    display: flex; align-items: center; gap: 12px; font-size: 13px; color: var(--muted);
    flex-wrap: wrap;
}
.status-bar .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--green); box-shadow: 0 0 8px var(--green);
    animation: pulse 2s ease-in-out infinite; flex-shrink: 0;
}
@keyframes pulse { 0%,100%{opacity:1}50%{opacity:.4} }

.toggle-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
    gap: 18px; margin-bottom: 32px;
}
.toggle-card {
    background: var(--card-bg); border: 1px solid var(--card-border);
    border-radius: 16px; padding: 20px 22px;
    display: flex; align-items: flex-start; gap: 16px;
    transition: border-color .2s, box-shadow .2s;
    position: relative; overflow: hidden;
}
.toggle-card:hover { border-color: rgba(108,99,255,.5); box-shadow: 0 4px 24px rgba(108,99,255,.12); }
.toggle-card.danger-card { border-color: rgba(255,87,87,.2); }
.toggle-card.danger-card:hover { border-color: rgba(255,87,87,.5); box-shadow: 0 4px 24px rgba(255,87,87,.12); }

.toggle-card-info { flex: 1; min-width: 0; }
.toggle-card-info h3 {
    color: var(--text); font-size: 14px; font-weight: 600; margin: 0 0 4px;
}
.toggle-card-info .env-key {
    font-size: 11px; color: var(--accent); font-family: monospace; margin-bottom: 6px; display: block;
}
.toggle-card-info p { color: var(--muted); font-size: 12px; margin: 0; line-height: 1.5; }
.danger-badge {
    display: inline-block; font-size: 10px; font-weight: 700; color: var(--red);
    background: rgba(255,87,87,.12); border: 1px solid rgba(255,87,87,.3);
    border-radius: 4px; padding: 1px 6px; margin-left: 6px; vertical-align: middle;
}

.toggle-switch { position: relative; width: 54px; height: 28px; flex-shrink: 0; cursor: pointer; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider {
    position: absolute; inset: 0; background: #2a2d3e;
    border-radius: 28px; transition: .25s; border: 1.5px solid #3a3d4e;
}
.toggle-slider::before {
    content: ''; position: absolute; width: 20px; height: 20px;
    left: 3px; top: 3px; background: #555; border-radius: 50%; transition: .25s;
}
.toggle-switch input:checked + .toggle-slider { background: var(--green); border-color: var(--green); }
.toggle-switch input:checked + .toggle-slider::before {
    background: #fff; transform: translateX(26px); box-shadow: 0 2px 6px rgba(0,0,0,.3);
}
.toggle-switch.danger-toggle input:checked + .toggle-slider { background: var(--red); border-color: var(--red); }

.apply-section { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.btn-apply {
    display: inline-flex; align-items: center; gap: 10px;
    background: linear-gradient(135deg, var(--accent), #8b5cf6);
    color: #fff; border: none; border-radius: 10px; padding: 12px 28px;
    font-size: 15px; font-weight: 600; cursor: pointer;
    transition: opacity .2s, transform .1s, box-shadow .2s;
    box-shadow: 0 4px 20px rgba(108,99,255,.4);
}
.btn-apply:hover { opacity: .9; transform: translateY(-1px); }
.btn-apply:active { transform: translateY(0); }
.btn-apply:disabled { opacity: .5; cursor: not-allowed; }
#apply-status { font-size: 14px; font-weight: 500; color: var(--muted); }
#apply-status.success { color: var(--green); }
#apply-status.error   { color: var(--red); }

.conf-viewer {
    background: #0a0c14; border: 1px solid var(--card-border);
    border-radius: 12px; padding: 18px 20px; margin-top: 14px;
    font-family: monospace; font-size: 12px; color: #7ee787;
    white-space: pre; overflow-x: auto; max-height: 280px; overflow-y: auto;
}
.conf-viewer-label {
    color: var(--muted); font-size: 12px; font-weight: 600; margin-bottom: 8px;
    display: flex; align-items: center; gap: 8px;
}
.conf-viewer-label span { color: var(--accent); font-family: monospace; }
</style>

<div class="content">
<div id="face-pilot-wrapper">

    <div class="pilot-header">
        <div class="pilot-header-icon">⚙️</div>
        <div>
            <h1>Face Pilot Config</h1>
            <p>Database-backed feature-flag editor &mdash; settings are saved directly to MySQL tables (<code>face_attendance_config</code> &amp; <code>attendance_settings</code>). Admin-only (position_id = 1).</p>
        </div>
    </div>

    <div class="status-bar">
        <div class="dot"></div>
        Storage:&nbsp;<span style="color:var(--accent);font-family:monospace">MySQL (`face_attendance_config` &amp; `attendance_settings`)</span>
        &nbsp;&middot;&nbsp; Last updated:&nbsp;<strong id="last-updated-display" style="color:var(--text)"><?php echo htmlspecialchars($lastUpdated); ?></strong>
    </div>

    <form id="pilot-form">
        <div class="toggle-grid">
        <?php foreach ($TOGGLES as $key => $meta):
            $isOn = !empty($current[$key]);
        ?>
        <div class="toggle-card <?php echo $meta['danger'] ? 'danger-card' : ''; ?>">
            <div class="toggle-card-info">
                <h3><?php echo htmlspecialchars($meta['label']); ?><?php if ($meta['danger']): ?><span class="danger-badge">DANGER</span><?php endif; ?></h3>
                <span class="env-key"><?php echo htmlspecialchars($key); ?> &rarr; <?php echo htmlspecialchars(FaceAttendanceConfig::COLUMN_MAP[$key]); ?></span>
                <p><?php echo htmlspecialchars($meta['desc']); ?></p>
            </div>
            <label class="toggle-switch <?php echo $meta['danger'] ? 'danger-toggle' : ''; ?>">
                <input type="checkbox" name="<?php echo htmlspecialchars($key); ?>" value="1"
                       <?php echo $isOn ? 'checked' : ''; ?>
                       data-danger="<?php echo $meta['danger'] ? '1' : '0'; ?>"
                       onchange="onToggleChange(this)">
                <span class="toggle-slider"></span>
            </label>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="apply-section">
            <button type="button" class="btn-apply" id="btn-apply" onclick="applyConfig()">
                💾 Save Settings to Database
            </button>
            <span id="apply-status">No unsaved changes.</span>
        </div>
    </form>

    <div style="margin-top:36px">
        <div class="conf-viewer-label">
            📊 Live Database Configuration &mdash; <span>MySQL Table Columns</span>
            &nbsp;<button onclick="loadConf()" style="font-size:11px;background:none;border:1px solid #333;color:#aaa;padding:2px 8px;border-radius:4px;cursor:pointer">↺ Refresh</button>
        </div>
        <div class="conf-viewer" id="conf-viewer"><?php echo htmlspecialchars(json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></div>
    </div>

</div>
</div>

<script>
var _dirty = false;

function onToggleChange(el) {
    _dirty = true;
    var s = document.getElementById('apply-status');
    s.textContent = '⚠ Unsaved changes — click Save Settings to Database.';
    s.className = '';
    if (el.dataset.danger === '1' && el.checked) {
        if (!confirm('⚠ This flag is marked DANGER. Are you sure you want to enable it?')) {
            el.checked = false;
        }
    }
}

function applyConfig() {
    var btn = document.getElementById('btn-apply');
    var s   = document.getElementById('apply-status');
    var data = {};
    document.querySelectorAll('#pilot-form input[type=checkbox]').forEach(function(inp) {
        data[inp.name] = inp.checked ? '1' : '0';
    });
    btn.disabled = true;
    s.className = ''; s.textContent = '⏳ Saving to database…';
    fetch('face-pilot-config-save.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    })
    .then(function(r){return r.json();})
    .then(function(res){
        btn.disabled = false;
        if (res.ok) {
            s.className = 'success';
            s.textContent = '✅ ' + res.message;
            _dirty = false;
            loadConf();
        } else {
            s.className = 'error';
            s.textContent = '❌ ' + (res.message || 'Unknown error');
        }
    })
    .catch(function(e){
        btn.disabled = false;
        s.className = 'error';
        s.textContent = '❌ Network error: ' + e.message;
    });
}

function loadConf() {
    fetch('face-pilot-config-load.php')
        .then(function(r){return r.json();})
        .then(function(data){
            if (data && data.config) {
                document.getElementById('conf-viewer').textContent = JSON.stringify(data.config, null, 2);
                document.getElementById('last-updated-display').textContent = new Date().toLocaleTimeString();
            }
        })
        .catch(function(err){
            console.error(err);
        });
}

window.addEventListener('beforeunload', function(e){ if (_dirty){ e.preventDefault(); e.returnValue=''; } });
</script>

<?php include 'inc/footer.php'; ?>
<?php include 'inc/bottom.php'; ?>
