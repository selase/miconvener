<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage organization settings') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'url' => ['required', 'string', 'url:https,http', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in([
                WebhookEndpoint::EVENT_ALL,
                ...array_keys(WebhookEndpoint::AVAILABLE_EVENTS),
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.url' => 'The endpoint URL must be a valid http(s) address.',
            'events.required' => 'Subscribe the endpoint to at least one event.',
            'events.min' => 'Subscribe the endpoint to at least one event.',
            'events.*.in' => 'One of the selected event topics is not supported.',
        ];
    }
}
