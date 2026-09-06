<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AbonnementUsager extends Model
{
    use HasFactory;

    protected $table = 'abonnement_usagers';

    protected $fillable = [
        'user_id',
        'forfait_id',
        'date_debut',
        'date_fin',
        'statut',
        'is_free',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'forfait_id' => 'integer',
        'date_debut' => 'date',
        'date_fin' => 'date',
        'statut' => 'integer',
        'is_free' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function forfait()
    {
        return $this->belongsTo(Forfait_usager::class, 'forfait_id');
    }
}
