<?php
declare(strict_types=1);

/*
 * Files pop-up for quote requests and orders: every image, .glb 3D model and CAD file,
 * each with an open or download link. Used by quotes.php and orders.php.
 */

/** Add one file to a list, keyed by its URL so the same file is never listed twice. */
function admin_add_file(array &$list, ?string $path, string $prefix): void
{
    $path = trim((string)$path);
    if ($path === '') {
        return;
    }
    $clean = ltrim($path, '/');
    $url = $prefix . $clean;
    $list[$url] = ['url' => $url, 'label' => basename($clean)];
}

/** Every file attached to each quote request, as [quote_id => [file, ...]]. URLs are relative to admin/. */
function admin_quote_files(): array
{
    $lists = [];
    foreach (db_fetch_all('SELECT quote_id, quote_img_url, quote_cad_url FROM quote_img ORDER BY quote_img_id') as $row) {
        $qid = (int)$row['quote_id'];
        $lists[$qid] ??= [];
        admin_add_file($lists[$qid], $row['quote_img_url'] ?? null, '../');
        admin_add_file($lists[$qid], $row['quote_cad_url'] ?? null, '../');
    }
    foreach (db_fetch_all("SELECT quote_id, file_url FROM quote WHERE file_url IS NOT NULL AND file_url <> ''") as $row) {
        $qid = (int)$row['quote_id'];
        $lists[$qid] ??= [];
        admin_add_file($lists[$qid], $row['file_url'], '../');
    }
    return array_map(static fn(array $files) => array_values($files), $lists);
}

/** Button that opens the files pop-up, or a dash when there are no files. */
function admin_files_button(string $key, array $files): string
{
    if (!$files) {
        return admin_dash();
    }
    return '<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#files-' . e($key) . '">'
        . '<i class="mdi mdi-folder-open-outline"></i> Files (' . count($files) . ')</button>';
}

/** The pop-up itself. Place it after the table, outside any table cell. Returns '' when there are no files. */
function admin_files_modal(string $key, string $title, array $files): string
{
    static $viewerLoaded = false;
    if (!$files) {
        return '';
    }
    $id = 'files-' . $key;
    $images = [];
    $models = [];
    $others = [];
    foreach ($files as $f) {
        $ext = strtolower(pathinfo($f['url'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $images[] = $f;
        } elseif ($ext === 'glb') {
            $models[] = $f;
        } else {
            $others[] = $f;
        }
    }

    $h = '<div class="modal fade" id="' . e($id) . '" tabindex="-1" aria-labelledby="' . e($id) . '-title" aria-hidden="true">'
        . '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">'
        . '<div class="modal-header"><h5 class="modal-title" id="' . e($id) . '-title">' . e($title) . '</h5>'
        . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>'
        . '<div class="modal-body">';

    if ($images) {
        $h .= '<h6 class="section-label">Images</h6><div class="file-grid">';
        foreach ($images as $f) {
            $h .= '<figure class="file-card"><a href="' . e($f['url']) . '" target="_blank" rel="noopener">'
                . '<img src="' . e($f['url']) . '" alt="' . e($f['label']) . '"></a>'
                . '<figcaption><span class="small text-truncate">' . e($f['label']) . '</span>'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . e($f['url']) . '" download>Download</a></figcaption></figure>';
        }
        $h .= '</div>';
    }

    if ($models) {
        $h .= '<h6 class="section-label mt-3">3D models (.glb)</h6>';
        foreach ($models as $f) {
            $h .= '<div class="file-model mb-3"><model-viewer src="' . e($f['url']) . '" camera-controls auto-rotate shadow-intensity="0.6" alt="' . e($f['label']) . '"></model-viewer>'
                . '<div class="d-flex justify-content-between align-items-center mt-2"><span class="small">' . e($f['label']) . '</span>'
                . '<a class="btn btn-sm btn-edge" href="' . e($f['url']) . '" download>Download .glb</a></div></div>';
        }
        if (!$viewerLoaded) {
            // Loaded once per page, and only when a model is shown.
            $h .= '<script type="module" src="https://cdn.jsdelivr.net/npm/@google/model-viewer@3.5.0/dist/model-viewer.min.js"></script>';
            $viewerLoaded = true;
        }
    }

    if ($others) {
        $h .= '<h6 class="section-label mt-3">CAD and other files</h6><ul class="file-list">';
        foreach ($others as $f) {
            $h .= '<li class="d-flex justify-content-between align-items-center"><span class="text-truncate">' . e($f['label']) . '</span>'
                . '<a class="btn btn-sm btn-outline-secondary" href="' . e($f['url']) . '" download>Download</a></li>';
        }
        $h .= '</ul>';
    }

    $h .= '</div><div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>'
        . '</div></div></div>';
    return $h;
}
