<?php
declare(strict_types=1);

$requireOnce = require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

$viewer = current_user();
$isAdmin = is_array($viewer) && (($viewer['role_name'] ?? '') === 'admin');

$slug = trim((string) ($_GET['slug'] ?? ''));
$postIdParam = trim((string) ($_GET['id'] ?? ''));
if ($slug === '' && $postIdParam === '') {
    render_not_found();
}

$post = null;
if ($slug !== '') {
    $post = blog_get_post_by_slug(null, $slug, $isAdmin);
}

if (!$post && $postIdParam !== '') {
    try {
        $post = blog_get_post_by_id(null, $postIdParam);
    } catch (Throwable $exception) {
        $post = null;
    }
}

if ($post && (($post['status'] ?? 'published') !== 'published') && !$isAdmin) {
    $post = null;
}

if (!$post) {
    render_not_found();
}

$slug = $post['slug'] ?? $slug;

$authorName = trim((string) ($post['author_name'] ?? ''));

function blog_filter_tags(array $tags): array
{
    $filtered = [];

    foreach ($tags as $tag) {
        $label = is_array($tag) ? trim((string) ($tag['name'] ?? '')) : trim((string) $tag);
        if ($label === '') {
            continue;
        }

        if (is_array($tag)) {
            $tag['name'] = $label;
            $filtered[] = $tag;
        } else {
            $filtered[] = $label;
        }
    }

    return $filtered;
}

$coverImage = $post['cover_image_url'] ?? ($post['cover_image'] ?? '');
$coverAlt = $post['cover_image_alt'] ?? '';
$coverAltText = $coverAlt !== '' ? $coverAlt : ($post['title'] ?? 'Blog cover');

$publishedIso = '';
$updatedIso = '';
try {
    if (!empty($post['published_at'])) {
        $published = new DateTime($post['published_at'], new DateTimeZone('UTC'));
        $publishedIso = $published->format(DateTimeInterface::ATOM);
    }
    if (!empty($post['updated_at'])) {
        $updated = new DateTime($post['updated_at'], new DateTimeZone('UTC'));
        $updatedIso = $updated->format(DateTimeInterface::ATOM);
    }
} catch (Throwable $exception) {
    $publishedIso = $post['published_at'] ?? '';
    $updatedIso = $post['updated_at'] ?? '';
}

$metaDescription = $post['excerpt'] ?? '';
if ($metaDescription === '') {
    $metaDescription = mb_substr(blog_extract_plain_text((string) ($post['body_html'] ?? '')), 0, 160);
}
$metaDescription = trim($metaDescription);

$canonicalQuery = $slug !== '' ? 'slug=' . rawurlencode($slug) : '';
if ($canonicalQuery === '') {
    $canonicalId = $post['id'] ?? $postIdParam;
    if ($canonicalId !== '') {
        $canonicalQuery = 'id=' . rawurlencode((string) $canonicalId);
    }
}
$canonicalUrl = absolute_url('/blog/post.php' . ($canonicalQuery !== '' ? ('?' . $canonicalQuery) : ''));
$ogImage = $coverImage !== '' ? $coverImage : absolute_url('/images/hero/hero.png');
if ($ogImage !== '' && !preg_match('#^(https?:|data:)#i', $ogImage)) {
    $ogImage = absolute_url('/' . ltrim($ogImage, '/'));
}

send_cache_headers($post['updated_at'] ?? '', 'blog-post-' . $post['id']);

$visibleTagsRaw = blog_filter_tags($post['tags'] ?? []);
$tags = array_map(static fn ($tag) => is_array($tag) ? $tag['name'] : $tag, $visibleTagsRaw);
$adjacent = blog_get_adjacent_posts(null, (string) $post['id']);
$related = blog_related_posts(null, (string) $post['id'], 3);

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    $imageForJson = $post['cover_image_url'] ?? ($post['cover_image'] ?? $coverImage);
    if ($imageForJson !== null && $imageForJson !== '' && !preg_match('#^(https?:|data:)#i', (string) $imageForJson)) {
        $imageForJson = absolute_url('/' . ltrim((string) $imageForJson, '/'));
    }
    if ($imageForJson === '' || $imageForJson === null) {
        $imageForJson = null;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'post' => [
            'id' => (int) ($post['id'] ?? 0),
            'title' => $post['title'] ?? '',
            'slug' => $post['slug'] ?? ($slug !== '' ? $slug : ($post['id'] ?? $postIdParam)),
            'excerpt' => $post['excerpt'] ?? '',
            'body_html' => $post['body_html'] ?? '',
            'cover_image' => $imageForJson,
            'cover_image_alt' => $coverAltText,
            'author_name' => $authorName,
            'published_at' => $post['published_at'] ?? '',
            'updated_at' => $post['updated_at'] ?? '',
            'published_ist' => format_ist($post['published_at'] ?? ''),
            'updated_ist' => format_ist($post['updated_at'] ?? ''),
            'tags' => $visibleTagsRaw,
            'canonical_url' => $canonicalUrl,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function render_not_found(): void
{
    http_response_code(404);
    include __DIR__ . '/../404.shtml';
    exit;
}

function send_cache_headers(?string $timestamp, string $seed): void
{
    if (!$timestamp) {
        return;
    }
    try {
        $lastModified = new DateTime($timestamp, new DateTimeZone('UTC'));
    } catch (Throwable $exception) {
        return;
    }
    $formatted = $lastModified->format('D, d M Y H:i:s') . ' GMT';
    $etag = '"' . sha1($seed . '|' . $lastModified->getTimestamp()) . '"';
    header('Last-Modified: ' . $formatted);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=300');
    if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && trim((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']) === $formatted)) {
        http_response_code(304);
        exit;
    }
}

function absolute_url(string $path = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $root = rtrim($scheme . '://' . $host, '/');
    if ($path === '') {
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        $scriptDir = $scriptDir === '.' ? '' : $scriptDir;
        return $scriptDir ? $root . $scriptDir : $root;
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if ($path[0] === '/') {
        return $root . $path;
    }
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
    $scriptDir = $scriptDir === '.' ? '' : $scriptDir;
    $base = $scriptDir ? $root . $scriptDir : $root;
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function format_ist(?string $timestamp): string
{
    if (!$timestamp) {
        return '';
    }
    try {
        $date = new DateTime($timestamp, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $date->format('j F Y');
    } catch (Throwable $exception) {
        return '';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars(($post['title'] ?? 'Blog post') . ' | Dakshayani Blog', ENT_QUOTES | ENT_HTML5) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <link rel="icon" href="../images/favicon.ico" />
    <link rel="stylesheet" href="../style.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:type" content="article" />
    <meta property="og:title" content="<?= htmlspecialchars($post['title'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES | ENT_HTML5) ?>" />
    <?php if ($publishedIso): ?>
        <meta property="article:published_time" content="<?= htmlspecialchars($publishedIso, ENT_QUOTES | ENT_HTML5) ?>" />
    <?php endif; ?>
    <?php if ($updatedIso): ?>
        <meta property="article:modified_time" content="<?= htmlspecialchars($updatedIso, ENT_QUOTES | ENT_HTML5) ?>" />
    <?php endif; ?>
    <?php foreach ($tags as $tagName): ?>
        <meta property="article:tag" content="<?= htmlspecialchars($tagName, ENT_QUOTES | ENT_HTML5) ?>" />
    <?php endforeach; ?>
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($post['title'] ?? '', ENT_QUOTES | ENT_HTML5) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES | ENT_HTML5) ?>" />
    <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'headline' => $post['title'] ?? '',
        'description' => $metaDescription,
        'image' => $ogImage,
        'datePublished' => $publishedIso ?: null,
        'dateModified' => $updatedIso ?: null,
        'author' => $authorName !== '' ? ['@type' => 'Person', 'name' => $authorName] : ['@type' => 'Organization', 'name' => 'Dakshayani Enterprises'],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonicalUrl],
        'publisher' => ['@type' => 'Organization', 'name' => 'Dakshayani Enterprises', 'logo' => ['@type' => 'ImageObject', 'url' => absolute_url('/images/logo/New dakshayani logo centered small.png')]],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
    </script>
    <style>
        .article-shell { background: #ffffff; border-radius: 1.5rem; box-shadow: 0 30px 60px -40px rgba(15,23,42,0.6); padding: clamp(1.5rem, 4vw, 3rem); display: grid; gap: 1.5rem; width: 100%; }
        .article-body { position: relative; }
        .article-body::after { content: ""; display: block; clear: both; }
        .article-hero { width: min(420px, 45%); max-width: 100%; height: auto; border-radius: 1.25rem; object-fit: contain; float: left; margin: 0 1.5rem 1.25rem 0; }
        .article-meta { color: rgba(15,23,42,0.65); font-size: 0.95rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
        .article-meta span { display: inline-flex; align-items: center; gap: 0.4rem; }
        .article-content { display: grid; gap: 1.4rem; line-height: 1.7; color: rgba(15,23,42,0.85); }
        .article-content h2, .article-content h3 { color: var(--base-900); margin-top: 1.5rem; margin-bottom: 0.5rem; }
        .article-content ul, .article-content ol { padding-left: 1.2rem; }
        .article-content blockquote { border-left: 4px solid rgba(37, 99, 235, 0.4); padding-left: 1rem; color: rgba(15,23,42,0.75); }
        .article-tags { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .article-tags span { background: rgba(37, 99, 235, 0.12); color: #1d4ed8; border-radius: 999px; padding: 0.35rem 0.8rem; font-size: 0.85rem; font-weight: 600; }
        .article-nav { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-top: 2.5rem; }
        .article-nav a { display: inline-flex; flex-direction: column; gap: 0.3rem; text-decoration: none; padding: 1rem 1.25rem; border-radius: 1rem; border: 1px solid rgba(15,23,42,0.12); width: min(280px, 100%); }
        .article-related { margin-top: 3rem; }
        .article-related-list { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); }
        .article-related-card { text-decoration: none; padding: 1.25rem; border-radius: 1.25rem; border: 1px solid rgba(15,23,42,0.12); display: grid; gap: 0.75rem; transition: border-color 0.2s ease, transform 0.2s ease; }
        .article-related-card:hover, .article-related-card:focus { transform: translateY(-4px); border-color: rgba(37,99,235,0.45); }
        .article-related-card h3 { margin: 0; font-size: 1.1rem; color: var(--base-900); }
        .article-related-card p { margin: 0; color: var(--base-600); font-size: 0.95rem; line-height: 1.5; }
        .article-section { padding: clamp(3rem, 5vw, 4.5rem) clamp(1.25rem, 5vw, 3rem); }
        .article-layout { width: 100%; display: grid; gap: clamp(1.75rem, 3vw, 3rem); }
        .article-section .article-nav, .article-section .article-related { width: 100%; }
        @media (max-width: 720px) {
            .article-shell { padding: 1.5rem; }
            .article-hero { float: none; width: 100%; margin: 0 0 1rem; }
        }
    </style>
</head>
<body>
<?php if ($isAdmin): ?>
    <div class="container" style="padding:1rem 1.5rem 0; display:flex; justify-content:flex-start;">
        <a class="btn btn-secondary" href="../admin-blog-manager.php" style="gap:0.5rem; display:inline-flex; align-items:center;">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
            <span>Back to Blog Manager</span>
        </a>
    </div>
<?php endif; ?>
<header class="site-header"></header>
<main>
    <section class="page-hero" style="background:linear-gradient(135deg,rgba(15,23,42,0.94),rgba(59,130,246,0.85));">
        <div class="container hero-inner">
            <div class="hero-copy">
                <span class="hero-eyebrow"><i class="fa-solid fa-newspaper"></i> Dakshayani insights</span>
                <h1><?= htmlspecialchars($post['title'] ?? 'Blog post', ENT_QUOTES | ENT_HTML5) ?></h1>
                <p class="lead article-meta">
                    <?php if ($post['published_at']): ?>
                        <span><i class="fa-regular fa-calendar"></i> Published <?= htmlspecialchars(format_ist($post['published_at']), ENT_QUOTES | ENT_HTML5) ?> IST</span>
                    <?php endif; ?>
                    <?php if ($updatedIso && $updatedIso !== $publishedIso): ?>
                        <span><i class="fa-solid fa-rotate"></i> Updated <?= htmlspecialchars(format_ist($post['updated_at']), ENT_QUOTES | ENT_HTML5) ?></span>
                    <?php endif; ?>
                    <?php if ($authorName !== ''): ?>
                        <span><i class="fa-solid fa-user"></i> <?= htmlspecialchars($authorName, ENT_QUOTES | ENT_HTML5) ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </section>

    <section class="section article-section">
        <div class="article-layout">
            <article class="article-shell">
                <?php
                $displayImage = $coverImage !== '' ? $coverImage : '';
                if ($displayImage !== '' && !preg_match('#^(?:https?:|data:)#i', $displayImage)) {
                    $displayImage = '../' . ltrim($displayImage, '/');
                }
                if ($displayImage === '') {
                    $displayImage = '../images/hero/hero.png';
                }
                ?>
                <div class="article-body">
                    <img src="<?= htmlspecialchars($displayImage, ENT_QUOTES | ENT_HTML5) ?>" alt="<?= htmlspecialchars($coverAltText, ENT_QUOTES | ENT_HTML5) ?>" class="article-hero" loading="lazy" />
                    <div class="article-content" data-blog-post-content><?= $post['body_html'] ?? '' ?></div>
                </div>
                <?php if ($tags): ?>
                    <div class="article-tags" aria-label="Tags">
                        <?php foreach ($tags as $tagName): ?>
                            <span><?= htmlspecialchars($tagName, ENT_QUOTES | ENT_HTML5) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>

            <div class="article-nav" aria-label="Post navigation">
                <?php if (!empty($adjacent['previous'])): ?>
                    <a href="<?= htmlspecialchars('post.php?slug=' . urlencode($adjacent['previous']['slug']), ENT_QUOTES | ENT_HTML5) ?>" rel="prev">
                        <strong>Newer post</strong>
                        <span><?= htmlspecialchars($adjacent['previous']['title'], ENT_QUOTES | ENT_HTML5) ?></span>
                    </a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <?php if (!empty($adjacent['next'])): ?>
                    <a href="<?= htmlspecialchars('post.php?slug=' . urlencode($adjacent['next']['slug']), ENT_QUOTES | ENT_HTML5) ?>" rel="next" style="text-align:right;">
                        <strong>Older post</strong>
                        <span><?= htmlspecialchars($adjacent['next']['title'], ENT_QUOTES | ENT_HTML5) ?></span>
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($related): ?>
                <section class="article-related" aria-label="Related posts">
                    <h2>Related reading</h2>
                    <div class="article-related-list">
                        <?php foreach ($related as $item): ?>
                            <a class="article-related-card" href="<?= htmlspecialchars('post.php?slug=' . urlencode($item['slug']), ENT_QUOTES | ENT_HTML5) ?>">
                                <h3><?= htmlspecialchars($item['title'], ENT_QUOTES | ENT_HTML5) ?></h3>
                                <?php if (!empty($item['excerpt'])): ?>
                                    <p><?= htmlspecialchars($item['excerpt'], ENT_QUOTES | ENT_HTML5) ?></p>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    </section>
</main>
<footer class="site-footer"></footer>
<script src="../script.js" defer></script>
</body>
</html>
