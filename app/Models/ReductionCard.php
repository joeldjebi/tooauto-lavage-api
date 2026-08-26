<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionCard extends Model
{
    use HasFactory;

    protected $table = 'reduction_cards';

    protected $guarded = [];

    protected $casts = [
        'statut' => 'integer',
        'discount_value' => 'decimal:2',
    ];

    public function userCards()
    {
        return $this->hasMany(UserReductionCard::class, 'reduction_card_id');
    }
}
