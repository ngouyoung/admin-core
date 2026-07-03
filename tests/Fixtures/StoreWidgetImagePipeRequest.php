<?php

namespace Ngos\AdminCore\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

/** Like StoreWidgetImageRequest but with PIPE-STRING rules — import must drop image/file columns in this form too. */
class StoreWidgetImagePipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'photo' => 'nullable|image|max:2048',
        ];
    }
}
