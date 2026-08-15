<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operation\UpdateJourneyPricesRequest;
use App\Http\Requests\Operation\UpdateTicketMessageRequest;
use App\Http\Requests\Operation\UpdateTicketTitleRequest;
use App\Services\GlobalPriceService;
use App\Services\JourneyPlanService;
use App\Services\OperationContextService;
use App\Services\TicketMessageService;
use App\Services\TicketTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class JourneyPriceController extends Controller
{
    public function __construct(
        private readonly OperationContextService $context,
        private readonly JourneyPlanService $journeys,
        private readonly GlobalPriceService $prices,
        private readonly TicketMessageService $ticketMessages,
        private readonly TicketTitleService $ticketTitles,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->data($request),
        ]);
    }

    public function update(UpdateJourneyPricesRequest $request): JsonResponse
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $actor = $this->context->actor($request, (int) $branch->id);

        DB::transaction(fn () => $this->prices->save(
            $companyId,
            (int) $actor->id,
            $request->validated('global_prices'),
            $request->validated('expected_prices')
        ), 3);

        return response()->json([
            'message' => 'Precios de la jornada actualizados correctamente.',
            'data' => $this->data($request),
        ]);
    }

    public function updateTicketMessage(UpdateTicketMessageRequest $request): JsonResponse
    {
        $ticketMessage = $this->ticketMessages->save(
            $this->context->companyId($request),
            $request->validated('ticket_message')
        );

        return response()->json([
            'message' => 'Mensaje para los tickets actualizado correctamente.',
            'data' => [
                'ticket_message' => $ticketMessage,
            ],
        ]);
    }

    public function updateTicketTitle(UpdateTicketTitleRequest $request): JsonResponse
    {
        $ticketTitle = $this->ticketTitles->save(
            $this->context->companyId($request),
            $request->validated('ticket_title')
        );

        return response()->json([
            'message' => 'Título para los tickets actualizado correctamente.',
            'data' => [
                'ticket_title' => $ticketTitle,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Request $request): array
    {
        $companyId = $this->context->companyId($request);
        $branch = $this->context->branch($request);
        $window = $this->journeys->currentWindow($companyId, $branch);

        return [
            'operating_date' => $window['operating_date']->format('Y-m-d'),
            'starts_at' => $window['starts_at']->toIso8601String(),
            'ends_at' => $window['ends_at']->toIso8601String(),
            'timezone' => $branch->zona_horaria,
            'global_prices' => $this->prices->current($companyId),
            'ticket_title' => $this->ticketTitles->current($companyId),
            'ticket_message' => $this->ticketMessages->current($companyId),
        ];
    }
}
