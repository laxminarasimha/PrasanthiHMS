<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\PastRecord;
use App\Models\Setting;
use App\Support\PastRecordDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The door the hospital's reading computer uses. That computer runs the reading program (Marker)
 * on scanned papers, because the web server cannot. It asks for the next paper, fetches its pages,
 * and hands back the text. It signs in with a key made under Setup, and can do nothing else.
 */
class RecordReaderController extends Controller
{
    /** A paper taken by the reading computer and not handed back in this time is offered again. */
    private const CLAIM_MINUTES = 15;

    /** Checks the key and nothing else, for setting the reading computer up. */
    public function hello(Request $request): JsonResponse
    {
        $this->knock($request);

        return response()->json(['ok' => true, 'waiting' => PastRecord::where('status', 'to_check')->where('read_state', 'queued')->count()]);
    }

    /** The next paper waiting to be read, if there is one. Calling this is also how the computer shows it is on. */
    public function next(Request $request): JsonResponse
    {
        $this->knock($request);

        $record = DB::transaction(function () {
            $record = PastRecord::where('status', 'to_check')
                ->where(fn ($q) => $q->where('read_state', 'queued')
                    ->orWhere(fn ($q) => $q->where('read_state', 'reading')->where('read_claimed_at', '<', now()->subMinutes(self::CLAIM_MINUTES))))
                ->oldest('id')->lockForUpdate()->first();
            $record?->update(['read_state' => 'reading', 'read_claimed_at' => now()]);

            return $record;
        });

        return response()->json($record ? [
            'id' => $record->id,
            'pages' => $record->pages->map(fn (Attachment $a) => ['id' => $a->id, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size])->values(),
        ] : ['id' => null]);
    }

    /** One page of the paper the reading computer is working on. Nothing else can be fetched this way. */
    public function page(Request $request, Attachment $attachment): StreamedResponse
    {
        $this->knock($request);
        abort_unless($attachment->pastRecord?->read_state === 'reading', 404, 'This page is not waiting to be read.');
        abort_unless(Storage::disk('local')->exists($attachment->path), 404, 'The file is missing.');

        return Storage::disk('local')->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime]);
    }

    /** Takes back what was read, or the reason it could not be read. */
    public function done(Request $request, PastRecord $record): JsonResponse
    {
        $this->knock($request);
        $data = $request->validate([
            'text' => ['nullable', 'string', 'max:400000'],
            'error' => ['nullable', 'string', 'max:500'],
        ]);

        // If a person had it read another way in the meantime, or filed it, the late answer is set aside.
        if ($record->read_state !== 'reading' || $record->status !== 'to_check') {
            return response()->json(['kept' => false]);
        }

        $text = trim((string) ($data['text'] ?? ''));
        if ($text === '') {
            $record->update(['read_state' => 'failed', 'read_error' => $data['error'] ?? 'Nothing could be read from this scan.', 'read_claimed_at' => null]);

            return response()->json(['kept' => true]);
        }

        $draft = PastRecordDraft::fromText($text);
        $record->update([
            'read_state' => 'done', 'read_engine' => 'marker', 'read_text' => $text, 'read_error' => null, 'read_at' => now(), 'read_claimed_at' => null,
            // A draft someone has already started on is not replaced.
            'draft' => $record->draft ?: $draft,
            'kind' => $record->kind === 'other' && $draft['kind'] ? $draft['kind'] : $record->kind,
            'record_date' => $record->record_date ?? $draft['record_date'],
            'doctor_name' => $record->doctor_name ?: ($draft['doctor_name'] ?: null),
            'hospital_name' => $record->hospital_name ?: ($draft['hospital_name'] ?: null),
        ]);

        return response()->json(['kept' => true]);
    }

    /** Lets the reading computer in if it carries the hospital's current key, and notes that it called. */
    private function knock(Request $request): void
    {
        $reader = Setting::find(PastRecordController::READER);
        $hash = $reader?->value['key_hash'] ?? null;
        abort_unless(filled($hash) && hash_equals($hash, hash('sha256', (string) $request->header('X-Reader-Key'))), 401, 'The reading key is not accepted.');

        $seen = $reader->value['seen_at'] ?? null;
        if (! $seen || $seen < now()->subSeconds(30)->format('Y-m-d H:i:s')) {
            $reader->update(['value' => array_merge($reader->value, ['seen_at' => now()->format('Y-m-d H:i:s')])]);
        }
    }
}
