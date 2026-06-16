<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/** Like StoreWidgetRequest but with a file column — to exercise import dropping image/file columns. */
class StoreWidgetImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
