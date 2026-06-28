<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/content.php';
$page_title = "Blessing - " . content('couple_names', 'Our Wedding');
include __DIR__ . '/includes/header.php';
?>

<main class="page-container">
    <h1 class="page-title">Blessing</h1>

    <?php foreach (contentBlocks('blessing') as $block): ?>
    <section class="story-section">
        <?php if ($block['heading'] !== ''): ?><h2><?php echo $block['heading']; ?></h2><?php endif; ?>
        <?php echo renderContentBody($block['body']); ?>
    </section>
    <?php endforeach; ?>
</main>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-image" src="" alt="">
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
