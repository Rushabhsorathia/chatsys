<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Chatsys\Traits\UUID;

class ChMessage extends Model
{
    use UUID;

    protected $casts = [
        'seen' => 'array'
    ];
}
