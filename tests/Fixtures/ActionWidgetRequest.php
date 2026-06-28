<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/** Validates the protected `secret`/`status` too, so field-level stripping is observable in tests. */
class ActionWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // A test can flip this to prove a locked *required* field is still submittable (the controller
            // merges the stored value past validation).
            'secret' => config('test.require_secret') ? ['required', 'string'] : ['nullable', 'string'],
            'status' => ['nullable', 'string'],
        ];
    }
}
