<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleRegister extends Model
{
    use HasFactory;

    protected $table = 'sale_register';

    protected $connection = 'appsolon_app';

    protected $fillable = [
        'status',
    ];

    public $timestamps = false;

    public function routerSaleRegister()
    {
        return $this->hasOne(RouterSaleRegister::class, 'id_register', 'id_register');
    }

    public function salePaketInternet()
    {
        return $this->hasOne(SalePaketInternet::class, 'id_paket', 'id_paket');
    }
}
