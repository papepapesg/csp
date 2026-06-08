<?php

namespace Modules\Billing\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Models\Wallet;
use Modules\Billing\Services\WalletService;

/**
 * BIL-05 wallet & top-up API (keyed by subscription).
 */
class WalletController extends ApiController
{
    public function __construct(private readonly WalletService $wallets) {}

    /** The PLM-CFG-03 walletRef being addressed; defaults to the money wallet. */
    private function walletCode(Request $request): string
    {
        return $request->query('walletCode', $request->input('walletCode', WalletService::DEFAULT_WALLET_CODE));
    }

    /** GET /api/wallets/{subscriptionId}/balance?walletCode=MONEY_KES */
    public function balance(Request $request, string $subscriptionId): JsonResponse
    {
        $wallet = $this->wallets->ensureWallet($subscriptionId, $this->walletCode($request));

        return ApiResponse::item([
            'walletId' => $wallet->wallet_id,
            'subscriptionId' => $wallet->subscription_id,
            'walletCode' => $wallet->wallet_code,
            'currency' => $wallet->currency,
            'balance' => $wallet->balance,
            'status' => $wallet->status,
        ]);
    }

    /** POST /api/wallets/{subscriptionId}/topup */
    public function topup(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'walletCode' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string'],
        ]);

        $wallet = $this->wallets->ensureWallet($subscriptionId, $this->walletCode($request));
        $txn = $this->wallets->credit($wallet, (float) $data['amount'], 'TOPUP', $data['reference'] ?? null);

        return ApiResponse::created($txn);
    }

    /** POST /api/wallets/{subscriptionId}/debit */
    public function debit(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:64'],
            'walletCode' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string'],
        ]);

        $wallet = $this->wallets->ensureWallet($subscriptionId, $this->walletCode($request));
        $txn = $this->wallets->debit($wallet, (float) $data['amount'], $data['reason'] ?? 'CYCLE_CHARGE', $data['reference'] ?? null);

        return ApiResponse::item($txn);
    }
}
