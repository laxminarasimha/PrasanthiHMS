<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['medicine_id', 'medicine_name', 'units_per_pack', 'packs'])]
class PurchaseOrderItem extends Model
{
}
