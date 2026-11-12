<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'unit', 'reference_range', 'method', 'position'])]
class LabTestParameter extends Model
{
}
