<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;
use Ngos\AdminCore\Support\Html;

/**
 * A store request that sanitises its input in prepareForValidation() — the same shape a generated form
 * uses for a rich-text field. Used to prove CSV import runs prepareForValidation (no XSS bypass).
 */
class StoreWidgetSanitizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->name)) {
            $this->merge(['name' => Html::clean($this->name)]);
        }
    }
}
