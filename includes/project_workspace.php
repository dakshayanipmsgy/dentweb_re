<?php
declare(strict_types=1);

/** Pure, read-only helpers for the Accepted/Completed operational lists. */
function project_workspace_params(array $input, string $kind): array
{
    $accepted = $kind === 'accepted';
    $prefix = $accepted ? 'accepted_' : 'completed_';
    $allowed = [
        'financial' => ['all','due','paid','credit'], 'age' => ['all','current','1_30','31_60','61_plus'],
        'request' => ['all','yes','no'], 'documents' => ['all','ready','missing'],
        'link' => ['all','linked','attention'], 'archive' => ['active','with_archived','archived'],
        'review' => ['all','clear','changed'], 'payment' => ['all','paid','due'],
    ];
    $sorts = $accepted
        ? ['due_desc','due_asc','age_desc','customer_asc','quotation_asc','received_desc']
        : ['completed_desc','completed_asc','customer_asc','quotation_asc','amount_desc','paid_desc'];
    $out = [
        'q' => trim((string)($input[$prefix.'q'] ?? '')),
        'documents' => in_array((string)($input[$prefix.'documents'] ?? 'all'), $allowed['documents'], true) ? (string)($input[$prefix.'documents'] ?? 'all') : 'all',
        'link' => in_array((string)($input[$prefix.'link'] ?? 'all'), $allowed['link'], true) ? (string)($input[$prefix.'link'] ?? 'all') : 'all',
        'sort' => in_array((string)($input[$prefix.'sort'] ?? $sorts[0]), $sorts, true) ? (string)($input[$prefix.'sort'] ?? $sorts[0]) : $sorts[0],
        'page' => max(1, filter_var($input[$prefix.'page'] ?? 1, FILTER_VALIDATE_INT) ?: 1),
        'per_page' => in_array((int)($input[$prefix.'per_page'] ?? 25), [25,50,100], true) ? (int)($input[$prefix.'per_page'] ?? 25) : 25,
    ];
    if ($accepted) {
        foreach (['financial','age','request','archive'] as $key) {
            $default = $key === 'archive' ? 'active' : 'all';
            $out[$key] = in_array((string)($input[$prefix.$key] ?? $default), $allowed[$key], true) ? (string)($input[$prefix.$key] ?? $default) : $default;
        }
    } else {
        foreach (['review','payment'] as $key) {
            $out[$key] = in_array((string)($input[$prefix.$key] ?? 'all'), $allowed[$key], true) ? (string)($input[$prefix.$key] ?? 'all') : 'all';
        }
        foreach (['from','to'] as $key) {
            $value = (string)($input[$prefix.$key] ?? '');
            $out[$key] = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? $value : '';
        }
    }
    return $out;
}

function project_workspace_query(array $state, string $kind, array $extra = []): array
{
    $prefix = $kind === 'accepted' ? 'accepted_' : 'completed_';
    $query = ['tab' => $kind === 'accepted' ? 'accepted_customers' : 'completed_customers'];
    foreach ($state as $key => $value) {
        if ($value !== '' && $value !== null) $query[$prefix.$key] = (string)$value;
    }
    return array_merge($query, $extra);
}

function project_workspace_return_query(string $encoded, string $kind): array
{
    parse_str(substr($encoded, 0, 3000), $input);
    return project_workspace_query(project_workspace_params(is_array($input) ? $input : [], $kind), $kind);
}

function project_workspace_filter(array $rows, array $state, string $kind): array
{
    $needle = strtolower(preg_replace('/\s+/', '', (string)$state['q']) ?? '');
    $rows = array_values(array_filter($rows, static function(array $row) use ($state, $kind, $needle): bool {
        $hay = strtolower((string)$row['customer'].' '.(string)$row['mobile'].' '.preg_replace('/\s+/', '', (string)$row['mobile']).' '.(string)$row['quotation']);
        if ($needle !== '' && !str_contains(preg_replace('/\s+/', '', $hay) ?? '', $needle)) return false;
        if ($state['documents'] !== 'all' && (($state['documents'] === 'ready') !== !empty($row['documents_ready']))) return false;
        if ($state['link'] !== 'all' && (($state['link'] === 'linked') !== !empty($row['link_ready']))) return false;
        if ($kind === 'accepted') {
            $due=(float)$row['due']; $financial=$due > .01 ? 'due' : ($due < -.01 ? 'credit' : 'paid');
            if ($state['financial'] !== 'all' && $state['financial'] !== $financial) return false;
            $days=(int)$row['due_days']; $age=$days<=0?'current':($days<=30?'1_30':($days<=60?'31_60':'61_plus'));
            if ($state['age'] !== 'all' && $state['age'] !== $age) return false;
            if ($state['request'] !== 'all' && (($state['request'] === 'yes') !== ((int)$row['active_requests'] > 0))) return false;
            if ($state['archive'] === 'active' && !empty($row['archived'])) return false;
            if ($state['archive'] === 'archived' && empty($row['archived'])) return false;
        } else {
            if ($state['from'] !== '' && (string)$row['completed_date'] < $state['from']) return false;
            if ($state['to'] !== '' && (string)$row['completed_date'] > $state['to']) return false;
            if ($state['review'] !== 'all' && (($state['review'] === 'changed') !== !empty($row['review_changed']))) return false;
            if ($state['payment'] !== 'all' && (($state['payment'] === 'paid') !== ((float)$row['paid'] + .01 >= (float)$row['amount']))) return false;
        }
        return true;
    }));
    $sort = (string)$state['sort'];
    usort($rows, static function(array $a, array $b) use ($sort): int {
        $cmp = match ($sort) {
            'due_desc' => (float)$b['due'] <=> (float)$a['due'], 'due_asc' => (float)$a['due'] <=> (float)$b['due'],
            'age_desc' => (int)$b['due_days'] <=> (int)$a['due_days'], 'received_desc' => (float)$b['received'] <=> (float)$a['received'],
            'completed_desc' => strcmp((string)$b['completed_date'], (string)$a['completed_date']), 'completed_asc' => strcmp((string)$a['completed_date'], (string)$b['completed_date']),
            'amount_desc' => (float)$b['amount'] <=> (float)$a['amount'], 'paid_desc' => (float)$b['paid'] <=> (float)$a['paid'],
            'quotation_asc' => strcasecmp((string)$a['quotation'], (string)$b['quotation']),
            default => strcasecmp((string)$a['customer'], (string)$b['customer']),
        };
        return $cmp !== 0 ? $cmp : strcmp((string)$a['id'], (string)$b['id']);
    });
    return $rows;
}

function project_workspace_paginate(array $rows, array $state): array
{
    $total=count($rows); $pages=max(1,(int)ceil($total/$state['per_page'])); $page=min($state['page'],$pages);
    return ['rows'=>array_slice($rows,($page-1)*$state['per_page'],$state['per_page']),'total'=>$total,'page'=>$page,'pages'=>$pages,'per_page'=>$state['per_page']];
}

function project_workspace_pagination_html(array $page, array $state, string $kind): string
{
    if ((int)$page['pages'] <= 1) return '';
    $html='<nav class="workspace-pagination" aria-label="'.ucfirst($kind).' customer pages">';
    foreach (range(1,(int)$page['pages']) as $number) {
        $query=project_workspace_query(array_merge($state,['page'=>$number]),$kind);
        $current=$number===(int)$page['page'];
        $html.='<a class="btn secondary'.($current?' active':'').'" href="?'.htmlspecialchars(http_build_query($query),ENT_QUOTES).'"'.($current?' aria-current="page"':'').'>'.$number.'</a>';
    }
    return $html.'</nav>';
}
