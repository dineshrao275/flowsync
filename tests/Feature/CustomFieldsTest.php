<?php

namespace Tests\Feature;

use App\Models\CustomFieldValue;
use App\Services\CustomFields\CustomFieldService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HrmsP5Helpers;
use Tests\IsolatesDatabase;
use Tests\TestCase;

/** F2.1 / F2.2 — the custom-field engine: definitions, typed validation, employee values, filters, gating. */
class CustomFieldsTest extends TestCase
{
    use HrmsP5Helpers;
    use IsolatesDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAcmeModules(['hrms.core', 'custom_fields']);
        $this->loginAdmin();
    }

    private function define(array $overrides = []): array
    {
        return $this->postJson('/api/custom-fields', $overrides + [
            'entity' => 'employee', 'label' => 'Blood group', 'type' => 'select',
            'options' => [['value' => 'a_pos', 'label' => 'A+'], ['value' => 'o_neg', 'label' => 'O-']],
        ])->assertCreated()->json('field');
    }

    public function test_a_manager_defines_a_field_and_the_key_is_derived_and_unique(): void
    {
        $first = $this->define();
        $second = $this->define();

        $this->assertSame('blood_group', $first['key']);
        $this->assertSame('blood_group_2', $second['key']);
        $this->assertSame('select', $first['type']);
    }

    public function test_a_select_needs_options_and_unknown_types_are_refused(): void
    {
        $this->postJson('/api/custom-fields', ['entity' => 'employee', 'label' => 'X', 'type' => 'select'])
            ->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->postJson('/api/custom-fields', ['entity' => 'employee', 'label' => 'X', 'type' => 'wizard'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/custom-fields', ['entity' => 'spaceship', 'label' => 'X', 'type' => 'text'])
            ->assertUnprocessable()->assertJsonValidationErrors('entity');
    }

    public function test_type_and_key_cannot_be_changed_after_creation(): void
    {
        $field = $this->define();

        $this->putJson("/api/custom-fields/{$field['id']}", ['label' => 'Blood type', 'type' => 'text', 'key' => 'other'])
            ->assertOk()->assertJsonPath('field.label', 'Blood type')
            ->assertJsonPath('field.type', 'select')->assertJsonPath('field.key', 'blood_group');
    }

    public function test_an_option_in_use_cannot_be_removed(): void
    {
        $field = $this->define();
        $employee = $this->makeEmployee('Holder');
        $service = app(CustomFieldService::class);
        $service->save('employee', $employee->id, $service->validate('employee', ['blood_group' => 'o_neg']));

        $this->putJson("/api/custom-fields/{$field['id']}", ['options' => [['value' => 'a_pos', 'label' => 'A+']]])
            ->assertUnprocessable()->assertJsonValidationErrors('options');
    }

    public function test_values_are_validated_per_type(): void
    {
        $this->define(['label' => 'Age', 'type' => 'number', 'options' => null]);
        $this->define(['label' => 'Joined club', 'type' => 'date', 'options' => null]);
        $this->define(['label' => 'Site', 'type' => 'url', 'options' => null]);
        $this->define(['label' => 'Languages', 'type' => 'multiselect', 'options' => [['value' => 'en', 'label' => 'English'], ['value' => 'es', 'label' => 'Spanish']]]);
        $service = app(CustomFieldService::class);

        $ok = $service->validate('employee', ['age' => '42', 'joined_club' => '2026-03-04', 'site' => 'https://example.com', 'languages' => ['en', 'es']]);
        $this->assertCount(4, $ok);

        foreach ([['age' => 'abc'], ['joined_club' => 'not-a-date'], ['site' => 'javascript:alert(1)'], ['languages' => ['fr']], ['nope' => 1]] as $bad) {
            try {
                $service->validate('employee', $bad);
                $this->fail('expected a validation error for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_required_fields_are_enforced_on_create_but_not_on_partial_update(): void
    {
        $this->define(['label' => 'Badge', 'type' => 'text', 'options' => null, 'required' => true]);
        $service = app(CustomFieldService::class);

        $this->expectException(ValidationException::class);
        try {
            $service->validate('employee', [], partial: true); // partial: ok
        } catch (ValidationException) {
            $this->fail('a partial update must not demand required fields');
        }

        $service->validate('employee', [], partial: false);
    }

    public function test_employee_api_stores_returns_and_filters_custom_fields(): void
    {
        $this->define();
        $this->define(['label' => 'Age', 'type' => 'number', 'options' => null]);

        $id = $this->postJson('/api/hrms/employees', [
            'name' => 'Field Tester', 'custom_fields' => ['blood_group' => 'o_neg', 'age' => 30],
        ])->assertCreated()->assertJsonPath('employee.custom_fields.blood_group', 'o_neg')->json('employee.id');
        $this->postJson('/api/hrms/employees', ['name' => 'Other', 'custom_fields' => ['blood_group' => 'a_pos', 'age' => 50]])->assertCreated();

        $this->putJson("/api/hrms/employees/{$id}", ['name' => 'Field Tester', 'custom_fields' => ['age' => 31]])
            ->assertOk()->assertJsonPath('employee.custom_fields.age', 31)->assertJsonPath('employee.custom_fields.blood_group', 'o_neg');

        $names = collect($this->getJson('/api/hrms/employees?cf[blood_group]=o_neg')->assertOk()->json('employees'))->pluck('name');
        $this->assertSame(['Field Tester'], $names->all());

        $range = collect($this->getJson('/api/hrms/employees?cf[age][from]=40&cf[age][to]=60')->json('employees'))->pluck('name');
        $this->assertSame(['Other'], $range->all());

        $this->postJson('/api/hrms/employees', ['name' => 'Bad', 'custom_fields' => ['blood_group' => 'z']])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_fields.blood_group');
    }

    public function test_sensitive_fields_are_hidden_in_lists_and_for_readers_without_the_sensitive_grant(): void
    {
        $this->define(['label' => 'Passport', 'type' => 'text', 'options' => null, 'is_sensitive' => true]);
        $employee = $this->makeEmployee('Secretive');
        $service = app(CustomFieldService::class);
        $service->save('employee', $employee->id, $service->validate('employee', ['passport' => 'X1234567']));

        $row = collect($this->getJson('/api/hrms/employees')->json('employees'))->firstWhere('id', $employee->id);
        $this->assertArrayNotHasKey('passport', (array) $row['custom_fields']);

        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->assertJsonPath('employee.custom_fields.passport', 'X1234567');

        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));
        $this->getJson("/api/hrms/employees/{$employee->id}")->assertOk()->assertJsonMissingPath('employee.custom_fields.passport');
    }

    public function test_clearing_a_value_removes_the_row(): void
    {
        $this->define(['label' => 'Nick', 'type' => 'text', 'options' => null]);
        $employee = $this->makeEmployee('Nicky');
        $service = app(CustomFieldService::class);
        $service->save('employee', $employee->id, $service->validate('employee', ['nick' => 'Nix']));
        $this->assertSame(1, CustomFieldValue::count());

        $service->save('employee', $employee->id, $service->validate('employee', ['nick' => '']));
        $this->assertSame(0, CustomFieldValue::count());
    }

    public function test_only_managers_write_definitions_but_everyone_reads_them(): void
    {
        $this->define();
        $this->actAs($this->userWith(['hrms.view', 'hrms.employees.view']));

        $this->getJson('/api/custom-fields?entity=employee')->assertOk()->assertJsonCount(1, 'fields');
        $this->postJson('/api/custom-fields', ['entity' => 'employee', 'label' => 'Z', 'type' => 'text'])->assertForbidden();
    }

    public function test_without_the_module_the_routes_are_gated_and_values_are_ignored(): void
    {
        $this->define();
        $this->setAcmeModules(['hrms.core']);

        $this->getJson('/api/custom-fields?entity=employee')->assertForbidden();
        $this->postJson('/api/hrms/employees', ['name' => 'No Module', 'custom_fields' => ['blood_group' => 'o_neg']])
            ->assertCreated()->assertJsonMissingPath('employee.custom_fields');
    }
}
