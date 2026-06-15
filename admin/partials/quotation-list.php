<?php
declare(strict_types=1);
?>
<div id="quotationList" class="card workspace-panel <?= in_array($tab, ['quotations', 'archived'], true) ? 'active' : '' ?>"><div class="list-toolbar"><div><h2><?= $tab === 'archived' ? 'Archived Quotations' : 'Quotations' ?></h2><p class="muted"><?= count($allQuotes) ?> quotation<?= count($allQuotes) === 1 ? '' : 's' ?> shown</p></div><form method="get" style="display:flex;gap:8px;align-items:end"><input type="hidden" name="tab" value="<?= htmlspecialchars($tab, ENT_QUOTES) ?>"><div><label>Status Filter</label><select name="status_filter"><option value="">All</option><option value="needs_approval" <?= $statusFilter==='needs_approval'?'selected':'' ?>>Needs Approval</option><option value="Approved" <?= $statusFilter==='Approved'?'selected':'' ?>>Approved</option><option value="Accepted" <?= $statusFilter==='Accepted'?'selected':'' ?>>Accepted</option></select></div><button class="btn secondary" type="submit">Apply</button></form></div>
<div class="list-table-wrap"><table class="sticky-head"><thead><tr><th>Quotation</th><th>Customer</th><th>Status</th><th class="quote-amount">Amount</th><th>Updated</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($allQuotes as $q):
$q = is_array($q) ? $q : [];
$quoteId = safe_text((string) ($q['id'] ?? ''));
$quoteNo = safe_text((string) ($q['quote_no'] ?? $quoteId));
$createdByName = safe_text((string) ($q['created_by_name'] ?? ''));
$customerName = safe_text((string) ($q['customer_name'] ?? ''));
$customerMobile = safe_text((string) ($q['customer_mobile'] ?? ''));
$updatedAt = safe_text((string) ($q['updated_at'] ?? ''));
$calc = is_array($q['calc'] ?? null) ? $q['calc'] : [];
$amount = (float) ($calc['grand_total'] ?? $q['input_total_gst_inclusive'] ?? 0);
?>
<tr data-quote-id="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>">
<td><strong><?= htmlspecialchars($quoteNo, ENT_QUOTES) ?></strong><div class="quote-meta">By <?= htmlspecialchars($createdByName, ENT_QUOTES) ?></div></td>
<td><div class="quote-customer"><?= htmlspecialchars($customerName, ENT_QUOTES) ?></div><div class="quote-meta"><?= htmlspecialchars($customerMobile, ENT_QUOTES) ?></div></td>
<td><span class="status-pill"><?= htmlspecialchars(documents_status_label($q, 'admin'), ENT_QUOTES) ?></span></td>
<td class="quote-amount">₹<?= number_format($amount, 2) ?></td>
<td><span title="<?= htmlspecialchars($updatedAt, ENT_QUOTES) ?>"><?= htmlspecialchars(substr($updatedAt, 0, 10), ENT_QUOTES) ?></span></td>
<td>
<?php
$publicShareToken = safe_text((string) ($q['public_share_token'] ?? ''));
$publicShareEnabled = !empty($q['public_share_enabled']) && $publicShareToken !== '';
$publicShareUrl = $quotationPublicShareUrl($q);
$quoteShareMobile = $quotationExtractMobile($q);
$canWhatsappShare = $quoteShareMobile !== '';
$quoteStatusNorm = documents_quote_normalize_status((string) ($q['status'] ?? 'draft'));
$quoteArchived = documents_is_archived($q);
$quoteLocked = documents_quote_is_locked($q);
$isQuotationAdmin = (string) (current_user()['role_name'] ?? '') === 'admin';
$canApproveQuote = $isQuotationAdmin && !$quoteArchived && !$quoteLocked && in_array($quoteStatusNorm, ['draft', 'pending_admin_approval'], true);
$canAcceptQuote = $isQuotationAdmin && !$quoteArchived && !$quoteLocked && $quoteStatusNorm === 'approved';
$changeRequest = is_array($q['customer_change_request'] ?? null) ? $q['customer_change_request'] : [];
?>
<div class="list-actions">
<?php if ($changeRequest !== []): ?><div class="quote-meta"><strong>Change request <?= htmlspecialchars((string)($changeRequest['request_ref'] ?? ''), ENT_QUOTES) ?></strong><br><?= nl2br(htmlspecialchars((string)($changeRequest['requested_changes'] ?? ''), ENT_QUOTES)) ?><br>Requested <?= htmlspecialchars((string)($changeRequest['requested_at'] ?? ''), ENT_QUOTES) ?><br><a href="admin-quotations.php?tab=editor&amp;edit=<?= urlencode((string)($changeRequest['generated_draft_revision_id'] ?? '')) ?>">Edit Revised Quotation <?= htmlspecialchars((string)($changeRequest['generated_draft_revision_no'] ?? $changeRequest['generated_draft_revision_id'] ?? ''), ENT_QUOTES) ?></a></div><?php endif; ?>
<a class="btn" href="quotation-view.php?id=<?= urlencode($quoteId) ?>">Open</a>
<?php if (documents_quote_can_edit($q, 'admin')): ?><a class="btn secondary" href="admin-quotations.php?tab=editor&amp;edit=<?= urlencode($quoteId) ?>">Edit</a><?php endif; ?>
<button class="btn secondary js-wa-share" type="button" data-quote-id="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>" data-customer-mobile="<?= htmlspecialchars($quoteShareMobile, ENT_QUOTES) ?>" data-customer-name="<?= htmlspecialchars($customerName, ENT_QUOTES) ?>" <?= $canWhatsappShare ? '' : 'disabled title="Missing valid mobile"' ?>>Share</button>
<details class="more-actions"><summary class="btn quiet">More ▾</summary><div class="more-menu"><div class="secondary-actions">
<?php if ($canApproveQuote): ?><form method="post" class="js-quote-action" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="approve_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><button class="btn" type="submit">Approve</button></form><?php endif; ?>
<?php if ($canAcceptQuote): ?><form method="post" class="js-quote-action" style="margin:0" data-confirm="Accept and lock this quotation?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="accept_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><button class="btn" type="submit" onclick="return confirm('Accept and lock this quotation?');">Accept</button></form><?php endif; ?>
<?php if (($quoteStatusNorm === 'accepted' || $quoteLocked) && !$quoteArchived): ?><span class="muted">Accepted / locked</span><?php endif; ?>
<a class="btn secondary js-open-new-tab" href="quotation-view.php?id=<?= urlencode($quoteId) ?>" target="_blank" rel="noopener">Print HTML</a>
<form method="post" class="js-quote-action" style="margin:0" data-confirm="Clone this quotation into a new draft?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="clone_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><button class="btn secondary" type="submit" onclick="return confirm('Clone this quotation into a new draft?');">Clone</button></form>
<?php if (!$quoteArchived): ?><form method="post" class="js-quote-action" style="margin:0" data-confirm="Archive this quotation?"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="archive_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><button class="btn secondary" type="submit" onclick="return confirm('Archive this quotation?');">Archive</button></form><?php else: ?><form method="post" class="js-quote-action" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="unarchive_quote"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><button class="btn secondary" type="submit">Unarchive</button></form><?php endif; ?>
</div><div class="share-actions"><strong>Public link</strong><form method="post" class="js-quote-action" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:6px;align-items:center"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="toggle_public_share"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><?php if (!$publicShareEnabled): ?><input type="hidden" name="share_mode" value="enable"><button class="btn secondary" type="submit">Enable Public Link</button><?php else: ?><input id="public-share-link-<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>" type="text" readonly value="<?= htmlspecialchars($publicShareUrl, ENT_QUOTES) ?>" style="flex:1;min-width:220px"><button class="btn secondary" type="button" data-copy-target="public-share-link-<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>">Copy Link</button><button class="btn secondary" type="submit" name="share_mode" value="disable">Disable</button><?php endif; ?></form></div>
<?php if (documents_quote_is_locked($q)): ?><details style="margin-top:8px"><summary class="muted" style="cursor:pointer">Create revision</summary><form method="post" style="margin-top:6px"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="create_revision"><input type="hidden" name="quote_id" value="<?= htmlspecialchars($quoteId, ENT_QUOTES) ?>"><label>Revision reason (optional)</label><textarea name="revision_reason" placeholder="Reason for revision"></textarea><?php if (documents_quote_has_workflow_documents($q)): ?><p class="muted" style="margin:6px 0">Documents already exist for this accepted quotation. New documents must be created under the revision.</p><label><input type="checkbox" name="archive_existing_documents" value="1"> Archive existing PI/Invoice</label><?php endif; ?><div style="margin-top:6px"><button class="btn" type="submit" onclick="return confirm('Create a new revision from this accepted quotation?');">Create Revision</button></div></form></details><?php endif; ?>
</div></details>
</div></td></tr>
<?php endforeach; if ($allQuotes===[]): ?><tr><td colspan="6">No quotations found.</td></tr><?php endif; ?></tbody></table></div>
</div>
