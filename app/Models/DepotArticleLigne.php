<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepotArticleLigne extends Model
{
    use HasFactory;

    protected $table = 'depot_article_lignes';

    protected $fillable = [
        'depot_article_id',
        'type_article_id',
        'libelle',
        'description',
        'quantite',
        'unite',
        'prix_unitaire',
        'montant_laveur_unitaire',
        'montant',
    ];

    protected $casts = [
        'depot_article_id' => 'integer',
        'type_article_id' => 'integer',
        'quantite' => 'decimal:2',
        'prix_unitaire' => 'decimal:2',
        'montant_laveur_unitaire' => 'decimal:2',
        'montant' => 'decimal:2',
    ];

    public function depot()
    {
        return $this->belongsTo(DepotArticle::class, 'depot_article_id');
    }

    public function typeArticle()
    {
        return $this->belongsTo(TypeArticle::class, 'type_article_id');
    }
}
