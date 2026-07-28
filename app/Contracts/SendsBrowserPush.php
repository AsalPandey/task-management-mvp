<?php

namespace App\Contracts;

use App\ValueObjects\BrowserPushMessage;

interface SendsBrowserPush
{
    public function toBrowserPush(object $notifiable): BrowserPushMessage;
}
