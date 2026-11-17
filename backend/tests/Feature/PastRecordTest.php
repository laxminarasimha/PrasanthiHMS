<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LabTest;
use App\Models\PastRecord;
use App\Models\Patient;
use App\Models\PatientHealthItem;
use App\Models\User;
use App\Support\AiReader;
use App\Support\PastRecordDraft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PastRecordTest extends TestCase
{
    use RefreshDatabase;

    private const LAB_REPORT = <<<'TEXT'
        # City Diagnostics, Vijayawada

        Patient: Mrs Lakshmi Devi   Ref. by: Dr. K Suresh Kumar
        Collected Date: 14/03/2019

        | Test | Result | Units | Reference Range |
        |------|--------|-------|-----------------|
        | **Complete Blood Count** | | | |
        | Haemoglobin | 9.8 | g/dL | 12.0 - 15.0 |
        | Total WBC Count | 7,400 | cells/cumm | 4000 - 11000 |
        | Fasting Blood Sugar | 142 | mg/dL | 70 - 100 |

        Impression: Mild anaemia
        TEXT;

    private function scan(Patient $patient, array $more = []): int
    {
        return $this->post("/api/patients/{$patient->id}/past-records", ['files' => [UploadedFile::fake()->create('report.pdf', 200, 'application/pdf')]] + $more, ['Accept' => 'application/json'])
            ->assertCreated()->json('records.0.id');
    }

    public function test_front_desk_scans_papers_and_they_wait_to_be_checked(): void
    {
        Storage::fake('local');
        $patient = Patient::factory()->create();

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson("/api/patients/{$patient->id}/past-records")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk', 'name' => 'Sita']));
        $this->post("/api/patients/{$patient->id}/past-records", ['files' => [UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream')]], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('files.0');

        // Two files scanned separately are two papers; the same two as one paper are its pages.
        $files = fn () => [UploadedFile::fake()->image('page1.jpg', 800, 1100), UploadedFile::fake()->create('page2.pdf', 200, 'application/pdf')];
        $this->post("/api/patients/{$patient->id}/past-records", ['files' => $files()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(2, 'records')->assertJsonPath('records.0.status', 'to_check')->assertJsonPath('records.0.read_state', 'none');
        $this->post("/api/patients/{$patient->id}/past-records", ['files' => $files(), 'one_paper' => '1', 'kind' => 'discharge', 'record_date' => '2018-06-02'], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(3, 'records');

        $paper = PastRecord::latest('id')->first();
        $this->assertSame(['discharge', '2018-06-02', 2], [$paper->kind, $paper->record_date->toDateString(), $paper->pages()->count()]);
        Storage::disk('local')->assertExists($paper->pages->first()->path);

        // The pages are not mixed into the patient's ordinary attachments, and cannot be removed one by one.
        $this->getJson("/api/patients/{$patient->id}/attachments")->assertOk()->assertJsonCount(0);
        $this->deleteJson("/api/attachments/{$paper->pages->first()->id}")->assertStatus(409);

        $this->getJson('/api/past-records')->assertOk()->assertJsonCount(3, 'records')->assertJsonPath('counts.to_check', 3)->assertJsonPath('records.0.patient.id', $patient->id);
        $this->assertTrue(AuditLog::where('action', 'past.scanned')->where('patient_id', $patient->id)->exists());

        // Scanned by mistake: the paper and its files go.
        $path = $paper->pages->first()->path;
        $this->deleteJson("/api/past-records/{$paper->id}")->assertOk();
        Storage::disk('local')->assertMissing($path);
        $this->assertSame(2, PastRecord::count());
    }

    public function test_the_reading_computer_fetches_a_scan_and_hands_back_a_draft(): void
    {
        Storage::fake('local');
        $patient = Patient::factory()->create(['name' => 'Lakshmi Devi']);
        $desk = User::factory()->create(['role' => 'frontdesk']);

        // Only setup staff make the key, and without it the door stays shut.
        Sanctum::actingAs($desk);
        $this->postJson('/api/past-records/setup/reader-key', [])->assertForbidden();
        $this->postJson('/api/reader/next', [])->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $key = $this->postJson('/api/past-records/setup/reader-key', [])->assertOk()->assertJsonPath('marker.set_up', true)->assertJsonPath('marker.online', false)->json('key');
        $this->assertSame(48, strlen($key));

        Sanctum::actingAs($desk);
        $id = $this->scan($patient);
        $this->assertSame('queued', PastRecord::find($id)->read_state);
        $pageId = PastRecord::find($id)->pages->first()->id;

        $reader = ['X-Reader-Key' => $key];
        $this->postJson('/api/reader/next', [], ['X-Reader-Key' => 'wrong'])->assertUnauthorized();
        // A page cannot be fetched until its paper has been handed to the reading computer.
        $this->get("/api/reader/pages/{$pageId}", $reader)->assertNotFound();
        $this->postJson('/api/reader/next', [], $reader)->assertOk()->assertJsonPath('id', $id)->assertJsonPath('pages.0.id', $pageId);
        $this->postJson('/api/reader/next', [], $reader)->assertOk()->assertJsonPath('id', null);
        $this->get("/api/reader/pages/{$pageId}", $reader)->assertOk();
        $this->getJson('/api/past-records/setup')->assertForbidden();

        // The text comes wrapped, so the web host's firewall does not mistake a page of medical text for an attack.
        $this->postJson("/api/reader/records/{$id}/done", ['text_b64' => 'not base64 !!'], $reader)->assertStatus(422);
        $this->postJson("/api/reader/records/{$id}/done", ['text_b64' => base64_encode(self::LAB_REPORT)], $reader)->assertOk()->assertJsonPath('kept', true);
        $this->get("/api/reader/pages/{$pageId}", $reader)->assertNotFound();

        $paper = $this->getJson("/api/past-records/{$id}")->assertOk()->assertJsonPath('read_state', 'done')->assertJsonPath('read_engine', 'marker')
            ->assertJsonPath('kind', 'lab')->assertJsonPath('record_date', '2019-03-14')->assertJsonPath('doctor_name', 'Dr. K Suresh Kumar')->assertJsonPath('hospital_name', 'City Diagnostics, Vijayawada')
            ->assertJsonPath('reading.marker.online', true)->assertJsonPath('name_on_paper', null)->json();
        $this->assertStringContainsString('City Diagnostics', $paper['read_text']);
        $this->assertSame('Mild anaemia', $paper['draft']['diagnosis']);
        $this->assertSame(
            [['Complete Blood Count', 'Haemoglobin', '9.8', 'g/dL', '12.0 - 15.0'], ['Complete Blood Count', 'Total WBC Count', '7,400', 'cells/cumm', '4000 - 11000'], ['Complete Blood Count', 'Fasting Blood Sugar', '142', 'mg/dL', '70 - 100']],
            array_map(fn ($r) => [$r['test'], $r['parameter'], $r['value'], $r['unit'], $r['reference_range']], $paper['draft']['results']),
        );

        // A second answer for a paper no longer being read is set aside, and so is nothing at all.
        $this->postJson("/api/reader/records/{$id}/done", ['text' => 'something else'], $reader)->assertOk()->assertJsonPath('kept', false);
        $this->assertStringContainsString('City Diagnostics', PastRecord::find($id)->read_text);

        $this->postJson("/api/past-records/{$id}/read", ['engine' => 'marker'])->assertOk()->assertJsonPath('read_state', 'queued');
        $this->postJson('/api/reader/next', [], $reader)->assertJsonPath('id', $id);
        $this->postJson("/api/reader/records/{$id}/done", ['error' => 'The scan is blank.'], $reader)->assertJsonPath('kept', true);
        $this->getJson("/api/past-records/{$id}")->assertJsonPath('read_state', 'failed')->assertJsonPath('read_error', 'The scan is blank.');

        // Switching the reading computer off stops its key.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->deleteJson('/api/past-records/setup/reader-key')->assertOk()->assertJsonPath('marker.set_up', false);
        $this->postJson('/api/reader/next', [], $reader)->assertUnauthorized();
    }

    public function test_a_checked_paper_is_filed_into_the_history_the_results_and_the_health_summary(): void
    {
        Storage::fake('local');
        $patient = Patient::factory()->create(['allergies' => 'Sulfa']);
        $cbc = LabTest::create(['name' => 'Complete Blood Picture', 'price' => 300]);
        $cbc->parameters()->create(['name' => 'Haemoglobin', 'unit' => 'g/dL']);
        $cbc->parameters()->create(['name' => 'Platelet Count', 'unit' => 'lakhs/cumm']);
        PatientHealthItem::create(['patient_id' => $patient->id, 'kind' => 'condition', 'title' => 'Hypertension', 'is_active' => true]);

        $nurse = User::factory()->create(['role' => 'nurse', 'name' => 'Nurse Padma']);
        Sanctum::actingAs($nurse);
        $id = $this->scan($patient);

        // Half-way: kept as a draft, and nothing shows in the patient's history yet.
        $this->putJson("/api/past-records/{$id}", ['kind' => 'lab', 'record_date' => null, 'draft' => ['diagnosis' => 'Anaemia', 'results' => [['parameter' => 'Haemoglobin', 'value' => '9.8']], 'made_up' => 'x']])
            ->assertOk()->assertJsonPath('draft.diagnosis', 'Anaemia')->assertJsonPath('draft.results.0.value', '9.8')->assertJsonMissingPath('draft.made_up');
        $this->getJson("/api/patients/{$patient->id}/case-history")->assertOk()->assertJsonCount(0, 'events')->assertJsonPath('papers_waiting', 1);

        $filing = [
            'kind' => 'lab', 'record_date' => '2019-03-14', 'hospital_name' => 'City Diagnostics', 'doctor_name' => 'Dr. K Suresh Kumar',
            'diagnosis' => 'Mild anaemia', 'notes' => '',
            'medicines' => [['name' => 'Tab Orofer XT', 'frequency' => '0-1-0', 'duration' => '3 months']],
            'results' => [
                ['test' => 'CBC', 'parameter' => 'haemoglobin', 'value' => '9.8', 'unit' => 'g/dL', 'reference_range' => '12.0 - 15.0', 'flag' => 'low'],
                ['test' => 'CBC', 'parameter' => 'Platelet Count', 'value' => '250', 'unit' => 'x10^3/uL'],
                ['test' => '', 'parameter' => 'Fasting Blood Sugar', 'value' => '142', 'unit' => 'mg/dL', 'flag' => 'high'],
            ],
            'facts' => [['kind' => 'condition', 'title' => 'hypertension'], ['kind' => 'condition', 'title' => 'Type 2 diabetes', 'since' => '2015'], ['kind' => 'surgery', 'title' => 'Appendicectomy', 'since' => '2004']],
            'allergies' => 'Penicillin',
        ];
        $this->postJson("/api/past-records/{$id}/file", ['record_date' => now()->addDay()->toDateString()] + $filing)->assertStatus(422)->assertJsonValidationErrors('record_date');
        $this->postJson("/api/past-records/{$id}/file", ['results' => [['parameter' => 'Hb', 'value' => '']]] + $filing)->assertStatus(422)->assertJsonValidationErrors('results.0.value');
        $this->postJson("/api/past-records/{$id}/file", $filing)->assertOk()->assertJsonPath('status', 'filed')->assertJsonPath('checker.name', 'Nurse Padma')
            ->assertJsonPath('details.diagnosis', 'Mild anaemia')->assertJsonPath('details.notes', null)->assertJsonPath('details.medicines.0.name', 'Tab Orofer XT')->assertJsonPath('details.medicines.0.dose', null);

        // A value the hospital's own lab also measures, in the same unit, takes the hospital's test name. A different unit does not.
        $this->assertSame(
            [['Complete Blood Picture', 'haemoglobin'], ['CBC', 'Platelet Count'], ['Outside report', 'Fasting Blood Sugar']],
            PastRecord::find($id)->results->map(fn ($r) => [$r->test_name, $r->parameter])->all(),
        );

        // Health summary: the fact already there is not doubled; the new ones say where they came from. The allergy is added to the old one.
        $this->assertSame(['Hypertension', 'Type 2 diabetes', 'Appendicectomy'], PatientHealthItem::where('patient_id', $patient->id)->orderBy('id')->pluck('title')->all());
        $diabetes = PatientHealthItem::where('title', 'Type 2 diabetes')->first();
        $this->assertSame([$id, '2015', 'From a paper dated 14 Mar 2019, City Diagnostics'], [$diabetes->past_record_id, $diabetes->since, $diabetes->detail]);
        $this->assertSame('Sulfa, Penicillin', $patient->fresh()->allergies);

        // The scan itself now carries the paper's date.
        $this->assertSame('2019-03-14', PastRecord::find($id)->pages->first()->document_date->toDateString());

        // The doctor sees it in the case history, marked as an old paper, with the scan one step away.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $history = $this->getJson("/api/patients/{$patient->id}/case-history")->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('papers_waiting', 0)->json('events.0');
        $this->assertSame(['past', '2019-03-14 00:00', 'Lab report', 'City Diagnostics', 'Nurse Padma'], [$history['kind'], $history['at'], $history['past']['kind_label'], $history['past']['hospital_name'], $history['past']['checked_by']]);
        $this->assertCount(3, $history['past']['results']);
        $this->assertCount(1, $history['past']['pages']);
        $this->get("/api/attachments/{$history['past']['pages'][0]['id']}/download")->assertOk();

        $trend = collect($this->getJson("/api/patients/{$patient->id}/trends")->assertOk()->json('tests'))->keyBy('parameter');
        $this->assertSame(['Complete Blood Picture', '2019-03-14', 9.8, 'low', null, 'Old paper · City Diagnostics', $id], [
            $trend['haemoglobin']['test'], $trend['haemoglobin']['points'][0]['date'], $trend['haemoglobin']['points'][0]['number'], $trend['haemoglobin']['points'][0]['flag'],
            $trend['haemoglobin']['points'][0]['order_id'], $trend['haemoglobin']['points'][0]['order_no'], $trend['haemoglobin']['points'][0]['past_record_id'],
        ]);

        // Filing again corrects it: the values are replaced, nothing is doubled.
        Sanctum::actingAs($nurse);
        $this->putJson("/api/past-records/{$id}", ['kind' => 'lab', 'draft' => []])->assertStatus(409);
        $this->postJson("/api/past-records/{$id}/read", ['engine' => 'ai'])->assertStatus(409);
        $this->postJson("/api/past-records/{$id}/file", ['results' => [['parameter' => 'Haemoglobin', 'value' => '9.6', 'unit' => 'g/dL']]] + $filing)->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.value', '9.6');
        $this->assertSame(3, PatientHealthItem::where('patient_id', $patient->id)->count());
        $this->assertSame('Sulfa, Penicillin', $patient->fresh()->allergies);
        $this->assertSame(['past.filed', 'past.corrected'], AuditLog::whereIn('action', ['past.filed', 'past.corrected'])->orderBy('id')->pluck('action')->all());
        $this->assertStringContainsString('Lab report dated 14 Mar 2019, 3 test values, 1 medicine', AuditLog::where('action', 'past.filed')->first()->summary);
    }

    public function test_a_hard_page_is_read_by_the_ai_service_only_when_asked_and_every_send_is_recorded(): void
    {
        Storage::fake('local');
        $patient = Patient::factory()->create(['name' => 'Lakshmi Devi']);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->post("/api/patients/{$patient->id}/past-records", ['files' => [UploadedFile::fake()->image('rx.jpg', 600, 800), UploadedFile::fake()->create('back.pdf', 50, 'application/pdf')], 'one_paper' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $id = PastRecord::first()->id;

        // Not set up: nothing is sent, and nothing is recorded as sent.
        config(['services.anthropic.key' => null]);
        $this->getJson("/api/past-records/{$id}")->assertJsonPath('reading.ai.set_up', false);
        $this->postJson("/api/past-records/{$id}/read", ['engine' => 'ai'])->assertStatus(422)->assertJsonValidationErrors('engine');
        $this->assertNull(PastRecord::find($id)->ai_sent_at);

        config(['services.anthropic.key' => 'test-key-not-real']);
        $reading = [
            'kind' => 'visit', 'record_date' => '2021-11-05', 'doctor_name' => 'Dr. P Anitha', 'hospital_name' => 'Sri Sai Clinic', 'patient_name' => 'Ramesh Babu',
            'complaints' => 'Burning in the upper abdomen for 2 weeks', 'diagnosis' => 'Acid peptic disease', 'findings' => 'BP 130/80', 'advice' => 'Review after 2 weeks', 'notes' => '', 'allergies' => '',
            'medicines' => [['name' => 'Tab Pantocid 40 [?]', 'dose' => '', 'frequency' => '1-0-0', 'duration' => '14 days', 'instructions' => 'before food']],
            'results' => [], 'facts' => [['kind' => 'habit', 'title' => 'Smoker', 'since' => ''], ['kind' => 'not_a_kind', 'title' => 'x', 'since' => '']],
            'unsure' => ['Medicine 1: name may be "Pantocid" or "Pantodac"'], 'text' => "Sri Sai Clinic\nRamesh Babu 05/11/2021\nTab Pantocid 40 1-0-0 x 14 days",
        ];
        $sent = [];
        $replies = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => AiReader::MODEL, 'stop_reason' => 'end_turn', 'stop_sequence' => null,
                'content' => [['type' => 'text', 'text' => json_encode($reading)]], 'usage' => ['input_tokens' => 3120, 'output_tokens' => 640],
            ])),
            new Response(401, ['Content-Type' => 'application/json'], json_encode(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']])),
        ]);
        $stack = HandlerStack::create($replies);
        $stack->push(Middleware::history($sent));
        $this->app->bind(AiReader::class, fn () => new AiReader(new Client(['handler' => $stack])));

        $paper = $this->postJson("/api/past-records/{$id}/read", ['engine' => 'ai'])->assertOk()->assertJsonPath('read_state', 'done')->assertJsonPath('read_engine', 'ai')
            ->assertJsonPath('kind', 'visit')->assertJsonPath('record_date', '2021-11-05')->assertJsonPath('hospital_name', 'Sri Sai Clinic')
            // The paper is in another person's name, which the checker is told.
            ->assertJsonPath('name_on_paper', 'Ramesh Babu')
            ->assertJsonPath('draft.medicines.0.name', 'Tab Pantocid 40 [?]')->assertJsonPath('draft.unsure.0', 'Medicine 1: name may be "Pantocid" or "Pantodac"')
            ->assertJsonCount(1, 'draft.facts')->assertJsonPath('status', 'to_check')->json();
        $this->assertStringContainsString('Tab Pantocid 40 1-0-0', $paper['read_text']);
        $this->assertNotNull($paper['ai_sent_at']);

        // What went out: the hospital's key, the chosen model, both pages, and nothing about the patient beyond the pages.
        $request = $sent[0]['request'];
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('Bearer test-key-not-real', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame([AiReader::MODEL, 'default', 'high', 'json_schema'], [$body['model'], $body['fallbacks'], $body['output_config']['effort'], $body['output_config']['format']['type']]);
        $this->assertSame(['image', 'document', 'text'], array_column($body['messages'][0]['content'], 'type'));
        $this->assertSame(['base64', 'image/jpeg'], [$body['messages'][0]['content'][0]['source']['type'], $body['messages'][0]['content'][0]['source']['media_type']]);
        $this->assertSame('application/pdf', $body['messages'][0]['content'][1]['source']['media_type']);
        $this->assertStringNotContainsString('Lakshmi', json_encode([$body['system'], $body['messages'][0]['content'][2]]));
        $this->assertStringContainsString('2 pages sent to the AI reading service and read (3,120 in, 640 out)', AuditLog::where('action', 'past.ai_read')->first()->summary);

        // The service turns the key down: the person is told in plain words, the draft stays, and the send is still on record.
        $this->postJson("/api/past-records/{$id}/read", ['engine' => 'ai'])->assertStatus(422)->assertJsonPath('errors.engine.0', 'The AI reading service did not accept the hospital’s key. Ask the admin to check it.');
        $this->assertSame('Acid peptic disease', PastRecord::find($id)->draft['diagnosis']);
        $this->assertStringContainsString('no reading came back', AuditLog::where('action', 'past.ai_read')->latest('id')->first()->summary);
    }

    public function test_dates_on_old_papers_are_read_day_first_and_never_guessed(): void
    {
        $this->assertSame('2019-04-03', PastRecordDraft::date('03/04/2019')?->toDateString());
        $this->assertSame('1998-12-25', PastRecordDraft::date('25-12-98')?->toDateString());
        $this->assertSame('2020-01-07', PastRecordDraft::date('7th Jan 2020')?->toDateString());
        $this->assertSame('2017-08-15', PastRecordDraft::date('August 15, 2017')?->toDateString());
        $this->assertNull(PastRecordDraft::date('31/02/2019'));
        $this->assertNull(PastRecordDraft::date(now()->addMonth()->format('d/m/Y')));
        $this->assertNull(PastRecordDraft::date('sometime in 2019'));

        // A printed prescription with a medicine table, and a report whose values sit on plain lines.
        $rx = PastRecordDraft::fromText("Dr. Anitha Rao MD\nDate: 5 Nov 2021\nComplaints: Burning pain for 2 weeks\nExamination: BP 130/80\nDiagnosis: Acid peptic disease\nAdvice: Avoid spicy food\n\n| Medicine | Dose | Frequency | Duration |\n|---|---|---|---|\n| Tab Pantocid 40 | 1 tab | 1-0-0 | 14 days |\n| | | | |\n");
        $this->assertSame(['visit', '2021-11-05', 'Dr. Anitha Rao MD', 'Acid peptic disease'], [$rx['kind'], $rx['record_date'], $rx['doctor_name'], $rx['diagnosis']]);
        $this->assertSame(['Burning pain for 2 weeks', 'BP 130/80', 'Avoid spicy food'], [$rx['complaints'], $rx['findings'], $rx['advice']]);
        $this->assertSame([['name' => 'Tab Pantocid 40', 'dose' => '1 tab', 'frequency' => '1-0-0', 'duration' => '14 days', 'instructions' => '']], $rx['medicines']);

        $plain = PastRecordDraft::fromText("Reported on 02.01.2020\nSerum Creatinine   1.4 mg/dL   0.6 - 1.2\nBlood Urea : 38 mg/dL (15 - 40)\nThis line has 3 numbers 4 and 5 but is not a result");
        $this->assertSame([['Serum Creatinine', '1.4', 'mg/dL', '0.6 - 1.2'], ['Blood Urea', '38', 'mg/dL', '15 - 40']], array_map(fn ($r) => [$r['parameter'], $r['value'], $r['unit'], $r['reference_range']], $plain['results']));
        $this->assertSame('2020-01-02', $plain['record_date']);
        $this->assertSame([], PastRecordDraft::fromText('')['results']);
    }
}
