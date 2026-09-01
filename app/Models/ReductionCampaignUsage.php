<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionCampaignUsage extends Model
{
    use HasFactory;

    protected $table = 'reduction_campaign_usages';

    protected $fillable = [
        'reduction_campaign_id',
        'user_reduction_card_id',
        'user_id',
        'campaign_name',
        'product_or_service',
        'discount_type',
        'discount_value',
        'normal_price',
        'promotional_price',
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
        'reduction_campaign_id' => 'integer',
        'user_reduction_card_id' => 'integer',
        'user_id' => 'integer',
        'discount_value' => 'decimal:2',
        'normal_price' => 'decimal:2',
        'promotional_price' => 'decimal:2',
        'montant_initial' => 'decimal:2',
        'montant_reduction' => 'decimal:2',
        'montant_final' => 'decimal:2',
        'applied_by_id' => 'integer',
        'establishment_id' => 'integer',
        'used_at' => 'datetime',
    ];

    public function campaign()
    {
        return $this->belongsTo(ReductionCampaign::class, 'reduction_campaign_id');
    }

    public function userReductionCard()
    {
        return $this->belongsTo(UserReductionCard::class, 'user_reduction_card_id');
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
