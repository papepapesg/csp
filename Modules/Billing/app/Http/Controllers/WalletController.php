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

    /** GET /api/wallets/{subscriptionId}/balance */
    public function balance(string $subscriptionId): JsonResponse
    {
        $wallet = $this->wallets->ensureWallet($subscriptionId);

        return ApiResponse::item([
            'walletId' => $wallet->wallet_id,
            'subscriptionId' => $wallet->subscription_id,
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
            'reference' => ['nullable', 'string'],
        ]);

        $wallet = $this->wallets->ensureWallet($subscriptionId);
        $txn = $this->wallets->credit($wallet, (float) $data['amount'], 'TOPUP', $data['reference'] ?? null);

        return ApiResponse::created($txn);
    }

    /** POST /api/wallets/{subscriptionId}/debit */
    public function debit(Request $request, string $subscriptionId): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:64'],
            'reference' => ['nullable', 'string'],
        ]);

        $wallet = $this->wallets->ensureWallet($subscriptionId);
        $txn = $this->wallets->debit($wallet, (float) $data['amount'], $data['reason'] ?? 'CYCLE_CHARGE', $data['reference'] ?? null);

        return ApiResponse::item($txn);
    }
}
