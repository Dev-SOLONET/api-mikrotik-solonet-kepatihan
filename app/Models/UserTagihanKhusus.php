<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserTagihanKhusus extends Model
{
    use HasFactory;

    protected $table = 'user_tagihan_khusus';

    protected $connection = 'appsolon_app';
}
