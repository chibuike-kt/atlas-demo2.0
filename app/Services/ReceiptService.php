<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\RuleExecution;

class ReceiptService
{
  public function generate(RuleExecution $execution): Receipt
  {
    // Check if receipt already exists
    $existing = Receipt::where('execution_id', $execution->id)->first();
    if ($existing) return $existing;

    $execution->load(['steps', 'rule' => fn($q) => $q->withTrashed(), 'user']);

    $user  = $execution->user;
    $rule  = $execution->rule;
    $steps = $execution->steps;

    // Get fees for this execution
    $fees = \App\Models\FeeLedger::where('execution_id', $execution->id)->get();
    $totalFees = (float) $fees->sum('fee_amount');

    // Build receipt data snapshot
    $receiptData = [
      'receipt_number' => Receipt::generateReceiptNumber(),
      'generated_at'   => now()->toISOString(),
      'user'           => [
        'name'  => $user->full_name,
        'email' => $user->email,
      ],
      'rule'           => [
        'name'        => $rule?->name ?? '[Deleted Rule]',
        'trigger'     => $rule?->trigger_type ?? 'unknown',
      ],
      'execution'      => [
        'id'           => $execution->id,
        'triggered_by' => $execution->triggered_by,
        'status'       => $execution->status,
        'started_at'   => $execution->started_at,
        'completed_at' => $execution->completed_at,
        'total_amount' => '₦' . number_format((float) $execution->total_amount_ngn, 2),
      ],
      'steps' => $steps->map(fn($s) => [
        'step'           => $s->step_order,
        'label'          => $s->label,
        'action_type'    => $s->action_type,
        'amount'         => '₦' . number_format((float) $s->amount_ngn, 2),
        'status'         => $s->status,
        'rail_reference' => $s->rail_reference,
        'completed_at'   => $s->completed_at,
      ])->toArray(),
      'fees' => $fees->map(fn($f) => [
        'type'        => $f->fee_type,
        'amount'      => '₦' . number_format((float) $f->fee_amount, 2),
        'description' => $f->description,
      ])->toArray(),
      'summary' => [
        'total_moved' => '₦' . number_format((float) $execution->total_amount_ngn, 2),
        'total_fees'  => '₦' . number_format($totalFees, 2),
        'steps_count' => $steps->count(),
        'status'      => $execution->status,
      ],
    ];

    return Receipt::create([
      'user_id'        => $user->id,
      'execution_id'   => $execution->id,
      'receipt_number' => $receiptData['receipt_number'],
      'total_amount'   => $execution->total_amount_ngn,
      'total_fees'     => $totalFees,
      'status'         => $execution->status,
      'receipt_data'   => $receiptData,
    ]);
  }

  // Generate HTML receipt for display/download
  public function renderHtml(Receipt $receipt): string
  {
    $d = $receipt->receipt_data;
    $steps = collect($d['steps'] ?? []);
    $fees  = collect($d['fees']  ?? []);

    $stepsHtml = $steps->map(fn($s, $i) => "
            <tr>
                <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0'>{$s['step']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0'>{$s['label']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0'>{$s['amount']}</td>
                <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0'>
                    <span style='background:" . ($s['status'] === 'completed' ? '#ECFDF3' : '#FEF3F2') . ";color:" . ($s['status'] === 'completed' ? '#027A48' : '#B42318') . ";padding:2px 8px;border-radius:99px;font-size:12px'>{$s['status']}</span>
                </td>
                <td style='padding:10px 12px;border-bottom:1px solid #f0f0f0;font-family:monospace;font-size:12px'>{$s['rail_reference']}</td>
            </tr>
        ")->join('');

    $feesHtml = $fees->count() > 0
      ? $fees->map(fn($f) => "
                <tr>
                    <td style='padding:8px 12px'>{$f['description']}</td>
                    <td style='padding:8px 12px;text-align:right;font-weight:600'>{$f['amount']}</td>
                </tr>
            ")->join('')
      : "<tr><td colspan='2' style='padding:8px 12px;color:#98A2B3'>No fees charged</td></tr>";

    $statusColor = $d['execution']['status'] === 'completed' ? '#027A48' : '#B42318';
    $statusBg    = $d['execution']['status'] === 'completed' ? '#ECFDF3' : '#FEF3F2';

    return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'/>
<title>Atlas Receipt — {$receipt->receipt_number}</title>
<link href='https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap' rel='stylesheet'/>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',sans-serif;background:#F7F8FA;color:#101828;padding:40px 20px}
  .receipt{max-width:680px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .header{background:#0F6FDE;padding:32px 40px;color:#fff}
  .header-top{display:flex;justify-content:space-between;align-items:flex-start}
  .logo{font-size:24px;font-weight:700;letter-spacing:-0.5px}
  .receipt-num{font-family:'DM Mono',monospace;font-size:13px;opacity:.8;margin-top:4px}
  .status-badge{padding:6px 14px;border-radius:99px;font-size:13px;font-weight:600;background:{$statusBg};color:{$statusColor}}
  .section{padding:24px 40px;border-bottom:1px solid #F0F2F5}
  .section-title{font-size:11px;font-weight:600;color:#98A2B3;text-transform:uppercase;letter-spacing:.8px;margin-bottom:14px}
  .meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .meta-item label{font-size:12px;color:#667085;display:block;margin-bottom:2px}
  .meta-item span{font-size:14px;font-weight:500}
  table{width:100%;border-collapse:collapse}
  th{text-align:left;padding:10px 12px;font-size:12px;font-weight:600;color:#667085;background:#F9FAFB;border-bottom:1px solid #F0F2F5}
  .summary{padding:24px 40px;background:#F9FAFB}
  .summary-row{display:flex;justify-content:space-between;padding:6px 0;font-size:14px}
  .summary-row.total{font-size:16px;font-weight:700;border-top:2px solid #E4E7EC;padding-top:12px;margin-top:6px}
  .footer{padding:20px 40px;text-align:center;font-size:12px;color:#98A2B3}
</style>
</head>
<body>
<div class='receipt'>
  <div class='header'>
    <div class='header-top'>
      <div>
        <div class='logo'>Atlas</div>
        <div class='receipt-num'>{$receipt->receipt_number}</div>
      </div>
      <span class='status-badge'>{$d['execution']['status']}</span>
    </div>
  </div>

  <div class='section'>
    <div class='section-title'>Transaction Details</div>
    <div class='meta-grid'>
      <div class='meta-item'><label>Rule</label><span>{$d['rule']['name']}</span></div>
      <div class='meta-item'><label>Triggered by</label><span>{$d['execution']['triggered_by']}</span></div>
      <div class='meta-item'><label>Date</label><span>{$d['execution']['started_at']}</span></div>
      <div class='meta-item'><label>Total moved</label><span>{$d['execution']['total_amount']}</span></div>
      <div class='meta-item'><label>Account</label><span>{$d['user']['name']}</span></div>
      <div class='meta-item'><label>Reference</label><span style='font-family:monospace;font-size:12px'>{$d['execution']['id']}</span></div>
    </div>
  </div>

  <div class='section'>
    <div class='section-title'>Steps ({$steps->count()})</div>
    <table>
      <thead><tr>
        <th>#</th><th>Action</th><th>Amount</th><th>Status</th><th>Reference</th>
      </tr></thead>
      <tbody>{$stepsHtml}</tbody>
    </table>
  </div>

  <div class='section'>
    <div class='section-title'>Fees</div>
    <table><tbody>{$feesHtml}</tbody></table>
  </div>

  <div class='summary'>
    <div class='summary-row'><span>Total moved</span><span>{$d['summary']['total_moved']}</span></div>
    <div class='summary-row'><span>Total fees</span><span>{$d['summary']['total_fees']}</span></div>
    <div class='summary-row total'><span>Net debited</span><span>{$d['summary']['total_moved']}</span></div>
  </div>

  <div class='footer'>
    Generated by Atlas · {$d['generated_at']} · This is an official transaction receipt
  </div>
</div>
</body>
</html>";
  }
}
