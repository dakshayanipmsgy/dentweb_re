<?php
declare(strict_types=1);

function blog_slugify(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return 'post';
    }

    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($converted !== false) {
        $text = $converted;
    }
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'post';
}

function blog_normalize_tag(string $tag): string
{
    $tag = trim($tag);
    $tag = preg_replace('/\s+/', ' ', $tag) ?? '';
    return $tag;
}

function blog_extract_plain_text(string $html): string
{
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    return trim($text);
}

function blog_generate_placeholder_cover(string $title, string $prompt = ''): array
{
    $baseTitle = trim($title) !== '' ? trim($title) : 'Dakshayani Blog';
    $promptText = trim($prompt) !== '' ? trim($prompt) : 'Clean energy insights';
    $primary = function_exists('mb_substr') ? mb_substr($baseTitle, 0, 64) : substr($baseTitle, 0, 64);
    $secondary = function_exists('mb_substr') ? mb_substr($promptText, 0, 64) : substr($promptText, 0, 64);
    $primaryEscaped = htmlspecialchars($primary, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    $secondaryEscaped = htmlspecialchars($secondary, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

    $svg = <<<SVG
<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 1200 630' role='img'>
  <defs>
    <linearGradient id='g' x1='0%' y1='0%' x2='100%' y2='100%'>
      <stop offset='0%' stop-color='#1d4ed8'/>
      <stop offset='100%' stop-color='#0ea5e9'/>
    </linearGradient>
  </defs>
  <rect fill='url(#g)' width='1200' height='630'/>
  <g fill='#ffffff'>
    <text x='50%' y='45%' font-size='64' font-family='Inter, Arial, sans-serif' text-anchor='middle' font-weight='600'>{$primaryEscaped}</text>
    <text x='50%' y='70%' font-size='32' font-family='Inter, Arial, sans-serif' text-anchor='middle' opacity='0.85'>Dakshayani Insights · {$secondaryEscaped}</text>
  </g>
</svg>
SVG;

    $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);
    $alt = sprintf('Illustrative cover for %s', $baseTitle);

    return [$dataUri, $alt];
}

function blog_sanitise_body(string $html): string
{
    // Preserve compatibility with previous sanitiser by allowing basic formatting.
    $allowed = ['p', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'blockquote', 'h2', 'h3', 'h4', 'figure', 'figcaption', 'code', 'pre', 'br'];
    $document = new DOMDocument();
    libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $walker = static function (DOMNode $node) use (&$walker, $allowed) {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (!in_array($tag, $allowed, true)) {
                if ($node->parentNode) {
                    while ($node->firstChild) {
                        $node->parentNode->insertBefore($node->firstChild, $node);
                    }
                    $node->parentNode->removeChild($node);
                }
                return;
            }
            foreach (iterator_to_array($node->attributes) as $attr) {
                if ($tag === 'a' && strtolower($attr->name) === 'href') {
                    $href = trim($attr->value);
                    if ($href === '' || !preg_match('/^(https?:|mailto:)/i', $href)) {
                        $node->removeAttributeNode($attr);
                    } else {
                        $node->setAttribute('rel', 'noopener');
                    }
                    continue;
                }
                if (!in_array(strtolower($attr->name), ['href', 'title', 'class'], true)) {
                    $node->removeAttributeNode($attr);
                }
            }
        }
        foreach (iterator_to_array($node->childNodes) as $child) {
            $walker($child);
        }
    };

    foreach (iterator_to_array($document->documentElement->childNodes) as $child) {
        $walker($child);
    }

    $htmlOutput = '';
    foreach ($document->documentElement->childNodes as $child) {
        $htmlOutput .= $document->saveHTML($child);
    }

    return $htmlOutput;
}

function blog_render_excerpt(string $bodyHtml, string $summary = ''): string
{
    if ($summary !== '') {
        return trim($summary);
    }
    $plain = blog_extract_plain_text($bodyHtml);
    return trim(mb_substr($plain, 0, 240));
}

function blog_filters_apply(array $posts, array $filters): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    $tag = trim((string) ($filters['tag'] ?? ''));

    return array_values(array_filter($posts, static function (array $post) use ($search, $tag) {
        if ($tag !== '') {
            $tagMatches = array_filter($post['tags'] ?? [], static function ($candidate) use ($tag) {
                return strcasecmp($candidate, $tag) === 0;
            });
            if (empty($tagMatches)) {
                return false;
            }
        }
        if ($search !== '') {
            $haystacks = [
                $post['title'] ?? '',
                $post['summary'] ?? '',
                blog_extract_plain_text($post['body_html'] ?? ''),
            ];
            $found = false;
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && stripos($haystack, $search) !== false) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        return true;
    }));
}

function blog_fetch_published($unusedDb, array $filters, int $limit, int $offset): array
{
    $service = blog_service();
    $posts = array_map('blog_map_published_post', $service->listPublished());
    $filtered = blog_filters_apply($posts, $filters);
    $total = count($filtered);
    $paged = array_slice($filtered, $offset, $limit);

    return [
        'posts' => $paged,
        'total' => $total,
    ];
}

function blog_get_tag_summary($unusedDb): array
{
    $service = blog_service();
    $posts = $service->listPublished();
    $counts = [];
    foreach ($posts as $post) {
        foreach ($post['tags'] ?? [] as $tag) {
            $key = strtolower($tag);
            if ($key === '') {
                continue;
            }
            if (!isset($counts[$key])) {
                $counts[$key] = ['name' => $tag, 'post_count' => 0, 'slug' => blog_slugify($tag)];
            }
            $counts[$key]['post_count']++;
        }
    }
    usort($counts, static function ($a, $b) {
        return $b['post_count'] <=> $a['post_count'] ?: strcasecmp($a['name'], $b['name']);
    });
    return array_values($counts);
}

function blog_get_latest_update($unusedDb): ?string
{
    $service = blog_service();
    $posts = $service->listPublished();
    if (empty($posts)) {
        return null;
    }
    usort($posts, static function ($a, $b) {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });
    return $posts[0]['updated_at'] ?? null;
}

function blog_get_post_by_slug($unusedDb, string $slug, bool $includeDrafts = false): ?array
{
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    $service = blog_service();
    foreach ($service->listPublished() as $post) {
        if ($post['slug'] === $slug) {
            return blog_map_published_post($post);
        }
    }
    if ($includeDrafts) {
        foreach ($service->listDrafts() as $draft) {
            if (($draft['slug'] ?? '') === $slug) {
                return blog_map_draft_post($draft);
            }
        }
    }
    return null;
}

function blog_get_post_by_id($unusedDb, $postId): array
{
    $id = (string) $postId;
    $service = blog_service();
    foreach ($service->listPublished() as $post) {
        if ($post['post_id'] === $id || $post['draft_id'] === $id) {
            return blog_map_published_post($post);
        }
    }
    foreach ($service->listDrafts() as $draft) {
        if (($draft['draft_id'] ?? '') === $id) {
            return blog_map_draft_post($draft);
        }
    }
    throw new RuntimeException('Post not found.');
}

function blog_get_adjacent_posts($unusedDb, $postId): array
{
    $id = (string) $postId;
    $service = blog_service();
    $posts = array_values(array_map('blog_map_published_post', $service->listPublished()));
    foreach ($posts as $index => $post) {
        if ($post['id'] === $id) {
            $prev = $posts[$index + 1] ?? null;
            $next = $posts[$index - 1] ?? null;
            return [
                'previous' => $prev ? blog_summarise_post($prev) : null,
                'next' => $next ? blog_summarise_post($next) : null,
            ];
        }
    }
    return ['previous' => null, 'next' => null];
}

function blog_related_posts($unusedDb, $postId, int $limit = 3): array
{
    $id = (string) $postId;
    $service = blog_service();
    $target = null;
    $published = array_map('blog_map_published_post', $service->listPublished());
    foreach ($published as $post) {
        if ($post['id'] === $id) {
            $target = $post;
            break;
        }
    }
    if ($target === null) {
        return [];
    }

    $targetTags = blog_related_post_tag_names($target['tags'] ?? []);
    if (empty($targetTags)) {
        return [];
    }

    $related = [];
    foreach ($published as $post) {
        if ($post['id'] === $target['id']) {
            continue;
        }
        $postTags = blog_related_post_tag_names($post['tags'] ?? []);
        if (empty($postTags)) {
            continue;
        }
        $intersection = array_intersect($postTags, $targetTags);
        if (!empty($intersection)) {
            $related[] = $post;
        }
    }
    usort($related, static function ($a, $b) {
        return strcmp($b['published_at'] ?? '', $a['published_at'] ?? '');
    });
    return array_slice(array_map('blog_summarise_post', $related), 0, $limit);
}

function blog_related_post_tag_names(array $tags): array
{
    $names = [];
    foreach ($tags as $tag) {
        $label = is_array($tag) ? ($tag['name'] ?? '') : $tag;
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }
        $names[] = strtolower($label);
    }
    if (empty($names)) {
        return [];
    }
    $names = array_values(array_unique($names));
    return $names;
}

function blog_admin_list($unusedDb): array
{
    $service = blog_service();
    $drafts = array_map('blog_map_draft_post', $service->listDrafts());
    $published = array_map('blog_map_published_post', $service->listPublished());
    $combined = array_merge($published, $drafts);
    usort($combined, static function ($a, $b) {
        return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
    });
    return $combined;
}

function blog_save_post($unusedDb, array $input, int $actorId): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $body = trim((string) ($input['body'] ?? ''));
    if ($title === '' || $body === '') {
        throw new RuntimeException('Title and body are required.');
    }

    $summary = trim((string) ($input['excerpt'] ?? ''));
    $coverImage = trim((string) ($input['coverImage'] ?? ($input['cover_image'] ?? '')));
    $coverAlt = trim((string) ($input['coverImageAlt'] ?? ($input['cover_image_alt'] ?? '')));
    $tagsRaw = $input['tags'] ?? [];
    if (is_string($tagsRaw)) {
        $tagsRaw = array_map('trim', preg_split('/[,\n]+/', $tagsRaw) ?: []);
    }
    $tags = [];
    foreach ((array) $tagsRaw as $tag) {
        $tag = blog_normalize_tag((string) $tag);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    $service = blog_service();
    $payload = [
        'title' => $title,
        'summary' => blog_render_excerpt($body, $summary),
        'body_html' => blog_sanitise_body($body),
        'hero_image' => $coverImage,
        'hero_image_alt' => $coverAlt,
        'tags' => $tags,
        'author' => [
            'id' => (string) $actorId,
            'name' => trim((string) ($input['authorName'] ?? $input['author_name'] ?? 'Administrator')),
        ],
        'attachments' => $input['attachments'] ?? [],
        'extra' => [
            'source' => $input['source'] ?? 'admin',
            'status' => $input['status'] ?? 'draft',
        ],
    ];

    $draftId = isset($input['id']) && $input['id'] !== '' ? (string) $input['id'] : null;
    if ($draftId !== null) {
        $draft = $service->updateDraft($draftId, $payload);
    } else {
        $draft = $service->createDraft($payload);
    }

    return blog_map_draft_post($draft);
}

function blog_publish_post($unusedDb, $postId, bool $publish, int $actorId): array
{
    if (!$publish) {
        throw new RuntimeException('Unpublishing is not supported in the file-based blog.');
    }
    $service = blog_service();
    $post = $service->publishPost((string) $postId, ['actor' => $actorId]);
    return blog_map_published_post($post);
}

function blog_archive_post($unusedDb, $postId, int $actorId): array
{
    throw new RuntimeException('Archiving is not supported in the file-based blog.');
}

function blog_seed_default($unusedDb): void
{
    // No seed data required for the filesystem blog implementation.
}

function blog_backfill_cover_images($unusedDb): void
{
    // No-op for filesystem implementation.
}

function blog_map_published_post(array $post): array
{
    $author = $post['author']['name'] ?? '';
    $tags = array_values(array_filter(array_map(static function ($tag) {
        $label = is_string($tag) ? $tag : ($tag['name'] ?? '');
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        return ['name' => $label, 'slug' => blog_slugify($label)];
    }, $post['tags'] ?? [])));
    return [
        'id' => (string) ($post['post_id'] ?? $post['draft_id'] ?? ''),
        'title' => $post['title'] ?? '',
        'slug' => $post['slug'] ?? '',
        'excerpt' => $post['summary'] ?? '',
        'body_html' => $post['body_html'] ?? '',
        'cover_image' => $post['hero_image'] ?? '',
        'cover_image_url' => $post['hero_image_url'] ?? '',
        'cover_image_alt' => $post['hero_image_alt'] ?? '',
        'attachments' => array_values(array_map(static function ($item) {
            if (is_array($item) && isset($item['path'])) {
                return $item['path'];
            }
            return (string) $item;
        }, $post['attachments'] ?? [])),
        'attachment_urls' => $post['attachment_urls'] ?? [],
        'author_name' => $author,
        'status' => 'published',
        'tags' => $tags,
        'created_at' => $post['created_at'] ?? null,
        'updated_at' => $post['updated_at'] ?? null,
        'published_at' => $post['published_at'] ?? null,
        'url' => $post['url'] ?? '',
    ];
}

function blog_map_draft_post(array $draft): array
{
    $author = $draft['author']['name'] ?? '';
    $tags = array_values(array_filter(array_map(static function ($tag) {
        $label = is_string($tag) ? $tag : ($tag['name'] ?? '');
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        return ['name' => $label, 'slug' => blog_slugify($label)];
    }, $draft['tags'] ?? [])));
    return [
        'id' => (string) ($draft['draft_id'] ?? ''),
        'title' => $draft['title'] ?? '',
        'slug' => $draft['slug'] ?? '',
        'excerpt' => $draft['summary'] ?? '',
        'body_html' => $draft['body_html'] ?? '',
        'cover_image' => $draft['hero_image'] ?? '',
        'cover_image_alt' => $draft['hero_image_alt'] ?? '',
        'attachments' => array_values(array_map(static function ($item) {
            if (is_array($item) && isset($item['path'])) {
                return $item['path'];
            }
            return (string) $item;
        }, $draft['attachments'] ?? [])),
        'author_name' => $author,
        'status' => $draft['status'] ?? 'draft',
        'tags' => $tags,
        'created_at' => $draft['created_at'] ?? null,
        'updated_at' => $draft['updated_at'] ?? null,
        'published_at' => $draft['published_at'] ?? null,
        'url' => '',
    ];
}

function blog_summarise_post(array $post): array
{
    return [
        'id' => $post['id'],
        'title' => $post['title'],
        'slug' => $post['slug'],
        'excerpt' => $post['excerpt'],
        'cover_image' => $post['cover_image'],
        'cover_image_alt' => $post['cover_image_alt'],
        'published_at' => $post['published_at'],
        'url' => $post['url'] ?? '',
    ];
}
