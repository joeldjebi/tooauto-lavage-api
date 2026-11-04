<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepotArticlePaiement extends Model
{
    use HasFactory;

    protected $table = 'depot_article_paiements';

    protected $fillable = [
        'depot_article_id',
        'type',
        'montant',
        'mode_paiement',
        'operateur_paiement',
        'encaisse_par',
        'date_paiement',
    ];

    protected $casts = [
        'depot_article_id' => 'integer',
        'montant' => 'decimal:2',
        'date_paiement' => 'datetime',
    ];

    public function depot()
    {
        return $this->belongsTo(DepotArticle::class, 'depot_article_id');
    }
}
