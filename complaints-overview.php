<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/employee_portal.php';
require_once __DIR__ . '/includes/customer_complaints.php';
require_once __DIR__ . '/includes/customer_admin.php';

$employeeStore = new EmployeeFsStore();
$customerStore = new CustomerFsStore();

$viewerType = 'employee';
$viewerName = 'Employee';

$admin = current_user();
if (($admin['role_name'] ?? '') === 'admin') {
    require_admin();
    $viewerType = 'admin';
    $viewerName = trim((string) ($admin['full_name'] ?? 'Administrator'));
} else {
    employee_portal_require_login();
    $employee = employee_portal_current_employee($employeeStore);
    if ($employee === null) {
        header('Location: login.php?login_type=employee');
        exit;
    }
    $viewerName = trim((string) ($employee['name'] ?? ($employee['login_id'] ?? 'Employee')));
}

$statusFilterRaw = $_GET['status'] ?? null;
$statusFilter = strtolower(trim((string) ($statusFilterRaw ?? 'all')));
$assigneeFilter = trim((string) ($_GET['assignee'] ?? 'all'));
$categoryFilter = trim((string) ($_GET['category'] ?? 'all'));
$statusOptions = ['all' => 'All statuses', 'open' => 'Open', 'intake' => 'Intake', 'triage' => 'Admin triage', 'work' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];

$complaints = load_all_complaints();
$customers = $customerStore->listCustomers();

$customerByMobile = [];
foreach ($customers as $cust) {
    $mobile = $cust['mobile'] ?? '';
    if ($mobile !== '') {
        $customerByMobile[$mobile] = $cust;
    }
}

usort($complaints, static function (array $left, array $right): int {
    $leftTime = (string) ($left['created_at'] ?? '');
    $rightTime = (string) ($right['created_at'] ?? '');
    return strcmp($rightTime, $leftTime);
});

$assigneeOptions = array_unique(array_merge(['Unassigned'], array_map(static fn ($item) => complaint_display_assignee($item['assignee'] ?? ''), $complaints), complaint_assignee_options()));
$categoryOptions = array_unique(array_merge(['All'], complaint_problem_categories()));
$divisionOptions = [];
$subDivisionOptions = [];
foreach ($customers as $customer) {
    $divisionName = trim((string) ($customer['division_name'] ?? ''));
    if ($divisionName !== '') {
        $divisionOptions[$divisionName] = true;
    }
    $subDivisionName = trim((string) ($customer['sub_division_name'] ?? ''));
    if ($subDivisionName !== '') {
        $subDivisionOptions[$subDivisionName] = true;
    }
}
$divisionOptions = array_keys($divisionOptions);
$subDivisionOptions = array_keys($subDivisionOptions);
sort($divisionOptions, SORT_NATURAL | SORT_FLAG_CASE);
sort($subDivisionOptions, SORT_NATURAL | SORT_FLAG_CASE);

$noStatusFilterApplied = $statusFilterRaw === null || $statusFilterRaw === '';

$filtered = array_filter($complaints, static function (array $complaint) use ($statusFilter, $assigneeFilter, $categoryFilter, $noStatusFilterApplied): bool {
    $statusRaw = (string) ($complaint['status'] ?? 'open');
    $status = strtolower($statusRaw);
    $assignee = complaint_display_assignee($complaint['assignee'] ?? '');
    $category = (string) ($complaint['problem_category'] ?? '');
    $isClosed = strtolower(trim($statusRaw)) === 'closed';

    if ($noStatusFilterApplied && $isClosed) {
        return false;
    }

    $statusMatches = $statusFilter === 'all' || $status === $statusFilter;
    $assigneeMatches = $assigneeFilter === 'all' || strcasecmp($assignee, $assigneeFilter) === 0;
    $categoryMatches = $categoryFilter === 'all' || strcasecmp($category, $categoryFilter) === 0;

    return $statusMatches && $assigneeMatches && $categoryMatches;
});

$counts = complaint_summary_counts($complaints);

function complaints_overview_value_or_dash(?string $value): string
{
    $trimmed = trim((string) $value);
    return $trimmed === '' ? '—' : $trimmed;
}

function complaints_overview_normalize_selection(mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }

    $selected = [];
    foreach ($raw as $item) {
        $value = trim((string) $item);
        if ($value === '') {
            continue;
        }
        $selected[$value] = true;
    }

    return array_keys($selected);
}

function complaints_overview_excel_col_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}

function complaints_overview_build_xlsx(array $rows, int $freezeHeaderRow, array $rowStyles = [], string $sheetName = 'Complaints'): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive extension is required to create Excel files.');
    }

    $maxColumns = 0;
    foreach ($rows as $row) {
        $maxColumns = max($maxColumns, count($row));
    }
    $maxColumns = max(1, $maxColumns);

    $columnWidths = array_fill(0, $maxColumns, 12);
    foreach ($rows as $row) {
        foreach ($row as $columnIndex => $cell) {
            $length = function_exists('mb_strlen') ? mb_strlen((string) $cell, 'UTF-8') : strlen((string) $cell);
            $columnWidths[$columnIndex] = min(60, max($columnWidths[$columnIndex], $length + 2));
        }
    }

    $xmlRows = [];
    foreach ($rows as $rowIndex => $row) {
        $sheetRowNumber = $rowIndex + 1;
        $rowStyle = (string) ($rowStyles[$sheetRowNumber] ?? '');
        $cells = '';
        foreach ($row as $columnIndex => $cellValue) {
            $columnName = complaints_overview_excel_col_name($columnIndex + 1);
            $styleId = 0;
            if ($sheetRowNumber === 1) {
                $styleId = 1;
            } elseif ($sheetRowNumber === 2) {
                $styleId = 2;
            } elseif ($sheetRowNumber === $freezeHeaderRow) {
                $styleId = 3;
            } elseif ($columnIndex === ($maxColumns - 1) && $sheetRowNumber > $freezeHeaderRow) {
                $styleId = 4;
            }
            if ($sheetRowNumber > $freezeHeaderRow && $columnIndex === ($maxColumns - 1) && $rowStyle === 'highlight') {
                $styleId = 6;
            } elseif ($sheetRowNumber > $freezeHeaderRow && $rowStyle === 'highlight') {
                $styleId = 5;
            }

            $escapedValue = htmlspecialchars((string) $cellValue, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
            $cells .= '<c r="' . $columnName . $sheetRowNumber . '" t="inlineStr" s="' . $styleId . '"><is><t>' . $escapedValue . '</t></is></c>';
        }
        $xmlRows[] = '<row r="' . $sheetRowNumber . '">' . $cells . '</row>';
    }

    $colsXml = '';
    foreach ($columnWidths as $index => $width) {
        $colId = $index + 1;
        $colsXml .= '<col min="' . $colId . '" max="' . $colId . '" width="' . number_format((float) $width, 2, '.', '') . '" customWidth="1"/>';
    }

    $sheetNameEscaped = htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    $freezeXml = '';
    if ($freezeHeaderRow >= 2) {
        $freezeXml = '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . ($freezeHeaderRow - 1) . '" topLeftCell="A' . $freezeHeaderRow . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
    }

    $worksheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . $freezeXml
        . '<cols>' . $colsXml . '</cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '</worksheet>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . $sheetNameEscaped . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $highlightColor = strtoupper(trim((string) ($rowStyles['_highlight_color'] ?? 'FFFFF3B0')));
    if ($highlightColor === '' || !preg_match('/^[A-F0-9]{8}$/', $highlightColor)) {
        $highlightColor = 'FFFFF3B0';
    }

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="16"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF1FF"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="' . $highlightColor . '"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
        . '</cellXfs>'
        . '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $timestampIso = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:creator>Dakshayani Enterprises</dc:creator><cp:lastModifiedBy>Dakshayani Enterprises</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestampIso . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestampIso . '</dcterms:modified>'
        . '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>PHP</Application>'
        . '</Properties>';

    $tmpPath = tempnam(sys_get_temp_dir(), 'cmp_xlsx_');
    if ($tmpPath === false) {
        throw new RuntimeException('Unable to create temporary file for Excel export.');
    }
    $xlsxPath = $tmpPath . '.xlsx';
    @unlink($xlsxPath);
    rename($tmpPath, $xlsxPath);

    $zip = new ZipArchive();
    if ($zip->open($xlsxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to initialize Excel archive.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->close();

    return $xlsxPath;
}

function complaints_overview_safe(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function complaints_overview_render_summary(array $counts): string
{
    ob_start(); ?>
    <div class="complaints-summary" id="complaintsSummary">
      <div class="summary-card"><h3>Total complaints</h3><p><?= number_format((int) $counts['total']) ?></p></div>
      <div class="summary-card"><h3>Open complaints</h3><p><?= number_format((int) $counts['open']) ?></p></div>
      <div class="summary-card"><h3>Unassigned complaints</h3><p><?= number_format((int) $counts['unassigned']) ?></p></div>
    </div>
    <?php return (string) ob_get_clean();
}

function complaints_overview_render_table(array $filtered, array $customerByMobile): string
{
    ob_start();
    if (count($filtered) === 0): ?>
      <div class="empty-state">No complaints match your filters.</div>
    <?php else: ?>
      <div class="complaints-table-wrap">
      <table class="complaints-table admin-table">
        <thead><tr><th>ID</th><th>Customer Name</th><th>Customer mobile</th><th>Division</th><th>Sub Division</th><th>Title</th><th>Category</th><th>Assignee</th><th>Status</th><th>Forwarded</th><th>Created</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($filtered as $complaint):
            $created = complaints_overview_safe((string) ($complaint['created_at'] ?? ''));
            $title = trim((string) ($complaint['title'] ?? 'Complaint'));
            $mobile = (string) ($complaint['customer_mobile'] ?? '');
            $customer = $customerByMobile[$mobile] ?? [];
            $customerName = (string) ($customer['name'] ?? '');
            $divisionName = complaints_overview_value_or_dash((string) ($customer['division_name'] ?? ''));
            $subDivisionName = complaints_overview_value_or_dash((string) ($customer['sub_division_name'] ?? ''));
            $forwardedVia = complaint_normalize_forwarded_via($complaint['forwarded_via'] ?? 'none');
            $forwardedLabel = match ($forwardedVia) {
                'whatsapp' => 'WhatsApp',
                'email' => 'Email',
                'both' => 'Both',
                default => 'No',
            };
            $statusRaw = (string) ($complaint['status'] ?? 'open');
            $status = strtolower(trim($statusRaw));
            $statusLabel = ucfirst($statusRaw);
            $rowClass = '';
            $ageLabel = 'Fresh';
            if (!in_array($status, ['closed', 'resolved'], true)) {
                $createdAt = $complaint['created_at'] ?? null;
                $days = 0;
                if ($createdAt) {
                    $createdTs = strtotime((string) $createdAt);
                    if ($createdTs !== false) {
                        $days = (int) floor((time() - $createdTs) / 86400);
                    }
                }
                $rowClass = $days <= 1 ? 'complaint-age-0-1' : ($days <= 3 ? 'complaint-age-2-3' : ($days <= 7 ? 'complaint-age-4-7' : ($days <= 14 ? 'complaint-age-8-14' : 'complaint-age-15plus')));
                $ageLabel = $days <= 1 ? 'Fresh' : ($days <= 3 ? 'Needs follow-up' : ($days <= 7 ? 'Attention' : ($days <= 14 ? 'Urgent' : 'Critical')));
            }
            ?>
          <tr class="<?= complaints_overview_safe($rowClass) ?>">
            <td><?= complaints_overview_safe((string) ($complaint['id'] ?? '—')) ?></td>
            <td><?= complaints_overview_safe($customerName) ?></td>
            <td><?= complaints_overview_safe((string) ($complaint['customer_mobile'] ?? 'Unknown')) ?></td>
            <td><?= complaints_overview_safe($divisionName) ?></td>
            <td><?= complaints_overview_safe($subDivisionName) ?></td>
            <td><?= complaints_overview_safe($title !== '' ? $title : 'Complaint') ?></td>
            <td><?= complaints_overview_safe((string) ($complaint['problem_category'] ?? '')) ?></td>
            <td><?= complaints_overview_safe(complaint_display_assignee($complaint['assignee'] ?? '')) ?></td>
            <td><span class="status-pill <?= complaints_overview_safe($status) ?>"><?= complaints_overview_safe($statusLabel) ?></span></td>
            <td>
              <span class="admin-chip admin-chip--muted"><?= complaints_overview_safe($forwardedLabel) ?></span>
              <?php if (!in_array($status, ['closed', 'resolved'], true)): ?>
                <span class="urgency-pill admin-chip"><?= complaints_overview_safe($ageLabel) ?></span>
              <?php endif; ?>
            </td>
            <td><?= $created ?></td>
            <td><a href="complaint-detail.php?id=<?= complaints_overview_safe((string) ($complaint['id'] ?? '')) ?>" class="js-complaint-open" data-complaint-id="<?= complaints_overview_safe((string) ($complaint['id'] ?? '')) ?>">View / Edit</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif;

    return (string) ob_get_clean();
}

if (($_GET['ajax'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'summary_html' => complaints_overview_render_summary($counts),
        'table_html' => complaints_overview_render_table($filtered, $customerByMobile),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$exportError = '';
$exportAssignees = complaints_overview_normalize_selection($_POST['export_assignees'] ?? []);
$exportCategories = complaints_overview_normalize_selection($_POST['export_categories'] ?? []);
$exportStatuses = complaints_overview_normalize_selection($_POST['export_statuses'] ?? []);
$exportHighlightDivisions = complaints_overview_normalize_selection($_POST['highlight_divisions'] ?? []);
$exportHighlightSubDivisions = complaints_overview_normalize_selection($_POST['highlight_sub_divisions'] ?? []);
$highlightColorOptions = [
    'none' => ['label' => 'No Highlight', 'argb' => ''],
    'yellow' => ['label' => 'Yellow', 'argb' => 'FFFFF3B0'],
    'light_green' => ['label' => 'Light Green', 'argb' => 'FFE9FCD8'],
    'light_blue' => ['label' => 'Light Blue', 'argb' => 'FFDDEBFF'],
    'pink' => ['label' => 'Pink', 'argb' => 'FFFFE0EC'],
];
$exportHighlightColor = strtolower(trim((string) ($_POST['highlight_color'] ?? 'yellow')));
if (!isset($highlightColorOptions[$exportHighlightColor])) {
    $exportHighlightColor = 'yellow';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'export_excel') {
    $validAssigneeMap = array_fill_keys($assigneeOptions, true);
    $validCategoryMap = array_fill_keys(complaint_problem_categories(), true);
    $validStatusMap = array_fill_keys(array_keys(array_diff_key($statusOptions, ['all' => 'All statuses'])), true);
    $validDivisionMap = array_fill_keys($divisionOptions, true);
    $validSubDivisionMap = array_fill_keys($subDivisionOptions, true);

    $exportAssignees = array_values(array_filter($exportAssignees, static fn (string $item): bool => isset($validAssigneeMap[$item])));
    $exportCategories = array_values(array_filter($exportCategories, static fn (string $item): bool => isset($validCategoryMap[$item])));
    $exportStatuses = array_values(array_filter($exportStatuses, static fn (string $item): bool => isset($validStatusMap[$item])));
    $exportHighlightDivisions = array_values(array_filter($exportHighlightDivisions, static fn (string $item): bool => isset($validDivisionMap[$item])));
    $exportHighlightSubDivisions = array_values(array_filter($exportHighlightSubDivisions, static fn (string $item): bool => isset($validSubDivisionMap[$item])));

    if ($exportAssignees === [] && $exportCategories === [] && $exportStatuses === []) {
        $exportError = 'Please select at least one filter to create the sheet.';
    } else {
        $matchingComplaints = array_values(array_filter($complaints, static function (array $complaint) use ($exportAssignees, $exportCategories, $exportStatuses): bool {
            $assignee = complaint_display_assignee($complaint['assignee'] ?? '');
            $category = (string) ($complaint['problem_category'] ?? '');
            $status = strtolower(trim((string) ($complaint['status'] ?? 'open')));

            $assigneeMatches = $exportAssignees === [] || in_array($assignee, $exportAssignees, true);
            $categoryMatches = $exportCategories === [] || in_array($category, $exportCategories, true);
            $statusMatches = $exportStatuses === [] || in_array($status, $exportStatuses, true);

            return $assigneeMatches && $categoryMatches && $statusMatches;
        }));

        if ($matchingComplaints === []) {
            $exportError = 'No matching complaints found for selected filters.';
        } else {
            $highlightEnabled = $exportHighlightColor !== 'none' && ($exportHighlightDivisions !== [] || $exportHighlightSubDivisions !== []);
            $rows = [];
            $rows[] = ['Dakshayani Enterprises'];
            $rows[] = ['Filters Used'];
            $rows[] = ['Assignees', $exportAssignees === [] ? 'Not filtered' : implode(', ', $exportAssignees)];
            $rows[] = ['Categories', $exportCategories === [] ? 'Not filtered' : implode(', ', $exportCategories)];
            $rows[] = ['Status', $exportStatuses === [] ? 'Not filtered' : implode(', ', array_map(static fn (string $status): string => $statusOptions[$status] ?? ucfirst($status), $exportStatuses))];
            $rows[] = ['Highlight Used', $highlightEnabled ? 'Configured' : 'None'];
            if ($highlightEnabled) {
                $rows[] = ['Division Highlight', $exportHighlightDivisions === [] ? 'None' : implode(', ', $exportHighlightDivisions)];
                $rows[] = ['Sub Division Highlight', $exportHighlightSubDivisions === [] ? 'None' : implode(', ', $exportHighlightSubDivisions)];
                $rows[] = ['Highlight Color', $highlightColorOptions[$exportHighlightColor]['label']];
            }
            $rows[] = [];
            $tableHeaderRow = count($rows) + 1;
            $rowStyles = [];
            $rows[] = [
                'Name',
                'Mobile Number',
                'Application ID',
                'Division Name',
                'Sub Division Name',
                'JBVNL Account Number',
                'City',
                'Complaint ID',
                'Category',
                'Status',
                'Assignee',
                'Complaint Date / Created At',
                'Remarks / Description',
            ];

            foreach ($matchingComplaints as $complaint) {
                $mobile = (string) ($complaint['customer_mobile'] ?? '');
                $customer = $customerByMobile[$mobile] ?? [];
                $divisionName = trim((string) ($customer['division_name'] ?? ''));
                $subDivisionName = trim((string) ($customer['sub_division_name'] ?? ''));
                $rows[] = [
                    complaints_overview_value_or_dash((string) ($customer['name'] ?? '')),
                    complaints_overview_value_or_dash($mobile),
                    complaints_overview_value_or_dash((string) ($customer['application_id'] ?? '')),
                    complaints_overview_value_or_dash($divisionName),
                    complaints_overview_value_or_dash($subDivisionName),
                    complaints_overview_value_or_dash((string) ($customer['jbvnl_account_number'] ?? '')),
                    complaints_overview_value_or_dash((string) ($customer['city'] ?? '')),
                    complaints_overview_value_or_dash((string) ($complaint['id'] ?? '')),
                    complaints_overview_value_or_dash((string) ($complaint['problem_category'] ?? '')),
                    complaints_overview_value_or_dash(ucfirst((string) ($complaint['status'] ?? 'open'))),
                    complaints_overview_value_or_dash(complaint_display_assignee($complaint['assignee'] ?? '')),
                    complaints_overview_value_or_dash((string) ($complaint['created_at'] ?? '')),
                    complaints_overview_value_or_dash((string) ($complaint['description'] ?? '')),
                ];

                if ($highlightEnabled) {
                    $divisionMatches = $exportHighlightDivisions !== [] && in_array($divisionName, $exportHighlightDivisions, true);
                    $subDivisionMatches = $exportHighlightSubDivisions !== [] && in_array($subDivisionName, $exportHighlightSubDivisions, true);
                    if ($divisionMatches || $subDivisionMatches) {
                        $rowStyles[count($rows)] = 'highlight';
                    }
                }
            }
            $rowStyles['_highlight_color'] = $highlightColorOptions[$exportHighlightColor]['argb'] ?? '';

            try {
                $xlsxPath = complaints_overview_build_xlsx($rows, $tableHeaderRow, $rowStyles);
                $filename = 'complaints_export_' . gmdate('Ymd_His') . '.xlsx';

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . (string) filesize($xlsxPath));
                header('Cache-Control: max-age=0');
                readfile($xlsxPath);
                @unlink($xlsxPath);
                exit;
            } catch (Throwable $exception) {
                $exportError = 'Unable to create Excel sheet right now. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Customer Complaints | Dakshayani Enterprises</title>
  <link rel="stylesheet" href="style.css" />
  <link rel="stylesheet" href="assets/css/admin-unified.css" />
  <style>
    .complaints-page .admin-page.complaints-shell {
      width:100%;
      max-width:none !important;
      margin:1.5rem auto;
      padding:0 1.5rem 1.25rem;
      box-sizing:border-box;
    }
    .complaints-header { display:flex; justify-content:space-between; align-items:center; gap:1rem; flex-wrap:wrap; margin-bottom:1rem; }
    .complaints-title { margin:0; font-size:2rem; color:#111827; }
    .complaints-subtitle { margin:.35rem 0 0; color:#4b5563; }
    .complaints-filters { position:sticky; top:0; z-index:5; background:#f8fafc; padding:.8rem; border:1px solid #e5e7eb; border-radius:12px; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.75rem; margin:1rem 0; }
    .complaints-filters select,.complaints-filters button { width:100%; padding:.6rem .7rem; border:1px solid #d1d5db; border-radius:10px; font:inherit; }
    .complaints-filters button { background:#1f4b99; color:#fff; font-weight:700; cursor:pointer; border-color:#1f4b99; }
    .complaints-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:.75rem; margin:1rem 0; }
    .summary-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 10px 24px rgba(0,0,0,.04); }
    .summary-card h3 { margin:0; font-size:.95rem; color:#4b5563; font-weight:600; }
    .summary-card p { margin:.35rem 0 0; font-size:1.4rem; font-weight:700; color:#111827; }
    .complaints-table-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:auto; max-height:65vh; }
    .complaints-table { width:100%; border-collapse:collapse; background:#fff; table-layout:auto; }
    .complaints-table th,.complaints-table td { border:1px solid #e5e7eb; padding:.75rem .8rem; text-align:left; font-size:.95rem; }
    .complaints-table th { background:#f9fafb; font-weight:700; color:#111827; position:sticky; top:0; z-index:2; }
    .complaints-table td a { color:#1f4b99; text-decoration:none; font-weight:700; }
    .complaints-table th:nth-child(2),.complaints-table td:nth-child(2) { min-width:180px; }
    .complaints-table th:nth-child(4),.complaints-table td:nth-child(4) { min-width:150px; }
    .complaints-table th:nth-child(5),.complaints-table td:nth-child(5) { min-width:170px; }
    .complaints-table th:nth-child(6),.complaints-table td:nth-child(6) { min-width:240px; }
    .complaints-table th:nth-child(3),
    .complaints-table th:nth-child(9),
    .complaints-table th:nth-child(10),
    .complaints-table th:nth-child(11),
    .complaints-table th:nth-child(12),
    .complaints-table td:nth-child(3),
    .complaints-table td:nth-child(9),
    .complaints-table td:nth-child(10),
    .complaints-table td:nth-child(11),
    .complaints-table td:nth-child(12) { white-space:nowrap; }
    .empty-state { margin:1rem 0 0; padding:1rem 1.25rem; border-radius:12px; border:1px dashed #cbd5e1; background:#f8fafc; color:#475569; }
    .sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
    .complaint-age-0-1 { background-color:#f0fff4; } .complaint-age-2-3 { background-color:#fffbea; } .complaint-age-4-7 { background-color:#fff4e6; } .complaint-age-8-14 { background-color:#ffe5e5; } .complaint-age-15plus { background-color:#ffd6d6; }
    .ux-backdrop { position:fixed; inset:0; background:rgba(15,23,42,.42); display:none; z-index:80; }
    .ux-drawer { position:fixed; top:0; right:0; width:min(960px,92vw); height:100vh; background:#fff; transform:translateX(100%); transition:transform .22s ease; z-index:81; box-shadow:-10px 0 28px rgba(0,0,0,.16); display:flex; flex-direction:column; }
    .ux-drawer.open { transform:translateX(0); }
    .ux-backdrop.open { display:block; }
    .ux-drawer-head { display:flex; justify-content:space-between; align-items:center; padding:.9rem 1rem; border-bottom:1px solid #e5e7eb; }
    .ux-drawer iframe { width:100%; height:100%; border:none; }
    .loading { opacity:.6; pointer-events:none; }
    .complaints-export { margin:1rem 0; border:1px solid #dbe7ff; border-radius:12px; padding:1rem; background:#f8fbff; }
    .complaints-export h2 { margin:0 0 .65rem; font-size:1.1rem; color:#1f4b99; }
    .complaints-export-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; }
    .complaints-export select,.complaints-export button { width:100%; padding:.6rem .7rem; border:1px solid #d1d5db; border-radius:10px; font:inherit; }
    .complaints-export select { min-height:120px; background:#fff; }
    .complaints-export select.single-select { min-height:auto; }
    .complaints-export button { max-width:220px; background:#1f4b99; color:#fff; border-color:#1f4b99; font-weight:700; cursor:pointer; margin-top:.75rem; }
    .complaints-export-note { margin:.35rem 0 0; font-size:.88rem; color:#475569; }
    .complaints-export-error { margin:.65rem 0 0; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:8px; padding:.6rem .8rem; }
    @media (max-width: 980px) {
      .complaints-page .admin-page.complaints-shell { padding:0 .95rem 1rem; }
    }
    @media (max-width: 720px) {
      .complaints-page .admin-page.complaints-shell { padding:0 .75rem 1rem; margin:1rem auto; }
    }
  </style>
</head>
<body class="admin-shell complaints-page">
  <div class="complaints-shell admin-page" id="complaintsApp">
    <div class="complaints-header">
      <div>
        <h1 class="complaints-title">Customer Complaints</h1>
        <p class="complaints-subtitle">Signed in as <?= complaints_overview_safe($viewerName) ?> (<?= complaints_overview_safe(ucfirst($viewerType)) ?>)</p>
      </div>
      <div><a href="<?= complaints_overview_safe($viewerType === 'admin' ? 'admin-dashboard.php' : 'employee-dashboard.php') ?>" class="btn btn-ghost">Back to dashboard</a></div>
    </div>

    <div id="complaintsSummaryRoot"><?= complaints_overview_render_summary($counts) ?></div>

    <form method="get" class="complaints-filters" id="complaintFilters">
      <div>
        <label class="sr-only" for="status">Status</label>
        <select id="status" name="status">
          <?php foreach ($statusOptions as $value => $label): ?><option value="<?= complaints_overview_safe($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= complaints_overview_safe($label) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="sr-only" for="assignee">Assignee</label>
        <select id="assignee" name="assignee"><option value="all" <?= $assigneeFilter === 'all' ? 'selected' : '' ?>>All assignees</option><?php foreach ($assigneeOptions as $assignee): $value = complaints_overview_safe($assignee); ?><option value="<?= $value ?>" <?= strcasecmp($assigneeFilter, $assignee) === 0 ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select>
      </div>
      <div>
        <label class="sr-only" for="category">Category</label>
        <select id="category" name="category"><option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>All categories</option><?php foreach (complaint_problem_categories() as $category): ?><option value="<?= complaints_overview_safe($category) ?>" <?= strcasecmp($categoryFilter, $category) === 0 ? 'selected' : '' ?>><?= complaints_overview_safe($category) ?></option><?php endforeach; ?></select>
      </div>
      <div><button type="submit">Apply filters</button></div>
    </form>

    <section class="complaints-export complaints-export--secondary">
      <h2>Export Excel</h2>
      <form method="post" target="_blank" rel="noopener">
        <input type="hidden" name="action" value="export_excel" />
        <div class="complaints-export-grid">
          <div>
            <label for="export_assignees">Assignees</label>
            <select id="export_assignees" name="export_assignees[]" multiple>
              <?php foreach ($assigneeOptions as $assignee): ?>
                <option value="<?= complaints_overview_safe($assignee) ?>" <?= in_array($assignee, $exportAssignees, true) ? 'selected' : '' ?>><?= complaints_overview_safe($assignee) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="export_categories">Categories</label>
            <select id="export_categories" name="export_categories[]" multiple>
              <?php foreach (complaint_problem_categories() as $category): ?>
                <option value="<?= complaints_overview_safe($category) ?>" <?= in_array($category, $exportCategories, true) ? 'selected' : '' ?>><?= complaints_overview_safe($category) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="export_statuses">Status</label>
            <select id="export_statuses" name="export_statuses[]" multiple>
              <?php foreach ($statusOptions as $value => $label): if ($value === 'all') { continue; } ?>
                <option value="<?= complaints_overview_safe($value) ?>" <?= in_array($value, $exportStatuses, true) ? 'selected' : '' ?>><?= complaints_overview_safe($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="highlight_divisions">Highlight by Division (optional)</label>
            <select id="highlight_divisions" name="highlight_divisions[]" multiple>
              <?php foreach ($divisionOptions as $division): ?>
                <option value="<?= complaints_overview_safe($division) ?>" <?= in_array($division, $exportHighlightDivisions, true) ? 'selected' : '' ?>><?= complaints_overview_safe($division) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="highlight_sub_divisions">Highlight by Sub Division (optional)</label>
            <select id="highlight_sub_divisions" name="highlight_sub_divisions[]" multiple>
              <?php foreach ($subDivisionOptions as $subDivision): ?>
                <option value="<?= complaints_overview_safe($subDivision) ?>" <?= in_array($subDivision, $exportHighlightSubDivisions, true) ? 'selected' : '' ?>><?= complaints_overview_safe($subDivision) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="highlight_color">Highlight color</label>
            <select id="highlight_color" name="highlight_color" class="single-select">
              <?php foreach ($highlightColorOptions as $colorKey => $meta): ?>
                <option value="<?= complaints_overview_safe($colorKey) ?>" <?= $exportHighlightColor === $colorKey ? 'selected' : '' ?>><?= complaints_overview_safe((string) $meta['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <p class="complaints-export-note">Select at least one Assignee, Category, or Status to create the sheet. Highlighting is optional and visual only.</p>
        <button type="submit">Create Excel Sheet</button>
        <?php if ($exportError !== ''): ?>
          <div class="complaints-export-error"><?= complaints_overview_safe($exportError) ?></div>
        <?php endif; ?>
      </form>
    </section>

    <div id="complaintsTableRoot"><?= complaints_overview_render_table($filtered, $customerByMobile) ?></div>
  </div>

  <div class="ux-backdrop" id="complaintsBackdrop"></div>
  <aside class="ux-drawer" id="complaintsDrawer" aria-hidden="true">
    <div class="ux-drawer-head"><strong id="drawerTitle">Complaint details</strong><button type="button" class="btn btn-ghost" id="drawerClose">Close</button></div>
    <iframe id="complaintDetailFrame" src="about:blank" title="Complaint detail"></iframe>
  </aside>

  <script>
    (function () {
      const app = document.getElementById('complaintsApp');
      const form = document.getElementById('complaintFilters');
      const summaryRoot = document.getElementById('complaintsSummaryRoot');
      const tableRoot = document.getElementById('complaintsTableRoot');
      const drawer = document.getElementById('complaintsDrawer');
      const backdrop = document.getElementById('complaintsBackdrop');
      const closeBtn = document.getElementById('drawerClose');
      const frame = document.getElementById('complaintDetailFrame');

      const getState = () => new URLSearchParams(new FormData(form));
      const persist = () => {
        sessionStorage.setItem('complaints-overview:filters', getState().toString());
        sessionStorage.setItem('complaints-overview:scroll', String(window.scrollY || 0));
      };
      const restore = () => {
        const stored = sessionStorage.getItem('complaints-overview:filters');
        if (!stored) return;
        const params = new URLSearchParams(stored);
        ['status', 'assignee', 'category'].forEach((name) => {
          const el = form.querySelector('[name="' + name + '"]');
          if (el && params.has(name)) el.value = params.get(name);
        });
      };

      const refreshData = async () => {
        const params = getState();
        params.set('ajax', '1');
        persist();
        app.classList.add('loading');
        try {
          const response = await fetch('complaints-overview.php?' + params.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
          const payload = await response.json();
          if (!payload || !payload.ok) return;
          summaryRoot.innerHTML = payload.summary_html || '';
          tableRoot.innerHTML = payload.table_html || '';
          history.replaceState({}, '', 'complaints-overview.php?' + getState().toString());
        } catch (err) {
          console.error(err);
        } finally {
          app.classList.remove('loading');
        }
      };

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        refreshData();
      });

      const openDrawer = (url) => {
        persist();
        frame.src = url;
        drawer.classList.add('open');
        backdrop.classList.add('open');
        drawer.setAttribute('aria-hidden', 'false');
      };
      const closeDrawer = () => {
        drawer.classList.remove('open');
        backdrop.classList.remove('open');
        drawer.setAttribute('aria-hidden', 'true');
        frame.src = 'about:blank';
        refreshData();
      };

      document.addEventListener('click', function (event) {
        const link = event.target.closest('.js-complaint-open');
        if (!link) return;
        event.preventDefault();
        openDrawer(link.getAttribute('href'));
      });

      closeBtn.addEventListener('click', closeDrawer);
      backdrop.addEventListener('click', closeDrawer);

      restore();
      const scroll = Number(sessionStorage.getItem('complaints-overview:scroll') || 0);
      if (Number.isFinite(scroll) && scroll > 0) {
        window.scrollTo({ top: scroll, behavior: 'auto' });
      }
    })();
  </script>
</body>
</html>
