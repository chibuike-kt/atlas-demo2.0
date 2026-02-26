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

    public function parse(Request $request): JsonResponse
    {
        $ruleText = trim((string) $request->input('rule_text', ''));

        if (strlen($ruleText) < 5) {
            return response()->json([
                'success' => false,
                'message' => 'Rule text too short',
            ], 422);
        }

        $user     = auth()->user();
        $contacts = $this->contactService->list($user);
        $wallets  = $this->getWallets($user);
        $parsed   = $this->parser->parse($ruleText, $contacts, $wallets);

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
