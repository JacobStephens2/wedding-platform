<?php
/**
 * Couple-content accessors.
 *
 * Every couple-specific value (names, date, venues, page prose) is read through
 * these helpers so the public/ pages stay couple-agnostic. Resolution order for
 * each value is:
 *   1. the matching row in the `site_content` / `content_blocks` MySQL tables
 *      (what the admin Content editor writes), then
 *   2. the fallback in private/content_defaults.php.
 *
 * If the database is unavailable or the tables have not been created yet, the
 * defaults are used, so pages that never touch the DB (home, about, travel)
 * keep rendering. See private/content_defaults.php for the full field list.
 */

require_once __DIR__ . '/db.php';

/** The raw defaults array (scalars + blocks). */
function contentDefaults(): array
{
    static $defaults = null;
    if ($defaults === null) {
        $defaults = require __DIR__ . '/content_defaults.php';
    }
    return $defaults;
}

/**
 * All scalar content values, defaults overlaid by any DB rows.
 *
 * @return array<string,string>
 */
function siteContentAll(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cache = contentDefaults()['scalars'] ?? [];

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_content");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['setting_value'] !== null && $row['setting_value'] !== '') {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        // Table missing or DB down: fall back to the defaults already in $cache.
    }

    return $cache;
}

/** A single scalar content value (e.g. content('couple_names')). */
function content(string $key, ?string $default = null): ?string
{
    $all = siteContentAll();
    return array_key_exists($key, $all) ? $all[$key] : $default;
}

/**
 * The ordered, published prose blocks for a page ('story', 'about', 'travel',
 * 'blessing'). Each block is ['section_key' => ..., 'heading' => ..., 'body' => ...].
 *
 * Falls back to the defaults when the table is missing or has no rows for the
 * page yet (so a freshly created but unseeded install still shows content).
 *
 * @return array<int,array{section_key:string,heading:string,body:string}>
 */
function contentBlocks(string $page): array
{
    static $cache = [];
    if (array_key_exists($page, $cache)) {
        return $cache[$page];
    }

    $blocks = null;

    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            "SELECT section_key, heading, body
             FROM content_blocks
             WHERE page = ? AND published = 1
             ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([$page]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $blocks = array_map(static function (array $r): array {
                return [
                    'section_key' => $r['section_key'],
                    'heading'     => (string) ($r['heading'] ?? ''),
                    'body'        => (string) ($r['body'] ?? ''),
                ];
            }, $rows);
        }
    } catch (Throwable $e) {
        $blocks = null;
    }

    if ($blocks === null) {
        $defBlocks = contentDefaults()['blocks'][$page] ?? [];
        usort($defBlocks, static fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
        $blocks = array_map(static function (array $b): array {
            return [
                'section_key' => $b['section_key'],
                'heading'     => (string) ($b['heading'] ?? ''),
                'body'        => (string) ($b['body'] ?? ''),
            ];
        }, $defBlocks);
    }

    $cache[$page] = $blocks;
    return $blocks;
}

/**
 * Expand a block body's photo placeholders against a section => photos map.
 *   {{carousel:KEY}}    -> swipeable carousel
 *   {{blockimages:KEY}} -> static image grid
 * Bodies with no placeholder are returned unchanged.
 *
 * @param array<string,array<int,array<string,mixed>>> $photosBySection
 */
function renderContentBody(string $body, array $photosBySection = []): string
{
    return preg_replace_callback(
        '/\{\{(carousel|blockimages):([a-z0-9_]+)\}\}/i',
        static function (array $m) use ($photosBySection): string {
            $photos = $photosBySection[$m[2]] ?? [];
            return strtolower($m[1]) === 'carousel'
                ? renderCarousel($photos)
                : renderBlockImages($photos);
        },
        $body
    );
}

/** Swipeable photo carousel for a story section. */
function renderCarousel(array $photos): string
{
    if (empty($photos)) {
        return '';
    }
    $html = '<div class="photo-carousel"><div class="carousel-container">';
    foreach ($photos as $i => $p) {
        $active = $i === 0 ? ' active' : '';
        $src = '/assets.php?type=photo&path=' . urlencode($p['path']);
        $alt = htmlspecialchars($p['alt']);
        $dateFormatted = !empty($p['photo_date']) ? date('F j, Y', strtotime($p['photo_date'])) : '';
        $pos = !empty($p['position']) ? htmlspecialchars($p['position']) : 'center';
        $html .= '<img src="' . $src . '" alt="' . $alt . '"'
            . ' data-caption-desc="' . $alt . '"'
            . ' data-caption-date="' . htmlspecialchars($dateFormatted) . '"'
            . ' data-object-position="' . $pos . '"'
            . ' class="carousel-image clickable-image' . $active . '">';
    }
    $html .= '<button class="carousel-btn carousel-prev" aria-label="Previous image">&lsaquo;</button>';
    $html .= '<button class="carousel-btn carousel-next" aria-label="Next image">&rsaquo;</button>';
    $html .= '<div class="carousel-indicators">';
    foreach ($photos as $i => $p) {
        $active = $i === 0 ? ' active' : '';
        $html .= '<span class="indicator' . $active . '" data-slide="' . $i . '"></span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    $firstDesc = htmlspecialchars($photos[0]['alt'] ?? '');
    $firstDate = !empty($photos[0]['photo_date']) ? date('F j, Y', strtotime($photos[0]['photo_date'])) : '';
    $html .= '<div class="carousel-caption">';
    if ($firstDesc) {
        $html .= '<span class="carousel-caption-desc">' . $firstDesc . '</span>';
    }
    if ($firstDate) {
        $html .= '<span class="carousel-caption-date">' . htmlspecialchars($firstDate) . '</span>';
    }
    $html .= '</div>';
    $html .= '</div>';
    return $html;
}

/** Static image grid for a story section. */
function renderBlockImages(array $photos): string
{
    if (empty($photos)) {
        return '';
    }
    $html = '<div class="story-media-block">';
    foreach ($photos as $p) {
        $src = '/assets.php?type=photo&path=' . urlencode($p['path']);
        $alt = htmlspecialchars($p['alt']);
        $html .= '<img src="' . $src . '" alt="' . $alt . '" class="clickable-image">';
    }
    $html .= '</div>';
    return $html;
}
