<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalePaketInternet extends Model
{
    use HasFactory;

    protected $table = 'sale_paket_internet';
    
    protected $connection = 'appsolon_app';
}
