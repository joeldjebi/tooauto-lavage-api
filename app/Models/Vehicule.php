<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vehicule extends Model
{
    use HasFactory;

    protected $fillable = [
        'matricule',
        'marque_id',
        'modele',
        'user_id'
    ];

    protected $casts = [
        'photos' => 'array',
    ];

    /**
     * Relation avec l'usager propriétaire
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec la marque
     */
    public function marque()
    {
        return $this->belongsTo(Marque::class);
    }
}
