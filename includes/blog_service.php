<?php
declare(strict_types=1);

final class BlogService
{
    private const ASSET_BASE_PATH = 'storage/blog/assets/';

    private string $root;
    private string $draftDir;
    private string $publishedDir;
    private string $assetDir;
    private string $cacheDir;
    private string $tempDir;
    private string $lockDir;
    private string $projectRoot;

    public function __construct(?string $root = null)
    {
        $this->projectRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
        $this->root = $root ?? $this->projectRoot . '/storage/blog';
        $this->draftDir = $this->root . '/drafts';
        $this->publishedDir = $this->root . '/published';
        $this->assetDir = $this->root . '/assets';
        $this->cacheDir = $this->root . '/cache';
        $this->tempDir = $this->root . '/tmp';
        $this->lockDir = $this->root . '/locks';

        $this->initialiseStorage();
    }

    public function createDraft(array $input): array
    {
        $payload = $this->normaliseDraftPayload($input, null);
        $this->assertValidDraftPayload($payload);

        $draftId = $this->generateDraftId();
        $slug = $payload['slug'] !== '' ? $this->slugify($payload['slug']) : $this->generateSlug($payload['title']);
        $now = $this->now();

        $metadata = [
            'draft_id' => $draftId,
            'title' => $payload['title'],
            'slug' => $slug,
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
            'tags' => $payload['tags'],
            'summary' => $payload['summary'],
            'hero_image' => $payload['hero_image'],
            'hero_image_alt' => $payload['hero_image_alt'],
            'attachments' => $payload['attachments'],
            'author' => $payload['author'],
            'extra' => $payload['extra'],
        ];

        $this->writeDraft($draftId, $metadata, $payload['body_html']);

        return $this->formatDraftResponse($metadata, $payload['body_html']);
    }

    public function updateDraft(string $draftId, array $input): array
    {
        $existing = $this->getDraft($draftId);
        $payload = $this->normaliseDraftPayload($input, $existing);
        $this->assertValidDraftPayload($payload);

        $metadata = $existing['metadata'];
        $metadata['title'] = $payload['title'];
        $metadata['slug'] = $payload['slug'] !== '' ? $this->slugify($payload['slug']) : $metadata['slug'];
        $metadata['summary'] = $payload['summary'];
        $metadata['tags'] = $payload['tags'];
        $metadata['hero_image'] = $payload['hero_image'];
        $metadata['hero_image_alt'] = $payload['hero_image_alt'];
        $metadata['attachments'] = $payload['attachments'];
        $metadata['author'] = $payload['author'];
        $metadata['extra'] = $payload['extra'];
        $metadata['updated_at'] = $this->now();

        $this->writeDraft($draftId, $metadata, $payload['body_html']);

        return $this->formatDraftResponse($metadata, $payload['body_html']);
    }

    public function getDraft(string $draftId): array
    {
        $path = $this->draftDir . '/' . $draftId;
        if (!is_dir($path)) {
            throw new RuntimeException('Draft not found.');
        }

        $metadata = $this->readJson($path . '/metadata.json');
        if (!isset($metadata['draft_id'])) {
            $metadata['draft_id'] = $draftId;
        }
        $body = $this->readFile($path . '/content.html');

        return [
            'metadata' => $this->hydrateMetadataDefaults($metadata),
            'body_html' => $body,
        ];
    }

    public function getPublished(string $slug): array
    {
        $slug = $this->slugify($slug);
        $path = $this->publishedDir . '/' . $slug;
        if (!is_dir($path)) {
            throw new RuntimeException('Published post not found.');
        }

        $metadata = $this->readJson($path . '/metadata.json');
        $body = $this->readFile($path . '/content.html');

        return [
            'metadata' => $this->hydrateMetadataDefaults($metadata, false),
            'body_html' => $body,
        ];
    }

    public function listDrafts(): array
    {
        $drafts = [];
        foreach ($this->scanDirectory($this->draftDir) as $entry) {
            $metadataPath = $entry . '/metadata.json';
            if (!is_file($metadataPath)) {
                continue;
            }
            $metadata = $this->hydrateMetadataDefaults($this->readJson($metadataPath));
            $body = '';
            $bodyPath = $entry . '/content.html';
            if (is_file($bodyPath)) {
                $body = $this->readFile($bodyPath);
            }
            $drafts[] = $this->formatDraftResponse($metadata, $body);
        }

        usort($drafts, static function (array $a, array $b): int {
            return strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? '');
        });

        return $drafts;
    }

    public function listPublished(): array
    {
        $posts = [];
        foreach ($this->scanDirectory($this->publishedDir) as $entry) {
            $metadataPath = $entry . '/metadata.json';
            $contentPath = $entry . '/content.html';
            if (!is_file($metadataPath) || !is_file($contentPath)) {
                continue;
            }
            $metadata = $this->hydrateMetadataDefaults($this->readJson($metadataPath), false);
            $body = $this->readFile($contentPath);
            $posts[] = $this->formatPublishedResponse($metadata, $body);
        }

        usort($posts, static function (array $a, array $b): int {
            return strcmp($b['published_at'] ?? '', $a['published_at'] ?? '');
        });

        return $posts;
    }

    public function attachAssets(string $draftId, array $assets): array
    {
        $draft = $this->getDraft($draftId);
        $metadata = $draft['metadata'];
        $added = [];

        foreach ($assets as $asset) {
            $stored = $this->storeAssetDescriptor($asset);
            if ($stored === null) {
                continue;
            }
            $metadata['attachments'][] = $stored['internal_path'];
            $added[] = $stored;
        }

        if (!empty($added)) {
            $metadata['attachments'] = array_values(array_unique($metadata['attachments']));
            $metadata['updated_at'] = $this->now();
            $this->writeDraft($draftId, $metadata, $draft['body_html']);
        }

        return $added;
    }

    public function detachAsset(string $draftId, string $relativePath): array
    {
        $draft = $this->getDraft($draftId);
        $metadata = $draft['metadata'];
        $normalised = $this->normaliseRelativePath($relativePath);
        $metadata['attachments'] = array_values(array_filter($metadata['attachments'], function ($candidate) use ($normalised) {
            return $this->normaliseRelativePath((string) $candidate) !== $normalised;
        }));
        $metadata['updated_at'] = $this->now();
        $this->writeDraft($draftId, $metadata, $draft['body_html']);

        return $this->formatDraftResponse($metadata, $draft['body_html']);
    }

    public function promoteExternalAsset(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if ($this->isRemoteUrl($path)) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException(sprintf('Unable to download remote asset: %s', $path));
            }
            $extension = strtolower((string) pathinfo(parse_url($path, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
            return $this->storeAssetContents($contents, $extension);
        }

        $absolute = $this->resolveAbsoluteFromMixedPath($path);
        $assetRoot = rtrim(str_replace('\\', '/', $this->assetDir), '/');
        $absoluteNormalised = str_replace('\\', '/', $absolute);
        if (strpos($absoluteNormalised, $assetRoot) === 0) {
            return $this->relativePath($absolute);
        }

        $extension = strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $this->ensureDirectory($this->assetDir);
        $target = $this->assetDir . '/' . 'asset_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        if (!copy($absolute, $target)) {
            throw new RuntimeException('Failed to promote asset into blog store.');
        }
        $this->cleanupIfTemporary($absoluteNormalised);

        return $this->relativePath($target);
    }

    public function publishPost(string $draftId, array $context = []): array
    {
        $contextId = $this->newContextId();
        $this->logTelemetry('blog_publish_attempt', [
            'draft_id' => $draftId,
            'context_id' => $contextId,
        ]);

        $metadata = [];
        $lock = $this->acquireLock('draft_' . $draftId);
        try {
            $draft = $this->getDraft($draftId);
            $metadata = $draft['metadata'];
            $body = $draft['body_html'];
            $this->assertPublishable($metadata, $body);

            $resolvedSlug = $this->resolvePublishSlug($metadata['slug'], $metadata['draft_id']);
            $publishedDir = $this->publishedDir . '/' . $resolvedSlug;
            $stagingDir = $this->tempDir . '/publish_' . $resolvedSlug . '_' . bin2hex(random_bytes(4));
            $this->ensureEmptyDirectory($stagingDir);
            $assetsDir = $stagingDir . '/assets';
            $this->ensureDirectory($assetsDir);

            if ($metadata['hero_image'] !== '') {
                $this->guardReadableAsset($metadata['hero_image']);
                // Keep the canonical asset reference while still staging a published copy.
                try {
                    $this->materialiseAsset($metadata['hero_image'], $assetsDir, $resolvedSlug, 'hero');
                } catch (Throwable $exception) {
                    // Failure to stage a copy should not block publishing if the canonical asset exists.
                }
            }

            $attachments = [];
            foreach ($metadata['attachments'] as $index => $attachmentPath) {
                $attachments[] = $this->materialiseAsset($attachmentPath, $assetsDir, $resolvedSlug, 'asset_' . $index);
            }

            $publishedAt = $this->now();
            $publishedMetadata = [
                'post_id' => $metadata['draft_id'],
                'draft_id' => $metadata['draft_id'],
                'title' => $metadata['title'],
                'slug' => $resolvedSlug,
                'status' => 'published',
                'summary' => $metadata['summary'],
                'tags' => $metadata['tags'],
                'hero_image' => $metadata['hero_image'],
                'hero_image_alt' => $metadata['hero_image_alt'],
                'attachments' => $attachments,
                'author' => $metadata['author'],
                'created_at' => $metadata['created_at'],
                'updated_at' => $publishedAt,
                'published_at' => $publishedAt,
                'extra' => $metadata['extra'],
            ];

            $this->writeJson($stagingDir . '/metadata.json', $publishedMetadata);
            $this->writeFileAtomic($stagingDir . '/content.html', $body);

            $finalDir = $publishedDir;
            if (is_dir($finalDir)) {
                $this->ensureEmptyDirectory($finalDir, true);
            }
            $this->ensureDirectory(dirname($finalDir));
            if (!rename($stagingDir, $finalDir)) {
                throw new RuntimeException('Failed to finalise published article.');
            }

            $metadata['slug'] = $resolvedSlug;
            $metadata['extra']['last_published_at'] = $publishedAt;
            $metadata['extra']['last_published_slug'] = $resolvedSlug;
            $metadata['updated_at'] = $this->now();
            $this->writeDraft($metadata['draft_id'], $metadata, $body);

            $this->refreshCache();

            $result = $this->formatPublishedResponse($publishedMetadata, $body);
            $this->logTelemetry('blog_publish_success', [
                'draft_id' => $draftId,
                'slug' => $resolvedSlug,
                'context_id' => $contextId,
            ]);
            return $result;
        } catch (Throwable $exception) {
            $this->logTelemetry('blog_publish_error', [
                'draft_id' => $draftId,
                'slug' => $metadata['slug'] ?? null,
                'context_id' => $contextId,
                'developer_message' => $exception->getMessage(),
            ]);
            throw $exception;
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function unpublishPost(string $slug): array
    {
        $slug = $this->slugify($slug);
        $lock = $this->acquireLock('slug_' . $slug);
        try {
            $published = $this->getPublished($slug);
            $metadata = $published['metadata'];
            $body = $published['body_html'];

            $draftId = $metadata['draft_id'] ?? $this->generateDraftId();
            $draftPath = $this->draftDir . '/' . $draftId;
            $this->ensureDirectory($draftPath);

            $heroAsset = '';
            if ($metadata['hero_image'] !== '') {
                if ($this->isAssetStorePath($metadata['hero_image'])) {
                    $heroAsset = $this->normaliseRelativePath($metadata['hero_image']);
                } else {
                    $heroAsset = $this->restoreToAssetStore($metadata['hero_image']);
                }
            }

            $attachments = [];
            foreach ($metadata['attachments'] as $attachment) {
                $attachments[] = $this->restoreToAssetStore($attachment);
            }

            $draftMetadata = [
                'draft_id' => $draftId,
                'title' => $metadata['title'],
                'slug' => $metadata['slug'],
                'status' => 'draft',
                'created_at' => $metadata['created_at'],
                'updated_at' => $this->now(),
                'tags' => $metadata['tags'],
                'summary' => $metadata['summary'],
                'hero_image' => $heroAsset,
                'hero_image_alt' => $metadata['hero_image_alt'],
                'attachments' => $attachments,
                'author' => $metadata['author'],
                'extra' => $metadata['extra'],
            ];

            $this->writeDraft($draftId, $draftMetadata, $body);

            $this->removeDirectory($this->publishedDir . '/' . $slug);
            $this->refreshCache();

            return $this->formatDraftResponse($draftMetadata, $body);
        } finally {
            if ($lock !== null) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function deleteDraft(string $draftId): void
    {
        $path = $this->draftDir . '/' . $draftId;
        if (!is_dir($path)) {
            return;
        }
        $this->removeDirectory($path);
    }

    public function deletePublished(string $slug): void
    {
        $slug = $this->slugify($slug);
        $path = $this->publishedDir . '/' . $slug;
        if (!is_dir($path)) {
            return;
        }
        $this->removeDirectory($path);
        $this->refreshCache();
    }

    public function rebuildIndex(): void
    {
        $this->refreshCache();
    }

    public function healthCheck(): array
    {
        return [
            'drafts' => $this->isWritable($this->draftDir),
            'published' => $this->isWritable($this->publishedDir),
            'assets' => $this->isWritable($this->assetDir),
        ];
    }

    private function initialiseStorage(): void
    {
        foreach ([$this->root, $this->draftDir, $this->publishedDir, $this->assetDir, $this->cacheDir, $this->tempDir, $this->lockDir] as $dir) {
            $this->ensureDirectory($dir);
        }

        $this->repairLegacyDrafts();
        $this->repairLegacyPublished();
    }

    private function normaliseDraftPayload(array $input, ?array $existing): array
    {
        $existingMeta = $existing['metadata'] ?? [];
        $author = $input['author'] ?? ($existingMeta['author'] ?? []);
        if (!is_array($author)) {
            $author = [];
        }
        $authorId = isset($author['id']) ? (string) $author['id'] : '';
        if ($authorId === '' && isset($input['author_id'])) {
            $authorId = (string) $input['author_id'];
        }
        $authorName = isset($author['name']) ? (string) $author['name'] : '';
        if ($authorName === '' && isset($input['author_name'])) {
            $authorName = (string) $input['author_name'];
        }

        $body = $input['body_html'] ?? $input['body'] ?? ($existing['body_html'] ?? '');

        return [
            'title' => trim((string) ($input['title'] ?? ($existingMeta['title'] ?? ''))),
            'slug' => trim((string) ($input['slug'] ?? ($existingMeta['slug'] ?? ''))),
            'summary' => trim((string) ($input['summary'] ?? ($existingMeta['summary'] ?? ''))),
            'body_html' => (string) $body,
            'tags' => $this->normaliseTags($input['tags'] ?? ($existingMeta['tags'] ?? [])),
            'hero_image' => $this->normaliseRelativePath($input['hero_image'] ?? ($existingMeta['hero_image'] ?? '')),
            'hero_image_alt' => trim((string) ($input['hero_image_alt'] ?? ($existingMeta['hero_image_alt'] ?? ''))),
            'attachments' => $this->normaliseAttachments($input['attachments'] ?? ($existingMeta['attachments'] ?? [])),
            'author' => [
                'id' => $authorId,
                'name' => $authorName !== '' ? $authorName : 'Administrator',
            ],
            'extra' => is_array($input['extra'] ?? null) ? $input['extra'] : ($existingMeta['extra'] ?? []),
        ];
    }

    private function normaliseTags($tags): array
    {
        $normalised = [];
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag)) {
                    $label = trim($tag);
                } elseif (is_array($tag) && isset($tag['name'])) {
                    $label = trim((string) $tag['name']);
                } else {
                    $label = '';
                }
                if ($label !== '') {
                    $normalised[] = $label;
                }
            }
        }
        $normalised = array_values(array_unique($normalised));
        sort($normalised, SORT_NATURAL | SORT_FLAG_CASE);
        return $normalised;
    }

    private function normaliseAttachments($attachments): array
    {
        $normalised = [];
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    $path = $attachment;
                } elseif (is_array($attachment) && isset($attachment['path'])) {
                    $path = (string) $attachment['path'];
                } else {
                    $path = '';
                }
                $path = $this->normaliseRelativePath($path);
                if ($path !== '') {
                    $normalised[] = $path;
                }
            }
        }
        return array_values(array_unique($normalised));
    }

    private function hydrateMetadataDefaults(array $metadata, bool $isDraft = true): array
    {
        $metadata['draft_id'] = (string) ($metadata['draft_id'] ?? ($metadata['post_id'] ?? ''));
        if ($isDraft) {
            $metadata['status'] = 'draft';
        } else {
            $metadata['status'] = 'published';
        }
        $metadata['title'] = (string) ($metadata['title'] ?? '');
        $metadata['slug'] = $this->slugify((string) ($metadata['slug'] ?? ''));
        $metadata['summary'] = (string) ($metadata['summary'] ?? '');
        $metadata['tags'] = $this->normaliseTags($metadata['tags'] ?? []);
        $metadata['hero_image'] = $this->normaliseRelativePath($metadata['hero_image'] ?? '');
        $metadata['hero_image_alt'] = (string) ($metadata['hero_image_alt'] ?? '');
        $metadata['attachments'] = $this->normaliseAttachments($metadata['attachments'] ?? []);
        $metadata['author'] = is_array($metadata['author'] ?? null) ? $metadata['author'] : ['id' => '', 'name' => ''];
        $metadata['extra'] = is_array($metadata['extra'] ?? null) ? $metadata['extra'] : [];
        return $metadata;
    }

    private function assertValidDraftPayload(array $payload): void
    {
        if ($payload['title'] === '') {
            throw new RuntimeException('Title is required.');
        }
        if (trim($payload['body_html']) === '') {
            throw new RuntimeException('Body content is required.');
        }
    }

    private function assertPublishable(array $metadata, string $body): void
    {
        if (trim((string) $metadata['title']) === '') {
            throw new RuntimeException('Title is required before publishing.');
        }
        if (trim($body) === '') {
            throw new RuntimeException('Body content is required before publishing.');
        }
        if ($metadata['slug'] === '') {
            throw new RuntimeException('Slug is missing for this draft.');
        }

        foreach ($metadata['attachments'] as $path) {
            $this->guardReadableAsset($path);
        }
        if ($metadata['hero_image'] !== '') {
            $this->guardReadableAsset($metadata['hero_image']);
        }
    }

    private function resolvePublishSlug(string $slug, string $draftId): string
    {
        $slug = $this->slugify($slug !== '' ? $slug : 'post');
        $candidate = $slug;
        $existing = $this->publishedDir . '/' . $candidate;
        while (is_dir($existing)) {
            try {
                $current = $this->readJson($existing . '/metadata.json');
                if (($current['draft_id'] ?? '') === $draftId) {
                    return $candidate;
                }
            } catch (Throwable $exception) {
                // ignore
            }
            $candidate = $slug . '-' . substr($this->randomBase36(), 0, 6);
            $existing = $this->publishedDir . '/' . $candidate;
        }
        return $candidate;
    }

    private function materialiseAsset(string $relativePath, string $destinationDir, string $slug, string $basename): string
    {
        $source = $this->resolveRelativeToRoot($relativePath);
        if (!is_file($source)) {
            throw new RuntimeException(sprintf('Attachment missing: %s', $relativePath));
        }
        if (filesize($source) === 0) {
            throw new RuntimeException(sprintf('Attachment is empty: %s', $relativePath));
        }

        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $fileName = $basename . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        $target = rtrim($destinationDir, '/') . '/' . $fileName;
        if (!copy($source, $target)) {
            throw new RuntimeException('Failed to copy attachment into publish directory.');
        }

        $finalPath = $this->publishedDir . '/' . $slug . '/assets/' . $fileName;
        return $this->relativePath($finalPath);
    }

    private function restoreToAssetStore(string $relativePath): string
    {
        $source = $this->resolveRelativeToRoot($relativePath);
        if (!is_file($source)) {
            throw new RuntimeException(sprintf('Attachment could not be restored: %s', $relativePath));
        }
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $targetName = 'asset_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        $target = $this->assetDir . '/' . $targetName;
        if (!copy($source, $target)) {
            throw new RuntimeException('Failed to move attachment back to draft.');
        }
        return $this->relativePath($target);
    }

    private function guardReadableAsset(string $relativePath): void
    {
        $path = $this->resolveRelativeToRoot($relativePath);
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Attachment not found: %s', $relativePath));
        }
        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Attachment cannot be read: %s', $relativePath));
        }
        if (filesize($path) === 0) {
            throw new RuntimeException(sprintf('Attachment is empty: %s', $relativePath));
        }
    }

    private function storeAssetDescriptor($asset): ?array
    {
        if (is_array($asset) && isset($asset['tmp_name']) && is_uploaded_file($asset['tmp_name'] ?? '')) {
            return $this->storeUploadedFile($asset);
        }

        $path = '';
        $name = '';
        if (is_array($asset)) {
            $path = isset($asset['path']) ? (string) $asset['path'] : '';
            $name = isset($asset['name']) ? (string) $asset['name'] : '';
        } elseif (is_string($asset)) {
            $path = $asset;
        }

        $path = $this->normaliseRelativePath($path);
        if ($path === '') {
            return null;
        }

        $absolute = $this->resolveRelativeToRoot($path);
        if (!is_file($absolute)) {
            throw new RuntimeException(sprintf('Asset path does not exist: %s', $path));
        }
        if (!is_readable($absolute)) {
            throw new RuntimeException(sprintf('Asset is not readable: %s', $path));
        }

        $assetRootNormalised = rtrim(str_replace('\\', '/', $this->assetDir), '/');
        $absoluteNormalised = str_replace('\\', '/', $absolute);
        if (strpos($absoluteNormalised, $assetRootNormalised) === 0) {
            $relative = $this->relativePath($absolute);
            return [
                'internal_path' => $relative,
                'public_url' => $this->buildPublicUrl('/' . $relative),
            ];
        }

        $extension = strtolower((string) pathinfo($name !== '' ? $name : $absolute, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $target = $this->assetDir . '/' . 'asset_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        if (!copy($absolute, $target)) {
            throw new RuntimeException('Failed to copy asset into store.');
        }

        $relative = $this->relativePath($target);
        return [
            'internal_path' => $relative,
            'public_url' => $this->buildPublicUrl('/' . $relative),
        ];
    }

    private function storeUploadedFile(array $file): array
    {
        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '') {
            throw new RuntimeException('Upload missing temporary file.');
        }
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Upload did not originate from HTTP POST.');
        }
        $original = isset($file['name']) ? (string) $file['name'] : 'asset';
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        $extension = $extension !== '' ? '.' . $extension : '';
        $target = $this->assetDir . '/' . 'asset_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('Failed to move uploaded asset into store.');
        }

        $relative = $this->relativePath($target);
        return [
            'internal_path' => $relative,
            'public_url' => $this->buildPublicUrl('/' . $relative),
        ];
    }

    private function storeAssetContents(string $contents, string $extension = ''): string
    {
        $extension = trim($extension);
        $extension = $extension !== '' ? '.' . ltrim($extension, '.') : '';
        $this->ensureDirectory($this->assetDir);
        $target = $this->assetDir . '/' . 'asset_' . substr(bin2hex(random_bytes(6)), 0, 12) . $extension;
        if (file_put_contents($target, $contents) === false) {
            throw new RuntimeException('Failed to store generated asset.');
        }
        return $this->relativePath($target);
    }

    private function writeDraft(string $draftId, array $metadata, string $bodyHtml): void
    {
        $path = $this->draftDir . '/' . $draftId;
        $this->ensureDirectory($path);
        $metadata['draft_id'] = $draftId;
        $metadata['status'] = 'draft';
        $this->writeJson($path . '/metadata.json', $metadata);
        $this->writeFileAtomic($path . '/content.html', $bodyHtml);
    }

    private function formatDraftResponse(array $metadata, string $body): array
    {
        return [
            'draft_id' => $metadata['draft_id'],
            'title' => $metadata['title'],
            'slug' => $metadata['slug'],
            'status' => 'draft',
            'summary' => $metadata['summary'],
            'tags' => $metadata['tags'],
            'hero_image' => $metadata['hero_image'],
            'hero_image_alt' => $metadata['hero_image_alt'],
            'attachments' => $metadata['attachments'],
            'author' => $metadata['author'],
            'created_at' => $metadata['created_at'],
            'updated_at' => $metadata['updated_at'],
            'published_at' => $metadata['extra']['last_published_at'] ?? null,
            'body_html' => $body,
            'extra' => $metadata['extra'],
        ];
    }

    private function formatPublishedResponse(array $metadata, string $body): array
    {
        $heroUrl = $metadata['hero_image'] !== '' ? $this->buildPublicUrl('/' . $metadata['hero_image']) : '';
        $attachmentUrls = [];
        foreach ($metadata['attachments'] as $item) {
            $attachmentUrls[] = $this->buildPublicUrl('/' . $item);
        }

        return [
            'post_id' => $metadata['post_id'],
            'draft_id' => $metadata['draft_id'],
            'title' => $metadata['title'],
            'slug' => $metadata['slug'],
            'status' => 'published',
            'summary' => $metadata['summary'],
            'tags' => $metadata['tags'],
            'hero_image' => $metadata['hero_image'],
            'hero_image_url' => $heroUrl,
            'hero_image_alt' => $metadata['hero_image_alt'],
            'attachments' => $metadata['attachments'],
            'attachment_urls' => $attachmentUrls,
            'author' => $metadata['author'],
            'created_at' => $metadata['created_at'],
            'updated_at' => $metadata['updated_at'],
            'published_at' => $metadata['published_at'],
            'body_html' => $body,
            'extra' => $metadata['extra'],
            'url' => $this->buildPublicUrl('/blog/post.php?slug=' . rawurlencode($metadata['slug'])),
        ];
    }

    private function refreshCache(): void
    {
        $posts = $this->listPublished();
        $payload = json_encode($posts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        $this->writeFileAtomic($this->cacheDir . '/published_index.json', $payload);
    }

    private function repairLegacyDrafts(): void
    {
        foreach ($this->scanDirectory($this->draftDir) as $entry) {
            $metaPath = $entry . '/metadata.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $metadata = $this->readJson($metaPath);
            $changed = false;
            $draftId = basename($entry);
            $attachments = $this->normaliseAttachments($metadata['attachments'] ?? []);
            $convertedAttachments = [];
            foreach ($attachments as $attachment) {
                $converted = $this->maybePromoteLegacyAsset($attachment, $draftId);
                if ($converted !== '') {
                    $convertedAttachments[] = $converted;
                }
            }
            $assetDir = $entry . '/assets';
            if (is_dir($assetDir)) {
                $handle = opendir($assetDir);
                if ($handle !== false) {
                    while (($file = readdir($handle)) !== false) {
                        if ($file === '.' || $file === '..') {
                            continue;
                        }
                        $absolute = $assetDir . '/' . $file;
                        if (!is_file($absolute)) {
                            continue;
                        }
                        $legacyRelative = $this->relativePath($absolute);
                        $converted = $this->maybePromoteLegacyAsset($legacyRelative, $draftId);
                        if ($converted !== '' && !in_array($converted, $convertedAttachments, true)) {
                            $convertedAttachments[] = $converted;
                        }
                    }
                    closedir($handle);
                }
            }
            if (!empty($convertedAttachments) && $convertedAttachments !== $attachments) {
                $metadata['attachments'] = array_values(array_unique($convertedAttachments));
                $changed = true;
            } elseif (!isset($metadata['attachments']) || !is_array($metadata['attachments'])) {
                $metadata['attachments'] = [];
                $changed = true;
            }
            if (isset($metadata['hero_image'])) {
                $convertedHero = $this->maybePromoteLegacyAsset($metadata['hero_image'], $draftId);
                if ($convertedHero !== $metadata['hero_image']) {
                    $metadata['hero_image'] = $convertedHero;
                    $changed = true;
                }
            }
            if (!isset($metadata['attachments']) || !is_array($metadata['attachments'])) {
                $metadata['attachments'] = [];
                $changed = true;
            }
            if (!isset($metadata['extra']) || !is_array($metadata['extra'])) {
                $metadata['extra'] = [];
                $changed = true;
            }
            if ($changed) {
                $this->writeJson($metaPath, $metadata);
            }
        }
    }

    private function maybePromoteLegacyAsset(string $path, string $draftId): string
    {
        $path = $this->normaliseRelativePath($path);
        if ($path === '') {
            return '';
        }
        $legacyPrefix = 'storage/blog/drafts/' . $draftId . '/assets/';
        if (strpos($path, $legacyPrefix) === 0) {
            $absolute = $this->resolveRelativeToRoot($path);
            if (is_file($absolute)) {
                try {
                    $stored = $this->storeAssetDescriptor(['path' => $path]);
                    if ($stored !== null) {
                        return $stored['internal_path'];
                    }
                } catch (Throwable $exception) {
                    // fall through to path return
                }
            }
        }
        return $path;
    }

    private function repairLegacyPublished(): void
    {
        foreach ($this->scanDirectory($this->publishedDir) as $entry) {
            $metaPath = $entry . '/metadata.json';
            if (!is_file($metaPath)) {
                continue;
            }
            $metadata = $this->readJson($metaPath);
            $changed = false;
            if (!isset($metadata['attachments']) || !is_array($metadata['attachments'])) {
                $metadata['attachments'] = [];
                $changed = true;
            }
            if (!isset($metadata['extra']) || !is_array($metadata['extra'])) {
                $metadata['extra'] = [];
                $changed = true;
            }
            if ($changed) {
                $this->writeJson($metaPath, $metadata);
            }
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function ensureEmptyDirectory(string $dir, bool $reuse = false): void
    {
        if (is_dir($dir)) {
            $this->removeDirectory($dir);
            if (!$reuse) {
                mkdir($dir, 0775, true);
            }
        } elseif (!$reuse) {
            mkdir($dir, 0775, true);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private function scanDirectory(string $path): array
    {
        $items = [];
        $handle = @opendir($path);
        if ($handle === false) {
            return $items;
        }
        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_dir($full)) {
                $items[] = $full;
            }
        }
        closedir($handle);
        return $items;
    }

    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if ($contents === false || trim($contents) === '') {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to decode blog metadata: ' . $exception->getMessage(), 0, $exception);
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Failed to encode blog metadata.');
        }
        $this->writeFileAtomic($path, $json);
    }

    private function readFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return '';
        }
        return (string) $contents;
    }

    private function writeFileAtomic(string $path, string $contents): void
    {
        $dir = dirname($path);
        $this->ensureDirectory($dir);
        $temp = tempnam($this->tempDir, 'blog');
        if ($temp === false) {
            throw new RuntimeException('Failed to create temporary file for write.');
        }
        if (file_put_contents($temp, $contents) === false) {
            @unlink($temp);
            throw new RuntimeException('Failed to write contents to temporary file.');
        }
        if (!rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Failed to persist file atomically.');
        }
    }

    private function relativePath(string $absolute): string
    {
        $absoluteNormalised = str_replace('\\', '/', $absolute);
        if (strpos($absoluteNormalised, $this->projectRoot) === 0) {
            $relative = substr($absoluteNormalised, strlen($this->projectRoot));
            return ltrim($relative, '/');
        }
        $absoluteReal = realpath($absolute);
        if ($absoluteReal !== false && strpos(str_replace('\\', '/', $absoluteReal), $this->projectRoot) === 0) {
            $relative = substr(str_replace('\\', '/', $absoluteReal), strlen($this->projectRoot));
            return ltrim($relative, '/');
        }
        return ltrim($absoluteNormalised, '/');
    }

    private function normaliseRelativePath($path): string
    {
        if (!is_string($path)) {
            return '';
        }
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/\.(?=/|$)#', '/', $path) ?? $path;
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, './');
        if (preg_match('#(^|/)\.\.(?:/|$)#', $path)) {
            throw new RuntimeException('Parent directory traversal is not allowed.');
        }
        return $path;
    }

    private function resolveAbsoluteFromMixedPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('Empty asset path.');
        }
        $path = str_replace('\\', '/', $path);
        if ($this->isRemoteUrl($path)) {
            throw new RuntimeException('Remote paths must be downloaded separately.');
        }
        if (preg_match('#^[A-Za-z]:/#', $path)) {
            $absolute = $path;
        } elseif (strpos($path, '/') === 0) {
            $absolute = $path;
        } else {
            $absolute = $this->projectRoot . '/' . ltrim($path, '/');
        }
        if (is_file($absolute)) {
            return $absolute;
        }
        $real = realpath($absolute);
        if ($real !== false && is_file($real)) {
            return $real;
        }
        throw new RuntimeException(sprintf('Asset path does not exist: %s', $path));
    }

    private function cleanupIfTemporary(string $absolute): void
    {
        $absolute = str_replace('\\', '/', $absolute);
        $aiDir = str_replace('\\', '/', $this->projectRoot . '/storage/ai/');
        if (strpos($absolute, rtrim($aiDir, '/') . '/') === 0 && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function isRemoteUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    private function isAssetStorePath(string $relative): bool
    {
        $relative = $this->normaliseRelativePath($relative);
        if ($relative === '') {
            return false;
        }
        $assetRoot = ltrim(self::ASSET_BASE_PATH, '/');
        return strpos($relative, $assetRoot) === 0;
    }

    private function resolveRelativeToRoot(string $relative): string
    {
        $relative = $this->normaliseRelativePath($relative);
        if ($relative === '') {
            throw new RuntimeException('Empty path.');
        }
        return $this->projectRoot . '/' . $relative;
    }

    private function buildPublicUrl(string $path): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return rtrim($scheme . '://' . $host, '/') . '/' . ltrim($path, '/');
    }

    private function generateDraftId(): string
    {
        do {
            $id = 'drf_' . substr(bin2hex(random_bytes(8)), 0, 12);
        } while (is_dir($this->draftDir . '/' . $id));
        return $id;
    }

    private function generateSlug(string $title): string
    {
        $base = $this->slugify($title);
        do {
            $candidate = $base . '-' . substr($this->randomBase36(), 0, 6);
        } while (is_dir($this->publishedDir . '/' . $candidate));
        return $candidate;
    }

    private function slugify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'post';
        }
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
        $text = trim($text, '-');
        return $text !== '' ? $text : 'post';
    }

    private function randomBase36(): string
    {
        $bytes = random_bytes(4);
        $value = unpack('N', $bytes)[1];
        return strtolower(base_convert((string) $value, 10, 36));
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
    }

    private function newContextId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }

    private function logTelemetry(string $event, array $payload): void
    {
        $safePayload = [];
        foreach ($payload as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safePayload[$key] = $value;
            } elseif (is_array($value)) {
                $safePayload[$key] = json_encode($value, JSON_UNESCAPED_SLASHES);
            } else {
                $safePayload[$key] = (string) $value;
            }
        }
        $message = sprintf('[blog] %s: %s', $event, json_encode($safePayload, JSON_UNESCAPED_SLASHES));
        error_log($message);
    }

    private function acquireLock(string $name)
    {
        $lockPath = $this->lockDir . '/' . $this->slugify($name) . '.lock';
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new RuntimeException('Failed to acquire blog lock.');
        }
        return $handle;
    }

    private function isWritable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }
}

function blog_service(): BlogService
{
    static $service = null;
    if (!$service instanceof BlogService) {
        $service = new BlogService();
    }
    return $service;
}
