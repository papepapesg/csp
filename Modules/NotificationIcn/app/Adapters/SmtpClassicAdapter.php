<?php

namespace Modules\Notification\Icn\Adapters;

/** EMAIL via a classic SMTP relay (Jakarta-Mail-equivalent). Used by WIK/WUG/WTZ. */
class SmtpClassicAdapter extends EmailAdapterBase
{
    public function adapterImplCode(): string
    {
        return 'smtp-classic';
    }
}
