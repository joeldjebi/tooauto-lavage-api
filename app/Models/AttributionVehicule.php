<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributionVehicule extends Model
{
    use HasFactory;

    protected $table = 'attribution_vehicules';

    protected $fillable = [
        'matricule_vehicule',
        'laveur_id',
        'manager_id',
        'type_lavage',
        'notes',
        'statut',
        'date_attribution',
        'date_debut',
        'date_fin',
        'station_lavage_id'
    ];

    protected $casts = [
        'date_attribution' => 'datetime',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime'
    ];

    // Constantes pour les statuts
    const STATUT_EN_COURS = 'en_cours';
    const STATUT_TERMINE = 'termine';
    const STATUT_ANNULE = 'annule';

    // Constantes pour les types de lavage
    const TYPE_INTERIEUR = 'interieur';
    const TYPE_EXTERIEUR = 'exterieur';
    const TYPE_COMPLET = 'complet';
    const TYPE_PREMIUM = 'premium';

    /**
     * Relation avec le laveur
     */
    public function laveur()
    {
        return $this->belongsTo(Lavage::class, 'laveur_id');
    }

    /**
     * Relation avec le manager
     */
    public function manager()
    {
        return $this->belongsTo(Lavage::class, 'manager_id');
    }

    /**
     * Relation avec le véhicule
     */
    public function vehicule()
    {
        return $this->belongsTo(Vehicule::class, 'matricule_vehicule', 'matricule');
    }

    /**
     * Vérifier si l'attribution est en cours
     */
    public function isEnCours()
    {
        return $this->statut === self::STATUT_EN_COURS;
    }

    /**
     * Vérifier si l'attribution est terminée
     */
    public function isTerminee()
    {
        return $this->statut === self::STATUT_TERMINE;
    }

    /**
     * Obtenir la durée du lavage en minutes
     */
    public function getDureeMinutes()
    {
        if (!$this->date_debut) {
            return 0;
        }

        $dateFin = $this->date_fin ?? now();
        return $this->date_debut->diffInMinutes($dateFin);
    }

    /**
     * Obtenir la durée formatée
     */
    public function getDureeFormatee()
    {
        $minutes = $this->getDureeMinutes();

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $heures = floor($minutes / 60);
        $minutesRestantes = $minutes % 60;

        if ($minutesRestantes === 0) {
            return $heures . 'h';
        }

        return $heures . 'h ' . $minutesRestantes . 'min';
    }

    /**
     * Obtenir le nom du type de lavage
     */
    public function getTypeLavageNameAttribute()
    {
        $types = [
            self::TYPE_INTERIEUR => 'Intérieur',
            self::TYPE_EXTERIEUR => 'Extérieur',
            self::TYPE_COMPLET => 'Complet',
            self::TYPE_PREMIUM => 'Premium'
        ];

        return $types[$this->type_lavage] ?? 'Non spécifié';
    }

    /**
     * Obtenir le nom du statut
     */
    public function getStatutNameAttribute()
    {
        $statuts = [
            self::STATUT_EN_COURS => 'En cours',
            self::STATUT_TERMINE => 'Terminé',
            self::STATUT_ANNULE => 'Annulé'
        ];

        return $statuts[$this->statut] ?? 'Inconnu';
    }

    /**
     * Scope pour les attributions en cours
     */
    public function scopeEnCours($query)
    {
        return $query->where('statut', self::STATUT_EN_COURS);
    }

    /**
     * Scope pour les attributions terminées
     */
    public function scopeTerminees($query)
    {
        return $query->where('statut', self::STATUT_TERMINE);
    }

    /**
     * Scope pour un laveur spécifique
     */
    public function scopeParLaveur($query, $laveurId)
    {
        return $query->where('laveur_id', $laveurId);
    }

    /**
     * Scope pour un véhicule spécifique
     */
    public function scopeParVehicule($query, $matricule)
    {
        return $query->where('matricule_vehicule', $matricule);
    }
}