<?php
require_once __DIR__ . '/../private/config.php';
require_once __DIR__ . '/../private/db.php';
$page_title = "Gallery - Jacob & Melissa";
include __DIR__ . '/includes/header.php';

$photos = [];
try {
    $pdo = getDbConnection();
    $stmt = $pdo->query("SELECT path, alt, photo_date, position FROM gallery_photos ORDER BY photo_date ASC, id ASC");
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Gallery error: " . $e->getMessage());
}
?>

<main class="page-container">
    <h1 class="page-title">Gallery</h1>

    <nav class="gallery-quicklinks" aria-label="Jump to">
        <span class="gallery-quicklinks-label">Jump to:</span>
        <a href="https://drive.google.com/drive/folders/1DrRDH8wAYEs1x7WPUVAFXLeJdOEmcnRN?usp=sharing"
           target="_blank" rel="noopener">Full wedding gallery</a>
        <span class="gallery-quicklinks-sep" aria-hidden="true">&middot;</span>
        <a href="https://baronephoto.pic-time.com/client/bwportraitsjacobmelissa/gallery?ptat=AAAAAAIBAABa2GXe-gp4DBjRNYJ3qh8D9Yh0ygjq10fR_tKNeQ,,&amp;inviteptoken2=AAAAABUBAAB6MSBxUrIu5TH4WE_efiRjIA,,"
           target="_blank" rel="noopener">B&amp;W portraits</a>
        <span class="gallery-quicklinks-sep" aria-hidden="true">&middot;</span>
        <a href="#wedding-video">Wedding video</a>
    </nav>

    <h2 class="gallery-section-heading">Highlights</h2>
    <div class="gallery-grid">
        <?php foreach ($photos as $i => $photo): ?>
        <div class="gallery-item">
            <img
                src="/assets.php?type=photo&path=<?php echo urlencode($photo['path']); ?>"
                alt="<?php echo htmlspecialchars($photo['alt']); ?>"
                class="gallery-image"
                loading="lazy"
                data-gallery-index="<?php echo $i; ?>"
                data-caption-desc="<?php echo htmlspecialchars($photo['alt'] ?? ''); ?>"
                data-caption-date="<?php echo htmlspecialchars(date('F j, Y', strtotime($photo['photo_date']))); ?>"
                <?php if (!empty($photo['position'])): ?>style="object-position: <?php echo htmlspecialchars($photo['position']); ?>;"<?php endif; ?>
            >
            <div class="gallery-caption">
                <?php if (!empty($photo['alt'])): ?><span class="gallery-caption-desc"><?php echo htmlspecialchars($photo['alt']); ?></span><?php endif; ?>
                <span class="gallery-caption-date"><?php echo date('F j, Y', strtotime($photo['photo_date'])); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <section class="featured-galleries gallery-outro-heading" aria-label="More from the day">
        <a class="featured-card" href="https://drive.google.com/drive/folders/1DrRDH8wAYEs1x7WPUVAFXLeJdOEmcnRN?usp=sharing"
           target="_blank" rel="noopener">
            <img class="featured-photo" src="/images/wedding-color-highlight.jpg" alt="Jacob and Melissa on their wedding day" loading="lazy">
            <div class="featured-body">
                <h2>Wedding Photo Gallery</h2>
                <p>The full set of photos from our wedding day, hosted by Barone Photo.</p>
                <span class="featured-cta">View the full gallery &rarr;</span>
            </div>
        </a>

        <a class="featured-card" href="https://baronephoto.pic-time.com/client/bwportraitsjacobmelissa/gallery?ptat=AAAAAAIBAABa2GXe-gp4DBjRNYJ3qh8D9Yh0ygjq10fR_tKNeQ,,&amp;inviteptoken2=AAAAABUBAAB6MSBxUrIu5TH4WE_efiRjIA,,"
           target="_blank" rel="noopener">
            <img class="featured-photo" src="/images/wedding-bw-highlight.jpg" alt="Black-and-white portrait of Jacob and Melissa" loading="lazy">
            <div class="featured-body">
                <h2>Black &amp; White Portraits</h2>
                <p>A curated set of black-and-white portraits from our wedding.</p>
                <span class="featured-cta">View the B&amp;W set &rarr;</span>
            </div>
        </a>
    </section>

    <section id="wedding-video" class="wedding-video" aria-label="Wedding video">
        <h2 class="gallery-section-heading">Wedding Video</h2>
        <div class="video-embed">
            <iframe src="https://player.vimeo.com/video/1190695875?h=e325e0040b"
                    title="Jacob and Melissa Wedding Video"
                    frameborder="0"
                    allow="autoplay; fullscreen; picture-in-picture"
                    allowfullscreen
                    loading="lazy"></iframe>
        </div>
    </section>

    <section id="wedding-video-2" class="wedding-video" aria-label="Wedding video">
        <div class="video-embed">
            <iframe src="https://www.youtube-nocookie.com/embed/RUJWq4K5kW8"
                    title="Jacob and Melissa Wedding Video"
                    frameborder="0"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowfullscreen
                    loading="lazy"></iframe>
        </div>
    </section>
</main>

<!-- Lightbox Modal -->
<div id="lightbox" class="lightbox">
    <span class="lightbox-close">&times;</span>
    <img class="lightbox-content" id="lightbox-image" src="" alt="">
    <div class="lightbox-caption" id="lightbox-caption">
        <span class="lightbox-caption-desc" id="lightbox-caption-desc"></span>
        <span class="lightbox-caption-date" id="lightbox-caption-date"></span>
    </div>
</div>

<style>
    .gallery-quicklinks {
        max-width: 1100px;
        margin: 0 auto 2rem;
        padding: 0.75rem 1rem;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: center;
        gap: 0.5rem 0.75rem;
        font-family: 'Crimson Text', serif;
        font-size: 1rem;
        text-align: center;
    }
    .gallery-quicklinks-label {
        font-family: 'Cinzel', serif;
        font-size: 0.78rem;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--color-text-secondary, #555);
    }
    .gallery-quicklinks a {
        color: var(--color-green);
        text-decoration: none;
        border-bottom: 1px solid transparent;
        transition: color 0.2s ease, border-color 0.2s ease;
    }
    .gallery-quicklinks a:hover {
        color: var(--color-gold);
        border-bottom-color: var(--color-gold);
    }
    .gallery-quicklinks-sep {
        color: var(--color-text-secondary, #999);
    }

    .gallery-outro-heading {
        margin-top: 1rem;
    }

    .featured-galleries {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 1.25rem;
        max-width: 1100px;
        margin: 0 auto 3rem;
        padding: 0 1rem;
    }
    .featured-card {
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
    .featured-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px var(--color-shadow-hover);
        border-color: var(--color-green);
    }
    .featured-photo {
        display: block;
        width: 100%;
        aspect-ratio: 4 / 3;
        object-fit: cover;
        background: var(--color-bg);
    }
    .featured-body {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        padding: 1.25rem 1.4rem 1.5rem;
    }
    .featured-card h2 {
        font-family: 'Cinzel', serif;
        font-size: 1.2rem;
        margin: 0;
        color: var(--color-green);
        letter-spacing: 0.04em;
    }
    .featured-card p {
        margin: 0;
        font-family: 'Crimson Text', serif;
        font-size: 1rem;
        line-height: 1.45;
        color: var(--color-text-secondary, #555);
    }
    .featured-cta {
        margin-top: 0.4rem;
        font-family: 'Cinzel', serif;
        font-size: 0.85rem;
        letter-spacing: 0.05em;
        color: var(--color-green);
    }
    .featured-card:hover .featured-cta { color: var(--color-gold); }

    .wedding-video {
        max-width: 1100px;
        margin: 0 auto 3rem;
        padding: 0 1rem;
    }
    .video-embed {
        position: relative;
        width: 100%;
        aspect-ratio: 16 / 9;
        background: #000;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 4px 18px var(--color-shadow);
    }
    .video-embed iframe {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        border: 0;
    }

    .gallery-section-heading {
        font-family: 'Cinzel', serif;
        font-size: 1.35rem;
        text-align: center;
        color: var(--color-green);
        letter-spacing: 0.08em;
        margin: 0 0 1.25rem;
    }

    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 1.25rem;
        max-width: 1100px;
        margin: 0 auto 3rem;
        padding: 0 1rem;
    }

    .gallery-item {
        border-radius: 8px;
        overflow: hidden;
        background: var(--color-surface);
        box-shadow: 0 2px 10px var(--color-shadow);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .gallery-item:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 24px var(--color-shadow-hover);
    }

    .gallery-image {
        width: 100%;
        height: 260px;
        object-fit: cover;
        display: block;
        cursor: pointer;
    }

    .gallery-caption {
        padding: 0.65rem 0.85rem;
        font-family: 'Crimson Text', serif;
        text-align: center;
    }
    .gallery-caption-desc {
        display: block;
        font-size: 0.95rem;
        color: #444;
    }
    .gallery-caption-date {
        display: block;
        font-size: 0.82rem;
        color: #999;
    }

    @media (max-width: 600px) {
        .gallery-grid {
            grid-template-columns: 1fr;
        }
        .gallery-image {
            height: 220px;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var galleryImages = document.querySelectorAll('.gallery-image');
    var allImages = Array.from(galleryImages);
    var lightbox = document.getElementById('lightbox');
    var lightboxImg = document.getElementById('lightbox-image');
    var lightboxClose = document.querySelector('.lightbox-close');
    var lightboxCaptionDesc = document.getElementById('lightbox-caption-desc');
    var lightboxCaptionDate = document.getElementById('lightbox-caption-date');
    var currentIndex = 0;

    function applyCaption(img) {
        var desc = img.getAttribute('data-caption-desc') || '';
        var date = img.getAttribute('data-caption-date') || '';
        if (lightboxCaptionDesc) lightboxCaptionDesc.textContent = desc;
        if (lightboxCaptionDate) lightboxCaptionDate.textContent = date;
    }

    function openLightbox(index) {
        currentIndex = index;
        lightboxImg.src = allImages[currentIndex].src;
        lightboxImg.alt = allImages[currentIndex].alt;
        applyCaption(allImages[currentIndex]);
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        lightbox.classList.remove('active');
        document.body.style.overflow = '';
    }

    function navigate(direction) {
        currentIndex += direction;
        if (currentIndex < 0) currentIndex = allImages.length - 1;
        if (currentIndex >= allImages.length) currentIndex = 0;
        lightboxImg.src = allImages[currentIndex].src;
        lightboxImg.alt = allImages[currentIndex].alt;
        applyCaption(allImages[currentIndex]);
    }

    galleryImages.forEach(function(img, i) {
        img.addEventListener('click', function(e) {
            e.stopPropagation();
            openLightbox(i);
        });
    });

    if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) closeLightbox();
    });

    document.addEventListener('keydown', function(e) {
        if (!lightbox.classList.contains('active')) return;
        if (e.key === 'Escape') closeLightbox();
        else if (e.key === 'ArrowRight') navigate(1);
        else if (e.key === 'ArrowLeft') navigate(-1);
    });

    var touchStartX = 0;
    var swipeThreshold = 50;
    lightbox.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    lightbox.addEventListener('touchend', function(e) {
        var endX = e.changedTouches[0].screenX;
        var diff = endX - touchStartX;
        if (Math.abs(diff) > swipeThreshold) {
            navigate(diff < 0 ? 1 : -1);
        } else if (e.target === lightboxImg) {
            var rect = lightboxImg.getBoundingClientRect();
            var tapX = endX - rect.left;
            navigate(tapX < rect.width / 2 ? -1 : 1);
        }
    }, { passive: true });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
