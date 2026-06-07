<?php

namespace Modules\Ilm\Http\Controllers;

use App\Foundation\Http\ApiController;
use App\Foundation\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Ilm\Http\Resources\ContactMethodResource;
use Modules\Ilm\Models\Customer;

/**
 * Customer sub-resources: contact methods, notes and structured interactions
 * (ILM-CFG-01 §4.1). Grouped here as they share the customer scope and shape.
 */
class CustomerSubResourceController extends ApiController
{
    /** GET /api/customers/{customer}/contact-methods */
    public function contactMethods(Customer $customer): JsonResponse
    {
        return ApiResponse::item([
            'items' => ContactMethodResource::collection($customer->contactMethods()->get()),
        ]);
    }

    /** POST /api/customers/{customer}/contact-methods */
    public function storeContactMethod(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:MSISDN,EMAIL,LANDLINE,WHATSAPP'],
            'value' => ['required', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $method = $customer->contactMethods()->create($data);

        return ApiResponse::created(new ContactMethodResource($method));
    }

    /** GET /api/customers/{customer}/notes?limit=&kind= */
    public function notes(Request $request, Customer $customer): JsonResponse
    {
        $notes = $customer->notes()
            ->when($request->query('kind'), fn ($q, $kind) => $q->where('kind', $kind))
            ->latest()
            ->limit((int) $request->integer('limit', 20))
            ->get();

        return ApiResponse::item(['items' => $notes]);
    }

    /** POST /api/customers/{customer}/notes */
    public function storeNote(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'max:32'],
            'body' => ['required', 'string'],
            'author_id' => ['nullable', 'string', 'max:64'],
        ]);

        $note = $customer->notes()->create($data);

        return ApiResponse::created($note);
    }

    /** GET /api/customers/{customer}/interactions?limit= */
    public function interactions(Request $request, Customer $customer): JsonResponse
    {
        $items = $customer->interactions()
            ->latest()
            ->limit((int) $request->integer('limit', 20))
            ->get();

        return ApiResponse::item(['items' => $items]);
    }

    /** POST /api/customers/{customer}/interactions */
    public function storeInteraction(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'agent_name' => ['nullable', 'string', 'max:128'],
            'reason' => ['nullable', 'string', 'max:128'],
            'findings' => ['nullable', 'string'],
            'resolution_ticket' => ['nullable', 'string', 'max:64'],
            'voc' => ['nullable', 'string'],
        ]);

        $interaction = $customer->interactions()->create($data);

        return ApiResponse::created($interaction);
    }
}
