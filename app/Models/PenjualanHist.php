<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenjualanHist extends Model
{
    use HasFactory;

    protected $table = 'penjualan_hist';
    protected $primaryKey = 'id_history';
    protected $guarded = [];

    // public function member()
    // {
    //     return $this->hasOne(Member::class, 'id_member', 'id_member');
    // }

    public function user()
    {
        return $this->hasOne(User::class, 'id_user', 'id_user');
    }
}
