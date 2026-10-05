<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Purge des clés d'idempotence.
 *
 * ⚠️ CETTE LIGNE NE S'EXÉCUTE QUE SI LE CRON DU SERVEUR APPELLE
 * `php artisan schedule:run` (toutes les minutes). Sur un hébergement mutualisé
 * type o2switch, ce n'est généralement PAS le cas : la purge n'a alors jamais
 * lieu et `idempotency_keys` continue de grossir.
 *
 * Vérifier que c'est bien en place avant la production :
 *     crontab -l | grep schedule
 * Sans cron, déclencher à la main :
 *     php artisan yam:purge-idempotency-keys
 *
 * Le plancher de 7 jours appliqué par la commande protège l'argent : même
 * déclenchée par erreur, elle ne peut pas rouvrir la fenêtre de double débit.
 */
Schedule::command('yam:purge-idempotency-keys')->dailyAt('03:17');