<?php

namespace Chatsys\Facades;

use Illuminate\Support\Facades\Facade;

class ChatsysMessenger extends Facade 
{

    protected static function getFacadeAccessor() 
    { 
       return 'ChatsysMessenger'; 
    }
}