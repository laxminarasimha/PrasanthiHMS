<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['direction', 'name', 'specialization', 'phone', 'place'])]
class ReferralDoctor extends Model {}
