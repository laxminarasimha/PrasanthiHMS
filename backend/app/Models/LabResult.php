<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['parameter', 'unit', 'reference_range', 'method', 'value', 'flag', 'position'])]
class LabResult extends Model
{
}
