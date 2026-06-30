<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;

class Lavage extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $table = 'lavages';

    protected $fillable = [
        'first_name',
        'last_name',
        'mobile',
        'email',
        'password',
        'role',
        'statut',
        'created_by'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => 'integer',
        'statut' => 'integer',
    ];

    // Méthode pour l'authentification par mobile
    public function findForPassport($mobile)
    {
        return $this->where('mobile', $mobile)->first();
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    // Accesseurs
    public function getFullNameAttribute()
    {
        return "{$this->first_name} {$this->last_name}";
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('statut', 1);
    }

    public function scopeInactive($query)
    {
        return $query->where('statut', 0);
    }

    public function scopeByRole($query, $role)
    {
        return $query->where('role', $role);
    }
}