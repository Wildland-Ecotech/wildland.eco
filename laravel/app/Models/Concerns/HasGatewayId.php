<?php

namespace App\Models\Concerns;

/**
 * For models synced from a payment gateway, which are identified by the
 * gateway's own ID for the object they represent. The using model defines
 * GATEWAY_ID_COLUMN and uses SoftDeletes.
 *
 * Sync and import code finds records with findByGatewayId(), which includes
 * soft-deleted rows, so receiving the same object again updates its record
 * without restoring it and never creates a duplicate.
 */
trait HasGatewayId
{
    /**
     * Find the record for a gateway object, including a soft-deleted one.
     */
    public static function findByGatewayId(string $gateway, string $gatewayId): ?static
    {
        return static::withTrashed()
            ->where('gateway', $gateway)
            ->where(static::GATEWAY_ID_COLUMN, $gatewayId)
            ->first();
    }
}
