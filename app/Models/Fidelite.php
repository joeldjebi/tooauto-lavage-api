<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Fidelite extends Model
{
    use HasFactory;

    protected $table = 'fidelites';

    protected $fillable = [
        'usager_id',
        'lavage_id',
        'matricule_vehicule',
        'cases_remplies',
        'total_cases',
        'recompenses_gagnees',
        'derniere_recompense',
        'station_lavage_id'
    ];

    protected $casts = [
        'cases_remplies' => 'integer',
        'total_cases' => 'integer',
        'recompenses_gagnees' => 'integer',
        'derniere_recompense' => 'datetime'
    ];

    public function usager()
    {
        return $this->belongsTo(User::class, 'usager_id');
    }

    public function lavage()
    {
        return $this->belongsTo(User::class, 'lavage_id');
    }

    public function vehicule()
    {
        return $this->belongsTo(Vehicule::class, 'matricule_vehicule', 'matricule');
    }

    /**
     * Vérifie si la carte de fidélité est complète
     */
    public function isCarteComplete()
    {
        return $this->cases_remplies >= $this->total_cases;
    }

    /**
     * Retourne le pourcentage de progression
     */
    public function getProgressionPourcentage()
    {
        return round(($this->cases_remplies / $this->total_cases) * 100, 2);
    }

    /**
     * Retourne le nombre de cases restantes
     */
    public function getCasesRestantes()
    {
        return max(0, $this->total_cases - $this->cases_remplies);
    }
}