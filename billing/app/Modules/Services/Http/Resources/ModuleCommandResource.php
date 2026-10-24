<?php

namespace App\Modules\Services\Http\Resources;

use App\Modules\Services\Models\ModuleCommand;
use App\Modules\Services\Support\CommandInitiators;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModuleCommand
 */
class ModuleCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'service_label' => $this->whenLoaded('service', fn (): ?string => $this->service?->label()),
            'server_name' => $this->whenLoaded('server', fn (): ?string => $this->server?->name),
            'driver' => $this->driver,
            'command' => $this->command,
            'initiator' => $this->initiator,
            'initiator_label' => app(CommandInitiators::class)->label($this->initiator),
            'attempt' => $this->attempt,
            'success' => $this->success,
            'error' => $this->error,
            'request' => $this->request,
            'response' => $this->response,
            'duration_ms' => $this->duration_ms,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
