<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEventPublicBrandingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update event') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'show_managing_organisation' => ['required', 'boolean'],
            'social_links_mode' => ['required', Rule::in(Event::SOCIAL_LINK_MODES)],
            'social_links' => ['present', 'array:'.implode(',', Event::SOCIAL_LINK_KEYS)],
        ];
        foreach (Event::SOCIAL_LINK_KEYS as $key) {
            $rules['social_links.'.$key] = ['nullable', 'url:http,https', 'max:2048'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'social_links.*.url' => 'Enter a full http:// or https:// link to a page or post.',
            'social_links_mode.in' => 'Choose organisation links, event links or hidden links.',
            'show_managing_organisation.boolean' => 'Choose whether to show the managing organisation.',
        ];
    }
}
