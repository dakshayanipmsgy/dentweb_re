<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
start_session();
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(16));
$settings = solar_finance_settings();
$msg=''; $tone='info';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {$msg='Session expired.';$tone='error';}
  else {
    try {
      $content = $settings['content'];
      $content['page_title']=trim((string)($_POST['page_title']??''));
      $content['hero_text']=trim((string)($_POST['hero_text']??''));
      $content['cta_text']=trim((string)($_POST['cta_text']??''));
      $content['explainer_cards']=json_decode((string)($_POST['explainer_cards']??'[]'), true) ?: $content['explainer_cards'];
      $content['faq']=json_decode((string)($_POST['faq']??'[]'), true) ?: $content['faq'];
      $defaults = [
        'daily_generation_per_kw'=>(float)($_POST['daily_generation_per_kw']??5),
        'unit_rate'=>(float)($_POST['unit_rate']??8),
        'interest_upto_2_lacs'=>(float)($_POST['interest_upto_2_lacs']??6),
        'interest_above_2_lacs'=>(float)($_POST['interest_above_2_lacs']??8.15),
        'loan_tenure_years'=>(int)($_POST['loan_tenure_years']??10),
        'co2_factor_kg_per_unit'=>(float)($_POST['co2_factor_kg_per_unit']??0.82),
        'tree_factor_kg_per_tree'=>(float)($_POST['tree_factor_kg_per_tree']??21),
        'roof_area_sqft_per_kw'=>(float)($_POST['roof_area_sqft_per_kw']??100),
      ];
      $onGrid=json_decode((string)($_POST['on_grid_prices']??'[]'), true); $hybrid=json_decode((string)($_POST['hybrid_prices']??'[]'), true);
      if (!is_array($onGrid) || !is_array($hybrid)) {throw new RuntimeException('Price tables must be valid JSON arrays.');}
      solar_finance_settings_save(['content'=>$content,'defaults'=>$defaults,'on_grid_prices'=>$onGrid,'hybrid_prices'=>$hybrid]);
      $settings=solar_finance_settings(); $msg='Solar & Finance settings saved.'; $tone='success';
    } catch (Throwable $e) {$msg='Error: '.$e->getMessage();$tone='error';}
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Solar & Finance Settings</title><link rel="stylesheet" href="/style.css"><style>main{max-width:none;padding:1rem 1.2rem}textarea{width:100%;min-height:160px} .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.8rem}</style></head>
<body><main>
  <h1>Solar &amp; Finance Settings</h1><p><a href="/admin-dashboard.php">← Back to dashboard</a></p>
  <?php if($msg): ?><div class="admin-alert admin-alert--<?= htmlspecialchars($tone) ?>"><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <div class="grid">
      <label>Page title<input name="page_title" value="<?= htmlspecialchars((string)$settings['content']['page_title']) ?>"></label>
      <label>CTA text<input name="cta_text" value="<?= htmlspecialchars((string)$settings['content']['cta_text']) ?>"></label>
    </div>
    <label>Hero text<textarea name="hero_text"><?= htmlspecialchars((string)$settings['content']['hero_text']) ?></textarea></label>
    <h3>Calculator defaults</h3>
    <div class="grid"><?php foreach(($settings['defaults']??[]) as $k=>$v): ?><label><?= htmlspecialchars($k) ?><input name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>"></label><?php endforeach; ?></div>
    <h3>Explainer cards JSON</h3><textarea name="explainer_cards"><?= htmlspecialchars((string)json_encode($settings['content']['explainer_cards'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea>
    <h3>FAQ JSON</h3><textarea name="faq"><?= htmlspecialchars((string)json_encode($settings['content']['faq'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea>
    <h3>On-grid prices JSON</h3><textarea name="on_grid_prices"><?= htmlspecialchars((string)json_encode($settings['on_grid_prices'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea>
    <h3>Hybrid prices JSON</h3><textarea name="hybrid_prices"><?= htmlspecialchars((string)json_encode($settings['hybrid_prices'], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></textarea>
    <p><button class="btn btn-primary" type="submit">Save</button></p>
  </form>
</main></body></html>
