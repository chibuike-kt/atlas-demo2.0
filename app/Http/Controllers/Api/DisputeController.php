<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
  public function __construct(private readonly DisputeService $disputes) {}

  /** POST /disputes */
  public function store(Request $request): JsonResponse
  {
    $request->validate([
      'execution_id' => 'required|uuid',
      'reason'       => 'required|in:' . implode(',', array_keys(Dispute::REASONS)),
      'description'  => 'required|string|min:20|max:1000',
    ]);

    try {
      $dispute = $this->disputes->open(
        auth()->user(),
        $request->execution_id,
        $request->reason,
        $request->description
      );

      return response()->json([
        'success' => true,
        'message' => "Dispute {$dispute->dispute_number} opened. Our team will review within 2 business days.",
        'data'    => $this->format($dispute),
      ], 201);
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
      return response()->json(['success' => false, 'message' => 'Execution not found.'], 404);
    } catch (\RuntimeException $e) {
      return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
    }
  }

  /** GET /disputes */
  public function index(): JsonResponse
  {
    $disputes = Dispute::where('user_id', auth()->id())
      ->orderBy('created_at', 'desc')
      ->get()
      ->map(fn($d) => $this->format($d));

    return response()->json(['success' => true, 'data' => $disputes]);
  }

  /** GET /disputes/{id} */
  public function show(string $id): JsonResponse
  {
    $dispute = Dispute::where('user_id', auth()->id())->findOrFail($id);
    return response()->json(['success' => true, 'data' => $this->format($dispute)]);
  }

  /** GET /disputes/reasons */
  public function reasons(): JsonResponse
  {
    return response()->json(['success' => true, 'data' => Dispute::REASONS]);
  }

  private function format(Dispute $d): array
  {
    return [
      'id'              => $d->id,
      'dispute_number'  => $d->dispute_number,
      'execution_id'    => $d->execution_id,
      'reason'          => $d->reason,
      'reason_label'    => Dispute::REASONS[$d->reason] ?? $d->reason,
      'description'     => $d->description,
      'amount'          => '₦' . number_format((float)$d->amount_ngn, 2),
      'status'          => $d->status,
      'refund_amount'   => $d->refund_amount ? '₦' . number_format((float)$d->refund_amount, 2) : null,
      'resolution_note' => $d->resolution_note,
      'opened_at'       => $d->opened_at,
      'resolved_at'     => $d->resolved_at,
    ];
  }
}
