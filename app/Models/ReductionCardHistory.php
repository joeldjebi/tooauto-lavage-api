<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionCardHistory extends Model
{
    use HasFactory;

    protected $table = 'reduction_card_histories';

    protected $fillable = [
        'user_reduction_card_id',
        'reduction_card_id',
        'user_id',
        'abonnement_usager_id',
        'forfait_usager_id',
        'discount_type',
        'discount_value',
        'montant_initial',
        'montant_reduction',
        'montant_final',
        'applied_by_id',
        'establishment_type',
        'establishment_id',
        'notes',
        'used_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'montant_initial' => 'decimal:2',
        'montant_reduction' => 'decimal:2',
        'montant_final' => 'decimal:2',
        'used_at' => 'datetime',
    ];

    public function userReductionCard()
    {
        return $this->belongsTo(UserReductionCard::class, 'user_reduction_card_id');
    }

    public function reductionCard()
    {
        return $this->belongsTo(ReductionCard::class, 'reduction_card_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function appliedBy()
    {
        return $this->belongsTo(Lavage::class, 'applied_by_id');
    }
}
