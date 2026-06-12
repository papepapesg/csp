<?php

namespace Modules\Notification\Dispatch;

/**
 * Thrown by ChannelAdapter::initialize() when the operator config is invalid or
 * required credentials are missing. The registry catches it, marks the adapter
 * UNUSABLE, and the dispatch records a PERMANENT_TEMPLATE-style configuration failure
 * (no auto-retry of initialization).
 */
class AdapterConfigurationException extends \RuntimeException {}
