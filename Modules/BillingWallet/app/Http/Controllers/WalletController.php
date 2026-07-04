<?php

namespace Modules\Billing\Wallet\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Wallet\Models\Wallet;
use Modules\Billing\Wallet\Models\WalletTransaction;
use Modules\Billing\Wallet\Services\WalletService;

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

    /** GET /api/wallets/{subscriptionId}/balance?walletCode=MONEY */
    public function balance(Request $request, string $subscriptionId): JsonResponse
    {
        $wallet = $this->wallets->ensureWallet($subscriptionId, $this->walletCode($request));

        return ApiResponse::item([
            'walletId' => $wallet->wallet_id,
            'subscriptionId' => $wallet->subscription_id,
            'walletCode' => $wallet->wallet_code,
            'currency' => $this->wallets->deploymentCurrency($wallet->operator_code),
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
        $txn = $this->wallets->credit($wallet, (float) $data['amount'], WalletTransaction::MOVEMENT_TOPUP, $data['reference'] ?? null);

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
        // The endpoint can never set the cataloged movement — a debit is always a CHARGE; the
        // caller's free text is only the descriptive reason. So TOPUP can't be injected here.
        $txn = $this->wallets->debit($wallet, (float) $data['amount'], WalletTransaction::MOVEMENT_CHARGE, $data['reference'] ?? null, $data['reason'] ?? null);

        return ApiResponse::item($txn);
    }
}
