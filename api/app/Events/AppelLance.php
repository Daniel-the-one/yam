<?php
namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand un appel facturé passe au statut "sonne".
 * Envoyé sur le canal PRIVÉ user.{destinationUserId} : seul le destinataire
 * authentifié (vérifié dans routes/channels.php) reçoit la notification.
 */
class AppelLance implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $appelId,
        public int $destinationUserId,
        public string $initiePar,
        public string $status,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.' . $this->destinationUserId)];
    }

    public function broadcastAs(): string
    {
        return 'appel.lance';
    }

    public function broadcastWith(): array
    {
        return [
            'appel_id'   => $this->appelId,
            'initie_par' => $this->initiePar,
            'status'     => $this->status,
        ];
    }
}