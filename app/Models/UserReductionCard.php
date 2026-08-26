<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserReductionCard extends Model
{
    use HasFactory;

    protected $table = 'user_reduction_cards';

    protected $guarded = [];

    protected $casts = [
        'date_debut' => 'date',
        'date_fin' => 'date',
        'statut' => 'integer',
    ];

    public function reductionCard()
    {
        return $this->belongsTo(ReductionCard::class, 'reduction_card_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function histories()
    {
        return $this->hasMany(ReductionCardHistory::class, 'user_reduction_card_id');
    }
}
