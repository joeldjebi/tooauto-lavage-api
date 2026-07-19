<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Type_lavage extends Model
{
    use HasFactory;

	protected $table = 'type_lavages';

    protected $fillable = [
        'libelle',
        'montant',
        'lavage_id',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'lavage_id' => 'integer',
    ];

    public function lavage()
    {
        return $this->belongsTo(Lavage::class, 'lavage_id');
    }

}
