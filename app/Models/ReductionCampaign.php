<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReductionCampaign extends Model
{
    use HasFactory;

    protected $table = 'reduction_campaigns';

    protected $fillable = [
        'establishment_type',
        'establishment_id',
        'name',
        'image',
        'description',
        'product_or_service',
        'discount_type',
        'discount_value',
        'normal_price',
        'promotional_price',
        'date_debut',
        'date_fin',
        'quantity_available',
        'quantity_used',
        'conditions',
        'statut',
        'created_by',
    ];

    protected $casts = [
        'establishment_id' => 'integer',
        'discount_value' => 'decimal:2',
        'normal_price' => 'decimal:2',
        'promotional_price' => 'decimal:2',
        'date_debut' => 'date',
        'date_fin' => 'date',
        'quantity_available' => 'integer',
        'quantity_used' => 'integer',
        'statut' => 'integer',
        'created_by' => 'integer',
    ];

    public function usages()
    {
        return $this->hasMany(ReductionCampaignUsage::class, 'reduction_campaign_id');
    }

    public function creator()
    {
        return $this->belongsTo(Lavage::class, 'created_by');
    }
}
