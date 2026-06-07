<?php

use Illuminate\Support\Facades\Route;
use Modules\PaymentGateway\Http\Controllers\GatewayCallbackController;

/*
| PAY-GW-01 Payment Gateway Integration API (DD_API-00). Gateway callbacks are
| accepted under payment.apply (service-token in production); reads under payment.read.
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('payment-gateway/{provider}/callbacks', [GatewayCallbackController::class, 'callback'])->middleware('permission:payment.apply');
    Route::get('payment-gateway/callbacks', [GatewayCallbackController::class, 'index'])->middleware('permission:payment.read');
    Route::get('payment-gateway/callbacks/{paymentGatewayCallback}', [GatewayCallbackController::class, 'show'])->middleware('permission:payment.read');
});
