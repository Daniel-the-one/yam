<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand quelqu'un appelle un appareil.
 * Le client web (ou mobile) qui écoute le canal correspondant
 * reçoit cet événement en temps réel et peut afficher "appel entrant".
 */
class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callId,
        public string $toDeviceId,
        public string $fromDeviceId,
        public string $fromUsername,
        public string $type = 'audio',
        public ?int $fromUserId = null,
    ) {}

    /**
     * Canal public pour l'instant (pas encore de base de données pour
     * authentifier un canal privé). À sécuriser plus tard avec un canal
     * privé + vérification du token une fois la BDD en place.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('device.' . $this->toDeviceId);
    }

    // Nom de l'événement tel que reçu côté client (ex: channel.bind('incoming-call', ...))
    public function broadcastAs(): string
    {
        return 'incoming-call';
    }

    // Données envoyées au client
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'from_device_id' => $this->fromDeviceId,
            'from_username' => $this->fromUsername,
            'from_user_id' => $this->fromUserId,
            'type' => $this->type,
        ];
    }
}
