<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
require_once __DIR__ . '/../private/content.php';
$page_title = "Our Story - " . content('couple_names', 'Our Wedding');
include __DIR__ . '/includes/header.php';

// Photos for each story section, keyed by story_section, in story order.
// Block bodies reference these via {{carousel:KEY}} / {{blockimages:KEY}}.
$storyPhotos = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("
        SELECT path, alt, photo_date, position, story_section, story_position
        FROM gallery_photos
        WHERE story_section IS NOT NULL
        ORDER BY story_section, story_position ASC
    ");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $storyPhotos[$row['story_section']][] = $row;
    }
} catch (Exception $e) {
    error_log("Story photos error: " . $e->getMessage());
}
?>

<main class="page-container">
    <h1 class="page-title">Our Story</h1>

    <?php foreach (contentBlocks('story') as $block): ?>
    <section class="story-section">
        <?php if ($block['heading'] !== ''): ?><h2><?php echo $block['heading']; ?></h2><?php endif; ?>
        <?php echo renderContentBody($block['body'], $storyPhotos); ?>
    </section>
    <?php endforeach; ?>
</main>

<style>
    .story-section .featured-galleries {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.25rem;
        margin: 1rem 0 1.5rem;
        padding: 0;
    }
    .story-section .featured-card {
        display: flex;
        flex-direction: column;
        background: var(--color-surface);
        border: 1px solid var(--color-border);
        border-radius: 10px;
        box-shadow: 0 2px 10px var(--color-shadow);
        text-decoration: none;
        color: var(--color-dark);
        overflow: hidden;
        transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
    }
    .story-section .featured-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px var(--color-shadow-hover);
        border-color: var(--color-green);
    }
    .story-section .featured-photo {
        display: block;
        width: 100%;
        aspect-ratio: 4 / 3;
        object-fit: cover;
        background: var(--color-bg);
    }
    .story-section .featured-body {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1.25rem 1.4rem 1.5rem;
    }
    .story-section .featured-card h3 {
        font-family: 'Cinzel', serif;
        font-size: 1.15rem;
        margin: 0;
        color: var(--color-green);
        letter-spacing: 0.04em;
    }
    .story-section .featured-card p {
        margin: 0;
        font-family: 'Crimson Text', serif;
        font-size: 1rem;
        line-height: 1.45;
        color: var(--color-text-secondary, #555);
    }
    .story-section .featured-cta {
        margin-top: 0.4rem;
        font-family: 'Cinzel', serif;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
        color: var(--color-green);
    }
    .story-section .featured-card:hover .featured-cta { color: var(--color-gold); }

    .story-video-embed {
        position: relative;
        width: 100%;
        aspect-ratio: 16 / 9;
        background: #000;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 4px 18px var(--color-shadow);
    }
    .story-video-embed iframe {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        border: 0;
    }
</style>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-image" src="" alt="">
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
