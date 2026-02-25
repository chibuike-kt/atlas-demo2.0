<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RuleParserService;
use App\Services\ContactService;
use App\Services\EncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RuleParserController extends Controller
{
    public function __construct(
        private readonly RuleParserService $parser,
        private readonly ContactService    $contactService,
        private readonly EncryptionService $encryption,
    ) {
        auth()->shouldUse('api');
    }

    /**
     * POST /api/rules/parse
     * Parse plain English rule text into structured config.
     */
    public function parse(Request $request): JsonResponse
    {
        $request->validate([
            'rule_text' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        $user = auth()->user();

        // Get user's contacts and wallets to match against
        $contacts = $this->contactService->list($user);
        $wallets  = $this->getWallets($user);

        $parsed = $this->parser->parse(
            $request->rule_text,
            $contacts,
            $wallets
        );

        return response()->json([
            'success' => true,
            'data'    => $parsed,
        ]);
    }

    private function getWallets($user): array
    {
        return $user->savedContacts()
            ->where('type', 'crypto')
            ->where('is_active', true)
            ->get()
            ->map(function ($c) {
                try {
                    $address = $this->encryption->decrypt($c->wallet_address_enc);
                } catch (\Throwable) {
                    $address = null;
                }
                return [
                    'id'      => $c->id,
                    'label'   => $c->label,
                    'address' => $address,
                    'network' => $c->crypto_network,
                ];
            })
            ->toArray();
    }
}
