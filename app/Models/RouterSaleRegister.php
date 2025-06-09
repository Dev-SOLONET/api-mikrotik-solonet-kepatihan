<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterSaleRegister extends Model
{
    use HasFactory;

    protected $table = 'routers_sale_registers';

    protected $connection = 'appsolon_app';
}
