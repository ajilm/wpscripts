<?php
/**
 * Theme Deployer — FTP-based sync for modified theme files.
 *
 * Browser UI with connection form, theme selector, progress bar, and live log.
 * Also works via CLI: php deploy-themes.php [--dry-run]
 *
 * @package TalentDove
 */

$is_cli = (php_sapi_name() === 'cli');

if (! $is_cli) {
    require_once __DIR__ . '/wp-load.php';
    if (! current_user_can('manage_options')) {
        wp_die('Unauthorized. Admin access required.');
    }
}

// ── Saved config ─────────────────────────────────────────────────────────
$saved_config = [];
$config_file  = __DIR__ . '/deploy-config.php';
if (file_exists($config_file)) {
    $overrides = require $config_file;
    if (is_array($overrides)) {
        $saved_config = $overrides;
    }
}

$defaults = [
    'ftp_host'          => '',
    'ftp_port'          => 21,
    'ftp_user'          => '',
    'ftp_pass'          => '',
    'ftp_ssl'           => false,
    'ftp_passive'       => true,
    'remote_themes_path'=> '/public_html/wp-content/themes/',
    'include_extensions'=> [],
    'exclude_patterns'  => ['node_modules/','.git/','.DS_Store','Thumbs.db','.env','*.log','package-lock.json','deploy-themes.php','deploy-config.php','.theme-deploy-sync.json'],
    'dry_run'           => false,
    'themes'            => [],
];

$saved_config = array_merge($defaults, $saved_config);

// ── Discover available themes ────────────────────────────────────────────
$themes_dir     = str_replace('\\', '/', __DIR__) . '/wp-content/themes';
$all_themes     = [];
if (is_dir($themes_dir)) {
    foreach (scandir($themes_dir) as $d) {
        if ($d === '.' || $d === '..') continue;
        if (is_dir($themes_dir . '/' . $d) && file_exists($themes_dir . '/' . $d . '/style.css')) {
            $all_themes[] = $d;
        }
    }
}

$sync_file = __DIR__ . '/.theme-deploy-sync.json';

// ── Helper functions ─────────────────────────────────────────────────────
function load_sync_data(string $path): array {
    if (! file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function save_sync_data(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
}
function should_exclude(string $relative_path, array $patterns): bool {
    $n = str_replace('\\', '/', $relative_path);
    foreach ($patterns as $p) {
        if (str_ends_with($p, '/')) {
            $dir = rtrim($p, '/');
            if (str_starts_with($n, $dir.'/') || str_contains($n, '/'.$dir.'/')) return true;
        } elseif (str_contains($p, '*')) {
            if (fnmatch($p, basename($n))) return true;
        } else {
            if (basename($n) === $p || $n === $p) return true;
        }
    }
    return false;
}
function scan_theme_files(string $dir, array $excl, array $ext): array {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isDir()) continue;
        $full = str_replace('\\','/',$f->getPathname());
        $rel  = substr($full, strlen($dir)+1);
        if (should_exclude($rel, $excl)) continue;
        if (!empty($ext) && !in_array(strtolower(pathinfo($rel,PATHINFO_EXTENSION)),$ext)) continue;
        $files[$rel] = $f->getMTime();
    }
    return $files;
}
function ftp_mkdir_recursive($ftp, string $dir): void {
    $parts = explode('/', trim($dir,'/'));
    $path  = '';
    foreach ($parts as $part) { $path .= '/'.$part; @ftp_mkdir($ftp,$path); }
}
function format_size(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576,1).' MB';
    if ($bytes >= 1024)    return round($bytes/1024,1).' KB';
    return $bytes.' B';
}

// ── AJAX deploy handler ──────────────────────────────────────────────────
if (! $is_cli && isset($_POST['action']) && $_POST['action'] === 'deploy') {
    header('Content-Type: application/json');

    if (! wp_verify_nonce($_POST['_nonce'] ?? '', 'theme_deploy')) {
        echo json_encode(['error' => 'Invalid security token. Refresh the page.']);
        exit;
    }

    $config = $defaults;
    $config['ftp_host']          = sanitize_text_field($_POST['ftp_host'] ?? '');
    $config['ftp_port']          = (int)($_POST['ftp_port'] ?? 21);
    $config['ftp_user']          = sanitize_text_field($_POST['ftp_user'] ?? '');
    $config['ftp_pass']          = $_POST['ftp_pass'] ?? '';
    $config['ftp_ssl']           = !empty($_POST['ftp_ssl']);
    $config['ftp_passive']       = !empty($_POST['ftp_passive']);
    $config['remote_themes_path']= sanitize_text_field($_POST['remote_path'] ?? '/public_html/wp-content/themes/');
    $config['dry_run']           = !empty($_POST['dry_run']);
    $config['themes']            = array_filter(array_map('sanitize_text_field', $_POST['themes'] ?? []));

    if (empty($config['ftp_host']) || empty($config['ftp_user']) || empty($config['themes'])) {
        echo json_encode(['error' => 'Host, username, and at least one theme are required.']);
        exit;
    }

    // Validate themes
    foreach ($config['themes'] as $t) {
        if (! is_dir($themes_dir.'/'.$t)) {
            echo json_encode(['error' => "Theme directory not found: $t"]);
            exit;
        }
    }

    // Scan
    $sync_data = load_sync_data($sync_file);
    $to_upload = [];
    foreach ($config['themes'] as $theme) {
        $tp    = $themes_dir.'/'.$theme;
        $files = scan_theme_files($tp, $config['exclude_patterns'], $config['include_extensions']);
        $last  = $sync_data[$theme] ?? [];
        foreach ($files as $rel => $mt) {
            if ($mt > ($last[$rel] ?? 0)) {
                $to_upload[] = [
                    'local'    => $tp.'/'.$rel,
                    'remote'   => rtrim($config['remote_themes_path'],'/').'/'.$theme.'/'.$rel,
                    'theme'    => $theme,
                    'relative' => $rel,
                    'mtime'    => $mt,
                    'size'     => filesize($tp.'/'.$rel),
                ];
            }
        }
    }

    if (empty($to_upload)) {
        echo json_encode(['success' => true, 'message' => 'No modified files. Everything is up to date.', 'files' => [], 'uploaded' => 0, 'failed' => 0]);
        exit;
    }

    if ($config['dry_run']) {
        $file_list = array_map(fn($f) => ['theme'=>$f['theme'],'file'=>$f['relative'],'size'=>format_size($f['size']),'status'=>'skipped'], $to_upload);
        echo json_encode(['success'=>true,'message'=>'Dry run complete. '.count($to_upload).' file(s) would be uploaded.','files'=>$file_list,'uploaded'=>0,'failed'=>0,'dry_run'=>true]);
        exit;
    }

    // FTP connect
    $ftp = $config['ftp_ssl']
        ? @ftp_ssl_connect($config['ftp_host'], $config['ftp_port'], 30)
        : @ftp_connect($config['ftp_host'], $config['ftp_port'], 30);
    if (!$ftp) {
        echo json_encode(['error'=>'Could not connect to FTP server at '.$config['ftp_host'].':'.$config['ftp_port']]);
        exit;
    }
    if (!@ftp_login($ftp, $config['ftp_user'], $config['ftp_pass'])) {
        ftp_close($ftp);
        echo json_encode(['error'=>'FTP login failed. Check username and password.']);
        exit;
    }
    if ($config['ftp_passive']) ftp_pasv($ftp, true);

    // Upload
    $uploaded = 0; $failed = 0;
    $new_sync  = $sync_data;
    $file_list = [];

    foreach ($to_upload as $f) {
        ftp_mkdir_recursive($ftp, dirname($f['remote']));
        if (@ftp_put($ftp, $f['remote'], $f['local'], FTP_BINARY)) {
            $uploaded++;
            $new_sync[$f['theme']][$f['relative']] = $f['mtime'];
            $file_list[] = ['theme'=>$f['theme'],'file'=>$f['relative'],'size'=>format_size($f['size']),'status'=>'ok'];
        } else {
            $failed++;
            $file_list[] = ['theme'=>$f['theme'],'file'=>$f['relative'],'size'=>format_size($f['size']),'status'=>'fail'];
        }
    }

    ftp_close($ftp);
    save_sync_data($sync_file, $new_sync);

    echo json_encode(['success'=>true,'message'=>"Deploy complete: $uploaded uploaded, $failed failed.",'files'=>$file_list,'uploaded'=>$uploaded,'failed'=>$failed]);
    exit;
}

// ── AJAX scan handler ────────────────────────────────────────────────────
if (! $is_cli && isset($_POST['action']) && $_POST['action'] === 'scan') {
    header('Content-Type: application/json');
    if (! wp_verify_nonce($_POST['_nonce'] ?? '', 'theme_deploy')) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    $themes = array_filter(array_map('sanitize_text_field', $_POST['themes'] ?? []));
    if (empty($themes)) {
        echo json_encode(['error' => 'Select at least one theme.']);
        exit;
    }
    $sync_data = load_sync_data($sync_file);
    $results   = [];
    $total     = 0;
    foreach ($themes as $theme) {
        if (! is_dir($themes_dir.'/'.$theme)) continue;
        $files = scan_theme_files($themes_dir.'/'.$theme, $defaults['exclude_patterns'], []);
        $last  = $sync_data[$theme] ?? [];
        $count = 0;
        foreach ($files as $rel => $mt) {
            if ($mt > ($last[$rel] ?? 0)) $count++;
        }
        $results[$theme] = $count;
        $total += $count;
    }
    echo json_encode(['themes' => $results, 'total' => $total]);
    exit;
}

// ── AJAX reset handler ───────────────────────────────────────────────────
if (! $is_cli && isset($_POST['action']) && $_POST['action'] === 'reset') {
    header('Content-Type: application/json');
    if (! wp_verify_nonce($_POST['_nonce'] ?? '', 'theme_deploy')) {
        echo json_encode(['error' => 'Invalid security token.']);
        exit;
    }
    if (file_exists($sync_file)) {
        unlink($sync_file);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ── CLI mode ─────────────────────────────────────────────────────────────
if ($is_cli) {
    $config = $saved_config;
    if (in_array('--dry-run', $argv ?? [])) $config['dry_run'] = true;
    if (empty($config['themes'])) $config['themes'] = ['talentdove','aija-developer-theme'];

    echo "Theme Deployer (CLI)\n";
    echo "Themes: ".implode(', ',$config['themes'])."\n";
    echo "Server: ".$config['ftp_host']."\n\n";

    $sync_data = load_sync_data($sync_file);
    $to_upload = [];
    foreach ($config['themes'] as $theme) {
        $tp = $themes_dir.'/'.$theme;
        if (!is_dir($tp)) { echo "ERROR: $tp not found\n"; exit(1); }
        $files = scan_theme_files($tp, $config['exclude_patterns'], $config['include_extensions']);
        $last  = $sync_data[$theme] ?? [];
        foreach ($files as $rel => $mt) {
            if ($mt > ($last[$rel] ?? 0)) {
                $to_upload[] = ['local'=>$tp.'/'.$rel,'remote'=>rtrim($config['remote_themes_path'],'/').'/'.$theme.'/'.$rel,'theme'=>$theme,'relative'=>$rel,'mtime'=>$mt];
            }
        }
    }
    if (empty($to_upload)) { echo "No modified files.\n"; exit(0); }
    echo count($to_upload)." file(s) to upload:\n";
    foreach ($to_upload as $f) echo "  [{$f['theme']}] {$f['relative']}\n";

    if ($config['dry_run']) { echo "\nDRY RUN — nothing uploaded.\n"; exit(0); }

    $ftp = $config['ftp_ssl'] ? @ftp_ssl_connect($config['ftp_host'],$config['ftp_port'],30) : @ftp_connect($config['ftp_host'],$config['ftp_port'],30);
    if (!$ftp) { echo "ERROR: FTP connect failed.\n"; exit(1); }
    if (!@ftp_login($ftp,$config['ftp_user'],$config['ftp_pass'])) { echo "ERROR: FTP login failed.\n"; exit(1); }
    if ($config['ftp_passive']) ftp_pasv($ftp,true);

    $ok=0; $fail=0; $ns=$sync_data;
    foreach ($to_upload as $f) {
        ftp_mkdir_recursive($ftp,dirname($f['remote']));
        if (@ftp_put($ftp,$f['remote'],$f['local'],FTP_BINARY)) { echo "  OK   {$f['relative']}\n"; $ok++; $ns[$f['theme']][$f['relative']]=$f['mtime']; }
        else { echo "  FAIL {$f['relative']}\n"; $fail++; }
    }
    ftp_close($ftp);
    save_sync_data($sync_file,$ns);
    echo "\nDone: $ok uploaded, $fail failed.\n";
    exit(0);
}

// ── Browser UI ───────────────────────────────────────────────────────────
$nonce = wp_create_nonce('theme_deploy');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Theme Deployer</title>
<style>
    :root {
        --bg: #0f172a;
        --surface: #1e293b;
        --surface2: #334155;
        --border: #475569;
        --text: #e2e8f0;
        --muted: #94a3b8;
        --primary: #3b82f6;
        --primary-hover: #2563eb;
        --success: #22c55e;
        --danger: #ef4444;
        --warning: #f59e0b;
        --radius: 10px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: var(--bg);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        justify-content: center;
        padding: 40px 20px;
    }
    .deployer {
        width: 100%;
        max-width: 720px;
    }
    .deployer__header {
        text-align: center;
        margin-bottom: 32px;
    }
    .deployer__header h1 {
        font-size: 1.75rem;
        font-weight: 700;
        margin-bottom: 6px;
        letter-spacing: -0.5px;
    }
    .deployer__header p {
        color: var(--muted);
        font-size: 0.9rem;
    }
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 24px;
        margin-bottom: 20px;
    }
    .card__title {
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 1px;
        color: var(--muted);
        margin-bottom: 16px;
    }
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }
    .form-grid .full { grid-column: 1 / -1; }
    .form-group { display: flex; flex-direction: column; gap: 5px; }
    .form-group label {
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--muted);
    }
    .form-group input[type="text"],
    .form-group input[type="number"],
    .form-group input[type="password"] {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: 10px 12px;
        color: var(--text);
        font-size: 0.9rem;
        outline: none;
        transition: border-color 0.2s;
    }
    .form-group input:focus {
        border-color: var(--primary);
    }
    .checkbox-row {
        display: flex;
        gap: 20px;
        align-items: center;
        flex-wrap: wrap;
    }
    .checkbox-row label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.85rem;
        color: var(--text);
        cursor: pointer;
    }
    .checkbox-row input[type="checkbox"] {
        accent-color: var(--primary);
        width: 16px;
        height: 16px;
    }
    .theme-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 10px;
    }
    .theme-item {
        background: var(--bg);
        border: 2px solid var(--border);
        border-radius: 8px;
        padding: 12px 14px;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .theme-item:hover { border-color: var(--primary); }
    .theme-item.selected {
        border-color: var(--primary);
        background: rgba(59,130,246,0.08);
    }
    .theme-item input { display: none; }
    .theme-item__check {
        width: 20px;
        height: 20px;
        border: 2px solid var(--border);
        border-radius: 4px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.15s;
    }
    .theme-item.selected .theme-item__check {
        background: var(--primary);
        border-color: var(--primary);
    }
    .theme-item__check svg { display: none; }
    .theme-item.selected .theme-item__check svg { display: block; }
    .theme-item__info { flex: 1; min-width: 0; }
    .theme-item__name {
        font-size: 0.85rem;
        font-weight: 600;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .theme-item__badge {
        font-size: 0.7rem;
        color: var(--muted);
        margin-top: 2px;
    }
    .btn-row {
        display: flex;
        gap: 10px;
        margin-top: 8px;
    }
    .btn {
        padding: 11px 24px;
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    .btn--primary {
        background: var(--primary);
        color: #fff;
    }
    .btn--primary:hover:not(:disabled) { background: var(--primary-hover); }
    .btn--outline {
        background: transparent;
        color: var(--text);
        border: 1px solid var(--border);
    }
    .btn--outline:hover:not(:disabled) { background: var(--surface2); }
    .btn--danger {
        background: transparent;
        color: var(--danger);
        border: 1px solid var(--danger);
    }
    .btn--danger:hover:not(:disabled) { background: rgba(239,68,68,0.1); }

    /* Progress */
    .progress-wrap {
        display: none;
        margin-bottom: 20px;
    }
    .progress-wrap.visible { display: block; }
    .progress-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }
    .progress-header__text {
        font-size: 0.85rem;
        font-weight: 500;
    }
    .progress-header__count {
        font-size: 0.8rem;
        color: var(--muted);
    }
    .progress-bar {
        height: 8px;
        background: var(--bg);
        border-radius: 4px;
        overflow: hidden;
    }
    .progress-bar__fill {
        height: 100%;
        width: 0%;
        border-radius: 4px;
        transition: width 0.3s ease;
        background: var(--primary);
    }
    .progress-bar__fill.done { background: var(--success); }
    .progress-bar__fill.has-errors { background: var(--warning); }

    /* Log */
    .log-wrap {
        display: none;
    }
    .log-wrap.visible { display: block; }
    .log {
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 8px;
        max-height: 400px;
        overflow-y: auto;
        font-family: 'JetBrains Mono', 'Fira Code', monospace;
        font-size: 0.78rem;
        line-height: 1.7;
    }
    .log__entry {
        padding: 4px 14px;
        border-bottom: 1px solid rgba(71,85,105,0.3);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .log__entry:last-child { border-bottom: none; }
    .log__entry--ok .log__status { color: var(--success); }
    .log__entry--fail .log__status { color: var(--danger); }
    .log__entry--skip .log__status { color: var(--muted); }
    .log__entry--info { color: var(--primary); }
    .log__status {
        font-weight: 700;
        width: 40px;
        flex-shrink: 0;
        text-align: center;
    }
    .log__theme {
        color: var(--muted);
        width: 160px;
        flex-shrink: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .log__file { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .log__size { color: var(--muted); width: 70px; text-align: right; flex-shrink: 0; }

    /* Summary */
    .summary {
        display: none;
        padding: 16px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
        font-size: 0.9rem;
        text-align: center;
    }
    .summary.visible { display: block; }
    .summary--ok { background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); color: var(--success); }
    .summary--warn { background: rgba(245,158,11,0.12); border: 1px solid rgba(245,158,11,0.3); color: var(--warning); }
    .summary--err { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); }

    /* Scan badge */
    .scan-result {
        font-size: 0.85rem;
        color: var(--muted);
        margin-top: 10px;
        min-height: 22px;
    }
    .scan-result span { color: var(--primary); font-weight: 600; }

    /* Spinner */
    .spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid rgba(255,255,255,0.3);
        border-top-color: #fff;
        border-radius: 50%;
        animation: spin 0.6s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 540px) {
        .form-grid { grid-template-columns: 1fr; }
        .log__theme { display: none; }
    }
</style>
</head>
<body>

<div class="deployer">
    <div class="deployer__header">
        <h1>Theme Deployer</h1>
        <p>Sync modified theme files to your remote server via FTP</p>
    </div>

    <!-- FTP Connection -->
    <div class="card">
        <div class="card__title">FTP Connection</div>
        <div class="form-grid">
            <div class="form-group">
                <label for="ftp_host">Host</label>
                <input type="text" id="ftp_host" placeholder="ftp.example.com" value="<?php echo esc_attr($saved_config['ftp_host']); ?>">
            </div>
            <div class="form-group">
                <label for="ftp_port">Port</label>
                <input type="number" id="ftp_port" value="<?php echo (int)$saved_config['ftp_port']; ?>">
            </div>
            <div class="form-group">
                <label for="ftp_user">Username</label>
                <input type="text" id="ftp_user" placeholder="ftp-user" value="<?php echo esc_attr($saved_config['ftp_user']); ?>">
            </div>
            <div class="form-group">
                <label for="ftp_pass">Password</label>
                <input type="password" id="ftp_pass" placeholder="••••••••" value="<?php echo esc_attr($saved_config['ftp_pass']); ?>">
            </div>
            <div class="form-group full">
                <label for="remote_path">Remote Themes Path</label>
                <input type="text" id="remote_path" placeholder="/public_html/wp-content/themes/" value="<?php echo esc_attr($saved_config['remote_themes_path']); ?>">
            </div>
            <div class="full checkbox-row">
                <label><input type="checkbox" id="ftp_ssl" <?php echo $saved_config['ftp_ssl'] ? 'checked' : ''; ?>> Use FTPS (SSL)</label>
                <label><input type="checkbox" id="ftp_passive" <?php echo $saved_config['ftp_passive'] ? 'checked' : ''; ?>> Passive Mode</label>
                <label><input type="checkbox" id="dry_run"> Dry Run</label>
            </div>
        </div>
    </div>

    <!-- Theme Selector -->
    <div class="card">
        <div class="card__title">Select Themes</div>
        <div class="theme-grid" id="themeGrid">
            <?php foreach ($all_themes as $t):
                $style_file = $themes_dir.'/'.$t.'/style.css';
                $theme_data = get_file_data($style_file, ['Name'=>'Theme Name','Version'=>'Version']);
                $name = $theme_data['Name'] ?: $t;
                $ver  = $theme_data['Version'] ?: '';
                $preselected = in_array($t, $saved_config['themes']) || in_array($t, ['talentdove','aija-developer-theme']);
            ?>
            <label class="theme-item <?php echo $preselected ? 'selected' : ''; ?>" data-theme="<?php echo esc_attr($t); ?>">
                <input type="checkbox" name="themes[]" value="<?php echo esc_attr($t); ?>" <?php echo $preselected ? 'checked' : ''; ?>>
                <span class="theme-item__check">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M2.5 6L5 8.5L9.5 3.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span class="theme-item__info">
                    <div class="theme-item__name"><?php echo esc_html($name); ?></div>
                    <?php if ($ver): ?><div class="theme-item__badge">v<?php echo esc_html($ver); ?></div><?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="scan-result" id="scanResult"></div>
    </div>

    <!-- Actions -->
    <div class="card">
        <div class="btn-row">
            <button class="btn btn--outline" id="btnScan" onclick="scanFiles()">
                Scan Changes
            </button>
            <button class="btn btn--primary" id="btnDeploy" onclick="startDeploy()">
                Deploy Now
            </button>
            <button class="btn btn--danger" id="btnReset" onclick="resetSync()" title="Clear sync history and deploy all files next time">
                Reset Sync
            </button>
        </div>
    </div>

    <!-- Progress -->
    <div class="progress-wrap" id="progressWrap">
        <div class="progress-header">
            <span class="progress-header__text" id="progressText">Uploading...</span>
            <span class="progress-header__count" id="progressCount">0 / 0</span>
        </div>
        <div class="progress-bar">
            <div class="progress-bar__fill" id="progressFill"></div>
        </div>
    </div>

    <!-- Summary -->
    <div class="summary" id="summary"></div>

    <!-- Log -->
    <div class="log-wrap" id="logWrap">
        <div class="card" style="padding: 0; overflow: hidden;">
            <div class="log" id="log"></div>
        </div>
    </div>
</div>

<script>
const NONCE = '<?php echo $nonce; ?>';
const DEPLOY_URL = window.location.href.split('?')[0];

function getSelectedThemes() {
    return Array.from(document.querySelectorAll('#themeGrid input:checked')).map(i => i.value);
}

function getFormData(action) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_nonce', NONCE);
    fd.append('ftp_host', document.getElementById('ftp_host').value);
    fd.append('ftp_port', document.getElementById('ftp_port').value);
    fd.append('ftp_user', document.getElementById('ftp_user').value);
    fd.append('ftp_pass', document.getElementById('ftp_pass').value);
    fd.append('remote_path', document.getElementById('remote_path').value);
    if (document.getElementById('ftp_ssl').checked) fd.append('ftp_ssl', '1');
    if (document.getElementById('ftp_passive').checked) fd.append('ftp_passive', '1');
    if (document.getElementById('dry_run').checked) fd.append('dry_run', '1');
    getSelectedThemes().forEach(t => fd.append('themes[]', t));
    return fd;
}

function setButtons(disabled) {
    ['btnScan','btnDeploy','btnReset'].forEach(id => document.getElementById(id).disabled = disabled);
}

// Theme toggle
document.querySelectorAll('.theme-item').forEach(el => {
    el.addEventListener('click', (e) => {
        if (e.target.tagName === 'INPUT') return;
        const cb = el.querySelector('input');
        cb.checked = !cb.checked;
        el.classList.toggle('selected', cb.checked);
    });
});

// Scan
async function scanFiles() {
    const themes = getSelectedThemes();
    if (!themes.length) { alert('Select at least one theme.'); return; }

    const btn = document.getElementById('btnScan');
    btn.innerHTML = '<span class="spinner"></span> Scanning...';
    setButtons(true);
    document.getElementById('scanResult').textContent = '';

    try {
        const fd = new FormData();
        fd.append('action', 'scan');
        fd.append('_nonce', NONCE);
        themes.forEach(t => fd.append('themes[]', t));

        const res = await fetch(DEPLOY_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            document.getElementById('scanResult').innerHTML = '<span style="color:var(--danger)">' + data.error + '</span>';
        } else {
            let html = '<span>' + data.total + '</span> modified file(s) found';
            const parts = Object.entries(data.themes).map(([t,c]) => t + ': ' + c);
            if (parts.length > 1) html += ' &mdash; ' + parts.join(', ');
            document.getElementById('scanResult').innerHTML = html;
        }
    } catch (e) {
        document.getElementById('scanResult').innerHTML = '<span style="color:var(--danger)">Scan failed: ' + e.message + '</span>';
    } finally {
        btn.textContent = 'Scan Changes';
        setButtons(false);
    }
}

// Deploy
async function startDeploy() {
    const themes = getSelectedThemes();
    if (!themes.length) { alert('Select at least one theme.'); return; }

    const host = document.getElementById('ftp_host').value;
    const user = document.getElementById('ftp_user').value;
    if (!host || !user) { alert('Enter FTP host and username.'); return; }

    const dryRun = document.getElementById('dry_run').checked;
    if (!dryRun && !confirm('Deploy selected themes to ' + host + '?')) return;

    setButtons(true);
    const btn = document.getElementById('btnDeploy');
    btn.innerHTML = '<span class="spinner"></span> Deploying...';

    const progressWrap = document.getElementById('progressWrap');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    const progressCount = document.getElementById('progressCount');
    const logWrap = document.getElementById('logWrap');
    const log = document.getElementById('log');
    const summary = document.getElementById('summary');

    progressWrap.classList.add('visible');
    progressFill.style.width = '0%';
    progressFill.className = 'progress-bar__fill';
    progressText.textContent = dryRun ? 'Scanning...' : 'Connecting...';
    progressCount.textContent = '';
    logWrap.classList.remove('visible');
    log.innerHTML = '';
    summary.classList.remove('visible');
    summary.className = 'summary';

    // Simulate connection progress
    progressFill.style.width = '5%';

    try {
        const fd = getFormData('deploy');
        const res = await fetch(DEPLOY_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.error) {
            summary.textContent = data.error;
            summary.classList.add('visible', 'summary--err');
            progressFill.style.width = '100%';
            progressFill.classList.add('has-errors');
            progressText.textContent = 'Error';
            return;
        }

        // Animate log entries
        const files = data.files || [];
        const total = files.length;

        if (total === 0) {
            summary.textContent = data.message;
            summary.classList.add('visible', 'summary--ok');
            progressFill.style.width = '100%';
            progressFill.classList.add('done');
            progressText.textContent = 'Complete';
            progressCount.textContent = '0 files';
            return;
        }

        logWrap.classList.add('visible');
        progressText.textContent = dryRun ? 'Scan complete' : 'Uploading...';

        for (let i = 0; i < files.length; i++) {
            const f = files[i];
            const pct = Math.round(((i + 1) / total) * 100);
            progressFill.style.width = pct + '%';
            progressCount.textContent = (i + 1) + ' / ' + total;

            const cls = f.status === 'ok' ? 'log__entry--ok' : f.status === 'fail' ? 'log__entry--fail' : 'log__entry--skip';
            const statusLabel = f.status === 'ok' ? 'OK' : f.status === 'fail' ? 'FAIL' : 'SKIP';

            const entry = document.createElement('div');
            entry.className = 'log__entry ' + cls;
            entry.innerHTML =
                '<span class="log__status">' + statusLabel + '</span>' +
                '<span class="log__theme">' + f.theme + '</span>' +
                '<span class="log__file">' + f.file + '</span>' +
                '<span class="log__size">' + f.size + '</span>';
            log.appendChild(entry);
            log.scrollTop = log.scrollHeight;

            // Stagger entries for visual effect
            await new Promise(r => setTimeout(r, 30));
        }

        progressText.textContent = 'Complete';
        if (data.failed > 0) {
            progressFill.classList.add('has-errors');
            summary.classList.add('visible', 'summary--warn');
        } else {
            progressFill.classList.add('done');
            summary.classList.add('visible', 'summary--ok');
        }
        summary.textContent = data.message;

    } catch (e) {
        summary.textContent = 'Request failed: ' + e.message;
        summary.classList.add('visible', 'summary--err');
        progressFill.style.width = '100%';
        progressFill.classList.add('has-errors');
        progressText.textContent = 'Error';
    } finally {
        btn.textContent = 'Deploy Now';
        setButtons(false);
    }
}

// Reset sync
async function resetSync() {
    if (!confirm('This will clear all sync history. The next deploy will upload ALL files. Continue?')) return;

    try {
        const fd = new FormData();
        fd.append('action', 'reset');
        fd.append('_nonce', NONCE);
        await fetch(DEPLOY_URL, { method: 'POST', body: fd });

        const summary = document.getElementById('summary');
        summary.textContent = 'Sync history cleared. Next deploy will upload all files.';
        summary.className = 'summary visible summary--ok';
        document.getElementById('scanResult').textContent = '';
    } catch (e) {
        alert('Reset failed: ' + e.message);
    }
}
</script>
</body>
</html>
