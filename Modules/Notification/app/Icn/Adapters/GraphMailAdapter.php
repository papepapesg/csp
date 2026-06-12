<?php

namespace Modules\Notification\Icn\Adapters;

/** EMAIL via Microsoft Graph sendMail (Office 365 tenant). Used by YASSN. */
class GraphMailAdapter extends EmailAdapterBase
{
    public function adapterImplCode(): string
    {
        return 'microsoft-graph-mail';
    }
}
