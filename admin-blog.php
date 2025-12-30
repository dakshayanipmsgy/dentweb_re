<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';

require_admin();
$admin = current_user();
$db = get_db();

$csrfToken = $_SESSION['csrf_token'] ?? '';
$flashData = consume_flash();
$flashMessage = '';
$flashTone = 'info';
if (is_array($flashData)) {
    if (isset($flashData['message']) && is_string($flashData['message'])) {
        $flashMessage = trim($flashData['message']);
    }
    if (isset($flashData['type']) && is_string($flashData['type'])) {
        $flashTone = strtolower($flashData['type']);
    }
}

function parse_tags(string $input): array
{
    $parts = preg_split('/[\n,]+/', $input) ?: [];
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim($part);
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }
    return $tags;
}

function blog_status_label(string $status): string
{
    $map = [
        'draft' => 'Draft',
        'pending' => 'Pending review',
        'published' => 'Published',
        'archived' => 'Archived',
    ];
    $key = strtolower($status);
    return $map[$key] ?? ucfirst($key);
}

function format_blog_datetime(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        $dt = new DateTimeImmutable($value);
        return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('d M Y · h:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token(is_string($token) ? $token : null)) {
        set_flash('error', 'Your session expired. Please try again.');
        header('Location: admin-blog.php');
        exit;
    }

    $action = (string) ($_POST['action'] ?? '');
    $redirectId = isset($_POST['id']) ? trim((string) $_POST['id']) : '';

    try {
        switch ($action) {
            case 'save-post':
                $payload = [
                    'id' => isset($_POST['id']) && $_POST['id'] !== '' ? trim((string) $_POST['id']) : null,
                    'title' => $_POST['title'] ?? '',
                    'slug' => $_POST['slug'] ?? '',
                    'excerpt' => $_POST['excerpt'] ?? '',
                    'body' => $_POST['body'] ?? '',
                    'authorName' => $_POST['author_name'] ?? '',
                    'status' => $_POST['status'] ?? 'draft',
                    'tags' => parse_tags((string) ($_POST['tags'] ?? '')),
                    'coverImage' => $_POST['cover_image'] ?? '',
                    'coverImageAlt' => $_POST['cover_image_alt'] ?? '',
                    'coverPrompt' => $_POST['cover_prompt'] ?? '',
                ];
                $saved = blog_save_post($db, $payload, (int) ($admin['id'] ?? 0));
                $redirectId = (string) ($saved['id'] ?? $redirectId);
                set_flash('success', 'Blog post saved successfully.');
                break;
            case 'publish-post':
                $postId = trim((string) ($_POST['id'] ?? ''));
                $publish = !empty($_POST['publish']);
                if (!$publish) {
                    set_flash('warning', 'Unpublishing is not supported in the current blog system.');
                    break;
                }
                blog_publish_post($db, $postId, $publish, (int) ($admin['id'] ?? 0));
                $redirectId = $postId;
                set_flash('success', 'Post published.');
                break;
            case 'archive-post':
                set_flash('warning', 'Archiving is not available with file-based storage.');
                break;
            default:
                throw new RuntimeException('Unsupported action.');
        }
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
    }

    $location = 'admin-blog.php';
    if ($redirectId !== '') {
        $location .= '?id=' . $redirectId;
    }

    header('Location: ' . $location);
    exit;
}

$selectedId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
$posts = blog_admin_list($db);
$selectedPost = null;
if ($selectedId !== '') {
    try {
        $selectedPost = blog_get_post_by_id($db, $selectedId);
    } catch (Throwable $exception) {
        set_flash('error', $exception->getMessage());
        $selectedPost = null;
    }
}

$formDefaults = [
    'id' => null,
    'title' => '',
    'slug' => '',
    'excerpt' => '',
    'body' => '',
    'authorName' => $admin['full_name'] ?? '',
    'status' => 'draft',
    'tags' => [],
    'coverImage' => '',
    'coverImageAlt' => '',
    'coverPrompt' => '',
];

if ($selectedPost) {
    $formDefaults = array_merge($formDefaults, [
        'id' => $selectedPost['id'],
        'title' => $selectedPost['title'],
        'slug' => $selectedPost['slug'],
        'excerpt' => $selectedPost['excerpt'] ?? '',
        'body' => $selectedPost['body'] ?? '',
        'authorName' => $selectedPost['authorName'] ?? ($admin['full_name'] ?? ''),
        'status' => $selectedPost['status'],
        'tags' => $selectedPost['tags'] ?? [],
        'coverImage' => $selectedPost['cover_image'] ?? ($selectedPost['coverImage'] ?? ''),
        'coverImageAlt' => $selectedPost['cover_image_alt'] ?? ($selectedPost['coverImageAlt'] ?? ''),
        'coverPrompt' => '',
    ]);
}

$tagsValue = $formDefaults['tags'] ? implode(', ', $formDefaults['tags']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Blog Publishing | Admin</title>
  <meta name="description" content="Create, edit, and publish blog posts to the Dakshayani public site." />
  <link rel="icon" href="images/favicon.ico" />
  <link rel="stylesheet" href="style.css" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap"
    rel="stylesheet"
  />
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    crossorigin="anonymous"
    referrerpolicy="no-referrer"
  />
</head>
<body class="admin-blog" data-theme="light">
  <main class="admin-blog__shell">
    <header class="admin-blog__header">
      <div>
        <p class="admin-blog__subtitle">Admin workspace</p>
        <h1 class="admin-blog__title">Blog publishing</h1>
        <p class="admin-blog__meta">Signed in as <strong><?= htmlspecialchars($admin['full_name'] ?? 'Administrator', ENT_QUOTES) ?></strong></p>
      </div>
      <div class="admin-blog__actions">
        <a href="admin-dashboard.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to overview</a>
        <a href="logout.php" class="btn btn-primary"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i> Log out</a>
      </div>
    </header>

    <?php if ($flashMessage !== ''): ?>
    <div class="admin-alert admin-alert--<?= htmlspecialchars($flashTone, ENT_QUOTES) ?>" role="status" aria-live="polite">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span><?= htmlspecialchars($flashMessage, ENT_QUOTES) ?></span>
    </div>
    <?php endif; ?>

    <section class="admin-panel" aria-labelledby="blog-editor">
      <div class="admin-panel__header">
        <div>
          <h2 id="blog-editor">Write and edit</h2>
          <p>Draft posts stay internal until published. Only published posts appear on the public blog.</p>
        </div>
      </div>
      <form method="post" class="admin-form admin-blog__form">
        <input type="hidden" name="action" value="save-post" />
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
        <?php if ($formDefaults['id']): ?>
        <input type="hidden" name="id" value="<?= htmlspecialchars((string) $formDefaults['id'], ENT_QUOTES) ?>" />
        <?php endif; ?>
        <div class="admin-form__grid">
          <label>
            Title
            <input type="text" name="title" required value="<?= htmlspecialchars($formDefaults['title'], ENT_QUOTES) ?>" placeholder="Post headline" />
          </label>
          <label>
            Slug
            <input type="text" name="slug" value="<?= htmlspecialchars($formDefaults['slug'], ENT_QUOTES) ?>" placeholder="optional-custom-slug" />
          </label>
          <label>
            Author name
            <input type="text" name="author_name" value="<?= htmlspecialchars($formDefaults['authorName'], ENT_QUOTES) ?>" placeholder="Editorial author" />
          </label>
          <label>
            Status
            <select name="status">
              <?php foreach (['draft' => 'Draft', 'pending' => 'Pending', 'published' => 'Published', 'archived' => 'Archived'] as $key => $label): ?>
              <option value="<?= $key ?>" <?= $formDefaults['status'] === $key ? 'selected' : '' ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <label class="admin-form__full">
          Tags
          <input type="text" name="tags" value="<?= htmlspecialchars($tagsValue, ENT_QUOTES) ?>" placeholder="Comma-separated keywords" />
        </label>
        <label class="admin-form__full">
          Excerpt
          <textarea name="excerpt" rows="3" placeholder="Summary used for cards and SEO."><?= htmlspecialchars($formDefaults['excerpt'], ENT_QUOTES) ?></textarea>
        </label>
        <label class="admin-form__full">
          Body HTML
          <textarea name="body" rows="16" required placeholder="Use semantic HTML (p, h2, ul) to structure content."><?= htmlspecialchars($formDefaults['body'], ENT_QUOTES) ?></textarea>
        </label>
        <div class="admin-form__grid">
          <label>
            Cover image URL
            <input type="text" name="cover_image" value="<?= htmlspecialchars($formDefaults['coverImage'], ENT_QUOTES) ?>" placeholder="Optional. Leave blank for auto image." />
          </label>
          <label>
            Cover alt text
            <input type="text" name="cover_image_alt" value="<?= htmlspecialchars($formDefaults['coverImageAlt'], ENT_QUOTES) ?>" placeholder="Describe the cover image" />
          </label>
          <label>
            Cover prompt
            <input type="text" name="cover_prompt" value="" placeholder="Optional hint for auto artwork" />
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Save post</button>
      </form>
    </section>

    <section class="admin-panel" aria-labelledby="blog-list">
      <div class="admin-panel__header">
        <div>
          <h2 id="blog-list">Post library</h2>
          <p>Manage published and draft content. Drafts remain internal until published.</p>
        </div>
        <span class="admin-panel__count"><?= count($posts) ?> posts</span>
      </div>
      <?php if (empty($posts)): ?>
      <p class="admin-empty">No blog posts yet. Save a draft above to get started.</p>
      <?php else: ?>
      <div class="admin-table-wrapper">
        <table class="admin-table">
          <thead>
            <tr>
              <th scope="col">Title</th>
              <th scope="col">Status</th>
              <th scope="col">Updated</th>
              <th scope="col">Published</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($posts as $post): ?>
            <?php $rowId = (string) ($post['id'] ?? ''); ?>
            <tr <?= ($selectedId !== '' && $selectedId === $rowId) ? 'class="is-selected"' : '' ?>>
              <td>
                <a href="admin-blog.php?id=<?= htmlspecialchars($rowId, ENT_QUOTES) ?>" class="admin-link"><?= htmlspecialchars($post['title'], ENT_QUOTES) ?></a>
                <?php if (!empty($post['excerpt'])): ?>
                <div class="admin-muted"><?= htmlspecialchars($post['excerpt'], ENT_QUOTES) ?></div>
                <?php endif; ?>
              </td>
              <td><span class="admin-badge admin-badge--<?= htmlspecialchars($post['status'], ENT_QUOTES) ?>"><?= htmlspecialchars(blog_status_label($post['status']), ENT_QUOTES) ?></span></td>
              <td><?= htmlspecialchars(format_blog_datetime($post['updated_at']), ENT_QUOTES) ?></td>
              <td><?= $post['published_at'] ? htmlspecialchars(format_blog_datetime($post['published_at']), ENT_QUOTES) : '—' ?></td>
              <td class="admin-blog__actions-cell">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>" />
                  <input type="hidden" name="id" value="<?= htmlspecialchars($rowId, ENT_QUOTES) ?>" />
                  <?php if ($post['status'] === 'published'): ?>
                  <a href="<?= htmlspecialchars($post['url'] ?? ('/blog/post.php?slug=' . urlencode($post['slug'] ?? '')), ENT_QUOTES) ?>" class="btn btn-secondary btn-xs" target="_blank" rel="noopener">View live</a>
                  <?php else: ?>
                  <input type="hidden" name="action" value="publish-post" />
                  <input type="hidden" name="publish" value="1" />
                  <button type="submit" class="btn btn-success btn-xs">Publish</button>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
