<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Recompense extends Model
{
    use HasFactory;

    protected $table = 'recompenses';

    protected $fillable = [
        'usager_id',
        'lavage_id',
        'matricule_vehicule',
        'type_recompense',
        'description',
        'valeur',
        'statut',
        'date_attribution',
        'date_utilisation',
        'utilisee'
    ];

    protected $casts = [
        'date_attribution' => 'datetime',
        'date_utilisation' => 'datetime',
        'utilisee' => 'boolean',
        'valeur' => 'decimal:2'
    ];

    /**
     * Types de récompenses disponibles
     */
    const TYPES = [
        'lavage_gratuit' => 'Lavage gratuit',
        'reduction_50' => 'Réduction 50%',
        'reduction_25' => 'Réduction 25%',
        'bonus_points' => 'Bonus points',
        'service_premium' => 'Service premium',
        'cadeau' => 'Cadeau'
    ];

    /**
     * Statuts des récompenses
     */
    const STATUTS = [
        'attribuee' => 'Attribuée',
        'utilisee' => 'Utilisée',
        'expiree' => 'Expirée',
        'annulee' => 'Annulée'
    ];

    /**
     * Relation avec l'usager
     */
    public function usager()
    {
        return $this->belongsTo(User::class, 'usager_id');
    }

    /**
     * Relation avec le lavage
     */
    public function lavage()
    {
        return $this->belongsTo(User::class, 'lavage_id');
    }

    /**
     * Relation avec le véhicule
     */
    public function vehicule()
    {
        return $this->belongsTo(Vehicule::class, 'matricule_vehicule', 'matricule');
    }

    /**
     * Vérifier si la récompense est utilisable
     */
    public function isUtilisable()
    {
        return $this->statut === 'attribuee' && !$this->utilisee;
    }

    /**
     * Marquer la récompense comme utilisée
     */
    public function utiliser()
    {
        $this->update([
            'statut' => 'utilisee',
            'utilisee' => true,
            'date_utilisation' => now()
        ]);
    }

    /**
     * Obtenir le nom du type de récompense
     */
    public function getTypeNameAttribute()
    {
        return self::TYPES[$this->type_recompense] ?? 'Inconnu';
    }

    /**
     * Obtenir le nom du statut
     */
    public function getStatutNameAttribute()
    {
        return self::STATUTS[$this->statut] ?? 'Inconnu';
    }
}
