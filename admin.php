<?php
/**
 * admin.php — Single-file Drop-in Admin Panel v2
 * ===============================================
 * Place this file in the root of your site. No database required.
 *
 * Features:
 *   - Multi-page management (create, rename, delete pages)
 *   - Visual block editor (text, image, gallery blocks)
 *   - Image upload with drag-and-drop support
 *   - Preview any page as rendered HTML
 *   - Export pages to .html files in site root
 *   - Statistics (page count, image count)
 *   - Changeable password (stored as bcrypt hash in .admin_config.json)
 *
 * POST endpoints:
 *   ?action=login           – Authenticate (JSON: {password})
 *   ?action=logout          – Destroy session
 *   ?action=change-password – Change admin password (JSON: {current, new})
 *   ?action=get-stats       – Return page & image counts
 *   ?action=list-pages      – Return all pages overview
 *   ?action=create-page     – Create new page (JSON: {title})
 *   ?action=delete-page     – Delete a page (JSON: {page_id})
 *   ?action=rename-page     – Rename a page  (JSON: {page_id, title})
 *   ?action=get-page        – Get one page's data (JSON: {page_id})
 *   ?action=save            – Save blocks for a page (JSON: {page_id, blocks, title?})
 *   ?action=preview         – Render a page as standalone HTML (GET: ?page_id=xxx)
 *   ?action=export-all      – Export all pages to .html files
 *   ?action=upload          – Accept image file, save to uploads/
 *   ?action=delete-file     – Delete file from uploads/
 */

declare(strict_types=1);
session_start();

// ─── CONFIG ───────────────────────────────────────────────────────────────────
define('ADMIN_PASSWORD', 'admin123');        // Default password (used until changed via UI)
define('DATA_FILE',      __DIR__ . '/data.json');
define('CONFIG_FILE',    __DIR__ . '/.admin_config.json');
define('UPLOAD_DIR',     __DIR__ . '/uploads');
define('MAX_FILE_SIZE',  10 * 1024 * 1024);  // 10 MB
define('ALLOWED_EXTS',   ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif']);

// ─── PASSWORD HELPERS ────────────────────────────────────────────────────────

/** Return the stored bcrypt hash from config file, or null. */
function get_stored_hash(): ?string {
    if (!file_exists(CONFIG_FILE)) return null;
    $data = json_decode(file_get_contents(CONFIG_FILE), true);
    return $data['password_hash'] ?? null;
}

/** Save a bcrypt hash to the config file. */
function set_stored_hash(string $hash): bool {
    return file_put_contents(CONFIG_FILE, json_encode(
        ['password_hash' => $hash], JSON_PRETTY_PRINT
    )) !== false;
}

/** Verify a password against the stored hash or the constant fallback. */
function verify_admin_password(string $pwd): bool {
    $hash = get_stored_hash();
    if ($hash !== null) {
        return password_verify($pwd, $hash);
    }
    return $pwd === ADMIN_PASSWORD;
}

// ─── AUTH HELPERS ────────────────────────────────────────────────────────────

function is_authenticated(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function json_exit(mixed $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function html_exit(string $html, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

// ─── DATA HELPERS ────────────────────────────────────────────────────────────

/** Load the full data.json (auto-migrates old format). */
function load_data(): array {
    if (!file_exists(DATA_FILE)) {
        return ['pages' => []];
    }
    $raw  = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) return ['pages' => []];

    // Auto-migrate old v1 format: {blocks: [...]} → {pages: {id: {title, slug, blocks}}}
    if (isset($data['blocks']) && !isset($data['pages'])) {
        $data = [
            'pages' => [
                generate_page_id() => [
                    'title'  => 'Main Page',
                    'slug'   => 'index',
                    'blocks' => $data['blocks'],
                ],
            ],
        ];
        save_data($data);
    }
    return $data;
}

/** Write to data.json atomically (write-then-rename). */
function save_data(array $data): bool {
    $tmp = DATA_FILE . '.tmp';
    $ok  = file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($ok === false) return false;
    return rename($tmp, DATA_FILE);
}

/** Generate a URL-safe slug from a title string. */
function slugify(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    }
    $text = preg_replace('/[^a-z0-9-]+/i', '-', $text);
    $text = trim($text, '-');
    $text = strtolower($text);
    return $text ?: 'page';
}

/** Generate a unique page ID. */
function generate_page_id(): string {
    return 'p_' . bin2hex(random_bytes(8));
}

// ─── STATISTICS ──────────────────────────────────────────────────────────────

/** Count pages and total images across all pages. */
function collect_stats(array $data): array {
    $pages  = $data['pages'] ?? [];
    $images = 0;
    foreach ($pages as $page) {
        foreach ($page['blocks'] ?? [] as $block) {
            if ($block['type'] === 'image' && !empty($block['src'])) {
                $images++;
            } elseif ($block['type'] === 'gallery') {
                $images += count($block['images'] ?? []);
            }
        }
    }
    return [
        'pages'  => count($pages),
        'images' => $images,
    ];
}

// ─── HTML RENDERER (preview & export) ────────────────────────────────────────

/** Render a page's blocks into a complete standalone HTML document. */
function render_page_html(string $title, array $blocks): string {
    $blockHtml = '';
    foreach ($blocks as $b) {
        switch ($b['type'] ?? '') {
            case 'text':
                $blockHtml .= '<section class="s-text">' . ($b['content'] ?? '') . '</section>';
                break;
            case 'image':
                $src = htmlspecialchars($b['src'] ?? '', ENT_QUOTES);
                if ($src) {
                    $blockHtml .= '<section class="s-image"><img src="' . $src . '" alt="" loading="lazy"></section>';
                }
                break;
            case 'gallery':
                $blockHtml .= '<section class="s-gallery"><div class="gallery-grid">';
                foreach ($b['images'] ?? [] as $img) {
                    $src = htmlspecialchars($img, ENT_QUOTES);
                    $blockHtml .= '<div class="g-item"><img src="' . $src . '" alt="" loading="lazy"></div>';
                }
                $blockHtml .= '</div></section>';
                break;
        }
    }

    $safeTitle = htmlspecialchars($title, ENT_QUOTES);

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$safeTitle}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', system-ui, sans-serif; color: #1a1a2e; background: #f8f9fa; line-height: 1.6; }
.s-text { max-width: 800px; margin: 0 auto; padding: 40px 24px; }
.s-text h1, .s-text h2, .s-text h3 { margin-top: 1.5em; margin-bottom: .5em; }
.s-text p { margin-bottom: 1em; color: #444; }
.s-image { max-width: 1000px; margin: 0 auto; padding: 24px; }
.s-image img { width: 100%; height: auto; border-radius: 8px; }
.s-gallery { max-width: 1000px; margin: 0 auto; padding: 24px; }
.gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 16px; }
.g-item img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 6px; }
.admin-link { position: fixed; bottom: 20px; right: 20px; background: #e94560; color: #fff; text-decoration: none;
  padding: 10px 18px; border-radius: 8px; font-size: .85rem; font-weight: 600; box-shadow: 0 4px 12px rgba(0,0,0,.2);
  transition: opacity .2s; z-index: 999; }
.admin-link:hover { opacity: .85; }
</style>
</head>
<body>
{$blockHtml}
<a class="admin-link" href="admin.php">⚡ Admin</a>
</body>
</html>
HTML;
}

/** Export a page to its .html file in the site root. Returns true on success. */
function export_page(string $slug, string $title, array $blocks): bool {
    $html = render_page_html($title, $blocks);
    $path = __DIR__ . '/' . $slug . '.html';
    return file_put_contents($path, $html) !== false;
}

/** Export all pages to .html files. */
function export_all_pages(array $data): array {
    $results = [];
    foreach ($data['pages'] ?? [] as $id => $page) {
        $slug           = $page['slug'] ?? slugify($page['title'] ?? 'page');
        $ok             = export_page($slug, $page['title'] ?? '', $page['blocks'] ?? []);
        $results[$slug] = $ok;
    }
    return $results;
}

// ─── FILE UPLOAD HELPERS ─────────────────────────────────────────────────────

function get_upload_path(string $original_name): ?string {
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTS, true)) return null;
    return UPLOAD_DIR . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
}

function ensure_upload_dir(): void {
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
}

// ─── SITE SCANNER ─────────────────────────────────────────────────────────────

/** Scan site root for .html files not yet managed by the admin panel. */
function scan_site_files(array $managedPages): array {
    $found   = [];
    $managed = [];
    foreach ($managedPages as $p) {
        $managed[] = ($p['slug'] ?? '') . '.html';
    }
    $files = glob(__DIR__ . '/*.html');
    if ($files === false) return [];

    foreach ($files as $path) {
        $basename = basename($path);
        // Skip admin.php itself (not .html) and any .html that's already managed
        if ($basename === 'admin.php' || in_array($basename, $managed, true)) continue;

        $title = pathinfo($basename, PATHINFO_FILENAME);
        $firstLine = '';
        // Try to extract <title> from the file
        $content = file_get_contents($path);
        if (preg_match('/<title>\s*(.+?)\s*<\/title>/i', $content, $m)) {
            $title = trim($m[1]);
        }

        $found[] = [
            'filename' => $basename,
            'title'    => $title,
            'size'     => filesize($path),
        ];
    }
    return $found;
}

// ═══════════════════════════════════════════════════════════════════════════════
// ROUTER
// ═══════════════════════════════════════════════════════════════════════════════

$action = $_REQUEST['action'] ?? '';

/* ─── LOGIN ──────────────────────────────────────────────────────────────── */
if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $pwd   = $input['password'] ?? '';
    if (verify_admin_password($pwd)) {
        $_SESSION['admin_logged_in'] = true;
        json_exit(['ok' => true]);
    }
    json_exit(['ok' => false, 'error' => 'Wrong password'], 403);
}

/* ─── LOGOUT ─────────────────────────────────────────────────────────────── */
if ($action === 'logout') {
    $_SESSION = [];
    session_destroy();
    json_exit(['ok' => true]);
}

// ─── PREVIEW (public, but only if page_id is valid) ─────────────────────────
// We allow preview without full auth because it renders a public page.
// But we still require auth to prevent info leakage.
if ($action === 'preview') {
    if (!is_authenticated()) {
        html_exit('<h1>Unauthorized</h1><p>Please <a href="admin.php">login</a>.</p>', 401);
    }
    $pageId = $_GET['page_id'] ?? '';
    $data   = load_data();
    $page   = $data['pages'][$pageId] ?? null;
    if (!$page) {
        html_exit('<h1>Page not found</h1>', 404);
    }
    html_exit(render_page_html($page['title'] ?? '', $page['blocks'] ?? []));
}

// ─── All remaining POST actions require auth ───────────────────────────────
if (!$action) {
    // GET — serve UI (fall through to HTML below)
} elseif (!is_authenticated()) {
    json_exit(['ok' => false, 'error' => 'Unauthorized'], 401);
}

/* ─── CHANGE PASSWORD ────────────────────────────────────────────────────── */
if ($action === 'change-password') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $curr   = $input['current'] ?? '';
    $new    = $input['new'] ?? '';

    if (!verify_admin_password($curr)) {
        json_exit(['ok' => false, 'error' => 'Current password is wrong'], 403);
    }
    if (strlen($new) < 4) {
        json_exit(['ok' => false, 'error' => 'New password must be at least 4 characters'], 400);
    }

    $hash = password_hash($new, PASSWORD_BCRYPT);
    if (set_stored_hash($hash)) {
        json_exit(['ok' => true]);
    }
    json_exit(['ok' => false, 'error' => 'Failed to save password'], 500);
}

/* ─── GET STATS ──────────────────────────────────────────────────────────── */
if ($action === 'get-stats') {
    $data = load_data();
    json_exit(collect_stats($data));
}

/* ─── LIST PAGES ─────────────────────────────────────────────────────────── */
if ($action === 'list-pages') {
    $data  = load_data();
    $pages = [];
    foreach ($data['pages'] ?? [] as $id => $page) {
        $pages[$id] = [
            'id'         => $id,
            'title'      => $page['title'] ?? '',
            'slug'       => $page['slug'] ?? '',
            'blockCount' => count($page['blocks'] ?? []),
        ];
    }
    $discovered = scan_site_files($pages);
    json_exit(['pages' => $pages, 'discovered' => $discovered]);
}

/* ─── CREATE PAGE ────────────────────────────────────────────────────────── */
if ($action === 'create-page') {
    $input = json_decode(file_get_contents('php://input'), true);
    $title = trim($input['title'] ?? '');
    if (!$title) {
        json_exit(['ok' => false, 'error' => 'Title is required'], 400);
    }
    $data = load_data();
    $id   = generate_page_id();
    $slug = slugify($title);

    // Ensure unique slug
    $existingSlugs = [];
    foreach ($data['pages'] ?? [] as $p) {
        $existingSlugs[] = $p['slug'] ?? '';
    }
    $baseSlug = $slug;
    $counter  = 1;
    while (in_array($slug, $existingSlugs, true)) {
        $slug = $baseSlug . '-' . ($counter++);
    }

    $data['pages'][$id] = [
        'title'  => $title,
        'slug'   => $slug,
        'blocks' => [],
    ];
    save_data($data);

    // Export the new page
    export_page($slug, $title, []);

    json_exit(['ok' => true, 'page_id' => $id, 'slug' => $slug]);
}

/* ─── IMPORT FILE ────────────────────────────────────────────────────────── */
if ($action === 'import-file') {
    $input = json_decode(file_get_contents('php://input'), true);
    $file  = $input['file'] ?? '';

    if (!$file || !file_exists(__DIR__ . '/' . $file)) {
        json_exit(['ok' => false, 'error' => 'File not found'], 404);
    }
    // Security: must be .html in the site root
    $real = realpath(__DIR__ . '/' . $file);
    if ($real === false || pathinfo($real, PATHINFO_EXTENSION) !== 'html' || dirname($real) !== __DIR__) {
        json_exit(['ok' => false, 'error' => 'Invalid file'], 403);
    }

    $content = file_get_contents($real);
    $slug    = pathinfo($file, PATHINFO_FILENAME);
    $title   = $slug;
    if (preg_match('/<title>\s*(.+?)\s*<\/title>/i', $content, $m)) {
        $title = trim($m[1]);
    }

    $data = load_data();
    $id   = generate_page_id();

    $data['pages'][$id] = [
        'title'  => $title,
        'slug'   => $slug,
        'blocks' => [
            ['id' => 'b1', 'type' => 'text', 'content' => $content],
        ],
    ];
    save_data($data);

    // Re-export with our template
    export_page($slug, $title, $data['pages'][$id]['blocks']);

    json_exit(['ok' => true, 'page_id' => $id, 'slug' => $slug, 'title' => $title]);
}

/* ─── DELETE PAGE ────────────────────────────────────────────────────────── */
if ($action === 'delete-page') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $pageId = $input['page_id'] ?? '';
    $data   = load_data();
    if (!isset($data['pages'][$pageId])) {
        json_exit(['ok' => false, 'error' => 'Page not found'], 404);
    }
    unset($data['pages'][$pageId]);
    save_data($data);
    json_exit(['ok' => true]);
}

/* ─── RENAME PAGE ────────────────────────────────────────────────────────── */
if ($action === 'rename-page') {
    $input  = json_decode(file_get_contents('php://input'), true);
    $pageId = $input['page_id'] ?? '';
    $title  = trim($input['title'] ?? '');
    if (!$title) {
        json_exit(['ok' => false, 'error' => 'Title is required'], 400);
    }
    $data = load_data();
    if (!isset($data['pages'][$pageId])) {
        json_exit(['ok' => false, 'error' => 'Page not found'], 404);
    }
    $data['pages'][$pageId]['title'] = $title;
    $data['pages'][$pageId]['slug']  = slugify($title);
    save_data($data);

    // Re-export with new title
    $p = $data['pages'][$pageId];
    export_page($p['slug'], $p['title'], $p['blocks'] ?? []);

    json_exit(['ok' => true, 'slug' => $p['slug']]);
}

/* ─── GET PAGE ───────────────────────────────────────────────────────────── */
if ($action === 'get-page') {
    $pageId = $_GET['page_id'] ?? ($_POST['page_id'] ?? '');
    $input  = json_decode(file_get_contents('php://input'), true);
    if (!$pageId && $input) $pageId = $input['page_id'] ?? '';
    $data = load_data();
    $page = $data['pages'][$pageId] ?? null;
    if (!$page) {
        json_exit(['ok' => false, 'error' => 'Page not found'], 404);
    }
    json_exit(['ok' => true, 'page' => $page]);
}

/* ─── SAVE ───────────────────────────────────────────────────────────────── */
if ($action === 'save') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $pageId = $body['page_id'] ?? '';
    $blocks = $body['blocks'] ?? [];
    $title  = $body['title'] ?? null;

    if (!is_array($blocks)) {
        json_exit(['ok' => false, 'error' => 'Invalid blocks'], 400);
    }
    $data = load_data();
    if (!isset($data['pages'][$pageId])) {
        json_exit(['ok' => false, 'error' => 'Page not found'], 404);
    }

    $data['pages'][$pageId]['blocks'] = $blocks;
    if ($title !== null) {
        $data['pages'][$pageId]['title'] = $title;
    }

    $ok = save_data($data);

    // Auto-export to .html
    $p = $data['pages'][$pageId];
    export_page($p['slug'], $p['title'], $blocks);

    json_exit(['ok' => $ok]);
}

/* ─── EXPORT ALL ─────────────────────────────────────────────────────────── */
if ($action === 'export-all') {
    $data    = load_data();
    $results = export_all_pages($data);
    $ok      = !in_array(false, $results, true);
    json_exit(['ok' => $ok, 'results' => $results]);
}

/* ─── UPLOAD ──────────────────────────────────────────────────────────────── */
if ($action === 'upload') {
    if (empty($_FILES['file'])) {
        json_exit(['ok' => false, 'error' => 'No file uploaded'], 400);
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_exit(['ok' => false, 'error' => 'Upload error code: ' . $file['error']], 400);
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        json_exit(['ok' => false, 'error' => 'File too large (max 10 MB)'], 413);
    }
    $dest = get_upload_path($file['name']);
    if ($dest === null) {
        json_exit(['ok' => false, 'error' => 'File type not allowed'], 415);
    }
    ensure_upload_dir();
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        json_exit(['ok' => false, 'error' => 'Failed to save file'], 500);
    }
    json_exit(['ok' => true, 'url' => 'uploads/' . basename($dest)]);
}

/* ─── DELETE FILE ────────────────────────────────────────────────────────── */
if ($action === 'delete-file') {
    $body = json_decode(file_get_contents('php://input'), true);
    $file = $body['file'] ?? '';
    if (!$file) {
        json_exit(['ok' => false, 'error' => 'No file specified'], 400);
    }
    $path       = __DIR__ . '/' . ltrim($file, '/');
    $real       = realpath($path);
    $uploadReal = realpath(UPLOAD_DIR);
    if ($real === false || $uploadReal === false || strpos($real, $uploadReal) !== 0) {
        json_exit(['ok' => false, 'error' => 'Invalid path'], 403);
    }
    if (file_exists($real) && unlink($real)) {
        json_exit(['ok' => true]);
    }
    json_exit(['ok' => false, 'error' => 'File not found'], 404);
}

// ─── Unknown action guard ─────────────────────────────────────────────────
if ($action) {
    json_exit(['ok' => false, 'error' => 'Unknown action'], 400);
}

/* ===========================================================================
   HTML — Admin Panel Interface
   =========================================================================== */
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>⚡ Admin Panel</title>
<style>
/* ═══ CSS Variables (Dark Theme) ═══════════════════════════════════════════ */
:root {
    --bg-primary:   #0d0d1a;
    --bg-secondary: #16162b;
    --bg-card:      #1e1e3a;
    --bg-canvas:    #0d0d1a;
    --text-primary: #e0e0f0;
    --text-secondary: #8888aa;
    --accent:       #e94560;
    --accent-hover: #ff6b81;
    --success:      #2ecc71;
    --warning:      #f39c12;
    --border:       #2a2a4a;
    --radius:       8px;
    --radius-sm:    4px;
    --shadow:       0 4px 24px rgba(0,0,0,.5);
    --font:         'Segoe UI', system-ui, -apple-system, sans-serif;
    --topbar-h:     56px;
    --sidebar-w:    260px;
    --transition:   .2s ease;
}

* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: var(--font);
    background: var(--bg-primary);
    color: var(--text-primary);
    height: 100vh;
    overflow: hidden;
    font-size: 14px;
}

/* ═══ Buttons ══════════════════════════════════════════════════════════════ */
.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 16px;
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    font-size: .82rem;
    font-weight: 600;
    transition: background var(--transition), opacity var(--transition);
    white-space: nowrap;
}
.btn:disabled { opacity: .5; cursor: not-allowed; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover:not(:disabled) { background: var(--accent-hover); }
.btn-ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
.btn-ghost:hover:not(:disabled) { background: var(--border); color: var(--text-primary); }
.btn-sm { padding: 4px 10px; font-size: .75rem; border-radius: var(--radius-sm); }
.btn-success { background: var(--success); color: #fff; }
.btn-success:hover:not(:disabled) { opacity: .85; }
.btn-icon {
    width: 28px; height: 28px; padding: 0;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: var(--radius-sm); font-size: .85rem;
}

/* ═══ LOGIN SCREEN ═════════════════════════════════════════════════════════ */
#login-screen {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100vh;
    background: var(--bg-primary);
}
#login-screen .card {
    background: var(--bg-secondary);
    padding: 40px;
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    text-align: center;
    width: 340px;
}
#login-screen h1 { margin-bottom: 4px; font-size: 1.4rem; }
#login-screen p { color: var(--text-secondary); margin-bottom: 24px; font-size: .85rem; }
#login-screen input[type="password"] {
    width: 100%; padding: 11px 16px;
    border: 1px solid var(--border); border-radius: var(--radius);
    background: var(--bg-primary); color: var(--text-primary);
    font-size: .95rem; outline: none; transition: border-color var(--transition);
}
#login-screen input[type="password"]:focus { border-color: var(--accent); }
#login-screen .btn { width: 100%; margin-top: 16px; padding: 11px; font-size: .95rem; justify-content: center; }
#login-error { color: var(--accent); margin-top: 12px; font-size: .82rem; display: none; }

/* ═══ MAIN APP LAYOUT ══════════════════════════════════════════════════════ */
#app { display: none; height: 100vh; flex-direction: column; }

/* ─── Top Bar ───────────────────────────────────────────────────────────── */
.topbar {
    display: flex; align-items: center; gap: 12px;
    padding: 0 20px; height: var(--topbar-h);
    background: var(--bg-secondary); border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.topbar .logo { font-size: 1rem; font-weight: 700; margin-right: auto; white-space: nowrap; }
.topbar .logo span { color: var(--accent); }
.topbar-stats {
    display: flex; gap: 16px; margin-right: 16px;
    font-size: .78rem; color: var(--text-secondary);
}
.topbar-stats .stat { display: flex; align-items: center; gap: 4px; }
.topbar-stats .stat strong { color: var(--text-primary); }
.topbar-actions { display: flex; align-items: center; gap: 8px; }

/* ─── Body: Sidebar + Canvas ──────────────────────────────────────────────── */
.body { display: flex; flex: 1; overflow: hidden; }

/* ─── Sidebar ────────────────────────────────────────────────────────────── */
.sidebar {
    width: var(--sidebar-w); background: var(--bg-secondary);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column; flex-shrink: 0;
}
.sidebar-section { padding: 14px 14px 10px; }
.sidebar-section:not(:last-child) { border-bottom: 1px solid var(--border); }
.sidebar-section h3 {
    font-size: .68rem; text-transform: uppercase; letter-spacing: 1px;
    color: var(--text-secondary); margin-bottom: 10px;
    display: flex; align-items: center; justify-content: space-between;
}

/* Pages list */
.page-list { list-style: none; max-height: 200px; overflow-y: auto; }
.page-list li {
    display: flex; align-items: center; gap: 6px;
    padding: 7px 10px; margin-bottom: 2px;
    border-radius: var(--radius-sm); cursor: pointer;
    transition: background var(--transition);
    font-size: .82rem;
}
.page-list li:hover { background: var(--bg-card); }
.page-list li.active { background: var(--bg-card); border-left: 2px solid var(--accent); }
.page-list li .page-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.page-list li .page-badge {
    font-size: .65rem; color: var(--text-secondary);
    background: var(--bg-primary); padding: 1px 6px; border-radius: 10px;
}
.page-list li .btn-icon { opacity: 0; transition: opacity var(--transition); }
.page-list li:hover .btn-icon { opacity: 1; }
.page-list .sep { font-size: .65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: .5px; padding: 8px 10px 4px; cursor: default; border-left: none !important; pointer-events: none; }
.page-list .sep:hover { background: transparent; }
.page-list .disc-li { opacity: .75; }
.page-list .disc-li:hover { opacity: 1; }

/* Widgets */
.widget-list { display: flex; flex-direction: column; gap: 4px; }
.widget-item {
    display: flex; align-items: center; gap: 10px;
    padding: 9px 12px; border-radius: var(--radius-sm);
    cursor: pointer; user-select: none;
    transition: background var(--transition), transform var(--transition);
    border: 1px solid transparent; font-size: .82rem;
}
.widget-item:hover { background: var(--bg-card); border-color: var(--accent); transform: translateX(2px); }
.widget-item .icon { font-size: 1.1rem; flex-shrink: 0; }
.widget-item .label { font-weight: 500; }

/* ─── Canvas ──────────────────────────────────────────────────────────────── */
.canvas-wrap {
    flex: 1; display: flex; flex-direction: column; overflow: hidden;
    background: var(--bg-canvas);
}
.canvas-toolbar {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px; border-bottom: 1px solid var(--border);
    background: var(--bg-secondary); flex-shrink: 0;
}
.canvas-toolbar .page-title-input {
    flex: 1; background: transparent; border: none;
    color: var(--text-primary); font-size: 1rem; font-weight: 600;
    outline: none; padding: 4px 0;
}
.canvas-toolbar .page-title-input::placeholder { color: var(--text-secondary); }

.canvas {
    flex: 1; padding: 24px; overflow-y: auto;
    background-image: radial-gradient(circle, var(--border) 1px, transparent 1px);
    background-size: 24px 24px;
}
.canvas-drop-zone {
    min-height: 200px;
    transition: background var(--transition), outline var(--transition);
}
.canvas-drop-zone.drag-over {
    background: rgba(233,69,96,.05);
    outline: 2px dashed var(--accent); outline-offset: -8px;
    border-radius: var(--radius);
}
.canvas-empty {
    display: flex; align-items: center; justify-content: center;
    height: 300px; color: var(--text-secondary); font-size: .9rem;
    flex-direction: column; gap: 8px;
}
.canvas-empty .big-icon { font-size: 3rem; opacity: .3; }

/* ─── Blocks ──────────────────────────────────────────────────────────────── */
.block {
    background: var(--bg-secondary);
    border: 1px solid var(--border); border-radius: var(--radius);
    padding: 16px 20px; margin-bottom: 14px;
    transition: box-shadow var(--transition);
}
.block:hover { box-shadow: 0 0 0 1px var(--accent); }
.block-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 10px; font-size: .68rem; text-transform: uppercase;
    letter-spacing: .5px; color: var(--text-secondary);
}
.block-header .block-actions { display: flex; gap: 4px; }
.block-header .block-type { font-weight: 600; }

/* Text */
.block-text[contenteditable="true"] {
    outline: none; min-height: 36px; line-height: 1.7;
    font-size: .92rem; padding: 4px 8px; border-radius: var(--radius-sm);
    transition: background var(--transition);
}
.block-text[contenteditable="true"]:focus {
    background: rgba(255,255,255,.03);
    box-shadow: inset 0 0 0 1px var(--accent);
}

/* Image block as drop target */
.block-image {
    position: relative;
}
.block-image .img-preview {
    max-width: 100%; max-height: 350px; border-radius: 4px;
    display: block; margin-bottom: 10px;
}
.block-image .upload-area {
    border: 2px dashed var(--border); border-radius: var(--radius-sm);
    padding: 32px 16px; text-align: center; cursor: pointer;
    transition: border-color var(--transition), background var(--transition);
    color: var(--text-secondary); font-size: .82rem;
}
.block-image .upload-area:hover,
.block-image .upload-area.drag-over {
    border-color: var(--accent); background: rgba(233,69,96,.05);
}
.block-image .upload-area .icon { font-size: 1.5rem; display: block; margin-bottom: 6px; }
.block-image .upload-progress { margin-top: 8px; font-size: .8rem; color: var(--text-secondary); }
.block-image input[type="file"] { display: none; }

/* Gallery */
.block-gallery .gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px; margin-top: 10px;
}
.block-gallery .g-item {
    position: relative; border-radius: 4px; overflow: hidden;
    aspect-ratio: 1; background: var(--bg-card);
    border: 1px solid transparent; transition: border-color var(--transition);
}
.block-gallery .g-item:hover { border-color: var(--accent); }
.block-gallery .g-item img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.block-gallery .g-item .remove-img {
    position: absolute; top: 4px; right: 4px;
    width: 22px; height: 22px; border: none; border-radius: 50%;
    background: rgba(0,0,0,.7); color: #fff; cursor: pointer;
    font-size: .7rem; display: flex; align-items: center; justify-content: center;
    opacity: 0; transition: opacity var(--transition);
}
.block-gallery .g-item:hover .remove-img { opacity: 1; }
.block-gallery .g-item .remove-img:hover { background: var(--accent); }
.block-gallery .gallery-add-btn {
    display: flex; align-items: center; justify-content: center;
    border: 2px dashed var(--border); border-radius: 4px;
    aspect-ratio: 1; cursor: pointer; color: var(--text-secondary);
    font-size: 1.8rem; transition: border-color var(--transition), color var(--transition), background var(--transition);
}
.block-gallery .gallery-add-btn:hover {
    border-color: var(--accent); color: var(--accent); background: rgba(233,69,96,.05);
}
.block-gallery .gallery-add-btn.drag-over {
    border-color: var(--accent); background: rgba(233,69,96,.08);
}

/* ═══ MODAL ════════════════════════════════════════════════════════════════ */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.6); backdrop-filter: blur(4px);
    z-index: 1000; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal {
    background: var(--bg-secondary); border-radius: var(--radius);
    padding: 28px; width: 400px; max-width: 90vw;
    box-shadow: var(--shadow);
}
.modal h2 { font-size: 1.1rem; margin-bottom: 16px; }
.modal p { color: var(--text-secondary); font-size: .85rem; margin-bottom: 16px; }
.modal label { display: block; font-size: .8rem; color: var(--text-secondary); margin-bottom: 4px; }
.modal input[type="text"],
.modal input[type="password"] {
    width: 100%; padding: 10px 14px;
    border: 1px solid var(--border); border-radius: var(--radius-sm);
    background: var(--bg-primary); color: var(--text-primary);
    font-size: .9rem; outline: none; transition: border-color var(--transition);
    margin-bottom: 12px;
}
.modal input:focus { border-color: var(--accent); }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px; }

/* ═══ TOAST ════════════════════════════════════════════════════════════════ */
.toast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 12px 24px; border-radius: var(--radius);
    background: var(--bg-card); color: var(--text-primary);
    box-shadow: var(--shadow); font-size: .82rem; z-index: 2000;
    opacity: 0; transform: translateY(12px);
    transition: opacity .3s, transform .3s; pointer-events: none;
}
.toast.show { opacity: 1; transform: translateY(0); }
.toast.success { border-left: 3px solid var(--success); }
.toast.error { border-left: 3px solid var(--accent); }

/* ═══ SCROLLBAR ════════════════════════════════════════════════════════════ */
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--text-secondary); }
</style>
</head>
<body>

<!-- ═══ LOGIN SCREEN ═══════════════════════════════════════════════════════ -->
<div id="login-screen">
    <div class="card">
        <h1>🔐 Admin Panel</h1>
        <p>Enter password to continue</p>
        <input type="password" id="password-input" placeholder="Password" aria-label="Password" autofocus>
        <button class="btn btn-primary" id="login-btn">Sign In</button>
        <div id="login-error">Wrong password</div>
    </div>
</div>

<!-- ═══ MAIN APP ════════════════════════════════════════════════════════════ -->
<div id="app">
    <!-- Top Bar -->
    <div class="topbar">
        <div class="logo">⚡<span>Site</span>Admin</div>
        <div class="topbar-stats" id="stats-bar">
            <span class="stat">📄 Pages: <strong id="stat-pages">0</strong></span>
            <span class="stat">🖼️ Images: <strong id="stat-images">0</strong></span>
        </div>
        <div class="topbar-actions">
            <span id="save-status"></span>
            <button class="btn btn-ghost btn-sm" id="preview-btn" title="Preview current page">👁️ Preview</button>
            <button class="btn btn-ghost btn-sm" id="export-btn" title="Export all pages to .html">📦 Export</button>
            <button class="btn btn-ghost btn-sm" id="password-btn" title="Change password">🔑 Password</button>
            <button class="btn btn-primary btn-sm" id="save-btn">💾 Save</button>
            <button class="btn btn-ghost btn-sm" id="logout-btn">Logout</button>
        </div>
    </div>

    <!-- Body -->
    <div class="body">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Pages -->
            <div class="sidebar-section">
                <h3>📄 Pages <button class="btn btn-ghost btn-sm" id="add-page-btn">+ Add</button></h3>
                <ul class="page-list" id="page-list"></ul>
            </div>
            <!-- Widgets -->
            <div class="sidebar-section">
                <h3>🧩 Widgets</h3>
                <div class="widget-list">
                    <div class="widget-item" data-type="text">
                        <span class="icon">📝</span>
                        <span class="label">Text</span>
                    </div>
                    <div class="widget-item" data-type="image">
                        <span class="icon">🖼️</span>
                        <span class="label">Image</span>
                    </div>
                    <div class="widget-item" data-type="gallery">
                        <span class="icon">🗂️</span>
                        <span class="label">Gallery</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Canvas -->
        <div class="canvas-wrap">
            <div class="canvas-toolbar">
                <input class="page-title-input" id="page-title-input" placeholder="Page title..." spellcheck="false">
            </div>
            <div class="canvas" id="canvas">
                <div class="canvas-drop-zone" id="canvas-drop">
                    <div class="canvas-empty" id="canvas-empty">
                        <div class="big-icon">📦</div>
                        <div>Click a widget from the sidebar to add content</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MODALS ══════════════════════════════════════════════════════════════ -->
<!-- Change Password -->
<div class="modal-overlay" id="password-modal">
    <div class="modal">
        <h2>🔑 Change Password</h2>
        <label for="pwd-current">Current password</label>
        <input type="password" id="pwd-current" autocomplete="off">
        <label for="pwd-new">New password (min 4 chars)</label>
        <input type="password" id="pwd-new" autocomplete="off">
        <label for="pwd-confirm">Confirm new password</label>
        <input type="password" id="pwd-confirm" autocomplete="off">
        <div class="modal-actions">
            <button class="btn btn-ghost" id="pwd-cancel">Cancel</button>
            <button class="btn btn-primary" id="pwd-save">Change</button>
        </div>
        <div id="pwd-error" style="color:var(--accent);font-size:.8rem;margin-top:8px;display:none;"></div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
/* ==========================================================================
   JavaScript — Admin Panel Client
   ========================================================================== */

// ─── State ────────────────────────────────────────────────────────────────────
let pages           = {};           // {page_id: {id, title, slug, blockCount}}
let discoveredFiles = [];           // [{filename, title, size}] from site root
let pageOrder       = [];           // ordered array of page IDs
let currentPageId   = null;         // ID of the page currently being edited
let blocks          = [];           // blocks of current page
let blockIdCounter  = 0;
let isSaving        = false;

// ─── DOM refs ─────────────────────────────────────────────────────────────────
const $ = (s, ctx = document) => ctx.querySelector(s);
const $$ = (s, ctx = document) => [...ctx.querySelectorAll(s)];

const canvas        = document.getElementById('canvas');
const canvasDrop    = document.getElementById('canvas-drop');
const canvasEmpty   = document.getElementById('canvas-empty');
const pageList      = document.getElementById('page-list');
const pageTitleInput = document.getElementById('page-title-input');
const loginScreen   = document.getElementById('login-screen');
const app           = document.getElementById('app');
const passwordInput = document.getElementById('password-input');
const loginBtn      = document.getElementById('login-btn');
const loginError    = document.getElementById('login-error');
const saveBtn       = document.getElementById('save-btn');
const previewBtn    = document.getElementById('preview-btn');
const exportBtn     = document.getElementById('export-btn');
const passwordBtn   = document.getElementById('password-btn');
const logoutBtn     = document.getElementById('logout-btn');
const addPageBtn    = document.getElementById('add-page-btn');
const saveStatus    = document.getElementById('save-status');
const toastEl       = document.getElementById('toast');
const statPages     = document.getElementById('stat-pages');
const statImages    = document.getElementById('stat-images');
const pwdModal      = document.getElementById('password-modal');
const pwdCurrent    = document.getElementById('pwd-current');
const pwdNew        = document.getElementById('pwd-new');
const pwdConfirm    = document.getElementById('pwd-confirm');
const pwdCancel     = document.getElementById('pwd-cancel');
const pwdSave       = document.getElementById('pwd-save');
const pwdError      = document.getElementById('pwd-error');

let toastTimer = null;

// ─── Toast ────────────────────────────────────────────────────────────────────
function showToast(msg, type = 'success') {
    toastEl.textContent = msg;
    toastEl.className = 'toast ' + type;
    clearTimeout(toastTimer);
    void toastEl.offsetWidth;
    toastEl.classList.add('show');
    toastTimer = setTimeout(() => toastEl.classList.remove('show'), 2500);
}

// ─── ID generation ────────────────────────────────────────────────────────────
function nextBlockId() { return 'b' + (++blockIdCounter); }

// ─── Auth ─────────────────────────────────────────────────────────────────────
async function login(password) {
    try {
        const res = await fetch('?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password })
        });
        const data = await res.json();
        if (data.ok) {
            loginScreen.style.display = 'none';
            app.style.display = 'flex';
            await initApp();
        } else {
            loginError.style.display = 'block';
        }
    } catch {
        loginError.textContent = 'Network error';
        loginError.style.display = 'block';
    }
}

loginBtn.addEventListener('click', () => login(passwordInput.value));
passwordInput.addEventListener('keydown', e => { if (e.key === 'Enter') login(passwordInput.value); });

logoutBtn.addEventListener('click', async () => {
    await fetch('?action=logout', { method: 'POST' });
    app.style.display = 'none';
    loginScreen.style.display = 'flex';
    passwordInput.value = '';
    loginError.style.display = 'none';
});

// ─── Stats ────────────────────────────────────────────────────────────────────
async function refreshStats() {
    try {
        const res = await fetch('?action=get-stats');
        const s = await res.json();
        statPages.textContent  = s.pages ?? 0;
        statImages.textContent = s.images ?? 0;
    } catch {}
}

// ─── App Init ─────────────────────────────────────────────────────────────────
async function initApp() {
    await refreshStats();
    await loadPageList();
}

// ─── Page List ────────────────────────────────────────────────────────────────
async function loadPageList() {
    try {
        const res = await fetch('?action=list-pages');
        const data = await res.json();
        pages = data.pages || {};
        discoveredFiles = data.discovered || [];
        pageOrder = Object.keys(pages);
        renderPageList();
        // Select first page if none selected
        if (!currentPageId && pageOrder.length > 0) {
            await selectPage(pageOrder[0]);
        }
    } catch {
        showToast('Failed to load pages', 'error');
    }
}

function renderPageList() {
    pageList.innerHTML = '';

    // Managed pages
    pageOrder.forEach(id => {
        const p = pages[id];
        if (!p) return;
        const li = document.createElement('li');
        li.className = id === currentPageId ? 'active' : '';
        li.dataset.pageId = id;

        const titleSpan = document.createElement('span');
        titleSpan.className = 'page-title';
        titleSpan.textContent = p.title || 'Untitled';
        li.appendChild(titleSpan);

        const badge = document.createElement('span');
        badge.className = 'page-badge';
        badge.textContent = p.blockCount || 0;
        li.appendChild(badge);

        // Rename button
        const renameBtn = document.createElement('button');
        renameBtn.className = 'btn btn-icon btn-ghost';
        renameBtn.textContent = '✎';
        renameBtn.title = 'Rename';
        renameBtn.setAttribute('aria-label', `Rename page ${p.title || 'Untitled'}`);
        renameBtn.addEventListener('click', async e => {
            e.stopPropagation();
            const newTitle = prompt('New title:', p.title);
            if (newTitle && newTitle.trim() && newTitle !== p.title) {
                await renamePage(id, newTitle.trim());
            }
        });
        li.appendChild(renameBtn);

        // Delete button
        const delBtn = document.createElement('button');
        delBtn.className = 'btn btn-icon btn-ghost';
        delBtn.textContent = '✕';
        delBtn.title = 'Delete page';
        delBtn.setAttribute('aria-label', `Delete page ${p.title || 'Untitled'}`);
        delBtn.addEventListener('click', async e => {
            e.stopPropagation();
            if (!confirm(`Delete "${p.title}"? This cannot be undone.`)) return;
            await deletePage(id);
        });
        li.appendChild(delBtn);

        li.addEventListener('click', () => selectPage(id));
        pageList.appendChild(li);
    });

    // Discovered files from site root
    if (discoveredFiles.length > 0) {
        const sep = document.createElement('li');
        sep.className = 'sep';
        sep.textContent = '── Site files ──';
        pageList.appendChild(sep);

        discoveredFiles.forEach(f => {
            const li = document.createElement('li');
            li.className = 'disc-li';

            const titleSpan = document.createElement('span');
            titleSpan.className = 'page-title';
            titleSpan.textContent = f.title + ' (' + f.filename + ')';
            li.appendChild(titleSpan);

            const importBtn = document.createElement('button');
            importBtn.className = 'btn btn-icon btn-ghost';
            importBtn.textContent = '📥';
            importBtn.title = 'Import into editor';
            importBtn.setAttribute('aria-label', `Import site file ${f.filename}`);
            importBtn.addEventListener('click', async e => {
                e.stopPropagation();
                await importSiteFile(f.filename);
            });
            li.appendChild(importBtn);

            pageList.appendChild(li);
        });
    }
}

/** Import a discovered .html file as a managed page. */
async function importSiteFile(filename) {
    try {
        const res = await fetch('?action=import-file', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ file: filename })
        });
        const data = await res.json();
        if (data.ok) {
            showToast('Imported: ' + data.title);
            await loadPageList();
            await selectPage(data.page_id);
            await refreshStats();
        } else {
            showToast(data.error || 'Import failed', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    }
}

async function selectPage(id) {
    if (id === currentPageId) return;
    try {
        const res = await fetch('?action=get-page', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ page_id: id })
        });
        const data = await res.json();
        if (!data.ok || !data.page) throw new Error('Page not found');
        currentPageId = id;
        blocks = data.page.blocks || [];
        blocks.forEach(b => { if (!b.id) b.id = nextBlockId(); });
        pageTitleInput.value = data.page.title || '';
        renderBlocks();
        renderPageList();
    } catch {
        showToast('Failed to load page', 'error');
    }
}

// ─── Create / Rename / Delete Page ────────────────────────────────────────────

addPageBtn.addEventListener('click', async () => {
    const title = prompt('New page title:');
    if (!title || !title.trim()) return;
    try {
        const res = await fetch('?action=create-page', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title: title.trim() })
        });
        const data = await res.json();
        if (data.ok) {
            showToast('Page created');
            await loadPageList();
            await selectPage(data.page_id);
            await refreshStats();
        } else {
            showToast(data.error || 'Failed to create page', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    }
});

async function renamePage(id, title) {
    try {
        const res = await fetch('?action=rename-page', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ page_id: id, title })
        });
        const data = await res.json();
        if (data.ok) {
            if (pages[id]) pages[id].title = title;
            if (id === currentPageId) pageTitleInput.value = title;
            renderPageList();
            showToast('Page renamed');
        } else {
            showToast(data.error || 'Failed to rename', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    }
}

async function deletePage(id) {
    try {
        const res = await fetch('?action=delete-page', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ page_id: id })
        });
        const data = await res.json();
        if (data.ok) {
            showToast('Page deleted');
            delete pages[id];
            pageOrder = pageOrder.filter(pid => pid !== id);
            if (currentPageId === id) {
                currentPageId = null;
                blocks = [];
                if (pageOrder.length > 0) {
                    await selectPage(pageOrder[0]);
                } else {
                    renderBlocks();
                    renderPageList();
                }
            } else {
                renderPageList();
            }
            await refreshStats();
        } else {
            showToast(data.error || 'Failed to delete', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    }
}

// ─── Title input ──────────────────────────────────────────────────────────────
pageTitleInput.addEventListener('change', () => {
    if (!currentPageId) return;
    pages[currentPageId].title = pageTitleInput.value;
});

// ─── Render blocks ────────────────────────────────────────────────────────────
function renderBlocks() {
    canvasDrop.querySelectorAll('.block').forEach(el => el.remove());
    if (blocks.length === 0) {
        canvasEmpty.style.display = 'flex';
        return;
    }
    canvasEmpty.style.display = 'none';
    blocks.forEach((block, idx) => {
        const el = createBlockElement(block, idx);
        canvasDrop.appendChild(el);
    });
}

function createBlockElement(block, index) {
    const div = document.createElement('div');
    div.className = 'block';
    div.dataset.blockId = block.id;

    // Header
    const header = document.createElement('div');
    header.className = 'block-header';
    header.innerHTML = `<span class="block-type">${block.type}</span>`;

    const actions = document.createElement('div');
    actions.className = 'block-actions';

    // Move up
    if (index > 0) {
        const upBtn = document.createElement('button');
        upBtn.className = 'btn btn-icon btn-ghost';
        upBtn.textContent = '↑';
        upBtn.title = 'Move up';
        upBtn.setAttribute('aria-label', `Move ${block.type} block up`);
        upBtn.addEventListener('click', () => moveBlock(block.id, -1));
        actions.appendChild(upBtn);
    }
    // Move down
    if (index < blocks.length - 1) {
        const downBtn = document.createElement('button');
        downBtn.className = 'btn btn-icon btn-ghost';
        downBtn.textContent = '↓';
        downBtn.title = 'Move down';
        downBtn.setAttribute('aria-label', `Move ${block.type} block down`);
        downBtn.addEventListener('click', () => moveBlock(block.id, 1));
        actions.appendChild(downBtn);
    }
    // Delete
    const delBtn = document.createElement('button');
    delBtn.className = 'btn btn-icon btn-ghost';
    delBtn.textContent = '✕';
    delBtn.title = 'Delete block';
    delBtn.setAttribute('aria-label', `Delete ${block.type} block`);
    delBtn.addEventListener('click', () => removeBlock(block.id));
    actions.appendChild(delBtn);

    header.appendChild(actions);
    div.appendChild(header);

    // Body
    switch (block.type) {
        case 'text':    div.appendChild(createTextBody(block)); break;
        case 'image':   div.appendChild(createImageBody(block)); break;
        case 'gallery': div.appendChild(createGalleryBody(block)); break;
    }
    return div;
}

// ─── Move block ───────────────────────────────────────────────────────────────
function moveBlock(id, direction) {
    const idx = blocks.findIndex(b => b.id === id);
    if (idx === -1) return;
    const newIdx = idx + direction;
    if (newIdx < 0 || newIdx >= blocks.length) return;
    [blocks[idx], blocks[newIdx]] = [blocks[newIdx], blocks[idx]];
    renderBlocks();
}

// ─── Remove block ─────────────────────────────────────────────────────────────
function removeBlock(id) {
    const block = blocks.find(b => b.id === id);
    const type = block ? block.type : 'block';
    if (!confirm(`Delete this ${type}? This cannot be undone.`)) return;
    blocks = blocks.filter(b => b.id !== id);
    renderBlocks();
}

// ─── Text block ───────────────────────────────────────────────────────────────
function createTextBody(block) {
    const el = document.createElement('div');
    el.className = 'block-text';
    el.contentEditable = 'true';
    el.innerHTML = block.content || '<p>Enter text here...</p>';
    el.addEventListener('input', () => { block.content = el.innerHTML; });
    return el;
}

// ─── Image block (with DnD file upload) ────────────────────────────────────────
function createImageBody(block) {
    const wrapper = document.createElement('div');
    wrapper.className = 'block-image';

    const img = document.createElement('img');
    img.className = 'img-preview';
    if (block.src) {
        img.src = block.src;
        img.alt = '';
    } else {
        img.style.display = 'none';
    }
    wrapper.appendChild(img);

    const uploadArea = document.createElement('div');
    uploadArea.className = 'upload-area';
    uploadArea.innerHTML = '<span class="icon">📁</span> Click or drag an image here';

    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.addEventListener('change', () => {
        if (fileInput.files[0]) uploadFile(fileInput.files[0], block, img, uploadArea);
    });
    uploadArea.appendChild(fileInput);

    // Click to upload
    uploadArea.addEventListener('click', () => fileInput.click());

    // Drag-and-drop for image files
    uploadArea.addEventListener('dragover', e => {
        e.preventDefault();
        uploadArea.classList.add('drag-over');
    });
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('drag-over');
    });
    uploadArea.addEventListener('drop', e => {
        e.preventDefault();
        uploadArea.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length > 0 && files[0].type.startsWith('image/')) {
            uploadFile(files[0], block, img, uploadArea);
        }
    });

    const progress = document.createElement('div');
    progress.className = 'upload-progress';
    wrapper.appendChild(uploadArea);
    wrapper.appendChild(progress);

    return wrapper;
}

/** Upload a single image file, update block and preview. */
async function uploadFile(file, block, imgEl, areaEl) {
    const progress = areaEl.parentElement.querySelector('.upload-progress');
    if (!progress) return;
    progress.textContent = '⏳ Uploading...';
    const formData = new FormData();
    formData.append('file', file);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.ok) {
            block.src = data.url;
            imgEl.src = data.url;
            imgEl.style.display = 'block';
            progress.textContent = '✅ Uploaded';
            showToast('Image uploaded');
        } else {
            progress.textContent = '❌ ' + (data.error || 'Failed');
        }
    } catch {
        progress.textContent = '❌ Network error';
    }
}

// ─── Gallery block (with DnD) ─────────────────────────────────────────────────
function createGalleryBody(block) {
    const wrapper = document.createElement('div');
    wrapper.className = 'block-gallery';
    if (!block.images) block.images = [];

    const grid = document.createElement('div');
    grid.className = 'gallery-grid';
    renderGalleryGrid(grid, block);
    wrapper.appendChild(grid);

    // Hidden file input
    const fileInput = document.createElement('input');
    fileInput.type = 'file';
    fileInput.accept = 'image/*';
    fileInput.multiple = true;
    fileInput.style.display = 'none';
    fileInput.addEventListener('change', async () => {
        for (const file of Array.from(fileInput.files)) {
            await uploadGalleryImage(file, block, grid);
        }
        renderGalleryGrid(grid, block);
        showToast('Gallery updated');
    });
    wrapper.appendChild(fileInput);

    // Add button
    const addBtn = document.createElement('div');
    addBtn.className = 'gallery-add-btn';
    addBtn.textContent = '+';
    addBtn.addEventListener('click', () => fileInput.click());

    // DnD on add button / grid
    const dndTarget = grid;

    dndTarget.addEventListener('dragover', e => {
        if (e.dataTransfer.types.includes('Files')) {
            e.preventDefault();
            addBtn.classList.add('drag-over');
        }
    });
    dndTarget.addEventListener('dragleave', () => {
        addBtn.classList.remove('drag-over');
    });
    dndTarget.addEventListener('drop', async e => {
        e.preventDefault();
        addBtn.classList.remove('drag-over');
        const files = Array.from(e.dataTransfer.files).filter(f => f.type.startsWith('image/'));
        for (const file of files) {
            await uploadGalleryImage(file, block, grid);
        }
        renderGalleryGrid(grid, block);
        if (files.length > 0) showToast('Gallery updated');
    });

    grid.appendChild(addBtn);
    return wrapper;
}

async function uploadGalleryImage(file, block, grid) {
    const formData = new FormData();
    formData.append('file', file);
    try {
        const res = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.ok) block.images.push(data.url);
    } catch { /* skip */ }
}

function renderGalleryGrid(grid, block) {
    grid.querySelectorAll('.g-item').forEach(el => el.remove());
    block.images.forEach((url, idx) => {
        const item = document.createElement('div');
        item.className = 'g-item';
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.loading = 'lazy';
        item.appendChild(img);
        const rmBtn = document.createElement('button');
        rmBtn.className = 'remove-img';
        rmBtn.textContent = '✕';
        rmBtn.addEventListener('click', async e => {
            e.stopPropagation();
            await fetch('?action=delete-file', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ file: url })
            }).catch(() => {});
            block.images.splice(idx, 1);
            renderGalleryGrid(grid, block);
        });
        item.appendChild(rmBtn);
        const addBtn = grid.querySelector('.gallery-add-btn');
        grid.insertBefore(item, addBtn);
    });
}

// ─── Add widget (click) ───────────────────────────────────────────────────────
$$('.widget-item').forEach(w => {
    w.addEventListener('click', () => {
        if (!currentPageId) {
            showToast('Select a page first', 'error');
            return;
        }
        const type = w.dataset.type;
        const block = { id: nextBlockId(), type };
        switch (type) {
            case 'text':    block.content = ''; break;
            case 'image':   block.src = ''; break;
            case 'gallery': block.images = []; break;
        }
        blocks.push(block);
        renderBlocks();
        // Scroll to bottom
        canvas.scrollTop = canvas.scrollHeight;
        showToast(`Added ${type} block`);
    });
});

// ─── Save ─────────────────────────────────────────────────────────────────────
async function saveBlocks() {
    if (isSaving || !currentPageId) {
        if (!currentPageId) showToast('No page selected', 'error');
        return;
    }
    isSaving = true;
    saveBtn.disabled = true;
    saveStatus.textContent = '⏳ Saving...';
    try {
        const res = await fetch('?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                page_id: currentPageId,
                blocks: blocks,
                title: pageTitleInput.value
            })
        });
        const data = await res.json();
        if (data.ok) {
            saveStatus.textContent = '✅ Saved';
            showToast('Changes saved');
            if (pages[currentPageId]) {
                pages[currentPageId].blockCount = blocks.length;
                pages[currentPageId].title = pageTitleInput.value;
            }
            renderPageList();
            await refreshStats();
        } else {
            saveStatus.textContent = '❌ Error';
            showToast('Save failed: ' + (data.error || 'unknown'), 'error');
        }
    } catch {
        saveStatus.textContent = '❌ Network error';
        showToast('Network error', 'error');
    }
    isSaving = false;
    saveBtn.disabled = false;
    setTimeout(() => {
        if (saveStatus.textContent !== '⏳ Saving...') saveStatus.textContent = '';
    }, 3000);
}

saveBtn.addEventListener('click', saveBlocks);

document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        saveBlocks();
    }
});

// ─── Preview ─────────────────────────────────────────────────────────────────
previewBtn.addEventListener('click', () => {
    if (!currentPageId) { showToast('No page selected', 'error'); return; }
    window.open('?action=preview&page_id=' + encodeURIComponent(currentPageId), '_blank');
});

// ─── Export all ──────────────────────────────────────────────────────────────
exportBtn.addEventListener('click', async () => {
    try {
        const res = await fetch('?action=export-all', { method: 'POST' });
        const data = await res.json();
        if (data.ok) {
            showToast('All pages exported to .html');
        } else {
            showToast('Export failed', 'error');
        }
    } catch {
        showToast('Network error', 'error');
    }
});

// ─── Change Password ─────────────────────────────────────────────────────────
passwordBtn.addEventListener('click', () => {
    pwdModal.classList.add('open');
    pwdCurrent.value = '';
    pwdNew.value = '';
    pwdConfirm.value = '';
    pwdError.style.display = 'none';
    pwdCurrent.focus();
});

pwdCancel.addEventListener('click', () => pwdModal.classList.remove('open'));

pwdSave.addEventListener('click', async () => {
    const current = pwdCurrent.value;
    const pwnew   = pwdNew.value;
    const confirm = pwdConfirm.value;
    pwdError.style.display = 'none';
    if (!current || !pwnew) {
        pwdError.textContent = 'Fill all fields';
        pwdError.style.display = 'block';
        return;
    }
    if (pwnew.length < 4) {
        pwdError.textContent = 'New password must be at least 4 characters';
        pwdError.style.display = 'block';
        return;
    }
    if (pwnew !== confirm) {
        pwdError.textContent = 'Passwords do not match';
        pwdError.style.display = 'block';
        return;
    }
    try {
        const res = await fetch('?action=change-password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ current, new: pwnew })
        });
        const data = await res.json();
        if (data.ok) {
            showToast('Password changed successfully');
            pwdModal.classList.remove('open');
        } else {
            pwdError.textContent = data.error || 'Failed to change password';
            pwdError.style.display = 'block';
        }
    } catch {
        pwdError.textContent = 'Network error';
        pwdError.style.display = 'block';
    }
});

// Allow Enter key in password modal
[pwdCurrent, pwdNew, pwdConfirm].forEach(el => {
    el.addEventListener('keydown', e => {
        if (e.key === 'Enter') pwdSave.click();
    });
});

// ─── Boot: check if session is already active ─────────────────────────────────
(async function boot() {
    try {
        const res = await fetch('?action=list-pages');
        if (res.status === 401) return; // stay on login
        const data = await res.json();
        if (data.pages !== undefined) {
            loginScreen.style.display = 'none';
            app.style.display = 'flex';
            await initApp();
        }
    } catch {}
})();
</script>
</body>
</html>
