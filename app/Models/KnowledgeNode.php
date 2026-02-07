<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Pgvector\Laravel\Vector;  

class KnowledgeNode extends Model
{
    use HasFactory;

   
    protected $fillable = [
        'content',
        'url',
        'embedding'
    ];

    // 2. Casteamos el vector para que Laravel lo entienda como array/vector
    // Si no instalaste pgvector-php, puedes omitir esto, pero es recomendado.
   // protected $casts = [
//        'embedding' => Vector::class, 
  //  ];


  
}