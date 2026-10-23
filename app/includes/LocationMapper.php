<?php

declare(strict_types=1);

/**
 * Location Mapper - Maps Snipe IT locations to Quacktrack regions
 *
 * Provides utilities for automatically assigning users to Quacktrack regions
 * based on their Snipe IT location. This enables region-based organization and
 * filtering in reports.
 *
 * @package Quacktrack
 */
class LocationMapper
{
    private static ?PDO $pdo = null;
    private static array $mappingCache = [];
/**
     * Initialize with database connection
     *
     * @param PDO $pdo Database connection
     * @return void
     */
    public static function init(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    /**
     * Map a Snipe IT location to a Quacktrack region
     *
     * Looks up the mapping in snipe_it_location_mappings table.
     * Falls back to default region if mapping doesn't exist.
     *
     * @param int $snipeLocationId Snipe IT location ID
     * @param int $defaultRegionId Default region if no mapping found
     * @return int Region ID
     */
    public static function mapLocationToRegion(int $snipeLocationId, int $defaultRegionId = 1): int
    {
        if (!self::$pdo) {
            return $defaultRegionId;
        }

        // Check cache first
        if (isset(self::$mappingCache[$snipeLocationId])) {
            return self::$mappingCache[$snipeLocationId];
        }

        try {
            $stmt = self::$pdo->prepare('
                SELECT region_id FROM snipe_it_location_mappings
                WHERE snipe_location_id = ?
                LIMIT 1
            ');
            $stmt->execute([$snipeLocationId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && isset($result['region_id'])) {
                $regionId = (int)$result['region_id'];
                self::$mappingCache[$snipeLocationId] = $regionId;
                return $regionId;
            }
        } catch (PDOException $e) {
            Logger::error('location_mapper_lookup', $e, [
                'snipe_location_id' => $snipeLocationId,
            ]);
        }

        return $defaultRegionId;
    }

    /**
     * Create or update a location mapping
     *
     * @param int $regionId Quacktrack region ID
     * @param int $snipeLocationId Snipe IT location ID
     * @param string $snipeLocationName Snipe IT location name (optional, for reference)
     * @return bool Success or failure
     */
    public static function createMapping(int $regionId, int $snipeLocationId, string $snipeLocationName = ''): bool
    {
        if (!self::$pdo) {
            return false;
        }

        try {
            $stmt = self::$pdo->prepare('
                INSERT INTO snipe_it_location_mappings (region_id, snipe_location_id, snipe_location_name)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    region_id = VALUES(region_id),
                    snipe_location_name = VALUES(snipe_location_name),
                    updated_at = NOW()
            ');
            $result = $stmt->execute([$regionId, $snipeLocationId, $snipeLocationName]);
// Clear cache entry if successful
            if ($result) {
                unset(self::$mappingCache[$snipeLocationId]);
            }

            return $result;
        } catch (PDOException $e) {
            Logger::error('location_mapper_create', $e, [
                'region_id' => $regionId,
                'snipe_location_id' => $snipeLocationId,
            ]);
            return false;
        }
    }

    /**
     * Get all location mappings
     *
     * @return array Array of mappings [snipe_location_id => region_id]
     */
    public static function getAllMappings(): array
    {
        if (!self::$pdo) {
            return [];
        }

        try {
            $stmt = self::$pdo->prepare('
                SELECT snipe_location_id, region_id FROM snipe_it_location_mappings
                ORDER BY region_id, snipe_location_name
            ');
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $mappings = [];
            foreach ($results as $row) {
                $mappings[(int)$row['snipe_location_id']] = (int)$row['region_id'];
            }

            return $mappings;
        } catch (PDOException $e) {
            Logger::error('location_mapper_get_all', $e);
            return [];
        }
    }

    /**
     * Delete a location mapping
     *
     * @param int $snipeLocationId Snipe IT location ID to remove
     * @return bool Success or failure
     */
    public static function deleteMapping(int $snipeLocationId): bool
    {
        if (!self::$pdo) {
            return false;
        }

        try {
            $stmt = self::$pdo->prepare('
                DELETE FROM snipe_it_location_mappings
                WHERE snipe_location_id = ?
            ');
            $result = $stmt->execute([$snipeLocationId]);
// Clear cache entry if successful
            if ($result) {
                unset(self::$mappingCache[$snipeLocationId]);
            }

            return $result;
        } catch (PDOException $e) {
            Logger::error('location_mapper_delete', $e, [
                'snipe_location_id' => $snipeLocationId,
            ]);
            return false;
        }
    }

    /**
     * Clear the cache
     *
     * Useful if mappings are updated externally
     *
     * @return void
     */
    public static function clearCache(): void
    {
        self::$mappingCache = [];
    }
}
