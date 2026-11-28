<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Support\PhoneNotice;
use App\Support\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class PhoneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'https://fcm.googleapis.com/fcm/send/sample-device-one';

    private const IPHONE = 'https://web.push.apple.com/sample-device-two';

    /** A made-up phone: its key pair and its secret, as a browser would have made them. */
    private OpenSSLAsymmetricKey $phone;

    private string $phoneKey;

    private string $phoneSecret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phone = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->phone)['ec'];
        $this->phoneKey = WebPush::encode("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        $this->phoneSecret = WebPush::encode(random_bytes(16));
    }

    /** What the phone would read out of a notification sent to it. */
    private function read(string $body): array
    {
        $salt = substr($body, 0, 16);
        $sender = substr($body, 21, 65);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(WebPush::publicPem($sender)), $this->phone);
        $material = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".WebPush::decode($this->phoneKey).$sender, WebPush::decode($this->phoneSecret));
        $key = hash_hkdf('sha256', $material, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $material, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($body, 86, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($body, -16));
        $this->assertNotFalse($plain, 'The phone could not open the notification.');
        $this->assertSame("\x02", substr($plain, -1));

        return json_decode(substr($plain, 0, -1), true);
    }

    private function switchOn(User $user, string $endpoint = self::CHROME): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/me/push', ['endpoint' => $endpoint, 'keys' => ['p256dh' => $this->phoneKey, 'auth' => $this->phoneSecret]])->assertCreated();
    }

    private function book(User $doctor, array $more = []): array
    {
        return $this->postJson('/api/appointments', $more + [
            'patient_id' => Patient::factory()->create(['name' => 'Sample Father Tester'])->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => today()->format('Y-m-d').' 16:30',
            // Whatever the day and the hour the tests are run at, the time is taken: the doctor's hours are not what is tested here.
            'outside_hours' => true,
        ])->assertCreated()->json();
    }

    public function test_the_words_are_locked_exactly_as_the_standard_shows(): void
    {
        // The worked example of RFC 8291 (section 5 and appendix A).
        $private = WebPush::decode('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
        $public = WebPush::decode('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
        $der = hex2bin('30770201010420').$private.hex2bin('a00a06082a8648ce3d030107a14403420004').substr($public, 1);
        $sender = openssl_pkey_get_private("-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n");
        $this->assertNotFalse($sender);

        $locked = WebPush::encrypt(
            'When I grow up, I want to be a watermelon',
            'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4',
            'BTBZMqHH6r4Tts7J_aSIgg',
            $sender,
            WebPush::decode('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::encode($locked),
        );
    }

    public function test_each_request_is_signed_so_the_service_knows_who_is_sending(): void
    {
        $header = WebPush::authorization(self::CHROME, 1_800_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $part);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::decode($part[1]), true));
        $claims = json_decode(WebPush::decode($part[2]), true);
        $this->assertSame(['https://fcm.googleapis.com', 1_800_000_000 + 43200], [$claims['aud'], $claims['exp']]);
        $this->assertStringStartsWith('https://', $claims['sub']);
        $this->assertSame(WebPush::publicKey(), $part[4]);

        // The signature is the two numbers side by side; wrapped again, the public key must agree with it.
        $plain = WebPush::decode($part[3]);
        $this->assertSame(64, strlen($plain));
        $wrap = function (string $number): string {
            $number = ltrim($number, "\0");
            $number = (ord($number[0]) & 0x80 ? "\0" : '').$number;

            return "\x02".chr(strlen($number)).$number;
        };
        $both = $wrap(substr($plain, 0, 32)).$wrap(substr($plain, 32));
        $this->assertSame(1, openssl_verify("{$part[1]}.{$part[2]}", "\x30".chr(strlen($both)).$both, WebPush::publicPem(WebPush::decode($part[4])), OPENSSL_ALGO_SHA256));

        // The key pair is made once and kept; the private half is not left readable in the database.
        $this->assertSame($part[4], WebPush::publicKey());
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(Setting::find('push_keys')->value));
    }

    public function test_a_device_is_one_persons_at_a_time_and_only_known_services_are_used(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $keys = ['p256dh' => $this->phoneKey, 'auth' => $this->phoneSecret];

        $this->postJson('/api/me/push', ['endpoint' => self::CHROME, 'keys' => $keys])->assertUnauthorized();
        Sanctum::actingAs($doctor);
        $this->getJson('/api/me/push/key')->assertOk()->assertJsonPath('key', WebPush::publicKey());
        // The server only ever sends to the notification services themselves.
        foreach (['http://fcm.googleapis.com/fcm/send/x', 'https://localhost/x', 'https://fcm.googleapis.com.example.com/x', 'https://10.0.0.5/x', 'https://fcm.googleapis.com:8443/x'] as $bad) {
            $this->postJson('/api/me/push', ['endpoint' => $bad, 'keys' => $keys])->assertStatus(422)->assertJsonValidationErrors('endpoint');
        }
        $this->postJson('/api/me/push', ['endpoint' => self::CHROME, 'keys' => ['p256dh' => 'short', 'auth' => $this->phoneSecret]])->assertStatus(422)->assertJsonValidationErrors('keys.p256dh');

        $this->switchOn($doctor);
        $this->switchOn($doctor); // opening the app again notes the same device, not a second one
        // A phone noting itself is not something a person did: the activity log is left alone.
        $this->assertSame(0, \App\Models\AuditLog::count());
        $this->switchOn($doctor, self::IPHONE);
        $this->assertSame(2, PushSubscription::where('user_id', $doctor->id)->count());
        $this->assertSame('Other device', PushSubscription::first()->device);

        // Someone else signs in on the same phone: it is theirs now, and the first doctor's notices stop going to it.
        $this->switchOn($other);
        $this->assertSame([self::IPHONE], PushSubscription::where('user_id', $doctor->id)->pluck('endpoint')->all());
        $this->assertSame([self::CHROME], PushSubscription::where('user_id', $other->id)->pluck('endpoint')->all());

        // Nobody can switch off another person's device; signing out switches off one's own.
        Sanctum::actingAs($doctor);
        $this->deleteJson('/api/me/push', ['endpoint' => self::CHROME])->assertNoContent();
        $this->assertSame(2, PushSubscription::count());
        $this->deleteJson('/api/me/push', ['endpoint' => self::IPHONE])->assertNoContent();
        $this->assertSame(0, PushSubscription::where('user_id', $doctor->id)->count());
    }

    public function test_the_doctor_is_told_when_the_desk_adds_an_appointment(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201), 'web.push.apple.com/*' => Http::response('', 410)]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $quiet = User::factory()->create(['role' => 'doctor']);
        $this->switchOn($doctor);
        $this->switchOn($doctor, self::IPHONE); // a phone that has since had the app removed

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->book($quiet); // a doctor who has not switched notifications on: nothing is sent to anyone
        Http::assertNothingSent();

        $booked = $this->book($doctor);
        Http::assertSentCount(2);
        Http::assertSent(function (SentRequest $sent) use ($booked) {
            if ($sent->url() !== self::CHROME) {
                return false;
            }
            $this->assertSame(['aes128gcm', 'high', '14400'], [$sent->header('Content-Encoding')[0], $sent->header('Urgency')[0], $sent->header('TTL')[0]]);
            $this->assertStringStartsWith('vapid t=', $sent->header('Authorization')[0]);
            // Nothing about the patient is readable on the way: only the phone can open it.
            $this->assertStringNotContainsString('Sample Father', $sent->body());
            $this->assertSame([
                'title' => 'New appointment',
                'body' => 'Sample Father Tester · Today 4:30 PM · Token 1',
                'url' => '/doctor',
                'tag' => 'appointment-'.$booked['id'],
                'kind' => 'appointment',
            ], $this->read($sent->body()));

            return true;
        });
        // The phone that no longer takes them is forgotten; the other is noted as reached.
        $this->assertSame([self::CHROME], PushSubscription::pluck('endpoint')->all());
        $this->assertNotNull(PushSubscription::first()->last_sent_at);

        // A patient who is checked in straight away, and one booked for another day.
        $this->book($doctor, ['status' => 'arrived', 'scheduled_at' => today()->format('Y-m-d').' 17:00']);
        $this->book($doctor, ['scheduled_at' => today()->addDay()->format('Y-m-d').' 10:45', 'outside_hours' => true]);
        $read = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (SentRequest $sent) => $sent->url() === self::CHROME)->map(fn (SentRequest $sent) => $this->read($sent->body()))->values();
        $this->assertSame(['Patient waiting', 'Sample Father Tester · Today 5:00 PM · Token 2'], [$read[1]['title'], $read[1]['body']]);
        $this->assertSame(['New appointment', 'Sample Father Tester · Tomorrow 10:45 AM · Token 1'], [$read[2]['title'], $read[2]['body']]);
    }

    public function test_no_notice_for_the_doctors_own_booking_an_old_visit_or_a_closed_login(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $this->switchOn($doctor);

        // The doctor adds the visit themselves: they already know.
        $this->book($doctor);
        // An old visit entered late by the desk.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->book($doctor, ['scheduled_at' => today()->subDays(3)->format('Y-m-d').' 10:30', 'status' => 'arrived']);
        Http::assertNothingSent();

        // Booked from the patient's own phone: the doctor is told, and told where it came from.
        $visit = Appointment::create(['patient_id' => Patient::factory()->create(['name' => 'Sample Mother Tester'])->id, 'doctor_id' => $doctor->id, 'scheduled_at' => today()->addDays(2)->format('Y-m-d').' 11:00', 'status' => 'booked', 'token_no' => 4, 'booked_via' => 'app']);
        PhoneNotice::appointmentAdded($visit, null, true);
        $this->app->make(\Illuminate\Support\Defer\DeferredCallbackCollection::class)->invoke();
        Http::assertSentCount(1);
        $words = $this->read(Http::recorded()[0][0]->body())['body'];
        $this->assertStringStartsWith('Sample Mother Tester · '.today()->addDays(2)->format('D j M').' 11:00 AM · Token 4', $words);
        $this->assertStringEndsWith('booked from the patient app', $words);

        // A login that has been closed is sent nothing, even with a phone still noted against it.
        $doctor->update(['is_active' => false]);
        $this->assertSame(0, PhoneNotice::now($doctor->id, ['title' => 'x']));
        Http::assertSentCount(1);
    }

    public function test_the_doctor_is_told_when_a_booked_patient_arrives(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $this->switchOn($doctor);
        $desk = User::factory()->create(['role' => 'frontdesk']);

        Sanctum::actingAs($desk);
        $visit = $this->book($doctor);
        $old = Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => today()->subDay()->format('Y-m-d').' 10:30', 'status' => 'booked', 'token_no' => 1]);
        Http::assertSentCount(1); // the booking itself

        // A change that is not an arrival says nothing.
        $this->putJson("/api/appointments/{$visit['id']}", ['notes' => 'Bring old reports'])->assertOk();
        Http::assertSentCount(1);

        $this->putJson("/api/appointments/{$visit['id']}", ['status' => 'arrived'])->assertOk()->assertJsonPath('status', 'arrived');
        Http::assertSentCount(2);
        $this->assertSame([
            'title' => 'Patient waiting',
            'body' => 'Sample Father Tester · has arrived · booked for 4:30 PM · Token 1',
            'url' => '/doctor',
            // The same mark as the booking's notice, so on the phone this one takes its place.
            'tag' => 'appointment-'.$visit['id'],
            'kind' => 'appointment',
        ], $this->read(Http::recorded()[1][0]->body()));

        // Said once: marking it again, or moving on from it, is silent. So is tidying up yesterday's visit.
        $this->putJson("/api/appointments/{$visit['id']}", ['status' => 'arrived'])->assertOk();
        $this->putJson("/api/appointments/{$visit['id']}", ['status' => 'ongoing'])->assertOk();
        $this->putJson("/api/appointments/{$old->id}", ['status' => 'arrived'])->assertOk();
        Http::assertSentCount(2);

        // The doctor marking their own patient as here already knows.
        $own = $this->book($doctor, ['scheduled_at' => today()->format('Y-m-d').' 17:15']);
        Http::assertSentCount(3);
        Sanctum::actingAs($doctor);
        $this->putJson("/api/appointments/{$own['id']}", ['status' => 'arrived'])->assertOk();
        Http::assertSentCount(3);

        // A doctor who has not switched notifications on is sent nothing.
        Sanctum::actingAs($desk);
        $quiet = $this->book($other);
        $this->putJson("/api/appointments/{$quiet['id']}", ['status' => 'arrived'])->assertOk();
        Http::assertSentCount(3);
    }

    public function test_a_test_notice_can_be_sent_to_the_device_in_hand(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::sequence()->push('', 201)->push('', 500)->push('', 410)]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $this->postJson('/api/me/push/test', ['endpoint' => self::CHROME])->assertStatus(422)->assertJsonPath('sent', false);

        $this->switchOn($doctor);
        $this->postJson('/api/me/push/test', ['endpoint' => self::CHROME])->assertOk()->assertJsonPath('sent', true);
        $this->assertSame('Notifications are on', $this->read(Http::recorded()[0][0]->body())['title']);
        // The service is having trouble: said plainly, and the device is kept.
        $this->postJson('/api/me/push/test', ['endpoint' => self::CHROME])->assertOk()->assertJsonPath('sent', false);
        $this->assertSame(1, PushSubscription::count());
        // The device has stopped taking them: it is forgotten, and the person is told to switch on again.
        $this->postJson('/api/me/push/test', ['endpoint' => self::CHROME])->assertOk()->assertJsonPath('sent', false)->assertJsonPath('message', 'This device had stopped accepting notifications. Switch them on again.');
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_the_doctor_is_told_when_lab_results_are_ready_and_has_them_until_seen(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $this->switchOn($doctor);
        $cbp = \App\Models\LabTest::create(['name' => 'CBP', 'price' => 350]);
        $cbp->parameters()->create(['name' => 'Haemoglobin', 'unit' => 'g/dL', 'reference_range' => '13 - 17', 'position' => 1]);
        $culture = \App\Models\LabTest::create(['name' => 'Blood culture', 'price' => 900, 'outside_lab' => 'City Diagnostics']);
        $rbs = \App\Models\LabTest::create(['name' => 'RBS', 'price' => 80]);

        Sanctum::actingAs($desk = User::factory()->create(['role' => 'frontdesk']));
        $visit = $this->book($doctor);
        Http::assertSentCount(1); // the booking
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $order = $this->postJson('/api/lab/orders', ['patient_id' => $visit['patient_id'], 'appointment_id' => $visit['id'], 'test_ids' => [$cbp->id, $culture->id, $rbs->id]])->assertCreated()->json();
        $item = fn (string $name) => collect($order['items'])->firstWhere('test_name', $name);
        $save = fn (string $name, array $results = [], bool $complete = true) => $this->putJson("/api/lab/orders/{$order['id']}/items/{$item($name)['id']}", ['complete' => $complete, 'results' => $results])->assertOk();

        // One of the two tests done here is finished: the doctor is not told yet.
        $save('CBP', [['id' => $item('CBP')['results'][0]['id'], 'value' => '8.2', 'flag' => 'low']]);
        Http::assertSentCount(1);
        // Both done here: told once, though the culture is still with the outside lab. No values travel in the notice.
        $save('RBS');
        Http::assertSentCount(2);
        $this->assertSame([
            'title' => 'Lab results ready',
            'body' => 'Sample Father Tester · CBP, RBS · 1 outside the normal range',
            'url' => '/doctor/results',
            'tag' => 'lab-'.$order['id'],
            'kind' => 'lab',
        ], $this->read(Http::recorded()[1][0]->body()));
        // Saving the same result again says nothing more.
        $save('RBS');
        Http::assertSentCount(2);

        // The doctor has it on To do and on their results list, with what is outside its range and what is still awaited.
        Sanctum::actingAs($doctor);
        $todo = collect($this->getJson('/api/todo')->assertOk()->json('waiting'))->firstWhere('key', 'doctor');
        $line = collect($todo['items'])->firstWhere('key', 'results_ready');
        $this->assertSame(1, $line['count']);
        $this->assertTrue($line['urgent']);
        $this->getJson('/api/doctor/ready-results')->assertOk()->assertJsonCount(1)->assertJsonPath('0.order_no', $order['order_no'])->assertJsonPath('0.seen_at', null)
            ->assertJsonPath('0.tests', ['CBP', 'RBS'])->assertJsonPath('0.awaited', ['Blood culture (City Diagnostics)'])
            ->assertJsonPath('0.abnormal.0.parameter', 'Haemoglobin')->assertJsonPath('0.abnormal.0.value', '8.2');
        // Another doctor has nothing, and cannot tick these off.
        Sanctum::actingAs($other = User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/doctor/ready-results')->assertOk()->assertJsonCount(0);
        $this->putJson("/api/lab/orders/{$order['id']}/seen")->assertForbidden();
        // A nurse is shown what is waiting for every doctor, to chase.
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->getJson('/api/doctor/ready-results')->assertOk()->assertJsonCount(1)->assertJsonPath('0.mine', false)->assertJsonPath('0.doctor', $doctor->name);

        Sanctum::actingAs($doctor);
        $this->putJson("/api/lab/orders/{$order['id']}/seen")->assertNoContent();
        $this->getJson('/api/doctor/ready-results')->assertJsonCount(1)->assertJsonPath('0.seen_at', now()->format('Y-m-d H:i'));
        $this->assertNull(collect(collect($this->getJson('/api/todo')->json('waiting'))->firstWhere('key', 'doctor')['items'] ?? [])->firstWhere('key', 'results_ready'));

        // The outside lab's result comes days later: told again, and it is back on the list as not seen.
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $save('Blood culture');
        Http::assertSentCount(3);
        $this->assertNull(\App\Models\LabOrder::find($order['id'])->seen_at);

        // A result reopened is no longer ready; finished again by the doctor themself, there is nobody to tell.
        $save('RBS', [], false);
        $this->assertNull(\App\Models\LabOrder::find($order['id'])->ready_at);
        Sanctum::actingAs($doctor);
        $save('RBS');
        Http::assertSentCount(3);
        $this->assertNotNull(\App\Models\LabOrder::find($order['id'])->seen_at);

        // Where the lab approves its results, the doctor is told at the approval, not when the result is typed.
        Setting::updateOrCreate(['key' => 'lab'], ['value' => ['approval_required' => true] + Setting::group('lab', Setting::LAB_DEFAULTS)]);
        Sanctum::actingAs($lab = User::factory()->create(['role' => 'lab_tech']));
        $second = $this->postJson('/api/lab/orders', ['patient_id' => $visit['patient_id'], 'appointment_id' => $visit['id'], 'test_ids' => [$rbs->id]])->assertCreated()->json();
        $this->putJson("/api/lab/orders/{$second['id']}/items/{$second['items'][0]['id']}", ['complete' => true, 'results' => []])->assertOk();
        Http::assertSentCount(3);
        $this->putJson("/api/lab/orders/{$second['id']}/approve")->assertOk();
        Http::assertSentCount(4);
    }
}
