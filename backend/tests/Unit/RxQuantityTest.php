<?php

namespace Tests\Unit;

use App\Models\Medicine;
use App\Models\StockBatch;
use App\Support\RxQuantity;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** How many tablets or capsules a prescription line comes to, read only from plain ways of writing. No database. */
class RxQuantityTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string, 3: int, 4: string}> */
    public static function counted(): array
    {
        return [
            'pattern, no dose' => [null, '1-0-1', '5 days', 10, '1-0-1 for 5 days = 10'],
            'three a day for a week' => [null, '1-1-1', '1 week', 21, '1-1-1 for 1 week (7 days) = 21'],
            'four a day' => [null, '1-1-1-1', '2 days', 8, '1-1-1-1 for 2 days = 8'],
            'numbers are the count' => [null, '2-0-2', '5 days', 20, '2-0-2 for 5 days = 20'],
            'halves in the pattern' => [null, '1/2-0-1/2', '5 days', 5, '1/2-0-1/2 for 5 days = 5'],
            'half signs' => [null, '½-0-½', '3 days', 3, '½-0-½ for 3 days = 3'],
            'half left over rounds up' => [null, '½-½-½', '3 days', 5, '½-½-½ for 3 days = 5 (4½ rounded up)'],
            'half a tablet at night' => ['½ tablet', '0-0-1', '5 days', 3, '½ tablet 0-0-1 for 5 days = 3 (2½ rounded up)'],
            'one tablet each time' => ['1 tablet', '1-0-1', 'for 5 days', 10, '1 tablet 1-0-1 for 5 days = 10'],
            'half each time' => ['½ tablet', '1-0-1', '5 days', 5, '½ tablet 1-0-1 for 5 days = 5'],
            'spaces round the dashes' => [null, '1 - 0 - 1', 'x 5 Days.', 10, '1-0-1 for 5 days = 10'],
            'a month is 30 days' => [null, '1-0-1', '1 month', 60, '1-0-1 for 1 month (30 days) = 60'],
            'three months' => [null, '0-0-1', '3 months', 90, '0-0-1 for 3 months (90 days) = 90'],
            'BD' => ['1 tab', 'BD', '3 days', 6, '1 tab BD for 3 days = 6'],
            'B.D. with full stops' => ['1 tablet', 'B.D.', '5 days', 10, '1 tablet B.D for 5 days = 10'],
            'twice daily' => ['1 tab', 'twice daily', '5 days', 10, '1 tab twice daily for 5 days = 10'],
            'OD for a month' => ['1 tab', 'OD', '1 month', 30, '1 tab OD for 1 month (30 days) = 30'],
            'daily, as the ward chart reads it' => ['1 tab', 'daily', '5 days', 5, '1 tab daily for 5 days = 5'],
            'TDS' => ['2 tablets', 'TDS', '3 days', 18, '2 tablets TDS for 3 days = 18'],
            'QID' => ['1 cap', 'QID', '5 days', 20, '1 cap QID for 5 days = 20'],
            'HS' => ['1 tab', 'HS', '10 days', 10, '1 tab HS for 10 days = 10'],
            '8 hourly' => ['1 tab', '8 hourly', '5 days', 15, '1 tab 8 hourly for 5 days = 15'],
            'every 6 hours' => ['1 tab', 'every 6 hours', '2 days', 8, '1 tab every 6 hours for 2 days = 8'],
            'q12h' => ['1 tab', 'q12h', '4 days', 8, '1 tab q12h for 4 days = 8'],
            'one and a half' => ['1 1/2 tablets', 'BD', '3 days', 9, '1 1/2 tablets BD for 3 days = 9'],
            'capsules for a fortnight' => ['1 capsule', 'BD', '2 weeks', 28, '1 capsule BD for 2 weeks (14 days) = 28'],
        ];
    }

    #[DataProvider('counted')]
    public function test_plain_prescriptions_are_counted_with_the_working_in_words(?string $dose, ?string $frequency, ?string $duration, int $quantity, string $workedOut): void
    {
        $count = RxQuantity::count($dose, $frequency, $duration, null, null);

        $this->assertNotNull($count);
        $this->assertSame($quantity, $count['quantity']);
        $this->assertSame($workedOut, $count['worked_out']);
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: ?string}> */
    public static function refused(): array
    {
        return [
            'SOS' => [null, 'SOS', '5 days'],
            'SOS with a dose' => ['1 tab', 'SOS', '5 days'],
            'Stat' => ['1 tab', 'Stat', '1 day'],
            'alternate days' => ['1 tab', 'Alternate days', '1 month'],
            'once a week' => ['1 tab', 'Once a week', '1 month'],
            '48 hourly is alternate days' => ['1 tab', '48 hourly', '10 days'],
            'hourly with no number' => ['1 tab', 'hourly', '5 days'],
            'a time of day, not how often' => ['1 tab', 'At bedtime', '5 days'],
            'a strength, not a count' => ['40 mg', 'OD', '5 days'],
            'millilitres' => ['5 ml', 'TDS', '5 days'],
            'puffs' => ['1 puff', 'BD', '5 days'],
            'apply locally' => ['Apply locally', 'BD', '5 days'],
            'a bare number as the dose' => ['1', '1-0-1', '5 days'],
            'BD with no dose written' => [null, 'BD', '5 days'],
            'OD with a bare number' => ['1', 'OD', '5 days'],
            'which number is the count' => ['2 tablets', '2-0-2', '5 days'],
            'a dose with halves in the pattern' => ['1 tablet', '½-0-½', '5 days'],
            'a quarter' => ['¼ tablet', 'OD', '5 days'],
            'more than four at once' => ['10 tablets', 'OD', '5 days'],
            'nothing at any time' => [null, '0-0-0', '5 days'],
            'two parts only' => [null, '1-0', '5 days'],
            'words after the pattern' => [null, '1-0-1 after food', '5 days'],
            'to continue' => [null, '1-0-1', 'To continue'],
            'a bare number of days' => [null, '1-0-1', '5'],
            'days in words' => [null, '1-0-1', 'five days'],
            'a range of days' => [null, '1-0-1', '5-7 days'],
            'more than a year' => ['1 tab', 'OD', '13 months'],
            'no duration' => [null, '1-0-1', null],
            'no frequency' => ['1 tab', null, '5 days'],
            'nothing at all' => [null, null, null],
        ];
    }

    #[DataProvider('refused')]
    public function test_anything_not_written_plainly_gives_no_quantity(?string $dose, ?string $frequency, ?string $duration): void
    {
        $this->assertNull(RxQuantity::count($dose, $frequency, $duration, null, null));
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function plainWhen(): array
    {
        return [
            'after food' => ['After food', null],
            'before food' => ['Before food', null],
            'empty stomach' => ['Empty stomach', null],
            'on an empty stomach' => ['On an empty stomach', null],
            'at bedtime' => ['At bedtime', null],
            'bed time' => ['Bed time', null],
            'with food' => ['With food', null],
            'with milk' => ['With milk', null],
            'morning' => ['Morning', null],
            'afternoon' => ['Afternoon', null],
            'in the evening' => ['In the evening', null],
            'at night' => ['At night', null],
            'two meals' => ['After breakfast and dinner', null],
            'two times of day' => ['Morning & night', null],
            'with a comma and brackets' => ['After food, (at bedtime)', null],
            'spaces and a full stop' => ['  after   FOOD. ', null],
            'A/F' => ['A/F', null],
            'B/F' => ['B/F', null],
            'A.C.' => ['A.C.', null],
            'PC' => ['PC', null],
            'HS' => ['HS', null],
            'E/S' => ['E/S', null],
            'in the instructions' => [null, 'After food'],
            'in both' => ['Before breakfast', 'Empty stomach'],
            'both empty' => ['', '  '],
        ];
    }

    #[DataProvider('plainWhen')]
    public function test_a_when_or_instructions_box_of_plain_meal_or_time_words_still_gets_the_count(?string $timing, ?string $instructions): void
    {
        $this->assertSame(10, RxQuantity::count('1 tablet', '1-0-1', '5 days', $timing, $instructions)['quantity'] ?? null);
    }

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function notPlainWhen(): array
    {
        return [
            'SOS' => ['SOS', null],
            'Stat' => ['Stat', null],
            'PRN' => ['PRN', null],
            'if needed' => ['If needed', null],
            'when required' => ['When required', null],
            'only if in pain' => ['After food', 'Only if in pain'],
            'alternate days' => ['Alternate days', null],
            'every other day' => [null, 'Every other day'],
            'every other morning' => ['Every other morning', null],
            'once a week in when' => ['Once a week', null],
            'once a week in the instructions' => ['After food', 'Once a week'],
            'weekly' => [null, 'Weekly'],
            'once a month' => ['Once a month', null],
            'monthly' => [null, 'Monthly'],
            'a day of the week' => ['Every Sunday', null],
            'days by name' => ['Mon, Wed, Fri', null],
            'on sundays' => [null, 'On Sundays after food'],
            'then' => ['After food', 'Then 1-0-0 for 5 days'],
            'taper' => [null, 'Taper'],
            'reduce' => [null, 'Reduce slowly'],
            'stop' => [null, 'Stop after 3 days'],
            'continue' => [null, 'Continue'],
            'a word not on the list' => ['After food', 'Avoid alcohol'],
            'a number' => ['30 min before food', null],
            'one or the other' => ['Morning or night', null],
            'the night before' => ['The night before', null],
            'then, between two plain words' => ['After food then at bedtime', null],
        ];
    }

    #[DataProvider('notPlainWhen')]
    public function test_a_when_or_instructions_box_that_may_change_how_often_gives_no_quantity(?string $timing, ?string $instructions): void
    {
        // Without the When and Instructions boxes, the same line is counted.
        $this->assertSame(10, RxQuantity::count('1 tablet', '1-0-1', '5 days', null, null)['quantity']);
        $this->assertNull(RxQuantity::count('1 tablet', '1-0-1', '5 days', $timing, $instructions));
    }

    public function test_a_stocked_medicine_reads_the_when_and_instructions_boxes_too(): void
    {
        $this->assertSame(30, RxQuantity::forStock($this->medicine('Tablet'), '1 tablet', '1-0-0', '1 month', 'After food', null)['quantity']);
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet'), '1 tablet', '1-0-0', '1 month', 'After food', 'Once a week'));
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet'), '1 tablet', '1-0-0', '1 month', 'Alternate days', null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Capsule'), '1 cap', 'TDS', '5 days', 'SOS', null));
    }

    public function test_the_count_says_what_form_the_dose_named_and_whether_a_half_came_into_it(): void
    {
        $this->assertSame(['tablet', false], $this->formAndHalves('1 tab', 'BD', '5 days'));
        $this->assertSame(['capsule', false], $this->formAndHalves('1 cap', 'BD', '5 days'));
        $this->assertSame(['tablet', true], $this->formAndHalves('½ tablet', '0-0-1', '5 days'));
        $this->assertSame([null, true], $this->formAndHalves(null, '½-0-½', '5 days'));
        $this->assertSame([null, false], $this->formAndHalves(null, '1-0-1', '5 days'));
    }

    public function test_a_stocked_tablet_or_capsule_sold_loose_from_strips_gets_the_count(): void
    {
        $this->assertSame(['quantity' => 10, 'worked_out' => '1-0-1 for 5 days = 10'], RxQuantity::forStock($this->medicine('Tablet'), null, '1-0-1', '5 days', null, null));
        $this->assertSame(14, RxQuantity::forStock($this->medicine('Capsule'), '1 cap', 'BD', '1 week', null, null)['quantity']);
        $this->assertSame(15, RxQuantity::forStock($this->medicine('Dispersible Tablet'), '1 tab', 'TDS', '5 days', null, null)['quantity']);
        $this->assertSame(3, RxQuantity::forStock($this->medicine('Chewable Tablet', [10, 15]), '½ tablet', '0-0-1', '5 days', null, null)['quantity']);
    }

    public function test_only_a_tablet_or_capsule_by_its_own_type_gets_a_count(): void
    {
        foreach (['Syrup', 'Suspension', 'Drops', 'Cream', 'Injection', 'Tablet / Syrup', 'Other', '', null] as $type) {
            $this->assertNull(RxQuantity::forStock($this->medicine($type), null, '1-0-1', '5 days', null, null), "type {$type}");
        }
    }

    public function test_a_medicine_sold_whole_or_with_nothing_on_sale_gets_no_count(): void
    {
        // A pack of one (a bottle, a tube) is sold whole, whatever the type says.
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet', [1]), null, '1-0-1', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet', [10, 1]), null, '1-0-1', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet', []), null, '1-0-1', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock(null, null, '1-0-1', '5 days', null, null));
    }

    public function test_the_dose_must_name_the_form_the_pharmacy_has_and_a_capsule_is_never_halved(): void
    {
        $this->assertNull(RxQuantity::forStock($this->medicine('Tablet'), '1 cap', 'BD', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Capsule'), '1 tab', 'BD', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Capsule'), '½ capsule', 'BD', '5 days', null, null));
        $this->assertNull(RxQuantity::forStock($this->medicine('Capsule'), null, '½-0-½', '5 days', null, null));
        $this->assertSame(10, RxQuantity::forStock($this->medicine('Capsule'), null, '1-0-1', '5 days', null, null)['quantity']);
    }

    /** @return array{0: ?string, 1: bool} */
    private function formAndHalves(?string $dose, string $frequency, string $duration): array
    {
        $count = RxQuantity::count($dose, $frequency, $duration, null, null);

        return [$count['form'], $count['halves']];
    }

    /** A made-up medicine with batches on sale holding these many to a pack; nothing is saved. */
    private function medicine(?string $type, array $perPack = [10]): Medicine
    {
        $medicine = new Medicine(['name' => 'Sample Medicine', 'type' => $type]);

        return $medicine->setRelation('sellableBatches', new Collection(array_map(fn (int $units) => new StockBatch(['units_per_pack' => $units, 'units_in_stock' => 30]), $perPack)));
    }
}
