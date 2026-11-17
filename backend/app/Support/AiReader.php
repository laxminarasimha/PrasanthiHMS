<?php

namespace App\Support;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\ContentTooLargeException;
use Anthropic\Core\Exceptions\OverloadedException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Models\PastRecord;
use App\Models\PatientHealthItem;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * Reads a scanned paper with an outside AI service, for the pages a reading program inside the
 * hospital cannot manage: handwriting, faint photocopies, crowded prescriptions.
 *
 * The page leaves the hospital when this is used, so it runs only when a member of staff asks for
 * it on one paper, and every use is written in the activity record. What comes back is a draft
 * for a person to check against the scan, never the record itself.
 */
class AiReader
{
    public const MODEL = 'claude-opus-5-5';

    /** The most pages sent in one go, and the most a single request may carry. */
    public const MAX_PAGES = 8;

    private const MAX_BYTES = 20 * 1024 * 1024;

    /** A picture larger than this is made smaller first: the service takes pictures up to 5 MB once encoded. */
    private const MAX_IMAGE_BYTES = 3_600_000;

    private const LONG_EDGE = 2200;

    private const INSTRUCTIONS = <<<'TEXT'
        You are helping a hospital in India copy a patient's old paper record into its computer system. You are given the scanned pages of one paper: a prescription, a consultation note, a lab report, a scan report or a discharge summary. It may be printed, handwritten, or both, in English with occasional Telugu or Hindi.

        Copy out what is written. A person at the hospital will check your copy against the scan before anything is kept, and a doctor may later treat the patient from it, so a wrong word is worse than a missing one.

        - Write only what is on the paper. Never fill in a medicine, dose, value or diagnosis from what would be usual.
        - Where a word or number cannot be read with confidence, write your best reading followed by [?], and add a short line to "unsure" saying what was unclear and where (for example: 'Medicine 3: name may be "Pantocid" or "Pantodac"'). If a part cannot be read at all, write [unreadable].
        - Dates on Indian papers are written day first (03/04/2019 is 3 April 2019). Give record_date as YYYY-MM-DD: the date of the visit, or the date the sample was taken or the report was made. Leave it empty if no date is written.
        - Keep medicine names as written, including the brand, strength and form (Tab, Cap, Syp, Inj). Keep the frequency as written (for example 1-0-1, BD, TDS, SOS). Do not expand or translate abbreviations.
        - For test values, give one entry per value with the value exactly as printed. "test" is the heading the value sits under (for example "Complete Blood Count"), or empty if there is none. Do not work out whether a value is high or low.
        - "facts" are only lasting things the paper states plainly about the patient: a long-term condition, a medicine taken regularly, a past operation or hospital stay, family history, a habit such as smoking, a vaccine given. Do not infer them from the medicines prescribed. "allergies" is filled only if the paper names an allergy.
        - "patient_name" is the patient's name as written on the paper, or empty.
        - "text" is the whole paper copied line by line in reading order, so the checker can find anything the other fields missed.
        - Leave any field empty when the paper says nothing about it.
        TEXT;

    public function __construct(private ?ClientInterface $http = null) {}

    public static function ready(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Sends the pages of one paper to be read.
     *
     * @return array{draft: array<string, mixed>, text: string, pages: int, tokens: array{in: int, out: int}}
     *
     * @throws AiReadingFailed with words that can be shown to the person who asked
     */
    public function read(PastRecord $record): array
    {
        if (! self::ready()) {
            throw new AiReadingFailed('AI reading is not set up yet. The admin adds its key in the server settings.');
        }

        $pages = $record->pages;
        if ($pages->isEmpty()) {
            throw new AiReadingFailed('This paper has no scanned pages.');
        }
        if ($pages->count() > self::MAX_PAGES) {
            throw new AiReadingFailed('This paper has more than '.self::MAX_PAGES.' files. Scan it as smaller papers to read it with AI.');
        }

        $content = [];
        $bytes = 0;
        foreach ($pages as $page) {
            if (! Storage::disk('local')->exists($page->path)) {
                throw new AiReadingFailed("The file “{$page->original_name}” is missing.");
            }
            $data = Storage::disk('local')->get($page->path);
            if ($page->mime === 'application/pdf') {
                $content[] = ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($data)]];
            } else {
                [$data, $mime] = self::fit($data, (string) $page->mime, $page->original_name);
                $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($data)]];
            }
            $bytes += strlen($data);
        }
        if ($bytes > self::MAX_BYTES) {
            throw new AiReadingFailed('These pages are too large to send together. Scan the paper at a lower quality, or as smaller papers.');
        }
        $content[] = ['type' => 'text', 'text' => 'These are the pages of one paper, in order. Copy it out as instructed.'];

        // The reply can take a minute or two for a full handwritten page.
        @set_time_limit(240);
        $client = new Client(
            apiKey: (string) config('services.anthropic.key'),
            requestOptions: ['transporter' => $this->http ?? new Http(['timeout' => 180, 'connect_timeout' => 15]), 'maxRetries' => 1],
        );

        try {
            $message = $client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $content]],
                model: self::MODEL,
                system: self::INSTRUCTIONS,
                outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => self::schema()]],
                // If the service declines a page on the first model, it tries its own stand-in before giving up.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException|PermissionDeniedException $e) {
            throw self::failed('The AI reading service did not accept the hospital’s key. Ask the admin to check it.', $e);
        } catch (RateLimitException|OverloadedException $e) {
            throw self::failed('The AI reading service is busy. Try again in a minute.', $e);
        } catch (ContentTooLargeException $e) {
            throw self::failed('These pages are too large for the AI reading service. Scan the paper at a lower quality, or as smaller papers.', $e);
        } catch (BadRequestException $e) {
            throw self::failed('The AI reading service could not take this paper. Check that the scan opens properly, then try again.', $e);
        } catch (APIStatusException $e) {
            throw self::failed('The AI reading service could not read this paper just now. Try again in a minute.', $e);
        } catch (APIConnectionException $e) {
            throw self::failed('Could not reach the AI reading service. Check the internet connection and try again.', $e, sent: false);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiReadingFailed('The AI reading service declined to read this paper. Type it in by hand.', sent: true);
        }
        if ($message->stopReason === 'max_tokens') {
            throw new AiReadingFailed('This paper is too long to read in one go. Scan it as smaller papers.', sent: true);
        }

        $json = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $json = $block->text;
                break;
            }
        }
        $read = json_decode($json, true);
        if (! is_array($read)) {
            throw new AiReadingFailed('The AI reading service sent back something that could not be understood. Try again.', sent: true);
        }

        return [
            'draft' => PastRecordDraft::tidy($read),
            'text' => is_string($read['text'] ?? null) ? trim($read['text']) : '',
            'pages' => $pages->count(),
            'tokens' => ['in' => $message->usage->inputTokens, 'out' => $message->usage->outputTokens],
        ];
    }

    /** What went wrong is kept in the server log for the admin; the person at the desk gets plain words. */
    private static function failed(string $words, Throwable $e, bool $sent = true): AiReadingFailed
    {
        Log::warning('AI reading failed: '.$e::class.': '.mb_substr($e->getMessage(), 0, 500));

        return new AiReadingFailed($words, $sent, $e);
    }

    /**
     * A picture small enough to send. Large photographs are redrawn smaller; a page of writing stays readable.
     *
     * @return array{0: string, 1: string} the bytes and their type
     */
    private static function fit(string $data, string $mime, string $name): array
    {
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new AiReadingFailed("“{$name}” is not a picture or a PDF.");
        }
        if (strlen($data) <= self::MAX_IMAGE_BYTES) {
            return [$data, $mime];
        }
        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring($data) : false;
        if (! $image) {
            throw new AiReadingFailed("“{$name}” is too large to send. Scan or photograph it at a lower quality.");
        }
        $scale = min(1, self::LONG_EDGE / max(imagesx($image), imagesy($image)));
        if ($scale < 1) {
            $image = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale), IMG_BICUBIC) ?: $image;
        }
        ob_start();
        imagejpeg($image, null, 85);
        $small = (string) ob_get_clean();
        if ($small === '' || strlen($small) > self::MAX_IMAGE_BYTES) {
            throw new AiReadingFailed("“{$name}” is too large to send. Scan or photograph it at a lower quality.");
        }

        return [$small, 'image/jpeg'];
    }

    /**
     * The shape of the reply, which the service is held to.
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        $text = fn (string $says) => ['type' => 'string', 'description' => $says];
        $object = fn (array $properties) => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];

        return $object([
            'kind' => ['type' => 'string', 'enum' => array_keys(PastRecord::KINDS), 'description' => 'visit: a prescription or consultation note. lab: a laboratory report. imaging: an X-ray, ultrasound, CT, MRI, ECG or similar report. discharge: a discharge summary. other: anything else.'],
            'record_date' => $text('YYYY-MM-DD, or empty'),
            'doctor_name' => $text('The doctor who wrote or signed the paper, as written'),
            'hospital_name' => $text('The hospital, clinic or laboratory on the paper'),
            'patient_name' => $text('The patient name on the paper'),
            'complaints' => $text('What the patient came with, and the history noted'),
            'diagnosis' => $text('The diagnosis or impression, as written'),
            'findings' => $text('Examination findings, vital signs, or the body of a scan report'),
            'advice' => $text('Advice, follow-up and tests asked for'),
            'notes' => $text('Anything else worth keeping that fits nowhere above; for a discharge summary, the course in hospital and procedures done'),
            'allergies' => $text('Allergies named on the paper, or empty'),
            'medicines' => ['type' => 'array', 'items' => $object([
                'name' => $text('Name with form and strength, as written'), 'dose' => $text('How much each time, if written separately'),
                'frequency' => $text('As written, e.g. 1-0-1 or BD'), 'duration' => $text('As written, e.g. 5 days'), 'instructions' => $text('e.g. after food'),
            ])],
            'results' => ['type' => 'array', 'items' => $object([
                'test' => $text('The heading the value sits under, or empty'), 'parameter' => $text('What was measured'), 'value' => $text('Exactly as printed'),
                'unit' => $text('Unit, or empty'), 'reference_range' => $text('Normal range as printed, or empty'),
            ])],
            'facts' => ['type' => 'array', 'items' => $object([
                'kind' => ['type' => 'string', 'enum' => array_keys(PatientHealthItem::KINDS), 'description' => 'condition: a long-term illness. medication: a medicine taken regularly. surgery: a past operation or admission. family: family history. habit: smoking, alcohol and the like. immunisation: a vaccine given.'],
                'title' => $text('The fact in a few words'), 'since' => $text('Since when, as written, or empty'),
            ])],
            'unsure' => ['type' => 'array', 'items' => $text('One thing that could not be read with confidence, and where it is')],
            'text' => $text('The whole paper, copied line by line'),
        ]);
    }
}
