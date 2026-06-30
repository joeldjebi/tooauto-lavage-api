<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrcodeAssignment extends Model
{
    protected $fillable = [
        'qrcode_id', 'assigned_at', 'lavage_id', 'station_de_lavage_id', 'user_id'
    ];

    protected $dates = ['assigned_at'];

    public function lavage()
    {
        return $this->belongsTo(Lavage::class);
    }
	
	
	public function station_de_lavage()
	{
		return $this->belongsTo(StationLavage::class);
	}

    public function qrcode()
    {
        return $this->belongsTo(QrcodeGenerate::class);
    }
	
	
	public function user()
	{
		return $this->belongsTo(User::class);
	}

}