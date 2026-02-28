<?php

namespace App\Services;

use App\Models\Receipt;
use App\Models\RuleExecution;

class ReceiptService
{
    public function generate(RuleExecution $execution): Receipt
    {
        $existing = Receipt::where('execution_id', $execution->id)->first();
        if ($existing) return $existing;

        $execution->load(['steps', 'rule' => fn($q) => $q->withTrashed(), 'user']);

        $user  = $execution->user;
        $rule  = $execution->rule;
        $steps = $execution->steps;

        // Resolve amounts from execution record
        // total_debit_ngn  = what actually left the account (movement + service charge)
        // total_amount_ngn = the movement amount (what was distributed to steps)
        // service_charge_ngn = Atlas service charge — stored but NOT surfaced to user
        $movementNgn   = (float)($execution->total_amount_ngn    ?? 0);
        $serviceCharge = (float)($execution->service_charge_ngn  ?? 0);
        $totalDebitNgn = (float)($execution->total_debit_ngn     ?? ($movementNgn + $serviceCharge));

        // Build step rows
        // Crypto steps show USDT amount + rate — not NGN equivalent
        // Like Spenda: "deposited $150.02, got value for $148.66 at rate ₦1,371"
        $stepRows = $steps->map(function ($s) {
            $isCrypto  = $s->action_type === 'convert_crypto';
            $result    = is_array($s->result) ? $s->result : (json_decode($s->result, true) ?? []);
            $amountNgn = (float) $s->amount_ngn;

            if ($isCrypto) {
                return [
                    'step'           => $s->step_order,
                    'label'          => $s->label ?? 'Crypto Conversion',
                    'action_type'    => $s->action_type,
                    'display_type'   => 'crypto',
                    'amount_ngn'     => '₦' . number_format($amountNgn, 2),
                    'amount_token'   => isset($result['amount_token'])
                        ? number_format((float)$result['amount_token'], 4) . ' ' . ($result['token'] ?? 'USDT')
                        : null,
                    'network'        => strtoupper($result['network'] ?? ''),
                    // Show Atlas rate only — user never sees market rate or spread
                    'rate'           => isset($result['atlas_rate'])
                        ? '₦' . number_format((float)$result['atlas_rate'], 2) . '/' . ($result['token'] ?? 'USDT')
                        : null,
                    'status'         => $s->status,
                    'rail_reference' => $s->rail_reference,
                    'completed_at'   => $s->completed_at,
                ];
            }

            return [
                'step'           => $s->step_order,
                'label'          => $s->label ?? $s->action_type,
                'action_type'    => $s->action_type,
                'display_type'   => 'ngn',
                'amount_ngn'     => '₦' . number_format($amountNgn, 2),
                'amount_token'   => null,
                'network'        => null,
                'rate'           => null,
                'status'         => $s->status,
                'rail_reference' => $s->rail_reference,
                'completed_at'   => $s->completed_at,
            ];
        })->toArray();

        $receiptData = [
            'receipt_number' => Receipt::generateReceiptNumber(),
            'generated_at'   => now()->toISOString(),
            'user'           => ['name' => $user->full_name, 'email' => $user->email],
            'rule'           => ['name' => $rule?->name ?? '[Deleted Rule]', 'trigger_type' => $rule?->trigger_type ?? 'unknown'],
            'execution'      => [
                'id'           => $execution->id,
                'triggered_by' => $execution->triggered_by,
                'status'       => $execution->status,
                'started_at'   => $execution->started_at,
                'completed_at' => $execution->completed_at,
            ],
            // Only what the user needs to see: amount moved, service charge, total out
            'summary' => [
                'total_moved'    => '₦' . number_format($movementNgn, 2),
                'service_charge' => '₦' . number_format($serviceCharge, 2),
                'total_debited'  => '₦' . number_format($totalDebitNgn, 2),
                'steps_count'    => $steps->count(),
                'status'         => $execution->status,
            ],
            'steps'   => $stepRows,
            // Internal only — never rendered in user HTML
            '_internal' => [
                'movement_ngn'   => $movementNgn,
                'service_charge' => $serviceCharge,
                'total_debit'    => $totalDebitNgn,
            ],
        ];

        return Receipt::create([
            'user_id'        => $user->id,
            'execution_id'   => $execution->id,
            'receipt_number' => $receiptData['receipt_number'],
            'total_amount'   => $movementNgn,
            'total_fees'     => $serviceCharge,
            'status'         => $execution->status,
            'receipt_data'   => $receiptData,
        ]);
    }

    /**
     * Render clean HTML receipt.
     * Rules (GTBank / Paystack / Spenda standard):
     *   - Show per-step amounts
     *   - Crypto steps: show "Sent ₦X → received Y USDT at ₦Z/USDT"
     *   - ONE service charge line total — no breakdown of what it's for
     *   - Total debited at the bottom
     *   - Zero fee_ledger data visible to user
     */
    public function renderHtml(Receipt $receipt): string
    {
        $d       = $receipt->receipt_data;
        $steps   = collect($d['steps']   ?? []);
        $summary = $d['summary']  ?? [];
        $exec    = $d['execution'] ?? [];
        $rule    = $d['rule']      ?? [];
        $user    = $d['user']      ?? [];

        $isOk        = ($exec['status'] ?? '') === 'completed';
        $statusColor = $isOk ? '#027A48' : '#B42318';
        $statusBg    = $isOk ? '#ECFDF3' : '#FEF3F2';
        $statusLabel = ucfirst($exec['status'] ?? 'unknown');

        $stepsHtml = $steps->map(function ($s) {
            $isCrypto = ($s['display_type'] ?? '') === 'crypto';
            $ok       = ($s['status'] ?? '') === 'completed';
            $sBg      = $ok ? '#ECFDF3' : '#FEF3F2';
            $sColor   = $ok ? '#027A48' : '#B42318';
            $ref      = $s['rail_reference'] ?? '';
            $shortRef = strlen($ref) > 18 ? substr($ref, 0, 16) . '..' : ($ref ?: '—');

            if ($isCrypto) {
                $received = htmlspecialchars($s['amount_token'] ?? '—');
                $rate     = $s['rate']    ? '<br/><small style="color:#667085">@ ' . htmlspecialchars($s['rate']) . '</small>' : '';
                $net      = $s['network'] ? ' <span style="font-size:11px;background:#FFF7ED;color:#B54708;padding:1px 6px;border-radius:99px">' . htmlspecialchars($s['network']) . '</span>' : '';
                $amtHtml  = htmlspecialchars($s['amount_ngn']) . ' &rarr; <strong>' . $received . $net . '</strong>' . $rate;
            } else {
                $amtHtml = '<strong>' . htmlspecialchars($s['amount_ngn']) . '</strong>';
            }

            $label     = htmlspecialchars($s['label'] ?? '');
            $stepNum   = (int)($s['step'] ?? 0);
            $statusHtml = '<span style="background:' . $sBg . ';color:' . $sColor . ';padding:3px 10px;border-radius:99px;font-size:12px;font-weight:500">' . ucfirst($s['status'] ?? '') . '</span>';

            return "
            <tr>
                <td style='padding:12px 16px;border-bottom:1px solid #F0F2F5;color:#667085;font-size:13px'>{$stepNum}</td>
                <td style='padding:12px 16px;border-bottom:1px solid #F0F2F5;font-size:13.5px;font-weight:500'>{$label}</td>
                <td style='padding:12px 16px;border-bottom:1px solid #F0F2F5;font-size:13.5px'>{$amtHtml}</td>
                <td style='padding:12px 16px;border-bottom:1px solid #F0F2F5'>{$statusHtml}</td>
                <td style='padding:12px 16px;border-bottom:1px solid #F0F2F5;font-family:monospace;font-size:11px;color:#98A2B3'>{$shortRef}</td>
            </tr>";
        })->join('');

        $startedAt     = $exec['started_at']   ? date('d M Y, H:i', strtotime($exec['started_at']))   : '—';
        $completedAt   = $exec['completed_at'] ? date('d M Y, H:i', strtotime($exec['completed_at'])) : '—';
        $execIdShort   = htmlspecialchars(substr($exec['id'] ?? '', 0, 20) . '...');
        $ruleName      = htmlspecialchars($rule['name']     ?? '—');
        $triggeredBy   = htmlspecialchars($exec['triggered_by'] ?? '—');
        $userName      = htmlspecialchars($user['name']     ?? '—');
        $receiptNum    = htmlspecialchars($receipt->receipt_number);

        $totalMoved    = htmlspecialchars($summary['total_moved']    ?? '—');
        $serviceCharge = $summary['service_charge'] ?? '₦0.00';
        $totalDebited  = htmlspecialchars($summary['total_debited']  ?? $totalMoved);
        $stepsCount    = (int)($summary['steps_count'] ?? 0);

        // Only show service charge row if non-zero — same as how banks handle ₦0 fee transfers
        $serviceChargeRow = ($serviceCharge !== '₦0.00')
            ? '<div class="sum-row"><span>Service charge</span><span>' . htmlspecialchars($serviceCharge) . '</span></div>'
            : '';

        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>Atlas Receipt &mdash; ' . $receiptNum . '</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"DM Sans",sans-serif;background:#F7F8FA;color:#101828;padding:40px 20px;-webkit-print-color-adjust:exact}
.wrap{max-width:700px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);border:1px solid #E4E7EC}
.hdr{background:linear-gradient(135deg,#0F6FDE,#0A52A8);padding:32px 40px;color:#fff;display:flex;justify-content:space-between;align-items:flex-start}
.logo{font-size:26px;font-weight:700;letter-spacing:-0.5px;font-family:"DM Mono",monospace}
.rnum{font-family:"DM Mono",monospace;font-size:12px;opacity:.7;margin-top:5px}
.status-pill{padding:6px 16px;border-radius:99px;font-size:13px;font-weight:600;background:' . $statusBg . ';color:' . $statusColor . '}
.sec{padding:24px 40px;border-bottom:1px solid #F0F2F5}
.sec-label{font-size:11px;font-weight:700;color:#98A2B3;text-transform:uppercase;letter-spacing:.8px;margin-bottom:16px}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.meta-item label{font-size:12px;color:#667085;display:block;margin-bottom:3px}
.meta-item span{font-size:14px;font-weight:500}
.meta-item .mono{font-family:"DM Mono",monospace;font-size:12px}
table{width:100%;border-collapse:collapse}
th{text-align:left;padding:10px 16px;font-size:11px;font-weight:600;color:#667085;background:#F9FAFB;border-bottom:1px solid #F0F2F5;text-transform:uppercase;letter-spacing:.4px}
.sum{padding:24px 40px;background:#F9FAFB}
.sum-row{display:flex;justify-content:space-between;padding:8px 0;font-size:14px;color:#475467}
.sum-row.total{font-size:17px;font-weight:700;color:#101828;border-top:2px solid #E4E7EC;padding-top:14px;margin-top:8px}
.ftr{padding:20px 40px;text-align:center;font-size:12px;color:#98A2B3}
@media print{body{padding:0;background:#fff}.wrap{box-shadow:none;border-radius:0}}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div>
      <div class="logo">Atlas</div>
      <div class="rnum">' . $receiptNum . '</div>
    </div>
    <span class="status-pill">' . $statusLabel . '</span>
  </div>

  <div class="sec">
    <div class="sec-label">Transaction Details</div>
    <div class="meta">
      <div class="meta-item"><label>Rule</label><span>' . $ruleName . '</span></div>
      <div class="meta-item"><label>Triggered by</label><span>' . $triggeredBy . '</span></div>
      <div class="meta-item"><label>Date</label><span>' . $startedAt . '</span></div>
      <div class="meta-item"><label>Completed</label><span>' . $completedAt . '</span></div>
      <div class="meta-item"><label>Account</label><span>' . $userName . '</span></div>
      <div class="meta-item"><label>Reference</label><span class="mono">' . $execIdShort . '</span></div>
    </div>
  </div>

  <div class="sec">
    <div class="sec-label">Transactions (' . $stepsCount . ')</div>
    <table>
      <thead>
        <tr><th>#</th><th>Description</th><th>Amount</th><th>Status</th><th>Ref</th></tr>
      </thead>
      <tbody>' . $stepsHtml . '</tbody>
    </table>
  </div>

  <div class="sum">
    <div class="sum-row"><span>Amount distributed</span><span>' . $totalMoved . '</span></div>
    ' . $serviceChargeRow . '
    <div class="sum-row total"><span>Total debited</span><span>' . $totalDebited . '</span></div>
  </div>

  <div class="ftr">
    <strong>Atlas</strong> &nbsp;&middot;&nbsp; ' . $receiptNum . ' &nbsp;&middot;&nbsp; ' . $startedAt . '<br/>
    <span style="margin-top:6px;display:block">This is an official transaction record. Keep for your records.</span>
  </div>
</div>
</body>
</html>';
    }
}
