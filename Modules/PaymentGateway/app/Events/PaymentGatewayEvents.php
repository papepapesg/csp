<?php

namespace Modules\PaymentGateway\Events;

/** PAY-GW-01 domain-event types + topic. */
final class PaymentGatewayEvents
{
    public const TOPIC = 'paymentgateway.callback';

    public const CALLBACK_RECEIVED = 'GatewayCallbackReceived';

    public const CALLBACK_PROCESSED = 'GatewayCallbackProcessed';

    public const CALLBACK_REJECTED = 'GatewayCallbackRejected';

    public const CALLBACK_DUPLICATE = 'GatewayCallbackDuplicate';
}
