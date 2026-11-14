<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One entry of the ICD-10 diagnosis list, e.g. E11.9 "Type 2 diabetes mellitus without complications". */
class IcdCode extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
