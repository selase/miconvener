<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWebhookEndpointRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', Rule::in([
                WebhookEndpoint::EVENT_ALL,
                ...array_keys(WebhookEndpoint::AVAILABLE_EVENTS),
            ])],
        ];
    }
}
