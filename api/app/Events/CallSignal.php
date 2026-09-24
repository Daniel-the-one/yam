<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Événement de signaling WebRTC (offer, answer, ICE candidate).
 * Diffusé sur le canal device.{deviceId} pour acheminer les messages
 * de signaling entre les deux pairs d'un appel.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $toDeviceId,
        public string $fromDeviceId,
        public string $type,       // "offer", "answer", "candidate", "bye"
        public array  $payload,    // données SDP ou ICE candidate
        public ?string $callId = null, // identifiant de l'appel (optionnel)
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('device.' . $this->toDeviceId);
    }

    public function broadcastAs(): string
    {
        return 'call-signal';
    }

    public function broadcastWith(): array
    {
        $data = [
            'from_device_id' => $this->fromDeviceId,
            'type'           => $this->type,
            'payload'        => $this->payload,
        ];

        // Champ optionnel, rétro-compatible : les clients ignorent les
        // champs inconnus.
        if ($this->callId !== null) {
            $data['call_id'] = $this->callId;
        }

        return $data;
    }
}
