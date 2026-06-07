<?php

namespace Modules\Ilm\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ilm\Models\CustomerAccount;

/**
 * @mixin CustomerAccount
 */
class CustomerAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'accountId' => $this->account_id,
            'accountNumber' => $this->account_number,
            'paymentAccountNumber' => $this->payment_account_number,
            'customerId' => $this->customer_id,
            'operatorCode' => $this->operator_code,
            'homepassId' => $this->homepass_id,
            'serviceAddress' => $this->service_address,
            'status' => $this->status,
            'subStatus' => $this->sub_status,
            'subStatusReason' => $this->sub_status_reason,
            'serviceClass' => [
                'class1' => $this->service_class_1,
                'class2' => $this->service_class_2,
                'class3' => $this->service_class_3,
            ],
            'attentionBanner' => $this->attention_banner,
            'subscriptionId' => $this->subscription_id,
            'startBillDate' => $this->start_bill_date?->toDateString(),
            'installDate' => $this->install_date?->toDateString(),
            'disconnectDate' => $this->disconnect_date?->toDateString(),
            'accountManagerId' => $this->account_manager_id,
            'franchiseCode' => $this->franchise_code,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
