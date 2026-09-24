<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appel extends Model
{
    protected $table = 'appels';
    // P3-arrondi : stocke les dates AVEC microsecondes (Y-m-d H:i:s.u) pour
    // que reglerFacturation() calcule la durée réelle (décimale) et non une
    // durée tronquée à la seconde. Sans cela, le règlement final sous-évalue
    // le coût (delta = max(0, coutExact - dejaDebite) = 0) et le patient paie
    // le débit heartbeat précis sans jamais être ajusté → sur-facturation de
    // quelques centimes par appel.
    protected $dateFormat = 'Y-m-d H:i:s.u';
    public const STATUS_INITIE = 'initie';
    public const INITIE_PAR_PATIENT = 'patient';
    public const INITIE_PAR_MEDECIN = 'medecin';
    public const STATUS_SONNE = 'sonne';
    public const STATUS_DECROCHE = 'decroche';
    public const STATUS_NON_DECROCHE = 'non_decroche';
    public const STATUS_TERMINE = 'termine';
    protected $fillable = [
        'patient_id',
        'medecin_id',
        'initie_par',
        'status',
        'tarif_par_minute',
        'solde_consomme',
        'date_sonnerie',
        'date_decroche',
        'date_fin',
        'raison_fin'
    ];
    protected function casts(): array
    {
        return [
            'tarif_par_minute' => 'decimal:2',
            'solde_consomme' => 'decimal:2',
            'date_sonnerie' => 'datetime',
            'date_decroche' => 'datetime',
            'date_fin' => 'datetime',
        ];
    }
    public function patient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function medecin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'medecin_id');
    }
}