<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepotArticle extends Model
{
    use HasFactory;

    protected $table = 'depot_articles';

    protected $fillable = [
        'numero_ticket',
        'station_lavage_id',
        'manager_id',
        'user_id',
        'client_nom',
        'client_mobile',
        'statut',
        'date_depot',
        'date_retrait_prevue',
        'date_retrait',
        'montant_total',
        'montant_paye',
        'notes',
    ];

    protected $casts = [
        'station_lavage_id' => 'integer',
        'date_depot' => 'datetime',
        'date_retrait_prevue' => 'datetime',
        'date_retrait' => 'datetime',
        'montant_total' => 'decimal:2',
        'montant_paye' => 'decimal:2',
    ];

    // Constantes pour les statuts
    const STATUT_RECU = 'recu';
    const STATUT_EN_COURS = 'en_cours';
    const STATUT_PRET = 'pret';
    const STATUT_LIVRE = 'livre';
    const STATUT_ANNULE = 'annule';

    const STATUTS = [
        self::STATUT_RECU => 'Reçu',
        self::STATUT_EN_COURS => 'En cours',
        self::STATUT_PRET => 'Prêt',
        self::STATUT_LIVRE => 'Livré',
        self::STATUT_ANNULE => 'Annulé',
    ];

    public function lignes()
    {
        return $this->hasMany(DepotArticleLigne::class, 'depot_article_id');
    }

    public function paiements()
    {
        return $this->hasMany(DepotArticlePaiement::class, 'depot_article_id')->orderBy('date_paiement');
    }

    public function laveurs()
    {
        return $this->belongsToMany(Lavage::class, 'depot_article_laveur', 'depot_article_id', 'laveur_id')
            ->withTimestamps();
    }

    public function stationLavage()
    {
        return $this->belongsTo(StationLavage::class, 'station_lavage_id');
    }

    public function manager()
    {
        return $this->belongsTo(Lavage::class, 'manager_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getResteAPayerAttribute(): float
    {
        return max(0, round((float) $this->montant_total - (float) $this->montant_paye, 2));
    }

    public function getStatutPaiementAttribute(): string
    {
        if ((float) $this->montant_paye <= 0) {
            return 'non_paye';
        }

        return $this->reste_a_payer > 0 ? 'partiel' : 'paye';
    }

    /**
     * Gain total d'un laveur sur ce dépôt (chaque laveur touche le montant laveur des lignes)
     */
    public function getMontantLaveurAttribute(): float
    {
        return (float) $this->lignes->sum(fn ($ligne) => (float) $ligne->montant_laveur_unitaire * (float) $ligne->quantite);
    }

    public function isModifiable(): bool
    {
        return !in_array($this->statut, [self::STATUT_LIVRE, self::STATUT_ANNULE], true);
    }
}
