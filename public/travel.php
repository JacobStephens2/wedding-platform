<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/content.php';
$page_title = "Travel - " . content('couple_names', 'Our Wedding');
include __DIR__ . '/includes/header.php';
?>

<main class="page-container">
    <h1 class="page-title">Travel & Accommodations</h1>

    <?php foreach (contentBlocks('travel') as $block): ?>
    <section class="travel-section">
        <?php if ($block['heading'] !== ''): ?><h2><?php echo $block['heading']; ?></h2><?php endif; ?>
        <?php echo renderContentBody($block['body']); ?>
    </section>
    <?php endforeach; ?>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
