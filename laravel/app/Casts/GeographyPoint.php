<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * A PostGIS geography point as ['latitude' => …, 'longitude' => …], or null.
 *
 * PostgreSQL returns a geography as hex-encoded EWKB. A point is saved as
 * EWKT text, "SRID=4326;POINT(longitude latitude)", which PostGIS reads into
 * the column, to seven decimal places (about a centimeter). Both put the
 * longitude first.
 *
 * @implements CastsAttributes<array{latitude: float, longitude: float}|null, array{latitude: float, longitude: float}|null>
 */
class GeographyPoint implements CastsAttributes
{
    /**
     * @return array{latitude: float, longitude: float}|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        // Set on the model and not read back from the database yet
        if (preg_match('/POINT\(\s*(\S+)\s+(\S+)\s*\)/i', $value, $matches) === 1) {
            return ['latitude' => (float) $matches[2], 'longitude' => (float) $matches[1]];
        }

        return $this->fromEwkb($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $latitude = $value['latitude'] ?? null;
        $longitude = $value['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)
            || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            throw new InvalidArgumentException("{$key} needs a latitude from -90 to 90 and a longitude from -180 to 180.");
        }

        return sprintf('SRID=4326;POINT(%.7F %.7F)', $longitude, $latitude);
    }

    /**
     * A point from hex-encoded EWKB: a byte order, a geometry type with
     * flags (0x20000000 when an SRID follows), the SRID, then the longitude
     * and latitude as doubles.
     *
     * @return array{latitude: float, longitude: float}
     */
    private function fromEwkb(string $hex): array
    {
        $bytes = ctype_xdigit($hex) ? hex2bin($hex) : false;

        if ($bytes === false || strlen($bytes) < 21) {
            throw new UnexpectedValueException('Not a geography point.');
        }

        $littleEndian = ord($bytes[0]) === 1;
        $type = unpack($littleEndian ? 'V' : 'N', substr($bytes, 1, 4))[1];

        if (($type & 0x0FFFFFFF) !== 1) {
            throw new UnexpectedValueException('Not a geography point.');
        }

        $offset = ($type & 0x20000000) !== 0 ? 9 : 5;
        $coordinates = unpack($littleEndian ? 'e2' : 'E2', substr($bytes, $offset, 16));

        return ['latitude' => $coordinates[2], 'longitude' => $coordinates[1]];
    }
}
