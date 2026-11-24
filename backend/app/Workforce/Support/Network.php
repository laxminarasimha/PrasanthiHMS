<?php

namespace App\Workforce\Support;

/**
 * Whether a phone or computer is on one of the hospital's own internet connections, for the rule that lets staff
 * check in only from inside the hospital. It goes by the connection's public address, which every device on the
 * hospital's Wi-Fi shares. No location is taken from the phone.
 */
final class Network
{
    /**
     * The same connection: the same address, or for the newer long addresses the same first half, since each device
     * on one connection has its own second half.
     */
    public static function same(?string $a, ?string $b): bool
    {
        $a = @inet_pton((string) $a);
        $b = @inet_pton((string) $b);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }

        return strlen($a) === 4 ? $a === $b : substr($a, 0, 8) === substr($b, 0, 8);
    }

    /** @param  list<string>  $connections */
    public static function among(?string $address, array $connections): bool
    {
        foreach ($connections as $connection) {
            if (self::same($address, $connection)) {
                return true;
            }
        }

        return false;
    }

    /** Whether someone at this address may mark their own attendance. An empty list means anywhere. */
    public static function mayCheckIn(?string $address): bool
    {
        $connections = Settings::get('attendance')['self_check_in_networks'] ?? [];

        return $connections === [] || self::among($address, $connections);
    }
}
