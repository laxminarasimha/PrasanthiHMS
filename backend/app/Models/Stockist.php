<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'phone', 'email', 'gstin', 'address', 'lead_days', 'credit_days'])]
class Stockist extends Model
{
}
