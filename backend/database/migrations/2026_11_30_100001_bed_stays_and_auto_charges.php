<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every bed a patient occupies during a stay, with when they moved in and out, and what is needed to add
 * each day's bed charge to the bill automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bed_id')->nullable()->constrained()->nullOnDelete();
            // Kept as they were at the time, so the history still reads right if a bed is renamed or removed.
            $table->string('ward');
            $table->string('bed_no', 20);
            $table->dateTime('from_at');
            $table->dateTime('to_at')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['admission_id', 'from_at']);
            $table->index(['bed_id', 'from_at']);
        });

        Schema::table('admissions', function (Blueprint $table) {
            // Off for a patient on a package, or whose bed is charged some other way.
            $table->boolean('auto_bed_charge')->default(true)->after('bed_id');
            // How many days of the stay have been dealt with: charged, or deliberately left alone.
            $table->unsignedInteger('bed_days_done')->default(0)->after('auto_bed_charge');
        });

        // Stays so far: the one bed each is known to have had. Their days up to now are marked as dealt with,
        // so switching automatic charges on later never bills past days a second time.
        $now = Carbon::now();
        foreach (DB::table('admissions')->orderBy('id')->get(['id', 'bed_id', 'admitted_at', 'discharged_at']) as $admission) {
            $end = $admission->discharged_at ? Carbon::parse($admission->discharged_at) : $now;
            $hours = max(0, Carbon::parse($admission->admitted_at)->diffInMinutes($end) / 60);
            DB::table('admissions')->where('id', $admission->id)->update(['bed_days_done' => max(1, (int) ceil($hours / 24))]);

            $bed = $admission->bed_id ? DB::table('beds')->where('id', $admission->bed_id)->first(['ward', 'bed_no']) : null;
            if ($bed) {
                DB::table('admission_beds')->insert([
                    'admission_id' => $admission->id, 'bed_id' => $admission->bed_id, 'ward' => $bed->ward, 'bed_no' => $bed->bed_no,
                    'from_at' => $admission->admitted_at, 'to_at' => $admission->discharged_at, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn(['auto_bed_charge', 'bed_days_done']));
        Schema::dropIfExists('admission_beds');
    }
};
