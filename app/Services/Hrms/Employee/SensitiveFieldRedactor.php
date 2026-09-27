<?php

namespace App\Services\Hrms\Employee;

use App\Models\Hrms\Employee\Employee;

/**
 * Employee/HRMS — masks the personal fields for a reader without
 * `hrms.documents.view_sensitive`.
 *
 * Its own class because masking is a *rule with a dozen decisions in it*, and
 * burying those decisions inside the presenter's array literal is how a mask
 * quietly stops being applied to one field. Each primitive is a named rule that
 * can be tested on its own, and the presenter decides only which primitives to
 * reach for.
 *
 * Two rules govern every choice here:
 *
 * 1. **Mask where a partial mask still tells the reader something useful, null
 *    where it does not.** A phone's last two digits answer "which country" and
 *    confirm the number is on file; a year of birth answers only "how old",
 *    which combined with a name in a 200-person company is an identifier. So
 *    the phone is masked and the date of birth is dropped entirely.
 * 2. **Never emit a raw partial that reconstructs the value.** Keeping an email
 *    domain leaks the provider; a mask that keeps the provider is not a mask.
 *    The one place this rule bends is the email domain — see `email()`.
 */
class SensitiveFieldRedactor
{
    private const MASK = '***';

    /**
     * The employee columns this class considers personal.
     *
     * Also the source list for the `hrms_data_access_logs.fields` column, which
     * stores *names* only. One list, so a field cannot be readable through the
     * API yet missing from the access log that says who read it.
     *
     * @var list<string>
     */
    public const SENSITIVE_COLUMNS = [
        'personal_email',
        'phone',
        'date_of_birth',
        'gender',
        'marital_status',
        'nationality',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'notes',
    ];

    /**
     * `someone@example.com` -> `s***@***`.
     *
     * The local part keeps its first character, which is enough to tell two
     * different people's addresses apart without being usable as one — and
     * whether someone has a personal address on file at all. The **domain** is
     * masked too, even though keeping it would be more useful: the domain of a
     * personal address is the provider (`gmail.com`), and in a company where
     * four people use the same provider it narrows the field to a handful of
     * people. Usefulness is not worth narrowing a list that small.
     */
    public function email(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $at = strrpos($value, '@');

        // Not an address at all (legacy free-text): mask the whole thing rather
        // than returning it verbatim, which is what a naive mask would do.
        if ($at === false || $at === 0) {
            return $this->name($value);
        }

        return mb_substr($value, 0, 1).self::MASK.'@'.self::MASK;
    }

    /**
     * `+91 98765 43210` -> `****10`.
     *
     * The last two digits survive because they are not a locator: they separate
     * two landlines in the same office, and they confirm a number is on file.
     * Everything else, including any country or area code, is masked — a country
     * code plus an area code is often the whole of a fixed line's identity.
     */
    public function phone(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // The extension is cut off before the digits are read. Glued onto the
        // end of the number it shifts the mask by however many digits it has,
        // so "+15550001111 ext 4" would be masked as ending "14" — the extension
        // rather than the number, and the wrong length as well.
        $base = preg_split('/\s*(?:ext(?:ension)?\.?|x|#)\s*/i', $value)[0];
        $digits = preg_replace('/\D+/', '', $base) ?? '';

        if (strlen($digits) < 3) {
            return self::MASK;
        }

        return str_repeat('*', max(0, strlen($digits) - 2)).substr($digits, -2);
    }

    /**
     * `Ananya Sharma` -> `A***`.
     *
     * First character only. Enough to tell two emergency contacts apart in a
     * list; not a name anyone can ring.
     */
    public function name(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, 1).self::MASK;
    }

    /**
     * The date of birth, in full or in part, or nothing at all.
     *
     * There is no mask here on purpose. A year, or even a month and a year, is a
     * strong identifier on its own in any company small enough to read a
     * directory, so the only honest redaction is the whole value — and
     * `restricted: true` on the record is what tells the client this is a
     * withheld field rather than a person who never filled it in.
     */
    public function dateOfBirth(): null
    {
        return null;
    }

    /**
     * The personal fields, masked, for a reader without the sensitive
     * permission.
     *
     * Every key is present, in the same shape the privileged reader gets, so a
     * client renders one layout instead of branching on which caller it is
     * talking to. The values are what differ.
     *
     * @return array<string, mixed>
     */
    public function masked(Employee $employee): array
    {
        return [
            // The flag that separates "masked" from "not set". Without it a
            // client cannot tell a withheld field from an empty one.
            'restricted' => true,
            'personal_email' => $this->email($employee->personal_email),
            'phone' => $this->phone($employee->phone),
            'date_of_birth' => null,
            // Categories with no meaningful partial value. See the class note.
            'gender' => null,
            'marital_status' => null,
            'nationality' => null,
            'address' => $this->maskedAddress(),
            'emergency_contact' => $this->maskedEmergencyContact($employee),
            // Free text: there is no partial mask of a paragraph that is not the
            // paragraph.
            'notes' => null,
        ];
    }

    /**
     * A home address, with every part of it withheld.
     *
     * There is no field here worth a partial mask: a street name and a city
     * together are a location, and a postcode is a location on its own. A
     * masked address that a UI then invites the reader to "click to reveal"
     * would be a lie about what the server can do.
     *
     * @return array<string, null>
     */
    private function maskedAddress(): array
    {
        return [
            'line1' => null,
            'line2' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
            'country' => null,
        ];
    }

    /**
     * The person to ring in an emergency, with the parts that identify them
     * masked.
     *
     * The name and the number are worth masking rather than dropping — an HR
     * reader looking at a record legitimately needs to know *that* there is an
     * emergency contact and roughly how many are on file. The relation is
     * dropped: "spouse" is a disclosure about the employee, not about the
     * contact, and it is the one field here that says something true even when
     * every other value is hidden.
     *
     * @return array<string, string|null>
     */
    private function maskedEmergencyContact(Employee $employee): array
    {
        return [
            'name' => $this->name($employee->emergency_contact_name),
            'phone' => $this->phone($employee->emergency_contact_phone),
            'relation' => null,
        ];
    }

    /**
     * The sensitive columns actually populated on this record.
     *
     * This is what the `hrms_data_access_logs.fields` column records, and it
     * filters to what is *set* for one reason: the log answers "what could this
     * reader see", and an empty field was visible as nothing. A log that lists
     * all sixteen columns for a record with two of them filled is a log nobody
     * can use to tell one read from another.
     *
     * @return list<string>
     */
    public function exposedColumns(Employee $employee): array
    {
        $exposed = [];

        foreach (self::SENSITIVE_COLUMNS as $column) {
            $value = $employee->{$column};

            if ($value === null || $value === '') {
                continue;
            }

            $exposed[] = $column;
        }

        return $exposed;
    }
}
