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

    /**
     * GET /api/contacts
     * List all saved contacts.
     */
    public function index(): JsonResponse
    {
        $contacts = $this->contactService->list(auth()->user());

        return response()->json([
            'success' => true,
            'data'    => $contacts,
        ]);
    }

    /**
     * POST /api/contacts
     * Save a new contact manually or from device contacts.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'label'          => ['required', 'string', 'max:50'],
            'type'           => ['required', 'in:bank,crypto'],

            // Bank fields
            'account_name'   => ['required_if:type,bank', 'string', 'max:150'],
            'account_number' => ['required_if:type,bank', 'string', 'size:10'],
            'bank_code'      => ['required_if:type,bank', 'string', 'max:10'],
            'bank_name'      => ['required_if:type,bank', 'string', 'max:100'],

            // Crypto fields
            'wallet_address' => ['required_if:type,crypto', 'string'],
            'crypto_network' => ['nullable', 'string', 'max:20'],
        ]);

        try {
            $contact = $this->contactService->create(
                auth()->user(),
                $request->validated()
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

    /**
     * DELETE /api/contacts/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $this->contactService->delete(auth()->user(), $id);

            return response()->json([
                'success' => true,
                'message' => 'Contact removed.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * GET /api/contacts/device
     * Simulated device contacts picker.
     * Production: replaced by native mobile contacts API.
     */
    public function deviceContacts(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Simulated device contacts. Production: native contacts API.',
            'data'    => $this->contactService->deviceContacts(),
        ]);
    }

    /**
     * GET /api/contacts/banks
     * List of Nigerian banks for account input form.
     */
    public function banks(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->contactService->bankList(),
        ]);
    }
}
