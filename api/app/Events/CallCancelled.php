<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand un appel est annulé par l'appelant.
 * Les appareils du DESTINATAIRE reçoivent cet événement pour arrêter
 * la sonnerie et fermer l'écran d'appel entrant.
 *
 * Le canal est `device.{toDeviceId}` : un événement par appareil cible
 * (même modèle que IncomingCall), car les canaux sont publics.
 */
class CallCancelled implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $callId,
        public string $toDeviceId,
        public string $fromDeviceId,
    ) {}

    /**
     * Canal public (même modèle que IncomingCall) : l'appareil du
     * destinataire qui sonne doit être prévenu de l'annulation.
     */
    public function broadcastOn(): Channel
    {
        return new Channel('device.' . $this->toDeviceId);
    }

    // Nom de l'événement tel que reçu côté client (ex: channel.bind('call-cancelled', ...))
    public function broadcastAs(): string
    {
        return 'call-cancelled';
    }

    // Données envoyées au client
    public function broadcastWith(): array
    {
        return [
            'call_id'        => $this->callId,
            'from_device_id' => $this->fromDeviceId,
        ];
    }
}