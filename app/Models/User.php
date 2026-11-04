<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'indicatif',
        'mobile',
        'nom',
        'prenoms'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * Usager réel : s'est inscrit ou connecté au moins une fois sur l'app usager
     */
    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Scope pour les usagers réels
     */
    public function scopeActifs($query)
    {
        return $query->whereNotNull('activated_at');
    }

    /**
     * Scope pour les clients créés par un lavage, pas encore devenus usagers
     */
    public function scopeClientsLavageNonActives($query)
    {
        return $query->whereNotNull('lavage_id')->whereNull('activated_at');
    }

    /**
     * Relation avec les véhicules
     */
    public function vehicules()
    {
        return $this->hasMany(Vehicule::class);
    }

    /**
     * Relation avec les récompenses
     */
    public function recompenses()
    {
        return $this->hasMany(Recompense::class, 'usager_id');
    }

    /**
     * Obtenir le numéro de téléphone complet
     */
    public function getTelephoneCompletAttribute()
    {
        return $this->indicatif . $this->mobile;
    }

    /**
     * Rechercher un usager par numéro de téléphone complet
     */
    public static function findByTelephone($telephone)
    {
        return self::whereRaw("CONCAT(indicatif, mobile) = ?", [$telephone])->first();
    }

    /**
     * Rechercher un usager par matricule de véhicule
     */
    public static function findByMatricule($matricule)
    {
        return self::whereHas('vehicules', function($query) use ($matricule) {
            $query->where('matricule', $matricule);
        })->first();
    }
}
