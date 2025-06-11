<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use HasFactory;

    protected $fillable = [
        'id_register',
        'ip',
        'user_agent',
        'url',
        'request',
        'response',
        'status_code',
    ];
}
