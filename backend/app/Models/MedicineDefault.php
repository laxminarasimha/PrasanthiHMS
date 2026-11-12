<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['doctor_id', 'medicine', 'dose', 'frequency', 'timing', 'duration', 'instructions', 'uses'])]
class MedicineDefault extends Model {}
