<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TypeArticle extends Model
{
    use HasFactory;

    protected $table = 'type_articles';

    protected $fillable = [
        'station_lavage_id',
        'libelle',
        'prix',
        'montant_laveur',
        'unite',
        'statut',
    ];

    protected $casts = [
        'station_lavage_id' => 'integer',
        'prix' => 'decimal:2',
        'montant_laveur' => 'decimal:2',
        'statut' => 'boolean',
    ];

    public function stationLavage()
    {
        return $this->belongsTo(StationLavage::class, 'station_lavage_id');
    }
}
