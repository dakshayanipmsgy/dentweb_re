<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/documents_helpers.php';

function business_pulse_quote_amount(array $quote): float { return (float)($quote['calc']['gross_payable'] ?? $quote['calc']['final_price_incl_gst'] ?? $quote['calc']['grand_total'] ?? 0); }
function business_pulse_date(array $row, array $keys=['accepted_at','approved_at','updated_at','created_at']): string { foreach($keys as $k){$v=substr((string)($row[$k]??''),0,10); if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)) return $v;} return ''; }
function business_pulse_is_current_accepted_quote(array $q): bool { return documents_quote_normalize_status((string)($q['status']??'draft'))==='accepted' && !empty($q['is_current_version']) && !documents_is_archived($q); }
function business_pulse_collection_pct(float $received,float $total): ?float { return $total > 0 ? round(($received/$total)*100,1) : null; }
function business_pulse_format_pct(?float $pct): string { return $pct === null ? '—' : number_format($pct,1).'%'; }

function business_pulse_accepted_rows(): array {
    $rows=[]; foreach(documents_list_quotes() as $q){ if(!is_array($q)||!business_pulse_is_current_accepted_quote($q)) continue; $qid=(string)($q['id']??''); $summary=documents_project_financial_summary($q); $lastPay='';
        foreach(documents_final_receipts_for_quote($qid) as $r){ $d=business_pulse_date($r,['date_received','receipt_date','created_at']); if($d>$lastPay)$lastPay=$d; }
        $amount=(float)$summary['calculation_reference_amount']; $received=(float)$summary['total_payment_received']; $due=(float)$summary['remaining_amount'] - (float)$summary['overpayment'];
        $rows[]=['quote'=>$q,'qid'=>$qid,'amount'=>$amount,'received'=>$received,'due'=>$due,'pct'=>business_pulse_collection_pct($received,$amount),'accepted_date'=>business_pulse_date($q),'last_payment_date'=>$lastPay,'value_source'=>(string)$summary['calculation_basis'],'basis_status'=>(string)$summary['basis_status']]; }
    return $rows;
}

function business_pulse_collect_by_quote(array $docs,string $qid): array { return array_values(array_filter($docs,static fn($d):bool=>is_array($d)&&!documents_is_archived($d)&&(string)($d['quotation_id']??$d['linked_quote_id']??'')===$qid)); }

function business_pulse_document_status(array $row,array $sources): array {
    $qid=(string)$row['qid']; return ['agreement'=>business_pulse_collect_by_quote($sources['agreements'],$qid)!==[], 'dispatch'=>array_values(array_filter(documents_dispatch_advices_for_quote($qid),static fn(array $d):bool=>!documents_is_archived($d)))!==[], 'challan'=>business_pulse_collect_by_quote($sources['challans'],$qid)!==[], 'invoice'=>business_pulse_collect_by_quote($sources['invoices'],$qid)!==[]];
}

function business_pulse_summary(): array {
    $rows=business_pulse_accepted_rows(); $fyStart=((int)date('n')>=4?date('Y'):(string)((int)date('Y')-1)).'-04-01'; $month=date('Y-m'); $lastMonth=(new DateTimeImmutable('first day of this month'))->modify('-1 month')->format('Y-m');
    $sources=['agreements'=>documents_list_sales_documents('agreement'),'challans'=>documents_list_sales_documents('delivery_challan'),'invoices'=>documents_list_sales_documents('invoice')];
    $total=$received=$fyBooked=$monthBooked=$lastMonthBooked=$invoicedFy=0.0;
    foreach(documents_all_invoices() as $inv){ if(!is_array($inv)||documents_is_archived($inv)||!documents_invoice_is_finalized($inv)) continue; $d=documents_invoice_authoritative_date($inv); if($d!==''&&$d>=$fyStart){ $invoicedFy+=documents_invoice_final_total($inv); } }
    $dues=[0,0,0,0]; $customersWithDues=0; $advance=0; $highest=null; $pending=['agreement'=>0,'dispatch'=>0,'challan'=>0,'invoice'=>0,'payment_request'=>0]; $newAccepted=0; $fullyPaid=0;
    foreach($rows as $r){ $total+=$r['amount']; $received+=$r['received']; if($r['accepted_date']>=$fyStart)$fyBooked+=$r['amount']; if(substr($r['accepted_date'],0,7)===$month){$monthBooked+=$r['amount'];$newAccepted++;} if(substr($r['accepted_date'],0,7)===$lastMonth)$lastMonthBooked+=$r['amount']; if($r['due']>0.009){$customersWithDues++; if($highest===null||$r['due']>$highest['due'])$highest=$r; $age=max(0,(int)((time()-strtotime($r['accepted_date']?:date('Y-m-d')))/86400)); if($age<=7)$dues[0]+=$r['due']; elseif($age<=15)$dues[1]+=$r['due']; elseif($age<=30)$dues[2]+=$r['due']; else $dues[3]+=$r['due'];} elseif($r['due']<-0.009)$advance++; else $fullyPaid++; $ds=business_pulse_document_status($r,$sources); foreach(['agreement','dispatch','challan','invoice'] as $k){ if(!$ds[$k])$pending[$k]++; } $ps=documents_payment_summary_for_quote($r['quote']); if((int)($ps['active_request_count']??0)===0 && $r['due']>0.009)$pending['payment_request']++;  }
    return ['rows'=>$rows,'total_business'=>$total,'total_received'=>$received,'total_invoiced_fy'=>$invoicedFy,'total_dues'=>array_sum($dues),'collection_pct'=>business_pulse_collection_pct($received,$total),'fy_booked'=>$fyBooked,'month_booked'=>$monthBooked,'last_month_booked'=>$lastMonthBooked,'avg_project'=>count($rows)?$total/count($rows):0,'dues_buckets'=>$dues,'customers_with_dues'=>$customersWithDues,'highest_due'=>$highest,'advance_customers'=>$advance,'accepted_count'=>count($rows),'new_accepted_month'=>$newAccepted,'pending'=>$pending,'fully_paid'=>$fullyPaid];
}
