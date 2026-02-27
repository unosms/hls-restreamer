<?php

if (function_exists('auth')) {
  if (!auth()->check()) {
    header('Location: /login');
    exit;
  }
} else {
  require_once __DIR__ . '/auth.php';
  require_login();
}

$baseDir   = '/var/www/stream/live';
$manage    = '/usr/local/bin/hls_manage.sh';
$nginxLog  = '/var/log/nginx/access.log';
$tailLines = 20000;

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function csrf_input(){
  return function_exists('csrf_field') ? csrf_field() : '';
}
function valid_channel($ch){ return (bool)preg_match('/^[A-Za-z0-9_-]{1,50}$/', $ch); }
function valid_url($u){
  if (!filter_var($u, FILTER_VALIDATE_URL)) return false;
  $scheme = parse_url($u, PHP_URL_SCHEME);
  return in_array($scheme, ['http','https'], true);
}
function run_cmd($cmd){
  $out=[]; $rc=0;
  exec($cmd.' 2>&1', $out, $rc);
  return [$rc, implode("\n",$out)];
}
function svc($ch){ return "hls_{$ch}.service"; }

function fmt_bytes($n){
  $n = (float)$n;
  $u = ['B','KB','MB','GB','TB'];
  $i=0;
  while ($n>=1024 && $i<count($u)-1){ $n/=1024; $i++; }
  return sprintf($n>=10 ? "%.0f %s" : "%.1f %s", $n, $u[$i]);
}
function fmt_uptime_from_monotonic($startMono){
  if (!$startMono || !is_numeric($startMono) || (int)$startMono<=0) return "—";
  $nowMonoUs = (int)(hrtime(true)/1000);
  $diffUs = max(0, $nowMonoUs - (int)$startMono);
  $sec = (int)floor($diffUs/1_000_000);
  $h = intdiv($sec,3600);
  $m = intdiv($sec%3600,60);
  $s = $sec%60;
  if ($h>0) return sprintf("%dh %02dm %02ds",$h,$m,$s);
  if ($m>0) return sprintf("%dm %02ds",$m,$s);
  return sprintf("%ds",$s);
}

function systemd_show($service){
  $props = "ActiveState,SubState,ExecMainPID,ExecMainStartTimestampMonotonic,ActiveEnterTimestampMonotonic,ActiveExitTimestampMonotonic";
  $cmd = "sudo /bin/systemctl show ".escapeshellarg($service)." -p ".$props;
  [$rc,$out]=run_cmd($cmd);
  if ($rc!==0) return ['_error'=>$out];

  $data=[];
  foreach (explode("\n",$out) as $line){
    if (!str_contains($line,'=')) continue;
    [$k,$v]=explode('=',$line,2);
    $data[$k]=$v;
  }
  return $data;
}

function nginx_served_bytes($channel, $logPath, $tailLines){
  $needle = "/live/$channel/";
  $cmd = "sudo /usr/bin/tail -n ".intval($tailLines)." ".escapeshellarg($logPath);
  [$rc,$out]=run_cmd($cmd);
  if ($rc!==0) return [0,0,$out];

  $bytes=0; $hits=0;
  foreach (explode("\n",$out) as $line){
    if ($line==='' || strpos($line,$needle)===false) continue;

    if (preg_match('/"\s+\d+\s+(\d+)\s+/', $line, $m)) {
      $bytes += (int)$m[1];
      $hits++;
      continue;
    }
    if (preg_match('/\s(\d+)\s*$/', $line, $m)) {
      $bytes += (int)$m[1];
      $hits++;
    }
  }
  return [$bytes,$hits,null];
}

function url_file_path($baseDir, $channel){
  return rtrim($baseDir,'/').'/'.$channel.'/.source_url';
}
function read_saved_url($baseDir, $channel){
  $p = url_file_path($baseDir, $channel);
  if (!is_file($p)) return '';
  return trim((string)@file_get_contents($p));
}
function write_saved_url($baseDir, $channel, $url){
  $p = url_file_path($baseDir, $channel);
  @file_put_contents($p, $url);
}

/* ===== get server IPv4 list ===== */
function get_server_ipv4_list(){
  $ips = [];

  $out = @shell_exec("hostname -I 2>/dev/null");
  if (is_string($out) && trim($out) !== '') {
    foreach (preg_split('/\s+/', trim($out)) as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[] = $ip;
    }
  }

  if (!empty($_SERVER['SERVER_ADDR']) && filter_var($_SERVER['SERVER_ADDR'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $ips[] = $_SERVER['SERVER_ADDR'];
  }

  $uniq = [];
  foreach ($ips as $ip) $uniq[$ip] = true;
  return array_keys($uniq);
}
$serverIps = get_server_ipv4_list();

/* ===================== AJAX status ONLY ===================== */
if (($_GET['ajax'] ?? '') === 'status') {
  header('Content-Type: application/json');

  $ch = trim($_GET['channel'] ?? '');
  if (!valid_channel($ch)) { echo json_encode(['ok'=>false,'error'=>'bad channel']); exit; }

  $service = svc($ch);
  $info = systemd_show($service);
  if (isset($info['_error'])) { echo json_encode(['ok'=>false,'error'=>$info['_error']]); exit; }

  $active = $info['ActiveState'] ?? 'unknown';
  $sub    = $info['SubState'] ?? 'unknown';
  $pid    = $info['ExecMainPID'] ?? '';

  $isRunning = ($active === 'active' && ($sub === 'running' || $sub === 'start-post'));

  if ($isRunning) {
    $start  = $info['ActiveEnterTimestampMonotonic'] ?? ($info['ExecMainStartTimestampMonotonic'] ?? 0);
    $uptime = fmt_uptime_from_monotonic($start);
  } else {
    $uptime = "—";
  }

  [$servedBytes,$hits,$logErr] = nginx_served_bytes($ch, $GLOBALS['nginxLog'], $GLOBALS['tailLines']);

  echo json_encode([
    'ok'=>true,
    'active'=>$active,
    'sub'=>$sub,
    'pid'=>$pid,
    'isRunning'=>$isRunning,
    'uptime'=>$uptime,
    'servedBytes'=>(int)$servedBytes,
    'served'=>fmt_bytes($servedBytes),
    'hits'=>$hits,
    'logErr'=>$logErr,
    'raw'=>$info
  ]);
  exit;
}

/* ===================== PAGE actions (ONLY on POST) ===================== */
$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action  = $_POST['action'] ?? '';
  $channel = trim($_POST['channel'] ?? '');
  $url     = trim($_POST['url'] ?? '');

  if ($action === 'upload_backup') {
    if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
      $err = "Upload failed.";
    } else {
      $tmp  = $_FILES['backup_file']['tmp_name'];
      $name = $_FILES['backup_file']['name'] ?? '';
      if (!preg_match('/\.tar\.gz$/i', $name)) {
        $err = "Invalid file type. Please upload a .tar.gz file.";
      } else {
        $ts   = date('Ymd_His');
        $dest = "/tmp/hls_backup_{$ts}.tar.gz";
        if (!move_uploaded_file($tmp, $dest)) {
          $err = "Failed to save uploaded file.";
        } else {
          @file_put_contents('/tmp/hls_last_uploaded_backup.txt', $dest);
          $msg = "Uploaded OK: " . h($dest);
        }
      }
    }
  }

  elseif ($action === 'restore_backup') {
    $path = @trim(@file_get_contents('/tmp/hls_last_uploaded_backup.txt'));
    if (!$path) {
      $err = "No uploaded backup found. Upload a backup first.";
    } else {
      $cmd = "sudo /usr/local/bin/hls_restore_backup.sh " . escapeshellarg($path);
      [$rc,$outText] = run_cmd($cmd);
      if ($rc === 0) $msg = "<pre>".h($outText)."</pre>";
      else $err = $outText;
    }
  }

  elseif ($action === 'export_backup') {
    $cmd = "sudo /usr/local/bin/hls_export_backup.sh";
    [$rc, $outText] = run_cmd($cmd);
    if ($rc === 0) {
      $backupPath  = trim($outText);
      $downloadUrl = '/download.php?f=' . urlencode($backupPath);
      $msg = 'Backup created: ' . h($backupPath) . '<br>'
           . 'Download: <a href="' . h($downloadUrl) . '">Click here to download</a>';
    } else {
      $err = "Backup failed:\n" . $outText;
    }
  }

  elseif ($action === 'edit_url') {
    $newUrl = trim($_POST['new_url'] ?? '');

    if (!valid_channel($channel)) {
      $err = "Invalid channel name.";
    } elseif (!valid_url($newUrl)) {
      $err = "Invalid URL.";
    } else {
      $log = [];

      $cmd = 'sudo ' . escapeshellarg($manage) . ' stop ' . escapeshellarg($channel);
      [$rc1, $out1] = run_cmd($cmd);
      $log[] = "== stop ==\n".$out1;
      if ($rc1 !== 0) $err = implode("\n\n", $log);

      if (!$err) {
        $cmd = 'sudo ' . escapeshellarg($manage) . ' add ' . escapeshellarg($channel) . ' ' . escapeshellarg($newUrl);
        [$rc2, $out2] = run_cmd($cmd);
        $log[] = "== add ==\n".$out2;
        if ($rc2 !== 0) $err = implode("\n\n", $log);
      }

      if (!$err) {
        $cmd = 'sudo ' . escapeshellarg($manage) . ' start ' . escapeshellarg($channel);
        [$rc3, $out3] = run_cmd($cmd);
        $log[] = "== start ==\n".$out3;
        if ($rc3 !== 0) $err = implode("\n\n", $log);
      }

      if (!$err) {
        write_saved_url($baseDir, $channel, $newUrl);
        $msg = "<pre>".h(implode("\n\n", $log))."</pre>";
      }
    }
  }

  else {
    if (!valid_channel($channel)) {
      $err = "Invalid channel name. Use A-Z a-z 0-9 _ -";
    } else {

      if ($action === 'add') {
        if (!valid_url($url)) {
          $err = "Invalid URL.";
        } else {
          $cmd = 'sudo ' . escapeshellarg($manage) . ' add ' . escapeshellarg($channel) . ' ' . escapeshellarg($url);
          [$rc, $outText] = run_cmd($cmd);
          if ($rc === 0) {
            write_saved_url($baseDir, $channel, $url);
            $msg = "<pre>".h($outText)."</pre>";
          } else {
            $err = $outText;
          }
        }

      } elseif (in_array($action, ['start','stop','restart','delete'], true)) {

        if ($action === 'delete') {
          $cmd = 'sudo ' . escapeshellarg($manage) . ' delete ' . escapeshellarg($channel);
        } else {
          $cmd = 'sudo ' . escapeshellarg($manage) . ' ' . $action . ' ' . escapeshellarg($channel);
        }

        [$rc, $outText] = run_cmd($cmd);
        $rc === 0 ? $msg = "<pre>".h($outText)."</pre>" : $err = $outText;

      } else {
        $err = "Unknown action.";
      }
    }
  }
}

/* ===================== list channels ===================== */
$channels=[];
if (is_dir($baseDir)) {
  foreach (scandir($baseDir) as $d) {
    if ($d==='.'||$d==='..') continue;
    if (is_dir("$baseDir/$d") && valid_channel($d)) $channels[]=$d;
  }
  sort($channels);
}
$totalChannels=count($channels);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>HLS Streams Manager</title>
  <style>
    body{font-family:Arial, sans-serif; max-width:980px; margin:30px auto; padding:0 12px;}
    input,button{padding:10px; margin:4px 0;}
    .row{display:flex; gap:10px; flex-wrap:wrap;}
    .card{border:1px solid #ddd; padding:12px; border-radius:10px; margin:10px 0;}
    .ok{background:#e8fff0; padding:10px; border-radius:8px;}
    .bad{background:#ffecec; padding:10px; border-radius:8px;}
    code{background:#f5f5f5; padding:2px 6px; border-radius:6px; word-break: break-all;}
    .meta{display:flex; gap:14px; flex-wrap:wrap; margin-top:6px; color:#333;}
    .pill{background:#f3f3f3; padding:4px 8px; border-radius:999px; font-size:13px;}
    .statusBox{display:none; margin-top:10px; padding:10px; border-radius:10px; background:#f8fbff; border:1px dashed #cbd5ff;}
    .editBox{display:none; margin-top:10px; padding:10px; border-radius:10px; background:#fffef6; border:1px dashed #e7d37a;}
    .muted{color:#666; font-size:13px;}
    .topbar{display:flex; gap:12px; align-items:center; flex-wrap:wrap;}
    .danger{background:#ffecec;border:1px solid #f3b0b0;}

    .copyBtn{
      border:1px solid #ddd;
      background:#fff;
      border-radius:8px;
      padding:6px 10px;
      cursor:pointer;
      line-height:1;
    }
    .copyBtn:hover{ background:#f7f7f7; }
    .copied{ font-size:12px; color:#2b7a3d; margin-left:8px; }

    .hostSelect{ padding:6px 10px; border-radius:8px; border:1px solid #ddd; margin-left:8px; }
  </style>
</head>
<body>
<div class="topbar">
  <h2 style="margin:0;">HLS Streams Manager</h2>
  <span class="pill">Total channels: <?=h((string)$totalChannels)?></span>

  <form method="post" style="margin:0;">
    <?= csrf_input() ?>
    <button name="action" value="export_backup" type="submit">Export Backup (.tar.gz)</button>
  </form>

  <form method="post" enctype="multipart/form-data" style="margin:0;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="upload_backup">
    <input type="file" name="backup_file" accept=".tar.gz" required>
    <button type="submit">Upload Backup</button>
  </form>

  <form method="post" style="margin:0;">
    <?= csrf_input() ?>
    <button name="action" value="restore_backup" type="submit"
            onclick="return confirm('Restore will overwrite configs (streams.php, nginx, systemd, sudoers). Continue?');">
      Restore Latest Uploaded
    </button>
  </form>
</div>

<?php if ($msg): ?><div class="ok"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="bad"><pre><?=h($err)?></pre></div><?php endif; ?>

<div class="card">
  <h3>Add new stream</h3>
  <form method="post">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="add">
    <div class="row">
      <div style="flex:1; min-width:220px;">
        <label>Channel name (folder)</label><br>
        <input name="channel" placeholder="myauthorizedchannel" style="width:100%;" required>
      </div>
      <div style="flex:2; min-width:320px;">
        <label>Input URL (authorized)</label><br>
        <input name="url" placeholder="http://myauthorized.stream/index.m3u8" style="width:100%;" required>
      </div>
    </div>
    <button type="submit">Add Channel</button>
  </form>

  <!-- DEFAULT HOST FOR NEW CHANNELS -->
  <p class="muted">
    Output URL:
    <code id="exampleOut">/live/&lt;channel&gt;/index.m3u8</code>
    <?php if (!empty($serverIps)): ?>
      <select id="newHostSelect" class="hostSelect" title="Default host for NEW channels">
        <?php foreach ($serverIps as $ip): ?>
          <option value="<?=h($ip)?>"><?=h($ip)?></option>
        <?php endforeach; ?>
      </select>
      <span class="muted" style="margin-left:6px;">Default for new channels</span>
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <h3>Existing channels</h3>

  <?php if (!$channels): ?>
    <p>No channels yet.</p>
  <?php else: ?>
    <?php foreach ($channels as $ch): ?>
      <?php
        $savedUrl = read_saved_url($baseDir, $ch);
        $outPath  = "/live/$ch/index.m3u8"; // PATH ONLY
      ?>
      <div class="card" id="card-<?=h($ch)?>">
        <b><?=h($ch)?></b><br>

        Output:
        <code class="outFull" id="out-<?=h($ch)?>" data-path="<?=h($outPath)?>"><?=h($outPath)?></code>

        <?php if (!empty($serverIps)): ?>
          <select class="hostSelect chanHost" data-ch="<?=h($ch)?>">
            <?php foreach ($serverIps as $ip): ?>
              <option value="<?=h($ip)?>"><?=h($ip)?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <button type="button"
                class="copyBtn"
                title="Copy output URL"
                onclick="copyOutputUrl('<?=h($ch)?>')">⧉</button>
        <span class="copied" id="copied-<?=h($ch)?>" style="display:none;">Copied</span>
        <br>

        Source: <code><?= $savedUrl ? h($savedUrl) : '—' ?></code>

        <div class="meta">
          <span class="pill" id="state-<?=h($ch)?>">State: —</span>
          <span class="pill" id="uptime-<?=h($ch)?>">Uptime: —</span>
          <span class="pill" id="served-<?=h($ch)?>">Served: —</span>
          <span class="pill" id="hits-<?=h($ch)?>">Hits: —</span>
          <span class="pill" id="speed-<?=h($ch)?>">Speed: —</span>
        </div>

        <form method="post" class="row" style="margin-top:8px;">
          <?= csrf_input() ?>
          <input type="hidden" name="channel" value="<?=h($ch)?>">
          <button name="action" value="start" type="submit">Start</button>
          <button name="action" value="stop" type="submit">Stop</button>
          <button name="action" value="restart" type="submit">Restart</button>

          <button type="button" onclick="toggleStatus('<?=h($ch)?>')">Status</button>

          <button type="button"
                  class="editBtn"
                  data-ch="<?=h($ch)?>"
                  data-url="<?=h($savedUrl)?>">
            Edit URL
          </button>

          <button class="danger" name="action" value="delete" type="submit"
                  onclick="return confirm('Delete channel <?=h($ch)?> ? This will remove service + files.');">
            Delete
          </button>
        </form>

        <div class="editBox" id="edit-<?=h($ch)?>">
          <form method="post" class="row" onsubmit="return confirm('Update URL for <?=h($ch)?> ?');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="edit_url">
            <input type="hidden" name="channel" value="<?=h($ch)?>">
            <div style="flex:2; min-width:320px;">
              <label class="muted">Stream URL</label><br>
              <input id="edit-url-<?=h($ch)?>" name="new_url" style="width:100%;" required>
              <div class="muted" style="margin-top:6px;">
                If empty, add the URL once, then it will be remembered for next edits.
              </div>
            </div>
            <button type="submit">Save</button>
          </form>
        </div>

        <div class="statusBox" id="status-<?=h($ch)?>">
          <div class="muted">Click Status to load…</div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<script>
/* ===== keys ===== */
function lsKey(ch){ return 'hls_output_host_' + ch; }
const DEFAULT_NEW_HOST_KEY = 'hls_default_new_host';

/* ===== default host for NEW channels ===== */
function getDefaultNewHost(){ return localStorage.getItem(DEFAULT_NEW_HOST_KEY) || ''; }
function setDefaultNewHost(host){ localStorage.setItem(DEFAULT_NEW_HOST_KEY, host || ''); }

function buildFullUrl(host, path){
  if (!host) return path;
  return 'http://' + host + path;
}

function updateExampleOut(){
  const ex = document.getElementById('exampleOut');
  const sel = document.getElementById('newHostSelect');
  if (!ex) return;
  if (!sel) { ex.textContent = '/live/<channel>/index.m3u8'; return; }
  const host = sel.value || '';
  ex.textContent = buildFullUrl(host, '/live/<channel>/index.m3u8');
}

function initNewHostSelect(){
  const sel = document.getElementById('newHostSelect');
  if (!sel) return;

  let saved = getDefaultNewHost();
  if (saved){
    for (const opt of sel.options){
      if (opt.value === saved){ sel.value = saved; break; }
    }
  } else {
    setDefaultNewHost(sel.value || '');
  }
  updateExampleOut();

  sel.addEventListener('change', () => {
    setDefaultNewHost(sel.value || '');
    updateExampleOut();
  });
}

/* ===== per-channel host ===== */
function getHostForChannel(ch){
  return localStorage.getItem(lsKey(ch)) || '';
}
function setHostForChannel(ch, host){
  localStorage.setItem(lsKey(ch), host || '');
}
function applyHostToChannel(ch){
  const codeEl = document.getElementById('out-'+ch);
  if (!codeEl) return;

  const path = codeEl.getAttribute('data-path') || ('/live/' + ch + '/index.m3u8');

  // use per-channel if exists, else use default-new-host
  const host = getHostForChannel(ch) || getDefaultNewHost();

  codeEl.textContent = buildFullUrl(host, path);
}
function restoreAllChannelHosts(){
  document.querySelectorAll('.chanHost').forEach(sel => {
    const ch = sel.dataset.ch || '';
    if (!ch) return;

    let host = getHostForChannel(ch);
    if (!host) host = getDefaultNewHost();

    if (host){
      for (const opt of sel.options){
        if (opt.value === host){ sel.value = host; break; }
      }
      // persist so next reload keeps it
      setHostForChannel(ch, sel.value || host);
    }

    applyHostToChannel(ch);
  });
}

/* ===== COPY URL ===== */
async function copyText(text, ch){
  try{
    await navigator.clipboard.writeText(text);
  }catch(e){
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  const s = document.getElementById('copied-'+ch);
  if (s){
    s.style.display = 'inline';
    clearTimeout(s._t);
    s._t = setTimeout(()=>{ s.style.display='none'; }, 900);
  }
}
function copyOutputUrl(ch){
  const codeEl = document.getElementById('out-'+ch);
  const path = codeEl?.getAttribute('data-path') || ('/live/' + ch + '/index.m3u8');
  const host = getHostForChannel(ch) || getDefaultNewHost();
  copyText(buildFullUrl(host, path), ch);
}

/* ===== keep default-new-host updated when user changes any channel dropdown ===== */
document.addEventListener('change', function(e){
  const sel = e.target.closest('.chanHost');
  if (!sel) return;

  const ch = sel.dataset.ch || '';
  if (!ch) return;

  const host = sel.value || '';
  setHostForChannel(ch, host);

  // make it the default for future new channels
  setDefaultNewHost(host);

  // sync the "new channel" dropdown if exists
  const newSel = document.getElementById('newHostSelect');
  if (newSel){
    for (const opt of newSel.options){
      if (opt.value === host){ newSel.value = host; break; }
    }
    updateExampleOut();
  }

  applyHostToChannel(ch);
});

/* ===== EXISTING LOGIC BELOW (unchanged) ===== */
const lastTraffic = {};

function fmtSpeed(bytesPerSec){
  if (!isFinite(bytesPerSec) || bytesPerSec < 0) return '—';
  const units = ['B/s','KB/s','MB/s','GB/s'];
  let v = bytesPerSec, i = 0;
  while (v >= 1024 && i < units.length - 1){ v /= 1024; i++; }
  return (v >= 10 ? v.toFixed(0) : v.toFixed(1)) + ' ' + units[i];
}

function updateSpeed(ch, servedBytes){
  const now = Date.now();
  if (!lastTraffic[ch]) {
    lastTraffic[ch] = { bytes: servedBytes, t: now };
    const el = document.getElementById('speed-'+ch);
    if (el) el.textContent = 'Speed: —';
    return;
  }
  const prev = lastTraffic[ch];
  const dt = (now - prev.t) / 1000;
  const db = servedBytes - prev.bytes;

  if (dt <= 0 || db < 0) {
    lastTraffic[ch] = { bytes: servedBytes, t: now };
    const el = document.getElementById('speed-'+ch);
    if (el) el.textContent = 'Speed: —';
    return;
  }
  const el = document.getElementById('speed-'+ch);
  if (el) el.textContent = 'Speed: ' + fmtSpeed(db/dt);
  lastTraffic[ch] = { bytes: servedBytes, t: now };
}

async function fetchStatus(ch){
  const res = await fetch('?ajax=status&channel=' + encodeURIComponent(ch));
  return await res.json();
}

async function toggleStatus(ch){
  const box = document.getElementById('status-'+ch);
  const isOpen = box.style.display === 'block';
  if (isOpen) { box.style.display = 'none'; return; }

  box.style.display = 'block';
  box.innerHTML = '<div class="muted">Loading…</div>';

  const data = await fetchStatus(ch);
  if (!data.ok){
    box.innerHTML = '<div class="muted">Error: '+(data.error||'unknown')+'</div>';
    return;
  }
  applyStatus(ch, data, true);
}

function applyStatus(ch, data, updateBox){
  const st = document.getElementById('state-'+ch);
  const up = document.getElementById('uptime-'+ch);
  const sv = document.getElementById('served-'+ch);
  const hi = document.getElementById('hits-'+ch);

  if (st) st.textContent  = 'State: ' + data.active + '/' + data.sub;
  if (up) up.textContent  = 'Uptime: ' + data.uptime;
  if (sv) sv.textContent  = 'Served: ' + data.served;
  if (hi) hi.textContent  = 'Hits: ' + (data.hits||0);

  if (!data.isRunning) {
    lastTraffic[ch] = null;
    const el = document.getElementById('speed-'+ch);
    if (el) el.textContent = 'Speed: —';
  } else {
    updateSpeed(ch, Number(data.servedBytes||0));
  }

  if (!updateBox) return;
  const box = document.getElementById('status-'+ch);
  box.innerHTML =
    '<div><b>Service:</b> hls_' + ch + '.service</div>' +
    '<div><b>PID:</b> ' + (data.pid || '—') + '</div>' +
    '<div><b>Uptime:</b> ' + data.uptime + '</div>' +
    '<div><b>Traffic:</b> Served ' + data.served + ' (hits ' + (data.hits||0) + ')</div>' +
    '<div><b>Speed:</b> ' + (document.getElementById('speed-'+ch)?.textContent || 'Speed: —').replace('Speed: ','') + '</div>' +
    (data.logErr ? '<div class="muted">Log read warning: '+data.logErr+'</div>' : '') +
    '<hr>' +
    '<div class="muted">Raw systemd:</div>' +
    '<pre style="white-space:pre-wrap; margin:0;">' + JSON.stringify(data.raw, null, 2) + '</pre>';
}

function openEdit(ch, savedUrl){
  const box = document.getElementById('edit-'+ch);
  if (!box) return;

  const isOpen = box.style.display === 'block';
  box.style.display = isOpen ? 'none' : 'block';

  if (!isOpen) {
    const inp = document.getElementById('edit-url-'+ch);
    if (inp) inp.value = savedUrl || '';
  }
}

document.addEventListener('click', function(e){
  const btn = e.target.closest('.editBtn');
  if (!btn) return;
  e.preventDefault();
  openEdit(btn.dataset.ch || '', btn.dataset.url || '');
});

async function refreshAll(){
  const cards = document.querySelectorAll('[id^="card-"]');
  for (const card of cards){
    const ch = card.id.replace('card-','');
    try {
      const data = await fetchStatus(ch);
      if (!data.ok) continue;
      const box = document.getElementById('status-'+ch);
      const open = (box && box.style.display === 'block');
      applyStatus(ch, data, open);
    } catch(e){}
  }
}

document.addEventListener('DOMContentLoaded', () => {
  initNewHostSelect();       // NEW
  restoreAllChannelHosts();  // NEW
});

setInterval(refreshAll, 1000);
</script>

</body>
</html>
