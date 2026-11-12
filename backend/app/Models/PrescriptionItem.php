<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['medicine', 'dose', 'frequency', 'timing', 'duration', 'instructions', 'position'])]
class PrescriptionItem extends Model
{
}
