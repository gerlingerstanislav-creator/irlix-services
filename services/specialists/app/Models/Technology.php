<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class Technology extends Model {
    protected $table = 'technologies';
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];
}
