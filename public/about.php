<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/content.php';
$page_title = "About - " . content('couple_names', 'Our Wedding');
include __DIR__ . '/includes/header.php';
?>

<main class="page-container">
    <h1 class="page-title">About</h1>

    <?php foreach (contentBlocks('about') as $block): ?>
    <section class="about-section">
        <?php if ($block['heading'] !== ''): ?><h2><?php echo $block['heading']; ?></h2><?php endif; ?>
        <?php echo renderContentBody($block['body']); ?>
    </section>
    <?php endforeach; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
