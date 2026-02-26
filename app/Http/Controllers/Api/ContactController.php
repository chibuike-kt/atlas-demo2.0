<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private readonly ContactService $contactService)
    {
        auth()->shouldUse('api');
    }

    public function index(): JsonResponse
    {
        $contacts = $this->contactService->list(auth()->user());
        return response()->json(['success' => true, 'data' => $contacts]);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->input('type', 'bank');

        if ($type === 'bank') {
            $request->validate([
                'label'          => ['required', 'string', 'max:50'],
                'type'           => ['required', 'in:bank,crypto'],
                'account_name'   => ['required', 'string', 'max:150'],
                'account_number' => ['required', 'string', 'size:10'],
                'bank_code'      => ['required', 'string', 'max:10'],
                'bank_name'      => ['required', 'string', 'max:100'],
            ]);
        } else {
            $request->validate([
                'label'          => ['required', 'string', 'max:50'],
                'type'           => ['required', 'in:bank,crypto'],
                'wallet_address' => ['required', 'string'],
                'crypto_network' => ['required', 'string', 'max:20'],
            ]);
        }

        try {
            $contact = $this->contactService->create(
                auth()->user(),
                $request->all()
            );

            return response()->json([
                'success' => true,
                'message' => "Contact \"{$contact->label}\" saved.",
                'data'    => $this->contactService->format($contact),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function destroy(string $id): JsonResponse
    {
        try {
            $this->contactService->delete(auth()->user(), $id);
            return response()->json(['success' => true, 'message' => 'Contact removed.']);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function deviceContacts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->contactService->deviceContacts(),
        ]);
    }

    public function banks(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->contactService->bankList(),
        ]);
    }
}
