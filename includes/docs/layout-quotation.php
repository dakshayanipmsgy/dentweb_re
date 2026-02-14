<?php

declare(strict_types=1);

function quotation_fmt_money(float $amount): string
{
    return number_format($amount, 2);
}

function quotation_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function quotation_render_layout(array $quotation, array $templateSet, array $company): string
{
    $pricing = is_array($quotation['pricing'] ?? null) ? $quotation['pricing'] : [];
    $bucket5 = is_array($pricing['bucket_5'] ?? null) ? $pricing['bucket_5'] : [];
    $bucket18 = is_array($pricing['bucket_18'] ?? null) ? $pricing['bucket_18'] : [];
    $isIntraState = trim((string) ($quotation['place_of_supply_state_code'] ?? '20')) === '20';

    $blocks = is_array($templateSet['blocks'] ?? null) ? $templateSet['blocks'] : [];
    $defaults = is_array($templateSet['defaults'] ?? null) ? $templateSet['defaults'] : [];
    $milestones = is_array($defaults['payment_milestones'] ?? null) ? $defaults['payment_milestones'] : [];

    $page1Bg = trim((string) ($templateSet['page_1_background'] ?? ''));
    $annexBg = trim((string) ($templateSet['annexure_background'] ?? ''));

    $logo = trim((string) ($company['logo_path'] ?? '/images/Logopngsmallest.png'));
    if ($logo === '') {
        $logo = '/images/Logopngsmallest.png';
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= quotation_escape((string) ($quotation['quote_number'] ?? 'Quotation')) ?></title>
    <style>
        @page { size: A4; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; color: #1f2937; background: #eef2f7; }
        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 0 auto 8mm;
            padding: 16mm 14mm;
            background: #fff;
            background-size: cover;
            background-position: center center;
            page-break-after: always;
        }
        .page:last-child { page-break-after: auto; }
        .first-page { background-image: <?= $page1Bg !== '' ? "url('" . quotation_escape($page1Bg) . "')" : 'none' ?>; }
        .annexure-page { background-image: <?= $annexBg !== '' ? "url('" . quotation_escape($annexBg) . "')" : 'none' ?>; }
        .top { display: flex; justify-content: space-between; gap: 12mm; border-bottom: 1px solid #d1d5db; padding-bottom: 4mm; }
        .company { display: flex; gap: 4mm; }
        .company img { width: 22mm; height: auto; object-fit: contain; }
        .company h1 { margin: 0; font-size: 18px; }
        .meta { text-align: right; font-size: 12px; }
        .muted { color: #4b5563; font-size: 11px; margin-top: 2px; }
        .party { margin-top: 6mm; border: 1px solid #d1d5db; padding: 4mm; border-radius: 2mm; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; margin-top: 6mm; font-size: 12px; }
        th, td { border: 1px solid #d1d5db; padding: 2.5mm; vertical-align: top; }
        th { background: #f3f4f6; text-align: left; }
        .totals { margin-top: 4mm; width: 58%; margin-left: auto; }
        .totals td:first-child { width: 62%; }
        .totals .final td { font-weight: 700; font-size: 14px; }
        h3 { margin: 0 0 3mm; font-size: 14px; border-bottom: 1px solid #e5e7eb; padding-bottom: 2mm; }
        .block { border: 1px solid #d1d5db; border-radius: 2mm; padding: 4mm; margin-bottom: 4mm; background: rgba(255,255,255,0.92); }
        ul { margin: 0; padding-left: 18px; }
    </style>
</head>
<body>
<div class="page first-page">
    <div class="top">
        <div class="company">
            <img src="<?= quotation_escape($logo) ?>" alt="Logo">
            <div>
                <h1><?= quotation_escape((string) ($company['company_name'] ?? 'Dakshayani Enterprises')) ?></h1>
                <div class="muted"><?= quotation_escape((string) ($company['address_line'] ?? '')) ?></div>
                <div class="muted">GSTIN: <?= quotation_escape((string) ($company['gstin'] ?? '')) ?> | UDYAM: <?= quotation_escape((string) ($company['udyam'] ?? '')) ?></div>
                <div class="muted">Contact: <?= quotation_escape(trim((string) (($company['phone_1'] ?? '') . ' ' . ($company['phone_2'] ?? '')))) ?></div>
            </div>
        </div>
        <div class="meta">
            <div><strong>Quotation</strong></div>
            <div>No: <?= quotation_escape((string) ($quotation['quote_number'] ?? '')) ?></div>
            <div>Date: <?= quotation_escape(substr((string) ($quotation['created_at'] ?? ''), 0, 10)) ?></div>
            <div>Valid Until: <?= quotation_escape((string) ($quotation['valid_until'] ?? '')) ?></div>
        </div>
    </div>

    <div class="party">
        <strong>Customer:</strong> <?= quotation_escape((string) ($quotation['customer_name'] ?? '')) ?><br>
        <strong>Billing Address:</strong> <?= nl2br(quotation_escape((string) ($quotation['billing_address'] ?? ''))) ?><br>
        <strong>Shipping Address:</strong> <?= nl2br(quotation_escape((string) ($quotation['shipping_address'] ?? ''))) ?><br>
        <strong>GSTIN:</strong> <?= quotation_escape((string) ($quotation['gstin'] ?? '')) ?>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:7%;">#</th>
                <th>Description</th>
                <th style="width:18%; text-align:right;">Taxable Value (₹)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td><strong>Ongrid Solar Power Generation System 5%</strong><br><span class="muted">System Inclusions are given in Annexures.</span></td>
                <td style="text-align:right;"><?= quotation_fmt_money((float) ($bucket5['basic'] ?? 0)) ?></td>
            </tr>
            <tr>
                <td>2</td>
                <td><strong>Ongrid Solar Power Generation System 18%</strong><br><span class="muted">System Inclusions are given in Annexures.</span></td>
                <td style="text-align:right;"><?= quotation_fmt_money((float) ($bucket18['basic'] ?? 0)) ?></td>
            </tr>
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($pricing['basic_total'] ?? 0)) ?></td></tr>
        <?php if ($isIntraState): ?>
            <tr><td>CGST @ 2.5%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket5['cgst'] ?? 0)) ?></td></tr>
            <tr><td>SGST @ 2.5%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket5['sgst'] ?? 0)) ?></td></tr>
            <tr><td>CGST @ 9%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket18['cgst'] ?? 0)) ?></td></tr>
            <tr><td>SGST @ 9%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket18['sgst'] ?? 0)) ?></td></tr>
        <?php else: ?>
            <tr><td>IGST @ 5%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket5['igst'] ?? 0)) ?></td></tr>
            <tr><td>IGST @ 18%</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($bucket18['igst'] ?? 0)) ?></td></tr>
        <?php endif; ?>
        <tr><td>Round Off</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($pricing['round_off'] ?? 0)) ?></td></tr>
        <tr class="final"><td>Total</td><td style="text-align:right;">₹ <?= quotation_fmt_money((float) ($pricing['grand_total'] ?? 0)) ?></td></tr>
    </table>
</div>

<div class="page annexure-page">
    <h3>Annexure</h3>
    <div class="block">
        <strong>Payment Milestones</strong>
        <?php if ($milestones !== []): ?>
            <ul>
                <?php foreach ($milestones as $line): ?>
                    <li><?= quotation_escape((string) $line) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p><?= quotation_escape((string) ($blocks['payment_terms'] ?? 'Milestones shall be finalized in confirmed order stage.')) ?></p>
        <?php endif; ?>
    </div>
    <div class="block"><strong>System Inclusions</strong><p><?= nl2br(quotation_escape((string) ($blocks['inclusions'] ?? ''))) ?></p></div>
    <div class="block"><strong>Warranty</strong><p><?= nl2br(quotation_escape((string) ($blocks['warranty'] ?? ''))) ?></p></div>
    <div class="block"><strong>Transportation</strong><p><?= nl2br(quotation_escape((string) ($blocks['transportation_charges_block'] ?? ''))) ?></p></div>
    <div class="block"><strong>Feasibility</strong><p><?= nl2br(quotation_escape((string) ($blocks['terms_conditions'] ?? 'Execution is subject to technical feasibility and approvals.'))) ?></p></div>
    <?php if (trim((string) ($blocks['subsidy_info_block'] ?? '')) !== ''): ?>
        <div class="block"><strong>Subsidy Rules</strong><p><?= nl2br(quotation_escape((string) ($blocks['subsidy_info_block'] ?? ''))) ?></p></div>
    <?php endif; ?>
</div>
</body>
</html>
<?php
    return (string) ob_get_clean();
}
