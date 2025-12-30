<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$defaultPageSize = 6;
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPageInput = isset($_GET['per_page']) ? (int) $_GET['per_page'] : $defaultPageSize;
$perPage = max(3, min(12, $perPageInput > 0 ? $perPageInput : $defaultPageSize));
$q = trim((string) ($_GET['q'] ?? ''));
$tag = trim((string) ($_GET['tag'] ?? ''));

$filters = [
    'search' => $q,
    'tag' => $tag,
];

$offset = ($page - 1) * $perPage;
$result = blog_fetch_published(null, $filters, $perPage, $offset);
$tags = blog_get_tag_summary(null);
$lastUpdated = blog_get_latest_update(null);
send_cache_headers($lastUpdated, sprintf('blog-list-%d-%d-%s-%s', $page, $perPage, md5($q), md5($tag)));

$posts = $result['posts'];
$total = $result['total'];
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
if ($totalPages < 1) {
    $totalPages = 1;
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

    $lastModified->setTimezone(new DateTimeZone('UTC'));
    $formatted = $lastModified->format('D, d M Y H:i:s') . ' GMT';
    $etag = '"' . sha1($seed . '|' . $lastModified->getTimestamp()) . '"';

    header('Last-Modified: ' . $formatted);
    header('ETag: ' . $etag);
    header('Cache-Control: public, max-age=300');

    $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    $clientModified = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if (($clientEtag && trim($clientEtag) === $etag) || ($clientModified && trim($clientModified) === $formatted)) {
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

function build_query(array $params): string
{
    return http_build_query(array_filter($params, static fn ($value) => $value !== null && $value !== '' && $value !== false));
}

$canonicalParams = [];
if ($page > 1) {
    $canonicalParams['page'] = $page;
}
if ($q !== '') {
    $canonicalParams['q'] = $q;
}
if ($tag !== '') {
    $canonicalParams['tag'] = $tag;
}
if ($perPage !== $defaultPageSize) {
    $canonicalParams['per_page'] = $perPage;
}
$canonicalUrl = absolute_url('/blog/index.php' . ($canonicalParams ? ('?' . build_query($canonicalParams)) : ''));

$pageTitleParts = ['Dakshayani Blog & Insights'];
if ($tag !== '') {
    $pageTitleParts[] = ucfirst($tag);
}
if ($q !== '') {
    $pageTitleParts[] = 'Search results';
}
$pageTitle = implode(' · ', $pageTitleParts);

$metaDescription = 'Explore published solar, subsidy, and hydrogen insights from Dakshayani Enterprises. Filter by topic or search for installation learnings.';
if ($q !== '') {
    $metaDescription = 'Search results for ' . htmlspecialchars($q, ENT_QUOTES | ENT_HTML5) . ' from Dakshayani Enterprises blog.';
}
if ($tag !== '') {
    $metaDescription = 'Published posts tagged ' . htmlspecialchars($tag, ENT_QUOTES | ENT_HTML5) . ' from Dakshayani Enterprises.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_HTML5) ?></title>
    <meta name="description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <link rel="icon" href="../images/favicon.ico" />
    <link rel="stylesheet" href="../style.css" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700;800;900&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:type" content="website" />
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars(absolute_url('/images/hero/hero large.png'), ENT_QUOTES | ENT_HTML5) ?>" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($metaDescription, ENT_QUOTES | ENT_HTML5) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars(absolute_url('/images/hero/hero large.png'), ENT_QUOTES | ENT_HTML5) ?>" />
    <style>
        .blog-listing { display: grid; gap: 2rem; }
        .blog-filters { display: grid; gap: 1rem; padding: 1.5rem; border-radius: 1.5rem; background: rgba(15, 23, 42, 0.04); }
        .blog-filters form { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); align-items: end; }
        .blog-filters label { display: grid; gap: 0.35rem; font-weight: 600; color: var(--base-700); }
        .blog-filters input[type="search"], .blog-filters select { border-radius: 0.75rem; padding: 0.75rem 1rem; border: 1px solid rgba(15, 23, 42, 0.12); font: inherit; }
        .blog-filters button { justify-self: start; }
        .blog-grid { display: grid; gap: 2rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .blog-card { display: grid; gap: 1rem; text-decoration: none; color: inherit; border-radius: 1.5rem; overflow: hidden; background: #fff; box-shadow: 0 24px 60px -40px rgba(15, 23, 42, 0.35); transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .blog-card:hover, .blog-card:focus { transform: translateY(-6px); box-shadow: 0 32px 70px -40px rgba(15, 23, 42, 0.45); }
        .blog-card img { width: 100%; height: 180px; object-fit: cover; }
        .blog-card-body { display: grid; gap: 0.75rem; padding: 1.25rem 1.5rem 1.75rem; }
        .blog-card h3 { margin: 0; font-size: 1.3rem; color: var(--base-900); }
        .blog-card p { margin: 0; color: var(--base-600); line-height: 1.6; }
        .blog-card-meta { font-size: 0.95rem; color: var(--base-500); display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .blog-card-meta time { font-weight: 600; color: var(--base-700); }
        .blog-card-tags { display: flex; gap: 0.4rem; flex-wrap: wrap; }
        .blog-card-tags span { background: rgba(37, 99, 235, 0.12); color: #1d4ed8; border-radius: 999px; padding: 0.25rem 0.75rem; font-size: 0.8rem; font-weight: 600; }
        .blog-empty { text-align: center; padding: 4rem 1rem; border-radius: 1.5rem; background: rgba(15, 23, 42, 0.04); color: var(--base-600); }
        .blog-pagination { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-top: 2.5rem; }
        .blog-pagination nav { display: flex; gap: 0.75rem; }
        .blog-pagination a, .blog-pagination span { padding: 0.65rem 1.1rem; border-radius: 999px; border: 1px solid rgba(15, 23, 42, 0.12); font-weight: 600; text-decoration: none; }
        .blog-pagination .current { background: var(--base-900); color: #fff; }
        .tag-cloud { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .tag-chip { display: inline-flex; align-items: center; gap: 0.3rem; border-radius: 999px; padding: 0.3rem 0.8rem; background: rgba(37, 99, 235, 0.12); color: #1d4ed8; font-size: 0.85rem; font-weight: 600; }
        @media (max-width: 680px) {
            .blog-filters form { grid-template-columns: 1fr; }
            .blog-card img { height: 200px; }
        }
    </style>
</head>
<body>
<header class="site-header"></header>
<main>
    <section class="page-hero" style="background:linear-gradient(135deg,rgba(15,23,42,0.94),rgba(37,99,235,0.85));">
        <div class="container hero-inner">
            <div class="hero-copy">
                <span class="hero-eyebrow"><i class="fa-solid fa-newspaper"></i> Blog &amp; insights</span>
                <h1>Fresh solar intelligence from the Dakshayani editorial desk</h1>
                <p class="lead" style="color:rgba(255,255,255,0.82); max-width: 640px;">Policy explainers, EPC deep-dives, and hydrogen breakthroughs—published only after the admin desk reviews each story.</p>
                <div class="tag-cloud" aria-label="Popular blog tags">
                    <span class="tag-chip"><i class="fa-solid fa-solar-panel"></i> Rooftop</span>
                    <span class="tag-chip"><i class="fa-solid fa-scale-balanced"></i> Compliance</span>
                    <span class="tag-chip"><i class="fa-solid fa-sack-dollar"></i> Subsidy</span>
                    <span class="tag-chip"><i class="fa-solid fa-flask"></i> Hydrogen</span>
                </div>
            </div>
            <div class="hero-media" aria-hidden="true">
                <img src="../images/hero/hero large.png" alt="Dakshayani editorial team" loading="lazy" />
                <div class="caption">Published in Ranchi · Updated <?= htmlspecialchars(format_ist($lastUpdated) ?: 'recently', ENT_QUOTES | ENT_HTML5) ?></div>
            </div>
        </div>
    </section>

    <section class="section">
        <div class="container blog-listing">
            <div class="blog-filters" aria-labelledby="blog-filter-title">
                <div>
                    <h2 id="blog-filter-title" style="margin:0; font-size:1.3rem;">Filter published posts</h2>
                    <p class="text-sm" style="margin:0; color:var(--base-500);">Search the archive or focus on a specific tag. Only published stories are listed.</p>
                </div>
                <form method="get" action="index.php" novalidate>
                    <label for="blog-search">Search title or excerpt
                        <input type="search" id="blog-search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES | ENT_HTML5) ?>" placeholder="e.g. net-metering timeline" />
                    </label>
                    <label for="blog-tag">Filter by tag
                        <select id="blog-tag" name="tag">
                            <option value="">All tags</option>
                            <?php foreach ($tags as $tagRow): ?>
                                <option value="<?= htmlspecialchars($tagRow['slug'], ENT_QUOTES | ENT_HTML5) ?>" <?= $tagRow['slug'] === $tag ? 'selected' : '' ?>><?= htmlspecialchars($tagRow['name'], ENT_QUOTES | ENT_HTML5) ?> (<?= (int) $tagRow['post_count'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="blog-per-page">Posts per page
                        <select id="blog-per-page" name="per_page">
                            <?php for ($size = 3; $size <= 12; $size += 3): ?>
                                <option value="<?= $size ?>" <?= $size === $perPage ? 'selected' : '' ?>><?= $size ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn btn-primary">Apply filters</button>
                    <?php if ($q !== '' || $tag !== '' || $perPage !== $defaultPageSize): ?>
                        <a href="index.php" class="btn btn-outline">Reset</a>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($posts): ?>
                <div class="blog-grid" aria-live="polite">
                    <?php foreach ($posts as $post): ?>
                        <?php
                        $cover = $post['cover_image_url'] ?? ($post['cover_image'] ?? '');
                        $coverAlt = $post['cover_image_alt'] ?? '';
                        $altText = $coverAlt !== '' ? $coverAlt : ($post['title'] ?? 'Blog cover');
                        if ($cover !== '' && !preg_match('#^(https?:|data:)#i', $cover)) {
                            $cover = '../' . ltrim($cover, '/');
                        }
                        $tagsDisplay = [];
                        foreach (($post['tags'] ?? []) as $tagItem) {
                            $tagName = is_array($tagItem) ? ($tagItem['name'] ?? '') : (string) $tagItem;
                            if ($tagName === '') {
                                continue;
                            }
                            $tagsDisplay[] = is_array($tagItem) ? $tagItem : ['name' => $tagName];
                        }
                        $postSlug = trim((string) ($post['slug'] ?? ''));
                        $postId = (string) ($post['id'] ?? $post['post_id'] ?? $post['draft_id'] ?? '');
                        $postUrl = $postSlug !== ''
                            ? 'post.php?slug=' . urlencode($postSlug)
                            : ($postId !== '' ? 'post.php?id=' . urlencode($postId) : '#');
                        ?>
                        <article class="blog-card">
                            <?php if ($cover): ?>
                                <img src="<?= htmlspecialchars($cover, ENT_QUOTES | ENT_HTML5) ?>" alt="<?= htmlspecialchars($altText, ENT_QUOTES | ENT_HTML5) ?>" loading="lazy" />
                            <?php else: ?>
                <img src="<?= htmlspecialchars(absolute_url('/images/hero/hero.png'), ENT_QUOTES | ENT_HTML5) ?>" alt="<?= htmlspecialchars($altText, ENT_QUOTES | ENT_HTML5) ?>" loading="lazy" />
                            <?php endif; ?>
                            <div class="blog-card-body">
                                <div class="blog-card-meta">
                                    <time datetime="<?= htmlspecialchars((string) $post['published_at'], ENT_QUOTES | ENT_HTML5) ?>"><?= htmlspecialchars(format_ist($post['published_at']), ENT_QUOTES | ENT_HTML5) ?></time>
                                </div>
                                <h3><?= htmlspecialchars($post['title'], ENT_QUOTES | ENT_HTML5) ?></h3>
                                <?php if (!empty($post['excerpt'])): ?>
                                    <p><?= htmlspecialchars($post['excerpt'], ENT_QUOTES | ENT_HTML5) ?></p>
                                <?php endif; ?>
                                <?php if ($tagsDisplay): ?>
                                    <div class="blog-card-tags" aria-label="Tags">
                                        <?php foreach ($tagsDisplay as $tagItem): ?>
                                            <span><?= htmlspecialchars($tagItem['name'] ?? $tagItem, ENT_QUOTES | ENT_HTML5) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <a
                                    class="btn btn-secondary"
                                    href="<?= htmlspecialchars($postUrl, ENT_QUOTES | ENT_HTML5) ?>"
                                >Read more</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="blog-empty" role="status">
                    <p class="lead">No published posts match your filters yet.</p>
                    <p>Check back soon—our admin team reviews and publishes new insights regularly.</p>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
                <div class="blog-pagination" aria-label="Blog pagination">
                    <nav>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <?php
                            $query = build_query([
                                'page' => $i,
                                'q' => $q,
                                'tag' => $tag,
                                'per_page' => $perPage !== $defaultPageSize ? $perPage : null,
                            ]);
                            $url = 'index.php' . ($query ? ('?' . $query) : '');
                            ?>
                            <?php if ($i === $page): ?>
                                <span class="current" aria-current="page">Page <?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= htmlspecialchars($url, ENT_QUOTES | ENT_HTML5) ?>">Page <?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </nav>
                    <div>
                        <span style="font-weight:600;">Showing <?= min($total, $offset + $perPage) ?> of <?= $total ?> published posts</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

</main>
<footer class="site-footer"></footer>
<script src="../script.js" defer></script>
</body>
</html>
