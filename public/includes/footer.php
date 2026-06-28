    <?php
    require_once __DIR__ . '/../../private/content.php';
    $footerPartner1 = content('partner1_full_name', content('partner1_name', ''));
    $footerPartner2 = content('partner2_full_name', content('partner2_name', ''));
    $footerCouple = trim($footerPartner1 . ' & ' . $footerPartner2, ' &');
    $authorName = content('site_author_name', $footerPartner1);
    $authorUrl = content('site_author_url', '');
    ?>
    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($footerCouple !== '' ? $footerCouple : content('couple_names', '')); ?></p>
        <?php if ($authorName !== ''): ?>
        <p>Website created by <?php
            echo $authorUrl !== ''
                ? '<a href="' . htmlspecialchars($authorUrl) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($authorName) . '</a>'
                : htmlspecialchars($authorName);
        ?></p>
        <?php endif; ?>
        <p><a href="/admin">Admin Area</a> | <a href="/admin?sample=1">Admin Preview</a></p>
    </footer>
    
    <script src="/js/main.js?v=<?php
        $jsPath = __DIR__ . '/../js/main.js?v=3';
        echo file_exists($jsPath) ? filemtime($jsPath) : time();
    ?>"></script>
</body>
</html>
